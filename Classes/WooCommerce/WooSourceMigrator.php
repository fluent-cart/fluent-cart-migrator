<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\Database\DBMigrator;
use FluentCartMigrator\Classes\Contracts\AbstractSourceMigrator;
use FluentCartMigrator\Classes\Dto\CustomerData;
use FluentCartMigrator\Classes\Load\CustomerWriter;
use FluentCartMigrator\Classes\Support\BatchRuntime;

/**
 * WooCommerce migration source.
 *
 * Reads via the WooCommerce CRUD API (wc_get_products/wc_get_orders/WC_Coupon)
 * so it is agnostic to HPOS vs legacy post storage. This first iteration wires
 * up detection + source-side stats; the per-entity migration steps (products,
 * orders, customers, coupons, tax) land in follow-up iterations and currently
 * inherit the "not implemented yet" defaults from AbstractSourceMigrator.
 */
class WooSourceMigrator extends AbstractSourceMigrator
{
    public function key()
    {
        return 'woocommerce';
    }

    /**
     * Minimum WooCommerce version supported by the migrator. WC 3.0 introduced
     * the CRUD API (wc_get_products/wc_get_orders) this source relies on.
     */
    const MIN_VERSION = '3.0';

    public function detect()
    {
        $active  = class_exists('WooCommerce');
        $version = ($active && defined('WC_VERSION')) ? WC_VERSION : null;

        if (!$active) {
            $compatibility = [
                'state'   => 'blocked',
                'title'   => 'WooCommerce not detected',
                'message' => 'WooCommerce is not active. Please install and activate WooCommerce, then return here.',
            ];
        } elseif ($version && version_compare($version, self::MIN_VERSION, '<')) {
            $compatibility = [
                'state'   => 'blocked',
                'title'   => sprintf('WooCommerce %s detected', $version),
                'message' => sprintf('Migration requires WooCommerce %s or later. Please upgrade WooCommerce first, then return here.', self::MIN_VERSION),
            ];
        } else {
            $compatibility = [
                'state'   => 'pass',
                'title'   => $version ? sprintf('WooCommerce detected (v%s)', $version) : 'WooCommerce detected',
                'message' => 'Your WooCommerce installation is compatible with the migration tool.',
            ];
        }

        return [
            'key'           => 'woocommerce',
            'name'          => 'WooCommerce',
            'detected'      => $active,
            'version'       => $version,
            'compatibility' => $compatibility,
        ];
    }

    public function canMigrate()
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', 'WooCommerce plugin not found. Please install and activate WooCommerce and try again.');
        }

        if (!$this->countProducts()) {
            return new \WP_Error('no_woo_products', 'No WooCommerce products found. Nothing to migrate.');
        }

        return true;
    }

    public function getStats()
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', 'WooCommerce is not active.');
        }

        $allOrderStatuses = array_keys(wc_get_order_statuses()); // ['wc-pending', ...]
        $paidStatuses     = ['wc-processing', 'wc-completed', 'wc-refunded'];

        $productsCount = $this->countProducts();
        $ordersCount   = $this->countOrders('shop_order', 'any');
        $paidOrders    = $this->countOrders('shop_order', $paidStatuses);
        $couponsCount  = (int) (wp_count_posts('shop_coupon')->publish ?? 0);

        $registeredCustomers = $this->countRegisteredCustomers();
        $fctCustomers        = $this->countFctCustomers();

        $hasSubscriptions   = function_exists('wcs_get_subscriptions') || class_exists('WC_Subscriptions');
        $subscriptionsCount = $hasSubscriptions ? $this->countOrders('shop_subscription', 'any') : 0;

        $statuses = array_map(function ($status) {
            return str_replace('wc-', '', $status);
        }, $allOrderStatuses);

        return [
            'products_count'      => $productsCount,
            'orders_count'        => $ordersCount,
            'transactions_count'  => $paidOrders,
            'customers_count'     => $registeredCustomers,
            'customers_breakdown' => [
                'source_total' => $registeredCustomers,
                'fct_total'    => $fctCustomers,
            ],
            'subscriptions_count' => $subscriptionsCount,
            'licenses_count'      => 0, // WooCommerce core has no licensing
            'coupons_count'       => $couponsCount,
            'gateways'            => $this->getGatewayIds(),
            'statuses'            => array_values($statuses),
            'types'               => ['shop_order'],
            'has_subscriptions'   => $hasSubscriptions,
            'has_licenses'        => false,
        ];
    }

    public function migrateProducts()
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', 'WooCommerce is not active.');
        }

        // Non-destructive store-settings migration runs with the first step.
        (new StoreSettingsMigrator())->migrate();

        if ($this->isStepDone('products')) {
            return [
                'success'         => true,
                'step'            => 'products',
                'total'           => 0,
                'migrated'        => 0,
                'failed'          => 0,
                'errors'          => [],
                'skipped'         => true,
                'migration_state' => $this->getState(),
            ];
        }

        $results = (new ProductMigrator())->migrate(true);

        $migrated = 0;
        $failed   = 0;
        $errors   = [];
        foreach ($results as $wcId => $result) {
            if (is_wp_error($result)) {
                $failed++;
                $errors[] = ['wc_id' => $wcId, 'message' => $result->get_error_message()];
            } else {
                $migrated++;
            }
        }

        $state = $this->markStep('products');

        return [
            'success'         => true,
            'step'            => 'products',
            'total'           => count($results),
            'migrated'        => $migrated,
            'failed'          => $failed,
            'errors'          => $errors,
            'migration_state' => $state,
        ];
    }

    /**
     * Migrate the WooCommerce coupon catalog into fct_coupons. Once present,
     * the order step's couponIdByCode() back-links applied_coupons.coupon_id on
     * the next payments run.
     */
    public function migrateCoupons()
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', 'WooCommerce is not active.');
        }

        if ($this->isStepDone('coupons')) {
            return [
                'success'         => true,
                'step'            => 'coupons',
                'total'           => 0,
                'migrated'        => 0,
                'failed'          => 0,
                'errors'          => [],
                'skipped'         => true,
                'migration_state' => $this->getState(),
            ];
        }

        $results  = (new CouponMigrator())->migrate();
        $migrated = 0;
        $failed   = 0;
        $errors   = [];
        foreach ($results as $code => $result) {
            if (is_wp_error($result)) {
                $failed++;
                $errors[] = ['code' => $code, 'message' => $result->get_error_message()];
            } else {
                $migrated++;
            }
        }

        $state = $this->markStep('coupons');

        return [
            'success'         => true,
            'step'            => 'coupons',
            'total'           => count($results),
            'migrated'        => $migrated,
            'failed'          => $failed,
            'errors'          => $errors,
            'migration_state' => $state,
        ];
    }

    /**
     * Migrate WooCommerce tax configuration + rates into FluentCart and build
     * the WC→fct rate-id map the order step consumes.
     */
    public function migrateTaxRates()
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', 'WooCommerce is not active.');
        }

        if ($this->isStepDone('tax_rates')) {
            return [
                'success'         => true,
                'step'            => 'tax_rates',
                'skipped'         => true,
                'migration_state' => $this->getState(),
            ];
        }

        $result = (new TaxRateMigrator())->migrate();
        if (is_wp_error($result)) {
            return $result;
        }

        $result['step']            = 'tax_rates';
        $result['migration_state'] = $this->markStep('tax_rates');

        return $result;
    }

    /**
     * Migrate registered WooCommerce customers who have no orders (and so were
     * never created by the order step) into fct_customers. Deduped by email via
     * CustomerWriter, so customers already created from orders are skipped.
     */
    public function migrateMissingCustomers()
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', 'WooCommerce is not active.');
        }

        $migrated = 0;
        $page     = 1;
        $perPage  = 200;

        while (true) {
            $users = get_users([
                'role'    => 'customer',
                'number'  => $perPage,
                'paged'   => $page,
                'orderby' => 'ID',
                'order'   => 'ASC',
                'fields'  => ['ID', 'user_email'],
            ]);

            if (!$users) {
                break;
            }

            foreach ($users as $user) {
                $email = $user->user_email;
                if (!$email) {
                    continue;
                }

                // Only count genuinely missing customers; order-derived ones exist.
                $exists = fluentCart('db')->table('fct_customers')->where('email', $email)->first();
                if ($exists) {
                    continue;
                }

                $wc = new \WC_Customer($user->ID);
                $result = CustomerWriter::findOrCreate(CustomerData::make([
                    'userId'    => (int) $user->ID,
                    'email'     => $email,
                    'firstName' => $wc->get_first_name() ?: $wc->get_billing_first_name(),
                    'lastName'  => $wc->get_last_name() ?: $wc->get_billing_last_name(),
                    'country'   => $wc->get_billing_country(),
                    'city'      => $wc->get_billing_city(),
                    'state'     => $wc->get_billing_state(),
                    'postcode'  => $wc->get_billing_postcode(),
                    'createdAt' => current_time('mysql'),
                    'updatedAt' => current_time('mysql'),
                ]));

                if (!is_wp_error($result)) {
                    $migrated++;
                }
            }

            $page++;
            BatchRuntime::freeMemory();
        }

        $state = $this->markStep('missing_customers');

        return [
            'success'         => true,
            'step'            => 'missing_customers',
            'migrated'        => $migrated,
            'migration_state' => $state,
        ];
    }

    /**
     * Guard that WooCommerce is active, then run the shared resumable loop in
     * AbstractSourceMigrator.
     */
    public function migratePayments($page = 1, $perPage = 100, $maxSeconds = 25)
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', 'WooCommerce is not active.');
        }

        return parent::migratePayments($page, $perPage, $maxSeconds);
    }

    /**
     * Read + transform + write one page of WooCommerce orders (the per-page
     * hook the base loop calls).
     *
     * @return array{processed:int,has_more:bool}
     */
    public function migrateOrdersPage($page, $perPage)
    {
        return (new OrderMigrator())->migratePage($page, $perPage);
    }

    /**
     * Reset the WooCommerce migration: wipe all migrated FluentCart data and
     * clear the WooCommerce migration state. Dev-mode only, mirroring the EDD
     * source so the same "Reset Migration" button works for WooCommerce.
     */
    public function reset()
    {
        if (!defined('FLUENT_CART_DEV_MODE') || !FLUENT_CART_DEV_MODE) {
            return new \WP_Error(
                'dev_mode_required',
                'Reset is only available in dev mode. Define FLUENT_CART_DEV_MODE in wp-config.php.',
                ['status' => 403]
            );
        }

        return $this->wipeMigratedData();
    }

    /**
     * Drop & recreate the FluentCart tables, then remove migrated products and
     * the WooCommerce id-mapping postmeta. Same approach as the EDD source.
     */
    private function wipeMigratedData()
    {
        global $wpdb;

        delete_option($this->stateOptionKey());
        delete_option($this->failedLogOptionKey());
        delete_option($this->summaryOptionKey());

        $wpdb->query("SET SESSION FOREIGN_KEY_CHECKS=0;");
        try {
            DBMigrator::refresh();
        } catch (\Exception $e) {
            // Ignore — tables may be partially present.
        }
        $wpdb->query("SET SESSION FOREIGN_KEY_CHECKS=1;");

        $cpt = FluentProducts::CPT_NAME;
        $wpdb->query("DELETE pm FROM {$wpdb->prefix}postmeta pm INNER JOIN {$wpdb->prefix}posts p ON pm.post_id = p.ID WHERE p.post_type = '{$cpt}'");
        $wpdb->query("DELETE FROM {$wpdb->prefix}posts WHERE post_type = '{$cpt}'");

        $postMetas = [
            MigratorHelper::WC_TO_FCT_META,     // on WC products
            MigratorHelper::FCT_FROM_WC_META,   // on FC products
            MigratorHelper::VARIATION_MAP_META, // on FC products
        ];
        foreach ($postMetas as $metaKey) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}postmeta WHERE meta_key = %s", $metaKey));
        }

        return [
            'success' => true,
            'message' => 'All migrated data and WooCommerce migration state have been reset.',
        ];
    }

    /**
     * Post-migration recount substeps supported by the WooCommerce source.
     * EDD's reactivation / subscription-UUID fixes don't apply here; we only
     * recompute the aggregates that the order import leaves at their defaults.
     */
    public function getRecountSubsteps()
    {
        return ['coupons', 'customers', 'subscriptions'];
    }

    /**
     * Run a recount substep. recountCustomers()/recountSubscriptions() operate
     * purely on the migrated FluentCart tables (customer LTV / purchase counts,
     * subscription bill counts), so they're source-agnostic and reused from the
     * shared migrator service rather than duplicated here.
     */
    public function recountStats($substep)
    {
        $recounter = new \FluentCartMigrator\Classes\MigratorService();

        switch ($substep) {
            case 'coupons':
                return $recounter->recountCoupons();

            case 'customers':
                return $recounter->recountCustomers();

            case 'subscriptions':
                $result = $recounter->recountSubscriptions();
                // Final substep — mark the step done and refresh the summary.
                $this->markStep('recount');
                $this->buildAndSaveSummary();
                return $result;

            default:
                return $this->notImplemented('recount:' . $substep);
        }
    }

    /* -----------------------------------------------------------------
     | Counting helpers (CRUD API — HPOS-safe)
     * ----------------------------------------------------------------- */

    protected function countProducts()
    {
        if (!function_exists('wc_get_products')) {
            return 0;
        }

        $result = wc_get_products([
            'limit'    => 1,
            'paginate' => true,
            'return'   => 'ids',
            'status'   => ['publish', 'private', 'draft'],
        ]);

        return (int) ($result->total ?? 0);
    }

    protected function countOrders($type, $status)
    {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $result = wc_get_orders([
            'limit'    => 1,
            'paginate' => true,
            'return'   => 'ids',
            'type'     => $type,
            'status'   => $status,
        ]);

        return (int) ($result->total ?? 0);
    }

    protected function countRegisteredCustomers()
    {
        $query = new \WP_User_Query([
            'role'        => 'customer',
            'number'      => 1,
            'count_total' => true,
            'fields'      => 'ID',
        ]);

        return (int) $query->get_total();
    }

    protected function countFctCustomers()
    {
        if (!function_exists('fluentCart')) {
            return 0;
        }

        return (int) fluentCart('db')->table('fct_customers')->count();
    }

    protected function getGatewayIds()
    {
        $ids = [];

        if (function_exists('WC') && WC()->payment_gateways) {
            foreach (WC()->payment_gateways->payment_gateways() as $gateway) {
                $ids[] = $gateway->id;
            }
        }

        return $ids;
    }
}
