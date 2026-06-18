<?php

namespace FluentCartMigrator\Classes\Support;

/**
 * SKU de-duplication for fct_product_variations.
 *
 * The sku column is VARCHAR(30) with a UNIQUE index, and MySQL here runs in a
 * non-strict sql_mode (over-long values are silently truncated on insert).
 * Uniqueness must therefore be enforced on the *stored* (truncated) value, or
 * two long SKUs sharing a 30-char prefix collide. When a suffix is needed the
 * base is trimmed so the suffix survives the cap. Empty SKUs return null
 * (MySQL UNIQUE allows multiple NULLs).
 */
class Sku
{
    const MAX_LENGTH = 30;

    /**
     * @return string|null
     */
    public static function unique($sku)
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }

        static $used = [];

        $max       = self::MAX_LENGTH;
        $candidate = self::truncate($sku, $max);
        $i         = 1;

        while (isset($used[$candidate]) || self::exists($candidate)) {
            $suffix    = '-wc-' . $i;
            $candidate = self::truncate($sku, $max - strlen($suffix)) . $suffix;
            $i++;
        }

        $used[$candidate] = true;

        return $candidate;
    }

    /**
     * Reset the in-process claim cache (call between migration runs/tests).
     */
    public static function reset()
    {
        // No-op placeholder kept for symmetry; the static $used in unique() is
        // process-scoped and intentionally persists across a single run.
    }

    protected static function truncate($sku, $length)
    {
        if ($length <= 0) {
            return '';
        }

        return function_exists('mb_substr')
            ? mb_substr($sku, 0, $length)
            : substr($sku, 0, $length);
    }

    protected static function exists($sku)
    {
        if (!function_exists('fluentCart')) {
            return false;
        }

        return (int) fluentCart('db')->table('fct_product_variations')
            ->where('sku', $sku)
            ->count() > 0;
    }
}
