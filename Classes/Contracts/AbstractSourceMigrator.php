<?php

namespace FluentCartMigrator\Classes\Contracts;

use FluentCartMigrator\Classes\Load\CustomerWriter;
use FluentCartMigrator\Classes\Support\BatchRuntime;
use FluentCartMigrator\Classes\Support\MigrationLog;

/**
 * Base class for new migration sources (WooCommerce, SureCart, ...).
 *
 * Provides per-source progress state (namespaced option keys), failed-log
 * helpers, the shared resumable/time-boxed/memory-boxed payment loop, and safe
 * "not implemented yet" defaults for every migration step so a concrete source
 * can be built up incrementally — overriding only the steps it currently
 * supports while still satisfying SourceMigratorInterface.
 *
 * Note: the EDD source (MigratorService) does NOT extend this; it predates the
 * contract and keeps its own option keys and loop.
 */
abstract class AbstractSourceMigrator implements SourceMigratorInterface
{
    abstract public function key();

    /* -----------------------------------------------------------------
     | Per-source state
     * ----------------------------------------------------------------- */

    protected function stateOptionKey()
    {
        return '__fluent_cart_' . $this->key() . '_migration_steps';
    }

    protected function failedLogOptionKey()
    {
        return '_fluent_cart_' . $this->key() . '_failed_logs';
    }

    protected function summaryOptionKey()
    {
        return '__fluent_cart_' . $this->key() . '_migration_summary';
    }

    protected function getState()
    {
        $state = get_option($this->stateOptionKey(), []);
        return is_array($state) ? $state : [];
    }

    protected function saveState(array $state)
    {
        update_option($this->stateOptionKey(), $state, false);
    }

    protected function markStep($step, $value = 'yes')
    {
        $state        = $this->getState();
        $state[$step] = $value;
        $this->saveState($state);

        return $state;
    }

    protected function isStepDone($step)
    {
        $state = $this->getState();
        return ($state[$step] ?? '') === 'yes';
    }

    protected function getFailedLogs()
    {
        $logs = get_option($this->failedLogOptionKey(), []);
        return is_array($logs) ? $logs : [];
    }

    /**
     * Record a skipped/failed record. $args may carry the structured fields
     * MigrationLog understands (type, code, severity, stage, context); anything
     * else is stored as-is.
     */
    protected function logFailed($id, $message = 'unknown reason', $args = [])
    {
        $args['message'] = $message;
        MigrationLog::record($this->failedLogOptionKey(), (string) $id, $args);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line($id . ' => ' . $message);
        }

        return $this->getFailedLogs();
    }

    /**
     * Normalized log entries (see MigrationLog::normalize()).
     *
     * @return array<int,array>
     */
    protected function getLogEntries()
    {
        return MigrationLog::entries($this->getFailedLogs());
    }

    /**
     * Source-level totals shown above the skip report (e.g. how many orders the
     * source has vs how many were migrated). Sources override; keys are free-form
     * but the UI knows `source_orders` and `migrated_orders`.
     *
     * @return array<string,int>
     */
    protected function logTotals()
    {
        return [];
    }

    /* -----------------------------------------------------------------
     | Default interface implementations
     * ----------------------------------------------------------------- */

    public function detect()
    {
        return [
            'key'      => $this->key(),
            'name'     => ucfirst($this->key()),
            'detected' => false,
        ];
    }

    public function canMigrate()
    {
        return true;
    }

    public function getStats()
    {
        return [];
    }

    public function getStatus()
    {
        $failedLogs = $this->getFailedLogs();

        return [
            'migration'        => get_option($this->stateOptionKey(), false),
            'failed_log_count' => count($failedLogs),
            'recount_substeps' => $this->getRecountSubsteps(),
        ];
    }

    public function migrateProducts()
    {
        return $this->notImplemented('products');
    }

    public function migrateCoupons()
    {
        return $this->notImplemented('coupons');
    }

    public function migrateTaxRates()
    {
        return $this->notImplemented('tax_rates');
    }

    public function getPaymentResumePage()
    {
        $state = $this->getState();

        // payments done → start value is irrelevant (migratePayments skips).
        if (($state['payments'] ?? '') === 'yes') {
            return 1;
        }

        // last_order_page is the last *completed* page (0 when nothing done yet),
        // so always resume at the next page. This must advance even after only
        // page 1 completed, or a time-boxed batch would restart page 1 forever.
        $lastCompleted = (int) ($state['last_order_page'] ?? 0);

        return $lastCompleted + 1;
    }

    /**
     * Migrate orders into FluentCart, paginated and resumable. Shared by every
     * source that extends this base: it owns the loop, state, time-box and
     * memory-box; the source only implements migrateOrdersPage() to read+write
     * one page. REST callers pass a small $maxSeconds to dodge HTTP timeouts;
     * CLI callers pass 0 (and an unlimited memory_limit) to run to completion.
     */
    public function migratePayments($page = 1, $perPage = 100, $maxSeconds = 25)
    {
        if ($this->isStepDone('payments')) {
            return [
                'success'         => true,
                'step'            => 'payments',
                'page'            => $page,
                'processed'       => 0,
                'has_more'        => false,
                'errors_in_batch' => count($this->getFailedLogs()),
                'log_counts'      => MigrationLog::counts($this->getLogEntries()),
                'skipped'         => true,
                'migration_state' => $this->getState(),
            ];
        }

        $startedAt      = time();
        $totalProcessed = 0;
        $hasMore        = true;

        while ($hasMore) {
            $this->resetBatchCaches();

            $batch = $this->migrateOrdersPage($page, $perPage);
            $totalProcessed += (int) ($batch['processed'] ?? 0);
            $hasMore = !empty($batch['has_more']);

            $state = $this->getState();
            $state['last_order_page'] = $page;
            $this->saveState($state);

            // Release runtime caches that pile up while loading source orders.
            BatchRuntime::freeMemory();

            if (!$hasMore) {
                break;
            }

            $page++;

            if ($maxSeconds > 0 && (time() - $startedAt) >= $maxSeconds) {
                break;
            }

            // Hand off to the next request before exhausting memory.
            if (BatchRuntime::memoryNearLimit()) {
                break;
            }
        }

        $state = $this->getState();
        if (!$hasMore) {
            $state['payments'] = 'yes';
            $this->saveState($state);
            $this->buildAndSaveSummary();
        }

        return [
            'success'         => true,
            'step'            => 'payments',
            'page'            => $page,
            'processed'       => $totalProcessed,
            'has_more'        => $hasMore,
            'errors_in_batch' => count($this->getFailedLogs()),
            'log_counts'      => MigrationLog::counts($this->getLogEntries()),
            'migration_state' => $state,
        ];
    }

    /**
     * Read and write one page of orders. Sources override this. Returns
     * ['processed' => int, 'has_more' => bool].
     */
    public function migrateOrdersPage($page, $perPage)
    {
        return ['processed' => 0, 'has_more' => false];
    }

    /**
     * Reset per-batch caches between pages. Defaults to the shared customer
     * cache; sources may override to also clear their own.
     */
    protected function resetBatchCaches()
    {
        CustomerWriter::resetCache();
    }

    public function migrateMissingCustomers()
    {
        return $this->notImplemented('missing_customers');
    }

    public function getRecountSubsteps()
    {
        return [];
    }

    public function recountStats($substep)
    {
        return $this->notImplemented('recount:' . $substep);
    }

    /**
     * Skip/failure report: the raw log (back-compat), plus normalized entries,
     * per-severity counts, one group per reason and source totals — everything
     * the UI panel and the CSV export need.
     */
    public function getLogs()
    {
        $logs    = $this->getFailedLogs();
        $entries = MigrationLog::entries($logs);

        // The per-reason hint lives on the group; dropping it from every entry
        // keeps the payload small on stores with thousands of skipped records.
        $slim = array_map(function ($entry) {
            unset($entry['hint']);
            return $entry;
        }, $entries);

        return [
            'logs'    => $logs,
            'count'   => count($logs),
            'entries' => $slim,
            'counts'  => MigrationLog::counts($entries),
            'groups'  => MigrationLog::groups($entries),
            'totals'  => $this->logTotals(),
        ];
    }

    public function buildAndSaveSummary()
    {
        $state  = $this->getState();
        $counts = MigrationLog::counts($this->getLogEntries());

        $done = function ($step) use ($state) {
            return ['done' => ($state[$step] ?? '') === 'yes'];
        };

        // Same shape as the EDD summary (steps.{step}.done, steps.payments.errors)
        // so the intro screen renders both sources the same way; `skipped` and
        // `failed` split the error count by severity for the skip report.
        $summary = [
            'source'       => $this->key(),
            'completed_at' => current_time('mysql'),
            'steps'        => [
                'products'          => $done('products'),
                'tax_rates'         => $done('tax_rates'),
                'coupons'           => $done('coupons'),
                'payments'          => [
                    'done'    => ($state['payments'] ?? '') === 'yes',
                    'errors'  => $counts['total'],
                    'skipped' => $counts['skipped'],
                    'failed'  => $counts['failed'],
                ],
                'missing_customers' => $done('missing_customers'),
                'recount'           => $done('recount'),
            ],
            'state'        => $state,
            'log_counts'   => $counts,
            'totals'       => $this->logTotals(),
            'stats'        => [],
        ];

        try {
            $stats = $this->getStats();
            $summary['stats'] = [
                'products'      => $stats['products_count'] ?? 0,
                'orders'        => $stats['orders_count'] ?? 0,
                'customers'     => $stats['customers_count'] ?? 0,
                'subscriptions' => $stats['subscriptions_count'] ?? 0,
                'coupons'       => $stats['coupons_count'] ?? 0,
            ];
        } catch (\Exception $e) {
            // Source tables may be gone; leave stats empty.
        }

        update_option($this->summaryOptionKey(), $summary, false);

        return $summary;
    }

    public function getMigrationSummary()
    {
        return get_option($this->summaryOptionKey(), null);
    }

    public function reset()
    {
        if (!defined('FLUENT_CART_DEV_MODE') || !FLUENT_CART_DEV_MODE) {
            return new \WP_Error(
                'dev_mode_required',
                __('Reset is only available in dev mode. Define FLUENT_CART_DEV_MODE in wp-config.php.', 'fluent-cart-migrator'),
                ['status' => 403]
            );
        }

        delete_option($this->stateOptionKey());
        delete_option($this->failedLogOptionKey());
        delete_option($this->summaryOptionKey());

        return [
            'success' => true,
            /* translators: %s: migration source key */
            'message' => sprintf(__('Migration state for %s has been reset.', 'fluent-cart-migrator'), $this->key()),
        ];
    }

    protected function notImplemented($step)
    {
        return [
            'success' => false,
            'step'    => $step,
            /* translators: %s: migration source key */
            'message' => sprintf(__('This migration step is not implemented yet for %s.', 'fluent-cart-migrator'), $this->key()),
        ];
    }
}
