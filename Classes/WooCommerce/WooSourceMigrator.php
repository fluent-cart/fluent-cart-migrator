<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\Database\DBMigrator;
use FluentCartMigrator\Classes\Contracts\AbstractSourceMigrator;
use FluentCartMigrator\Classes\Dto\CustomerData;
use FluentCartMigrator\Classes\Load\CustomerWriter;
use FluentCartMigrator\Classes\Support\BatchRuntime;
use FluentCartMigrator\Classes\Support\MigrationLog;
use FluentCartMigrator\Classes\Support\TaxonomyMap;

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
                'title'   => __('WooCommerce not detected', 'fluent-cart-migrator'),
                'message' => __('WooCommerce is not active. Please install and activate WooCommerce, then return here.', 'fluent-cart-migrator'),
            ];
        } elseif ($version && version_compare($version, self::MIN_VERSION, '<')) {
            $compatibility = [
                'state'   => 'blocked',
                /* translators: %s: WooCommerce version number */
                'title'   => sprintf(__('WooCommerce %s detected', 'fluent-cart-migrator'), $version),
                /* translators: %s: minimum supported WooCommerce version */
                'message' => sprintf(__('Migration requires WooCommerce %s or later. Please upgrade WooCommerce first, then return here.', 'fluent-cart-migrator'), self::MIN_VERSION),
            ];
        } else {
            $compatibility = [
                'state'   => 'pass',
                /* translators: %s: WooCommerce version number */
                'title'   => $version ? sprintf(__('WooCommerce detected (v%s)', 'fluent-cart-migrator'), $version) : __('WooCommerce detected', 'fluent-cart-migrator'),
                'message' => __('Your WooCommerce installation is compatible with the migration tool.', 'fluent-cart-migrator'),
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
            return new \WP_Error('woocommerce_not_found', __('WooCommerce plugin not found. Please install and activate WooCommerce and try again.', 'fluent-cart-migrator'));
        }

        if (!$this->countProducts()) {
            return new \WP_Error('no_woo_products', __('No WooCommerce products found. Nothing to migrate.', 'fluent-cart-migrator'));
        }

        return true;
    }

    public function getStats()
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', __('WooCommerce is not active.', 'fluent-cart-migrator'));
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

    /**
     * Migrate the product catalog, paginated and resumable so large stores don't
     * try to migrate in a single non-resumable PHP request (the headline scale
     * risk). Mirrors the payments loop: time/memory-boxed, persists
     * last_product_page, and the front-end re-calls while has_more is true.
     * Bundle wiring + step completion run only on the final page.
     *
     * @param int|null $page null = resume from saved state (the REST/UI path)
     */
    public function migrateProducts($page = null, $perPage = 20, $maxSeconds = 25)
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', __('WooCommerce is not active.', 'fluent-cart-migrator'));
        }

        if ($this->isStepDone('products')) {
            return [
                'success'         => true,
                'step'            => 'products',
                'migrated'        => 0,
                'failed'          => 0,
                'errors'          => [],
                'has_more'        => false,
                'skipped'         => true,
                'migration_state' => $this->getState(),
            ];
        }

        if ($page === null) {
            $page = $this->getProductResumePage();
        }

        // Non-destructive store-settings migration runs once, with the first page.
        if ($page <= 1) {
            (new StoreSettingsMigrator())->migrate();
        }

        $migrator = new ProductMigrator();
        $start    = time();
        $migrated = 0;
        $failed   = 0;
        $errors   = [];
        $hasMore  = true;

        do {
            $batch     = $migrator->migratePage($page, $perPage);
            $migrated += (int) $batch['migrated'];
            $failed   += (int) $batch['failed'];
            if (!empty($batch['errors'])) {
                $errors = array_merge($errors, $batch['errors']);
            }
            $hasMore = !empty($batch['has_more']);

            $state                      = $this->getState();
            $state['last_product_page'] = $page;
            $this->saveState($state);

            $page++;
        } while ($hasMore && (time() - $start) < $maxSeconds && !BatchRuntime::memoryNearLimit());

        // Only when the whole catalog is in: wire bundle relationships (children
        // must all exist first) and mark the step complete.
        if (!$hasMore) {
            $migrator->syncBundles();
            $this->markStep('products');
            // Every product written in this pass already got its mapped terms,
            // so the taxonomies step has nothing left to do — mark it done and
            // skip a redundant walk of the catalog. Editing the mapping
            // re-opens it (TaxonomyMap::save()).
            $state = $this->markStep(TaxonomyMap::STEP);
        } else {
            $state = $this->getState();
        }

        return [
            'success'         => true,
            'step'            => 'products',
            'migrated'        => $migrated,
            'failed'          => $failed,
            'errors'          => $errors,
            'has_more'        => $hasMore,
            'migration_state' => $state,
        ];
    }

    /**
     * Next product page to process: 1 when the step is done (it then skips), else
     * the page after the last completed one. Mirrors getPaymentResumePage().
     */
    public function getProductResumePage()
    {
        $state = $this->getState();

        if (($state['products'] ?? '') === 'yes') {
            return 1;
        }

        return (int) ($state['last_product_page'] ?? 0) + 1;
    }

    /**
     * Migrate the WooCommerce coupon catalog into fct_coupons. Once present,
     * the order step's couponIdByCode() back-links applied_coupons.coupon_id on
     * the next payments run.
     */
    public function migrateCoupons()
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', __('WooCommerce is not active.', 'fluent-cart-migrator'));
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
            return new \WP_Error('woocommerce_not_found', __('WooCommerce is not active.', 'fluent-cart-migrator'));
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
            return new \WP_Error('woocommerce_not_found', __('WooCommerce is not active.', 'fluent-cart-migrator'));
        }

        $migrated = 0;
        $skipped  = 0;
        $page     = 1;
        $perPage  = 200;

        while (true) {
            // 'subscriber' included alongside 'customer': stores that let people
            // register before buying keep them on WP's default role, and those
            // accounts would otherwise be lost entirely.
            $users = get_users([
                'role__in' => ['customer', 'subscriber'],
                'number'   => $perPage,
                'paged'    => $page,
                'orderby'  => 'ID',
                'order'    => 'ASC',
                'fields'   => ['ID', 'user_email', 'user_login', 'display_name', 'user_registered'],
            ]);

            if (!$users) {
                break;
            }

            foreach ($users as $user) {
                $email = $user->user_email;
                if (!$email) {
                    $skipped++;
                    $this->logSkippedCustomer($user, 'customer_user_no_email', sprintf('User #%d (%s) has no email address.', (int) $user->ID, $user->user_login));
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
                    'addresses' => $this->customerProfileAddresses($wc, $email),
                ]));

                if (is_wp_error($result)) {
                    $skipped++;
                    $this->logSkippedCustomer($user, $result->get_error_code(), $result->get_error_message());
                } else {
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
            'skipped'         => $skipped,
            'log_counts'      => MigrationLog::counts($this->getLogEntries()),
            'migration_state' => $state,
        ];
    }

    /**
     * Record a registered user that could not be migrated as a customer.
     */
    private function logSkippedCustomer($user, $code, $message)
    {
        $this->logFailed(MigrationLog::key('customer', (int) $user->ID), $message, [
            'type'    => 'customer',
            'id'      => (int) $user->ID,
            'code'    => $code,
            'stage'   => 'customer_migration',
            'context' => [
                'email' => (string) ($user->user_email ?? ''),
                'name'  => (string) ($user->display_name ?: $user->user_login),
                'date'  => !empty($user->user_registered) ? substr($user->user_registered, 0, 10) : '',
                'url'   => get_edit_user_link((int) $user->ID),
            ],
        ]);
    }

    /**
     * Skip report for the WooCommerce source. Logs written before the report
     * existed have no order context (number/status/date/total/email); fill it
     * in from WooCommerce once and persist, so older installs get the same
     * report as fresh runs.
     */
    public function getLogs()
    {
        $this->backfillLogContext();

        return parent::getLogs();
    }

    private function backfillLogContext()
    {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $logs    = $this->getFailedLogs();
        $changed = false;

        foreach ($logs as $key => $entry) {
            if (!is_array($entry) || isset($entry['context']) || (($entry['type'] ?? 'order') !== 'order')) {
                continue;
            }

            $orderId = (int) ($entry['id'] ?? $key);
            $order   = $orderId ? wc_get_order($orderId) : null;
            $context = [];
            if ($order) {
                $date    = $order->get_date_created();
                $context = [
                    'number'   => (string) $order->get_order_number(),
                    'status'   => (string) $order->get_status(),
                    'date'     => $date ? $date->date('Y-m-d') : '',
                    'total'    => (string) $order->get_total(),
                    'currency' => (string) $order->get_currency(),
                    'email'    => (string) $order->get_billing_email(),
                    'name'     => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'url'      => method_exists($order, 'get_edit_order_url') ? (string) $order->get_edit_order_url() : '',
                ];
            }

            $entry['type']    = 'order';
            $entry['id']      = $orderId;
            $entry['code']    = $entry['code'] ?? ($entry['error_type'] ?? '');
            $entry['context'] = $context; // empty array still marks it as processed
            $logs[$key]       = $entry;
            $changed          = true;
        }

        if ($changed) {
            update_option($this->failedLogOptionKey(), $logs, false);
        }
    }

    /**
     * Totals for the skip report header: orders in WooCommerce (any status,
     * which includes statuses that are no longer registered) vs orders that
     * actually landed in FluentCart from this source.
     */
    protected function logTotals()
    {
        $migrated = 0;
        if (function_exists('fluentCart')) {
            // config is a JSON column/text; match loosely on the key+value so
            // encoder spacing ("migrated_from": "woocommerce") doesn't matter.
            $migrated = (int) fluentCart('db')->table('fct_orders')
                ->where('config', 'like', '%migrated_from%')
                ->where('config', 'like', '%woocommerce%')
                ->count();
        }

        return [
            'source_orders'   => $this->countOrders('shop_order', 'any'),
            'migrated_orders' => $migrated,
        ];
    }

    /**
     * Address-book rows for an orderless registered customer, read from the
     * WooCommerce customer profile (user meta). The writer drops entries with
     * neither address_1 nor city.
     *
     * @return array<int,array<string,mixed>>
     */
    private function customerProfileAddresses(\WC_Customer $wc, $email)
    {
        $addresses = [
            [
                'is_primary' => 1,
                'type'       => 'billing',
                'name'       => trim($wc->get_billing_first_name() . ' ' . $wc->get_billing_last_name()),
                'address_1'  => $wc->get_billing_address_1(),
                'address_2'  => $wc->get_billing_address_2(),
                'city'       => $wc->get_billing_city(),
                'state'      => $wc->get_billing_state(),
                'phone'      => $wc->get_billing_phone(),
                'email'      => $email,
                'postcode'   => $wc->get_billing_postcode(),
                'country'    => $wc->get_billing_country(),
            ],
        ];

        if ($wc->get_shipping_address_1()) {
            $addresses[] = [
                'is_primary' => 0,
                'type'       => 'shipping',
                'name'       => trim($wc->get_shipping_first_name() . ' ' . $wc->get_shipping_last_name()),
                'address_1'  => $wc->get_shipping_address_1(),
                'address_2'  => $wc->get_shipping_address_2(),
                'city'       => $wc->get_shipping_city(),
                'state'      => $wc->get_shipping_state(),
                'phone'      => method_exists($wc, 'get_shipping_phone') ? $wc->get_shipping_phone() : '',
                'postcode'   => $wc->get_shipping_postcode(),
                'country'    => $wc->get_shipping_country(),
            ];
        }

        return $addresses;
    }

    /**
     * Guard that WooCommerce is active, then run the shared resumable loop in
     * AbstractSourceMigrator.
     */
    public function migratePayments($page = 1, $perPage = 100, $maxSeconds = 25)
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', __('WooCommerce is not active.', 'fluent-cart-migrator'));
        }

        $result = parent::migratePayments($page, $perPage, $maxSeconds);

        // On completion, mask IPs shared across many orders (likely a gateway/
        // proxy IP, not a customer's) for privacy — mirrors the EDD source.
        if (!is_wp_error($result) && empty($result['has_more']) && $this->isStepDone('payments')) {
            $this->maskSharedIps();
        }

        return $result;
    }

    /**
     * Null out IP addresses that appear on more than $countLimit migrated
     * WooCommerce orders. Scoped to WooCommerce-migrated orders only, so EDD
     * (production) order data is never touched.
     */
    private function maskSharedIps($countLimit = 30)
    {
        $db = fluentCart('db');

        $countLimit = (int) $countLimit;
        $shared = $db->table('fct_orders')
            ->where('config', 'like', '%woocommerce%')
            ->where('ip_address', '!=', '')
            ->whereNotNull('ip_address')
            ->selectRaw('ip_address, COUNT(*) as c')
            ->groupBy('ip_address')
            ->havingRaw('COUNT(*) > ' . $countLimit)
            ->get();

        foreach ($shared as $row) {
            // ip_address is TEXT NOT NULL DEFAULT '' — clear to '' (not null).
            $db->table('fct_orders')
                ->where('config', 'like', '%woocommerce%')
                ->where('ip_address', $row->ip_address)
                ->update(['ip_address' => '']);
        }
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
                __('Reset is only available in dev mode. Define FLUENT_CART_DEV_MODE in wp-config.php.', 'fluent-cart-migrator'),
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

        $refresh = $this->refreshFluentCartSchema();
        if (is_wp_error($refresh)) {
            return $refresh;
        }

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
            'message' => __('All migrated data and WooCommerce migration state have been reset.', 'fluent-cart-migrator'),
        ];
    }

    /**
     * Drop & recreate the FluentCart schema, then verify the core tables came
     * back.
     *
     * DBMigrator::refresh() drops every fct_* table and recreates them. If the
     * recreate throws partway (the drop having already succeeded), the store is
     * left with a half-missing schema — and FluentCart's Tax module then fatals
     * on every `init` because fct_meta is gone, taking the whole site down.
     * Swallowing that failure silently (as the original reset did) hides a
     * bricked site behind a "success" response. So: attempt the recreate, then
     * verify a couple of sentinel tables exist, retrying the up-migration once
     * before surfacing a WP_Error the caller can show the user.
     *
     * @return true|\WP_Error
     */
    private function refreshFluentCartSchema()
    {
        global $wpdb;

        // migrateDown() unconditionally truncates the licensing tables when the
        // License class is loaded, even on stores that never created them (e.g.
        // WooCommerce sources) — that raises an expected wpdb error we handle
        // below, so silence the notices instead of spamming the log.
        $showErrors     = $wpdb->hide_errors();
        $suppressErrors = $wpdb->suppress_errors(true);

        $wpdb->query("SET SESSION FOREIGN_KEY_CHECKS=0;");

        $error = null;
        try {
            DBMigrator::refresh();
        } catch (\Throwable $e) {
            $error = $e;
        }

        // Post-condition check: the recreate must have brought the core tables
        // back. If not, try the up-migration once more before giving up.
        if (!$this->coreFluentCartTablesExist()) {
            try {
                DBMigrator::migrateUp();
            } catch (\Throwable $e) {
                $error = $e;
            }
        }

        $wpdb->query("SET SESSION FOREIGN_KEY_CHECKS=1;");

        $wpdb->suppress_errors($suppressErrors);
        if ($showErrors) {
            $wpdb->show_errors();
        }

        if (!$this->coreFluentCartTablesExist()) {
            return new \WP_Error(
                'fct_schema_recreate_failed',
                __('Reset dropped the FluentCart tables but failed to recreate them', 'fluent-cart-migrator')
                . ($error ? ': ' . $error->getMessage() : '.')
                . ' ' . __('Re-activate FluentCart (or run its DB migration) to restore the schema before retrying.', 'fluent-cart-migrator'),
                ['status' => 500]
            );
        }

        return true;
    }

    /**
     * True when the FluentCart tables the store cannot boot without are present.
     * fct_meta in particular is read on every `init` by the Tax module.
     */
    private function coreFluentCartTablesExist()
    {
        global $wpdb;

        foreach (['fct_meta', 'fct_orders', 'fct_subscriptions'] as $table) {
            $full = $wpdb->prefix . $table;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $full)) !== $full) {
                return false;
            }
        }

        return true;
    }

    /**
     * Post-migration recount substeps supported by the WooCommerce source.
     * EDD's subscription-UUID fix doesn't apply (the writer always sets a uuid).
     * `reactivations` runs before `subscriptions` so orphan renewal transactions
     * are re-attached to their subscription before bill counts are recomputed.
     */
    public function getRecountSubsteps()
    {
        return ['coupons', 'customers', 'reactivations', 'subscriptions'];
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

            case 'reactivations':
                // Re-attach paid renewal transactions left without a subscription_id
                // (e.g. parent subscription migrated after the renewal) to the
                // right subscription. Source-agnostic, reused read-only from EDD.
                return $recounter->fixReactivations();

            case 'subscriptions':
                $result = $recounter->recountSubscriptions();
                $result['offsets_seeded'] = $this->seedBillCountOffsets();
                // Final substep — mark the step done and refresh the summary.
                $this->markStep('recount');
                $this->buildAndSaveSummary();
                return $result;

            default:
                return $this->notImplemented('recount:' . $substep);
        }
    }

    /**
     * FluentCart recomputes bill_count on every renewal by counting succeeded
     * charge transactions (Subscription::calculateBillCount), so any WooCommerce
     * payment whose order didn't migrate (unregistered status, failed order)
     * would silently vanish from the count on the first renewal. Seed
     * billed_cycles_offset — a meta that formula already adds — with the gap
     * between WooCommerce's own completed-payment tally (stamped on the
     * subscription as wc_completed_payment_count during the orders step) and
     * the migrated succeeded charge transactions, then align bill_count.
     *
     * Idempotent: the offset is recomputed from the same two numbers each run.
     *
     * @return int subscriptions that received a non-zero offset
     */
    private function seedBillCountOffsets()
    {
        $db      = fluentCart('db');
        $page    = 1;
        $perPage = 100;
        $seeded  = 0;

        do {
            $rows = $db->table('fct_subscription_meta')
                ->where('meta_key', 'wc_completed_payment_count')
                ->orderBy('subscription_id', 'ASC')
                ->limit($perPage)
                ->offset(($page - 1) * $perPage)
                ->get();

            foreach ($rows as $row) {
                $subscription = \FluentCart\App\Models\Subscription::find($row->subscription_id);
                if (!$subscription) {
                    continue;
                }

                $wcCount = (int) $row->meta_value;
                $txCount = (int) $db->table('fct_order_transactions')
                    ->where('subscription_id', $subscription->id)
                    ->where('transaction_type', 'charge')
                    ->where('status', 'succeeded')
                    ->where('total', '>', 0)
                    ->count();

                $offset = max(0, $wcCount - $txCount);
                if ($offset > 0) {
                    $subscription->updateMeta('billed_cycles_offset', $offset);
                    $seeded++;
                } elseif ($subscription->getMeta('billed_cycles_offset')) {
                    // A previous run seeded an offset the migrated transactions
                    // now cover — clear it so the count isn't inflated.
                    $subscription->deleteMeta('billed_cycles_offset');
                }

                $billCount = $txCount + $offset;
                $hasChanges = false;

                if ($subscription->bill_count != $billCount) {
                    $subscription->bill_count = $billCount;
                    $hasChanges = true;
                }

                if ($subscription->bill_times > 0
                    && $subscription->bill_count >= $subscription->bill_times
                    && $subscription->status !== 'completed'
                ) {
                    $subscription->status = 'completed';
                    $hasChanges = true;
                }

                if ($hasChanges) {
                    unset($subscription->preventsLazyLoading);
                    $subscription->save();
                }
            }

            $page++;
        } while (count($rows) === $perPage);

        return $seeded;
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
        // Same roles migrateMissingCustomers() walks, so the stat matches what
        // the migration will actually pick up.
        $query = new \WP_User_Query([
            'role__in'    => ['customer', 'subscriber'],
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
