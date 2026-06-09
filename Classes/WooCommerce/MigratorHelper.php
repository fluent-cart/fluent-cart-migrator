<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCart\App\Helpers\CurrenciesHelper;

/**
 * Shared helpers for the WooCommerce source: money conversion, date formatting,
 * id-mapping meta, status maps, and SKU de-duplication. Reused across the
 * product, order, customer, coupon and tax migrators.
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
     * Ensure a SKU is unique across fct_product_variations (FC enforces a UNIQUE
     * index). Returns null for empty SKUs (MySQL UNIQUE allows multiple NULLs).
     */
    public static function uniqueSku($sku)
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }

        static $used = [];

        $candidate = $sku;
        $i         = 1;

        while (isset($used[$candidate]) || self::skuExists($candidate)) {
            $candidate = $sku . '-wc-' . $i;
            $i++;
        }

        $used[$candidate] = true;

        return $candidate;
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
}
