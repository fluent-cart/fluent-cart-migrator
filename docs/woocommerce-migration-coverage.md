# WooCommerce → FluentCart: Migration Coverage

What the WooCommerce source (`Classes/WooCommerce/`) migrates and what it does
not. Derived from the code as of 2026-08-24. See `woocommerce-migrator.md` for
how each step works internally.

## ✅ What IS migrated

### Products (`ProductMigrator`)

| Data | Notes |
|------|-------|
| Simple products | One FluentCart variation per product. |
| Variable products | One FC variation per WC variation; attribute terms resolved into the shared FC attribute library (advanced variations). Variable-subscription supported. |
| **Grouped products (bundles)** | WC core "grouped" type → FluentCart bundle: children's FC variation ids written to the parent's default variation (`bundle_child_ids`, `is_bundle_product = yes`). Wired by `syncBundles()` after all products exist. |
| **Product Bundles extension** | When `WC_Product_Bundle` is active, `bundle` type products migrate the same way; a bundle item pinned to a specific WC variation maps to that exact FC variation. |
| External/affiliate products | Migrated catalog-only: variation forced inactive + out-of-stock (FC has no external type); outbound URL and button text stored in product meta (`external_product_url`, `external_product_button`). |
| Subscription products (WC Subscriptions) | Recurring config (period × interval, length/installments, trial days, sign-up fee) on the variation's `other_info`; `payment_type = subscription`. |
| Product attributes | Global (taxonomy) and custom attributes → FC attribute groups/terms. Color/image swatch types (from swatch plugins' term meta) map to FC color/image groups. A non-variable product with attributes becomes an advanced-variations product via cartesian expansion (capped at 500 combinations, else stays simple). |
| Prices | Regular/sale price in cents; sale price → `item_price` with regular price as `compare_price` when on sale. |
| Stock | Managed stock quantities, backorders flag, stock status. Out-of-stock without managed stock is forced to managed/0 so it stays unpurchasable. Parent `manage_stock` rolled up from variations. |
| Downloadable files | WC downloads → FC variation downloads; `downloadable` + digital fulfillment flags. |
| Virtual/physical | → FC fulfillment type (`digital`/`physical`). |
| Categories | Full `product_cat` tree (with hierarchy) → FC `product-categories`; product assignments mapped. |
| Product images | Featured image (product + per-variation) → FC thumbnail/variation media. |
| Post fields | Title, description, short description, slug, status (publish/private/draft), author, created date. |
| SKUs | Migrated with de-duplication in the shared writer. |
| ID mapping | Bidirectional postmeta: `_fct_migrated_id` on WC products, `_wc_migrated_from` + `__wc_migrated_variation_maps` on FC products (makes the step idempotent/re-runnable). |

### Orders (`OrderMigrator`)

| Data | Notes |
|------|-------|
| Orders (all registered statuses) | HPOS-safe via `wc_get_orders`. Statuses mapped to FC (completed/processing/on-hold/pending/cancelled/refunded/failed; unknown → on-hold). `checkout-draft` excluded. |
| Line items | Quantity, unit price, subtotal, per-line tax, per-line discount, mapped to migrated FC product/variation ids. Amounts in cents. |
| Totals reconciliation | Subtotal, coupon discount, cart tax, shipping total, shipping tax (kept separate), fees; any residual difference vs the WC total absorbed as manual discount or fee so totals always reconcile. |
| Payment info | Gateway mapped to FC slug (stripe*/ppcp*/paypal* → stripe/paypal; cod/cheque/bacs → offline_payment; others pass through), method title, transaction id as vendor charge id, payment status. |
| Test/live mode | Best-effort per-gateway: read from the gateway's current test/sandbox setting (Stripe, PayPal, PPCP), else the store's order mode. Filterable via `fluentcart_migrator_woo_order_mode`. |
| Refunds | Full and partial. Per-line refund allocation from WC refund line items; unallocated refunds spread proportionally across items. Refund transactions with their own dates. |
| Order notes | System + customer notes → FC order activities, with author and timestamps. |
| Billing/shipping addresses | Both, incl. company/phone/email in meta. |
| Applied coupons | Code, discount amount, back-linked to the migrated FC coupon id. |
| Order tax lines | Per-rate tax rows mapped through the WC→FC tax-rate map; multiple WC rates collapsing to one FC rate are accumulated with source components preserved in meta. Inclusive/exclusive behavior recorded. |
| Invoice numbers | Sequential-number-plugin numbers kept verbatim; bare ids get the FC invoice prefix. |
| Shipping status | Physical orders: completed → shipped, else unshipped. Digital orders carry none. |
| Customer IP | Migrated; gateway/proxy IPs shared by >30 orders masked after the step for privacy. |
| Failed-order log | Every skipped/failed order logged with context (number, status, date, total, email, edit link) for the skip report — including orders in unregistered custom statuses. |

### Subscriptions (WC Subscriptions extension)

| Data | Notes |
|------|-------|
| Subscriptions from parent orders | Billing interval (incl. quarterly/half-yearly collapse), recurring amount/tax/total split, sign-up fee, bill times (installments), trial days, next billing date, trial end, expiry, cancel date, status (incl. derived expired/trialing), vendor subscription id. |
| Billing schedule snapshot | True cadence (period × interval + calendar anchor, incl. WCS synchronized renewal days) stored in `config.billing_schedule` so "every 2 weeks" and synced billing survive. |
| Renewal orders | Typed as renewals and linked to the migrated subscription; parent order id stamped for recount attribution. |
| Missing parent subscriptions | A renewal whose WC subscription was deleted gets one shared placeholder (canceled) subscription keyed by the source subscription id. |
| Auto-renewal capability | Stripe subscriptions with a `pm_` PaymentMethod + customer id (and FC Stripe supporting `system_subscription`) migrate as `system` collection with the card-on-file (brand/last4/expiry from the WC token vault). Everything else → `manual` (FC issues renewal invoices). |
| Subscription notes | → FC subscription activities. |
| Switch/upgrade orders | Marked in order config with the related subscription ids (best-effort, unverified). |

### Customers (`OrderMigrator` + `migrateMissingCustomers`)

| Data | Notes |
|------|-------|
| Customers from orders | Find-or-create by email; name, WP user link, billing geo fields. |
| Address book | Billing (+phone/email) and shipping addresses seeded from the customer's first migrated order. |
| Registered customers without orders | Users with `customer` or `subscriber` role migrated from their WC customer profile (incl. addresses), deduped by email; users without email logged as skipped. |

### Coupons (`CouponMigrator`)

| Data | Notes |
|------|-------|
| All `shop_coupon` posts | Percent → percentage; fixed cart/product → fixed (cents); zero-amount + free shipping → FC `free_shipping` type. |
| Restrictions | Usage limit, per-user limit, min/max spend, included/excluded products (mapped to FC ids) and categories. |
| Recurring behavior | WCS `recurring_*`/`renewal_*` discount types → `is_recurring = yes`. |
| Misc | Usage count, individual-use → stackable flag, expiry date, description, status, dates. |

### Tax configuration (`TaxRateMigrator`)

| Data | Notes |
|------|-------|
| Tax enablement + inclusion | FC tax settings enabled; prices-include-tax → included/excluded. |
| Tax rates | FC's built-in rates generated for every country WC taxes; WC `tax_rate_id` → `fct_tax_rates.id` map stored for the order step. Skipped cleanly when WC taxes are off. |

### Store settings (`StoreSettingsMigrator`)

Store name, address, city, postcode, country/state, currency, currency
position, decimal separator — **non-destructive** (only fills FC keys that are
still empty). Runs once with the first products page.

### Post-migration (recount substeps)

Coupon use counts, customer LTV/purchase counts, orphan renewal re-attachment
(`reactivations`), subscription bill counts.

---

## ❌ What is NOT migrated

### Products

| Data | Notes |
|------|-------|
| Product gallery images | Only the featured image migrates; `_product_image_gallery` is not read. |
| Product tags (`product_tag`) | Only categories migrate. |
| Product reviews & ratings | Not read at all. |
| Weight / dimensions / shipping classes | FC data model differences; not mapped. |
| Scheduled sale dates | Sale price migrates as current price state; `date_on_sale_from/to` scheduling is lost. |
| Cross-sells / up-sells | Not mapped. |
| Sold-individually flag, purchase note, menu order | Not mapped. |
| Trashed products | Only publish/private/draft statuses are queried. |
| Composite Products, Mix & Match, other bundle-ish extensions | Only WC core grouped + the official Product Bundles extension are recognized. |
| Product Bundles per-item config | Bundle **relationships** migrate; per-item quantities (min/max), per-item discounts, optional-item flags, and bundle-level dynamic pricing do not — FC's bundle model has no equivalent. |
| Product add-ons (WC Product Add-Ons etc.) | Line-item meta from add-ons is not carried onto FC order items. |

### Orders

| Data | Notes |
|------|-------|
| `checkout-draft` orders | Deliberately excluded. |
| Orders in unregistered custom statuses | Cannot be queried; logged to the skip report instead (re-activate the status plugin and re-run). |
| Bundle/grouped structure inside orders | Order line items migrate flat; parent-child (bundled_by) grouping of WC line items is not preserved. |
| Shipping line-item detail | Shipping totals/tax migrate; the chosen shipping method/rate per order does not. |
| Downloadable-file permission grants | Not copied; FC re-derives download access from its own order items. |
| Custom order item meta | Arbitrary third-party line-item meta is not carried over. |
| Guest-order → later-registered-user linking | Customer link is by billing email / customer id at migration time only. |

### Subscriptions

| Data | Notes |
|------|-------|
| PayPal auto-renewals | PayPal Billing Agreement ids (`B-…`) are not chargeable by FC's vault-based engine — those subscriptions migrate as `manual` collection (payer id kept for reference). |
| Legacy Stripe tokens | `src_…` / `card_…` tokens can't be charged off-session → `manual`. |
| Non-Stripe token gateways | Any gateway other than Stripe (with `pm_` token) → `manual` collection. |
| Pending subscription switches | Only a config marker is recorded; the switch history/proration is not reconstructed. |
| WCS retry/failed-payment schedules | Not migrated. |

### Store / platform

| Data | Notes |
|------|-------|
| Licenses | WooCommerce core has none; software-licensing extensions are not supported (`has_licenses = false`). Unlike the EDD source, there is no license migration or legacy API compatibility layer for Woo. |
| Payment gateway credentials | Gateways must be configured in FluentCart manually; only the per-order method slug/mode is mapped. |
| Shipping zones, methods, rates | Not migrated. |
| Custom tax rates as-defined | FC's built-in country/state rates are generated and mapped to; WC's exact custom rate values (e.g. city/postcode-scoped rates, custom percentages FC lacks) are not recreated. |
| Webhooks, REST API keys | Not migrated. |
| Email templates / notification settings | Not migrated. |
| Gift cards, points & rewards, memberships, bookings, deposits | No support for these extensions. |
| Analytics/reports history | Not migrated (FC recounts stats from migrated data). |
| Legacy endpoint compatibility | The EDD source ships an `EddLicenseHandler` for old EDD API/IPN/download URLs; there is no WooCommerce equivalent. |

---

## Notes on the bundle question specifically

Bundle migration lives in `ProductMigrator::syncBundles()`
(`Classes/WooCommerce/ProductMigrator.php:148`) and
`ProductWriter::markBundle()`. It runs **after the final products page** (both
in the one-shot `migrate()` and the resumable REST path in
`WooSourceMigrator::migrateProducts()`), because every child must already exist
to resolve its FC variation id. Children that failed to migrate are silently
dropped from the bundle; a parent whose children all failed is migrated as a
normal (non-bundle) product.
