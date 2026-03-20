<?php defined('ABSPATH') or die;

/*
Plugin Name: FluentCart Migrator
Description: Migrate your data to FluentCart from other platforms.
Version: 1.0.0
Author: FluentCart Team
Author URI: https://fluentcart.com
Plugin URI: https://github.com/fluent-cart/fluent-cart-migrator
License: GPLv2 or later
Text Domain: fluent-cart-migrator
*/

define('FLUENTCART_MIGRATOR_VERSION', '1.0.0');
define('FLUENTCART_MIGRATOR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FLUENTCART_MIGRATOR_URL', plugin_dir_url(__FILE__));

class FluentCartMigrator
{
    public function init()
    {
        if (defined('WP_CLI') && WP_CLI) {
            require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/Commands.php';
            \WP_CLI::add_command('fluent_cart_migrator', '\FluentCartMigrator\Classes\Commands');
        }

        $this->handleEddLegacyLicenses();
    }

    private function handleEddLegacyLicenses()
    {
        // let's handle the previous EDD Licensinge EndPoints
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/EDD3/EddLicenseHandler.php';
        $eddLicenseHandler = new \FluentCartMigrator\Classes\EDD3\EddLicenseHandler();
        $eddLicenseHandler->register();

        // Let's resolve the stripe's previous IPN Data which has charge id instead of payment intent id
        add_filter('fluent_cart/stripe/fallback_order_transaction', function ($transaction, $vendorChargeData) {
            $chargeId = $vendorChargeData->id ?? null;

            if (!$chargeId) {
                return $transaction;
            }

            $orderTransaction = \FluentCart\App\Models\OrderTransaction::query()->where('vendor_charge_id', $chargeId)
                ->where('transaction_type', 'charge')
                ->first();

            if ($orderTransaction) {
                $paymentIntent = $vendorChargeData->payment_intent;
                $orderTransaction->vendor_charge_id = $paymentIntent;
                $orderTransaction->save();
                return $orderTransaction;
            }

            return $transaction;
        }, 10, 2);
    }
}

add_action('plugins_loaded', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    (new FluentCartMigrator())->init();
});
