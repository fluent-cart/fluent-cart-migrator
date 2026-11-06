# Fix subscriptions imported as Manual

**Recommended:** fix the payment details in WooCommerce first, then migrate into a clean staging destination. If you must keep a completed import, use Option B.

## Before you begin

- Back up your staging database and record the original payment values.
- Keep automatic billing disabled on staging while making changes. Live Stripe credentials can charge real customers.
- Only change subscriptions that are intended and authorized to renew automatically. An **Active** subscription may still use manual renewal.
- Confirm the correct Stripe customer (`cus_...`) and PaymentMethod (`pm_...`). The PaymentMethod must belong to that customer in the correct Stripe account and test/live mode.

Run the commands below from your staging WordPress installation using WP-CLI. Your developer or hosting provider can help. Replace the example IDs before running them.

## What FluentCart needs

| Woo subscription setting | Required value |
| --- | --- |
| Payment gateway | Stripe |
| `_stripe_customer_id` | Correct `cus_...` |
| `_stripe_source_id` | Correct `pm_...`, not blank or `src_...` / `card_...` |
| Manual renewal | Disabled using `set_requires_manual_renewal(false)` |

**Use the subscription ID, not the parent or renewal order ID.** For example, if order `71` belongs to subscription `72`, the Stripe values must be saved on subscription `72`.

## Option A: Fix WooCommerce first (preferred)

### 1. Check the affected subscription

Replace `12345` with its Woo subscription ID:

```bash
wp eval '
$s = wcs_get_subscription(12345);
if (!$s) { WP_CLI::error("Subscription not found."); }
WP_CLI::line(wp_json_encode([
    "subscription_id" => $s->get_id(),
    "gateway" => $s->get_payment_method(),
    "manual_renewal" => $s->get_requires_manual_renewal(),
    "customer" => $s->get_meta("_stripe_customer_id"),
    "payment_method" => $s->get_meta("_stripe_source_id")
], JSON_PRETTY_PRINT));
'
```

Check for missing IDs or `manual_renewal: true`. Confirm automatic renewal is intended before changing that flag.

### 2. Save the correct details

For a confirmed Stripe subscription, replace the three values at the top:

```bash
wp eval '
$subscriptionId = 12345;
$customerId = "cus_REPLACE_ME";
$paymentMethodId = "pm_REPLACE_ME";

$s = wcs_get_subscription($subscriptionId);
if (!$s) { WP_CLI::error("Subscription not found."); }
$s->update_meta_data("_stripe_customer_id", $customerId);
$s->update_meta_data("_stripe_source_id", $paymentMethodId);
$s->set_requires_manual_renewal(false);
$s->save();
WP_CLI::success("Subscription payment details saved.");
'
```

Woo's API updates existing values or creates missing metadata in the correct table. Use boolean `false` as shown, not the string `'0'`. This command does not change the gateway or billing dates.

Run Step 1 again. Confirm the IDs are correct and `manual_renewal` is `false`.

### 3. Migrate

If you have not imported yet, save the repairs and choose either option:

- **Admin UI:** Open the FluentCart Migrator in your WordPress admin, select WooCommerce, and follow the migration steps through completion, including the final recount.
- **WP-CLI:** Run the full migration command:

```bash
wp fluent_cart_migrator migrate_from_woo --all
```

If the import is already complete, **rerunning `--payments` will not repair it**. Choose one of these paths:

- Restore a pre-migration staging backup, apply the Woo repairs again, then complete the migration through the admin UI or run `--all`.
- Keep the existing import and use Option B below.

`--all` includes the final recount. If you run migration steps individually, finish with `wp fluent_cart_migrator migrate_from_woo --recount`.

**Do not use `--reset` to repair individual subscriptions.** It requires `FLUENT_CART_DEV_MODE` and drops/recreates FluentCart tables, removes FluentCart products and migration mappings, and clears migration state. It can erase unrelated FluentCart data too.

## Option B: Fix FluentCart after import

Use this when you need to keep the completed import. It does not repair the Woo source. Confirm the Stripe customer, PaymentMethod, account, and test/live mode as described under "Before you begin."

### 1. Find the imported subscription

Run this read-only SQL in your database tool. Replace `12345` with the Woo subscription ID and `wp_` with your database prefix:

```sql
SELECT s.id AS fluentcart_subscription_id,
       s.vendor_subscription_id AS woo_subscription_id,
       s.collection_method,
       s.vendor_customer_id,
       s.current_payment_method,
       m.meta_value AS active_payment_method
FROM wp_fct_subscriptions AS s
LEFT JOIN wp_fct_subscription_meta AS m
  ON m.subscription_id = s.id
 AND m.meta_key = 'active_payment_method'
WHERE s.vendor_subscription_id = '12345';
```

Confirm the customer and subscription in FluentCart, then note the **FluentCart subscription ID**. If no row appears, or there are multiple matches, ask your developer to investigate before changing anything.

### 2. Save the correct details

This example is for a Stripe **card** PaymentMethod. Replace the four values at the top:

```bash
wp eval '
$fluentCartId = 999;
$wooSubscriptionId = "12345";
$customerId = "cus_REPLACE_ME";
$paymentMethodId = "pm_REPLACE_ME";

$s = \FluentCart\App\Models\Subscription::find($fluentCartId);
if (!$s) { WP_CLI::error("Subscription not found."); }
if ((string) $s->vendor_subscription_id !== $wooSubscriptionId) {
    WP_CLI::error("Woo subscription ID does not match.");
}
if ($s->collection_method !== "manual") {
    WP_CLI::error("Subscription is not manual. Check before changing.");
}
$s->updateMeta("active_payment_method", [
    "method" => "stripe",
    "type" => "card",
    "vendor_method_id" => $paymentMethodId,
    "details" => [
        "type" => "card",
        "payment_method_id" => $paymentMethodId
    ]
]);
$s->vendor_customer_id = $customerId;
$s->current_payment_method = "stripe";
$s->collection_method = "system";
$s->save();
WP_CLI::success("Subscription payment details saved.");
'
```

This replaces the active payment metadata and enables system charging. It does not repair billing dates or populate optional card display details such as brand and expiry.

### 3. Confirm the result

Run the SQL from Step 1 again. Expect:

- `collection_method`: `system`
- `vendor_customer_id`: the correct `cus_...`
- `current_payment_method`: `stripe`
- `active_payment_method`: the correct `pm_...` in both `vendor_method_id` and `details.payment_method_id`

## Before enabling billing

1. Check the subscription's customer, product, status, amount, currency, and next billing date.
2. Test a renewal separately with a test subscription and matching Stripe test-mode data. Confirm payment succeeds and the next billing date advances. Saving `system` alone does not prove renewal will succeed.
3. At production cutover, ensure only one system bills each subscription. Prevent duplicate charges from WooCommerce, FluentCart, and staging.

If anything is incorrect, keep billing disabled and restore the original values or staging backup.

## Reference: storage and UI

Replace `wp_` with your database prefix. No direct SQL inserts are needed when using the commands above.

| Data | Table and identifier |
| --- | --- |
| Woo metadata with HPOS | `wp_wc_orders_meta`, where `order_id` is the Woo subscription ID |
| Woo metadata with legacy storage | `wp_postmeta`, where `post_id` is the Woo subscription ID |
| FluentCart charge settings | `wp_fct_subscriptions`: `collection_method`, `vendor_customer_id`, `current_payment_method` |
| FluentCart payment token | `wp_fct_subscription_meta`: `subscription_id`, key `active_payment_method`, JSON value containing `vendor_method_id` |

**HPOS** means High-Performance Order Storage. WooCommerce's APIs automatically use the configured storage. The Woo metadata keys are `_stripe_customer_id`, `_stripe_source_id`, and `_requires_manual_renewal`.

**UI availability:** WooCommerce's Stripe integration may provide **Stripe Customer ID** and **Stripe Payment Method ID** fields on the Edit Subscription screen, depending on configuration. Use those for the verified IDs if available. Option B is a developer-assisted FluentCart data repair; it does not rely on a dashboard repair button.

These instructions are based on code inspection. The commands have not been executed against your store. Apply and validate them on staging before production.
