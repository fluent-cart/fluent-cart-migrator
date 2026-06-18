<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCart\Api\StoreSettings;

/**
 * Migrates WooCommerce store configuration into FluentCart store settings.
 *
 * Non-destructive: only fills FluentCart keys that are currently empty, so it
 * never overwrites settings the store owner has already configured. Mirrors the
 * intent of the EDD source's store-settings migration, but reads WooCommerce
 * options and uses only FluentCart core (no EDD code is touched).
 */
class StoreSettingsMigrator
{
    public function migrate()
    {
        if (!function_exists('get_woocommerce_currency')) {
            return;
        }

        $existing = get_option('fluent_cart_store_settings', []);
        if (!is_array($existing)) {
            $existing = [];
        }

        // country option is "US" or "US:CA" (country:state)
        $country = (string) get_option('woocommerce_default_country', '');
        $state   = '';
        if (strpos($country, ':') !== false) {
            list($country, $state) = array_pad(explode(':', $country, 2), 2, '');
        }

        $candidates = [
            'store_name'        => get_option('blogname', ''),
            'store_address1'    => get_option('woocommerce_store_address', ''),
            'store_address2'    => get_option('woocommerce_store_address_2', ''),
            'store_city'        => get_option('woocommerce_store_city', ''),
            'store_postcode'    => get_option('woocommerce_store_postcode', ''),
            'store_country'     => $country,
            'store_state'       => $state,
            'currency'          => get_woocommerce_currency(),
            'currency_position' => $this->currencyPosition(get_option('woocommerce_currency_pos', '')),
            'decimal_separator' => get_option('woocommerce_price_decimal_sep', '.') === ',' ? 'comma' : 'dot',
        ];

        $toUpdate = [];
        foreach ($candidates as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (empty($existing[$key])) {
                $toUpdate[$key] = $value;
            }
        }

        if ($toUpdate) {
            (new StoreSettings())->save($toUpdate);
        }
    }

    /**
     * WooCommerce uses left/right(+_space); FluentCart uses before/after.
     */
    private function currencyPosition($wcPos)
    {
        if ($wcPos === '') {
            return '';
        }

        return in_array($wcPos, ['right', 'right_space'], true) ? 'after' : 'before';
    }
}
