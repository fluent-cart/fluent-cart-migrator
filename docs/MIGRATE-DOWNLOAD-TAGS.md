# Migrating EDD `download_tag` Terms to FluentCart

FluentCart ships **Categories** and **Brands** only — there is no tag taxonomy
out of the box. To migrate EDD's `download_tag` terms you therefore need to do
two things: register a taxonomy for them, and point the migration at it.

> **The copying is now built in.** Since the taxonomy mapping feature, the
> migrator's **Overview → Taxonomy Mapping** card lists every taxonomy found on
> your EDD downloads next to every taxonomy registered on `fluent-products`.
> Once `product-tags` exists (step 1 below), pick it opposite `download_tag`
> and the products step copies the terms and re-attaches them — no snippet
> needed. The same is available on the CLI:
>
> ```bash
> # show what can be mapped
> wp fluent_cart_migrator migrate_from_edd --taxonomy_map
> # set the mapping
> wp fluent_cart_migrator migrate_from_edd --taxonomy_map --set=download_category:product-categories,download_tag:product-tags
> # apply it (also runs as part of --all)
> wp fluent_cart_migrator migrate_from_edd --taxonomies
> ```
>
> This works on an already-finished migration too: **Product Taxonomies** is a
> normal migration step, and saving a changed mapping re-opens it, so the next
> run applies the tags to the products already in FluentCart. Step 2 below is
> kept only as a manual alternative.

---

## 1. Register the `product-tags` taxonomy

Drop the snippet below into either:

- a **must-use plugin** at `wp-content/mu-plugins/fluent-cart-product-tags.php`, or
- your active theme's `functions.php`, or
- a small custom plugin file.

```php
<?php
/**
 * Register a "Product Tags" taxonomy for FluentCart products.
 */
add_action('init', function () {
    register_taxonomy('product-tags', 'fluent-products', [
        'hierarchical'      => false,
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'query_var'         => true,
        'rewrite'           => ['slug' => 'product-tags'],
        'labels'            => [
            'name'              => __('Tags', 'fluent-cart'),
            'singular_name'     => __('Tag', 'fluent-cart'),
            'search_items'      => __('Search Tags', 'fluent-cart'),
            'all_items'         => __('All Tags', 'fluent-cart'),
            'edit_item'         => __('Edit Tag', 'fluent-cart'),
            'update_item'       => __('Update Tag', 'fluent-cart'),
            'add_new_item'      => __('Add New Tag', 'fluent-cart'),
            'new_item_name'     => __('New Tag Name', 'fluent-cart'),
            'menu_name'         => __('Product Tag', 'fluent-cart'),
            'not_found'         => __('No tags found.', 'fluent-cart'),
            'separate_items_with_commas' => __('Separate tags with commas', 'fluent-cart'),
            'add_or_remove_items'        => __('Add or remove tags', 'fluent-cart'),
            'choose_from_most_used'      => __('Choose from the most used tags', 'fluent-cart'),
        ],
    ]);
}, 11);
```

> **Note**: The priority `11` ensures this runs *after* FluentCart's built-in
> `product-categories` / `product-brands` registrations (which use the default
> priority of `10`).

After saving the file, visit any admin page once so WordPress flushes its
rewrite cache.

---

## 2. (Optional) Backfill tags after the migration has already run

Only needed if you migrated before mapping `download_tag`, and you would rather
not re-run the products step. The function below:

1. Iterates every published EDD `download`.
2. Reads the EDD download's `download_tag` terms.
3. Creates a matching `product-tags` term (same name + slug) if missing.
4. Reads the `_fcart_migrated_id` meta to find the FluentCart product.
5. Attaches the tags to the FluentCart product (append-only, won't remove
   existing ones).

It is **idempotent** — you can safely run it multiple times.

### 2a. PHP function (callable from anywhere)

Add this alongside the taxonomy snippet from Step 1:

```php
<?php
/**
 * Migrate EDD download_tag terms to FluentCart product-tags.
 *
 * @param array $args {
 *     @type bool $dry_run  If true, only reports what would change.
 *     @type int  $batch    Number of EDD downloads per batch (default 200).
 * }
 * @return array Summary: ['products_processed', 'tags_created', 'tags_assigned', 'skipped'].
 */
function fcct_migrate_download_tags(array $args = []): array
{
    $args = array_merge([
        'dry_run' => false,
        'batch'   => 200,
    ], $args);

    if (!taxonomy_exists('download_tag')) {
        return ['error' => 'EDD download_tag taxonomy is not registered. Activate EDD or run this before deactivating it.'];
    }

    if (!taxonomy_exists('product-tags')) {
        return ['error' => 'product-tags taxonomy is not registered. See step 1 of this guide.'];
    }

    $summary = [
        'products_processed' => 0,
        'tags_created'       => 0,
        'tags_assigned'      => 0,
        'skipped_no_map'     => 0,
        'skipped_no_tags'    => 0,
        'dry_run'            => $args['dry_run'],
    ];

    $paged = 1;
    do {
        $downloads = get_posts([
            'post_type'        => 'download',
            'post_status'      => 'any',
            'posts_per_page'   => $args['batch'],
            'paged'            => $paged,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'orderby'          => 'ID',
            'order'            => 'ASC',
        ]);

        if (empty($downloads)) {
            break;
        }

        foreach ($downloads as $eddId) {
            $summary['products_processed']++;

            $fctId = (int) get_post_meta($eddId, '_fcart_migrated_id', true);
            if (!$fctId || get_post_type($fctId) !== 'fluent-products') {
                $summary['skipped_no_map']++;
                continue;
            }

            $eddTags = get_the_terms($eddId, 'download_tag');
            if (!$eddTags || is_wp_error($eddTags)) {
                $summary['skipped_no_tags']++;
                continue;
            }

            $fctTermIds = [];
            foreach ($eddTags as $tag) {
                $existing = get_term_by('slug', $tag->slug, 'product-tags');
                if ($existing) {
                    $fctTermIds[] = (int) $existing->term_id;
                    continue;
                }

                if ($args['dry_run']) {
                    $summary['tags_created']++;
                    continue;
                }

                $created = wp_insert_term($tag->name, 'product-tags', [
                    'slug'        => $tag->slug,
                    'description' => $tag->description,
                ]);

                if (is_wp_error($created)) {
                    // If insert fails (e.g. slug collision), look it up again.
                    $fallback = get_term_by('slug', $tag->slug, 'product-tags');
                    if ($fallback) {
                        $fctTermIds[] = (int) $fallback->term_id;
                    }
                    continue;
                }

                $summary['tags_created']++;
                $fctTermIds[] = (int) $created['term_id'];
            }

            if (!$fctTermIds) {
                continue;
            }

            if ($args['dry_run']) {
                $summary['tags_assigned'] += count($fctTermIds);
                continue;
            }

            // append = true so we don't remove anything previously assigned.
            $set = wp_set_object_terms($fctId, $fctTermIds, 'product-tags', true);
            if (!is_wp_error($set)) {
                $summary['tags_assigned'] += count($fctTermIds);
            }
        }

        $paged++;
    } while (count($downloads) === (int) $args['batch']);

    return $summary;
}
```

Call it from anywhere — e.g. a one-off snippet, a custom admin page, or the
WP-CLI `eval` wrapper:

```bash
# Dry run (no writes):
wp eval 'print_r(fcct_migrate_download_tags(["dry_run" => true]));'

# Real run:
wp eval 'print_r(fcct_migrate_download_tags());'
```

### 2b. WP-CLI command (optional)

If you'd like a dedicated command, register it next to the function:

```php
<?php
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('fcct migrate-tags', function ($args, $assoc_args) {
        $result = fcct_migrate_download_tags([
            'dry_run' => isset($assoc_args['dry-run']),
            'batch'   => (int) ($assoc_args['batch'] ?? 200),
        ]);

        if (isset($result['error'])) {
            WP_CLI::error($result['error']);
        }

        foreach ($result as $key => $value) {
            WP_CLI::line(sprintf('%-22s %s', $key . ':', is_bool($value) ? ($value ? 'yes' : 'no') : $value));
        }

        WP_CLI::success('Done.');
    });
}
```

Then run:

```bash
# Preview:
wp fcct migrate-tags --dry-run

# Real run:
wp fcct migrate-tags

# Adjust batch size (helpful on very large stores):
wp fcct migrate-tags --batch=500
```

---

## 3. Verify the result

```bash
# Count product-tags terms:
wp term list product-tags --format=count

# List tags attached to a single FluentCart product:
wp post term list <fluent-product-id> product-tags
```

Or visit any product in **WP Admin → FluentCart → Products** — the new
**Tags** meta box will appear in the sidebar.

---

## 4. Re-running / cleanup

- The script is **append-only and idempotent**. Re-running it will not create
  duplicate terms or duplicate term assignments.
- To remove all migrated tags (start over):
  ```bash
  wp term list product-tags --field=term_id | xargs -I{} wp term delete product-tags {}
  ```
- The `download_tag` terms on EDD downloads are **never modified** — this is a
  copy, not a move.

---

## Requirements

- The standard FluentCart Migrator "Migrate Products" step must have completed
  successfully (so `_fcart_migrated_id` postmeta exists on EDD downloads).
- EDD must be active while you migrate (or run the step 2 script) — that is
  where the `download_tag` taxonomy and its terms come from. Once the terms are
  in FluentCart, EDD can be deactivated.
