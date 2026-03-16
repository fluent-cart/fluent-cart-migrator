<?php

namespace FluentCartMigrator\Classes\Admin;

use FluentCart\App\App;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Subscription;
use FluentCartMigrator\Classes\Edd3\MigratorCli;

class MigratorService
{
    private function loadEddClasses()
    {
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/EDD3/MigratorCli.php';
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/EDD3/MigratorHelper.php';
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/EDD3/PaymentMigrate.php';
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
        ];

        return ['sources' => $sources];
    }

    public function getEddStats()
    {
        global $wpdb;

        $this->loadEddClasses();

        $eddCli = new MigratorCli();
        $stats  = $eddCli->stats();

        // Additional counts
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

        return [
            'products_count'      => $productsCount,
            'orders_count'        => $stats['order_count'],
            'transactions_count'  => $stats['transaction_count'],
            'customers_count'     => $customersCount,
            'subscriptions_count' => $subscriptionsCount,
            'licenses_count'      => $licensesCount,
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

    public function migrateCoupons()
    {
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

    public function migratePayments($page = 1, $perPage = 100)
    {
        $this->loadEddClasses();

        $eddCli  = new MigratorCli();
        $results = $eddCli->migratePayments($page, $perPage);

        $hasMore   = $results !== null && !$results->isEmpty();
        $processed = $results ? $results->count() : 0;

        $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', []);
        if (!is_array($migrationSteps)) {
            $migrationSteps = [];
        }
        $migrationSteps['last_order_page'] = $page;

        if (!$hasMore) {
            $migrationSteps['payments'] = 'yes';
            // Replace vendor IP addresses after all payments are done
            $eddCli->replaceVendorIpAddresses();
        }

        update_option('__fluent_cart_edd3_migration_steps', $migrationSteps);

        $failedLogs    = get_option('_fluent_edd_failed_payment_logs', []);
        $errorsInBatch = is_array($failedLogs) ? count($failedLogs) : 0;

        return [
            'success'          => true,
            'step'             => 'payments',
            'page'             => $page,
            'processed'        => $processed,
            'has_more'         => $hasMore,
            'errors_in_batch'  => $errorsInBatch,
            'migration_state'  => $migrationSteps,
        ];
    }

    public function recountStats($substep)
    {
        switch ($substep) {
            case 'coupons':
                return $this->recountCoupons();
            case 'customers':
                return $this->recountCustomers();
            case 'subscriptions':
                return $this->recountSubscriptions();
            default:
                return ['success' => false, 'message' => 'Unknown substep'];
        }
    }

    private function recountCoupons()
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

    private function recountCustomers()
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
                        if ($transaction->status == 'paid') {
                            if (empty($totalPayments[$order['currency']])) {
                                $totalPayments[$order['currency']] = $transaction['total'];
                            } else {
                                $totalPayments[$order['currency']] += $transaction['total'];
                            }
                        }
                    }

                    $totalPayments = array_map('intval', $totalPayments);
                }

                App::db()->table('fct_customers')->where('id', $customer->id)->update([
                    'user_id'             => $customer->getWpUserId(true),
                    'purchase_value'      => $totalPayments,
                    'ltv'                 => $ltv,
                    'purchase_count'      => $orders->count(),
                    'first_purchase_date' => $orders->min('created_at') . '',
                    'last_purchase_date'  => $orders->max('created_at') . '',
                ]);

                $totalCount++;
            }

            $page++;
        }

        return [
            'success'   => true,
            'substep'   => 'customers',
            'recounted' => $totalCount,
        ];
    }

    private function recountSubscriptions()
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
                $subscription = $keyedSubscriptions[$orderId];
                if ($subscription->bill_count != $count) {
                    unset($subscription->preventsLazyLoading);
                    $subscription->bill_count = $count;
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

    public function resetMigration()
    {
        if (!defined('FLUENT_CART_DEV_MODE') || !FLUENT_CART_DEV_MODE) {
            return new \WP_Error(
                'dev_mode_required',
                'Reset is only available in dev mode. Define FLUENT_CART_DEV_MODE in wp-config.php.',
                ['status' => 403]
            );
        }

        global $wpdb;

        delete_option('__fluent_cart_edd3_migration_steps');
        delete_option('_fluent_edd_failed_payment_logs');
        delete_option('fluent_cart_plugin_once_activated');

        $wpdb->query("SET GLOBAL FOREIGN_KEY_CHECKS=0;");
        try {
            \FluentCart\Database\DBMigrator::refresh();
        } catch (\Exception $e) {
            // Ignore
        }
        $wpdb->query("SET GLOBAL FOREIGN_KEY_CHECKS=1;");

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
}
