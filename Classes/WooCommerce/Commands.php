<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCartMigrator\Classes\SourceManager;

/**
 * WP-CLI for the WooCommerce source.
 *
 *   wp fluent_cart_migrator migrate_from_woo [--all] [--products] [--tax_rates]
 *       [--coupons] [--payments] [--missing-customers] [--recount] [--stats]
 *       [--log] [--reset]
 *
 * Separate from the production EDD command in Classes/Commands.php — registered
 * additively in the bootstrap. No EDD code is touched.
 *
 * For large stores run with a high memory limit, e.g.
 *   wp --exec='ini_set("memory_limit","1G");' fluent_cart_migrator migrate_from_woo --all
 */
class Commands
{
    /** @return \FluentCartMigrator\Classes\WooCommerce\WooSourceMigrator */
    private function source()
    {
        return (new SourceManager())->resolve('woocommerce');
    }

    public function run($args, $assoc_args = [])
    {
        if (!class_exists('WooCommerce')) {
            \WP_CLI::error('WooCommerce is not active.');
        }

        $src = $this->source();

        if (!empty($assoc_args['stats'])) {
            $this->printStats($src);
            return;
        }

        if (!empty($assoc_args['log'])) {
            $logs = $src->getLogs();
            \WP_CLI::line('Failed: ' . $logs['count']);
            foreach ($logs['logs'] as $id => $log) {
                \WP_CLI::line($id . ' => ' . ($log['message'] ?? ''));
            }
            return;
        }

        if (!empty($assoc_args['reset'])) {
            \WP_CLI::confirm('Reset ALL migrated FluentCart data?');
            $result = $src->reset();
            if (is_wp_error($result)) {
                \WP_CLI::error($result->get_error_message());
            }
            \WP_CLI::success($result['message'] ?? 'Reset complete.');
            return;
        }

        $all = !empty($assoc_args['all']);

        if ($all || !empty($assoc_args['products'])) {
            \WP_CLI::line('Migrating products + store settings...');
            $this->report($src->migrateProducts(), 'Products');
        }

        if ($all || !empty($assoc_args['tax_rates'])) {
            \WP_CLI::line('Migrating tax rates...');
            $r = $src->migrateTaxRates();
            if (is_wp_error($r)) {
                \WP_CLI::warning($r->get_error_message());
            } elseif (!empty($r['skipped'])) {
                \WP_CLI::line($r['message'] ?? 'Tax rates skipped.');
            } else {
                \WP_CLI::line('Mapped ' . ($r['mapped'] ?? 0) . ' tax rate(s) for: ' . implode(', ', $r['countries'] ?? []));
            }
        }

        if ($all || !empty($assoc_args['coupons'])) {
            \WP_CLI::line('Migrating coupons...');
            $this->report($src->migrateCoupons(), 'Coupons');
        }

        if ($all || !empty($assoc_args['payments'])) {
            \WP_CLI::line('Migrating orders...');
            $total = 0;
            do {
                $page   = $src->getPaymentResumePage();
                $result = $src->migratePayments($page, 1000, 0);
                $total += (int) ($result['processed'] ?? 0);
                \WP_CLI::line('  page ' . $page . ': processed ' . ($result['processed'] ?? 0) . (!empty($result['skipped']) ? ' [already done]' : ''));
            } while (!empty($result['has_more']));
            \WP_CLI::line('Orders processed: ' . $total . ' (errors logged: ' . ($result['errors_in_batch'] ?? 0) . ')');
        }

        if ($all || !empty($assoc_args['missing-customers'])) {
            \WP_CLI::line('Migrating customers without orders...');
            $r = $src->migrateMissingCustomers();
            \WP_CLI::line('Missing customers migrated: ' . (is_wp_error($r) ? $r->get_error_message() : ($r['migrated'] ?? 0)));
        }

        if ($all || !empty($assoc_args['recount'])) {
            \WP_CLI::line('Recounting aggregates...');
            foreach ($src->getRecountSubsteps() as $sub) {
                $r = $src->recountStats($sub);
                \WP_CLI::line('  ' . $sub . ': ' . (is_wp_error($r) ? $r->get_error_message() : ('recounted ' . ($r['recounted'] ?? 0))));
            }
        }

        \WP_CLI::success('WooCommerce migration step(s) complete.');
    }

    private function printStats($src)
    {
        $stats = $src->getStats();
        if (is_wp_error($stats)) {
            \WP_CLI::error($stats->get_error_message());
        }

        \WP_CLI::line('Products: ' . $stats['products_count']);
        \WP_CLI::line('Orders: ' . $stats['orders_count']);
        \WP_CLI::line('Transactions (paid): ' . $stats['transactions_count']);
        \WP_CLI::line('Customers: ' . $stats['customers_count']);
        \WP_CLI::line('Subscriptions: ' . $stats['subscriptions_count']);
        \WP_CLI::line('Coupons: ' . $stats['coupons_count']);
        \WP_CLI::line('Gateways: ' . implode(', ', $stats['gateways']));
        \WP_CLI::line('Order Statuses: ' . implode(', ', $stats['statuses']));
    }

    private function report($result, $label)
    {
        if (is_wp_error($result)) {
            \WP_CLI::warning($label . ': ' . $result->get_error_message());
            return;
        }
        if (!empty($result['skipped'])) {
            \WP_CLI::line($label . ' already done. Skipping.');
            return;
        }
        $line = $label . ': migrated ' . ($result['migrated'] ?? 0) . ' of ' . ($result['total'] ?? 0);
        if (!empty($result['failed'])) {
            $line .= ' (' . $result['failed'] . ' failed)';
        }
        \WP_CLI::line($line);
    }
}
