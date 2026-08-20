=== FluentCart Migrator ===
Contributors: wpmanageninja, techjewel
Tags: ecommerce, migration, easy-digital-downloads, edd, fluentcart
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate your data to FluentCart from other eCommerce platforms while preserving products, orders, subscriptions, licenses, coupons, customers, and tax rates.

== Description ==

FluentCart Migrator moves your existing WordPress eCommerce store to [FluentCart](https://fluentcart.com) with a single WP-CLI command. It preserves your products, orders, subscriptions, license keys, coupons, customers, and tax rates, and keeps your existing integrations working through built-in backward compatibility layers.

The migration is resumable, batch-aware, and safe to re-run. If a migration is interrupted (server restart, SSH disconnect, HTTP timeout), simply run the same command again — completed steps are skipped and order processing resumes from the last batch.

= Currently Supported Sources =

**Easy Digital Downloads (EDD 3.x)**

* Products — simple, variable pricing, bundles, subscriptions, download files, and featured images
* Orders — all statuses, line items, transactions, refunds, billing addresses, and activity notes
* Subscriptions — including live recurring payments via Stripe and PayPal
* License Keys — keys, activations, limits, and expiration (existing endpoints keep working)
* Coupons — discount rules, usage limits, product restrictions, and recurring settings
* Customers — profiles, WP user links, and addresses
* Tax Rates — country/state rates with inclusive/exclusive behavior
* Store Settings — store name, address, and currency (only fills unconfigured fields)
* Original order IDs and receipt numbers are preserved

**Coming Soon**

* WooCommerce
* SureCart

= Backward Compatibility =

After migrating from EDD, keep this plugin active to maintain seamless compatibility for your existing integrations:

* **EDD Licensing API** — Intercepts `activate_license`, `deactivate_license`, `check_license`, and `get_version` requests and routes them through FluentCart, so your customers' software keeps working unchanged.
* **PayPal Standard IPN** — Handles `edd-listener=IPN` for active PayPal subscription renewals.
* **Stripe Webhooks** — Auto-corrects EDD's Charge ID references to Payment Intent IDs on the first webhook.
* **Legacy Renewal URLs** — Redirects legacy EDD license renewal URLs to FluentCart's renewal page.
* **Package Downloads** — Routes EDD Software Licensing package download requests through FluentCart's download system.

== Installation ==

**Option 1 — Upload ZIP**

1. Download the plugin ZIP.
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP and click **Activate**.

**Option 2 — Git clone**

`cd wp-content/plugins/`
`git clone https://github.com/fluent-cart/fluent-cart-migrator.git`
`wp plugin activate fluent-cart-migrator`

= Requirements =

* WordPress 5.9 or higher
* Easy Digital Downloads 3.x installed and active
* FluentCart installed and activated
* FluentCart Pro (required only when migrating EDD Software Licensing data)
* WP-CLI installed on your server

== Quick Start ==

Migrate everything in one command:

`wp fluent_cart_migrator migrate_from_edd --all`

This runs all steps in order: store settings, products, tax rates, coupons, orders/payments (with subscriptions, licenses, and refunds), and a final statistics recount.

= Step-by-Step Commands =

`wp fluent_cart_migrator migrate_from_edd --products`   — Migrate products (variations, bundles, downloads)
`wp fluent_cart_migrator migrate_from_edd --tax_rates`  — Migrate tax rates
`wp fluent_cart_migrator migrate_from_edd --coupons`    — Migrate coupon codes
`wp fluent_cart_migrator migrate_from_edd --payments`   — Migrate orders, subscriptions, licenses, customers
`wp fluent_cart_migrator migrate_from_edd --recount`    — Recount customer stats, subscription bills, coupon usage

= Utility Commands =

`wp fluent_cart_migrator migrate_from_edd --stats`           — View EDD data statistics before migration
`wp fluent_cart_migrator migrate_from_edd --log`             — View failed payment logs
`wp fluent_cart_migrator migrate_from_edd --verify_license`  — Verify migrated licenses against EDD records
`wp fluent_cart_migrator migrate_from_edd --reset`           — Reset migration and start over
`wp fluent_cart_migrator edd_cleanup`                        — Remove legacy EDD post types and orphaned data (optional, post-migration)

== Recommended Migration Process ==

1. **Back up your database** before doing anything.
2. **Install FluentCart** and configure payment gateways (use the same Stripe/PayPal accounts as EDD).
3. **Install and activate this plugin.**
4. **Run the migration:** `wp fluent_cart_migrator migrate_from_edd --all`
5. **Verify** products, orders, subscriptions, licenses, and customers in FluentCart.
6. **Update your site** — replace EDD shortcodes with FluentCart checkout buttons/blocks.
7. **Deactivate EDD** but keep this migrator plugin active for backward compatibility.
8. **Test thoroughly** — verify checkout, subscription renewals, and license activations.

== Frequently Asked Questions ==

= Is the migration safe to re-run? =

Yes. The migrator tracks progress per step. If interrupted, re-run the same command and it will skip completed steps and resume orders from the last processed batch.

= Should I keep this plugin active after migrating? =

Yes, if you use the Licensing module or have active PayPal Standard subscriptions. It provides backward compatibility so existing license activations and renewals keep working without changes on your customers' end.

= What is not migrated? =

Draft, trashed, and abandoned orders are not migrated. Some License configuration values (version numbers, changelog, etc.) may need to be set manually in FluentCart Products after migration.

= Do I need FluentCart Pro? =

FluentCart Pro is only required if you are migrating EDD Software Licensing data.

= Can I convert currencies during migration? =

Yes. Use the `fluentcart_migrator_edd3_to_cents` filter to apply a conversion rate per currency. For example, to convert GBP to USD:

`add_filter('fluentcart_migrator_edd3_to_cents', function ($cents, $number, $currency) {`
`    if ($currency === 'GBP') {`
`        $cents = $cents * 1.32;`
`    }`
`    return $cents;`
`}, 10, 3);`

= I run a large store. Can I get help? =

Yes. Reach out to the FluentCart support team for assistance with large migrations. For issues or feature requests, open an issue on the GitHub repository.

== Changelog ==
= 1.0.3-beta (Aug 20, 2026) =
- Adds Woocommerce migrator for order, product, customer, subscriptions and renewal managements

= 1.0.2 (Jun 24, 2026) =
- Adds Missing customers migration step
- Adds Fallback for classic Paddle subscriptions, shared EddSubscriptionMeta helper, and guard for missing edd_subscriptionmeta table
- Fixes Duplicate transactions in EDD migration
- Fixes Store real Paddle Billing sub ID + backfill for migrated subscriptions; resolve Paddle Classic sub IDs 
- Fixes Allow orders with deleted products to migrate issue
- Fixes Exclude inactive EDD customers; fix collation mismatch & missing-customer detection
- Fixes EDD Recurring plugin dependency from Paddle backfill; 
- Improve customer name extraction during migration
