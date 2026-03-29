# FluentCart Migrator

Migrate your WordPress eCommerce store to [FluentCart](https://fluentcart.com) with a single WP-CLI command — preserving products, orders, subscriptions, licenses, coupons, customers, and tax rates.

**Currently Supported Migrations:**

- [x] Easy Digital Downloads (EDD 3.x):
    - [x] Products (simple, variable pricing, bundles, subscriptions, download files, featured images)
    - [x] Orders (all statuses, line items, transactions, refunds, billing addresses, activity notes)
    - [x] Subscriptions (including live recurring payments via Stripe & PayPal)
    - [x] License Keys (keys, activations, limits, expiration — existing endpoints keep working)
    - [x] Coupons (discount rules, usage limits, product restrictions, recurring settings)
    - [x] Customers (profiles, WP user links, addresses)
    - [x] Tax Rates (country/state rates, inclusive/exclusive behavior)
    - [x] Store Settings (store name, address, currency — only fills unconfigured fields)
    - [x] Order IDs and receipt numbers are preserved

- [ ] WooCommerce (Upcoming)
- [ ] SureCart (Upcoming)

## Requirements

- WordPress 5.9+
- [Easy Digital Downloads 3.x](https://easydigitaldownloads.com/) installed and active
- [FluentCart](https://fluentcart.com) installed and activated
- [FluentCart Pro](https://fluentcart.com) (required only if migrating EDD Software Licensing data)
- [WP-CLI](https://wp-cli.org/) installed on your server

## Installation

**Option 1 — Download ZIP:**

1. Download this repository as a ZIP from GitHub
2. Go to **Plugins > Add New > Upload Plugin** in WordPress admin
3. Upload the ZIP and activate

**Option 2 — Git clone:**

```bash
cd wp-content/plugins/
git clone https://github.com/fluent-cart/fluent-cart-migrator.git
wp plugin activate fluent-cart-migrator
```

## Quick Start

Migrate everything in one command:

```bash
wp fluent_cart_migrator migrate_from_edd --all
```

This runs all steps in order: store settings, products, tax rates, coupons, orders/payments (with subscriptions, licenses, and refunds), and statistics recount.

## Commands

### Full Migration

```bash
wp fluent_cart_migrator migrate_from_edd --all
```

### Step-by-Step

```bash
# Migrate products (with variations, bundles, downloads)
wp fluent_cart_migrator migrate_from_edd --products

# Migrate tax rates
wp fluent_cart_migrator migrate_from_edd --tax_rates

# Migrate coupon codes
wp fluent_cart_migrator migrate_from_edd --coupons

# Migrate orders, subscriptions, licenses, customers
wp fluent_cart_migrator migrate_from_edd --payments

# Recount customer stats, subscription bills, coupon usage
wp fluent_cart_migrator migrate_from_edd --recount
```

### Utilities

```bash
# View EDD data statistics before migration
wp fluent_cart_migrator migrate_from_edd --stats

# View failed payment logs
wp fluent_cart_migrator migrate_from_edd --log

# Verify migrated licenses against EDD records
wp fluent_cart_migrator migrate_from_edd --verify_license

# Reset migration and start over
wp fluent_cart_migrator migrate_from_edd --reset
```

### Post-Migration EDD Cleanup (optional)

Remove legacy EDD post types and orphaned data after verifying your migration:

```bash
wp fluent_cart_migrator edd_cleanup
```

## Resume Support

The migration tracks progress. If interrupted (server restart, SSH disconnect), re-run the same command — it skips completed steps and resumes orders from the last processed batch.

## Backward Compatibility

After migration, **keep this plugin active**. It provides seamless backward compatibility for your existing integrations:

- **EDD Licensing API** — Intercepts `?edd_action=activate_license|deactivate_license|check_license|get_version` and routes them through FluentCart. Your customers' software continues working without any changes on their end.
- **PayPal Standard IPN** — Handles `?edd-listener=IPN` for active PayPal subscription renewals.
- **Stripe Webhooks** — Auto-corrects EDD's Charge ID references to Payment Intent IDs on first webhook.
- **Legacy Renewal URLs** — Redirects `?edd_license_key=...&download_id=...` to FluentCart's renewal page.
- **Package Downloads** — Routes `edd-sl/package_download` requests through FluentCart's download system.

## Migration Process

1. **Backup your database** — always back up before migration
2. **Install FluentCart** and configure payment gateways (use the same Stripe/PayPal accounts as EDD)
3. **Install this plugin** and activate it
4. **Run the migration**: `wp fluent_cart_migrator migrate_from_edd --all`
5. **Verify** products, orders, subscriptions, licenses, and customers in FluentCart
6. **Update your site** — replace EDD shortcodes with FluentCart checkout buttons/blocks
7. **Deactivate EDD** — keep this migrator plugin active for backward compatibility
8. **Test thoroughly** — verify checkout, subscription renewals, and license activations

See [MIGRATION-GUIDE.md](MIGRATION-GUIDE.md) for the complete step-by-step tutorial with a detailed verification checklist, FAQ, and troubleshooting guide.

### Additional Notes (Licensing Module)

- After migration, you may need to manually configure License settings in FluentCart Products (version numbers, changelog, etc.). Only these configuration values are not migrated automatically.
- Your existing software license keys and endpoints will continue to work as before without any changes.
- We recommend migrating to the [FluentCart Licensing SDK](https://github.com/fluent-cart/software-licensing-sdk) in your software for a better experience.
- Keep this plugin activated if you use the Licensing module.

### Additional Notes

- FluentCart does not migrate draft, trashed, or abandoned orders.
- Please do a test run on a staging site before running on production.
- Keep this plugin activated if you have active PayPal Standard subscriptions.

### Edge Cases

If you are using multi-currency in EDD and need to convert currencies during migration, use this filter:

**Example: Convert GBP to USD with a conversion rate:**

```php
add_filter('fluentcart_migrator_edd3_to_cents', function ($cents, $number, $currency) {
    if ($currency === 'GBP') {
        $cents = $cents * 1.32;
    }
    return $cents;
}, 10, 3);
```

## Support

- If you run a large store and need assistance with the migration, feel free to reach out to our support team. Our founder Shahjahan Jewel will help you personally with the migration.
- For any issues or feature requests, please open an issue on this repository on GitHub.

## Contribution

Contributions to enhance the migration process are welcome. Feel free to fork the repository and submit pull requests with improvements or additional migration features.

## License

GPLv2 or later
