<?php

namespace FluentCartMigrator\Classes\EDD3;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;

class MigratorHelper
{

    public static $cachedSubscriptions = [];

    public static $cachedLicenses = [];

    public static $cachedTaxAdjustments = [];

    public static function resetCaches()
    {
        self::$cachedSubscriptions = [];
        self::$cachedLicenses = [];
        self::$cachedTaxAdjustments = [];
    }

    public static function getPaymentStatus($orderStatus)
    {
        $maps = [
            'complete'           => Status::PAYMENT_PAID,
            'refunded'           => Status::PAYMENT_REFUNDED,
            'partially_refunded' => Status::PAYMENT_PARTIALLY_REFUNDED,
            'revoked'            => Status::PAYMENT_FAILED,
            'on_hold'            => Status::PAYMENT_PENDING,
            'edd_subscription'   => Status::PAYMENT_PAID,
            'publish'            => Status::PAYMENT_PAID,
            'processing'         => Status::PAYMENT_PAID,
            'pending'            => Status::PAYMENT_PENDING,
            'failed'             => Status::PAYMENT_FAILED,
            'cancelled'          => Status::PAYMENT_FAILED,
            'abandoned'          => Status::PAYMENT_FAILED
        ];

        if (isset($maps[$orderStatus])) {
            return $maps[$orderStatus];
        }

        return Status::PAYMENT_PENDING;
    }

    public static function getFormattedOrderMeta($paymentId)
    {
        $metas = fluentCart('db')->table('edd_ordermeta')
            ->where('edd_order_id', $paymentId)
            ->get();

        $formatted = [];

        foreach ($metas as $meta) {
            $formatted[$meta->meta_key] = maybe_unserialize($meta->meta_value);
        }

        return $formatted;
    }

    public static function setCachedSubscriptions($paymentIds)
    {
        $eddSubscriptions = fluentCart('db')->table('edd_subscriptions')
            ->whereIn('parent_payment_id', $paymentIds)
            ->get();

        $formattedSubscriptions = [];

        foreach ($eddSubscriptions as $subscription) {
            if (!isset($formattedSubscriptions[$subscription->parent_payment_id])) {
                $formattedSubscriptions[$subscription->parent_payment_id] = [];
            }
            $formattedSubscriptions[$subscription->parent_payment_id][] = $subscription;
        }

        self::$cachedSubscriptions = $formattedSubscriptions;
    }

    public static function setCachedLicenses($paymentIds)
    {
        if (!ModuleSettings::isActive('license') || !defined('EDD_SL_PLUGIN_DIR')) {
            return [];
        }

        $licenses = fluentCart('db')
            ->table('edd_licenses')
            ->whereIn('payment_id', $paymentIds)
            ->get();

        $licenseIds = $licenses->pluck('id')->toArray();

        $allActivations = fluentCart('db')
            ->table('edd_license_activations')
            ->whereIn('license_id', $licenseIds)
            ->get();

        $activationIndex = [];

        foreach ($allActivations as $activation) {
            if (!isset($activationIndex[$activation->license_id])) {
                $activationIndex[$activation->license_id] = [];
            }

            $activationIndex[$activation->license_id][] = $activation;
        }

        $formattedLicenses = [];
        foreach ($licenses as $license) {
            if (!isset($formattedLicenses[$license->payment_id])) {
                $formattedLicenses[$license->payment_id] = [];
            }

            $productDetails = self::getTransformedProductDetails($license->download_id, $license->price_id);
            if (is_wp_error($productDetails) || !empty($productDetails['is_bundle_product'])) {
                continue;
            }

            $licenseData = [
                'status'             => $license->status,
                'limit'              => self::getLicenseActivationCount($license),
                'license_key'        => $license->license_key,
                'product_id'         => $productDetails['id'],
                'variation_id'       => Arr::get($productDetails, 'variation_id', NULL),
                'order_id'           => $license->payment_id,
                'parent_id'          => NULL,
                'customer_id'        => NULL, //  we will set this later
                'expiration_date'    => $license->expiration ? gmdate('Y-m-d H:i:s', $license->expiration) : NULL,
                'last_reminder_sent' => NULL,
                'last_reminder_type' => NULL,
                'subscription_id'    => NULL, // we will set this later
                'created_at'         => $license->date_created,
                'updated_at'         => current_time('mysql')
            ];
            $activations = Arr::get($activationIndex, $license->id, []);

            $formattedActivations = [];
            $activationCount = 0;
            foreach ($activations as $activation) {
                if (!$activation->activated) {
                    continue;
                }

                if (!$activation->is_local) {
                    $activationCount++;
                }

                $formattedActivations[] = [
                    'site_id'           => self::getSiteID($activation->site_name),
                    'license_id'        => $license->id,
                    'status'            => $activation->activated ? 'active' : 'inactive',
                    'is_local'          => $activation->is_local,
                    'product_id'        => $productDetails['id'],
                    'variation_id'      => Arr::get($productDetails, 'variation_id', NULL),
                    'activation_method' => 'key_based',
                    'activation_hash'   => md5($activation->site_name . '_' . $license->license_key),
                    'created_at'        => $license->date_created,
                    'updated_at'        => $license->date_created,
                ];
            }

            $licenseData['activations'] = $formattedActivations;
            $licenseData['activation_count'] = $activationCount;
            $formattedLicenses[$license->payment_id][] = $licenseData;
        }

        self::$cachedLicenses = $formattedLicenses;
    }

    public static function getSubscriptions($paymentId)
    {
        if (isset(self::$cachedSubscriptions[$paymentId])) {
            return self::$cachedSubscriptions[$paymentId];
        }

        return null;
    }

    public static function getLicenses($paymentId)
    {
        if (!defined('EDD_SL_PLUGIN_DIR')) {
            return [];
        }

        if (empty(self::$cachedLicenses)) {
            self::setCachedLicenses([$paymentId]);
        }

        if (isset(self::$cachedLicenses[$paymentId])) {
            return self::$cachedLicenses[$paymentId];
        }

        return [];
    }

    private static function getSiteID($siteName)
    {
        $siteName = LicenseHelper::sanitizeSiteUrl($siteName);

        $site = fluentCart('db')
            ->table('fct_license_sites')
            ->where('site_url', $siteName)
            ->first();

        if ($site) {
            return $site->id;
        }

        $siteId = fluentCart('db')
            ->table('fct_license_sites')
            ->insertGetId([
                'site_url'   => $siteName,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);

        return $siteId;
    }

    public static function convertEddCouponDate($dateString)
    {
        if (!$dateString) {
            return NULL;
        }

        // EDD3 stores dates in MySQL datetime format (Y-m-d H:i:s)
        $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $dateString);

        // Fallback: EDD2 used m/d/Y H:i:s format
        if ($dateTime === false) {
            $dateTime = \DateTime::createFromFormat('m/d/Y H:i:s', $dateString);
        }

        // Last resort: let PHP parse it
        if ($dateTime === false) {
            try {
                $dateTime = new \DateTime($dateString);
            } catch (\Exception $e) {
                return NULL;
            }
        }

        return $dateTime->format('Y-m-d H:i:s');
    }

    public static function getCouponByCode($code)
    {
        static $codes = [];

        if (isset($codes[$code])) {
            return $codes[$code];
        }

        $codes[$code] = fluentCart('db')->table('fct_coupons')->where('code', $code)->first();

        return $codes[$code];
    }

    public static function getBundleProductDetails($eddCartItem)
    {
        $productId = $eddCartItem['id'];
        if (isset($eddCartItem['item_number']['options']['price_id'])) {
            $priceId = (int)Arr::get($eddCartItem, 'item_number.options.price_id');
        } else {
            $priceId = null;
        }

        $cacheKey = $productId . '_' . $priceId;

        static $caches = [];

        if (isset($caches[$cacheKey])) {
            return $caches[$cacheKey];
        }

        $eddBundle = fluentCart('db')->table('posts')
            ->where('post_type', 'download')
            ->where('ID', $productId)
            ->first();

        if (!$eddBundle) {
            return null;
        }

        $metas = fluentCart('db')->table('postmeta')
            ->where('post_id', $productId)
            ->get();

        $formattedMetas = [];
        foreach ($metas as $meta) {
            $formattedMetas[$meta->meta_key] = maybe_unserialize($meta->meta_value);
        }

        if (Arr::get($formattedMetas, '_edd_product_type') != 'bundle') {
            return new \WP_Error('not_bundle', 'Product is not a bundle');
        }

        $bundleItems = Arr::get($formattedMetas, '_edd_bundled_products', []);
        $formattedBundleItems = [];

        foreach ($bundleItems as $index => $bundleItem) {
            $productArr = explode('_', $bundleItem);
            $bundleItemId = $productArr[0];
            $bundleItemPriceId = isset($productArr[1]) ? $productArr[1] : null;
            $productDetails = self::getTransformedProductDetails($bundleItemId, $bundleItemPriceId);
            if (is_wp_error($productDetails)) {
                return $productDetails;
            }
            $formattedBundleItems[$index + 1] = $productDetails;
        }

        $conditionItems = Arr::get($formattedMetas, '_edd_bundled_products_conditions', []);

        $filteredProducts = null;

        if ($priceId !== null) {
            $filteredItemIndexes = array_filter($conditionItems, function ($item) use ($priceId) {
                return $item == 'all' || $item == $priceId || $priceId === 0;
            });

            $filteredItemIndexes = array_keys($filteredItemIndexes);

            $filteredProducts = array_filter($formattedBundleItems, function ($key) use ($filteredItemIndexes) {
                return in_array($key, $filteredItemIndexes);
            }, ARRAY_FILTER_USE_KEY);
        }

        if (!$filteredProducts) {
            $filteredProducts = $formattedBundleItems;

            // make $filteredProducts as unique by product ID
            $uniqueProducts = [];
            foreach ($filteredProducts as $product) {
                if (!isset($uniqueProducts[$product['id']])) {
                    $uniqueProducts[$product['id']] = $product;
                }
            }

            $filteredProducts = array_filter($uniqueProducts);
        }

        $caches[$cacheKey] = $filteredProducts;

        return $caches[$cacheKey];
    }

    public static function getTransformedProductDetails($eddProductId, $eddVariationId = null)
    {
        $cacheKey = $eddProductId . '_' . $eddVariationId;

        static $caches = [];

        if (isset($caches[$cacheKey])) {
            return $caches[$cacheKey];
        }

        $migratedFcId = get_post_meta($eddProductId, '_fcart_migrated_id', true);
        $product = Product::find($migratedFcId);

        if (!$product) {
            return new \WP_Error('edd_migrator_error', 'Product not found. ' . $eddProductId);
        }

        $data = [
            'id'         => $product->ID,
            'title'      => $product->post_title,
            'full_title' => $product->post_title,
        ];

        if ($eddVariationId) {
            $variationMaps = get_post_meta($product->ID, '__edd_migrated_variation_maps', true);
            if (!$variationMaps) {
                return new \WP_Error('edd_migrator_error', 'Variation maps not found.');
            }

            $variationId = Arr::get($variationMaps, $eddVariationId, 0);

            if (!$variationId) {
                return new \WP_Error('edd_migrator_error', 'Variation not found.');
            }

            $variation = ProductVariation::find($variationId);


            if (!$variation) {
                return new \WP_Error('edd_migrator_error', 'Variation not found.');
            }

            if ($variation->variation_title) {
                $data['full_title'] = $product->post_title . ' - ' . $variation->variation_title;
            }

            $data['variation_title'] = $variation->variation_title;

            $data['variation_id'] = $variation->id;

            if (!empty($variation->other_info['bundle_child_ids'])) {
                $data['is_bundle_product'] = true;
                $data['bundle_child_ids'] = $variation->other_info['bundle_child_ids'];
            }
        } else {
            // get the first variation
            $variation = ProductVariation::where('post_id', $product->ID)
                ->orderBy('serial_index', 'ASC')
                ->first();
            if ($variation) {
                if ($variation->variation_title) {
                    $data['full_title'] = $product->post_title . ' - ' . $variation->variation_title;
                }
                $data['variation_id'] = $variation->id;
                $data['variation_title'] = $variation->variation_title;

                if (!empty($variation->other_info['bundle_child_ids'])) {
                    $data['is_bundle_product'] = true;
                    $data['bundle_child_ids'] = $variation->other_info['bundle_child_ids'];
                }
            }
        }

        $caches[$cacheKey] = $data;

        return $caches[$cacheKey];
    }

    public static function doBulkInsert($table, $dataItems)
    {
        foreach ($dataItems as $dataItem) {
            fluentCart('db')->table($table)->insert($dataItem);
        }
    }

    public static function getLicenseActivationCount($license)
    {
        static $manualLimits = [];
        if (isset($manualLimits[$license->id])) {
            return $manualLimits[$license->id];
        }

        // check the manual limit first
        $allManualLimits = fluentCart('db')->table('edd_licensemeta')
            ->where('meta_key', '_edd_sl_limit')
            ->get();

        foreach ($allManualLimits as $manualLimit) {
            if ($manualLimit->meta_value) {
                $manualLimits[$manualLimit->edd_license_id] = $manualLimit->meta_value;
            }
        }

        if (isset($manualLimits[$license->id])) {
            return $manualLimits[$license->id];
        }

        $cacheKey = $license->download_id . '_' . $license->price_id;
        static $caches = [];

        if (isset($caches[$cacheKey])) {
            return $caches[$cacheKey];
        }

        if (!$license->price_id) {
            return (int)self::getSlLimit($license->download_id);
        }

        return self::getVariableSlLimit($license->download_id, $license->price_id);
    }

    public static function formatOrderItem($payment, $eddCartItem, $transactionType)
    {
        $productDetails = self::getTransformedProductDetails($eddCartItem->product_id, $eddCartItem->price_id);

        if (is_wp_error($productDetails)) {
            return $productDetails;
        }

        $quantity = (int)$eddCartItem->quantity;
        if (!$quantity) {
            $quantity = 1;
        }

        // Use subtotal (net/tax-excluded amount) for unit price since tax is tracked separately
        // EDD's `amount` is the gross price (includes tax for inclusive-tax stores)
        // EDD's `subtotal` is always the net price (tax-excluded)
        $itemSubtotal = self::toCents($eddCartItem->subtotal, $payment->currency);
        $unitPrice = (int)round($itemSubtotal / $quantity);
        $tax = self::toCents($eddCartItem->tax, $payment->currency);
        $discount = self::toCents($eddCartItem->discount, $payment->currency);
        $lineTotal = $itemSubtotal + $tax - $discount;

        $originalDiscount = $discount;

        $pricing = [
            'unit_price'     => $unitPrice,
            'quantity'       => $quantity,
            'subtotal'       => $itemSubtotal,
            'tax_amount'     => $tax,
            'discount_total' => $discount,
            'line_total'     => $lineTotal,
        ];

        if ($lineTotal < 0) { // it's a free product with 100% discount
            $pricing['discount_total'] = $pricing['subtotal'];
            $pricing['tax_amount'] = 0;
            $pricing['line_total'] = 0;
        }

        $otherInfo = [];

        if ($transactionType == 'subscription') {

            $meta = fluentCart('db')->table('edd_order_itemmeta')
                ->where('edd_order_item_id', $eddCartItem->id)
                ->where('meta_key', '_option_recurring')
                ->first();

            $recurring = null;
            if ($meta) {
                $recurring = maybe_unserialize($meta->meta_value);
            }

            if ($recurring) {
                $otherInfo = [
                    'times'            => Arr::get($recurring, 'times', 0),
                    'trial_days'       => '',
                    'description'      => '',
                    'payment_type'     => $transactionType,
                    'subscription'     => '',
                    'billing_summary'  => '',
                    'repeat_interval'  => self::getPeriodSlug(Arr::get($recurring, 'period', 'year')),
                    'manage_setup_fee' => 'no'
                ];
            }
        }

        $orderItem = [
            'order_id'           => (int)$payment->id,
            'post_id'            => $productDetails['id'],
            'fulfillment_type'   => 'digital',
            'payment_type'       => $transactionType,
            'post_title'         => $productDetails['title'],
            'title'              => $productDetails['variation_title'],
            'object_id'          => Arr::get($productDetails, 'variation_id', NULL),
            'quantity'           => $quantity,
            'unit_price'         => $pricing['unit_price'],
            'subtotal'           => $pricing['subtotal'],
            'tax_amount'         => $pricing['tax_amount'],
            'shipping_charge'    => 0,
            'discount_total'     => $pricing['discount_total'], // this is mainly coupon discount
            'line_total'         => $pricing['line_total'],
            'refund_total'       => 0,
            'rate'               => 1,
            'other_info'         => $otherInfo,
            'line_meta'          => [],
            'created_at'         => self::getPostDate($payment, 'post_date'), //$payment->post_date_gmt,
            'updated_at'         => self::getPostDate($payment, 'post_date'), // $payment->post_date_gmt
            '_fallback_discount' => $originalDiscount
        ];

        if ($originalDiscount == $pricing['discount_total']) {
            $orderItem['_fallback_discount'] = 0;
        }

        if (!empty($productDetails['bundle_child_ids'])) {
            $bundledVariations = ProductVariation::whereIn('id', $productDetails['bundle_child_ids'])->get();
            $childItems = [];
            foreach ($bundledVariations as $bundleItem) {
                $childItems[] = [
                    'payment_type'     => 'bundle',
                    'post_id'          => $bundleItem->post_id,
                    'object_id'        => $bundleItem->id,
                    'post_title'       => $bundleItem->product ? $bundleItem->product->post_title : '',
                    'title'            => $bundleItem->variation_title,
                    'fulfillment_type' => Arr::get($bundleItem, 'fulfillment_type', 'digital'),
                    'quantity'         => $quantity,
                    'cost'             => 0,
                    'unit_price'       => 0,
                    'subtotal'         => 0,
                    'tax_amount'       => 0,
                    'shipping_charge'  => 0,
                    'discount_total'   => 0,
                    'other_info'       => [
                        'bundle_parent_product_id'   => Arr::get($productDetails, 'id', 0),
                        'bundle_parent_variation_id' => Arr::get($productDetails, 'variation_id', 0)
                    ],
                ];
            }

            if ($childItems) {
                $orderItem['line_meta']['bundle_child_ids'] = $productDetails['bundle_child_ids'];
                $orderItem['line_meta']['is_bundle_product'] = 'yes';
                $orderItem['bundle_items'] = $childItems;
            }
        }

        $orderItem['other_info'] = \json_encode($orderItem['other_info']);
        $orderItem['line_meta'] = \json_encode($orderItem['line_meta']);

        return [$orderItem];
    }

    private static function getSlLimit($downloadId)
    {
        static $formattedMetaItems = [];

        if (isset($formattedMetaItems[$downloadId])) {
            return $formattedMetaItems[$downloadId];
        }

        $metaItems = fluentCart('db')->table('postmeta')
            ->where('meta_key', '_edd_sl_limit')
            ->get();

        foreach ($metaItems as $metaItem) {
            $formattedMetaItems[$metaItem->post_id] = (int)$metaItem->meta_value;
        }

        return Arr::get($formattedMetaItems, $downloadId, 0);
    }

    private static function getVariableSlLimit($downloadId, $priceId = '')
    {
        if (!$priceId) {
            $priceId = '';
        }

        static $formattedMetaItems = [];

        $cacheKey = $downloadId . '_' . $priceId;

        if (isset($formattedMetaItems[$cacheKey])) {
            return $formattedMetaItems[$cacheKey];
        }

        $metas = fluentCart('db')->table('postmeta')
            ->where('meta_key', 'edd_variable_prices')
            ->get();

        foreach ($metas as $meta) {
            $metaValues = maybe_unserialize($meta->meta_value);
            foreach ($metaValues as $priceId => $price) {
                $formattedMetaItems[$meta->post_id . '_' . $priceId] = Arr::get($price, 'license_limit', 0);
            }
        }

        return Arr::get($formattedMetaItems, $cacheKey, 0);
    }

    public static function getPeriodSlug($slug)
    {
        $maps = [
            'day'       => 'daily',
            'week'      => 'weekly',
            'month'     => 'monthly',
            'quarter'   => 'quarterly',
            'semi-year' => 'half-yearly',
            'year'      => 'yearly'
        ];

        if (isset($maps[$slug])) {
            return $maps[$slug];
        }

        return $slug;
    }

    public static function getPostDate($post, $key = 'post_date')
    {

        $gmtKey = $key . '_gmt';
        $date = null;

        if (isset($post->{$gmtKey}) && $post->{$gmtKey} != '0000-00-00 00:00:00') {
            $date = $post->{$gmtKey};
        } else if (isset($post->{$key}) && $post->{$key} != '0000-00-00 00:00:00') {
            $date = $post->{$key};
        }

        if ($date) {
            $year = (int)substr($date, 0, 4);
            if ($year < 2010) {
                $gmtKey = $key;
                if (isset($post->{$gmtKey}) && $post->{$gmtKey} != '0000-00-00 00:00:00') {
                    $date = $post->{$gmtKey};
                } else if (isset($post->{$key}) && $post->{$key} != '0000-00-00 00:00:00') {
                    $date = $post->{$key};
                }
            }

            return $date;
        }

        if ($date) {
            return $date;
        }

        return current_time('mysql');
    }

    public static function toCents($number, $currency = '')
    {
        $float = floatval($number);

        if (!$float) {
            return 0;
        }

        // Zero-decimal currencies (JPY, KRW, etc.) are already in smallest unit
        if ($currency && \FluentCart\App\Helpers\CurrenciesHelper::isZeroDecimal($currency)) {
            $cents = $float;
        } else {
            $cents = $float * 100;
        }

        $cents = apply_filters('fluentcart_migrator_edd3_to_cents', $cents, $number, $currency);
        return (int)round($cents, 0);
    }

    public static function getActivities($payment)
    {

        $orderActivities = fluentCart('db')->table('edd_notes')
            ->where('object_type', 'order')
            ->orderBy('id', 'ASC')
            ->where('object_id', $payment->id)
            ->get();


        $formattedActivities = [];
        foreach ($orderActivities as $orderActivity) {
            $formattedActivities[] = [
                'status'      => 'info',
                'log_type'    => 'activity',
                'module_type' => 'FluentCart\App\Models\Order',
                'module_id'   => $payment->id,
                'module_name' => 'Order',
                'user_id'     => $orderActivity->user_id,
                'title'       => '',
                'content'     => $orderActivity->content,
                'read_status' => 'read',
                'created_by'  => 'Migrator',
                'created_at'  => $orderActivity->date_created,
                'updated_at'  => $orderActivity->date_modified,
            ];
        }

        return $formattedActivities;
    }

    public static function getSubscriptionActivities($eddSubscriptionId, $fctSubscriptionId)
    {
        $subNotes = fluentCart('db')->table('edd_notes')
            ->where('object_type', 'subscription')
            ->where('object_id', $eddSubscriptionId)
            ->orderBy('id', 'ASC')
            ->get();

        $formattedActivities = [];
        foreach ($subNotes as $note) {
            $formattedActivities[] = [
                'status'      => 'info',
                'log_type'    => 'activity',
                'module_type' => 'FluentCart\App\Models\Subscription',
                'module_id'   => $fctSubscriptionId,
                'module_name' => 'subscription',
                'user_id'     => $note->user_id,
                'title'       => '',
                'content'     => $note->content,
                'read_status' => 'read',
                'created_by'  => 'Migrator',
                'created_at'  => $note->date_created,
                'updated_at'  => $note->date_modified,
            ];
        }

        // Also migrate the inline notes field from edd_subscriptions (if column exists)
        $eddSub = fluentCart('db')->table('edd_subscriptions')
            ->where('id', $eddSubscriptionId)
            ->first();

        if ($eddSub && isset($eddSub->notes) && !empty($eddSub->notes)) {
            $formattedActivities[] = [
                'status'      => 'info',
                'log_type'    => 'activity',
                'module_type' => 'FluentCart\App\Models\Subscription',
                'module_id'   => $fctSubscriptionId,
                'module_name' => 'subscription',
                'user_id'     => NULL,
                'title'       => 'EDD Subscription Note',
                'content'     => $eddSub->notes,
                'read_status' => 'read',
                'created_by'  => 'Migrator',
                'created_at'  => $eddSub->created,
                'updated_at'  => $eddSub->created,
            ];
        }

        return $formattedActivities;
    }

    public static function moveOrderToNewCustomer($orderId, $newCustomerId, $datTime = '')
    {
        if (!$datTime) {
            $datTime = current_time('mysql');
        }

        /**
         *  customer_id,
         */
        $oldOrders = fluentCart('db')->table('fct_orders')
            ->where('id', $orderId)
            ->orWhere('parent_id', $orderId)
            ->get();

        if ($oldOrders->isEmpty()) {
            return false;
        }

        $orderIds = [];
        foreach ($oldOrders as $oldOrder) {
            $orderIds[] = $oldOrder->id;

            $activity = [
                'status'      => 'info',
                'log_type'    => 'activity',
                'module_type' => 'FluentCart\App\Models\Order',
                'module_id'   => $oldOrder->id,
                'module_name' => 'Order',
                'title'       => '[Migration] Moved to new customer',
                'content'     => 'Oder and related assets has been moved to new customer: ' . $newCustomerId . ' from ' . $oldOrder->customer_id,
                'read_status' => 'read',
                'created_by'  => 'Migrator',
                'created_at'  => $datTime,
                'updated_at'  => $datTime,
            ];

            fluentCart('db')->table('fct_activity')->insert($activity);
        }

        fluentCart('db')->table('fct_orders')
            ->whereIn('id', $orderIds)
            ->update([
                'customer_id' => $newCustomerId
            ]);

        /**
         * Subscriptions => parent_order_id, customer_id
         */
        fluentCart('db')->table('fct_subscriptions')
            ->whereIn('parent_order_id', $orderIds)
            ->update([
                'customer_id' => $newCustomerId
            ]);

        // let's fix the licenses
        fluentCart('db')->table('fct_licenses')
            ->whereIn('order_id', $orderIds)
            ->update([
                'customer_id' => $newCustomerId
            ]);

        return true;
    }


    public static function deleteOrderById($orderId)
    {
        // Delete child/renewal orders first (recursive)
        $childOrderIds = fluentCart('db')->table('fct_orders')
            ->where('parent_id', $orderId)
            ->pluck('id')
            ->toArray();

        foreach ($childOrderIds as $childId) {
            self::deleteOrderById($childId);
        }

        // Delete subscriptions and their activities
        $subscriptions = fluentCart('db')->table('fct_subscriptions')
            ->where('parent_order_id', $orderId)
            ->get();

        foreach ($subscriptions as $subscription) {
            fluentCart('db')->table('fct_subscription_meta')
                ->where('subscription_id', $subscription->id)
                ->delete();

            fluentCart('db')->table('fct_activity')
                ->where('module_id', $subscription->id)
                ->where('module_type', 'FluentCart\App\Models\Subscription')
                ->delete();
        }

        fluentCart('db')->table('fct_subscriptions')
            ->where('parent_order_id', $orderId)
            ->delete();

        // Delete licenses and their activations
        $licenses = fluentCart('db')->table('fct_licenses')
            ->where('order_id', $orderId)
            ->get();

        foreach ($licenses as $license) {
            fluentCart('db')->table('fct_license_activations')
                ->where('license_id', $license->id)
                ->delete();
        }

        fluentCart('db')->table('fct_licenses')
            ->where('order_id', $orderId)
            ->delete();

        // Delete order-related records
        fluentCart('db')->table('fct_order_items')
            ->where('order_id', $orderId)
            ->delete();

        fluentCart('db')->table('fct_order_meta')
            ->where('order_id', $orderId)
            ->delete();

        fluentCart('db')->table('fct_order_operations')
            ->where('order_id', $orderId)
            ->delete();

        fluentCart('db')->table('fct_order_transactions')
            ->where('order_id', $orderId)
            ->delete();

        fluentCart('db')->table('fct_activity')
            ->where('module_id', $orderId)
            ->where('module_type', 'FluentCart\App\Models\Order')
            ->delete();

        fluentCart('db')->table('fct_order_addresses')
            ->where('order_id', $orderId)
            ->delete();

        fluentCart('db')->table('fct_order_tax_rate')
            ->where('order_id', $orderId)
            ->delete();

        fluentCart('db')->table('fct_order_download_permissions')
            ->where('order_id', $orderId)
            ->delete();

        fluentCart('db')->table('fct_applied_coupons')
            ->where('order_id', $orderId)
            ->delete();

        // Delete the order itself last
        fluentCart('db')->table('fct_orders')
            ->where('id', $orderId)
            ->delete();
    }


    public static function getCustomerByPaymentMeta($formattedMeta, $payment = null)
    {
        $customerId = (int)Arr::get($formattedMeta, '_edd_payment_customer_id', '');
        $customerEmail = Arr::get($formattedMeta, '_edd_payment_meta.email');

        $cacheKey = $customerId . '______' . $customerEmail;

        static $cache = [];
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        if ($customerId) {
            $eddCustomer = fluentCart('db')->table('edd_customers')
                ->where('id', $customerId)
                ->first();
        } else {
            $eddCustomer = fluentCart('db')->table('edd_customers')
                ->where('email', $customerEmail)
                ->first();
        }

        $customerData = [];
        if ($eddCustomer) {
            $cutsomerUserId = (int)$eddCustomer->user_id;
            if ($cutsomerUserId && $cutsomerUserId > 0) {
                $wpUser = get_user_by('ID', $cutsomerUserId);
                if ($wpUser) {
                    $customerData = [
                        'user_id'   => $wpUser->ID,
                        'email'     => $wpUser->user_email,
                        'full_name' => trim($wpUser->first_name . ' ' . $wpUser->last_name),
                    ];

                    if (empty($customerData['full_name'])) {
                        $customerData['full_name'] = $eddCustomer->name;
                    }

                    if (empty($customerData['full_name'])) {
                        $customerData['full_name'] = $wpUser->display_name;
                    }
                }
            }
        }

        if (!$customerData) {
            $customerData = [
                'email'     => $customerEmail,
                'full_name' => trim(Arr::get($formattedMeta, '_edd_payment_meta.user_info.first_name') . ' ' . Arr::get($formattedMeta, '_edd_payment_meta.user_info.last_name'))
            ];
        }

        $exist = fluentCart('db')->table('fct_customers')
            ->where('email', $customerData['email'])
            ->first();

        if ($exist) {
            $cache[$cacheKey] = $exist;
            return $cache[$cacheKey];
        }

        $user = get_user_by('email', $customerData['email']);

        if ($user) {
            $customerData['user_id'] = $user->ID;
            $customerData['first_name'] = $user->first_name;
            $customerData['last_name'] = $user->last_name;

            $userAddress = get_user_meta($user->ID, '_edd_user_address', true);
            if ($userAddress && !empty($userAddress['country'])) {
                $customerData['country'] = Arr::get($userAddress, 'country');
                $customerData['city'] = Arr::get($userAddress, 'city');
                $customerData['state'] = Arr::get($userAddress, 'state');
                $customerData['postcode'] = Arr::get($userAddress, 'zip');
            }
        }

        $customerData = self::maybeExplodeFullName($customerData);
        unset($customerData['full_name']);

        if ($payment) {
            $customerData['created_at'] = self::getPostDate($payment, 'post_date');
        }

        if (empty($customerData['email'])) {
            return new \WP_Error('edd_migrator_error', 'Customer email is empty.');
        }

        $customerId = fluentCart('db')->table('fct_customers')
            ->insertGetId($customerData);

        $cache[$cacheKey] = fluentCart('db')->table('fct_customers')
            ->find($customerId);

        return $cache[$cacheKey];
    }

    public static function maybeExplodeFullName($data)
    {
        if (!empty($data['first_name']) || !empty($data['last_name'])) {
            return $data;
        }

        $fullNameArray = explode(' ', $data['full_name']);

        if (!$fullNameArray) {
            return $data;
        }

        $data['first_name'] = array_shift($fullNameArray);
        if ($fullNameArray) {
            $data['last_name'] = implode(' ', $fullNameArray);
        }

        return $data;
    }

    public static function getGatewaySlug($gateway)
    {
        $maps = [
            'paypal_standard' => 'paypal',
            'paypal_commerce' => 'paypal'
        ];

        return isset($maps[$gateway]) ? $maps[$gateway] : $gateway;

    }

    public static function setCachedTaxAdjustments($paymentIds)
    {
        $adjustments = fluentCart('db')->table('edd_order_adjustments')
            ->whereIn('object_id', $paymentIds)
            ->where('object_type', 'order')
            ->where('type', 'tax')
            ->get();

        $formatted = [];
        foreach ($adjustments as $adj) {
            if (!isset($formatted[$adj->object_id])) {
                $formatted[$adj->object_id] = [];
            }
            $formatted[$adj->object_id][] = $adj;
        }

        self::$cachedTaxAdjustments = $formatted;
    }

    public static function getTaxAdjustments($paymentId)
    {
        if (isset(self::$cachedTaxAdjustments[$paymentId])) {
            return self::$cachedTaxAdjustments[$paymentId];
        }

        return fluentCart('db')->table('edd_order_adjustments')
            ->where('object_id', $paymentId)
            ->where('object_type', 'order')
            ->where('type', 'tax')
            ->get();
    }

    public static function getEddTaxBehavior()
    {
        static $behavior = null;
        if ($behavior !== null) {
            return $behavior;
        }

        $eddSettings = get_option('edd_settings', []);
        $taxesEnabled = !empty($eddSettings['enable_taxes']);

        if (!$taxesEnabled) {
            $behavior = 0; // no_tax
            return $behavior;
        }

        $pricesIncludeTax = Arr::get($eddSettings, 'prices_include_tax', 'no') === 'yes';
        $behavior = $pricesIncludeTax ? 2 : 1; // 2 = inclusive, 1 = exclusive

        return $behavior;
    }

    public static function getTaxRateMap()
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = get_option('_edd_fct_tax_rate_maps', []);
        if (!is_array($map)) {
            $map = [];
        }

        return $map;
    }

    public static function canMigrate()
    {
        if (defined('EDD_SL_PLUGIN_DIR')) {
            // we have to check if we have fluent-cart-pro installed
            if (!defined('FLUENTCART_PRO_PLUGIN_VERSION')) {
                return new \WP_Error('fluentcart_pro_required', 'FluentCart Pro is required to migrate EDD License data. Please install FluentCart Pro and try again.');
            }

            if (!ModuleSettings::isActive('license')) {
                $prevSettings = ModuleSettings::getAllSettings(false);
                if (empty($prevSettings['license'])) {
                    $prevSettings['license'][] = [];
                }
                $prevSettings['license']['active'] = 'yes';
                // We are activating the license module here!
                ModuleSettings::saveSettings($prevSettings);
                do_action('fluent_cart/module/activated/license', $prevSettings, $prevSettings);
                // invalidate the cache
                ModuleSettings::getAllSettings(false);
            }
        }

        if (!defined('EDD_PLUGIN_FILE')) {
            return new \WP_Error('edd_not_found', 'Easy Digital Downloads plugin not found. Please install and activate Easy Digital Downloads and try again.');
        }

        $productCount = fluentCart('db')->table('posts')
            ->where('post_type', 'download')
            ->count();

        if (!$productCount) {
            return new \WP_Error('no_edd_products', 'No EDD products found. Nothing to migrate.');
        }
        
        return true;
    }

}
