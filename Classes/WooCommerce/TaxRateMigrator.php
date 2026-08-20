<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCart\App\Http\Controllers\TaxClassController;
use FluentCart\App\Services\Tax\TaxManager;

/**
 * WooCommerce tax-rate source.
 *
 * Mirrors the EDD source's approach (and reuses the same FluentCart **core**
 * services — no EDD code): enable FluentCart tax settings from WooCommerce,
 * ensure the default tax classes exist, generate FluentCart's built-in tax
 * rates for the countries WooCommerce taxes, and build a map from each
 * WooCommerce `tax_rate_id` to the resolved FluentCart `fct_tax_rates.id`.
 *
 * The map is stored in an option the order step consumes so a payments re-run
 * links `fct_order_tax_rate.tax_rate_id` (a NOT NULL column).
 */
class TaxRateMigrator
{
    const MAP_OPTION = '_woo_fct_tax_rate_maps';

    public function migrate()
    {
        if (!function_exists('wc_tax_enabled') || !wc_tax_enabled()) {
            return [
                'success'   => true,
                'skipped'   => true,
                'message'   => __('WooCommerce taxes are not enabled. Skipping tax rate migration.', 'fluent-cart-migrator'),
                'countries' => [],
                'mapped'    => 0,
            ];
        }

        $this->syncTaxSettings();

        // FluentCart core: ensure default classes (Standard/Reduced/Zero) exist.
        (new TaxClassController())->checkAndCreateInitialTaxClasses();

        global $wpdb;
        $wcRates = $wpdb->get_results(
            "SELECT tax_rate_id, tax_rate_country, tax_rate_state FROM {$wpdb->prefix}woocommerce_tax_rates"
        );

        $countries = [];
        foreach ((array) $wcRates as $r) {
            if ($r->tax_rate_country) {
                $countries[] = $r->tax_rate_country;
            }
        }
        $countries[] = $this->defaultCountry();
        $countries   = array_values(array_unique(array_filter($countries)));

        if ($countries) {
            TaxManager::getInstance()->generateTaxClasses($countries);
        }

        // Map WC tax_rate_id -> FC fct_tax_rates.id (country+state, then country-only).
        $map = [];
        foreach ((array) $wcRates as $r) {
            $country = $r->tax_rate_country ?: '';
            $state   = $r->tax_rate_state ?: '';
            if (!$country) {
                continue;
            }

            $fct = null;
            if ($state) {
                $fct = fluentCart('db')->table('fct_tax_rates')
                    ->where('country', $country)->where('state', $state)->first();
            }
            if (!$fct) {
                $fct = fluentCart('db')->table('fct_tax_rates')->where('country', $country)->first();
            }
            if ($fct) {
                $map[(int) $r->tax_rate_id] = (int) $fct->id;
            }
        }

        update_option(self::MAP_OPTION, $map, false);

        return [
            'success'   => true,
            'skipped'   => false,
            'countries' => $countries,
            'mapped'    => count($map),
        ];
    }

    /**
     * WC tax_rate_id => fct_tax_rates.id, consumed by the order step.
     */
    public static function rateMap()
    {
        $map = get_option(self::MAP_OPTION, []);
        return is_array($map) ? $map : [];
    }

    private function syncTaxSettings()
    {
        $s = get_option('fluent_cart_tax_configuration_settings', []);
        if (!is_array($s)) {
            $s = [];
        }

        $s['enable_tax']             = 'yes';
        $s['tax_inclusion']          = (function_exists('wc_prices_include_tax') && wc_prices_include_tax()) ? 'included' : 'excluded';
        $s['tax_calculation_basis']  = $s['tax_calculation_basis'] ?? 'billing';
        $s['tax_rounding']           = $s['tax_rounding'] ?? 'item';
        $s['price_suffix']           = $s['price_suffix'] ?? '';

        if (empty($s['eu_vat_settings'])) {
            $s['eu_vat_settings'] = [
                'require_vat_number'              => 'no',
                'local_reverse_charge'            => 'no',
                'vat_reverse_excluded_categories' => [],
                'method'                          => 'home',
                'home_country'                    => $this->defaultCountry(),
                'home_vat'                        => '',
                'oss_vat'                         => '',
            ];
        }

        update_option('fluent_cart_tax_configuration_settings', $s, true);
    }

    private function defaultCountry()
    {
        $default = (string) get_option('woocommerce_default_country', '');
        return strpos($default, ':') !== false ? explode(':', $default)[0] : $default;
    }
}
