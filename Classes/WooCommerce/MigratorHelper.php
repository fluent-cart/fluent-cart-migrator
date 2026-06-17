<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Helpers\Status;

/**
 * Shared helpers for the WooCommerce source: money conversion, date formatting,
 * id-mapping meta, status maps, and SKU de-duplication. Reused across the
 * product, order, customer, coupon and tax migrators.
 */
class MigratorHelper
{
    /**
     * email (lowercased) => fct_customers row. Reset between order batches to
     * keep memory flat while still deduping customers within a page.
     */
    public static $customerCache = [];

    /**
     * Clear per-batch static caches. Mirrors the EDD source so the paginated
     * payment loop doesn't grow unbounded.
     */
    public static function resetCaches()
    {
        self::$customerCache = [];
    }

    /**
     * Reclaim memory accumulated during a bulk migration. WordPress (and
     * WooCommerce on top of it) caches every loaded post/order/meta in the
     * in-memory object cache, SAVEQUERIES retains every query, and dev tooling
     * such as the FluentStudio debugger buffers every query plus a backtrace
     * until shutdown. Across thousands of orders that exhausts PHP's memory
     * limit, so the paginated loop calls this between pages to flush the
     * runtime caches (without touching any persistent cache backend).
     */
    public static function freeMemory()
    {
        global $wpdb, $wp_object_cache;

        // Drop the SAVEQUERIES log.
        if (is_object($wpdb) && property_exists($wpdb, 'queries')) {
            $wpdb->queries = [];
        }

        // Reset the in-memory object cache (WP-CLI's classic stop_the_insanity).
        if (is_object($wp_object_cache)) {
            foreach (['cache', 'group_ops', 'stats', 'memcache_debug'] as $prop) {
                if (property_exists($wp_object_cache, $prop)) {
                    $wp_object_cache->$prop = [];
                }
            }
        }

        // The FluentStudio debugger buffers every query (+ backtrace) until
        // shutdown; that buffer is the dominant allocation during a bulk import.
        // (WooCommerce's runtime order cache lives in the object cache cleared
        // above, so it's released too.)
        if (isset($GLOBALS['fluent_query_buffer']) && is_array($GLOBALS['fluent_query_buffer'])) {
            $GLOBALS['fluent_query_buffer'] = [];
        }
    }

    /**
     * The PHP memory_limit in bytes (0 = unlimited / not set).
     */
    public static function memoryLimitBytes()
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $num  = (int) $value;
        switch ($unit) {
            case 'g':
                $num *= 1024;
                // no break
            case 'm':
                $num *= 1024;
                // no break
            case 'k':
                $num *= 1024;
        }

        return $num;
    }

    /**
     * True when the process is using more than $fraction of the memory limit.
     * Used to stop a migration batch before it exhausts memory; the caller
     * resumes in the next (fresh) request. Loading WooCommerce orders retains
     * memory that survives cache flushing, so a single request can only safely
     * process a bounded number before it must hand off.
     */
    public static function memoryNearLimit($fraction = 0.7)
    {
        $limit = self::memoryLimitBytes();
        if ($limit <= 0) {
            return false; // unlimited — let it run (e.g. CLI)
        }

        return memory_get_usage(true) >= (int) ($limit * $fraction);
    }
    /**
     * Meta key written on the WooCommerce product holding the migrated FC product id.
     */
    const WC_TO_FCT_META = '_fct_migrated_id';

    /**
     * Meta key written on the FC product holding the source WooCommerce product id.
     */
    const FCT_FROM_WC_META = '_wc_migrated_from';

    /**
     * Meta key on the FC product mapping WC variation id => FC variation id.
     */
    const VARIATION_MAP_META = '__wc_migrated_variation_maps';

    /**
     * Convert a WooCommerce decimal amount to FluentCart minor units (cents).
     * Zero-decimal currencies (JPY, KRW, ...) are already in the smallest unit.
     */
    public static function toCents($number, $currency = '')
    {
        $float = floatval($number);

        if (!$float) {
            return 0;
        }

        if (!$currency) {
            $currency = self::storeCurrency();
        }

        if ($currency && CurrenciesHelper::isZeroDecimal($currency)) {
            $cents = $float;
        } else {
            $cents = $float * 100;
        }

        $cents = apply_filters('fluentcart_migrator_woo_to_cents', $cents, $number, $currency);

        return (int) round($cents, 0);
    }

    public static function storeCurrency()
    {
        if (function_exists('get_woocommerce_currency')) {
            return get_woocommerce_currency();
        }

        return 'USD';
    }

    /**
     * Format a WC_DateTime (or null) to a MySQL datetime string.
     *
     * @param \WC_DateTime|null $wcDateTime
     */
    public static function date($wcDateTime)
    {
        if ($wcDateTime instanceof \WC_DateTime) {
            return $wcDateTime->date('Y-m-d H:i:s');
        }

        return current_time('mysql');
    }

    /**
     * Map a WooCommerce stock status to FluentCart's.
     */
    public static function stockStatus($wcStatus)
    {
        $maps = [
            'instock'     => 'in-stock',
            'outofstock'  => 'out-of-stock',
            'onbackorder' => 'in-stock',
        ];

        return $maps[$wcStatus] ?? 'in-stock';
    }

    /**
     * 'digital' when the product/variation is virtual or downloadable, else 'physical'.
     *
     * @param \WC_Product $product
     */
    public static function fulfillmentType($product)
    {
        return ($product->is_virtual() || $product->is_downloadable()) ? 'digital' : 'physical';
    }

    /**
     * Max length of fct_product_variations.sku (VARCHAR(30)). MySQL runs in a
     * non-strict sql_mode here, so over-long SKUs are silently truncated on
     * insert — uniqueness must therefore be enforced on the *stored* (truncated)
     * value, not the raw one, or two long SKUs sharing a 30-char prefix collide
     * on the UNIQUE index.
     */
    const SKU_MAX_LENGTH = 30;

    /**
     * Ensure a SKU is unique across fct_product_variations (FC enforces a UNIQUE
     * index) and fits the column. Returns null for empty SKUs (MySQL UNIQUE
     * allows multiple NULLs). When a collision (or duplicate source SKU) forces
     * a suffix, the base is trimmed so the suffix survives the 30-char cap.
     */
    public static function uniqueSku($sku)
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }

        static $used = [];

        $max       = self::SKU_MAX_LENGTH;
        $candidate = self::truncateSku($sku, $max);
        $i         = 1;

        while (isset($used[$candidate]) || self::skuExists($candidate)) {
            $suffix    = '-wc-' . $i;
            $candidate = self::truncateSku($sku, $max - strlen($suffix)) . $suffix;
            $i++;
        }

        $used[$candidate] = true;

        return $candidate;
    }

    protected static function truncateSku($sku, $length)
    {
        if ($length <= 0) {
            return '';
        }

        return function_exists('mb_substr')
            ? mb_substr($sku, 0, $length)
            : substr($sku, 0, $length);
    }

    protected static function skuExists($sku)
    {
        if (!function_exists('fluentCart')) {
            return false;
        }

        return (int) fluentCart('db')->table('fct_product_variations')
            ->where('sku', $sku)
            ->count() > 0;
    }

    /* -----------------------------------------------------------------
     | Status maps (WooCommerce -> FluentCart)
     * ----------------------------------------------------------------- */

    /**
     * Map a WooCommerce order status (without the 'wc-' prefix) to a FluentCart
     * order status.
     */
    public static function orderStatus($wcStatus)
    {
        $maps = [
            'completed'  => Status::ORDER_COMPLETED,
            'processing' => Status::ORDER_PROCESSING,
            'on-hold'    => Status::ORDER_ON_HOLD,
            'pending'    => Status::ORDER_ON_HOLD,
            'cancelled'  => Status::ORDER_CANCELED,
            'refunded'   => Status::ORDER_CANCELED,
            'failed'     => Status::ORDER_FAILED,
        ];

        return $maps[$wcStatus] ?? Status::ORDER_ON_HOLD;
    }

    /**
     * Derive a FluentCart payment status from the WC order status and how much
     * of it has been refunded.
     *
     * @param \WC_Order $order
     */
    public static function paymentStatus($order)
    {
        $status         = $order->get_status(); // no 'wc-' prefix from get_status()
        $total          = self::toCents($order->get_total(), $order->get_currency());
        $totalRefunded  = self::toCents($order->get_total_refunded(), $order->get_currency());

        if ($totalRefunded > 0 && $total > 0) {
            return $totalRefunded >= $total
                ? Status::PAYMENT_REFUNDED
                : Status::PAYMENT_PARTIALLY_REFUNDED;
        }

        if ($status === 'refunded') {
            return Status::PAYMENT_REFUNDED;
        }

        if (in_array($status, ['completed', 'processing'], true)) {
            return Status::PAYMENT_PAID;
        }

        if (in_array($status, ['on-hold', 'pending'], true)) {
            return Status::PAYMENT_PENDING;
        }

        return Status::PAYMENT_FAILED;
    }

    /**
     * Map a WooCommerce Subscriptions status to a FluentCart subscription status.
     */
    public static function subscriptionStatus($wcStatus)
    {
        $maps = [
            'active'         => Status::SUBSCRIPTION_ACTIVE,
            'on-hold'        => Status::SUBSCRIPTION_PAUSED,
            'pending'        => Status::SUBSCRIPTION_PENDING,
            'pending-cancel' => Status::SUBSCRIPTION_CANCELED,
            'cancelled'      => Status::SUBSCRIPTION_CANCELED,
            'expired'        => Status::SUBSCRIPTION_EXPIRED,
        ];

        return $maps[$wcStatus] ?? Status::SUBSCRIPTION_PENDING;
    }

    /**
     * Map a WooCommerce Subscriptions billing period to a FluentCart interval.
     */
    public static function billingInterval($period)
    {
        $maps = [
            'day'   => 'daily',
            'week'  => 'weekly',
            'month' => 'monthly',
            'year'  => 'yearly',
        ];

        return $maps[$period] ?? 'monthly';
    }

    /* -----------------------------------------------------------------
     | Customers
     * ----------------------------------------------------------------- */

    /**
     * Find (by billing email) or create the FluentCart customer for an order.
     * Deduped by email, consistent with the EDD source.
     *
     * @param \WC_Order $order
     * @return object|\WP_Error fct_customers row
     */
    public static function findOrCreateCustomer($order)
    {
        $email  = $order->get_billing_email();
        $userId = (int) $order->get_customer_id();

        if (!$email && $userId) {
            $user  = get_user_by('id', $userId);
            $email = $user ? $user->user_email : '';
        }

        if (!$email) {
            return new \WP_Error('woo_migrator_error', 'Customer email is empty for order #' . $order->get_id());
        }

        $key = strtolower($email);
        if (isset(self::$customerCache[$key])) {
            return self::$customerCache[$key];
        }

        $existing = fluentCart('db')->table('fct_customers')
            ->where('email', $email)
            ->first();

        if ($existing) {
            self::$customerCache[$key] = $existing;
            return $existing;
        }

        $createdAt = self::date($order->get_date_created());

        $data = [
            'user_id'    => $userId ?: null,
            'email'      => $email,
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'status'     => 'active',
            'country'    => $order->get_billing_country(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
            'uuid'       => md5($email . '_' . wp_generate_uuid4()),
            'created_at' => $createdAt,
            'updated_at' => current_time('mysql'),
        ];

        $customerId = fluentCart('db')->table('fct_customers')->insertGetId($data);
        $customer   = fluentCart('db')->table('fct_customers')->find($customerId);

        self::$customerCache[$key] = $customer;

        return $customer;
    }

    /* -----------------------------------------------------------------
     | Coupons
     * ----------------------------------------------------------------- */

    /**
     * Resolve a FluentCart coupon id from a code (0 when not migrated/unknown).
     */
    public static function couponIdByCode($code)
    {
        static $cache = [];

        $code = (string) $code;
        if ($code === '') {
            return 0;
        }

        if (!array_key_exists($code, $cache)) {
            $coupon = fluentCart('db')->table('fct_coupons')->where('code', $code)->first();
            $cache[$code] = $coupon ? (int) $coupon->id : 0;
        }

        return $cache[$code];
    }

    /* -----------------------------------------------------------------
     | Idempotent re-runs
     * ----------------------------------------------------------------- */

    /**
     * Delete a FluentCart order and all of its child records, so a re-run of the
     * order migration replaces rather than duplicates. Mirrors the EDD source's
     * cascade delete.
     */
    public static function deleteOrderById($orderId)
    {
        $childOrderIds = fluentCart('db')->table('fct_orders')
            ->where('parent_id', $orderId)
            ->pluck('id')
            ->toArray();

        foreach ($childOrderIds as $childId) {
            self::deleteOrderById($childId);
        }

        $subscriptions = fluentCart('db')->table('fct_subscriptions')
            ->where('parent_order_id', $orderId)
            ->get();

        foreach ($subscriptions as $subscription) {
            fluentCart('db')->table('fct_subscription_meta')
                ->where('subscription_id', $subscription->id)
                ->delete();

            fluentCart('db')->table('fct_activity')
                ->where('module_id', $subscription->id)
                ->where('module_type', 'FluentCart\App\Models\Subscription')
                ->delete();
        }

        fluentCart('db')->table('fct_subscriptions')->where('parent_order_id', $orderId)->delete();
        fluentCart('db')->table('fct_order_items')->where('order_id', $orderId)->delete();
        fluentCart('db')->table('fct_order_meta')->where('order_id', $orderId)->delete();
        fluentCart('db')->table('fct_order_operations')->where('order_id', $orderId)->delete();
        fluentCart('db')->table('fct_order_transactions')->where('order_id', $orderId)->delete();

        fluentCart('db')->table('fct_activity')
            ->where('module_id', $orderId)
            ->where('module_type', 'FluentCart\App\Models\Order')
            ->delete();

        fluentCart('db')->table('fct_order_addresses')->where('order_id', $orderId)->delete();
        fluentCart('db')->table('fct_order_tax_rate')->where('order_id', $orderId)->delete();
        fluentCart('db')->table('fct_applied_coupons')->where('order_id', $orderId)->delete();
        fluentCart('db')->table('fct_orders')->where('id', $orderId)->delete();
    }
}
