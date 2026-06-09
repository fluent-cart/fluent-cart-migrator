<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCartMigrator\Classes\Contracts\AbstractSourceMigrator;

/**
 * WooCommerce migration source.
 *
 * Reads via the WooCommerce CRUD API (wc_get_products/wc_get_orders/WC_Coupon)
 * so it is agnostic to HPOS vs legacy post storage. This first iteration wires
 * up detection + source-side stats; the per-entity migration steps (products,
 * orders, customers, coupons, tax) land in follow-up iterations and currently
 * inherit the "not implemented yet" defaults from AbstractSourceMigrator.
 */
class WooSourceMigrator extends AbstractSourceMigrator
{
    public function key()
    {
        return 'woocommerce';
    }

    public function detect()
    {
        $active = class_exists('WooCommerce');

        return [
            'key'      => 'woocommerce',
            'name'     => 'WooCommerce',
            'detected' => $active,
            'version'  => ($active && defined('WC_VERSION')) ? WC_VERSION : null,
        ];
    }

    public function canMigrate()
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', 'WooCommerce plugin not found. Please install and activate WooCommerce and try again.');
        }

        if (!$this->countProducts()) {
            return new \WP_Error('no_woo_products', 'No WooCommerce products found. Nothing to migrate.');
        }

        return true;
    }

    public function getStats()
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('woocommerce_not_found', 'WooCommerce is not active.');
        }

        $allOrderStatuses = array_keys(wc_get_order_statuses()); // ['wc-pending', ...]
        $paidStatuses     = ['wc-processing', 'wc-completed', 'wc-refunded'];

        $productsCount = $this->countProducts();
        $ordersCount   = $this->countOrders('shop_order', 'any');
        $paidOrders    = $this->countOrders('shop_order', $paidStatuses);
        $couponsCount  = (int) (wp_count_posts('shop_coupon')->publish ?? 0);

        $registeredCustomers = $this->countRegisteredCustomers();
        $fctCustomers        = $this->countFctCustomers();

        $hasSubscriptions   = function_exists('wcs_get_subscriptions') || class_exists('WC_Subscriptions');
        $subscriptionsCount = $hasSubscriptions ? $this->countOrders('shop_subscription', 'any') : 0;

        $statuses = array_map(function ($status) {
            return str_replace('wc-', '', $status);
        }, $allOrderStatuses);

        return [
            'products_count'      => $productsCount,
            'orders_count'        => $ordersCount,
            'transactions_count'  => $paidOrders,
            'customers_count'     => $registeredCustomers,
            'customers_breakdown' => [
                'source_total' => $registeredCustomers,
                'fct_total'    => $fctCustomers,
            ],
            'subscriptions_count' => $subscriptionsCount,
            'licenses_count'      => 0, // WooCommerce core has no licensing
            'coupons_count'       => $couponsCount,
            'gateways'            => $this->getGatewayIds(),
            'statuses'            => array_values($statuses),
            'types'               => ['shop_order'],
            'has_subscriptions'   => $hasSubscriptions,
            'has_licenses'        => false,
        ];
    }

    /* -----------------------------------------------------------------
     | Counting helpers (CRUD API — HPOS-safe)
     * ----------------------------------------------------------------- */

    protected function countProducts()
    {
        if (!function_exists('wc_get_products')) {
            return 0;
        }

        $result = wc_get_products([
            'limit'    => 1,
            'paginate' => true,
            'return'   => 'ids',
            'status'   => ['publish', 'private', 'draft'],
        ]);

        return (int) ($result->total ?? 0);
    }

    protected function countOrders($type, $status)
    {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $result = wc_get_orders([
            'limit'    => 1,
            'paginate' => true,
            'return'   => 'ids',
            'type'     => $type,
            'status'   => $status,
        ]);

        return (int) ($result->total ?? 0);
    }

    protected function countRegisteredCustomers()
    {
        $query = new \WP_User_Query([
            'role'        => 'customer',
            'number'      => 1,
            'count_total' => true,
            'fields'      => 'ID',
        ]);

        return (int) $query->get_total();
    }

    protected function countFctCustomers()
    {
        if (!function_exists('fluentCart')) {
            return 0;
        }

        return (int) fluentCart('db')->table('fct_customers')->count();
    }

    protected function getGatewayIds()
    {
        $ids = [];

        if (function_exists('WC') && WC()->payment_gateways) {
            foreach (WC()->payment_gateways->payment_gateways() as $gateway) {
                $ids[] = $gateway->id;
            }
        }

        return $ids;
    }
}
