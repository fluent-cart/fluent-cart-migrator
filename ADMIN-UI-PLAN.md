# FluentCart Migrator — Admin UI Plan

## Context

The `fluent-cart-migrator` plugin currently only works via WP-CLI. We need an admin UI so non-technical users can run migrations from the browser.

**UX approach:** Onboarding-style wizard — a clean, step-by-step flow that guides the user from source selection through to completion. Each step is a full-page view with clear heading, content, and a single primary action. No tabs, no sidebars, no complex navigation. Think "setup wizard" not "settings page".

**Hard constraint:** EDD versions earlier than 3.0 are NOT supported. The wizard blocks migration with a clear message if EDD < 3 is detected.

**Technical:** The UI lives entirely in the migrator plugin (own page slug, own assets — FluentCart does NOT carry migrator files). PHP base page with Vue 3 injected for interactivity. Batched AJAX processing with user-configurable batch sizes. Multi-source ready (EDD now, WooCommerce/etc. later).

---

## Step 1: File Structure

New files inside `fluent-cart-migrator/`:

```
Classes/Admin/
  AdminMenu.php          — Page registration, submenu hook, asset enqueue
  RestApi.php            — REST route registration + handlers
  MigratorService.php    — Wraps CLI logic for web use (returns arrays, no WP_CLI output)
views/
  admin-page.php         — PHP template for the admin page
assets/
  css/migrator-app.css   — Minimal styles (progress bars, step indicators)
  js/migrator-app.js     — Vue 3 app (single entry point, built with Vite)
vite.config.mjs          — Minimal Vite config for building the Vue app
package.json             — Vue 3 + Vite dev deps (migrator-specific, not FC's)
```

---

## Step 2: Bootstrap — Modify `fluent-cart-migrator.php`

Add admin + REST loading to `FluentCartMigrator::init()`:

```php
public function init()
{
    // Existing: CLI
    if (defined('WP_CLI') && WP_CLI) {
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/Commands.php';
        \WP_CLI::add_command('fluent_cart_migrator', ...);
    }

    // NEW: Admin page (only in admin context)
    if (is_admin()) {
        require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/Admin/AdminMenu.php';
        (new \FluentCartMigrator\Classes\Admin\AdminMenu())->register();
    }

    // NEW: REST API (always — REST requests don't pass is_admin())
    require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/Admin/RestApi.php';
    require_once FLUENTCART_MIGRATOR_PLUGIN_PATH . 'Classes/Admin/MigratorService.php';
    (new \FluentCartMigrator\Classes\Admin\RestApi())->register();

    $this->handleEddLegacyLicenses();
}
```

---

## Step 3: Admin Page Registration — `Classes/Admin/AdminMenu.php`

**Menu integration:** Use `fluent_cart/admin_submenu_added` to add "Migrator" link under FluentCart's menu.
**Page slug:** `fluent-cart-migrator` (standalone page via `add_submenu_page(null, ...)`).

Key methods:
- `register()` — hooks into `fluent_cart/admin_submenu_added`, `admin_menu`, `admin_enqueue_scripts`
- `addMigratorSubmenu($submenu)` — adds entry to `$submenu['fluent-cart']` pointing to `admin.php?page=fluent-cart-migrator`
- `registerStandalonePage()` — `add_submenu_page(null, ...)` with `manage_options` capability
- `renderPage()` — loads `views/admin-page.php`
- `enqueueAssets($hook)` — only on our page; enqueues CSS/JS, calls `wp_localize_script` with:
  - `restUrl` — `rest_url('fct-migrator/v1/')`
  - `nonce` — `wp_create_nonce('wp_rest')`
  - `migration` — current `__fluent_cart_edd3_migration_steps` option

Reference pattern: FC's `MenuHandler.php:150-284` for submenu structure (array format: `[label, capability, url, '', slug]`).

---

## Step 4: PHP Template — `views/admin-page.php`

Minimal shell — Vue takes over `#fct-migrator-app`:

```php
<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap" id="fct-migrator-wrap">
    <h1><?php esc_html_e('FluentCart Migrator', 'fluent-cart-migrator'); ?></h1>
    <hr class="wp-header-end">
    <div id="fct-migrator-app">
        <p><?php esc_html_e('Loading migrator...', 'fluent-cart-migrator'); ?></p>
    </div>
</div>
```

---

## Step 5: REST API — `Classes/Admin/RestApi.php`

Namespace: `fct-migrator/v1`. All endpoints require `manage_options`.

| Method | Route | Purpose | Delegates to |
|--------|-------|---------|-------------|
| GET | `/sources` | List available sources (EDD detected?, WooCommerce?) | `MigratorService::getSources()` |
| GET | `/stats/{source}` | Pre-migration stats (counts, gateways, statuses) | `MigratorService::getEddStats()` |
| GET | `/status` | Current migration state + failed log counts | `MigratorService::getStatus()` |
| POST | `/migrate/products` | Migrate all products (single batch) | `MigratorService::migrateProducts()` |
| POST | `/migrate/coupons` | Migrate all coupons (single batch) | `MigratorService::migrateCoupons()` |
| POST | `/migrate/payments` | Migrate one page of payments | `MigratorService::migratePayments($page, $perPage)` |
| POST | `/migrate/recount` | Run recount for a substep | `MigratorService::recountStats($substep)` |
| GET | `/logs` | Get failed payment migration logs | returns `_fluent_edd_failed_payment_logs` option |
| POST | `/reset` | Reset migration state | `MigratorService::resetMigration()` |

POST `/migrate/payments` params: `page` (int, default 1), `per_page` (int, default 100).
POST `/migrate/recount` params: `substep` (string: `coupons|customers|subscriptions`).

Response format (all POST migrate endpoints):
```json
{
  "success": true,
  "step": "payments",
  "page": 3,
  "processed": 100,
  "has_more": true,
  "errors_in_batch": 2,
  "migration_state": {"products":"yes","coupons":"yes","payments":"no","last_order_page":3}
}
```

---

## Step 6: Service Layer — `Classes/Admin/MigratorService.php`

Wraps existing `MigratorCli` methods, returns data arrays instead of printing to CLI.

Key methods:
- **`getSources()`** — checks `class_exists('Easy_Digital_Downloads')` / `EDD_VERSION` constant / table `edd_orders` exists (EDD 3.x indicator). Returns `[{key: 'edd', name: 'Easy Digital Downloads', detected: true, version: '3.x.x', has_v3_tables: true}, {key: 'woocommerce', name: 'WooCommerce', detected: false, coming_soon: true}]`. Version check: if `EDD_VERSION` < 3.0 or `edd_orders` table missing → `has_v3_tables: false` (migration blocked in UI)
- **`getEddStats()`** — replicates the DB queries from `MigratorCli::stats()` (lines 37-70) but returns an array: `{products_count, orders_count, transactions_count, gateways[], statuses[], types[]}`
- **`getStatus()`** — returns `get_option('__fluent_cart_edd3_migration_steps')` + failed log count
- **`migrateProducts()`** — calls `$eddCli->migrate_products(true)`, counts successes/WP_Errors, returns `{total, success, failed, errors[]}`
- **`migrateCoupons()`** — calls `$eddCli->migrateCouponCodes()`, returns `{total, success}`
- **`migratePayments($page, $perPage)`** — calls `$eddCli->migratePayments($page, $perPage)`, updates `__fluent_cart_edd3_migration_steps` option, returns `{page, processed, has_more, errors_in_batch}`
- **`recountStats($substep)`** — reimplements the recount logic from `Commands.php` (lines 459-621) without WP_CLI dependencies. Each substep (`coupons`, `customers`, `subscriptions`) runs its own paginated loop internally.

---

## Step 7: Guard WP_CLI Calls in Existing Code

~20 unguarded `\WP_CLI::line()` calls in `classes/EDD3/MigratorCli.php` and `classes/EDD3/PaymentMigrate.php` will fatal when called from REST context. Wrap each with:

```php
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::line(...);
}
```

Files and approximate locations:
- `MigratorCli.php`: lines 66-70, 162, 226, 237, 244, 252, 257, 261, 278, 289, 295, 300, 308, 315, 840, 877
- `PaymentMigrate.php`: lines 236, 376, 917

---

## Step 8: Vue Application — `assets/js/migrator-app.js`

Lightweight Vue 3 app with Composition API. No Vue Router — uses a `ref('currentStep')` for onboarding wizard flow. Each step has a header, content area, and forward/back navigation.

### Onboarding Wizard Steps (6 total):

**Step 1: Select Source** (`step: 'source'`)
- Calls `GET /sources` on mount
- Shows cards for each source: EDD (enabled if detected), WooCommerce (Coming Soon), etc.
- Each card shows: icon, name, detection status (installed/not found)
- Click an enabled source card → advance to version check

**Step 2: Version Gate** (`step: 'version'`)
- Data already available from `GET /sources` response: `version` (from `EDD_VERSION`), `has_v3_tables` (checks `edd_orders` table exists — EDD 3.x uses custom tables, EDD 2.x used CPT `edd_payment`)
- Three possible states:
  - **Pass** (`has_v3_tables: true`): Green checkmark icon, "EDD 3.x detected (v{version})" → "Continue" button enabled
  - **Blocked** (`has_v3_tables: false` and EDD active): Red/warning icon, "EDD {version} detected. Migration requires EDD 3.0 or later. Please upgrade EDD first." → **No continue button**, only "Go Back"
  - **Data-only** (tables exist but EDD plugin deactivated): Info icon, "EDD is not active but migration data was found." → "Continue" button enabled

**Step 3: Pre-Migration Overview** (`step: 'overview'`)
- Calls `GET /stats/edd` + `GET /status`
- Shows stats in a summary card layout:
  - Products count
  - Orders count (by status breakdown)
  - Transactions count
  - Customers count
  - Subscriptions count (if `edd_subscriptions` table exists)
  - Licenses count (if `edd_licenses` table exists)
  - Payment gateways found
- If previous migration exists: shows resume banner with completed/pending steps
- "Continue to Configuration" button

**Step 4: Migration Config** (`step: 'config'`)
- Batch size selector: dropdown with 50 / 100 / 250 / 500 / 1000 (default 100)
- Step checkboxes: Products, Coupons, Payments, Recount (all checked by default)
- Already-completed steps show "(Completed)" badge with option to re-run
- "Start Migration" / "Resume Migration" button

**Step 5: Migration Runner** (`step: 'running'`)
- Overall step indicator (step 2 of 4)
- Per-step status: pending → running (with spinner/progress) → completed → error
- For payments: shows page-level progress (`Page 3 of ~50 | 300 orders processed`)
- **Pause button**: sets `isPaused = true`, stops issuing next request
- **Resume button**: continues from where paused
- Error counter: "2 errors so far" (click to expand inline log)
- Each step runs sequentially via `async/await` fetch loop

**Step 6: Completion** (`step: 'complete'`)
- Per-step results: success/failure counts
- Duration
- Expandable error log (from `GET /logs`)
- "View FluentCart Dashboard" link → `admin.php?page=fluent-cart#/`
- "Run Again" button → resets to config step

### HTTP helper:
```js
async function apiRequest(method, path, data = {}) {
    const res = await fetch(fctMigrator.restUrl + path, {
        method,
        headers: { 'X-WP-Nonce': fctMigrator.nonce, 'Content-Type': 'application/json' },
        body: method === 'GET' ? undefined : JSON.stringify(data)
    });
    return res.json();
}
```

### Batch loop for payments:
```js
async function runPaymentBatches(batchSize) {
    let page = migrationState.last_order_page || 1;
    let hasMore = true;
    while (hasMore && !isPaused.value) {
        const result = await apiRequest('POST', 'migrate/payments', { page, per_page: batchSize });
        hasMore = result.has_more;
        page++;
        updateProgress(result);
    }
}
```

---

## Step 9: Vite Build Config

Minimal `vite.config.mjs` in `fluent-cart-migrator/`:

```js
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    build: {
        outDir: 'assets/build',
        rollupOptions: {
            input: 'assets/js/migrator-app.js',
            output: {
                entryFileNames: 'migrator-app.js',
                assetFileNames: 'migrator-app.[ext]'
            }
        }
    }
});
```

`AdminMenu::enqueueAssets()` loads from `assets/build/migrator-app.js` (production) with `type="module"` attribute.

---

## Files Summary

| File | Action |
|------|--------|
| `fluent-cart-migrator.php` | **Modify** — add admin + REST loading in `init()` |
| `Classes/Admin/AdminMenu.php` | **Create** — page registration, submenu hook, asset enqueue |
| `Classes/Admin/RestApi.php` | **Create** — REST route registration + handlers |
| `Classes/Admin/MigratorService.php` | **Create** — web-safe wrappers around CLI migration logic |
| `views/admin-page.php` | **Create** — minimal PHP template |
| `assets/js/migrator-app.js` | **Create** — Vue 3 app (source) |
| `assets/css/migrator-app.css` | **Create** — minimal styles |
| `vite.config.mjs` | **Create** — Vue build config |
| `package.json` | **Create** — Vue 3 + Vite deps |
| `classes/EDD3/MigratorCli.php` | **Modify** — guard ~16 `\WP_CLI::line()` calls |
| `classes/EDD3/PaymentMigrate.php` | **Modify** — guard ~3 `\WP_CLI::line()` calls |

---

## Implementation Order

```
1. Guard WP_CLI calls in existing files (prerequisite for web use)
2. Create AdminMenu.php + views/admin-page.php (get the page showing)
3. Create RestApi.php + MigratorService.php (all endpoints working)
4. Modify fluent-cart-migrator.php to load new classes
5. Test REST endpoints via browser/Postman
6. Set up Vite build (package.json + vite.config.mjs)
7. Build Vue app screen by screen: Source → Version → Overview → Config → Runner → Complete
8. Polish: error handling, CSS, resume flow, large dataset testing
```

---

## Verification

1. Activate fluent-cart + fluent-cart-migrator → "Migrator" appears in FluentCart admin menu
2. Visit `admin.php?page=fluent-cart-migrator` → page loads, Vue mounts
3. Source selector shows EDD as detected (if EDD data exists)
4. EDD < 3.0 → version gate blocks with "Please upgrade EDD first"
5. EDD 3.x → stats screen shows correct counts
6. Run migration with batch size 50 → products, coupons, payments migrate in batches
7. Pause mid-payment-batch → refresh page → resume picks up at correct page
8. Completion screen shows accurate counts, error log is viewable
9. CLI migration still works independently (`wp fluent_cart_migrator migrate_from_edd --all`)
