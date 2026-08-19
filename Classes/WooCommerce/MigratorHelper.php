<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCartMigrator\Classes\Support\Money;

/**
 * WooCommerce-source helpers: id-mapping meta keys, money conversion (delegates
 * to the shared Support\Money), WC date/currency getters, and the WC→FluentCart
 * status/stock/fulfillment maps.
 *
 * The source-agnostic concerns that used to live here — customer find-or-create,
 * order cascade delete, SKU de-duplication, and runtime memory hygiene — now
 * live in Classes/Load and Classes/Support and are shared with every source.
 */
class MigratorHelper
{
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
     */
    public static function toCents($number, $currency = '')
    {
        if (!$currency) {
            $currency = self::storeCurrency();
        }

        return Money::toCents($number, $currency, 'woocommerce');
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
     * External/affiliate products only link out — nothing ships, so they are
     * treated as digital regardless of their virtual/downloadable flags.
     *
     * @param \WC_Product $product
     */
    public static function fulfillmentType($product)
    {
        if (method_exists($product, 'is_type') && $product->is_type('external')) {
            return 'digital';
        }

        return ($product->is_virtual() || $product->is_downloadable()) ? 'digital' : 'physical';
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
        $status        = $order->get_status(); // no 'wc-' prefix from get_status()
        $total         = self::toCents($order->get_total(), $order->get_currency());
        $totalRefunded = self::toCents($order->get_total_refunded(), $order->get_currency());

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
    public static function subscriptionStatus($wcStatus, $endDate = '')
    {
        $maps = [
            'active'         => Status::SUBSCRIPTION_ACTIVE,
            'on-hold'        => Status::SUBSCRIPTION_PAUSED,
            'pending'        => Status::SUBSCRIPTION_PENDING,
            'pending-cancel' => Status::SUBSCRIPTION_CANCELED,
            'cancelled'      => Status::SUBSCRIPTION_CANCELED,
            'expired'        => Status::SUBSCRIPTION_EXPIRED,
        ];

        $status = $maps[$wcStatus] ?? Status::SUBSCRIPTION_PENDING;

        // Mirror EDD: an otherwise-active subscription whose end date has passed
        // is really expired (WC may leave it 'active' until a cron run).
        if ($status === Status::SUBSCRIPTION_ACTIVE && $endDate && strtotime($endDate) && strtotime($endDate) < time()) {
            return Status::SUBSCRIPTION_EXPIRED;
        }

        return $status;
    }

    /**
     * Map a WooCommerce Subscriptions billing period (+ interval multiplier) to a
     * FluentCart repeat_interval slug. FluentCart supports daily/weekly/monthly/
     * quarterly/half_yearly/yearly, so common month multiples collapse to the
     * named slug; anything else falls back to the base period.
     */
    public static function repeatInterval($period, $interval = 1)
    {
        $period   = (string) $period;
        $interval = max(1, (int) $interval);

        if ($period === 'month') {
            if ($interval === 3) {
                return 'quarterly';
            }
            if ($interval === 6) {
                return 'half_yearly';
            }
        }

        $maps = [
            'day'   => 'daily',
            'week'  => 'weekly',
            'month' => 'monthly',
            'year'  => 'yearly',
        ];

        return $maps[$period] ?? 'monthly';
    }

    /**
     * Map a WooCommerce payment gateway id to the FluentCart payment-method slug.
     * Unknown gateways pass through unchanged; empty falls back to offline.
     */
    public static function gatewaySlug($wcGateway)
    {
        $wcGateway = (string) $wcGateway;
        if ($wcGateway === '') {
            return 'offline_payment';
        }

        if (strpos($wcGateway, 'stripe') === 0) {
            return 'stripe';
        }
        if (strpos($wcGateway, 'ppcp') === 0 || strpos($wcGateway, 'paypal') === 0) {
            return 'paypal';
        }

        $maps = [
            'cod'       => 'offline_payment',
            'cheque'    => 'offline_payment',
            'bacs'      => 'offline_payment',
            'paystack'  => 'paystack',
            'airwallex' => 'airwallex',
        ];

        return $maps[$wcGateway] ?? $wcGateway;
    }

    /**
     * Resolve the FluentCart order mode (test|live) for a WooCommerce order.
     *
     * WooCommerce keeps no per-order mode — Stripe and PayPal write their
     * vendor ids onto the order without recording which account minted them —
     * so the source gateway's own configuration is the only evidence available
     * at migration time. That makes this a best-effort read of the CURRENT
     * gateway settings: an order taken before the merchant last flipped
     * test/live is mapped by today's setting, not the one in force back then.
     * Woo does not retain the older value, so no reading of it can do better.
     *
     * Getting it wrong is not cosmetic. FluentCart selects its API keys by
     * order mode, so a test-mode order imported as live cannot be charged at
     * all — the renewal dies with "no Stripe live secret key is configured for
     * this store", and the saved card is unreachable.
     *
     * Gateways with no test/live concept (offline, cheque, BACS) and any
     * gateway we don't recognise fall back to the store's own order mode.
     *
     * @param \WC_Order $order
     * @return string One of Status::ORDER_MODE_TEST | Status::ORDER_MODE_LIVE
     */
    public static function orderMode($order)
    {
        $gateway = (string) $order->get_payment_method();
        $mode    = null;

        if (strpos($gateway, 'stripe') === 0) {
            $mode = self::modeFromFlag('woocommerce_stripe_settings', 'testmode');
        } elseif (strpos($gateway, 'ppcp') === 0) {
            // The newer PayPal Payments plugin stores a boolean, not 'yes'/'no'.
            $mode = self::modeFromFlag('woocommerce-ppcp-settings', 'sandbox_on');
        } elseif (strpos($gateway, 'paypal') === 0) {
            $mode = self::modeFromFlag('woocommerce_paypal_settings', 'testmode');
        }

        if ($mode === null) {
            $mode = (new StoreSettings())->get('order_mode') === Status::ORDER_MODE_TEST
                ? Status::ORDER_MODE_TEST
                : Status::ORDER_MODE_LIVE;
        }

        return apply_filters('fluentcart_migrator_woo_order_mode', $mode, [
            'order'   => $order,
            'gateway' => $gateway,
        ]);
    }

    /**
     * Read a sandbox/test flag out of a WooCommerce gateway settings option.
     * Returns null when the option or key is missing, so the caller can fall
     * back rather than assume live for a gateway that was never configured.
     *
     * @return string|null
     */
    private static function modeFromFlag($option, $key)
    {
        $settings = get_option($option, null);

        if (!is_array($settings) || !array_key_exists($key, $settings)) {
            return null;
        }

        $flag = $settings[$key];

        // 'yes' (classic Stripe/PayPal) or true/1 (PayPal Payments) — both sandbox.
        $isTest = $flag === 'yes' || $flag === true || $flag === 1 || $flag === '1';

        return $isTest ? Status::ORDER_MODE_TEST : Status::ORDER_MODE_LIVE;
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
}
