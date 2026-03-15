<?php

namespace FluentCartMigrator\Classes;

use FluentCart\App\App;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Database\DBMigrator;
use FluentCart\Framework\Support\Arr;
use FluentCartMigrator\Classes\Edd3\MigratorCli;
use FluentCartMigrator\Classes\Edd3\MigratorHelper;

class Commands
{

    public function migrate_from_edd($args, $assoc_args = [])
    {
        $taxSettings = get_option('edd_settings', []);

        // load edd files
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/Edd3/MigratorCli.php';
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/Edd3/MigratorHelper.php';
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/Edd3/PaymentMigrate.php';

        $canMigrate = MigratorHelper::canMigrate();

        if (is_wp_error($canMigrate)) {
            \WP_CLI::error($canMigrate->get_error_message());
            return;
        }

        $eddCli = new \FluentCartMigrator\Classes\Edd3\MigratorCli();

        // $eddCli->migratePayments(); die();

        if (Arr::get($assoc_args, 'stats')) {
            $eddCli->stats($assoc_args);
            return;
        }

        if (Arr::get($assoc_args, 'verify_license')) {
            $eddCli->verifyLicenses();
            return;
        }

        if (Arr::get($assoc_args, 'log')) {
            $log = get_option('_fluent_edd_failed_payment_logs', []);
            print_r($log);
            return;
        }

        if (Arr::get($assoc_args, 'reset')) {
            \WP_CLI::confirm('Are you sure you want to reset the migration?');

            $this->migrate_fresh($args, $assoc_args, false);
            delete_option('__fluent_cart_edd3_migration_steps');
            \WP_CLI::line('All data has been reset.');
            return;
        }

        $migrationSteps = get_option('__fluent_cart_edd3_migration_steps', false);

        if (!is_array($migrationSteps) || !$migrationSteps) {
            $migrationSteps = [
                'products'        => 'no',
                'coupons'         => 'no',
                'payments'        => 'no',
                'last_order_page' => 1,
            ];
            update_option('__fluent_cart_edd3_migration_steps', $migrationSteps);
        }

        if (Arr::get($assoc_args, 'all')) {
            $assoc_args = [
                'products'  => true,
                'tax_rates' => true,
                'coupons'   => true,
                'payments'  => true,
                'recount'   => true
            ];
        }

        $startingAt = time();

        \WP_CLI::line('Starting EDD3 Migration at: ' . date('Y-m-d H:i:s'));

        if (Arr::get($assoc_args, 'products')) {
            if ($migrationSteps['products'] !== 'yes') {
                \WP_CLI::line('Starting Migrating products');
                $migratedProducts = $eddCli->migrate_products(true);
                if ($migratedProducts) {
                    $migrationSteps['products'] = 'yes';
                    update_option('__fluent_cart_edd3_migration_steps', $migrationSteps);
                }

                $validMigrations = array_filter($migratedProducts, function ($value) {
                    return !is_wp_error($value);
                });

                \WP_CLI::line('Migrated ' . count($validMigrations) . ' Products of ' . count($migratedProducts));
                \WP_CLI::line('---------------------------------------');
            } else {
                \WP_CLI::line('Products Migration already done. Skipping...');
            }
        }

        if (Arr::get($assoc_args, 'tax_rates')) {
            \WP_CLI::line('Starting Tax Rates Migration');
            $taxRates = $eddCli->migrateTaxRates();
            if ($taxRates) {
                \WP_CLI::line('Migrated ' . count($taxRates) . ' Tax Rate mappings');
            }
            \WP_CLI::line('---------------------------------------');
        }

        if (Arr::get($assoc_args, 'coupons')) {
            \WP_CLI::line('Starting Coupon Codes');
            $coupons = $eddCli->migrateCouponCodes();
            if ($coupons) {
                $migrationSteps['coupons'] = 'yes';
                update_option('__fluent_cart_edd3_migration_steps', $migrationSteps);
            }
            \WP_CLI::line('Migrated ' . count($coupons) . ' Coupon Codes ');
            \WP_CLI::line('---------------------------------------');
        }

        if (Arr::get($assoc_args, 'payments')) {
            if (Arr::get($migrationSteps, 'payments') === 'yes') {
                \WP_CLI::line('Orders Migration already done. Skipping...');
            } else {
                $status = true;
                $page = Arr::get($migrationSteps, 'last_order_page', 1);
                if (!$page || $page < 1) {
                    $page = 1;
                }

                \WP_CLI::line('Starting orders Migration. Page: ' . $page);

                $totalOrdersCount = fluentCart('db')->table('edd_orders')
                    ->whereIn('status', ['complete', 'pending', 'edd_subscription', 'processing', 'revoked', 'partially_refunded', 'refunded', 'publish'])
                    ->orderBy('ID', 'ASC')
                    ->count();

                $perPage = 1000;

                $totalOrdersCount = $totalOrdersCount - ($perPage * ($page - 1));

                $progress = \WP_CLI\Utils\make_progress_bar('Migrating Payments: (' . number_format($totalOrdersCount) . ')', $totalOrdersCount);

                while ($status) {
                    if ($page % 10 == 0) {
                        \WP_CLI::line('Migrating Page: ' . $page);
                    }

                    $results = (new MigratorCli())->migratePayments($page, $perPage);

                    $migrationSteps['last_order_page'] = $page;
                    update_option('__fluent_cart_edd3_migration_steps', $migrationSteps);

                    if (!$results) {
                        $status = false;
                        break;
                    }

                    foreach ($results as $result) {
                        $progress->tick();
                    }

                    MigratorHelper::resetCaches();
                    $page++;
                }

                $progress->finish();
                $migrationSteps['payments'] = 'yes';
                update_option('__fluent_cart_edd3_migration_steps', $migrationSteps);
                $eddCli->replaceVendorIpAddresses();
                \WP_CLI::line('All Payments Migration has been completed');
            }
        }

        if (Arr::get($assoc_args, 'recount')) {
            // Recount stats now
            $this->fix_reactivations();
            $this->fix_subs_uuid();
            $this->recountCoupons();
            $this->recountCustomersStat();
            $this->recountSubscriptions();
        }

        \WP_CLI::line('Completed Migration at: ' . date('Y-m-d H:i:s'));

        $endTime = time();
        $duration = $endTime - $startingAt;
        $durationFormatted = gmdate("H:i:s", $duration);
        \WP_CLI::line('Total Duration: ' . $durationFormatted);
    }

    public function fix_reactivations()
    {
        $orphans = OrderTransaction::where('order_type', 'renewal')
            ->whereDoesntHave('subscription')
            ->get();

        foreach ($orphans as $orphan) {
            $order = $orphan->order;
            $parentOrderId = $order->parent_id;
            if (!$parentOrderId) {
                \WP_CLI::line('No Parent Order for Order ID: ' . $order->id);
                continue;
            }

            $rightSubscription = Subscription::where('parent_order_id', $parentOrderId)
                ->orderBy('id', 'DESC')
                ->first();

            if (!$rightSubscription) {
                \WP_CLI::line('No Subscription Found for Parent Order ID: ' . $parentOrderId . ' - Order ID: ' . $order->id);
                continue;
            }

            $orphan->subscription_id = $rightSubscription->id;
            $orphan->save();

            $rightSubscription->reSyncFromRemote();
        }

        \WP_CLI::line('Done');
    }

    public function reset($args, $assoc_args)
    {
        if (!defined('FLUENT_CART_DEV_MODE') || !FLUENT_CART_DEV_MODE) {
            \WP_CLI::error('You can only reset the migration in dev mode. Please define FLUENT_CART_DEV_MODE in your wp-config.php file.');
            return;
        }

        \WP_CLI::confirm('Are you sure you want to reset the migration?');

        $this->migrate_fresh($args, $assoc_args, false);
        delete_option('__fluent_cart_edd3_migration_steps');
    }

    public function edd_cleanup($args, $assoc_args = [])
    {
        global $wpdb;
        $eddTypes = [
            'edd_advanced_report',
            'edd_discount',
            'edd_license_log',
            'edd_log',
            'edd_payment'
        ];

        foreach ($eddTypes as $type) {
            $this->deletePostType($type, $assoc_args);
        }

        // delete the orphanded commentmeta
        $this->deleteOrphandedCommentMeta();
        $this->deleteOrphancedPostMeta();

        $this->deleteTermTaxonomies([
            'download_category',
            'edd_log_type'
        ]);
    }

    private function deletePostType($postType, $assoc_args = [])
    {

        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type = %s", $postType));

        if (!$count) {
            \WP_CLI::line('No posts found for post type: ' . $postType);
            return;
        }

        \WP_CLI::line('Found ' . $count . ' posts of type: ' . $postType);
        // ask for confirmation
        \WP_CLI::confirm("Are you sure you want to delete this post type and it's associate data?" . $postType, $assoc_args);


        // let's create a progress bar
        $progress = \WP_CLI\Utils\make_progress_bar('Deleting ' . $postType . ' posts', $count / 100);
        $offset = 0;
        $perPage = 100;
        while ($count > 0) {
            $posts = $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = %s LIMIT 100", $postType));

            if (empty($posts)) {
                break;
            }

            $postIds = wp_list_pluck($posts, 'ID');

            if (!$postIds) {
                break;
            }

            // delete the posts
            $wpdb->query("DELETE FROM {$wpdb->prefix}posts WHERE ID IN (" . implode(',', $postIds) . ")");

            // delete the post meta
            $wpdb->query("DELETE FROM {$wpdb->prefix}postmeta WHERE post_id IN (" . implode(',', $postIds) . ")");

            // let's delete the comments
            $wpdb->query("DELETE FROM {$wpdb->prefix}comments WHERE comment_post_ID IN (" . implode(',', $postIds) . ")");

            $progress->tick();
            $count -= count($postIds);
        }

        $progress->finish();
        \WP_CLI::line('Deleted ' . $postType . ' posts and their associated data.');

    }

    private function deleteOrphandedCommentMeta()
    {
        global $wpdb;

        // select 100 commentmeta meta_id where comment_id not exists in comments table
        $orphandedCommentMeta = $wpdb->get_results("SELECT meta_id FROM {$wpdb->prefix}commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->prefix}comments)");

        if (empty($orphandedCommentMeta)) {
            \WP_CLI::line('No orphanded comment meta found or all done');
            return;
        }

        $metaIds = wp_list_pluck($orphandedCommentMeta, 'meta_id');

        // delete the comment meta
        $wpdb->query("DELETE FROM {$wpdb->prefix}commentmeta WHERE meta_id IN (" . implode(',', $metaIds) . ")");
        \WP_CLI::line('Deleted ' . count($metaIds) . ' orphanded comment meta');


        $this->deleteOrphandedCommentMeta();

    }

    private function deleteOrphancedPostMeta()
    {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE post_id NOT IN (SELECT ID FROM {$wpdb->prefix}posts)");

        if (!$count) {
            \WP_CLI::line('No orphanded post meta found or all done');
            return;
        }

        $totalCount = $count;
        \WP_CLI::line('Found ' . $totalCount . ' orphanded post meta');

        // confirm
        \WP_CLI::confirm("Are you sure you want to delete all orphanded post meta?", []);

        // let's create a progress bar
        $progress = \WP_CLI\Utils\make_progress_bar('Deleting Orphanded Post Meta', $count / 100);

        while ($count > 0) {
            $orphandedPostMeta = $wpdb->get_results("SELECT meta_id FROM {$wpdb->prefix}postmeta WHERE post_id NOT IN (SELECT ID FROM {$wpdb->prefix}posts) LIMIT 100");

            if (empty($orphandedPostMeta)) {
                break;
            }

            $metaIds = wp_list_pluck($orphandedPostMeta, 'meta_id');

            // delete the post meta
            $wpdb->query("DELETE FROM {$wpdb->prefix}postmeta WHERE meta_id IN (" . implode(',', $metaIds) . ")");

            $progress->tick();
            $count -= count($metaIds);
        }

        $progress->finish();
        \WP_CLI::line('Deleted ' . $totalCount . ' orphanded post meta');

    }

    private function deleteTermTaxonomies($taxonomies)
    {
        global $wpdb;

        if (empty($taxonomies)) {
            \WP_CLI::line('No taxonomies provided to delete');
            return;
        }

        $taxonomiesImplode = "'" . implode("', '", $taxonomies) . "'";

        $termTaxonomies = $wpdb->get_results("SELECT term_taxonomy_id FROM {$wpdb->prefix}term_taxonomy WHERE taxonomy IN (" . $taxonomiesImplode . ")");


        $termTaxonomyIds = wp_list_pluck($termTaxonomies, 'term_taxonomy_id');

        if (empty($termTaxonomyIds)) {
            \WP_CLI::line('No term taxonomies found for the provided taxonomies');
            return;
        }

        $termTaxonomyIds = array_values(array_unique($termTaxonomyIds));


        // confirm
        \WP_CLI::confirm("Are you sure you want to delete the term taxonomies: " . implode(', ', $termTaxonomyIds), []);

        while (true) {
            $termRelations = $wpdb->get_results("SELECT term_taxonomy_id, object_id FROM {$wpdb->prefix}term_relationships WHERE term_taxonomy_id IN (" . implode(',', $termTaxonomyIds) . ") LIMIT 100");
            if (empty($termRelations)) {
                break;
            }

            $wpdb->query("DELETE FROM {$wpdb->prefix}term_relationships WHERE term_taxonomy_id IN (" . implode(',', $termTaxonomyIds) . ") LIMIT 100");

            \WP_CLI::line('Deleted ' . count($termRelations) . ' term relationships');

        }

        // delete the term taxonomies
        $wpdb->query("DELETE FROM {$wpdb->prefix}term_taxonomy WHERE term_taxonomy_id IN (" . implode(',', $termTaxonomyIds) . ")");

        \WP_CLI::line('Deleted ' . count($termTaxonomyIds));


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
            fluentCart('db')->table('fct_coupons')->where('id', $appliedCoupon->coupon_id)
                ->update(['use_count' => $appliedCoupon->count]);
        }

        \WP_CLI::line('Recounted ' . $appliedCoupons->count() . ' Coupons');
    }

    private function recountCustomersStat()
    {
        $completed = false;
        $page = 1;
        $perPage = 100;
        $totalCustomers = Customer::count();

        $progress = \WP_CLI\Utils\make_progress_bar('Recounting Customer stats: (' . number_format($totalCustomers) . ')', $totalCustomers);
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
                $orders = \FluentCart\App\Models\Order::query()->where('customer_id', $customer->id)
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

                    $totalPayments = array_map(function ($value) {
                        return (int)$value;
                    }, $totalPayments);
                }

                $purchaseCount = $orders->count();
                $updateData = [
                    'user_id'             => $customer->getWpUserId(true),
                    'purchase_value'      => $totalPayments,
                    'ltv'                 => $ltv,
                    'aov'                 => $purchaseCount > 0 ? (int)($ltv / $purchaseCount) : 0,
                    'purchase_count'      => $purchaseCount,
                    'first_purchase_date' => $orders->min('created_at') . '',
                    'last_purchase_date'  => $orders->max('created_at') . '',
                ];

                App::db()->table('fct_customers')->where('id', $customer->id)->update($updateData);
                $progress->tick();
            }

            $page++;
        }

        $progress->finish();
    }

    private function recountSubscriptions()
    {
        $completed = false;
        $page = 1;
        $perPage = 100;
        $total = Subscription::count();

        $progress = \WP_CLI\Utils\make_progress_bar('Recounting Subscriptions Bills count: (' . number_format($total) . ')', $total);
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
            $parentOrderIds = [];

            foreach ($subscriptions as $subscription) {
                $progress->tick();
                if (isset($keyedSubscriptions[$subscription->parent_order_id])) {
                    // dd('Invalid Subscription Parent ID: '. $subscription->parent_order_id);
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
                if ($renewal->parent_id) {
                    if (!isset($counts[$renewal->parent_id])) {
                        $counts[$renewal->parent_id] = 0;
                    }
                    $counts[$renewal->parent_id]++;
                } else {
                    if (!isset($counts[$renewal->id])) {
                        $counts[$renewal->id] = 0;
                    }
                    $counts[$renewal->id]++;
                }
            }

            foreach ($counts as $orderId => $count) {
                if (!isset($keyedSubscriptions[$orderId])) {
                    \WP_CLI::line('Invalid Subscription. orderID: ' . $orderId);
                    continue;
                }

                $hasChanges = false;

                $subscription = $keyedSubscriptions[$orderId];
                if ($subscription->bill_count != $count) {
                    $hasChanges = true;
                    $subscription->bill_count = $count;
                }

                if ($subscription->bill_times > 0 && $subscription->bill_count >= $subscription->bill_times) {
                    $subscription->status = 'completed';
                    $hasChanges = true;
                }

                if ($hasChanges) {
                    unset($subscription->preventsLazyLoading);
                    $subscription->save();
                }
            }

            $page++;
        }

        $progress->finish();
    }

    public function migrate_fresh($args, $assoc_args, $checkDev = true)
    {
        delete_option('fluent_cart_plugin_once_activated');
        if ($checkDev && App::config()->get('using_faker') === false) {
            if (class_exists('WP_CLI')) {
                echo \WP_CLI::colorize('%yYou Are Not In Dev Mode');
            } else {
                echo "You Are Not In Dev Mode";
            }
            return;
        }

        delete_option('__fluent_cart_edd3_migration_steps');
        delete_option('_fluent_edd_failed_payment_logs');

        global $wpdb;
        $wpdb->query("SET SESSION FOREIGN_KEY_CHECKS=0;");

        try {
            DBMigrator::refresh();
        } catch (\Exception $e) {

        }

        $wpdb->query("SET SESSION FOREIGN_KEY_CHECKS=1;");

        // Delete the post metas
        $wpdb->query("DELETE pm FROM {$wpdb->prefix}postmeta pm INNER JOIN {$wpdb->prefix}posts p ON pm.post_id = p.ID WHERE p.post_type = 'fluent-products'");
        // Delete the posts
        $wpdb->query("DELETE FROM {$wpdb->prefix}posts WHERE post_type = 'fluent-products'");

        // Delete the post metas
        $postmetas = ['_edd_migrated_from', '_fcart_migrated_id', '__edd_migrated_variation_maps'];
        foreach ($postmetas as $postMeta) {
            // delete from wp_postmeta table where meta_key = $postMeta
            $wpdb->query("DELETE FROM {$wpdb->prefix}postmeta WHERE meta_key = '$postMeta'");
        }

        if (class_exists('WP_CLI')) {
            \WP_CLI::line('All Data has been resetted');
        } else {
            echo "All Done!";
        }

    }

    public function fix_subs_uuid()
    {
        $subscriptions = \FluentCart\App\Models\Subscription::query()
            ->whereNull('uuid')
            ->orWhere('uuid', '')
            ->get();

        if ($subscriptions->isEmpty()) {
            \WP_CLI::line('No subscriptions found to fix UUID');
            return;
        }

        foreach ($subscriptions as $subscription) {
            $subscription->uuid = md5($subscription->id . wp_generate_uuid4() . microtime(true));
            $subscription->save();
        }

        \WP_CLI::line('Fixed UUID for ' . $subscriptions->count() . ' subscriptions');
    }

    protected function get_user_input($question)
    {
        \WP_CLI::log($question);
        $handle = fopen('php://stdin', 'r');
        $input = trim(fgets($handle));
        fclose($handle);
        return $input;
    }
}
