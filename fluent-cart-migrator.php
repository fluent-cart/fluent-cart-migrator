<?php defined('ABSPATH') or die;

/*
Plugin Name: FluentCart Migrator
Description: Migrate your data to FluentCart from other platforms.
Version: 1.0.0
Author: FluentCart Team
Author URI: https://fluentcart.com
Plugin URI: https://github.com/fluent-cart/fluent-cart-migrator
License: GPLv2 or later
Text Domain: fluent-cart-migrator
*/

define('FLUENTCART_MIGRATOR_VERSION', '1.0.0');
define('FLUENTCART_MIGRATOR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FLUENTCART_MIGRATOR_URL', plugin_dir_url(__FILE__));// Enable development mode for detailed logging

class FluentCartMigrator
{
    public function init()
    {
        // Admin page (only in admin context)
        if (is_admin()) {
            require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/Admin/AdminMenu.php';
            (new \FluentCartMigrator\Classes\Admin\AdminMenu())->register();
        }

        // REST API — registered unconditionally because REST requests
        // from the admin Vue app hit /wp-json/ where is_admin() is false
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/Admin/RestApi.php';
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/MigratorService.php';
        (new \FluentCartMigrator\Classes\Admin\RestApi())->register();

        $this->handleEddLegacyLicenses();
    }

    private function handleEddLegacyLicenses()
    {
        // let's handle the previous EDD Licensinge EndPoints
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/EDD3/EddLicenseHandler.php';
        $eddLicenseHandler = new \FluentCartMigrator\Classes\EDD3\EddLicenseHandler();
        $eddLicenseHandler->register();

        // Let's resolve the stripe's previous IPN Data which has charge id instead of payment intent id
        add_filter('fluent_cart/stripe/fallback_order_transaction', function ($transaction, $vendorChargeData) {
            $chargeId = $vendorChargeData->id ?? null;

            if (!$chargeId) {
                return $transaction;
            }

            $orderTransaction = \FluentCart\App\Models\OrderTransaction::query()->where('vendor_charge_id', $chargeId)
                ->where('transaction_type', 'charge')
                ->first();

            if ($orderTransaction) {
                $paymentIntent = $vendorChargeData->payment_intent;
                $orderTransaction->vendor_charge_id = $paymentIntent;
                $orderTransaction->save();
                return $orderTransaction;
            }

            return $transaction;
        }, 10, 2);
    }
}

add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) {
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/Commands.php';
        \WP_CLI::add_command('fluent_cart_migrator', '\FluentCartMigrator\Classes\Commands');
    }

    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    (new FluentCartMigrator())->init();

    /**
     * Plugin Updater
     */
    require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/PluginManager/Updater.php';
    $apiUrl = 'https://api.fluentcart.com/wp-admin/admin-ajax.php?action=fluent_cart_migrator_update&time=' . time();
    new \FluentCartMigrator\Classes\PluginManager\Updater($apiUrl, __FILE__, array(
        'version'   => FLUENTCART_MIGRATOR_VERSION,
        'license'   => '',
        'item_name' => 'FluentCart Migrator',
        'item_id'   => '106',
        'author'    => 'wpmanageninja'
    ),
        array(
            'license_status' => 'valid',
            'admin_page_url' => admin_url('admin.php?page=fluent-cart#/'),
            'purchase_url'   => 'https://fluentcart.com',
            'plugin_title'   => 'FluentCart Migrator'
        )
    );

    add_filter('plugin_row_meta', function ($links, $pluginFile) {
        if (plugin_basename(__FILE__) !== $pluginFile) {
            return $links;
        }

        $checkUpdateUrl = esc_url(admin_url('plugins.php?fluent-cart-migrator-check-update=' . time()));

        $row_meta = array(
            'check_update' => '<a style="color: #583fad;font-weight: 600;" href="' . $checkUpdateUrl . '" aria-label="' . esc_attr__('Check Update', 'fluent-cart-migrator') . '">' . esc_html__('Check Update', 'fluent-cart-migrator') . '</a>',
        );

        return array_merge($links, $row_meta);
    }, 10, 2);
});
