<?php

namespace FluentCartMigrator\Classes;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use FluentCartMigrator\Classes\MigratorService;


class Commands
{

    private function loadEddClasses()
    {
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/EDD3/MigratorCli.php';
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/EDD3/MigratorHelper.php';
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/EDD3/PaymentMigrate.php';
    }

    private function getMigratorService()
    {
        return new MigratorService();
    }

    public function migrate_from_edd($args, $assoc_args = [])
    {
        $this->loadEddClasses();

        $canMigrate = $this->getMigratorService()->canMigrate();

        if (is_wp_error($canMigrate)) {
            \WP_CLI::error($canMigrate->get_error_message());
            return;
        }

        // CLI-specific diagnostic flags — these stay in Commands
        if (Arr::get($assoc_args, 'stats')) {
            $stats = $this->getMigratorService()->getEddStats();
            \WP_CLI::line('Products: ' . $stats['products_count']);
            \WP_CLI::line('Total Orders: ' . $stats['orders_count']);
            \WP_CLI::line('Total Transactions: ' . $stats['transactions_count']);
            \WP_CLI::line('Customers: ' . $stats['customers_count']);
            \WP_CLI::line('Subscriptions: ' . $stats['subscriptions_count']);
            \WP_CLI::line('Licenses: ' . $stats['licenses_count']);
            \WP_CLI::line('Gateways: ' . implode(', ', $stats['gateways']));
            \WP_CLI::line('Order Statuses: ' . implode(', ', $stats['statuses']));
            return;
        }

        if (Arr::get($assoc_args, 'verify_license')) {
            $result = $this->getMigratorService()->verifyLicenses(function ($type, $message) {
                \WP_CLI::line($message);
            });
            \WP_CLI::line('Verified ' . $result['total_checked'] . ' licenses, ' . $result['issues_count'] . ' issues found.');
            return;
        }

        if (Arr::get($assoc_args, 'log')) {
            $result = $this->getMigratorService()->getLogs();
            print_r($result['logs']);
            return;
        }

        if (Arr::get($assoc_args, 'reset')) {
            \WP_CLI::confirm('Are you sure you want to reset the migration?');

            $this->getMigratorService()->wipeMigratedData();
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
        $service = $this->getMigratorService();

        \WP_CLI::line('Starting EDD3 Migration at: ' . date('Y-m-d H:i:s'));

        if (Arr::get($assoc_args, 'products')) {
            if ($migrationSteps['products'] !== 'yes') {
                \WP_CLI::line('Starting Migrating products');
                $result = $service->migrateProducts();
                $migrationSteps = $result['migration_state'];
                \WP_CLI::line('Migrated ' . $result['migrated'] . ' Products of ' . $result['total']);
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
            $result = $service->migrateCoupons();
            $migrationSteps = $result['migration_state'];
            \WP_CLI::line('Migrated ' . $result['migrated'] . ' Coupon Codes');
            \WP_CLI::line('---------------------------------------');
        }

        if (Arr::get($assoc_args, 'payments')) {
            if (Arr::get($migrationSteps, 'payments') === 'yes') {
                \WP_CLI::line('Orders Migration already done. Skipping...');
            } else {
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

                while (true) {
                    if ($page % 10 == 0) {
                        \WP_CLI::line('Migrating Page: ' . $page);
                    }

                    $result = $service->migratePayments($page, $perPage);

                    for ($i = 0; $i < $result['processed']; $i++) {
                        $progress->tick();
                    }

                    if (!$result['has_more']) {
                        break;
                    }

                    MigratorHelper::resetCaches();
                    $page++;
                }

                $progress->finish();
                \WP_CLI::line('All Payments Migration has been completed');
            }
        }

        if (Arr::get($assoc_args, 'recount')) {
            $this->runRecount($service);
        }

        \WP_CLI::line('Completed Migration at: ' . date('Y-m-d H:i:s'));

        $durationFormatted = gmdate("H:i:s", time() - $startingAt);
        \WP_CLI::line('Total Duration: ' . $durationFormatted);
    }

    private function runRecount(MigratorService $service)
    {
        // Fix reactivations
        $result = $service->fixReactivations(function ($type, $message) {
            \WP_CLI::line($message);
        });
        \WP_CLI::line('Fixed ' . $result['fixed'] . ' reactivations of ' . $result['total'] . ' orphans');

        // Fix subscription UUIDs
        $result = $service->fixSubsUuid();
        if ($result['fixed'] > 0) {
            \WP_CLI::line('Fixed UUID for ' . $result['fixed'] . ' subscriptions');
        } else {
            \WP_CLI::line('No subscriptions found to fix UUID');
        }

        // Recount coupons
        $result = $service->recountCoupons();
        \WP_CLI::line('Recounted ' . $result['recounted'] . ' Coupons');

        // Recount customers with progress bar
        $totalCustomers = Customer::count();
        $progress = \WP_CLI\Utils\make_progress_bar('Recounting Customer stats: (' . number_format($totalCustomers) . ')', $totalCustomers);
        $service->recountCustomers(function () use ($progress) {
            $progress->tick();
        });
        $progress->finish();

        // Recount subscriptions with progress bar
        $totalSubs = Subscription::count();
        $progress = \WP_CLI\Utils\make_progress_bar('Recounting Subscriptions Bills count: (' . number_format($totalSubs) . ')', $totalSubs);
        $service->recountSubscriptions(function () use ($progress) {
            $progress->tick();
        });
        $progress->finish();
    }

    public function reset($args, $assoc_args)
    {
        if (!defined('FLUENT_CART_DEV_MODE') || !FLUENT_CART_DEV_MODE) {
            \WP_CLI::error('You can only reset the migration in dev mode. Please define FLUENT_CART_DEV_MODE in your wp-config.php file.');
            return;
        }

        \WP_CLI::confirm('Are you sure you want to reset the migration?');

        $this->getMigratorService()->wipeMigratedData();
        \WP_CLI::line('All data has been reset.');
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

    protected function get_user_input($question)
    {
        \WP_CLI::log($question);
        $handle = fopen('php://stdin', 'r');
        $input = trim(fgets($handle));
        fclose($handle);
        return $input;
    }
}
