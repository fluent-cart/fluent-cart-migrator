<?php

namespace FluentCartMigrator\Classes\Edd3;

use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Hooks\Handlers\LicenseApiHandler;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;

class EddLicenseHandler
{
    public function register()
    {
        require_once __DIR__ . '/MigratorHelper.php';

        add_action('init', function () {

            if (!defined('EDD_SL_VERSION')) {
                $this->interceptEddLicenses();
            }

            // Handle old EDD IPN requests
            if (isset($_REQUEST['edd-listener']) && $_REQUEST['edd-listener'] === 'IPN') {
                if (!defined('EDD_VERSION')) {
                    $this->handleOldEddIpns();
                    exit(200);
                }
            }
        }, 2);

    }

    private function interceptEddLicenses()
    {
        if (isset($_REQUEST['edd_action'])) {
            if (defined('EDD_SL_VERSION')) {
                return; // EDD is already active, no need to register the handler again
            }

            $action = (string)Arr::get($_REQUEST, 'edd_action', '');
            $validActionMaps = [
                'activate_license'   => 'activateLicense',
                'deactivate_license' => 'deactivateLicense',
                'check_license'      => 'getLicenseStatus',
                'get_version'        => 'getVersion',
            ];
            if (!empty($validActionMaps[$action])) {
                $this->{$validActionMaps[$action]}($_REQUEST);
            }
        }

        $requestURI = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestURI, 'edd-sl/package_download') !== false) {
            $this->handlePackageDownload($requestURI);
        }

        if (isset($_GET['edd_license_key']) && !empty($_GET['download_id'])) {
            $defaultRedirect = home_url('/');
            // we have to redirect to the new renew url
            $license = License::query()->where('license_key', sanitize_text_field(Arr::get($_GET, 'edd_license_key', '')))
                ->first();

            if (!$license) {
                wp_redirect($defaultRedirect);
                die();
            }

            $subscriptionId = $license->subscription_id;

            if (!$subscriptionId) {
                wp_redirect($defaultRedirect);
                die();
            }

            $subscription = Subscription::query()->find($subscriptionId);
            if (!$subscription) {
                wp_redirect($defaultRedirect);
                die();
            }

            $renewUrl = $subscription->getReactivateUrl();

            if (empty($renewUrl)) {
                wp_redirect($defaultRedirect);
                die();
            }

            wp_redirect($renewUrl);
            exit;
        }
    }

    private function handleOldEddIpns()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }

        // Set initial post data to empty string
        $post_data = '';

        // Fallback just in case post_max_size is lower than needed
        if (ini_get('allow_url_fopen')) {
            $post_data = file_get_contents('php://input');
        } else {
            // If allow_url_fopen is not enabled, then make sure that post_max_size is large enough
            ini_set('post_max_size', '12M');
        }
        // Start the encoded data collection with notification command
        $encoded_data = 'cmd=_notify-validate';

        // Get current arg separator
        $arg_separator = ini_get('arg_separator.output');

        // Verify there is a post_data
        if ($post_data || strlen($post_data) > 0) {
            // Append the data
            $encoded_data .= $arg_separator . $post_data;
        } else {
            // Check if POST is empty
            if (empty($_POST)) {
                // Nothing to do
                return;
            }
            // Loop through each POST
            foreach ($_POST as $key => $value) {
                // Encode the value and append the data
                $encoded_data .= $arg_separator . "$key=" . urlencode($value);
            }
        }

        do_action('fluent_cart_migrator_paypal_ipn', $encoded_data);

        // Convert collected post data to an array
        parse_str($encoded_data, $encoded_data_array);
        foreach ($encoded_data_array as $key => $value) {
            if (false !== strpos($key, 'amp;')) {
                $new_key = str_replace('&amp;', '&', $key);
                $new_key = str_replace('amp;', '&', $new_key);
                unset($encoded_data_array[$key]);
                $new_key = (string)$new_key; // Ensure the key is a string
                $encoded_data_array[$new_key] = $value;
            }
        }

        // We will just refetch the subscription data
        $defaults = array(
            'txn_type'       => '',
            'payment_status' => ''
        );

        $encoded_data_array = wp_parse_args($encoded_data_array, $defaults);

        $txtnType = Arr::get($encoded_data_array, 'txn_type', '');

        if (!$txtnType) {
            return;
        }

        if ($txtnType == 'subscr_payment') {
            // This is a renewal payment
            $vendorSubscriptionId = Arr::get($encoded_data_array, 'subscr_id', '');

            $subscription = null;

            if ($vendorSubscriptionId) {
                $subscription = Subscription::query()
                    ->where('vendor_subscription_id', $vendorSubscriptionId)
                    ->first();
            }

            if (!$subscription) {
                $custom = (int)Arr::get($encoded_data_array, 'custom', '');
                if ($custom) {
                    $subscription = Subscription::query()
                        ->where('parent_order_id', $custom)
                        ->first();
                }
            }

            if (!$subscription || $subscription->current_payment_method !== 'paypal') {
                fluent_cart_add_log('Paypal IPN subscr_payment could not be found', 'Failed to find the parent subscription. IPN Data: ' . \json_encode($post_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'error', [
                    'module_name'               => 'activity',
                    'log_type'                  => 'api',
                    'trigger_admin_alert_email' => 'yes'
                ]);

                return;
            }

            $subscription->reSyncFromRemote();
        }
    }

    public function handlePackageDownload($requestUri)
    {
        $url_parts = wp_parse_url(untrailingslashit($requestUri));
        $paths = array_values(explode('/', $url_parts['path']));

        $token = end($paths);
        $values = explode(':', base64_decode($token));
        if (count($values) !== 6) {
            die('error');
        }

        $license_key = $values[1];
        $url = str_replace('@', ':', $values[4]);

        $url = LicenseHelper::sanitizeSiteUrl($url);

        $license = License::query()->where('license_key', $license_key)
            ->first();

        if (!$license) {
            die('Invalid License Key');
        }

        $packageData = ($license_key) . '::' . ($url) . ':' . ($license->product_id) . ':' . (time() + 48 * HOUR_IN_SECONDS);
        $hash = base64_encode($packageData);

        $data = [
            'fct_package' => $hash,
        ];

        do_action('fluent_cart_action_download_license_package', $data);

    }

    public function getLicenseStatus($data = [])
    {
        $formattedData = [
            'license_key' => Arr::get($data, 'license'),
            'site_url'    => Arr::get($data, 'url', ''),
            'item_id'     => Arr::get($data, 'item_id', ''),
        ];

        $formattedData['site_url'] = LicenseHelper::sanitizeSiteUrl($formattedData['site_url']);

        $fluentProductDetails = MigratorHelper::getTransformedProductDetails($formattedData['item_id']);

        if (is_wp_error($fluentProductDetails)) {
            // let's find the product by license key
            $fctLicense = License::query()->where('license_key', $formattedData['license_key'])
                ->first();

            if ($fctLicense) {
                $formattedData['item_id'] = $fctLicense->product_id;
            } else {
                wp_send_json([
                    'success' => false,
                    'status'  => 'invalid',
                    'message' => __('Invalid License Key', 'fluent-cart-migrator')
                ], 422);
            }
        } else {
            $formattedData['item_id'] = $fluentProductDetails['id'];
        }

        add_filter('fluent_cart/license/checking_error', function ($response) {
            $response['license'] = '';
            $response['error'] = Arr::get($response, 'message', __('Invalid License Key', 'fluent-cart-migrator'));
            $response['success'] = false;
            return $response;
        });

        add_filter('fluent_cart/license/check_license_response', function ($returnData, $license, $activation) {
            $customer = $license->customer;
            // we have to transform the $returnData to match the edd format
            $eddFormat = [
                'success'          => Arr::get($returnData, 'status') === 'valid',
                'license'          => Arr::get($returnData, 'status'),
                'item_id'          => Arr::get($returnData, 'product_id'),
                'item_name'        => Arr::get($returnData, 'product_title'),
                'expires'          => Arr::get($returnData, 'expiration_date'),
                'payment_id'       => $license->order_id,
                'customer_name'    => $customer ? $customer->full_name : '',
                'customer_email'   => $customer ? $customer->email : '',
                'license_limit'    => Arr::get($returnData, 'activation_limit'),
                'site_count'       => Arr::get($returnData, 'activations_count'),
                'activations_left' => $license->getActivationLimit(),
                'price_id'         => Arr::get($returnData, 'variation_id'),
            ];
            return $eddFormat;
        }, 1, 3);

        // this will return the license status in EDD format
        (new LicenseApiHandler())->checkLicense($formattedData);
    }

    public function activateLicense($data = [])
    {
        $formattedData = [
            'license_key' => Arr::get($data, 'license'),
            'site_url'    => Arr::get($data, 'url', ''),
            'item_id'     => Arr::get($data, 'item_id', ''),
        ];

        $formattedData['site_url'] = LicenseHelper::sanitizeSiteUrl($formattedData['site_url']);

        $fluentProductDetails = MigratorHelper::getTransformedProductDetails($formattedData['item_id']);

        if (is_wp_error($fluentProductDetails)) {
            wp_send_json([
                'success' => false,
                'status'  => 'invalid',
                'message' => __('Invalid License Key', 'fluent-cart-migrator')
            ], 422);
        } else {
            $formattedData['item_id'] = $fluentProductDetails['id'];
        }

        add_filter('fluent_cart/license/activate_license_response', function ($returnData, $license, $activation) {
            $customer = $license->customer;
            $eddFormat = [
                'success'          => Arr::get($returnData, 'status') === 'valid',
                'license'          => Arr::get($returnData, 'status'),
                'item_id'          => Arr::get($returnData, 'product_id'),
                'item_name'        => Arr::get($returnData, 'product_title'),
                'expires'          => Arr::get($returnData, 'expiration_date'),
                'payment_id'       => $license->order_id,
                'customer_name'    => $customer ? $customer->full_name : '',
                'customer_email'   => $customer ? $customer->email : '',
                'license_limit'    => Arr::get($returnData, 'activation_limit'),
                'site_count'       => Arr::get($returnData, 'activations_count'),
                'activations_left' => $license->getActivationLimit(),
                'price_id'         => Arr::get($returnData, 'variation_id'),
            ];
            return $eddFormat;
        }, 1, 3);

        // this will return the license status in EDD format
        (new LicenseApiHandler())->activateLicense($formattedData);
    }

    public function deactivateLicense($data = [])
    {
        $formattedData = [
            'license_key' => Arr::get($data, 'license'),
            'site_url'    => Arr::get($data, 'url', ''),
            'item_id'     => Arr::get($data, 'item_id', ''),
        ];

        $formattedData['site_url'] = LicenseHelper::sanitizeSiteUrl($formattedData['site_url']);

        $fluentProductDetails = MigratorHelper::getTransformedProductDetails($formattedData['item_id']);


        if (is_wp_error($fluentProductDetails)) {
            // let's find the product by license key
            $fctLicense = License::query()->where('license_key', $formattedData['license_key'])
                ->first();

            if ($fctLicense) {
                $formattedData['item_id'] = $fctLicense->product_id;
            } else {
                wp_send_json([
                    'success' => false,
                    'status'  => 'invalid',
                    'message' => __('Invalid License Key', 'fluent-cart-migrator')
                ], 422);
            }
        } else {
            $formattedData['item_id'] = $fluentProductDetails['id'];
        }

        add_action('fluent_cart/license/site_deactivated_failed', function ($site) {
            wp_send_json([
                'license' => 'failed',
            ]);
        });

        add_action('fluent_cart/license/site_deactivated', function ($site) {
            wp_send_json([
                'license' => 'deactivated',
            ]);
        });

        // this will return the license status in EDD format
        (new LicenseApiHandler())->deActivateLicense($formattedData);
    }

    public function getVersion($data = [])
    {
        $formattedData = [
            'license_key' => Arr::get($data, 'license'),
            'site_url'    => Arr::get($data, 'url', ''),
            'item_id'     => Arr::get($data, 'item_id', ''),
        ];

        $formattedData['site_url'] = LicenseHelper::sanitizeSiteUrl($formattedData['site_url']);


        $fluentProductDetails = MigratorHelper::getTransformedProductDetails($formattedData['item_id']);

        if (is_wp_error($fluentProductDetails)) {
            // let's find the product by license key
            $fctLicense = License::query()->where('license_key', $formattedData['license_key'])
                ->first();

            if ($fctLicense) {
                $formattedData['item_id'] = $fctLicense->product_id;
            } else {
                wp_send_json([
                    'success' => false,
                    'status'  => 'invalid',
                    'message' => __('Invalid License Key', 'fluent-cart-migrator')
                ], 422);
            }
        } else {
            $formattedData['item_id'] = $fluentProductDetails['id'];
        }

        add_filter('fluent_cart/license/get_version_response', function ($returnData) {
            $returnData['sections'] = maybe_serialize($returnData['sections']);
            $returnData['banners'] = maybe_serialize($returnData['banners']);
            $returnData['icons'] = maybe_serialize($returnData['icons']);
            return $returnData;
        }, 1, 1);

        (new LicenseApiHandler())->getVersion($formattedData);
    }
}
