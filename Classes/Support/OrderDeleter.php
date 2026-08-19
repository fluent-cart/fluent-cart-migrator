<?php

namespace FluentCartMigrator\Classes\Support;

/**
 * Source-agnostic cascade delete of a migrated FluentCart order and all of its
 * child records, so a re-run replaces rather than duplicates (orders are keyed
 * by the reused source id). Superset of what any single source writes; deleting
 * a table that has no rows for the order is a harmless no-op.
 */
class OrderDeleter
{
    public static function deleteById($orderId)
    {
        $db = fluentCart('db');

        // Child / renewal orders first (recursive).
        $childOrderIds = $db->table('fct_orders')
            ->where('parent_id', $orderId)
            ->pluck('id')
            ->toArray();

        foreach ($childOrderIds as $childId) {
            self::deleteById($childId);
        }

        // Subscriptions + their meta/activity.
        $subscriptions = $db->table('fct_subscriptions')
            ->where('parent_order_id', $orderId)
            ->get();

        foreach ($subscriptions as $subscription) {
            $db->table('fct_subscription_meta')->where('subscription_id', $subscription->id)->delete();
            $db->table('fct_activity')
                ->where('module_id', $subscription->id)
                ->where('module_type', 'FluentCart\App\Models\Subscription')
                ->delete();
        }
        $db->table('fct_subscriptions')->where('parent_order_id', $orderId)->delete();

        // Licenses + activations (EDD). The licensing tables only exist on
        // installs with the licensing module — guard so a WooCommerce-only
        // store (no fct_licenses) doesn't abort the cascade on re-migration.
        try {
            $licenses = $db->table('fct_licenses')->where('order_id', $orderId)->get();
            foreach ($licenses as $license) {
                $db->table('fct_license_activations')->where('license_id', $license->id)->delete();
            }
            $db->table('fct_licenses')->where('order_id', $orderId)->delete();
        } catch (\Exception $e) {
            // Licensing tables absent on this install — nothing to delete.
        }

        // Order-scoped records.
        $db->table('fct_order_items')->where('order_id', $orderId)->delete();
        $db->table('fct_order_meta')->where('order_id', $orderId)->delete();
        $db->table('fct_order_operations')->where('order_id', $orderId)->delete();
        $db->table('fct_order_transactions')->where('order_id', $orderId)->delete();
        $db->table('fct_activity')
            ->where('module_id', $orderId)
            ->where('module_type', 'FluentCart\App\Models\Order')
            ->delete();
        $db->table('fct_order_addresses')->where('order_id', $orderId)->delete();
        $db->table('fct_order_tax_rate')->where('order_id', $orderId)->delete();
        self::deleteIfTableExists('fct_order_download_permissions', $orderId);
        $db->table('fct_applied_coupons')->where('order_id', $orderId)->delete();

        // The order itself last.
        $db->table('fct_orders')->where('id', $orderId)->delete();
    }

    /**
     * Some FluentCart installs/versions may not have every optional table; guard
     * the less-universal ones so a missing table doesn't abort the cascade.
     */
    protected static function deleteIfTableExists($table, $orderId)
    {
        try {
            fluentCart('db')->table($table)->where('order_id', $orderId)->delete();
        } catch (\Exception $e) {
            // Table absent on this install — nothing to delete.
        }
    }
}
