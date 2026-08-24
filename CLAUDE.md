# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Plugin Does

FluentCart Migrator is a WordPress plugin that migrates eCommerce data from Easy Digital Downloads (EDD) 3.x to FluentCart. It migrates products, orders, subscriptions, license keys, coupons, customers, and tax rates. It also provides backward compatibility for legacy EDD endpoints (licensing API, PayPal IPN, Stripe webhooks, download URLs) so existing customer integrations continue working after migration.

## Build Commands

```bash
npm run dev    # Vite watch mode (outputs to assets/build/)
npm run build  # Production build
npm run zip    # Pack builds/fluent-cart-migrator.zip from the whitelist in dev/zip.sh (needs a prior build)
npm run build:zip  # Build + zip
```

Build entry point: `assets/js/migrator-app.js` (Vue 3 app). Output: `assets/build/migrator-app.{js,css}`.

There are no PHP tests, linting, or composer autoloading — classes are loaded via `require_once`.

## Translations (i18n)

Text domain: `fluent-cart-migrator`, loaded from `languages/` (`Domain Path: /languages`, `load_plugin_textdomain` on `init`).

- **PHP**: wrap strings with `__()` / `esc_html__()` etc. and the `fluent-cart-migrator` domain.
- **Vue**: import `__`, `_n`, `sprintf` from `assets/js/i18n.js` (they are also available in every template as globals). They delegate to `wp.i18n` at runtime — the bundle depends on `wp-i18n` and translations are registered via `wp_set_script_translations()` in `AdminMenu`. Keep those three function names unchanged: Vite is configured with `esbuild.minifyIdentifiers: false` so `wp i18n make-pot` can extract the calls from the built bundle (`.vue` files are not scanned directly).
- `npm run pot` regenerates `languages/fluent-cart-migrator.pot` (run after `npm run build`; `npm run i18n` does both). It uses `--ignore-domain` because the bundled JS calls carry no domain argument.
- Translators ship `languages/fluent-cart-migrator-{locale}.po/.mo` plus the JS JSON (`wp i18n make-json languages/` or Loco Translate); the JSON filename hash is `md5('assets/build/migrator-app.js')`.

## WP-CLI Commands

```bash
wp fluent_cart_migrator migrate_from_edd --stats         # View migration statistics
wp fluent_cart_migrator migrate_from_edd --all            # Run full migration
wp fluent_cart_migrator migrate_from_edd --products       # Migrate only products
wp fluent_cart_migrator migrate_from_edd --taxonomies     # Apply the taxonomy mapping
wp fluent_cart_migrator migrate_from_edd --taxonomy_map   # Show/set the taxonomy mapping (--set=src:dest,...)
wp fluent_cart_migrator migrate_from_edd --payments       # Migrate only orders
wp fluent_cart_migrator migrate_from_edd --verify_license # Compare EDD vs FluentCart licenses
wp fluent_cart_migrator migrate_from_edd --reset          # Reset (requires FLUENT_CART_DEV_MODE)
wp fluent_cart_migrator migrate_from_edd --log            # View failed payment logs
```

## Architecture

### Bootstrap Flow

`fluent-cart-migrator.php` hooks into `plugins_loaded` and only initializes if FluentCart (`FLUENTCART_VERSION`) is active. It loads:
- **WP-CLI commands** (`Classes/Commands.php`) — only in CLI context
- **AdminMenu** (`Classes/Admin/AdminMenu.php`) — only in admin context
- **RestApi** (`Classes/Admin/RestApi.php`) — unconditionally (REST requests don't pass `is_admin()`)
- **EddLicenseHandler** (`Classes/EDD3/EddLicenseHandler.php`) — always, for backward compat

### Key Classes

- **`MigratorService`** — Facade over EDD3 migration logic. Both REST API and WP-CLI delegate to this. Manages migration state via WordPress options.
- **`MigratorCli`** (`Classes/EDD3/`) — EDD3 data extraction and transformation. Handles products, payments (paginated), tax rates, coupons.
- **`PaymentMigrate`** (`Classes/EDD3/`) — Assembles complete order data (items, transactions, refunds, subscriptions, licenses, taxes, coupons) and inserts into FluentCart. ~1650 lines. `setupData()` assembles, `validate()` checks, `migrate()` inserts.
- **`MigratorHelper`** (`Classes/EDD3/`) — Static utilities for status mapping, data transformation, customer lookup, and batch caching. ~1200 lines.
- **`EddLicenseHandler`** — Intercepts legacy EDD API requests (`edd_action`, `edd-listener=IPN`, `edd-sl/package_download`) and routes them to FluentCart when EDD is deactivated.
- **`TaxonomyMap`** (`Classes/Support/`) — Discovery + persistence for the taxonomy mapping (see below).
- **`TaxonomyResolver`** (`Classes/Support/`) — Run-time side of the mapping: syncs each mapped source term tree into its destination taxonomy and resolves a source product's terms into FluentCart term ids.
- **`TaxonomyApplier`** (`Classes/Support/`) — The `taxonomies` migration step: source-agnostic, resumable pass that assigns mapped terms to already-migrated products.
- **`TaxonomyWriter`** (`Classes/Load/`) — Creates/reuses terms in any destination taxonomy, parents-first. `CategoryWriter` is a thin shim over it for the built-in `product-categories`.

### REST API

Namespace: `fct-migrator/v1`. All routes require `manage_options`. Key endpoints:
- `GET /sources` — Available migration sources (EDD, WooCommerce coming soon)
- `GET /stats/{source}` — Migration statistics
- `GET /taxonomies` — Taxonomy mapper payload (destinations, sources, effective map)
- `POST /taxonomies/map` — Save the taxonomy mapping for a source
- `POST /migrate/{products|taxonomies|tax-rates|coupons|payments}` — Run migration steps
- `POST /migrate/recount` — Post-migration fixes (substeps: `fix_reactivations`, `fix_subs_uuid`, `coupons`, `customers`, `subscriptions`)
- `POST /reset` — Wipe migrated data (dev mode only)

### Frontend

Vue 3 single-file app (`assets/js/MigratorApp.vue`) mounted to `#fct-migrator-app`. Multi-step wizard: source selection -> version check -> statistics -> migration (batched) -> recount/verification -> complete.

### Taxonomy Mapping

FluentCart reads product taxonomies dynamically (anything registered on the `fluent-products` CPT — it ships `product-categories` + `product-brands`), so the migrator does not hardcode categories. The admin pairs each source taxonomy with a FluentCart one in the **Taxonomy Mapping** card on the Overview screen (`assets/js/components/TaxonomyMapper.vue`); pairs are stored per source in the option `__fluent_cart_migrator_taxonomy_map_{source}`.

- A row with either side empty is ignored — that is how "do not migrate this taxonomy" is expressed. A saved empty map migrates no taxonomies; a *never saved* map falls back to `TaxonomyMap::defaults()` (product_cat/download_category → product-categories, common brand taxonomies → product-brands, tags → `product-tags` when something registered it).
- **`taxonomies` is a normal migration step** (`TaxonomyApplier::step()`), with the same contract as products/payments: skipped once `state['taxonomies'] === 'yes'`, resumable via `last_taxonomy_page`, time/memory-boxed, exposed as a checkbox in the wizard, a row in the runner, and `--taxonomies` on the CLI (part of `--all`). It walks the source products, follows the id-mapping postmeta and assigns the mapped terms.
- The products step already assigns terms to every product it writes, so a *completed* products run marks the taxonomies step done — no redundant second pass. Saving a **changed** mapping calls `TaxonomyMap::reopenStep()`, which clears that flag so the next run re-applies it. That is how a mapping edited after the migration reaches the catalog.
- Both sides are discovered from the taxonomy registry (`get_object_taxonomies()` on `download`/`product` and on `fluent-products`) — the source plugin must be active to migrate, so its taxonomies are registered.
- WC attribute taxonomies (`pa_*`) and source internals (`product_type`, `product_visibility`, `product_shipping_class`, `edd_log_type`, …) are never offered — attributes migrate into FluentCart's attribute library (`fct_atts_*`) instead.
- Both sources run through `TaxonomyResolver`: WooCommerce fills `ProductData->taxonomies` (written by `ProductWriter`), EDD's `MigratorCli` calls `assignToProduct()` directly. WC coupon category restrictions resolve through the same map.
- `wp fluent_cart_migrator migrate_from_{edd,woo} --taxonomy_map [--set=src:dest,...|--set=none]` shows/sets the mapping; `--taxonomies` runs the step.

### Migration State

Tracked in WP option `__fluent_cart_edd3_migration_steps` with keys: `products`, `taxonomies`, `tax_rates`, `coupons`, `payments`, `last_order_page`, `last_taxonomy_page`. Each step is marked `'yes'` on completion. Payment migration is resumable via `last_order_page`. Failed orders logged to `_fluent_edd_failed_payment_logs`.

### Data Flow Pattern

1. Each migration step checks if already completed (skips if `'yes'`)
2. `MigratorCli` queries EDD tables directly via `$wpdb` or EDD's custom tables
3. Data is transformed to FluentCart format (cents-based amounts, status enums, etc.)
4. `PaymentMigrate` validates before inserting
5. State is updated in options; failures are logged
6. Static caches in `MigratorHelper` are reset between payment batches

### Important Conventions

- Amounts are stored in cents in FluentCart. The filter `fluentcart_migrator_edd3_to_cents` allows currency conversion during migration.
- EDD product IDs are stored as `_edd_migrated_from` postmeta on FluentCart products, and `_fcart_migrated_id` on EDD downloads, creating a bidirectional mapping.
- Variation mappings stored in `__edd_migrated_variation_maps` postmeta.
- Migrated terms are matched to existing FluentCart terms **by slug** and are never modified; term assignment replaces (does not append) a product's terms in each mapped taxonomy.
- The Stripe webhook handler auto-corrects EDD's Charge ID to Payment Intent ID format in the `fluent_cart/stripe/fallback_order_transaction` filter.
