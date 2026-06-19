# WooCommerce → FluentCart Migrator

How the WooCommerce migration source works after the ETL refactor.

## 1. Architecture at a glance

The migrator is split into **Extract → Transform → Load**. Source classes read
a store and map records to a normalized, typed contract; a shared, source-agnostic
"Load" layer owns every FluentCart insert.

```
WooCommerce store ──Extract──▶ WooCommerce/ ──Transform──▶ Dto/ ──▶ Load/ ──▶ fct_* tables
 (wc_get_products /            (OrderMigrator,            (typed     (OrderWriter,
  wc_get_orders, HPOS-safe;     ProductMigrator,          value      ProductWriter,
  WC_Coupon, WC_Tax, ...)       CouponMigrator, ...)      objects)   CustomerWriter, ...)
```

| Layer | Namespace / dir | Responsibility |
|-------|-----------------|----------------|
| **Source** | `Classes/WooCommerce/` | Read WooCommerce, map to DTOs (Extract + Transform). The only place that knows WooCommerce. |
| **Contract** | `Classes/Dto/` | Typed value objects — the normalized shape handed to the writers. |
| **Load** | `Classes/Load/` | Writers that own all FluentCart inserts, idempotency, uuid generation. Source-agnostic. |
| **Support** | `Classes/Support/` | Cross-cutting helpers: money, sku, validation, cascade delete, memory. |
| **Orchestration** | `Classes/Contracts/AbstractSourceMigrator.php` | State machine, resumable paginated loop, failed-log, recount dispatch, summary. |

The same Load/Support/Dto layers serve every source. EDD still runs on its own
legacy path (`Classes/MigratorService.php` + `Classes/EDD3/`) behind the same
interface and is never modified by the WooCommerce source.

## 2. Entry points

- **Admin wizard** (Vue, `assets/js/`) → REST. Each request carries the selected
  source as a `?source=woocommerce` query param (set once via `setApiSource()`
  in `assets/js/api.js`), so `RestApi::resolveMigrator()` routes to the
  WooCommerce source instead of defaulting to EDD.
- **REST** namespace `fct-migrator/v1` (`Classes/Admin/RestApi.php`):
  `GET /sources`, `GET /stats/woocommerce`, `GET /status`, and `POST /migrate/{products|tax-rates|coupons|payments|missing-customers|recount}`, `POST /reset`.
- **WP-CLI** — `wp fluent_cart_migrator migrate_from_woo` (see §12).
- **Source registry** `Classes/SourceManager.php` maps `woocommerce` →
  `WooCommerce\WooSourceMigrator`.

## 3. Detection, compatibility, stats

`WooSourceMigrator::detect()` returns a `compatibility` verdict the wizard
renders directly (not active → blocked; version `< 3.0` → blocked; else pass).
`getStats()` counts products / orders / paid orders / customers / coupons /
subscriptions via the CRUD API (HPOS-safe) and lists gateways + order statuses.

## 4. Products  (`POST /migrate/products`)

`WooSourceMigrator::migrateProducts()` → `ProductMigrator` (Transform) →
`ProductWriter` (Load).

- **Store settings** are migrated here first, once, non-destructively via
  `StoreSettingsMigrator` (WC store address / country-state / currency /
  currency position / decimal separator → FluentCart `StoreSettings`; only fills
  empty keys).
- Categories: `CategoryWriter::sync()` maps `product_cat` → FluentCart
  `product-categories` **parents-first** (`wcTermId → fctTermId`).
- Each product → `Dto\ProductData` (+ `ProductVariationData[]`, each with
  `ProductDownloadData[]`). `ProductWriter` upserts the `fluent-products` CPT post,
  `fct_product_variations` (SKU via `Support\Sku::unique()`), `fct_product_details`,
  `fct_product_downloads`, and the bidirectional mapping postmeta.

Simple product → one variation (`variation_identifier = '0'`); variable product
→ one per child keyed by the WC variation id.

### Advanced variations (attributes & terms)

**Any product that carries attributes** is migrated as a FluentCart
**advanced-variations** product. `ProductMigrator::resolveAttributeGroups()`
(Transform) maps each WC attribute — global taxonomy (`pa_*`) and custom — onto
the shared FluentCart attribute library via `Load\AttributeWriter`:

- **Group** (`fct_atts_groups`) per attribute, deduped by slug across the whole
  store (taxonomy → `wc_attribute_label()` title + taxonomy slug; custom →
  attribute name). Reused across products; defaults to a "Text" type.
- **Term** (`fct_atts_terms`) per value, deduped by `group_id + slug` (taxonomy
  term name/slug; custom option). A variation referencing a value missing from
  the parent's configured options is resolved from the taxonomy (or created from
  the custom value) so no variation loses a dimension; a value that exists in
  neither is skipped.

Variations are built per product type:

- **Variable / variable-subscription** → one FluentCart variation per WC child,
  carrying the terms (in group order) that define it. Only variation-defining
  attributes participate.
- **Non-variable with attributes** (simple/subscription) → the cartesian product
  of **all** attribute terms, one variation per combination, each sharing the
  source price/stock. Capped at `ProductMigrator::MAX_COMBINATIONS` (500); above
  it the product stays a plain single-variation product. The first combination
  keeps the legacy `'0'` map key so pre-existing orders still resolve.

Each advanced variation matches FluentCart's canonical shape (see the editor's
`AdvancedVariationService`):

- `variation_identifier` = the `_`-joined term ids in group order.
- `other_info` = the full default variant baseline (`tax_class`, `payment_type`,
  `repeat_interval`, …) plus `variant` = the term ids.
- `variation_title` = the term titles joined by ` / ` (e.g. `Red / XS / Wool`).
- `fct_atts_relations` rows link the **variation id** (`object_id`) → term/group.
- The product detail is marked `variation_type = 'advanced_variations'` with
  `other_info.attribute_config = [{group_id, variants:[termIds]}]` (each group's
  configured term set, in WC attribute order).

The source→fct postmeta map stays keyed by the WC variation id (not the term-id
identifier), so order migration still resolves line-item variations. Re-runs are
idempotent: groups/terms are never deleted (re-resolved by slug); a product's
relations are cleared before its variations are re-created.

## 5. Tax rates  (`POST /migrate/tax-rates`)

`WooSourceMigrator::migrateTaxRates()` → `TaxRateMigrator`. Mirrors EDD but reads
WooCommerce and uses FluentCart **core** services (no EDD code):

- Syncs `fluent_cart_tax_configuration_settings` (`enable_tax`, `tax_inclusion`
  from `wc_prices_include_tax()`).
- Ensures tax classes via `TaxClassController::checkAndCreateInitialTaxClasses()`
  and generates rates for the taxed countries via `TaxManager::generateTaxClasses()`.
- Builds a **`WC tax_rate_id → fct_tax_rates.id` map** (country+state, country-only
  fallback) stored in option `_woo_fct_tax_rate_maps`.

The order step consumes that map: `OrderMigrator::buildTaxRates()` resolves it to
a valid `fct_order_tax_rate.tax_rate_id` (a NOT NULL column) and **skips unmapped
rates** rather than writing null. Run tax-rates before payments so orders link.

## 6. Coupons  (`POST /migrate/coupons`)

`WooSourceMigrator::migrateCoupons()` → `CouponMigrator` (Transform) →
`CouponWriter` (Load, upsert by unique `code`). Each `WC_Coupon` →
`Dto\CouponData`:

- type: `percent`/`percent_product` → `percentage`, `fixed_*` → `fixed`, pure
  free-shipping → `free_shipping`; amount in cents (fixed) or raw percent.
- `conditions`: usage limits, min/max purchase amount, product & category
  include/exclude (mapped to migrated FluentCart ids/terms).
- `individual_use` → `stackable = 'no'`; status `publish` → `active`.

Once coupons exist, the order step back-links `applied_coupons.coupon_id` via
`MigratorHelper::couponIdByCode()` on the next payments run.

## 7. Orders  (`POST /migrate/payments`)

`AbstractSourceMigrator::migratePayments()` (shared loop) →
`WooSourceMigrator::migrateOrdersPage()` → `OrderMigrator` (Transform) →
`OrderWriter` (Load).

Per-order transform (`OrderMigrator::migrateOrder`):
1. **Customer** — `CustomerData` from WC billing; `CustomerWriter::findOrCreate()`
   dedupes by email.
2. **Items** — each line → `OrderItemData` (cents, product/variation mapped,
   subscription line flagged). Unmapped products keep `post_id = 0`.
3. **Totals & reconciliation** — derived from items + order tax/shipping/fee;
   the gap to the real `WC_Order::get_total()` lands in `manual_discount_total`
   (or `fee_total` if negative), so `total = subtotal + tax + shipping + fee −
   coupon − manual` always holds.
4. **Status** — `MigratorHelper::orderStatus()/paymentStatus()` → FluentCart enums.
5. **Payment method** — `MigratorHelper::gatewaySlug()` normalizes the WC gateway
   id to FluentCart slugs (`stripe*`→`stripe`, `ppcp*`/`paypal*`→`paypal`,
   `cod`/`cheque`/`bacs`→`offline_payment`, …) on the order + transactions.
6. **Transactions** — one charge (= `total_paid`); refunds from
   `WC_Order::get_refunds()` become refund transactions, and **per-line refund
   amounts** are written to `fct_order_items.refund_total` (via
   `_refunded_item_id`, or distributed proportionally when not line-allocated).
7. **Addresses / coupons / taxes** — billing+shipping, `AppliedCouponData[]`
   (`coupon_id` resolved from migrated coupons), `TaxRateData[]` (rate id from §5 map).
8. **Activity** — WC order notes (`wc_get_order_notes`, system + customer) →
   `ActivityData[]` → `fct_activity` (module = Order).
9. **Subscriptions** (WooCommerce Subscriptions, if active) — parent orders build
   `SubscriptionData[]`; renewals link to the migrated parent subscription.
   Guarded by `function_exists`, so a no-op when the plugin is absent.

Persistence (`OrderWriter::write`), all raw inserts (see §10):
validate → cascade-delete if the id exists (idempotent) → `fct_orders` →
items (+ bundle children) → subscriptions → charge + refund transactions →
addresses → applied coupons → tax rates → operations → optional licenses /
activities.

On completion the source also **masks shared IPs** — clears `ip_address` on
WooCommerce-migrated orders whose IP appears on more than N orders (likely a
gateway/proxy IP). Scoped to `migrated_from = woocommerce`, so EDD data is untouched.

## 8. Customers without orders  (`POST /migrate/missing-customers`)

`WooSourceMigrator::migrateMissingCustomers()` paginates
`WP_User_Query(role=customer)` and creates `fct_customers` for users not already
present (deduped by email via `CustomerWriter`), using `WC_Customer` billing
details. Order-derived customers are skipped. (Opt-in wizard step.)

## 9. The resumable batch loop

`AbstractSourceMigrator::migratePayments($page, $perPage, $maxSeconds)`:
- skips if the `payments` step is done;
- per page: reset caches → `migrateOrdersPage()` → save `last_order_page` →
  `BatchRuntime::freeMemory()`;
- breaks on no-more-pages, on `$maxSeconds` (REST passes 25s), or on
  `BatchRuntime::memoryNearLimit()` (70% of `memory_limit`) — returning
  `has_more = true` so the next (fresh) request resumes.

REST uses `perPage = 50` and the front-end loops until `has_more` is false. The
memory guard auto-disables when `memory_limit` is unlimited (CLI), so a CLI run
completes in one process. (It exists because WC order objects retain memory that
survives cache flushing.)

## 10. Idempotency & key conventions

- **Order id reused**: `fct_orders.id = WooCommerce order id`; re-running cascade-
  deletes then re-inserts — no duplicates. Catalog steps upsert by natural key
  (coupon code; tax rate by region+class via the map).
- **Product mapping postmeta**: `_fct_migrated_id` (on WC product) → FC post id;
  `_wc_migrated_from` (on FC post) → WC product id;
  `__wc_migrated_variation_maps` (on FC post) → `wcVariationId → fctVariationId`.
- WooCommerce-migrated orders carry `config.migrated_from = "woocommerce"`.
- **Raw inserts, not models** — to reuse the source id, keep historical
  timestamps, set `uuid`/`receipt_number`/`invoice_no` explicitly, and bypass
  model `boot()` hooks (which auto-generate those and can fire emails/webhooks).
- **SKU** — `fct_product_variations.sku` is `VARCHAR(30)` UNIQUE and MySQL is
  non-strict (silent truncation); `Support\Sku::unique()` truncates to 30 and
  dedupes on the *stored* value so long/duplicate WC SKUs don't collide.

## 11. Recount  (`POST /migrate/recount`)

WooCommerce substeps: `['coupons', 'customers', 'subscriptions']`, recomputed
purely from the migrated FluentCart tables (reused read-only from
`MigratorService`): coupon `use_count`; customer LTV / purchase count / AOV /
first&last purchase dates; subscription bill counts. The list is exposed in
`GET /status` as `recount_substeps`, which drives the wizard.

## 12. WP-CLI

```bash
wp fluent_cart_migrator migrate_from_woo --all
wp fluent_cart_migrator migrate_from_woo --products       # also store settings
wp fluent_cart_migrator migrate_from_woo --tax_rates
wp fluent_cart_migrator migrate_from_woo --coupons
wp fluent_cart_migrator migrate_from_woo --payments
wp fluent_cart_migrator migrate_from_woo --missing-customers
wp fluent_cart_migrator migrate_from_woo --recount
wp fluent_cart_migrator migrate_from_woo --stats           # counts only
wp fluent_cart_migrator migrate_from_woo --reset           # dev mode only
```

Implemented in `Classes/WooCommerce/Commands.php`, registered as a sibling
subcommand in the bootstrap (the production EDD `Classes/Commands.php` is
untouched). For very large stores raise the memory limit, e.g.
`wp --exec='ini_set("memory_limit","1G");' fluent_cart_migrator migrate_from_woo --all`.

## 13. Reset (dev mode only)

`POST /reset` / `--reset` → `WooSourceMigrator::reset()` (gated behind
`FLUENT_CART_DEV_MODE`): clears the WooCommerce migration options, runs
`DBMigrator::refresh()`, and removes migrated products + mapping postmeta.

During `refresh()` you may see logged DB warnings like
`Can't DROP 'wp_fct_..._0'`; these are **benign** — WordPress core's
`drop_index()` speculatively drops `index_0..index_24` with errors hidden and
Query Monitor logs them. The reset still succeeds.

## 14. Grouped/bundle products & advanced subscriptions

- **Grouped / bundle products** — after all products migrate,
  `ProductMigrator::syncBundles()` maps WC `grouped` products (core) and Product
  Bundles (extension, guarded by `class_exists('WC_Product_Bundle')`) to
  FluentCart's bundle model via `ProductWriter::markBundle()`: child variation
  ids on the parent's default variation `other_info.bundle_child_ids` +
  `is_bundle_product = 'yes'` on the variation and product detail. The grouped
  path is verified; the Product Bundles path mirrors it but is unverified (the
  extension isn't available to test against).
- **Advanced WooCommerce Subscriptions** (all guarded by WC Subscriptions, so a
  no-op when it's absent): subscription **notes** → `fct_activity` (module =
  Subscription); a **dummy (canceled) subscription** for a renewal order whose
  parent subscription wasn't migrated, so the renewal transaction still links;
  and a best-effort **switch/upgrade marker** (`config.contains_switch` /
  `config.switched`). Basic parent/renewal subscription migration is in §7.9.
  These extras are **unverified** — WooCommerce Subscriptions wasn't available to
  test; they no-op (and don't affect the normal order flow) without it.

EDD-only concepts — software licenses, EDD legacy API/IPN endpoints, Paddle
backfill — do not apply to WooCommerce core and are intentionally out of scope.

## 15. File map

```
Classes/
  Contracts/AbstractSourceMigrator.php   # shared state + resumable loop + recount
  Dto/                                   # OrderData, OrderItemData, TransactionData,
                                         #  AddressData, AppliedCouponData, TaxRateData,
                                         #  SubscriptionData, LicenseData, ActivityData,
                                         #  CustomerData, CouponData, ProductData,
                                         #  ProductVariationData, ProductDownloadData
  Load/
    OrderWriter.php / ProductWriter.php / CustomerWriter.php
    CategoryWriter.php / CouponWriter.php / AttributeWriter.php
  Support/
    Money.php / Sku.php / OrderValidator.php / OrderDeleter.php / BatchRuntime.php
  WooCommerce/
    WooSourceMigrator.php   # detect/stats/steps, migrateOrdersPage hook, reset, IP mask
    OrderMigrator.php       # WC order  → OrderData
    ProductMigrator.php     # WC product → ProductData
    CouponMigrator.php      # WC coupon  → CouponData
    TaxRateMigrator.php     # WC tax     → fct tax classes/rates + rate-id map
    StoreSettingsMigrator.php
    Commands.php            # migrate_from_woo WP-CLI
    MigratorHelper.php      # WC-specific maps (status/stock/fulfillment/gateway), money/date
```

## 16. Verifying a run (WP-CLI)

```bash
wp fluent_cart_migrator migrate_from_woo --all
wp fluent_cart_migrator migrate_from_woo --stats
```

Worth asserting: migrated counts match the store; the accounting identity holds
per order (`total_amount == subtotal + tax + shipping + fee − coupon − manual`,
and `== round(WC total × 100)`); a second run produces no duplicate rows
(idempotency); coupons/tax-rates back-link `coupon_id` / `tax_rate_id` on orders;
recount populates customer `ltv` / `purchase_count`.
