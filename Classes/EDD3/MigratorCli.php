<?php

namespace FluentCartMigrator\Classes\EDD3;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Support\Arr;

class MigratorCli
{

    public $paymentMetas = [];

    private $defaultCurrency = '';

    public function __construct()
    {
        $this->defaultCurrency = edd_get_currency();
    }

    public function testPayment($paymentId)
    {
        $payment = fluentCart('db')->table('edd_orders')
            ->where('id', $paymentId)
            ->first();

        $paymentMigrator = new PaymentMigrate($payment, []);
        $paymentMigrator->setupData();

        return $paymentMigrator;

    }

    public function stats($assoc_args = [])
    {
        $statuses = fluentCart('db')->table('edd_orders')
            ->select(['status'])
            ->whereNotIn('status', ['abandoned', 'trash'])
            ->distinct()
            ->pluck('status')
            ->toArray();

        $types = fluentCart('db')->table('edd_orders')
            ->select(['type'])
            ->distinct()
            ->pluck('type')
            ->toArray();

        $gateways = fluentCart('db')->table('edd_orders')
            ->select(['gateway'])
            ->distinct()
            ->pluck('gateway')
            ->toArray();

        $orderCounts = fluentCart('db')->table('edd_orders')
            ->whereNotIn('status', ['abandoned', 'trash'])
            ->count();

        $transactions = fluentCart('db')->table('edd_order_transactions')
            ->count();


        \WP_CLI::line('Total Orders: ' . $orderCounts);
        \WP_CLI::line('Total Transactions: ' . $transactions);
        \WP_CLI::line('Gatways: ' . implode(', ', $gateways));
        \WP_CLI::line('Order Types: ' . implode(', ', $types));
        \WP_CLI::line('Available Statuses: ' . implode(', ', $statuses));
    }

    public function migrate_products($willUpdate = false)
    {

        update_option('_fcart_migrated_bundled_products', []);

        $products = fluentCart('db')->table('posts')
            ->where('post_type', 'download')
            ->get();


        $results = [];

        foreach ($products as $product) {
            $results[$product->ID] = $this->migrateProduct($product->ID, $willUpdate);
        }

        $this->cacheMigratedProductMaps();

        $bunddledItems = get_option('_fcart_migrated_bundled_products');

        if ($bunddledItems) {
            foreach ($bunddledItems as $eddId => $fctId) {
                $this->syncBundledProductAttributes($eddId, $fctId);
            }
        }


        return $results;
    }

    public function migratePayments($page = 1, $perPage = 1000)
    {
        $payments = fluentCart('db')->table('edd_orders')
            ->whereIn('status', ['complete', 'pending', 'edd_subscription', 'processing', 'revoked', 'partially_refunded', 'refunded', 'publish'])
            ->orderBy('ID', 'ASC')
            ->where('type', 'sale')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        $doingTest = false;
        $this->setupPaymentMetas($payments);

        foreach ($payments as $index => $payment) {
            if ($doingTest) {
                $this->print('Processing Payment ID: ' . $payment->id);
            }

            $formattedMeta = Arr::get($this->paymentMetas, $payment->id, []);

            $paymentMigrator = new PaymentMigrate($payment, $formattedMeta);
            $settedUp = $paymentMigrator->setupData();

            if (is_wp_error($settedUp)) {
                $this->logFailedMigration($payment->id, $settedUp->get_error_message(), [
                    'error_type' => $settedUp->get_error_code(),
                    'stage'      => 'data_setup'
                ]);
                continue;
            }

            $validated = $paymentMigrator->validate();

            if (is_wp_error($validated)) {

                $this->print('Validation Failed for:  ' . $payment->id . ' :: ' . $validated->get_error_message());

                $this->logFailedMigration($payment->id, $validated->get_error_message(), [
                    'error_type' => $validated->get_error_code(),
                    'stage'      => 'validation'
                ]);
                continue;
            }

            // let's the the migration
            $migrated = $paymentMigrator->migrate(true, true);

            if (is_wp_error($migrated)) {

                $this->print('FAILED:  ' . $payment->id . ' :: ' . $migrated->get_error_message());

                $this->logFailedMigration($payment->id, $migrated->get_error_message(), [
                    'error_type' => $migrated->get_error_code(),
                    'stage'      => 'migration'
                ]);
                continue;
            }

            if ($doingTest) {
                \WP_CLI::line('Payment ID: ' . $payment->id . ' - Migration Success');
            }
        }

        if ($doingTest) {
            die();
        }

        if ($payments->isEmpty()) {
            return null;
        }

        return $payments;
    }

    protected function setupPaymentMetas($payments)
    {
        $this->paymentMetas = [];
        $paymentIds = $payments->pluck('id')->toArray();

        if (!$paymentIds) {
            return;
        }

        $metas = fluentCart('db')->table('edd_ordermeta')
            ->whereIn('edd_order_id', $paymentIds)
            ->get();

        foreach ($metas as $meta) {
            if (!isset($this->paymentMetas[$meta->edd_order_id])) {
                $this->paymentMetas[$meta->edd_order_id] = [];
            }

            $this->paymentMetas[$meta->edd_order_id][$meta->meta_key] = maybe_unserialize($meta->meta_value);
        }

        MigratorHelper::setCachedSubscriptions($paymentIds);
        MigratorHelper::setCachedLicenses($paymentIds);
        MigratorHelper::setCachedTaxAdjustments($paymentIds);
    }

    public function verifyLicenses()
    {
        $page = 1;
        $ignoredProductIds = [];
        $connectedLicenseSkippedIds = [];

        while (true) {
            $eddLicenses = fluentCart('db')->table('edd_licenses')
                ->limit(100)
                ->offset(($page - 1) * 100)
                ->get();

            if (!$eddLicenses->count()) {
                break;
            }

            foreach ($eddLicenses as $eddLicense) {
                $fluentLicense = fluentCart('db')->table('fct_licenses')
                    ->where('license_key', $eddLicense->license_key)
                    ->first();

                if (!$fluentLicense) {

                    if ($eddLicense->status == 'expired') {
                        \WP_CLI::line('404 Expired: ' . $eddLicense->license_key . ' => ' . $eddLicense->id);
                        continue;
                    }

                    // maybe the payment is not exist
                    $payment = fluentCart('db')->table('posts')
                        ->where('post_type', 'edd_payment')
                        ->where('ID', $eddLicense->payment_id)
                        ->first();

                    if ($payment && $payment->post_status != 'failed' && !in_array($eddLicense->download_id, $ignoredProductIds)) {
                        \WP_CLI::line('License not found: ' . $eddLicense->license_key . ' => ' . $eddLicense->id . ' => ' . $payment->ID);
                    }

                    continue;
                }

                if ($fluentLicense->status != $eddLicense->status) {
                    \WP_CLI::line('License Status Mismatch: ' . $eddLicense->license_key . ' => EDD: ' . $eddLicense->status . ' => Fluent: ' . $fluentLicense->status);
                }
            }

            $page++;
            if ($page % 100 === 0) {
                \WP_CLI::line('Verified Page: ' . $page);
            }
        }
    }

    public function migrateCouponCodes()
    {
        $coupons = fluentCart('db')->table('edd_adjustments')
            ->orderBy('id', 'ASC')
            ->get();

        $createdIds = [];

        foreach ($coupons as $coupon) {
            $couponMetas = fluentCart('db')->table('edd_adjustmentmeta')
                ->where('edd_adjustment_id', $coupon->id)
                ->get();

            $formattedMeta = [];
            foreach ($couponMetas as $meta) {
                $formattedMeta[$meta->meta_key] = maybe_unserialize($meta->meta_value);
            }

            $discountType = $coupon->amount_type;
            $discountAmount = $coupon->amount;

            if ($discountType == 'flat') {
                $discountType = 'fixed';
            } else {
                $discountType = 'percentage';
            }

            $status = $coupon->status;
            if ($status != 'active') {
                $status = 'disabled';
            }

            // Build product restrictions from EDD meta
            $includedProducts = [];
            $excludedProducts = [];

            $productReqs = Arr::get($formattedMeta, 'product_reqs', []);
            $eddExcludedProducts = Arr::get($formattedMeta, 'excluded_products', []);

            // EDD scope: 'global' = applies to all, 'not_global' = restricted to product_reqs
            if ($coupon->scope === 'not_global' && !empty($productReqs)) {
                if (!is_array($productReqs)) {
                    $productReqs = array_filter(array_map('trim', explode(',', $productReqs)));
                }
                foreach ($productReqs as $eddProductRef) {
                    // EDD stores as "product_id" or "product_id_price_id"
                    $parts = explode('_', $eddProductRef);
                    $eddProductId = (int)$parts[0];
                    $fctProductId = get_post_meta($eddProductId, '_fcart_migrated_id', true);
                    if ($fctProductId) {
                        $includedProducts[] = (int)$fctProductId;
                    }
                }
                $includedProducts = array_values(array_unique($includedProducts));
            }

            if (!empty($eddExcludedProducts)) {
                if (!is_array($eddExcludedProducts)) {
                    $eddExcludedProducts = wp_parse_id_list($eddExcludedProducts);
                }
                foreach ($eddExcludedProducts as $eddProductId) {
                    $fctProductId = get_post_meta((int)$eddProductId, '_fcart_migrated_id', true);
                    if ($fctProductId) {
                        $excludedProducts[] = (int)$fctProductId;
                    }
                }
                $excludedProducts = array_values(array_unique($excludedProducts));
            }

            $couponData = [
                'title'      => $coupon->name,
                'code'       => $coupon->code,
                'priority'   => 1,
                'type'       => $discountType,
                'amount'     => $discountType == 'fixed' ? MigratorHelper::toCents($discountAmount) : $discountAmount,
                'conditions' => \json_encode([
                    'max_uses'            => $coupon->max_uses,
                    'max_per_customer'    => $coupon->once_per_customer ? 1 : 0,
                    'max_discount_amount' => 0,
                    'max_purchase_amount' => 0,
                    'min_purchase_amount' => 0,
                    'included_products'   => $includedProducts,
                    'excluded_products'   => $excludedProducts,
                    'included_categories' => [],
                    'excluded_categories' => [],
                    'is_recurring'        => Arr::get($formattedMeta, 'recurring_one_time', '') === 'first' ? 'no' : 'yes',
                ]),
                'start_date' => MigratorHelper::convertEddCouponDate($coupon->start_date),
                'end_date'   => MigratorHelper::convertEddCouponDate($coupon->end_date),
                'notes'      => $coupon->description ?: '',
                'status'     => $status,
                'use_count'  => $coupon->use_count,
                'created_at' => $coupon->date_created, //$coupon->post_date_gmt,
                'updated_at' => $coupon->date_modified
            ];

            if ($exist = fluentCart('db')->table('fct_coupons')->where('code', $couponData['code'])->first()) {
                $createdIds[] = $exist->id;
                fluentCart('db')->table('fct_coupons')
                    ->where('id', $exist->id)
                    ->update($couponData);
                continue;
            }

            $createdCouponId = fluentCart('db')->table('fct_coupons')
                ->insertGetId($couponData);

            $affliateId = Arr::get($formattedMeta, 'affwp_discount_affiliate', 0);
            if ($affliateId) {
                $existMeta = Meta::query()
                    ->where('object_type', 'coupon')
                    ->where('object_id', $createdCouponId)
                    ->where('meta_key', '_fa_affiliate_id')->first();

                if ($existMeta) {
                    $existMeta->meta_value = $affliateId;
                    $existMeta->save();
                } else {
                    Meta::query()->create([
                        'object_type' => 'coupon',
                        'object_id'   => $createdCouponId,
                        'meta_key'    => '_fa_affiliate_id',
                        'meta_value'  => $affliateId
                    ]);
                }
            }

            $createdIds[] = $createdCouponId;
        }

        return $createdIds;
    }

    public function migrateTaxRates()
    {
        $eddSettings = get_option('edd_settings', []);
        $taxesEnabled = !empty($eddSettings['enable_taxes']);

        if (!$taxesEnabled) {
            \WP_CLI::line('EDD Taxes are not enabled. Skipping tax rate migration.');
            return [];
        }

        // Enable FluentCart tax settings mapped from EDD
        $this->syncTaxSettings($eddSettings);

        // Initialize default tax classes (Standard, Reduced, Zero) via FluentCart's built-in method
        $taxClassController = new \FluentCart\App\Http\Controllers\TaxClassController();
        $taxClassController->checkAndCreateInitialTaxClasses();
        \WP_CLI::line('Default tax classes initialized.');

        // Collect unique countries from EDD tax rates
        $eddCountries = [];

        global $wpdb;
        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}edd_tax_rates'");
        if ($tableExists) {
            $eddRates = fluentCart('db')->table('edd_tax_rates')
                ->where('status', 'active')
                ->get();

            foreach ($eddRates as $eddRate) {
                if ($eddRate->country) {
                    $eddCountries[] = $eddRate->country;
                }
            }
        }

        // Also include EDD base country
        $baseCountry = Arr::get($eddSettings, 'base_country', '');
        if ($baseCountry) {
            $eddCountries[] = $baseCountry;
        }

        $eddCountries = array_unique(array_filter($eddCountries));

        // Generate default FluentCart tax rates for these countries using built-in rate data
        if ($eddCountries) {
            $taxManager = \FluentCart\App\Services\Tax\TaxManager::getInstance();
            $taxManager->generateTaxClasses($eddCountries);
            \WP_CLI::line('Generated default tax rates for countries: ' . implode(', ', $eddCountries));
        }

        // Build a mapping from EDD tax_rate IDs to FluentCart tax_rate IDs (by country+state match)
        $rateMap = [];
        if ($tableExists && !empty($eddRates)) {
            foreach ($eddRates as $eddRate) {
                $country = $eddRate->country ?: '';
                $state = $eddRate->state ?: '';

                $query = fluentCart('db')->table('fct_tax_rates')
                    ->where('country', $country);

                if ($state) {
                    $query->where('state', $state);
                }

                $fctRate = $query->first();

                if ($fctRate) {
                    $rateMap[$eddRate->id] = $fctRate->id;
                }
            }
        }

        // Store the mapping for order migration
        update_option('_edd_fct_tax_rate_maps', $rateMap);

        \WP_CLI::line('Mapped ' . count($rateMap) . ' EDD tax rates to FluentCart rates.');
        \WP_CLI::line('Admin can adjust tax rates in FluentCart settings after migration.');

        return $rateMap;
    }

    private function syncTaxSettings($eddSettings)
    {
        $pricesIncludeTax = Arr::get($eddSettings, 'prices_include_tax', 'no') === 'yes';

        $fctTaxSettings = get_option('fluent_cart_tax_configuration_settings', []);

        $fctTaxSettings['enable_tax']            = 'yes';
        $fctTaxSettings['tax_inclusion']          = $pricesIncludeTax ? 'included' : 'excluded';
        $fctTaxSettings['tax_calculation_basis']  = Arr::get($fctTaxSettings, 'tax_calculation_basis', 'billing');
        $fctTaxSettings['tax_rounding']           = Arr::get($fctTaxSettings, 'tax_rounding', 'item');
        $fctTaxSettings['price_suffix']           = Arr::get($fctTaxSettings, 'price_suffix', '');

        if (empty($fctTaxSettings['eu_vat_settings'])) {
            $fctTaxSettings['eu_vat_settings'] = [
                'require_vat_number'              => 'no',
                'local_reverse_charge'            => 'no',
                'vat_reverse_excluded_categories' => [],
                'method'                          => 'home',
                'home_country'                    => Arr::get($eddSettings, 'base_country', ''),
                'home_vat'                        => '',
                'oss_vat'                         => '',
            ];
        }

        update_option('fluent_cart_tax_configuration_settings', $fctTaxSettings, true);

        \WP_CLI::line('FluentCart tax settings enabled:');
        \WP_CLI::line('  - Prices include tax: ' . ($pricesIncludeTax ? 'yes' : 'no'));
        \WP_CLI::line('  - Calculation basis: ' . $fctTaxSettings['tax_calculation_basis']);
    }

    public function replaceVendorIpAddresses($countLimit = 30)
    {
        // Find the orders with most ip addresses as same
        $orders = Order::query()
            ->select('ip_address')
            ->selectRaw('COUNT(ip_address) as ip_count')
            ->groupBy('ip_address')
            ->orderByDesc('ip_count')
            ->limit(100)
            ->get();

        foreach ($orders as $order) {
            if ($order->ip_count <= $countLimit) {
                continue;
            }

            fluentCart('db')->table('fct_orders')
                ->where('ip_address', $order->ip_address)
                ->update([
                    'ip_address' => NULL
                ]);
        }

        return $orders->toArray();
    }

    protected function migrateProduct($productId = 0, $willUpdate = false)
    {
        $product = fluentCart('db')->table('posts')
            ->where('ID', $productId)
            ->where('post_type', 'download')
            ->first();

        if (!$product) {
            return new \WP_Error('edd_migrator_error', 'Product not found.');
        }

        $newProductData = [
            'post_title'        => $product->post_title,
            'post_content'      => $product->post_content,
            'post_status'       => $product->post_status,
            'post_type'         => FluentProducts::CPT_NAME,
            'post_excerpt'      => $product->post_excerpt,
            'post_name'         => $product->post_name,
            'post_author'       => $product->post_author,
            'post_date'         => MigratorHelper::getPostDate($product, 'post_date'), // $product->post_date_gmt,
            'post_modified_gmt' => gmdate('Y-m-d H:i:s'),
            'post_modified'     => current_time('mysql'),
            'post_date_gmt'     => MigratorHelper::getPostDate($product, 'post_date')
        ];

        $productMetas = fluentCart('db')
            ->table('postmeta')
            ->where('post_id', $productId)
            ->get();

        $formattedMeta = [];
        foreach ($productMetas as $meta) {
            $formattedMeta[$meta->meta_key] = maybe_unserialize($meta->meta_value);
        }

        $isBundled = Arr::get($formattedMeta, '_edd_product_type') === 'bundle';

        if ($isBundled) {
            // return new \WP_Error('edd_migrator_error', 'Bundle product is not supported yet.');
        }

        $isVariable = Arr::get($formattedMeta, '_variable_pricing') == 1;
        $licenseEnabled = Arr::get($formattedMeta, '_edd_sl_enabled', 0) == 1;

        $isUpdated = false;
        $createdPostId = null;
        if ($willUpdate) {
            // check previous updated product
            $existingMigrationId = get_post_meta($productId, '_fcart_migrated_id', true);
            if ($existingMigrationId) {
                $post = get_post($existingMigrationId);
                if ($post && $post->post_type == FluentProducts::CPT_NAME) {
                    // update the existing product
                    $newProductData['post_modified'] = current_time('mysql');
                    $newProductData['post_modified_gmt'] = gmdate('Y-m-d H:i:s');
                    wp_update_post(array_merge($newProductData, ['ID' => $existingMigrationId]));
                    $createdPostId = $existingMigrationId;
                    $isUpdated = true;
                }
            }
        }

        if (!$createdPostId) {
            $createdPostId = wp_insert_post($newProductData);
        }

        update_post_meta($createdPostId, '_edd_migrated_from', $product->ID);
        update_post_meta($product->ID, '_fcart_migrated_id', $createdPostId);

        // Migrate featured image
        $thumbnailId = get_post_thumbnail_id($product->ID);
        if ($thumbnailId) {
            set_post_thumbnail($createdPostId, $thumbnailId);
        }


        $priceVariations = [];
        $licenseVariations = [];

        $maxPrice = 0;
        $minPrice = 0;
        $eddDefaultPriceId = Arr::get($formattedMeta, '_edd_default_price_id', 0);

        if ($isVariable) {
            $prices = Arr::get($formattedMeta, 'edd_variable_prices', []);
            foreach ($prices as $priceIndex => $price) {
                $isRecurring = Arr::get($price, 'recurring', '') == 'yes';
                $priceAmount = absint(MigratorHelper::toCents($price['amount']));
                if (!$maxPrice) {
                    $maxPrice = $priceAmount;
                }
                if (!$minPrice) {
                    $minPrice = $priceAmount;
                }

                if ($priceAmount > $maxPrice) {
                    $maxPrice = $priceAmount;
                }

                if ($priceAmount < $minPrice) {
                    $minPrice = $priceAmount;
                }

                $priceVariations[$priceIndex] = [
                    'post_id'              => $createdPostId,
                    'media_id'             => NULL,
                    'serial_index'         => $price['index'],
                    'variation_title'      => $price['name'],
                    'variation_identifier' => $priceIndex,
                    'manage_stock'         => 0,
                    'payment_type'         => $isRecurring ? 'subscription' : 'onetime',
                    'fulfillment_type'     => 'digital',
                    'stock_status'         => 'in-stock',
                    'backorders'           => 0,
                    'total_stock'          => NULL,
                    'on_hold'              => 0,
                    'committed'            => 0,
                    'available'            => 0,
                    'item_status'          => 'active',
                    'item_price'           => $priceAmount,
                    'downloadable'         => true,
                    'created_at'           => MigratorHelper::getPostDate($product, 'post_date'), // $product->post_date_gmt,
                    'updated_at'           => current_time('mysql'),
                    'other_info'           => json_encode($this->getPriceVariationDetails($price)),
                ];

                if ($licenseEnabled && !$isBundled) {
                    $isLifetime = Arr::get($price, 'is_lifetime', '') == 1;
                    $licenseVariations[$priceIndex] = [
                        'variation_id'     => '',
                        'activation_limit' => Arr::get($price, 'license_limit', 0),
                        'validity'         => [
                            'unit'  => $isLifetime ? 'lifetime' : Arr::get($price, 'period', 'year'),
                            'value' => 1
                        ]
                    ];
                }
            }
        } else {
            $maxPrice = $minPrice = absint(MigratorHelper::toCents(Arr::get($formattedMeta, 'edd_price')));
            $price = [
                'index'          => 1,
                'name'           => '',
                'amount'         => $minPrice,
                'recurring'      => Arr::get($formattedMeta, 'edd_recurring', 'no'),
                'period'         => Arr::get($formattedMeta, 'edd_period', 'yearly'),
                'trial-quantity' => Arr::get($formattedMeta, '_edd_trial_quantity', 0),
            ];

            $priceVariations[1] = [
                'post_id'              => $createdPostId,
                'serial_index'         => $price['index'],
                'variation_title'      => $price['name'],
                'variation_identifier' => '0',
                'manage_stock'         => 0,
                'payment_type'         => $price['recurring'] == 'yes' ? 'subscription' : 'onetime',
                'fulfillment_type'     => 'digital',
                'stock_status'         => 'in-stock',
                'backorders'           => 0,
                'total_stock'          => NULL,
                'on_hold'              => 0,
                'committed'            => 0,
                'available'            => 0,
                'item_status'          => 'active',
                'item_price'           => $maxPrice,
                'downloadable'         => true,
                'created_at'           => MigratorHelper::getPostDate($product, 'post_date'), //$product->post_date_gmt,
                'updated_at'           => current_time('mysql'),
                'other_info'           => json_encode($this->getPriceVariationDetails($price)),
            ];
        }

        if ($isUpdated) {
            $existingMigrationMaps = get_post_meta($createdPostId, '__edd_migrated_variation_maps', true);
        }

        $createdVariations = [];
        foreach ($priceVariations as $eddIndex => $priceVariation) {
            $identifier = $priceVariation['variation_identifier'];
            if ($isUpdated && isset($existingMigrationMaps[$identifier])) {
                fluentCart('db')->table('fct_product_variations')
                    ->where('id', $existingMigrationMaps[$identifier])
                    ->update($priceVariation);

                $createdVariations[$identifier] = $existingMigrationMaps[$identifier];
            } else {
                $createdVariations[$eddIndex] = fluentCart('db')->table('fct_product_variations')
                    ->insertGetId($priceVariation);
            }
        }

        $formattedLicenseVariations = [];
        if ($licenseVariations) {
            foreach ($licenseVariations as $eddIndex => $licenseVariation) {
                if (isset($createdVariations[$eddIndex])) {
                    $licenseVariation['variation_id'] = $createdVariations[$eddIndex];
                    $formattedLicenseVariations[$licenseVariation['variation_id']] = $licenseVariation;
                }
            }
        }

        $licenseConfig = null;
        $licenseChangelog = '';

        if ($licenseEnabled && !$isBundled) {
            $licenseConfig = [
                'enabled'            => 'yes',
                'version'            => Arr::get($formattedMeta, '_edd_sl_version', ''),
                'global_update_file' => [],
                'variations'         => $formattedLicenseVariations,
                'wp'                 => [
                    'is_wp'        => 'no',
                    'readme_url'   => '',
                    'banner_url'   => '',
                    'icon_url'     => '',
                    'required_php' => '',
                    'required_wp'  => '',
                ],
                'prefix'             => ''
            ];
            $licenseChangelog = Arr::get($formattedMeta, '_edd_sl_changelog', '');
        }

        // let's create the product details
        $detail = [
            'post_id'              => $createdPostId,
            'fulfillment_type'     => 'digital',
            'min_price'            => $minPrice,
            'max_price'            => $maxPrice,
            'default_variation_id' => ($eddDefaultPriceId && isset($createdVariations[$eddDefaultPriceId])) ? $createdVariations[$eddDefaultPriceId] : '',
            'default_media'        => \json_encode([]),
            'manage_stock'         => 0,
            'stock_availability'   => 'in-stock',
            'variation_type'       => $isVariable ? 'simple_variations' : 'simple',
            'manage_downloadable'  => 1,
            'other_info'           => json_encode([
                'group_pricing_by'  => 'repeat_interval',
                'use_pricing_table' => 'yes'
            ]),
            'created_at'           => MigratorHelper::getPostDate($product, 'post_date'), //$product->post_date_gmt,
            'updated_at'           => gmdate('Y-m-d H:i:s'),
        ];

        $createdDetailId = null;

        if ($isUpdated) {
            $exist = fluentCart('db')->table('fct_product_details')
                ->where('post_id', $createdPostId)
                ->first();

            if ($exist) {
                $createdDetailId = $exist->id;
                fluentCart('db')->table('fct_product_details')
                    ->where('id', $createdDetailId)
                    ->update($detail);
            }
        }

        if (!$createdDetailId) {
            // create the product detail
            $createdDetailId = fluentCart('db')->table('fct_product_details')
                ->insertGetId($detail);
        }

        // create the license settings
        if ($licenseConfig) {
            if ($isUpdated) {
                // remove the existing
                fluentCart('db')->table('fct_product_meta')
                    ->where('object_id', $createdPostId)
                    ->where('meta_key', 'license_settings')
                    ->delete();
            }

            fluentCart('db')->table('fct_product_meta')
                ->insert([
                    'object_id'  => $createdPostId,
                    'meta_key'   => 'license_settings',
                    'meta_value' => json_encode($licenseConfig),
                    'created_at' => MigratorHelper::getPostDate($product, 'post_date'), //$product->post_date_gmt,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);

            if ($licenseChangelog) {
                fluentCart('db')->table('fct_product_meta')
                    ->insert([
                        'object_id'  => $createdPostId,
                        'meta_key'   => '_fluent_sl_changelog',
                        'meta_value' => $licenseChangelog,
                        'created_at' => MigratorHelper::getPostDate($product, 'post_date'), //$product->post_date_gmt,
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
            }
        }

        if ($createdVariations) {
            update_post_meta($createdPostId, '__edd_migrated_variation_maps', $createdVariations);
        }

        if ($isUpdated) {
            // remove the existing downloads
            fluentCart('db')->table('fct_product_downloads')
                ->where('post_id', $createdPostId)
                ->delete();
        }

        if (!$isBundled) {
            foreach (Arr::get($formattedMeta, 'edd_download_files', []) as $index => $file) {
                $attachmentId = Arr::get($file, 'attachment_id', 0);
                $driver = 'local';
                $bucket = '';
                $attachedFileId = get_attached_file($attachmentId, true);

                if ($attachmentId && is_numeric($attachmentId) && $attachedFileId) {
                    $filePath = $attachedFileId;
                    $fileUrl = wp_get_attachment_url($attachmentId);
                } else {
                    if (!defined('EDD_AS3_VERSION')) {
                        continue;
                    }

                    $fileInfo = $file['file'];
                    $arr = explode('/', $fileInfo);
                    $bucket = array_shift($arr);
                    $filePath = implode('/', $arr);
                    $fileUrl = implode('/', $arr);
                    $driver = 's3';
                }

                if (empty($filePath)) {
                    continue;
                }

                // Map EDD file condition to FCT variation IDs
                // EDD condition: "all" = all variations, or a specific price_id like "1", "2"
                $fileCondition = Arr::get($file, 'condition', 'all');
                $variationIds = [];
                if ($fileCondition !== 'all' && $fileCondition !== '' && !empty($createdVariations)) {
                    $eddPriceId = (int)$fileCondition;
                    if (isset($createdVariations[$eddPriceId])) {
                        $variationIds[] = (int)$createdVariations[$eddPriceId];
                    }
                }

                // get the extension name from file path
                $fileName = basename($filePath);
                $fileExtension = explode('.', $fileName);
                $fileExtension = $fileExtension[count($fileExtension) - 1];

                $downloadFile = [
                    'download_identifier'  => md5($filePath . $createdPostId . wp_generate_uuid4() . $index),
                    'post_id'              => $createdPostId,
                    'product_variation_id'  => json_encode($variationIds),
                    'driver'               => $driver,
                    'title'                => $fileName,
                    'type'                 => $fileExtension,
                    'file_name'            => $fileName,
                    'file_path'            => $filePath,
                    'file_url'             => $fileUrl,
                    'serial'               => $index,
                    'settings'             => json_encode([
                        'bucket'          => $bucket,
                        'download_limit'  => '',
                        'download_expiry' => '',
                    ]),
                    'created_at'           => MigratorHelper::getPostDate($product, 'post_date'),
                    'updated_at'           => current_time('mysql')
                ];

                fluentCart('db')->table('fct_product_downloads')
                    ->insertGetId($downloadFile);
            }
        }

        if ($isBundled) {
            // we will add this to the options settings
            $prevSettings = get_option('_fcart_migrated_bundled_products', []);

            if (!$prevSettings || !is_array($prevSettings)) {
                $prevSettings = [];
            }

            $prevSettings[$product->ID] = $createdPostId;

            update_option('_fcart_migrated_bundled_products', $prevSettings);
        }

        return $createdPostId;
    }

    protected function getPriceVariationDetails($price)
    {
        $isRecurring = Arr::get($price, 'recurring', '') == 'yes';

        if ($isRecurring) {
            $repeatInterval = MigratorHelper::getPeriodSlug(Arr::get($price, 'period', 'year'));

            $times = Arr::get($price, 'times', 0);

            $repeatIUnits = ['lifetime', 'weekly', "monthly", "yearly"];
            $repeatIUnit = Arr::get($repeatIUnits, $repeatInterval, 'yearly');
            if ($times) {
                $billingSummary = "{$price['amount']} {$repeatIUnit} for {$times} Times";
            } else {
                $billingSummary = "{$price['amount']} {$repeatIUnit} until cancelled";
            }

            $signupFee = absint(MigratorHelper::toCents(Arr::get($price, 'signup_fee', 0)));

            $priceInfo = [
                'payment_type'     => 'subscription',
                'times'            => $times,
                'repeat_interval'  => $repeatInterval,
                'billing_summary'  => $billingSummary,
                'manage_setup_fee' => $signupFee > 0 ? 'yes' : 'no',
                'setup_fee'        => $signupFee,
                'installment'      => $times ? 'yes' : 'no',
                'trial_days'       => Arr::get($price, 'trial-quantity', 0),
            ];
        } else {
            $priceInfo = [
                'payment_type' => 'onetime',
            ];
        }

        return $priceInfo;
    }

    protected function logFailedMigration($paymentId, $message = 'unknow reason', $args = [])
    {
        $logs = get_option('_fluent_edd_failed_payment_logs', []);

        if (!$logs) {
            $logs = [];
        }

        $args['message'] = $message;
        $logs[$paymentId] = $args;
        update_option('_fluent_edd_failed_payment_logs', $logs, 'no');

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line($paymentId . ' => ' . $message);
        }

        return $logs;
    }

    public function cacheMigratedProductMaps()
    {
        $metaItems = fluentCart('db')->table('postmeta')
            ->where('meta_key', '_fcart_migrated_id')
            ->orderBy('meta_id', 'ASC')
            ->get();

        $maps = [];

        foreach ($metaItems as $metaItem) {
            $migratedProductId = $metaItem->meta_value;
            if (!$migratedProductId || !is_numeric($migratedProductId)) {
                continue;
            }

            $variations = get_post_meta($metaItem->meta_value, '__edd_migrated_variation_maps', true);
            if (!is_array($variations)) {
                $variations = [];
            }

            $maps[$metaItem->post_id] = [
                'product_id'     => $migratedProductId,
                'variation_maps' => $variations
            ];
        }

        fluent_cart_update_option('_edd_product_migration_maps', $maps);
    }

    private function print($text)
    {
        \WP_CLI::line($text);
    }


    private function syncBundledProductAttributes($eddId, $fctId)
    {
        $attributes = fluentCart('db')->table('postmeta')
            ->where('post_id', $eddId)
            ->get();

        $metaData = [];
        foreach ($attributes as $attribute) {
            $metaData[$attribute->meta_key] = maybe_unserialize($attribute->meta_value);
        }

        $bundledConditions = Arr::get($metaData, '_edd_bundled_products_conditions', []);

        $variablePrices = Arr::get($metaData, 'edd_variable_prices', []);

        $variablePriceIdentifiers = array_keys($variablePrices);

        $bundledMaps = Arr::get($metaData, '_edd_bundled_products', []);
        $fctMaps = get_post_meta($fctId, '__edd_migrated_variation_maps', true);

        $formattedBundledMaps = [];

        foreach ($bundledMaps as $productIndex => $map) {
            $maps = explode('_', $map);
            $formattedBundledMaps[$productIndex] = [
                'edd_product_id' => Arr::get($maps, 0, ''),
                'edd_price_id'   => Arr::get($maps, 1, '')
            ];
        }

        $formattedConditions = [];
        foreach ($bundledConditions as $priceIndex => $condition) {
            if ($condition == 'all') {
                $condition = $variablePriceIdentifiers;
            } else {
                $condition = [$condition];
            }

            foreach ($condition as $conditionItem) {
                if (!isset($formattedConditions[$conditionItem])) {
                    $formattedConditions[$conditionItem] = [];
                }

                $formattedConditions[$conditionItem][] = $priceIndex;
            }
        }

        $accessMaps = [];

        foreach ($formattedConditions as $index => $priceIds) {
            if (empty($fctMaps[$index])) {
                continue;
            }

            $eddItems = array_values(Arr::only($formattedBundledMaps, array_values($priceIds)));

            $fctVariationIds = [];

            foreach ($eddItems as $eddItem) {
                $eddProductId = Arr::get($eddItem, 'edd_product_id');
                $eddVariationId = Arr::get($eddItem, 'edd_price_id');
                $migratedFcId = get_post_meta($eddProductId, '_fcart_migrated_id', true);

                if ($eddVariationId) {
                    $variationMaps = get_post_meta($migratedFcId, '__edd_migrated_variation_maps', true);

                    if (!$variationMaps) {
                        return new \WP_Error('edd_migrator_error', 'Variation maps not found.');
                    }
                    $variationId = Arr::get($variationMaps, $eddVariationId, 0);
                    $fctVariationIds[] = $variationId;
                } else {
                    $allItemvariations = fluentCart('db')->table('fct_product_variations')
                        ->where('post_id', $migratedFcId)
                        ->get()
                        ->pluck('id')
                        ->toArray();

                    if ($allItemvariations) {
                        foreach ($allItemvariations as $variationId) {
                            if (!in_array($variationId, $fctVariationIds)) {
                                $fctVariationIds[] = $variationId;
                            }
                        }
                    }
                }
            }

            $accessMaps[$fctMaps[$index]] = array_values(array_unique($fctVariationIds));
        }

        $productDetail = ProductDetail::query()->where('post_id', $fctId)->first();

        if (!$productDetail) {
            return new \WP_Error('edd_migrator_error', 'Product detail not found for bundled product.');
        }

        foreach ($accessMaps as $variationId => $accessVariationIds) {
            $variation = ProductVariation::query()->where('id', $variationId)->first();
            if ($variation) {
                $otherInfo = $variation->other_info;

                if (!is_array($otherInfo)) {
                    $otherInfo = [];
                }

                $otherInfo['bundle_child_ids'] = $accessVariationIds;
                $otherInfo['is_bundle_product'] = 'yes';
                $variation->other_info = $otherInfo;
                $variation->save();
            }
        }

        $otherInfo = $productDetail->other_info;

        if (!is_array($otherInfo)) {
            $otherInfo = [];
        }

        $otherInfo['is_bundle_product'] = 'yes';
        $productDetail->other_info = $otherInfo;
        $productDetail->save();

        return true;
    }
}
