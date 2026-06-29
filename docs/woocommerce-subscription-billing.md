# WooCommerce → FluentCart: Subscriptions & Renewals (beta scope)

**Scope decision:** the migrator imports WooCommerce subscriptions **as records**.
It does **not** manage or continue their renewals — WooCommerce stays the biller.
This doc explains why renewal handling is intentionally out of scope, and what
that means for a store with active subscriptions.

---

## 1. Why renewals aren't handled

Renewal billing works fundamentally differently in the two systems:

- **WooCommerce: the _store_ owns the schedule and charges.** A subscription is a
  `shop_subscription` post; WooCommerce's Action Scheduler fires on the due date and
  charges a vaulted gateway token (Stripe `_stripe_customer_id`/`_stripe_source_id`,
  PayPal PPCP `_billing_agreement_id`). There is **no subscription object inside
  Stripe/PayPal** — the gateway only charges when WooCommerce tells it to.
- **FluentCart: the _gateway_ owns the schedule and charges.** At signup FluentCart
  creates a real gateway subscription (Stripe `sub_…` / PayPal subscription) and the
  gateway bills autonomously, with FluentCart just recording each webhook. FluentCart
  **never self-charges.**

A migrated WooCommerce subscription therefore has **no gateway subscription for
FluentCart to take over**, and its `vendor_subscription_id` is a WooCommerce id no
gateway recognises. Bridging the two systems (so FluentCart mirrors WooCommerce-driven
renewals) is real work with payment-critical edge cases, so it is **deferred** — this
version migrates the data only.

| | WooCommerce | FluentCart |
|---|---|---|
| Owns the schedule | WooCommerce (Action Scheduler) | The payment gateway |
| Triggers each charge | WooCommerce, on a saved token | The gateway, by itself |
| Object in Stripe/PayPal | None (vaulted token) | A real subscription (`sub_…`/`I-…`) |

(EDD, the production source, _is_ gateway-driven — it stores the real `sub_…`/`I-…` —
which is why EDD→FluentCart renewals continue automatically and WooCommerce's don't.)

## 2. What the migrator imports

Subscription rows migrate with accurate current state:

- status (active/paused/cancelled/expired), billing interval, `bill_times`, trial,
  dates, amounts (recurring split ex-tax / tax / gross), notes.
- `vendor_subscription_id` = the WooCommerce subscription id.
- `vendor_customer_id` = the gateway customer reference (Stripe customer / PayPal payer).
- `config.external_billing = 'woocommerce'` — marks the row as billed outside FluentCart.

FluentCart **does not** attempt to charge or renew these.

## 3. Implication for stores with active subscriptions (important)

Because FluentCart isn't billing them and there's no gateway subscription feeding it
renewal webhooks, a migrated active subscription's `next_billing_date` will pass and
FluentCart's hourly expiry scanner will eventually mark it **`expired`** (≈7 days past
due for monthly), even though WooCommerce is still charging the customer.

**Guidance for the beta:**
- Keep **WooCommerce active** as the system of record for subscription billing and
  access. Do not rely on FluentCart's subscription status for active subscribers.
- Treat the migrated subscriptions in FluentCart as **history/reference**.
- Full renewal continuation (a WooCommerce→FluentCart renewal bridge, or a gateway
  hand-off) is a **future** enhancement, not part of this release.

> Optional hardening (not enabled): nulling `next_billing_date` on migrated subs would
> stop FluentCart's scanner from auto-expiring them, leaving them as static records. Ask
> if you want this — it's a one-line change in `OrderMigrator::buildSubscriptions()`.

## 4. Quick reference

```sql
-- migrated subscriptions (external-billed records)
SELECT id, status, current_payment_method, vendor_customer_id,
       vendor_subscription_id, next_billing_date,
       JSON_EXTRACT(config,'$.external_billing') AS external_billing
FROM wp_fct_subscriptions
WHERE JSON_EXTRACT(config,'$.dummy') IS NULL
ORDER BY id;
```

- Field population — `Classes/WooCommerce/OrderMigrator.php::buildSubscriptions()`
- FC expiry scanner (for reference) — `fluent-cart/app/Models/Subscription.php::checkAndExpireSubscriptions()`
