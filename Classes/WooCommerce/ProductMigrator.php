<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCart\App\CPT\FluentProducts;

/**
 * Migrates WooCommerce products into FluentCart.
 *
 * Reads through the WooCommerce CRUD API (wc_get_product / WC_Product) so it is
 * agnostic to storage details. Each WC product becomes:
 *   - one fct_product (CPT) post
 *   - one fct_product_details row
 *   - one fct_product_variations row (simple) or one per variation (variable)
 *   - fct_product_downloads rows for downloadable files
 *   - product_cat terms mapped to the FluentCart 'product-categories' taxonomy
 *
 * Idempotent: a WC product already carrying MigratorHelper::WC_TO_FCT_META is
 * updated in place rather than duplicated.
 */
class ProductMigrator
{
    /**
     * WC term id => FC term id, built once per run.
     */
    private $categoryMap = null;

    /**
     * @return array<int, int|\WP_Error> wcProductId => fctPostId|WP_Error
     */
    public function migrate($willUpdate = true)
    {
        $this->prepareCategoryMap();

        $productIds = wc_get_products([
            'limit'   => -1,
            'return'  => 'ids',
            'status'  => ['publish', 'private', 'draft'],
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);

        $results = [];
        foreach ($productIds as $productId) {
            $results[$productId] = $this->migrateProduct((int) $productId, $willUpdate);
        }

        return $results;
    }

    public function migrateProduct($wcProductId, $willUpdate = true)
    {
        $product = wc_get_product($wcProductId);

        if (!$product || $product->get_type() === 'variation') {
            return new \WP_Error('woo_migrator_error', 'Product not found: ' . $wcProductId);
        }

        $createdAt = MigratorHelper::date($product->get_date_created());

        $postData = [
            'post_title'        => $product->get_name(),
            'post_content'      => $product->get_description(),
            'post_excerpt'      => $product->get_short_description(),
            'post_status'       => $this->postStatus($product->get_status()),
            'post_type'         => FluentProducts::CPT_NAME,
            'post_name'         => $product->get_slug(),
            'post_date'         => $createdAt,
            'post_date_gmt'     => $createdAt,
            'post_modified'     => current_time('mysql'),
            'post_modified_gmt' => gmdate('Y-m-d H:i:s'),
        ];

        // Idempotency: update the previously migrated post when re-running.
        $createdPostId = null;
        $isUpdate      = false;
        if ($willUpdate) {
            $existingId = (int) get_post_meta($wcProductId, MigratorHelper::WC_TO_FCT_META, true);
            if ($existingId && get_post($existingId) && get_post_type($existingId) === FluentProducts::CPT_NAME) {
                wp_update_post(array_merge($postData, ['ID' => $existingId]));
                $createdPostId = $existingId;
                $isUpdate      = true;
            }
        }

        if (!$createdPostId) {
            $createdPostId = wp_insert_post($postData);
        }

        if (is_wp_error($createdPostId) || !$createdPostId) {
            return new \WP_Error('woo_migrator_error', 'Failed to create product post for WC #' . $wcProductId);
        }

        update_post_meta($createdPostId, MigratorHelper::FCT_FROM_WC_META, $wcProductId);
        update_post_meta($wcProductId, MigratorHelper::WC_TO_FCT_META, $createdPostId);

        $this->assignCategories($product, (int) $createdPostId);

        $thumbnailId = $product->get_image_id();
        if ($thumbnailId) {
            set_post_thumbnail($createdPostId, $thumbnailId);
        }

        if ($isUpdate) {
            $this->cleanupExisting((int) $createdPostId);
        }

        $isVariable = $product->is_type(['variable', 'variable-subscription']);

        $variationRows = $isVariable
            ? $this->buildVariableVariations($product, $createdPostId, $createdAt)
            : $this->buildSimpleVariation($product, $createdPostId, $createdAt);

        $createdVariations = []; // wcVariationId => fctVariationId
        $prices            = [];
        $fulfillments      = [];
        foreach ($variationRows as $identifier => $row) {
            $downloads = $row['_downloads'];
            unset($row['_downloads']);

            $prices[]       = $row['item_price'];
            $fulfillments[] = $row['fulfillment_type'];

            $variationId = fluentCart('db')->table('fct_product_variations')->insertGetId($row);
            $createdVariations[$identifier] = $variationId;

            $this->insertDownloads($createdPostId, $variationId, $downloads, $createdAt);
        }

        update_post_meta($createdPostId, MigratorHelper::VARIATION_MAP_META, $createdVariations);

        $this->insertProductDetail($product, $createdPostId, $createdVariations, $prices, $fulfillments, $isVariable, $createdAt);

        return (int) $createdPostId;
    }

    /* -----------------------------------------------------------------
     | Variations
     * ----------------------------------------------------------------- */

    private function buildSimpleVariation($product, $postId, $createdAt)
    {
        $regular  = MigratorHelper::toCents($product->get_regular_price());
        $price    = MigratorHelper::toCents($product->get_price() !== '' ? $product->get_price() : $product->get_regular_price());

        $row = [
            'post_id'              => $postId,
            'media_id'             => $product->get_image_id() ?: null,
            'serial_index'         => 1,
            'variation_title'      => '',
            'variation_identifier' => '0',
            'sku'                  => MigratorHelper::uniqueSku($product->get_sku()),
            'manage_stock'         => $product->get_manage_stock() ? 1 : 0,
            'payment_type'         => 'onetime',
            'stock_status'         => MigratorHelper::stockStatus($product->get_stock_status()),
            'backorders'           => $product->get_backorders() !== 'no' ? 1 : 0,
            'total_stock'          => $product->get_stock_quantity(),
            'available'            => (int) $product->get_stock_quantity(),
            'committed'            => 0,
            'on_hold'              => 0,
            'fulfillment_type'     => MigratorHelper::fulfillmentType($product),
            'item_status'          => 'active',
            'item_price'           => $price,
            'compare_price'        => $product->is_on_sale() ? $regular : 0,
            'downloadable'         => $product->is_downloadable() ? 1 : 0,
            'other_info'           => json_encode(['payment_type' => 'onetime']),
            'created_at'           => $createdAt,
            'updated_at'           => current_time('mysql'),
            '_downloads'           => $product->is_downloadable() ? $product->get_downloads() : [],
        ];

        return ['0' => $row];
    }

    private function buildVariableVariations($product, $postId, $createdAt)
    {
        $rows  = [];
        $index = 0;

        foreach ($product->get_children() as $variationId) {
            $variation = wc_get_product($variationId);
            if (!$variation) {
                continue;
            }

            $index++;
            $regular = MigratorHelper::toCents($variation->get_regular_price());
            $price   = MigratorHelper::toCents($variation->get_price() !== '' ? $variation->get_price() : $variation->get_regular_price());

            $rows[(string) $variationId] = [
                'post_id'              => $postId,
                'media_id'             => $variation->get_image_id() ?: ($product->get_image_id() ?: null),
                'serial_index'         => $index,
                'variation_title'      => $this->variationTitle($variation),
                'variation_identifier' => (string) $variationId,
                'sku'                  => MigratorHelper::uniqueSku($variation->get_sku()),
                'manage_stock'         => $variation->get_manage_stock() ? 1 : 0,
                'payment_type'         => 'onetime',
                'stock_status'         => MigratorHelper::stockStatus($variation->get_stock_status()),
                'backorders'           => $variation->get_backorders() !== 'no' ? 1 : 0,
                'total_stock'          => $variation->get_stock_quantity(),
                'available'            => (int) $variation->get_stock_quantity(),
                'committed'            => 0,
                'on_hold'              => 0,
                'fulfillment_type'     => MigratorHelper::fulfillmentType($variation),
                'item_status'          => 'active',
                'item_price'           => $price,
                'compare_price'        => $variation->is_on_sale() ? $regular : 0,
                'downloadable'         => $variation->is_downloadable() ? 1 : 0,
                'other_info'           => json_encode(['payment_type' => 'onetime']),
                'created_at'           => $createdAt,
                'updated_at'           => current_time('mysql'),
                '_downloads'           => $variation->is_downloadable() ? $variation->get_downloads() : [],
            ];
        }

        // Fallback: a variable product with no published variations still needs a row.
        if (!$rows) {
            return $this->buildSimpleVariation($product, $postId, $createdAt);
        }

        return $rows;
    }

    private function variationTitle($variation)
    {
        if (method_exists($variation, 'get_attribute_summary')) {
            $summary = $variation->get_attribute_summary();
            if ($summary) {
                return $summary;
            }
        }

        $attributes = $variation->get_variation_attributes();

        return implode(' / ', array_filter(array_values($attributes)));
    }

    /* -----------------------------------------------------------------
     | Product detail
     * ----------------------------------------------------------------- */

    private function insertProductDetail($product, $postId, $createdVariations, $prices, $fulfillments, $isVariable, $createdAt)
    {
        $prices   = array_filter($prices, function ($p) { return $p !== null; });
        $minPrice = $prices ? min($prices) : 0;
        $maxPrice = $prices ? max($prices) : 0;

        $uniqueFulfillments = array_values(array_unique($fulfillments));
        $productFulfillment = count($uniqueFulfillments) === 1 ? $uniqueFulfillments[0] : 'mixed';

        $detail = [
            'post_id'              => $postId,
            'fulfillment_type'     => $productFulfillment,
            'min_price'            => $minPrice,
            'max_price'            => $maxPrice,
            'default_variation_id' => $createdVariations ? reset($createdVariations) : '',
            'default_media'        => json_encode([]),
            'manage_stock'         => $product->get_manage_stock() ? 1 : 0,
            'stock_availability'   => MigratorHelper::stockStatus($product->get_stock_status()),
            'variation_type'       => $isVariable ? 'simple_variations' : 'simple',
            'manage_downloadable'  => $product->is_downloadable() ? 1 : 0,
            'other_info'           => json_encode([
                'group_pricing_by'  => 'repeat_interval',
                'use_pricing_table' => 'yes',
            ]),
            'created_at'           => $createdAt,
            'updated_at'           => gmdate('Y-m-d H:i:s'),
        ];

        fluentCart('db')->table('fct_product_details')->insertGetId($detail);
    }

    /* -----------------------------------------------------------------
     | Downloads
     * ----------------------------------------------------------------- */

    private function insertDownloads($postId, $variationId, $downloads, $createdAt)
    {
        if (!$downloads) {
            return;
        }

        $serial = 0;
        foreach ($downloads as $download) {
            $serial++;
            $file = $download->get_file();
            if (!$file) {
                continue;
            }

            $isUrl    = (bool) preg_match('#^https?://#i', $file);
            $name     = $download->get_name() ?: basename(parse_url($file, PHP_URL_PATH) ?: $file);
            $ext      = pathinfo(parse_url($file, PHP_URL_PATH) ?: $file, PATHINFO_EXTENSION);

            fluentCart('db')->table('fct_product_downloads')->insertGetId([
                'download_identifier'  => md5($file . '_' . $postId . '_' . $variationId . '_' . $serial . '_' . wp_generate_uuid4()),
                'post_id'              => $postId,
                // Empty array = applies to all variations; a specific id scopes it.
                'product_variation_id' => json_encode($variationId ? [(int) $variationId] : []),
                'driver'               => $isUrl ? 'url' : 'local',
                'title'                => $name,
                'type'                 => $ext,
                'file_name'            => $name,
                'file_path'            => $file,
                'file_url'             => $file,
                'serial'               => $serial,
                'settings'             => json_encode([
                    'download_limit'  => '',
                    'download_expiry' => '',
                ]),
                'created_at'           => $createdAt,
                'updated_at'           => current_time('mysql'),
            ]);
        }
    }

    /* -----------------------------------------------------------------
     | Categories
     * ----------------------------------------------------------------- */

    private function prepareCategoryMap()
    {
        $this->categoryMap = [];

        if (!taxonomy_exists('product_cat') || !taxonomy_exists('product-categories')) {
            return;
        }

        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_wp_error($terms) || !$terms) {
            return;
        }

        $remaining = [];
        foreach ($terms as $term) {
            $remaining[(int) $term->term_id] = $term;
        }

        // Walk parents-first so hierarchy is preserved.
        $guard = 0;
        while ($remaining && $guard++ < 50) {
            $progressed = false;

            foreach ($remaining as $wcId => $term) {
                $parentWcId = (int) $term->parent;
                if ($parentWcId && !isset($this->categoryMap[$parentWcId])) {
                    continue; // wait for parent
                }

                $parentFctId = $parentWcId ? (int) $this->categoryMap[$parentWcId] : 0;

                $existing = get_term_by('slug', $term->slug, 'product-categories');
                if ($existing) {
                    $this->categoryMap[$wcId] = (int) $existing->term_id;
                } else {
                    $created = wp_insert_term($term->name, 'product-categories', [
                        'slug'        => $term->slug,
                        'description' => $term->description,
                        'parent'      => $parentFctId,
                    ]);

                    if (is_wp_error($created)) {
                        $fallback = get_term_by('slug', $term->slug, 'product-categories');
                        if ($fallback) {
                            $this->categoryMap[$wcId] = (int) $fallback->term_id;
                        }
                    } else {
                        $this->categoryMap[$wcId] = (int) $created['term_id'];
                    }
                }

                unset($remaining[$wcId]);
                $progressed = true;
            }

            if (!$progressed) {
                break; // orphaned terms with a missing parent
            }
        }
    }

    private function assignCategories($product, $fctPostId)
    {
        if (empty($this->categoryMap) || !taxonomy_exists('product-categories')) {
            return;
        }

        $fctTermIds = [];
        foreach ($product->get_category_ids() as $wcTermId) {
            if (isset($this->categoryMap[(int) $wcTermId])) {
                $fctTermIds[] = (int) $this->categoryMap[(int) $wcTermId];
            }
        }

        if ($fctTermIds) {
            wp_set_object_terms($fctPostId, $fctTermIds, 'product-categories', false);
        }
    }

    /* -----------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    private function postStatus($wcStatus)
    {
        // WC: publish/draft/private/pending; FluentCart products use publish/draft.
        return in_array($wcStatus, ['publish', 'private'], true) ? 'publish' : 'draft';
    }

    private function cleanupExisting($postId)
    {
        fluentCart('db')->table('fct_product_variations')->where('post_id', $postId)->delete();
        fluentCart('db')->table('fct_product_details')->where('post_id', $postId)->delete();
        fluentCart('db')->table('fct_product_downloads')->where('post_id', $postId)->delete();
    }
}
