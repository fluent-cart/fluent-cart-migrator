# WooCommerce → FluentCart: Subscriptions & Renewals

**Scope decision:** migrated WooCommerce subscriptions are handed to **FluentCart's
store-managed invoice engine**, mapped onto the right `collection_method`:

- **`system`** — a reusable, auto-chargeable gateway token is on file. FluentCart owns
  the renewal schedule/invoice and **auto-charges the saved token** each period.
- **`manual`** — no auto-chargeable token. FluentCart issues the renewal invoice and the
  customer pays it (Pay Now email + dunning).

This matches how WooCommerce Subscriptions itself bills: the **store** owns the schedule
and charges a saved gateway token — there is no gateway subscription object. FluentCart's
`system`/`manual` collection methods are the store-managed equivalent, so renewals
continue after migration without a WooCommerce→gateway subscription hand-off.

---

## 1. Why store-managed (not gateway) billing

| | WooCommerce | FluentCart `automatic` | FluentCart `system` / `manual` |
|---|---|---|---|
| Owns the schedule | WooCommerce (Action Scheduler) | The payment gateway | **FluentCart (invoice engine)** |
| Triggers each charge | WooCommerce, on a saved token | The gateway, by itself | FluentCart, on the due date |
| Object in Stripe/PayPal | None (vaulted token) | A real subscription (`sub_…`/`I-…`) | None (vaulted token) |

A migrated WooCommerce subscription has **no gateway subscription** for FluentCart to take
over (its `vendor_subscription_id` is a WooCommerce id, not a `sub_…`), so mapping it to
`automatic` is impossible. The store-managed methods (`system`/`manual`, shipped with the
[system-subscriptions](../../fluent-cart/dev-docs/system-subscriptions/) feature) are the
correct target: FluentCart becomes the biller, exactly as WooCommerce was.

(EDD, the other migration source, _is_ gateway-driven — it stores the real `sub_…`/`I-…` —
so EDD→FluentCart subscriptions migrate as `automatic`. WooCommerce cannot.)

## 2. How the collection method is chosen

`OrderMigrator::resolveSubscriptionCollection()` returns `system` **only when the saved
token is genuinely chargeable by FluentCart's engine**, so a migrated subscription never
lands in a state that fails every renewal. All other cases are `manual`:

| Source subscription | Result | Why |
|---|---|---|
| Stripe, `_requires_manual_renewal=false`, `_stripe_source_id` is a `pm_…` PaymentMethod, `_stripe_customer_id` present, and the FluentCart Stripe gateway declares `system_subscription` | **`system`** | Stripe can charge `pm_…` off-session with the customer id — the exact pair `Stripe::chargeRenewalInvoice()` reads |
| Stripe flagged `_requires_manual_renewal=true` | `manual` | Merchant/customer chose manual renewal |
| Stripe with a legacy `src_…` / `card_…` token | `manual` | Not usable as an off-session PaymentMethod |
| PayPal (PPCP) with `_billing_agreement_id` (`B-…`) | `manual` | A Billing Agreement id is **not** a PayPal Vault id; FluentCart's vault charge can't use it. Payer id is still kept as `vendor_customer_id` |
| Offline / unknown gateway | `manual` | Nothing to charge |

> **Do not use `WC_Subscription::is_manual()` for this decision.** It also returns `true`
> under the WooCommerce Subscriptions *duplicate-site lock* (any staging clone) and when
> the live gateway is momentarily unavailable — runtime/staging artifacts that would
> wrongly downgrade every token-backed subscription to `manual`. The migrator reads
> `get_requires_manual_renewal()`, the merchant's actual setting.

### Token storage for `system`

The renewal charge reads two values at fire time (never snapshotted):

```
customer = fct_subscriptions.vendor_customer_id                       ← Stripe cus_…
token    = active_payment_method meta → vendor_method_id              ← Stripe pm_…
                                     (or details.payment_method_id)
```

The migrator writes both: `vendor_customer_id` on the subscription row, and an
`active_payment_method` row in `fct_subscription_meta`:

```json
{ "method": "stripe", "type": "card", "vendor_method_id": "pm_…",
  "details": { "type": "card", "payment_method_id": "pm_…" } }
```

This is the same shape FluentCart's card-update / confirmation flows write, so the stock
`SystemChargeService` charges a migrated `system` subscription with no special-casing.

## 3. What migrates

Subscription rows migrate with accurate current state: status, billing interval,
`bill_times`, trial, dates, amounts (recurring split ex-tax / tax / gross), notes,
`vendor_subscription_id` (the WooCommerce id, for reference), `collection_method`,
`vendor_customer_id`, and — for `system` — the `active_payment_method` token meta.

## 4. Caveats for a store with active subscriptions

- **Same Stripe account required.** A migrated `system` subscription charges the original
  `pm_…`/`cus_…`, so FluentCart must be connected to the **same Stripe account** WooCommerce
  used. On a staging clone with test keys, those live tokens won't charge — expected.
- **Enable store-managed system charging.** Auto-charging only fires when the store's
  *Store Managed* subscription mode + "Automatically charge saved payment methods" setting
  is on (see the system-subscriptions PRD). Otherwise `system` rows behave like `manual`.
- **Overdue migrated subs.** FluentCart's invoice engine drives renewals off
  `next_billing_date`; a migrated sub whose next payment is already past due will generate
  its invoice (and, for `system`, attempt a charge) on the next scheduler pass.
- **Stop WooCommerce from also billing.** Once FluentCart owns renewals, disable
  WooCommerce Subscriptions' renewal scheduling for the migrated subs (or keep WooCommerce
  in its duplicate-site/manual state) so the customer isn't billed twice.

## 5. Quick reference

```sql
-- collection method split
SELECT collection_method, COUNT(*) FROM wp_fct_subscriptions GROUP BY collection_method;

-- system subscriptions with their saved token
SELECT s.id, s.current_payment_method, s.vendor_customer_id,
       JSON_UNQUOTE(JSON_EXTRACT(m.meta_value,'$.vendor_method_id')) AS token
FROM wp_fct_subscriptions s
JOIN wp_fct_subscription_meta m
  ON m.subscription_id = s.id AND m.meta_key = 'active_payment_method'
WHERE s.collection_method = 'system';
```

- Collection decision — `Classes/WooCommerce/OrderMigrator.php::resolveSubscriptionCollection()`
- Token meta write — `Classes/Load/OrderWriter.php::writeSubscriptions()`
- Charge contract — `fluent-cart/app/Modules/PaymentMethods/StripeGateway/Stripe.php::chargeRenewalInvoice()`
- Feature reference — `fluent-cart/dev-docs/system-subscriptions/prd.md`
