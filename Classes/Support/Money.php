<?php

namespace FluentCartMigrator\Classes\Support;

use FluentCart\App\Helpers\CurrenciesHelper;

/**
 * Money conversion shared by every migration source.
 *
 * FluentCart stores all amounts in minor units (cents). Zero-decimal currencies
 * (JPY, KRW, ...) are already in their smallest unit and are not multiplied.
 */
class Money
{
    /**
     * Convert a decimal amount to FluentCart minor units (cents).
     *
     * @param mixed  $amount   Decimal amount (string or float).
     * @param string $currency ISO currency code; empty falls back to USD-style x100.
     * @param string $source   Migration source key (passed to the filter for context).
     */
    public static function toCents($amount, $currency = '', $source = 'default')
    {
        $float = floatval($amount);

        if (!$float) {
            return 0;
        }

        if ($currency && class_exists(CurrenciesHelper::class) && CurrenciesHelper::isZeroDecimal($currency)) {
            $cents = $float;
        } else {
            $cents = $float * 100;
        }

        $cents = apply_filters('fluentcart_migrator_to_cents', $cents, $amount, $currency, $source);

        return (int) round($cents, 0);
    }
}
