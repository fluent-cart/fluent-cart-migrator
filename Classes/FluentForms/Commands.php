<?php

namespace FluentCartMigrator\Classes\FluentForms;

/**
 * WP-CLI for Fluent Forms Stripe subscriptions.
 *
 *   wp fluent_cart_migrator migrate_from_fluentforms [--submission=<entry id>] [--dry-run]
 *
 * Safe to rerun: a subscription whose Stripe id is already in FluentCart is skipped.
 * --dry-run writes nothing and prints what each subscription would become.
 */
class Commands
{
    public function run($args, $assoc_args = [])
    {
        if (!defined('FLUENTFORM')) {
            \WP_CLI::error('Fluent Forms is not active.');
        }

        $query = fluentCart('db')->table('fluentform_subscriptions')->orderBy('id', 'ASC');
        if (!empty($assoc_args['submission'])) {
            $query->where('submission_id', (int) $assoc_args['submission']);
        }

        $subscriptions = $query->get();
        if (!$subscriptions->count()) {
            \WP_CLI::warning('No Fluent Forms subscriptions found.');
            return;
        }

        $dryRun = !empty($assoc_args['dry-run']);
        if (!$dryRun && !FluentFormsSourceMigrator::acquireLock(0)) {
            \WP_CLI::error('Another Fluent Forms migration is running.');
        }

        try {
            $this->migrate($subscriptions, $dryRun);
        } finally {
            if (!$dryRun) {
                FluentFormsSourceMigrator::releaseLock();
            }
        }
    }

    private function migrate($subscriptions, $dryRun)
    {
        $migrator = new SubscriptionMigrator($dryRun);
        $counts   = ['migrated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($subscriptions as $ffSub) {
            $result = $migrator->migrate($ffSub);
            $counts[$result['status']]++;

            \WP_CLI::log(sprintf(
                '[%s] FF subscription #%d (entry #%d, %s): %s',
                $dryRun && $result['status'] === 'migrated' ? 'would migrate' : $result['status'],
                $ffSub->id,
                $ffSub->submission_id,
                $ffSub->vendor_subscription_id,
                $result['message']
            ));
            foreach ($result['details'] as $line) {
                \WP_CLI::log('    ' . $line);
            }
        }

        $summary = sprintf(
            '%d %s, %d skipped, %d failed.',
            $counts['migrated'],
            $dryRun ? 'would migrate' : 'migrated',
            $counts['skipped'],
            $counts['failed']
        );
        if ($dryRun) {
            $summary = 'Dry run — nothing written. ' . $summary;
        }
        if ($counts['failed']) {
            \WP_CLI::error($summary);
        }

        \WP_CLI::success($summary);
    }
}
