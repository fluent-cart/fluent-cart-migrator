<?php

namespace FluentCartMigrator\Classes\FluentForms;

use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeSettingsBase;
use FluentCart\Framework\Support\Arr;
use FluentCartMigrator\Classes\Contracts\AbstractSourceMigrator;
use FluentCartMigrator\Classes\Support\MigrationLog;

/**
 * Wizard source for Fluent Forms Stripe subscriptions. Only the payments step
 * applies: each page hands its subscriptions to SubscriptionMigrator, which
 * creates the products, customers and orders it needs and recounts only the
 * customers it created.
 */
class FluentFormsSourceMigrator extends AbstractSourceMigrator
{
    const PAID_STATUSES = ['paid', 'refunded', 'partially-refunded'];

    const LOCK_NAME = 'fct_migrator_fluentforms_payments';

    public function key()
    {
        return 'fluentforms';
    }

    public function detect()
    {
        $active  = defined('FLUENTFORM');
        $version = ($active && defined('FLUENTFORM_VERSION')) ? FLUENTFORM_VERSION : null;

        if (!$active) {
            $compatibility = [
                'state'   => 'blocked',
                'title'   => __('Fluent Forms not detected', 'fluent-cart-migrator'),
                'message' => __('Fluent Forms is not active. Please activate Fluent Forms, then return here.', 'fluent-cart-migrator'),
            ];
        } else {
            $compatibility = [
                'state'   => 'pass',
                /* translators: 1: Fluent Forms version number */
                'title'   => $version ? sprintf(__('Fluent Forms detected (v%1$s)', 'fluent-cart-migrator'), $version) : __('Fluent Forms detected', 'fluent-cart-migrator'),
                'message' => __('Stripe subscriptions from your payment forms can be moved to FluentCart.', 'fluent-cart-migrator'),
            ];
        }

        return [
            'key'           => 'fluentforms',
            'name'          => 'Fluent Forms',
            'detected'      => $active,
            'version'       => $version,
            'compatibility' => $compatibility,
        ];
    }

    public function canMigrate()
    {
        if (!defined('FLUENTFORM')) {
            return new \WP_Error('fluentforms_not_found', __('Fluent Forms is not active. Please activate Fluent Forms and try again.', 'fluent-cart-migrator'));
        }

        if (!$this->countStripeSubscriptions()) {
            return new \WP_Error('no_ff_stripe_subscriptions', __('No paid Stripe subscriptions found in Fluent Forms. Nothing to migrate.', 'fluent-cart-migrator'));
        }

        if (!(new StripeSettingsBase())->isActive()) {
            return new \WP_Error('fct_stripe_inactive', __('Stripe is not active in FluentCart. Connect the same Stripe account under FluentCart → Settings → Payments, then try again.', 'fluent-cart-migrator'));
        }

        return true;
    }

    public function getStats()
    {
        if (!defined('FLUENTFORM')) {
            return new \WP_Error('fluentforms_not_found', __('Fluent Forms is not active.', 'fluent-cart-migrator'));
        }

        $db      = fluentCart('db');
        $charges = $this->stripeChargesQuery()->count();
        $total   = $db->table('fluentform_subscriptions')->count();

        $customers = $this->stripeChargesQuery()->selectRaw('COUNT(DISTINCT payer_email) AS total')->first();

        $gateways = $db->table('fluentform_transactions')
            ->where('transaction_type', 'subscription')
            ->where('subscription_id', '>', 0)
            ->groupBy('payment_method')
            ->pluck('payment_method');

        $statuses = $db->table('fluentform_subscriptions')
            ->groupBy('status')
            ->pluck('status');

        return [
            'products_count'             => 0,
            'orders_count'               => $charges,
            'transactions_count'         => $charges,
            'customers_count'            => (int) Arr::get((array) $customers, 'total', 0),
            'subscriptions_count'        => $this->countStripeSubscriptions(),
            'progress_total'             => $total,
            'has_subscriptions'          => true,
            'coupons_count'              => 0,
            'reviews_count'              => 0,
            'gateways'                   => array_values(array_filter($this->toArray($gateways))),
            'statuses'                   => array_values(array_filter($this->toArray($statuses))),
        ];
    }

    private $runId = '';

    /**
     * The wizard sends one id per Start; retries and Pause/Resume reuse it, so only a
     * new Start rescans a finished run.
     */
    public function setRunId($runId)
    {
        $this->runId = substr(sanitize_key((string) $runId), 0, 64);
    }

    /**
     * A wizard retry or a second tab can post the same page while it is still
     * running, and subscription writes are not idempotent within a page. The
     * resume page is read only after the lock is held.
     */
    public function migratePayments($page = 1, $perPage = 100, $maxSeconds = 25)
    {
        if (!self::acquireLock(30)) {
            return [
                'success'         => true,
                'step'            => 'payments',
                'page'            => $page,
                'processed'       => 0,
                'has_more'        => true,
                'errors_in_batch' => 0,
            ];
        }

        try {
            // A finished run starts over from page 1 so subscriptions added since are picked up;
            // ones already in FluentCart are skipped by SubscriptionMigrator.
            $state = $this->getState();
            if ($this->runId !== '' && ($state['run_id'] ?? '') !== $this->runId) {
                if (($state['payments'] ?? '') === 'yes') {
                    unset($state['payments'], $state['last_order_page']);
                }
                $state['run_id'] = $this->runId;
                $this->saveState($state);
            }

            return parent::migratePayments($this->getPaymentResumePage(), $perPage, $maxSeconds);
        } finally {
            self::releaseLock();
        }
    }

    public static function acquireLock($timeout)
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, $timeout)) === 1;
    }

    public static function releaseLock()
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK_NAME));
    }

    public function migrateOrdersPage($page, $perPage)
    {
        $page    = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);

        $subscriptions = fluentCart('db')->table('fluentform_subscriptions')
            ->orderBy('id', 'ASC')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $migrator = new SubscriptionMigrator(false);

        $processed = 0;
        foreach ($subscriptions as $ffSub) {
            $processed++;
            try {
                $result = $migrator->migrate($ffSub);
            } catch (\Throwable $e) {
                $result = ['status' => 'failed', 'message' => $e->getMessage()];
            }

            if (Arr::get($result, 'status') === 'migrated') {
                $this->forgetLogged($ffSub);
            } else {
                $this->logSubscription($ffSub, $result);
            }
        }

        return [
            'processed' => $processed,
            'has_more'  => $processed === $perPage,
        ];
    }

    protected function logTotals()
    {
        return [
            'source_orders'   => $this->countStripeSubscriptions(),
            'migrated_orders' => fluentCart('db')->table('fct_subscriptions')
                ->where('config', 'like', '%"migration_source":"fluentforms"%')
                ->count(),
        ];
    }

    private function logSubscription($ffSub, array $result)
    {
        $code = Arr::get($result, 'code') ?: (Arr::get($result, 'status') === 'skipped' ? 'ff_not_stripe' : 'ff_failed');

        $this->logFailed(MigrationLog::key('ff_subscription', (int) $ffSub->id), (string) Arr::get($result, 'message', ''), [
            'type'    => 'ff_subscription',
            'id'      => (int) $ffSub->id,
            'code'    => $code,
            'stage'   => 'payments',
            'context' => [
                'number' => (string) $ffSub->vendor_subscription_id,
                'status' => (string) $ffSub->status,
                'date'   => substr((string) $ffSub->created_at, 0, 10),
                'name'   => (string) $ffSub->plan_name,
                'url'    => admin_url('admin.php?page=fluent_forms&route=entries&form_id=' . (int) $ffSub->form_id . '#/entries/' . (int) $ffSub->submission_id),
            ],
        ]);
    }

    // A subscription skipped in an earlier run (never paid then) can migrate on a rescan.
    private function forgetLogged($ffSub)
    {
        $logs = MigrationLog::all($this->failedLogOptionKey());
        $key  = MigrationLog::key('ff_subscription', (int) $ffSub->id);
        if (isset($logs[$key])) {
            unset($logs[$key]);
            update_option($this->failedLogOptionKey(), $logs, false);
        }
    }

    private function stripeChargesQuery()
    {
        return fluentCart('db')->table('fluentform_transactions')
            ->where('transaction_type', 'subscription')
            ->where('subscription_id', '>', 0)
            ->where('payment_method', 'stripe')
            ->whereIn('status', self::PAID_STATUSES);
    }

    private function countStripeSubscriptions()
    {
        $row = $this->stripeChargesQuery()->selectRaw('COUNT(DISTINCT subscription_id) AS total')->first();

        return (int) Arr::get((array) $row, 'total', 0);
    }

    private function toArray($values)
    {
        return is_array($values) ? $values : $values->toArray();
    }
}
