<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCart\Framework\Support\Arr;
use FluentCartMigrator\Classes\Support\BatchRuntime;

/**
 * Cancels WooCommerce Subscriptions' pending Action Scheduler actions for the
 * subscriptions this migrator moved to FluentCart, so Woo and FluentCart do not
 * both bill the same cycle.
 *
 * Only the queued actions are canceled. The Woo subscriptions themselves are
 * never loaded or saved: a status change would email customers and call the
 * gateway. WooCommerce Subscriptions re-schedules these actions when it edits
 * an active subscription, so it must be deactivated once the store is verified.
 */
class RenewalScheduleStopper
{
    const RETRY_HOOK = 'woocommerce_scheduled_subscription_payment_retry';

    // Scheduled with args ['subscription_id' => WC subscription id], in any group.
    const SUBSCRIPTION_HOOKS = [
        'woocommerce_scheduled_subscription_payment',
        'woocommerce_scheduled_subscription_trial_end',
        'woocommerce_scheduled_subscription_expiration',
        'woocommerce_scheduled_subscription_end_of_prepaid_term',
        'woocommerce_scheduled_subscription_customer_notification_renewal',
        'woocommerce_scheduled_subscription_customer_notification_trial_expiration',
        'woocommerce_scheduled_subscription_customer_notification_expiration',
    ];

    /**
     * @return array{count:int,wcs_active:bool} count = migrated subscriptions with a pending Woo action
     */
    public function status()
    {
        $count = 0;

        if ($this->available()) {
            $count = count(array_unique($this->migratedActions()));
        }

        return [
            'count'      => $count,
            'wcs_active' => class_exists('WC_Subscriptions') || function_exists('wcs_get_subscription'),
        ];
    }

    /**
     * Cancel one time-boxed batch. Call again with the returned last_id (an
     * action id) until done.
     *
     * @return array{done:bool,last_id:int}
     */
    public function stop($lastId = 0, $maxSeconds = 20)
    {
        $lastId = (int) $lastId;

        if (!$this->available()) {
            return ['done' => true, 'last_id' => $lastId];
        }

        $startedAt = time();
        $actionIds = array_keys($this->migratedActions());
        sort($actionIds);

        foreach ($actionIds as $actionId) {
            if ($actionId <= $lastId) {
                continue;
            }

            try {
                \ActionScheduler::store()->cancel_action($actionId);
            } catch (\Exception $e) {
                // Already ran or was removed since the list was read.
            }
            $lastId = $actionId;

            if ((time() - $startedAt) >= $maxSeconds || BatchRuntime::memoryNearLimit()) {
                return ['done' => $actionId === end($actionIds), 'last_id' => $lastId];
            }
        }

        return ['done' => true, 'last_id' => $lastId];
    }

    private function available()
    {
        return class_exists('ActionScheduler')
            && \ActionScheduler::is_initialized()
            && function_exists('as_get_scheduled_actions');
    }

    /**
     * Pending Woo actions that belong to a migrated subscription. Retries are
     * keyed by the renewal order, so they map through its _subscription_renewal meta.
     *
     * @return array<int, int> action id => WC subscription id
     */
    private function migratedActions()
    {
        $migrated = $this->migratedSubscriptionIds();
        if (!$migrated) {
            return [];
        }

        $actions = [];
        foreach (self::SUBSCRIPTION_HOOKS as $hook) {
            foreach ($this->pendingArgs($hook) as $actionId => $args) {
                $subId = (int) Arr::get($args, 'subscription_id', 0);
                if (isset($migrated[$subId])) {
                    $actions[$actionId] = $subId;
                }
            }
        }

        if (function_exists('wc_get_order')) {
            foreach ($this->pendingArgs(self::RETRY_HOOK) as $actionId => $args) {
                $order = wc_get_order((int) Arr::get($args, 'order_id', 0));
                if (!$order) {
                    continue;
                }
                foreach ($order->get_meta('_subscription_renewal', false) as $meta) {
                    $subId = (int) $meta->value;
                    if (isset($migrated[$subId])) {
                        $actions[$actionId] = $subId;
                        break;
                    }
                }
            }
        }

        return $actions;
    }

    /**
     * WC subscription ids of the migrated rows, read from config: the row's
     * vendor_subscription_id can be edited later.
     *
     * @return array<int, true>
     */
    private function migratedSubscriptionIds()
    {
        $ids    = [];
        $lastId = 0;

        do {
            $rows = fluentCart('db')->table('fct_subscriptions')
                ->select(['id', 'config'])
                ->where('id', '>', $lastId)
                // A JSON column reads back as `"key": "value"`, so match the key only.
                ->where('config', 'like', '%"migration_source"%')
                ->orderBy('id', 'ASC')
                ->limit(1000)
                ->get();

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $config = json_decode((string) $row->config, true);
                if (!is_array($config) || Arr::get($config, 'migration_source') !== 'woocommerce') {
                    continue;
                }
                $wcId = (int) Arr::get($config, 'wc_subscription_id', 0);
                if ($wcId) {
                    $ids[$wcId] = true;
                }
            }
        } while (!$rows->isEmpty());

        return $ids;
    }

    /**
     * Args of every pending action for a hook. On the DB store this is one query,
     * instead of loading each action object.
     *
     * @return array<int, array> action id => args
     */
    private function pendingArgs($hook)
    {
        global $wpdb;

        $pending = [];

        if (\ActionScheduler::store() instanceof \ActionScheduler_DBStore) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT action_id, args FROM {$wpdb->actionscheduler_actions} WHERE hook = %s AND status = %s",
                $hook,
                \ActionScheduler_Store::STATUS_PENDING
            ));
            foreach ((array) $rows as $row) {
                $args = json_decode((string) $row->args, true);
                if (is_array($args)) {
                    $pending[(int) $row->action_id] = $args;
                }
            }

            return $pending;
        }

        $actions = as_get_scheduled_actions([
            'hook'     => $hook,
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => -1,
        ]);
        foreach ($actions as $actionId => $action) {
            $pending[(int) $actionId] = (array) $action->get_args();
        }

        return $pending;
    }
}
