<?php

namespace FluentCartMigrator\Classes;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Database\DBMigrator;
use FluentCartMigrator\Classes\Edd3\MigratorCli;
use FluentCartMigrator\Classes\Edd3\MigratorHelper;

class MigratorService
{
    private function loadEddClasses()
    {
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/EDD3/MigratorCli.php';
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/EDD3/MigratorHelper.php';
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/EDD3/PaymentMigrate.php';
    }

    public function canMigrate()
    {
        $this->loadEddClasses();
        return MigratorHelper::canMigrate();
    }

    public function getSources()
    {
        global $wpdb;

        $eddActive    = class_exists('Easy_Digital_Downloads') || defined('EDD_VERSION');
        $eddVersion   = defined('EDD_VERSION') ? EDD_VERSION : null;
        $hasV3Tables  = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}edd_orders'") !== null;

        $sources = [
            [
                'key'           => 'edd',
                'name'          => 'Easy Digital Downloads',
                'detected'      => $eddActive || $hasV3Tables,
                'version'       => $eddVersion,
                'has_v3_tables' => $hasV3Tables,
            ],
            [
                'key'         => 'woocommerce',
                'name'        => 'WooCommerce',
                'detected'    => false,
                'coming_soon' => true,
            ],
            [
                'key'         => 'surecart',
                'name'        => 'SureCart',
                'detected'    => false,
                'coming_soon' => true,
            ],
        ];

        return ['sources' => $sources];
    }

    public function getEddStats()
    {
        global $wpdb;

        $this->loadEddClasses();

        $eddCli = new MigratorCli();
        $stats  = $eddCli->stats();

        $customersCount    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}edd_customers");
        $productsCount     = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type = %s", 'download')
        );

        $hasSubscriptions = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}edd_subscriptions'") !== null;
        $subscriptionsCount = 0;
        if ($hasSubscriptions) {
            $subscriptionsCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions");
        }

        $hasLicenses = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}edd_licenses'") !== null;
        $licensesCount = 0;
        if ($hasLicenses) {
            $licensesCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}edd_licenses");
        }

        $couponsCount = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}edd_adjustments WHERE type = %s", 'discount')
        );

        return [
            'products_count'      => $productsCount,
            'orders_count'        => $stats['order_count'],
            'transactions_count'  => $stats['transaction_count'],
            'customers_count'     => $customersCount,
            'subscriptions_count' => $subscriptionsCount,
            'licenses_count'      => $licensesCount,
            'coupons_count'       => $couponsCount,
            'gateways'            => $stats['gateways'],
            'statuses'            => $stats['statuses'],
            'types'               => $stats['types'],
            'has_subscriptions'   => $hasSubscriptions,
            'has_licenses'        => $hasLicenses,
        ];
    }

    public function getStatus()
    {
        $migration = get_option('__fluent_cart_edd3_migration_steps', false);
        $failedLogs = get_option('_fluent_edd_failed_payment_logs', []);

        return [
            'migration'        => $migration,
            'failed_log_count' => is_array($failedLogs) ? count($failedLogs) : 0,
        ];
    }

    public function migrateProducts()
    {
        $this->maybeMigrateStoreSettings();

        $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', []);
        if (is_array($migrationSteps) && ($migrationSteps['products'] ?? '') === 'yes') {
            return [
                'success'         => true,
                'step'            => 'products',
                'total'           => 0,
                'migrated'        => 0,
                'failed'          => 0,
                'errors'          => [],
                'skipped'         => true,
                'migration_state' => $migrationSteps,
            ];
        }

        $this->loadEddClasses();

        $eddCli  = new MigratorCli();
        $results = $eddCli->migrate_products(true);

        $success = 0;
        $failed  = 0;
        $errors  = [];

        foreach ($results as $eddId => $result) {
            if (is_wp_error($result)) {
                $failed++;
                $errors[] = [
                    'edd_id'  => $eddId,
                    'message' => $result->get_error_message(),
                ];
            } else {
                $success++;
            }
        }

        $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', []);
        if (!is_array($migrationSteps)) {
            $migrationSteps = [];
        }
        $migrationSteps['products'] = 'yes';
        update_option('__fluent_cart_edd3_migration_steps', $migrationSteps);

        return [
            'success'         => true,
            'step'            => 'products',
            'total'           => count($results),
            'migrated'        => $success,
            'failed'          => $failed,
            'errors'          => $errors,
            'migration_state' => $migrationSteps,
        ];
    }

    public function migrateTaxRates()
    {
        $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', []);
        if (is_array($migrationSteps) && ($migrationSteps['tax_rates'] ?? '') === 'yes') {
            return [
                'success'         => true,
                'step'            => 'tax_rates',
                'skipped'         => true,
                'migration_state' => $migrationSteps,
            ];
        }

        $this->loadEddClasses();

        $eddCli = new MigratorCli();
        $result = $eddCli->migrateTaxRates();

        $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', []);
        if (!is_array($migrationSteps)) {
            $migrationSteps = [];
        }
        $migrationSteps['tax_rates'] = 'yes';
        update_option('__fluent_cart_edd3_migration_steps', $migrationSteps);

        $result['step'] = 'tax_rates';
        $result['migration_state'] = $migrationSteps;

        return $result;
    }

    public function migrateCoupons()
    {
        $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', []);
        if (is_array($migrationSteps) && ($migrationSteps['coupons'] ?? '') === 'yes') {
            return [
                'success'         => true,
                'step'            => 'coupons',
                'total'           => 0,
                'migrated'        => 0,
                'skipped'         => true,
                'migration_state' => $migrationSteps,
            ];
        }

        $this->loadEddClasses();

        $eddCli     = new MigratorCli();
        $createdIds = $eddCli->migrateCouponCodes();

        $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', []);
        if (!is_array($migrationSteps)) {
            $migrationSteps = [];
        }
        $migrationSteps['coupons'] = 'yes';
        update_option('__fluent_cart_edd3_migration_steps', $migrationSteps);

        return [
            'success'         => true,
            'step'            => 'coupons',
            'total'           => count($createdIds),
            'migrated'        => count($createdIds),
            'migration_state' => $migrationSteps,
        ];
    }

    /**
     * Migrate payments/orders.
     *
     * In "timed" mode (default for REST/UI), processes pages in a loop for up to
     * $maxSeconds, then returns so the client can resume. This removes the need
     * for a user-facing batch-size setting.
     *
     * CLI callers pass a high $maxSeconds (or 0 to disable) and a large $perPage
     * since they don't have HTTP timeout concerns.
     */
    public function migratePayments($page = 1, $perPage = 100, $maxSeconds = 25)
    {
        $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', []);
        if (is_array($migrationSteps) && ($migrationSteps['payments'] ?? '') === 'yes') {
            return [
                'success'         => true,
                'step'            => 'payments',
                'page'            => $page,
                'processed'       => 0,
                'has_more'        => false,
                'errors_in_batch' => 0,
                'skipped'         => true,
                'migration_state' => $migrationSteps,
            ];
        }

        $this->loadEddClasses();

        $startedAt      = time();
        $totalProcessed = 0;
        $hasMore        = true;

        while ($hasMore) {
            MigratorHelper::resetCaches();
            $eddCli  = new MigratorCli();
            $results = $eddCli->migratePayments($page, $perPage);

            $batchCount = $results ? $results->count() : 0;
            $hasMore    = $results !== null && !$results->isEmpty();
            $totalProcessed += $batchCount;

            $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', []);
            if (!is_array($migrationSteps)) {
                $migrationSteps = [];
            }
            $migrationSteps['last_order_page'] = $page;
            update_option('__fluent_cart_edd3_migration_steps', $migrationSteps);

            if (!$hasMore) {
                break;
            }

            $page++;

            // Time-box: return to the client if we've been running long enough
            if ($maxSeconds > 0 && (time() - $startedAt) >= $maxSeconds) {
                break;
            }
        }

        // Finalize when no more rows
        $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', []);
        if (!is_array($migrationSteps)) {
            $migrationSteps = [];
        }

        if (!$hasMore) {
            $migrationSteps['payments'] = 'yes';
            $eddCli = $eddCli ?? new MigratorCli();
            $eddCli->replaceVendorIpAddresses();
            update_option('__fluent_cart_edd3_migration_steps', $migrationSteps);
            $this->buildAndSaveSummary();
        }

        $failedLogs    = get_option('_fluent_edd_failed_payment_logs', []);
        $errorsInBatch = is_array($failedLogs) ? count($failedLogs) : 0;

        return [
            'success'          => true,
            'step'             => 'payments',
            'page'             => $page,
            'processed'        => $totalProcessed,
            'has_more'         => $hasMore,
            'errors_in_batch'  => $errorsInBatch,
            'migration_state'  => $migrationSteps,
        ];
    }

    public function recountStats($substep)
    {
        $result = null;

        switch ($substep) {
            case 'fix_reactivations':
                $result = $this->fixReactivations();
                break;
            case 'fix_subs_uuid':
                $result = $this->fixSubsUuid();
                break;
            case 'coupons':
                $result = $this->recountCoupons();
                break;
            case 'customers':
                $result = $this->recountCustomers();
                break;
            case 'subscriptions':
                $result = $this->recountSubscriptions();
                // Mark recount as done and refresh summary after final substep
                $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', []);
                if (!is_array($migrationSteps)) {
                    $migrationSteps = [];
                }
                $migrationSteps['recount'] = 'yes';
                update_option('__fluent_cart_edd3_migration_steps', $migrationSteps);
                $this->buildAndSaveSummary();
                break;
            default:
                return ['success' => false, 'message' => 'Unknown substep'];
        }

        return $result;
    }

    public function recountCoupons()
    {
        $appliedCoupons = AppliedCoupon::whereHas('order', function ($query) {
            $query->whereIn('payment_status', ['paid', 'partially_refunded', 'require_capture']);
        })
            ->selectRaw('coupon_id, code, COUNT(*) as count')
            ->groupBy('coupon_id')
            ->whereNotNull('coupon_id')
            ->get();

        foreach ($appliedCoupons as $appliedCoupon) {
            fluentCart('db')->table('fct_coupons')
                ->where('id', $appliedCoupon->coupon_id)
                ->update(['use_count' => $appliedCoupon->count]);
        }

        return [
            'success'  => true,
            'substep'  => 'coupons',
            'recounted' => $appliedCoupons->count(),
        ];
    }

    /**
     * @param callable|null $onProgress Called per customer for progress reporting
     */
    public function recountCustomers($onProgress = null)
    {
        $completed  = false;
        $page       = 1;
        $perPage    = 100;
        $totalCount = 0;

        while (!$completed) {
            $customers = Customer::orderBy('id', 'ASC')
                ->limit($perPage)
                ->offset(($page - 1) * $perPage)
                ->get();

            if ($customers->isEmpty()) {
                $completed = true;
                break;
            }

            foreach ($customers as $customer) {
                $orders = \FluentCart\App\Models\Order::query()
                    ->where('customer_id', $customer->id)
                    ->with('transactions')
                    ->get();

                $totalPayments = [];
                $ltv = 0;

                foreach ($orders as $order) {
                    $netPaid = $order->total_paid - $order->total_refund;
                    if ($netPaid <= 0) {
                        continue;
                    }
                    $ltv += $netPaid;

                    foreach ($order->transactions as $transaction) {
                        if ($transaction->status == 'succeeded') {
                            if (empty($totalPayments[$order['currency']])) {
                                $totalPayments[$order['currency']] = $transaction['total'];
                            } else {
                                $totalPayments[$order['currency']] += $transaction['total'];
                            }
                        }
                    }

                    $totalPayments = array_map('intval', $totalPayments);
                }

                $purchaseCount = $orders->count();
                App::db()->table('fct_customers')->where('id', $customer->id)->update([
                    'user_id'             => $customer->getWpUserId(true),
                    'purchase_value'      => $totalPayments,
                    'ltv'                 => $ltv,
                    'aov'                 => $purchaseCount > 0 ? (int)($ltv / $purchaseCount) : 0,
                    'purchase_count'      => $purchaseCount,
                    'first_purchase_date' => $orders->min('created_at') . '',
                    'last_purchase_date'  => $orders->max('created_at') . '',
                ]);

                $totalCount++;

                if ($onProgress) {
                    $onProgress($customer);
                }
            }

            $page++;
        }

        return [
            'success'   => true,
            'substep'   => 'customers',
            'recounted' => $totalCount,
        ];
    }

    /**
     * @param callable|null $onProgress Called per subscription for progress reporting
     */
    public function recountSubscriptions($onProgress = null)
    {
        $completed  = false;
        $page       = 1;
        $perPage    = 100;
        $totalCount = 0;

        while (!$completed) {
            $subscriptions = Subscription::orderBy('id', 'ASC')
                ->limit($perPage)
                ->offset(($page - 1) * $perPage)
                ->get();

            if ($subscriptions->isEmpty()) {
                $completed = true;
                break;
            }

            $keyedSubscriptions = [];
            $parentOrderIds     = [];

            foreach ($subscriptions as $subscription) {
                if ($onProgress) {
                    $onProgress($subscription);
                }

                $keyedSubscriptions[$subscription->parent_order_id] = $subscription;
                $parentOrderIds[] = $subscription->parent_order_id;
            }

            $renewals = \FluentCart\App\Models\Order::query()
                ->where(function ($query) use ($parentOrderIds) {
                    $query->whereIn('id', $parentOrderIds)
                        ->orWhereIn('parent_id', $parentOrderIds);
                })
                ->whereIn('payment_status', ['paid', 'partially_refunded'])
                ->get();

            $counts = [];
            foreach ($renewals as $renewal) {
                $key = $renewal->parent_id ?: $renewal->id;
                if (!isset($counts[$key])) {
                    $counts[$key] = 0;
                }
                $counts[$key]++;
            }

            foreach ($counts as $orderId => $count) {
                if (!isset($keyedSubscriptions[$orderId])) {
                    continue;
                }

                $hasChanges = false;
                $subscription = $keyedSubscriptions[$orderId];

                if ($subscription->bill_count != $count) {
                    $subscription->bill_count = $count;
                    $hasChanges = true;
                }

                if ($subscription->bill_times > 0 && $subscription->bill_count >= $subscription->bill_times) {
                    $subscription->status = 'completed';
                    $hasChanges = true;
                }

                if ($hasChanges) {
                    unset($subscription->preventsLazyLoading);
                    $subscription->save();
                }

                $totalCount++;
            }

            $page++;
        }

        return [
            'success'   => true,
            'substep'   => 'subscriptions',
            'recounted' => $totalCount,
        ];
    }

    /**
     * @param callable|null $onLog Called with ($type, $message) for logging skipped items
     */
    public function fixReactivations($onLog = null)
    {
        $orphans = OrderTransaction::where('order_type', 'renewal')
            ->whereDoesntHave('subscription')
            ->get();

        $fixedCount = 0;

        foreach ($orphans as $orphan) {
            $order = $orphan->order;
            $parentOrderId = $order->parent_id;

            if (!$parentOrderId) {
                if ($onLog) {
                    $onLog('skip', 'No Parent Order for Order ID: ' . $order->id);
                }
                continue;
            }

            $rightSubscription = Subscription::where('parent_order_id', $parentOrderId)
                ->orderBy('id', 'DESC')
                ->first();

            if (!$rightSubscription) {
                if ($onLog) {
                    $onLog('skip', 'No Subscription Found for Parent Order ID: ' . $parentOrderId . ' - Order ID: ' . $order->id);
                }
                continue;
            }

            $orphan->subscription_id = $rightSubscription->id;
            $orphan->save();

            $rightSubscription->reSyncFromRemote();
            $fixedCount++;
        }

        return [
            'success' => true,
            'fixed'   => $fixedCount,
            'total'   => $orphans->count(),
        ];
    }

    public function fixSubsUuid()
    {
        $subscriptions = Subscription::query()
            ->whereNull('uuid')
            ->orWhere('uuid', '')
            ->get();

        foreach ($subscriptions as $subscription) {
            $subscription->uuid = md5($subscription->id . wp_generate_uuid4() . microtime(true));
            $subscription->save();
        }

        return [
            'success' => true,
            'fixed'   => $subscriptions->count(),
        ];
    }

    /**
     * @param callable|null $onIssue Called with ($type, $message) for each issue found
     */
    public function verifyLicenses($onIssue = null)
    {
        $this->loadEddClasses();

        $issues = [];
        $page = 1;
        $totalChecked = 0;

        while (true) {
            $eddLicenses = fluentCart('db')->table('edd_licenses')
                ->limit(100)
                ->offset(($page - 1) * 100)
                ->get();

            if (!$eddLicenses->count()) {
                break;
            }

            foreach ($eddLicenses as $eddLicense) {
                $totalChecked++;
                $fluentLicense = fluentCart('db')->table('fct_licenses')
                    ->where('license_key', $eddLicense->license_key)
                    ->first();

                if (!$fluentLicense) {
                    if ($eddLicense->status == 'expired') {
                        $msg = '404 Expired: ' . $eddLicense->license_key . ' => ' . $eddLicense->id;
                        if ($onIssue) {
                            $onIssue('expired', $msg);
                        }
                        continue;
                    }

                    $payment = fluentCart('db')->table('posts')
                        ->where('post_type', 'edd_payment')
                        ->where('ID', $eddLicense->payment_id)
                        ->first();

                    if ($payment && $payment->post_status != 'failed') {
                        $issue = [
                            'type'        => 'missing',
                            'license_key' => $eddLicense->license_key,
                            'edd_id'      => $eddLicense->id,
                            'payment_id'  => $payment->ID,
                            'message'     => 'License not found: ' . $eddLicense->license_key . ' => ' . $eddLicense->id . ' => ' . $payment->ID,
                        ];
                        $issues[] = $issue;
                        if ($onIssue) {
                            $onIssue('missing', $issue['message']);
                        }
                    }

                    continue;
                }

                if ($fluentLicense->status != $eddLicense->status) {
                    $issue = [
                        'type'        => 'status_mismatch',
                        'license_key' => $eddLicense->license_key,
                        'edd_status'  => $eddLicense->status,
                        'fct_status'  => $fluentLicense->status,
                        'message'     => 'License Status Mismatch: ' . $eddLicense->license_key . ' => EDD: ' . $eddLicense->status . ' => Fluent: ' . $fluentLicense->status,
                    ];
                    $issues[] = $issue;
                    if ($onIssue) {
                        $onIssue('status_mismatch', $issue['message']);
                    }
                }
            }

            $page++;
        }

        return [
            'success'       => true,
            'total_checked' => $totalChecked,
            'issues_count'  => count($issues),
            'issues'        => $issues,
        ];
    }

    public function getLogs()
    {
        $logs = get_option('_fluent_edd_failed_payment_logs', []);

        return [
            'logs'  => $logs ?: [],
            'count' => is_array($logs) ? count($logs) : 0,
        ];
    }

    public function buildAndSaveSummary()
    {
        $this->loadEddClasses();

        $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', []);
        if (!is_array($migrationSteps)) {
            $migrationSteps = [];
        }

        $failedLogs = get_option('_fluent_edd_failed_payment_logs', []);

        $summary = [
            'source'       => 'edd',
            'completed_at' => current_time('mysql'),
            'has_licenses' => false,
            'steps'        => [
                'products'  => ['done' => ($migrationSteps['products'] ?? '') === 'yes'],
                'tax_rates' => ['done' => ($migrationSteps['tax_rates'] ?? '') === 'yes'],
                'coupons'   => ['done' => ($migrationSteps['coupons'] ?? '') === 'yes'],
                'payments'  => [
                    'done'   => ($migrationSteps['payments'] ?? '') === 'yes',
                    'errors' => is_array($failedLogs) ? count($failedLogs) : 0,
                ],
                'recount'   => ['done' => ($migrationSteps['recount'] ?? '') === 'yes'],
            ],
            'stats'        => [],
        ];

        // Gather counts from the EDD source tables
        try {
            $stats = $this->getEddStats();
            $summary['stats'] = [
                'products'      => $stats['products_count'] ?? 0,
                'orders'        => $stats['orders_count'] ?? 0,
                'customers'     => $stats['customers_count'] ?? 0,
                'subscriptions' => $stats['subscriptions_count'] ?? 0,
                'licenses'      => $stats['licenses_count'] ?? 0,
                'coupons'       => $stats['coupons_count'] ?? 0,
            ];
            $summary['has_licenses'] = !empty($stats['has_licenses']) && ($stats['licenses_count'] ?? 0) > 0;
        } catch (\Exception $e) {
            // Stats may not be available if EDD tables were removed
        }

        update_option('__fluent_cart_migration_summary', $summary, false);

        return $summary;
    }

    public function getMigrationSummary()
    {
        return get_option('__fluent_cart_migration_summary', null);
    }

    public function wipeMigratedData()
    {
        global $wpdb;

        delete_option('__fluent_cart_edd3_migration_steps');
        delete_option('_fluent_edd_failed_payment_logs');
        delete_option('__fluent_cart_migration_summary');
        delete_option('fluent_cart_plugin_once_activated');

        $wpdb->query("SET SESSION FOREIGN_KEY_CHECKS=0;");
        try {
            DBMigrator::refresh();
        } catch (\Exception $e) {
            // Ignore
        }
        $wpdb->query("SET SESSION FOREIGN_KEY_CHECKS=1;");

        $wpdb->query("DELETE pm FROM {$wpdb->prefix}postmeta pm INNER JOIN {$wpdb->prefix}posts p ON pm.post_id = p.ID WHERE p.post_type = 'fluent-products'");
        $wpdb->query("DELETE FROM {$wpdb->prefix}posts WHERE post_type = 'fluent-products'");

        $postmetas = ['_edd_migrated_from', '_fcart_migrated_id', '__edd_migrated_variation_maps'];
        foreach ($postmetas as $postMeta) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}postmeta WHERE meta_key = %s", $postMeta));
        }

        return [
            'success' => true,
            'message' => 'All migrated data and migration state have been reset.',
        ];
    }

    public function resetMigration()
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

    private function maybeMigrateStoreSettings()
    {
        $eddSettings = get_option('edd_settings', []);
        if (empty($eddSettings)) {
            return;
        }

        $storeSettings = new StoreSettings();
        $existingSettings = get_option('fluent_cart_store_settings', []);

        $settingsMap = [
            'entity_name'          => 'store_name',
            'business_address'     => 'store_address1',
            'business_address_2'   => 'store_address2',
            'business_city'        => 'store_city',
            'base_state'           => 'store_state',
            'base_country'         => 'store_country',
            'business_postal_code' => 'store_postcode',
            'currency'             => 'currency',
        ];

        $toUpdate = [];

        foreach ($settingsMap as $eddKey => $fctKey) {
            $eddValue = $eddSettings[$eddKey] ?? '';
            $existingValue = $existingSettings[$fctKey] ?? '';

            if (!empty($eddValue) && empty($existingValue)) {
                $toUpdate[$fctKey] = $eddValue;
            }
        }

        $eddCurrencyPosition = $eddSettings['currency_position'] ?? '';
        $existingCurrencyPosition = $existingSettings['currency_position'] ?? '';
        if (!empty($eddCurrencyPosition) && empty($existingCurrencyPosition)) {
            $toUpdate['currency_position'] = $eddCurrencyPosition;
        }

        $eddDecimalSep = $eddSettings['decimal_separator'] ?? '';
        $existingDecimalSep = $existingSettings['decimal_separator'] ?? '';
        if (!empty($eddDecimalSep) && empty($existingDecimalSep)) {
            $toUpdate['decimal_separator'] = $eddDecimalSep === ',' ? 'comma' : 'dot';
        }

        if (!empty($toUpdate)) {
            $storeSettings->save($toUpdate);
        }
    }
}
