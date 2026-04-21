# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Plugin Does

FluentCart Migrator is a WordPress plugin that migrates eCommerce data from Easy Digital Downloads (EDD) 3.x to FluentCart. It migrates products, orders, subscriptions, license keys, coupons, customers, and tax rates. It also provides backward compatibility for legacy EDD endpoints (licensing API, PayPal IPN, Stripe webhooks, download URLs) so existing customer integrations continue working after migration.

## Build Commands

```bash
npm run dev    # Vite watch mode (outputs to assets/build/)
npm run build  # Production build
```

Build entry point: `assets/js/migrator-app.js` (Vue 3 app). Output: `assets/build/migrator-app.{js,css}`.

There are no PHP tests, linting, or composer autoloading — classes are loaded via `require_once`.

## WP-CLI Commands

```bash
wp fluent_cart_migrator migrate_from_edd --stats         # View migration statistics
wp fluent_cart_migrator migrate_from_edd --all            # Run full migration
wp fluent_cart_migrator migrate_from_edd --products       # Migrate only products
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

### REST API

Namespace: `fct-migrator/v1`. All routes require `manage_options`. Key endpoints:
- `GET /sources` — Available migration sources (EDD, WooCommerce coming soon)
- `GET /stats/{source}` — Migration statistics
- `POST /migrate/{products|tax-rates|coupons|payments}` — Run migration steps
- `POST /migrate/recount` — Post-migration fixes (substeps: `fix_reactivations`, `fix_subs_uuid`, `coupons`, `customers`, `subscriptions`)
- `POST /reset` — Wipe migrated data (dev mode only)

### Frontend

Vue 3 single-file app (`assets/js/MigratorApp.vue`) mounted to `#fct-migrator-app`. Multi-step wizard: source selection -> version check -> statistics -> migration (batched) -> recount/verification -> complete.

### Migration State

Tracked in WP option `__fluent_cart_edd3_migration_steps` with keys: `products`, `tax_rates`, `coupons`, `payments`, `last_order_page`. Each step is marked `'yes'` on completion. Payment migration is resumable via `last_order_page`. Failed orders logged to `_fluent_edd_failed_payment_logs`.

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
- The Stripe webhook handler auto-corrects EDD's Charge ID to Payment Intent ID format in the `fluent_cart/stripe/fallback_order_transaction` filter.
