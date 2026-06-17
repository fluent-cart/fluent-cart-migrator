<?php

namespace FluentCartMigrator\Classes\Contracts;

/**
 * Base class for new migration sources (WooCommerce, SureCart, ...).
 *
 * Provides per-source progress state (namespaced option keys), failed-log
 * helpers, and safe "not implemented yet" defaults for every migration step so
 * a concrete source can be built up incrementally — overriding only the steps
 * it currently supports while still satisfying SourceMigratorInterface.
 *
 * Note: the EDD source (MigratorService) does NOT extend this; it predates the
 * contract and keeps its own option keys.
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

    protected function logFailed($id, $message = 'unknown reason', $args = [])
    {
        $logs        = $this->getFailedLogs();
        $args['message'] = $message;
        $logs[$id]   = $args;
        update_option($this->failedLogOptionKey(), $logs, false);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line($id . ' => ' . $message);
        }

        return $logs;
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
        $page  = (int) ($state['last_order_page'] ?? 1);

        if ($page > 1 && ($state['payments'] ?? '') !== 'yes') {
            $page++;
        }

        return $page < 1 ? 1 : $page;
    }

    public function migratePayments($page = 1, $perPage = 100, $maxSeconds = 25)
    {
        return $this->notImplemented('payments');
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

    public function getLogs()
    {
        $logs = $this->getFailedLogs();

        return [
            'logs'  => $logs,
            'count' => count($logs),
        ];
    }

    public function buildAndSaveSummary()
    {
        $state = $this->getState();

        $summary = [
            'source'       => $this->key(),
            'completed_at' => current_time('mysql'),
            'steps'        => $state,
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
                'Reset is only available in dev mode. Define FLUENT_CART_DEV_MODE in wp-config.php.',
                ['status' => 403]
            );
        }

        delete_option($this->stateOptionKey());
        delete_option($this->failedLogOptionKey());
        delete_option($this->summaryOptionKey());

        return [
            'success' => true,
            'message' => 'Migration state for ' . $this->key() . ' has been reset.',
        ];
    }

    protected function notImplemented($step)
    {
        return [
            'success' => false,
            'step'    => $step,
            'message' => 'This migration step is not implemented yet for ' . $this->key() . '.',
        ];
    }
}
