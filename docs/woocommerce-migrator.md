# WooCommerce → FluentCart Migrator

How the WooCommerce migration source works after the ETL refactor.

## 1. Architecture at a glance

The migrator is split into **Extract → Transform → Load**. Source classes read
a store and map records to a normalized, typed contract; a shared, source-agnostic
"Load" layer owns every FluentCart insert.

```
WooCommerce store ──Extract──▶ WooCommerce/ ──Transform──▶ Dto/ ──▶ Load/ ──▶ fct_* tables
 (wc_get_products /            (OrderMigrator,            (typed     (OrderWriter,
  wc_get_orders, HPOS-safe)     ProductMigrator)          value      ProductWriter,
                                                          objects)   CustomerWriter,
                                                                     CategoryWriter)
```

| Layer | Namespace / dir | Responsibility |
|-------|-----------------|----------------|
| **Source** | `Classes/WooCommerce/` | Read WooCommerce, map to DTOs (Extract + Transform). The only place that knows WooCommerce. |
| **Contract** | `Classes/Dto/` | Typed value objects — the normalized shape handed to the writers. |
| **Load** | `Classes/Load/` | Writers that own all FluentCart inserts, idempotency, uuid generation. Source-agnostic. |
| **Support** | `Classes/Support/` | Cross-cutting helpers: money, sku, validation, cascade delete, memory. |
| **Orchestration** | `Classes/Contracts/AbstractSourceMigrator.php` | State machine, resumable paginated loop, failed-log, recount dispatch, summary. |

The same Load/Support/Dto layers are designed to serve every source. EDD still
runs on its own legacy path (`Classes/MigratorService.php` + `Classes/EDD3/`)
behind the same interface; only WooCommerce is on the new layer today.

## 2. Entry points

- **Admin wizard** (Vue, `assets/js/`) → REST. Each request carries the selected
  source as a `?source=woocommerce` query param (set once via `setApiSource()`
  in `assets/js/api.js`), so `RestApi::resolveMigrator()` routes to the
  WooCommerce source instead of defaulting to EDD.
- **REST** namespace `fct-migrator/v1` (`Classes/Admin/RestApi.php`), e.g.
  `GET /sources`, `GET /stats/woocommerce`, `GET /status`,
  `POST /migrate/products`, `POST /migrate/payments`, `POST /migrate/recount`,
  `POST /reset`.
- **Source registry** `Classes/SourceManager.php` maps `woocommerce` →
  `WooCommerce\WooSourceMigrator`.

## 3. Detection, compatibility, stats

`WooSourceMigrator::detect()` returns a `compatibility` verdict the wizard
renders directly (so it never shows EDD copy):
- not active → blocked ("WooCommerce not detected")
- version `< 3.0` (the CRUD-API baseline) → blocked
- otherwise → pass.

`getStats()` counts products / orders / paid orders / customers / coupons /
subscriptions via the CRUD API (HPOS-safe), and lists gateways + order statuses
for the overview screen.

## 4. Product migration

`POST /migrate/products` → `WooSourceMigrator::migrateProducts()` →
`WooCommerce\ProductMigrator` (Transform) → `Load\ProductWriter` (Load).

Per product:
1. `ProductMigrator::sourceCategories()` flattens `product_cat` terms; once per
   run `Load\CategoryWriter::sync()` creates/maps them into FluentCart's
   `product-categories` taxonomy **parents-first**, returning `wcTermId → fctTermId`.
2. `ProductMigrator::buildProduct()` builds a `Dto\ProductData` with
   `Dto\ProductVariationData[]` (each carrying `Dto\ProductDownloadData[]`),
   resolved category ids, and the id-mapping meta keys.
3. `ProductWriter::write()`:
   - Upserts the `fluent-products` CPT post (updates in place if already mapped).
   - Inserts `fct_product_variations` — each SKU passed through
     `Support\Sku::unique()`.
   - Inserts `fct_product_details` (min/max price, default variation, fulfillment).
   - Inserts `fct_product_downloads` (driver/extension derived from the file).
   - Writes the bidirectional mapping postmeta.

Variations: a simple product → one variation (`variation_identifier = '0'`);
a variable product → one per child, keyed by the WC variation id.

## 5. Order migration

`POST /migrate/payments` → `AbstractSourceMigrator::migratePayments()` (shared
loop) → `WooSourceMigrator::migrateOrdersPage()` → `WooCommerce\OrderMigrator`
(Transform) → `Load\OrderWriter` (Load).

### Per-order transform (`OrderMigrator::migrateOrder`)
1. **Customer** — build `Dto\CustomerData` from the WC billing details;
   `Load\CustomerWriter::findOrCreate()` dedupes by email (per-batch cache) and
   returns the `fct_customers` row.
2. **Items** — each `WC_Order_Item_Product` → `Dto\OrderItemData`
   (cents amounts, product/variation mapped to FluentCart ids, subscription
   line flagged). Unmapped products keep `post_id = 0` so the historical line
   is preserved.
3. **Totals & reconciliation** — subtotal/coupon-discount come from the items;
   tax/shipping/fee from the order. The remainder needed to make the components
   equal the real `WC_Order::get_total()` is absorbed into `manual_discount_total`
   (or `fee_total` if negative), so the FluentCart accounting identity always
   holds: `total = subtotal + tax + shipping + fee − coupon − manual`.
4. **Status** — `MigratorHelper::orderStatus()` / `paymentStatus()` map WC
   statuses + refund amount to FluentCart `Status` enums.
5. **Transactions** — one charge `Dto\TransactionData` (= `total_paid`); refunds
   from `WC_Order::get_refunds()` become refund transactions.
6. **Addresses / coupons / taxes** — `Dto\AddressData[]` (billing+shipping),
   `Dto\AppliedCouponData[]` (coupon id resolved via
   `MigratorHelper::couponIdByCode`), `Dto\TaxRateData[]`.
7. **Subscriptions** (WooCommerce Subscriptions, if active) — parent orders build
   `Dto\SubscriptionData[]`; renewal orders set `linkedSubscriptionId` resolved
   from the already-migrated parent subscription. Guarded by `function_exists`,
   so it's a no-op when the plugin isn't installed.

### Persistence (`OrderWriter::write`)
Order of operations, all via raw `fluentCart('db')->table()->insert` (never
Eloquent — see §7):
`OrderValidator::validate()` → if the order id already exists,
`OrderDeleter::deleteById()` (idempotent re-run) → `fct_orders` (uuid generated
here) → `fct_order_items` (+ nested bundle children) → `fct_subscriptions` →
charge `fct_order_transactions` (linked to the subscription) → refund
transactions → `fct_order_addresses` → `fct_applied_coupons` →
`fct_order_tax_rate` → `fct_order_operations` → optional `fct_licenses` /
`fct_activity` (populated only by sources that have them — WooCommerce leaves
them empty).

## 6. The resumable batch loop

`AbstractSourceMigrator::migratePayments($page, $perPage, $maxSeconds)` is shared:

- Skips if the `payments` step is already done.
- Per page: `resetBatchCaches()` (clears the customer cache) →
  `migrateOrdersPage()` → save `last_order_page` → `BatchRuntime::freeMemory()`.
- Breaks when there are no more pages, when `$maxSeconds` elapses (REST passes
  25s), or when `BatchRuntime::memoryNearLimit()` (70% of the PHP memory_limit)
  trips — returning `has_more = true` so the next (fresh) request resumes.
- On completion marks `payments = yes` and rebuilds the summary.

REST uses `perPage = 50`; the front-end loops until `has_more` is false. The
memory guard auto-disables when `memory_limit` is unlimited (e.g. WP-CLI), so a
CLI run goes start-to-finish in one process.

Why the memory guard exists: WooCommerce order objects retain memory that
survives cache flushing, so a single web request can only safely process a
bounded number of orders before handing off.

## 7. Idempotency & key conventions

- **Order id is reused**: `fct_orders.id = WooCommerce order id`. Re-running
  deletes the prior order (cascade) and re-inserts — no duplicates.
- **Product mapping postmeta**:
  - `_fct_migrated_id` on the **WC product** → FluentCart post id
  - `_wc_migrated_from` on the **FluentCart post** → WC product id
  - `__wc_migrated_variation_maps` on the FluentCart post → `wcVariationId → fctVariationId`
- WooCommerce-migrated orders are tagged `config.migrated_from = "woocommerce"`.
- **Raw inserts, not models**: lets us reuse the source id, keep historical
  `created_at`/`updated_at`, set `uuid`/`receipt_number`/`invoice_no` explicitly,
  and bypass FluentCart model `boot()` hooks (which auto-generate those and can
  fire emails/webhooks on the `created` event).
- **SKU**: the `fct_product_variations.sku` column is `VARCHAR(30)` with a UNIQUE
  index, and MySQL here is non-strict (silent truncation). `Support\Sku::unique()`
  truncates to 30 and dedupes on the *stored* value (trimming the base so the
  `-wc-N` suffix survives), preventing collisions from long/duplicate WC SKUs.

## 8. Recount

`POST /migrate/recount` runs the substeps the source declares.
WooCommerce → `['customers', 'subscriptions']` (EDD's reactivation / UUID fixes
don't apply). Both recompute aggregates purely from the migrated FluentCart
tables (so they're source-agnostic and reused from `MigratorService`):
- **customers** — LTV, purchase count, AOV, first/last purchase dates.
- **subscriptions** — bill counts and completed status from renewal orders.

The substep list is exposed in `GET /status` as `recount_substeps`, and the
wizard runs exactly those — so it never sends EDD-only substeps to WooCommerce.

## 9. Reset (dev mode only)

`POST /reset` → `WooSourceMigrator::reset()` (gated behind
`FLUENT_CART_DEV_MODE`). It clears the WooCommerce migration options and runs
`DBMigrator::refresh()` (drop + recreate all FluentCart tables), then removes
migrated products and the mapping postmeta.

Note: during `refresh()` you may see logged DB warnings like
`Can't DROP 'wp_fct_..._0'; check that column/key exists`. These are **benign** —
WordPress core's `drop_index()` speculatively drops `index_0..index_24` with
errors hidden; Query Monitor logs the hidden error. The reset still succeeds.

## 10. Current limitations

- **Standalone coupon and tax-rate steps** (`migrateCoupons` / `migrateTaxRates`)
  are not implemented for WooCommerce. Order-level coupons and taxes *are*
  captured inline with each order; only the separate catalog-level migration of
  `fct_coupons` / `fct_tax_rates` is pending.
- **No WP-CLI command** for WooCommerce yet — migration runs through the admin
  wizard / REST. (A long CLI run would need a raised `memory_limit`.)
- WooCommerce Subscriptions support is best-effort and depends on the
  WooCommerce Subscriptions plugin being active.

## 11. File map

```
Classes/
  Contracts/AbstractSourceMigrator.php   # shared state + resumable loop + recount
  Dto/                                   # OrderData, OrderItemData, TransactionData,
                                         #  AddressData, AppliedCouponData, TaxRateData,
                                         #  SubscriptionData, LicenseData, ActivityData,
                                         #  CustomerData, ProductData, ProductVariationData,
                                         #  ProductDownloadData
  Load/
    OrderWriter.php        # writes the whole order graph
    ProductWriter.php      # writes product + variations + downloads + details
    CustomerWriter.php     # find-or-create, dedupe by email
    CategoryWriter.php     # parents-first taxonomy sync
  Support/
    Money.php              # toCents (zero-decimal aware)
    Sku.php                # VARCHAR(30)-safe unique SKU
    OrderValidator.php     # accounting identity guard
    OrderDeleter.php       # cascade delete (idempotency)
    BatchRuntime.php       # freeMemory + memory-limit guard
  WooCommerce/
    WooSourceMigrator.php  # detect/stats/steps + migrateOrdersPage hook + reset
    OrderMigrator.php      # WC order → OrderData (Transform)
    ProductMigrator.php    # WC product → ProductData (Transform)
    MigratorHelper.php     # WC-specific maps (status/stock/fulfillment), money/date getters
```

## 12. Verifying a run (WP-CLI)

```bash
wp eval '
  $s = new \FluentCartMigrator\Classes\WooCommerce\WooSourceMigrator();
  $s->migrateProducts();
  $r = $s->migratePayments(1, 50, 0);            // perPage 50, no time box (CLI)
  foreach ($s->getRecountSubsteps() as $sub) { $s->recountStats($sub); }
  echo "orders processed={$r["processed"]} errors={$r["errors_in_batch"]}\n";
'
```

Checks worth asserting: migrated counts match the store; the accounting identity
holds per order (`total_amount == subtotal + tax + shipping + fee − coupon −
manual`); `total_amount == round(WC total × 100)`; a second run produces no
duplicate rows (idempotency); recount populates customer `ltv` / `purchase_count`.
