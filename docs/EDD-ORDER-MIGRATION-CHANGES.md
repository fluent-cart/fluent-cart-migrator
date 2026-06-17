# EDD Order Migration — Changes Admins Should Know

**Last updated:** June 12, 2026 · **Scope:** `Classes/EDD3/PaymentMigrate.php`

This document explains recent changes to how the migrator handles EDD orders, what data adjustments happen automatically, and what to check after a migration.

## Summary

| # | Situation in EDD | Before | Now |
|---|---|---|---|
| 1 | Order has fees (surcharges, shipping-as-fee, Fees API) | ❌ Skipped with validation error | ✅ Migrates; fee folded into item prices |
| 2 | Order paid via multiple transactions | ⚠️ Migrated with wrong total + fake discount | ✅ Each payment becomes its own transaction |
| 3 | Paid order with no transaction records | ⚠️ Migrated as a $0 order | ✅ Migrates with the real EDD order total |
| 4 | Renewal whose subscription was deleted | ❌ Skipped with subscription error | ✅ Migrates as a standalone order |
| 5 | Wallet credit / negative fee | Shown as manual discount | Same (unchanged, documented) |

## Why these changes were made

Two errors were causing orders to be **skipped entirely** during EDD → FluentCart migration:

1. `Order total paid does not match order totals.`
2. `No subscription ID found for renewal transaction.`

Skipped orders never appeared in FluentCart, so revenue, customer history, and license records were incomplete. The investigation also uncovered cases that migrated *without* errors but with distorted totals (#2 and #3 above). All of these are now migrated with correct totals, with automatic adjustments described below.

---

## 1. Orders with EDD fees now migrate (fee is folded into item prices)

**Before:** Any order that included an EDD fee — gateway surcharges, shipping charged via EDD Simple Shipping, signup fees, or any addon using the EDD Fees API — failed validation and was skipped. EDD stores fees separately (`edd_order_adjustments`), so the charged amount was higher than the sum of the order's items.

**Now:** The difference between what the customer actually paid and the item totals is **distributed proportionally across the order items**. The order migrates with totals that match the real charged amount.

**What the admin will see:**
- Item prices on migrated orders may be slightly higher than the original EDD product price (the fee portion is included in them). FluentCart has no separate "fee" line, so this is the only way to keep the order total truthful.
- An activity log entry on the order: **"Order totals adjusted during migration"** — including the adjusted amount in cents.
- The same applies to small 1-cent rounding differences between EDD's item rows and the gateway charge.

**Trade-off to be aware of:** per-product revenue reports will include fee amounts inside product line totals. The order total and total paid are exact; the item-level split is approximate for fee orders.

## 2. Split / multiple payments now migrate as separate transactions

**Before:** If an EDD order had more than one completed payment transaction (partial payments, wallet + gateway combinations, recorded retries), the migrator only read the **first** transaction. A $103 order paid in two transactions ($40 + $63) migrated as a **$40 order with a fabricated $63 "manual discount."**

**Now:** Each completed EDD transaction is migrated as its **own FluentCart transaction**, keeping its original amount, gateway charge ID, and date. The order's total paid is the sum of all of them — the order above migrates as $103 paid with two $40/$63 charge transactions, no fake discount.

**What the admin will see:** every payment listed individually on the order's payments panel, plus an activity log entry: **"Multiple payment transactions migrated"** with the count. Because each transaction keeps its own gateway charge ID, **refunds from FluentCart work per transaction** exactly as they would for native orders.

## 3. Paid orders without transaction records now keep their real totals

**Before:** Completed orders that have no transaction rows in EDD (common for orders migrated long ago from EDD 2.x, and for manually created orders) migrated as **$0 orders with a 100% "manual discount"** — real sales disappeared from revenue reports.

**Now:** When the order status shows money was collected (paid, refunded, or partially refunded) but no transaction rows exist, the migrator **trusts the EDD order total** as the paid amount.

**What the admin will see:** these orders now show their true totals. Pending and failed orders are not affected — they keep a paid amount of 0 as before.

## 4. Renewal orders with missing subscriptions now migrate as standalone orders

**Before:** A renewal payment whose EDD subscription record was deleted (or whose parent order could not be migrated) was skipped with `No subscription ID found for renewal transaction.`

**Now, in order of preference:**
1. The migrator tries harder to find the right FluentCart subscription (including via the EDD subscription's parent payment).
2. If a renewing license exists, it can create a placeholder ("dummy") subscription attached to the parent order — this lookup now works with native EDD 3.x data (it previously only worked for stores upgraded from EDD 2.x).
3. If no subscription can be resolved at all, the order **migrates as a regular standalone order** instead of being skipped.

**What the admin will see:** on fallback orders, an activity log entry: **"Renewal migrated as a standalone order"** with the original EDD subscription ID. These orders won't be linked to a subscription in FluentCart — the subscription no longer exists in EDD either — but the revenue, customer, and license history are preserved.

## 5. Wallet credits / negative fees (unchanged behavior, worth knowing)

EDD records wallet payments and credits as **negative fees**. These orders migrate with the credit shown as a **manual discount** (e.g., a $100 order paid with $20 wallet credit shows $20 manual discount, $80 paid). Totals are consistent; only the label differs from the original meaning.

---

## Reporting and refund impact (verified against FluentCart core)

**Future renewal billing: no impact.** Live renewal processing (gateway webhooks → `fct_subscriptions` by `vendor_subscription_id`) is untouched. The standalone fallback only applies to renewals whose subscription no longer exists in EDD, so no future webhook will ever reference them. The post-migration `recountSubscriptions` step counts renewals by `parent_id` against existing subscriptions — fallback orders' parents have no subscription by definition, so `bill_count` stays accurate.

**Revenue report categorization.** `DefaultReportService` splits revenue by order type (`onetime_gross` / `renewal_gross` / `subscription_gross`). Renewals migrated as standalone orders count as **one-time revenue, not renewal revenue** — total revenue is unchanged, only the split shifts for these orphan orders (previously they were missing from reports entirely). Note: the `subscription_renewals` metric counts by `parent_id`, which fallback orders keep, so that one metric still counts them.

**Refunds.** Refunds in FluentCart are issued per transaction, and every migrated transaction keeps its own gateway charge ID and amount — so gateway refunds work normally, including for split-payment orders (refund each charge up to its own amount). The one exception: orders migrated via the **no-transaction fallback** have no gateway charge ID, so refunds for those must be issued in the gateway dashboard — same as before the changes.

## Activity log reference

Every automatic adjustment leaves a trail on the migrated order. These are informational, not errors:

| Activity title | Meaning |
|---|---|
| Order totals adjusted during migration | Fee/rounding surplus was distributed into item prices (change #1) |
| Multiple payment transactions migrated | The order's payments were migrated as separate transactions (change #2) |
| Renewal migrated as a standalone order | No subscription could be resolved; order kept without subscription link (change #4) |
| Dummy Subscription Created for renewal payment | A placeholder subscription was created from the renewing license (change #4) |

## What to check after migrating

1. **Failed order log:** any orders that still could not migrate are recorded in the `_fluent_edd_failed_payment_logs` WordPress option (stage and reason per order).
2. **Spot-check fee orders:** pick a few orders that had fees in EDD and confirm the FluentCart total matches the gateway charge; expect the "Order totals adjusted during migration" activity on them.
3. **Spot-check split payments:** orders with the "Multiple payment transactions migrated" activity should list each payment with its own amount and gateway charge ID.
4. **Spot-check renewals:** orders with the "Renewal migrated as a standalone order" activity are intentional fallbacks, not errors.
5. **Reports:** total revenue should now match EDD more closely than before, since fee orders, split payments, and legacy no-transaction orders are no longer dropped or zeroed.
