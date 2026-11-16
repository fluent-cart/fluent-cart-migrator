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

    const PAGE_SIZE = 500;

    /**
     * @return array{count:int,wcs_active:bool} count = migrated subscriptions with a pending Woo action
     */
    public function status()
    {
        $subIds = [];

        if ($this->available()) {
            $migrated = $this->migratedSubscriptionIds();
            $cursor   = 0;

            while ($migrated) {
                $page = $this->pendingPage($cursor);
                foreach ($this->matchPage($page, $migrated) as $subId) {
                    $subIds[$subId] = true;
                }
                if (count($page) < self::PAGE_SIZE) {
                    break;
                }
                $cursor = (int) Arr::get(end($page), 'action_id', $cursor);
            }
        }

        return [
            'count'      => count($subIds),
            'wcs_active' => class_exists('WC_Subscriptions') || function_exists('wcs_get_subscription'),
        ];
    }

    /**
     * Cancel one time-boxed batch. Call again with the returned last_id (the last
     * action id scanned) until done.
     *
     * @return array{done:bool,last_id:int}
     */
    public function stop($lastId = 0, $maxSeconds = 20)
    {
        $lastId = (int) $lastId;

        if (!$this->available()) {
            return ['done' => true, 'last_id' => $lastId];
        }

        $migrated = $this->migratedSubscriptionIds();
        if (!$migrated) {
            return ['done' => true, 'last_id' => $lastId];
        }

        $startedAt = time();

        while (true) {
            $page    = $this->pendingPage($lastId);
            $matches = $this->matchPage($page, $migrated);

            foreach ($page as $row) {
                $actionId = (int) Arr::get($row, 'action_id', 0);

                $canceled = isset($matches[$actionId]);
                if ($canceled) {
                    // Throws only when the UPDATE fails; a row that already ran updates nothing.
                    \ActionScheduler::store()->cancel_action($actionId);
                }
                $lastId = $actionId;

                if ((time() - $startedAt) >= $maxSeconds || ($canceled && BatchRuntime::memoryNearLimit())) {
                    return ['done' => false, 'last_id' => $lastId];
                }
            }

            if (count($page) < self::PAGE_SIZE) {
                return ['done' => true, 'last_id' => $lastId];
            }

            if (BatchRuntime::memoryNearLimit()) {
                return ['done' => false, 'last_id' => $lastId];
            }
        }
    }

    private function available()
    {
        return class_exists('ActionScheduler')
            && \ActionScheduler::is_initialized()
            && function_exists('as_get_scheduled_actions');
    }

    /**
     * The actions of a page that belong to a migrated subscription. Retries are
     * keyed by the renewal order, so they map through its _subscription_renewal meta.
     *
     * @return array<int, int> action id => WC subscription id
     */
    private function matchPage(array $page, array $migrated)
    {
        $matches = [];

        foreach ($page as $row) {
            $actionId = (int) Arr::get($row, 'action_id', 0);
            $args     = (array) Arr::get($row, 'args', []);

            if (Arr::get($row, 'hook') !== self::RETRY_HOOK) {
                $subId = (int) Arr::get($args, 'subscription_id', 0);
                if (isset($migrated[$subId])) {
                    $matches[$actionId] = $subId;
                }
                continue;
            }

            if (!function_exists('wc_get_order')) {
                continue;
            }
            $order = wc_get_order((int) Arr::get($args, 'order_id', 0));
            if (!$order) {
                continue;
            }
            foreach ($order->get_meta('_subscription_renewal', false) as $meta) {
                $subId = (int) $meta->value;
                if (isset($migrated[$subId])) {
                    $matches[$actionId] = $subId;
                    break;
                }
            }
        }

        return $matches;
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
     * Up to PAGE_SIZE pending Woo subscription actions after an action id, in id order.
     *
     * @return array<int, array{action_id:int,hook:string,args:array}>
     */
    private function pendingPage($afterId)
    {
        global $wpdb;

        if (!(\ActionScheduler::store() instanceof \ActionScheduler_DBStore)) {
            throw new \RuntimeException(__('WooCommerce scheduled actions are not in their database tables yet. Let Action Scheduler finish its data migration, then try again.', 'fluent-cart-migrator'));
        }

        $hooks = array_merge(self::SUBSCRIPTION_HOOKS, [self::RETRY_HOOK]);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT action_id, hook, args FROM {$wpdb->actionscheduler_actions}
             WHERE status = %s AND action_id > %d AND hook IN (" . implode(',', array_fill(0, count($hooks), '%s')) . ")
             ORDER BY action_id ASC LIMIT %d",
            array_merge([\ActionScheduler_Store::STATUS_PENDING, (int) $afterId], $hooks, [self::PAGE_SIZE])
        ));

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException($wpdb->last_error);
        }

        $page = [];
        foreach ((array) $rows as $row) {
            $args   = json_decode((string) $row->args, true);
            $page[] = [
                'action_id' => (int) $row->action_id,
                'hook'      => (string) $row->hook,
                'args'      => is_array($args) ? $args : [],
            ];
        }

        return $page;
    }
}
