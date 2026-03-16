# Complete Guide: Migrating from Easy Digital Downloads to FluentCart

Moving to a modern ecommerce platform shouldn't mean losing years of customer data, orders, subscriptions, or license records. The **FluentCart Migrator** plugin makes switching from Easy Digital Downloads (EDD) to FluentCart a seamless, reliable process — migrating everything that matters so your business continues running without disruption.

This guide walks you through every step of the migration: what gets migrated, how to run it, what to verify afterward, and what FluentCart does automatically to maintain backward compatibility with your existing EDD setup.

---

## Table of Contents

1. [What Gets Migrated](#what-gets-migrated)
2. [Prerequisites](#prerequisites)
3. [Installation](#installation)
4. [Step-by-Step Migration](#step-by-step-migration)
5. [Post-Migration Verification](#post-migration-verification)
6. [Backward Compatibility — What's Handled Automatically](#backward-compatibility)
7. [What Needs Manual Attention](#what-needs-manual-attention)
8. [Frequently Asked Questions](#frequently-asked-questions)
9. [Troubleshooting](#troubleshooting)

---

## What Gets Migrated {#what-gets-migrated}

The migrator transfers your complete EDD data into FluentCart, preserving your business history and customer relationships.

### Products
- All EDD download products with their titles, descriptions, and settings
- Simple products (single pricing)
- Variable pricing products — each price option becomes a FluentCart product variation
- Product bundles with all child product associations
- Recurring/subscription products with billing intervals (daily, weekly, monthly, quarterly, half-yearly, yearly)
- Signup fees for product variations
- Product images and thumbnails

### Coupon Codes
- All active and expired discount codes
- Percentage and flat amount discount types
- Coupon usage limits (per-coupon and per-user)
- Start and expiration dates
- Product-specific restrictions
- Coupon minimum purchase requirements
- Recurring discount settings (applies to renewals or first payment only)
- Coupon descriptions and notes

### Tax Rates
- All EDD tax rate configurations
- Country and state-level tax rates
- Tax-inclusive and tax-exclusive pricing behavior

### Orders & Payments
- **All order records** — completed, pending, processing, refunded, partially refunded, and revoked orders
- Order line items with correct unit prices, quantities, subtotals, tax amounts, and discounts
- Customer billing and shipping addresses
- Payment transactions with gateway details (Stripe charge IDs, PayPal transaction IDs)
- Refund records with amounts and dates
- Applied coupon codes on each order
- Order notes and activity logs

### Renewal Orders
- Subscription renewal payments are linked to their parent orders
- Renewal order history is fully preserved

### Subscriptions
- Active, cancelled, expired, completed, failing, and pending subscriptions
- Billing intervals and billing cycle limits
- Signup fees (calculated as difference between initial payment and recurring amount)
- Subscription start dates, renewal dates, and expiration dates
- Cancellation dates for cancelled subscriptions
- Stripe subscription IDs (`vendor_subscription_id`)
- PayPal subscription IDs (`vendor_customer_id`)
- Subscription notes and activity history
- Currency information

### Software Licenses (EDD Software Licensing)
- All license keys with their current status (active, inactive, expired, disabled)
- License activation limits (including manually overridden limits)
- License expiration dates
- Site activations — every activated site URL is preserved
- License-to-subscription associations
- License-to-order associations

### Customers
- Customer records with names and email addresses
- WordPress user account associations
- Customer addresses (country, state, city, postal code)
- After migration, customer statistics (lifetime value, purchase count, average order value) are recalculated for accuracy

---

## Prerequisites {#prerequisites}

Before starting the migration, make sure you have:

1. **WordPress** with admin (and SSH/terminal) access
2. **Easy Digital Downloads 3.x** installed and active with your existing data
3. **FluentCart** installed and activated — [Get FluentCart](https://fluentcart.com)
4. **FluentCart Pro** installed and activated (required only if you use EDD Software Licensing)
5. **WP-CLI** installed on your server — [WP-CLI installation guide](https://wp-cli.org/#installing)
6. A **backup of your database** — always back up before any migration

### Why WP-CLI?

The migration runs via WP-CLI (WordPress Command Line Interface) rather than the browser. This is intentional — WP-CLI handles large datasets reliably without running into PHP timeout limits or memory issues that browser-based migrations often face. Even stores with tens of thousands of orders migrate smoothly.

### Server Recommendations

- **PHP memory limit**: 256MB or higher recommended
- **Max execution time**: Not a concern with WP-CLI (it bypasses PHP time limits)
- For stores with 50,000+ orders, the migration may take 15-30 minutes. It processes orders in batches of 1,000 and supports **resume** if interrupted

---

## Installation {#installation}

### Step 1: Install the FluentCart Migrator Plugin

The migrator plugin is available on GitHub. Install it using one of these methods:

**Method A — Download and Upload:**

1. Go to [github.com/fluent-cart/fluent-cart-migrator](https://github.com/fluent-cart/fluent-cart-migrator)
2. Click the green **Code** button and select **Download ZIP**
3. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**
4. Upload the downloaded ZIP file and click **Install Now**
5. Click **Activate Plugin**

**Method B — Via WP-CLI (if you have Git on your server):**

```bash
cd /path/to/your/wordpress/wp-content/plugins/
git clone https://github.com/fluent-cart/fluent-cart-migrator.git
wp plugin activate fluent-cart-migrator
```

### Step 2: Verify Everything Is Active

Run this command to confirm all required plugins are active:

```bash
wp plugin list --status=active --fields=name,version
```

You should see **easy-digital-downloads**, **fluent-cart**, and **fluent-cart-migrator** in the active plugins list. If you use EDD Software Licensing, also confirm **fluent-cart-pro** is active.

---

## Step-by-Step Migration {#step-by-step-migration}

### Quick Start — Migrate Everything at Once

If you want to migrate all data in a single command:

```bash
wp fluent_cart_migrator migrate_from_edd --all
```

This runs all migration steps in the correct order: Products → Tax Rates → Coupons → Orders/Payments → Recount Statistics.

That's it. For most stores, this is all you need.

---

### Advanced — Step-by-Step Migration

If you prefer more control or want to migrate in stages, you can run each step individually:

#### Step 1: Migrate Products

```bash
wp fluent_cart_migrator migrate_from_edd --products
```

This migrates all EDD download products into FluentCart:
- Creates FluentCart products with matching titles, descriptions, and settings
- Converts variable pricing options into FluentCart variations
- Sets up bundle products with their child associations
- Configures recurring billing intervals for subscription products
- Preserves signup fees on product variations

**What you'll see:**
```
Starting Migrating products
Migrated 45 Products of 45
---------------------------------------
```

#### Step 2: Migrate Tax Rates

```bash
wp fluent_cart_migrator migrate_from_edd --tax_rates
```

Transfers your EDD tax rate configurations to FluentCart, including country and state-level rates.

#### Step 3: Migrate Coupon Codes

```bash
wp fluent_cart_migrator migrate_from_edd --coupons
```

Migrates all discount codes with their rules, limits, date ranges, product restrictions, and recurring settings.

#### Step 4: Migrate Orders, Subscriptions & Licenses

```bash
wp fluent_cart_migrator migrate_from_edd --payments
```

This is the main migration step. For each EDD order, it creates:
- The FluentCart order with all line items and pricing
- Payment transactions with gateway details
- Customer records (created automatically if they don't exist)
- Billing addresses
- Refund records (if applicable)
- Applied coupon records
- Order activity notes
- Subscriptions (if the order contains subscription products)
- Software licenses and site activations (if using EDD Software Licensing)
- Renewal order history linked to parent subscriptions

**What you'll see:**
```
Starting orders Migration. Page: 1
Migrating Payments: (12,847) [========================================] 100%
All Payments Migration has been completed
```

Orders are processed in batches of 1,000. A progress bar shows real-time status.

#### Step 5: Recount Statistics

```bash
wp fluent_cart_migrator migrate_from_edd --recount
```

After all data is migrated, this step recalculates:
- **Coupon usage counts** — accurate count of how many times each coupon was used
- **Customer statistics** — lifetime value (LTV), total purchase count, average order value (AOV), first and last purchase dates
- **Subscription bill counts** — correct renewal counts and subscription completion status
- **Subscription UUIDs** — generates any missing unique identifiers
- **Orphaned renewals** — reconnects any renewal orders that weren't automatically linked to their subscriptions

---

### Migration Resume — Interrupted? No Problem

The migration tracks its progress. If it's interrupted (server restart, SSH disconnect, etc.), simply run the same command again:

```bash
wp fluent_cart_migrator migrate_from_edd --all
```

It will skip already-completed steps (products, coupons) and **resume orders from where it left off**. You'll see:

```
Products Migration already done. Skipping...
Starting orders Migration. Page: 47
```

---

### View Migration Statistics

Before or after migration, you can check your EDD data summary:

```bash
wp fluent_cart_migrator migrate_from_edd --stats
```

This shows order statuses, types, payment gateways, and counts — useful for verifying the migration scope.

---

### View Failed Payment Logs

If any individual orders fail during migration (e.g., due to missing product data), they're logged without stopping the overall process. View them with:

```bash
wp fluent_cart_migrator migrate_from_edd --log
```

---

## Post-Migration Verification {#post-migration-verification}

After migration, verify your data in FluentCart to confirm everything transferred correctly.

### 1. Check Product Count and Details

- Go to **FluentCart → Products** in your WordPress admin
- Verify the product count matches your EDD downloads
- Open a few products and check:
  - Product titles and descriptions
  - Pricing (single or variable)
  - Subscription billing intervals
  - Bundle product child items

### 2. Check Order Count and Totals

- Go to **FluentCart → Orders**
- Compare the total order count with your EDD orders
- Open several orders and verify:
  - Order total amounts
  - Line items and quantities
  - Payment status (paid, refunded, etc.)
  - Customer name and email
  - Billing address
  - Payment gateway and transaction IDs
  - Order notes/activity log

### 3. Verify Subscriptions

- Go to **FluentCart → Subscriptions**
- Check that subscription statuses match EDD:
  - Active subscriptions show as **active**
  - Cancelled subscriptions show as **cancelled** with correct cancellation date
  - Expired subscriptions show as **expired**
- Open a few subscriptions and verify:
  - Recurring amount
  - Billing interval (monthly, yearly, etc.)
  - Next renewal date
  - Subscription start date
  - Gateway subscription ID (Stripe/PayPal)
  - Bill count (number of payments made)

### 4. Verify Software Licenses

- Go to **FluentCart → Licenses**
- Check that license keys match your EDD license records
- Verify:
  - License status (active, expired, disabled)
  - Activation limit
  - Active site URLs
  - Expiration dates
  - Associated product and order

### 5. Verify Coupon Codes

- Go to **FluentCart → Coupons**
- Check that all discount codes are present with correct:
  - Discount type and amount
  - Usage limits
  - Expiration dates
  - Product restrictions

### 6. Verify Customer Records

- Go to **FluentCart → Customers**
- Open a few customer profiles and check:
  - Name and email
  - WordPress user association
  - Lifetime value (LTV)
  - Purchase count
  - Order history

### 7. Spot-Check Specific Records

Pick 3-5 orders from EDD that represent different scenarios:
- A simple one-time purchase
- A subscription order with renewals
- An order with a coupon code applied
- A refunded or partially refunded order
- A license-based purchase

Compare each one field-by-field between EDD and FluentCart to confirm accuracy.

---

## Backward Compatibility — What's Handled Automatically {#backward-compatibility}

One of the biggest concerns when switching ecommerce platforms is breaking existing integrations. The FluentCart Migrator plugin includes **built-in backward compatibility** that handles this seamlessly. You do not need to configure anything — it works automatically once the migrator plugin is active.

### EDD Software Licensing API Compatibility

If you sell software/plugins with license keys, your customers' products make API calls to your site to verify licenses and check for updates. These API calls use EDD's endpoint format:

```
https://yoursite.com/?edd_action=activate_license&license=KEY&item_id=123&url=https://customer-site.com
```

**The migrator plugin intercepts all EDD licensing API requests and routes them through FluentCart's licensing system.** Your customers' software will continue working without any changes on their end.

Supported EDD API actions:
- **`activate_license`** — Activates a license key for a customer's site
- **`deactivate_license`** — Deactivates a license from a site
- **`check_license`** — Checks if a license is valid, expired, or at its activation limit
- **`get_version`** — Returns the latest version info and download URL for auto-updates

The responses are formatted in the exact EDD API format, so existing plugins and themes using your license system will not notice any change.

### PayPal Standard (IPN) Backward Compatibility

If you have active PayPal Standard subscriptions, PayPal sends Instant Payment Notifications (IPN) to your site when subscription payments are received. EDD used this endpoint:

```
https://yoursite.com/?edd-listener=IPN
```

**The migrator plugin intercepts PayPal IPN requests** and processes `subscr_payment` notifications through FluentCart's subscription system. When PayPal processes a recurring payment:

1. The IPN request arrives at `?edd-listener=IPN`
2. The migrator identifies the subscription by the PayPal subscriber ID or order reference
3. FluentCart's subscription record is updated with the renewal payment
4. The subscription is synced from the payment gateway

This means **active PayPal Standard subscriptions will continue to renew correctly** without any intervention.

### Stripe Webhook Backward Compatibility

For Stripe payments, the migrator handles a common scenario: EDD stored Stripe **Charge IDs** as transaction references, but FluentCart uses **Payment Intent IDs**. When Stripe sends a webhook with a charge ID that doesn't match any FluentCart transaction:

1. The migrator looks up the charge ID in migrated transaction records
2. Updates the transaction reference to the Payment Intent ID
3. Future webhooks work normally

This is a **one-time, automatic correction** that happens the first time Stripe sends an event for a migrated transaction.

### EDD Renewal URL Redirects

If customers have bookmarked or been emailed EDD renewal URLs like:

```
https://yoursite.com/?edd_license_key=KEY&download_id=123
```

**The migrator automatically redirects these to FluentCart's renewal page.** It looks up the license, finds the associated subscription, and sends the customer to the correct FluentCart renewal/reactivation page.

### EDD Package Download URLs

If your software update system uses EDD's package download endpoint (`edd-sl/package_download`), the migrator intercepts these requests and routes them through FluentCart's download system, ensuring software updates continue to work.

---

## What Needs Manual Attention {#what-needs-manual-attention}

While the migration covers the vast majority of your data, a few things require manual setup after migration:

### 1. Payment Gateway Configuration

You need to configure your payment gateways (Stripe, PayPal) in FluentCart's settings. The migration transfers transaction data, but gateway API credentials need to be set up fresh in FluentCart.

- Go to **FluentCart → Settings → Payment Gateways**
- Add your Stripe API keys
- Add your PayPal credentials
- Enable the gateways you use

**Important**: Use the same Stripe and PayPal accounts as your EDD setup. This ensures existing subscriptions and future webhooks are processed correctly.

### 2. Store Settings

Review and configure your FluentCart store settings:
- Currency and currency position
- Tax settings (the migrator transfers tax rates, but verify the tax calculation mode)
- Email notification templates
- Checkout page settings

### 3. Download Files

Product download files need to be configured in FluentCart. The migration creates product records, but you'll need to ensure download files are attached to their respective products in FluentCart.

### 4. Email Templates

FluentCart has its own email notification system. Customize your:
- Purchase receipt emails
- Subscription renewal reminders
- License expiration notices
- Refund notifications

### 5. Website Integration

Update your site to use FluentCart's checkout:
- Replace EDD shortcodes with FluentCart's purchase buttons or checkout blocks
- Update any custom templates that referenced EDD functions
- Update menu links if your account/purchase history pages have changed

### 6. Deactivate EDD (After Verification)

Once you've verified everything is working correctly:

1. **Keep the FluentCart Migrator plugin active** — it provides the backward compatibility layer for existing licensing API calls, PayPal IPN, and legacy URLs
2. **Deactivate Easy Digital Downloads** — FluentCart is now handling everything
3. You can deactivate EDD add-ons (Recurring Payments, Software Licensing, etc.) as well

**Do not delete EDD data until you're fully confident in the migration.** Your EDD database tables remain untouched — the migrator only reads from them, never modifies them.

---

## Frequently Asked Questions {#frequently-asked-questions}

### Will my existing customers need to do anything?

No. Customer accounts, order history, subscriptions, and licenses are all migrated. Customers can log in with their existing WordPress credentials and see their full history in FluentCart.

### Will active subscriptions keep renewing?

Yes. Subscription records are migrated with their gateway subscription IDs (Stripe/PayPal). As long as you configure the same gateway accounts in FluentCart, renewals will continue processing.

For **PayPal Standard** subscriptions specifically, the migrator plugin provides IPN backward compatibility, so those will keep working too.

### Will my customers' software licenses keep working?

Yes. The migrator plugin intercepts all EDD licensing API calls (`?edd_action=activate_license`, `check_license`, etc.) and routes them through FluentCart. Your customers do not need to update their software — license checks, activations, deactivations, and auto-updates all continue working.

### How long does the migration take?

It depends on your data size. Rough estimates:
- **Under 5,000 orders**: A few minutes
- **5,000 - 25,000 orders**: 5-15 minutes
- **25,000 - 100,000 orders**: 15-45 minutes

The migration processes orders in batches of 1,000 with a progress bar, so you always know the status.

### Can I run the migration on a staging site first?

Absolutely — and we recommend it. Clone your production site to a staging environment, run the migration there, and verify everything before migrating production.

### What if the migration is interrupted?

The migration supports automatic resume. Just run the same command again and it will pick up where it left off. Completed steps (products, coupons) are skipped, and order migration resumes from the last processed batch.

### Does the migration modify my EDD data?

No. The migration only **reads** from EDD database tables. Your EDD data remains completely untouched. You can run EDD and FluentCart side by side during your transition period.

### Can I reset and re-run the migration?

Yes. If you need to start fresh:

```bash
wp fluent_cart_migrator migrate_from_edd --reset
```

This clears all migrated FluentCart data and resets the migration progress, allowing you to run it again from scratch. This is useful during testing on a staging site.

### What EDD order statuses are migrated?

The migrator handles these EDD order statuses:

| EDD Status | FluentCart Status |
|---|---|
| `complete` | Paid |
| `processing` | Paid |
| `edd_subscription` | Paid |
| `publish` | Paid |
| `pending` | Pending |
| `refunded` | Refunded |
| `partially_refunded` | Partially Refunded |
| `revoked` | Failed |
| `failed` | Failed |
| `cancelled` | Failed |

Abandoned and trashed orders are not migrated.

### What subscription statuses are migrated?

| EDD Status | FluentCart Status |
|---|---|
| `active` | Active |
| `cancelled` | Cancelled |
| `expired` | Expired |
| `completed` | Completed |
| `pending` | Pending |
| `failing` | Failing |
| `needs_attention` | Failing |
| `trialling` | Trialling |

---

## Troubleshooting {#troubleshooting}

### "Easy Digital Downloads plugin not found"

Make sure EDD is installed and activated. The migrator requires EDD to be active to read its data.

### "FluentCart Pro is required to migrate EDD License data"

If you use EDD Software Licensing, you need FluentCart Pro installed and activated. The migrator will automatically enable the Licensing module in FluentCart Pro.

### "Product not found" errors in logs

This can happen if an order references a product that was deleted from EDD. The order is skipped, and you can review skipped orders in the migration log:

```bash
wp fluent_cart_migrator migrate_from_edd --log
```

### Migration seems stuck

The migration processes large batches and may appear paused during complex orders. If it's truly stuck:
1. Press `Ctrl+C` to stop
2. Re-run the command — it will resume from the last completed batch

### High memory usage

For very large stores, the migrator clears its internal caches after each batch of 1,000 orders to manage memory. If you still experience memory issues, increase PHP's memory limit:

```bash
wp fluent_cart_migrator migrate_from_edd --all --allow-root
```

Or set the memory limit in `wp-config.php`:
```php
define('WP_MEMORY_LIMIT', '512M');
```

---

## Ready to Migrate?

FluentCart is built as a modern, high-performance ecommerce engine for WordPress — purpose-built for digital products, subscriptions, and software licensing. With the migrator plugin, the transition from EDD is smooth and complete: your customer data, order history, subscriptions, licenses, and even existing API integrations all carry over.

Here's your migration checklist:

- [ ] Back up your database
- [ ] Install and activate FluentCart (and FluentCart Pro if using licensing)
- [ ] Install the [FluentCart Migrator plugin](https://github.com/fluent-cart/fluent-cart-migrator) from GitHub
- [ ] Run `wp fluent_cart_migrator migrate_from_edd --all`
- [ ] Configure payment gateways in FluentCart (same Stripe/PayPal accounts)
- [ ] Verify products, orders, subscriptions, licenses, and customers
- [ ] Set up email templates and checkout pages
- [ ] Update your site's purchase buttons and links
- [ ] Deactivate EDD (keep the migrator plugin active for backward compatibility)
- [ ] You're live on FluentCart!

If you have questions or run into issues, reach out to the FluentCart team — we're here to help make your migration successful.
