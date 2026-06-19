<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCartMigrator\Classes\Dto\ProductData;
use FluentCartMigrator\Classes\Dto\ProductDownloadData;
use FluentCartMigrator\Classes\Dto\ProductVariationData;
use FluentCartMigrator\Classes\Load\AttributeWriter;
use FluentCartMigrator\Classes\Load\CategoryWriter;
use FluentCartMigrator\Classes\Load\ProductWriter;
use FluentCartMigrator\Classes\Support\BatchRuntime;

/**
 * WooCommerce product source: Extract + Transform only.
 *
 * Reads through the WooCommerce CRUD API (wc_get_product / WC_Product), maps
 * each product to a normalized ProductData DTO, and hands it to the shared
 * ProductWriter, which owns the fct product/details/variations/downloads
 * inserts, SKU de-duplication and the id-mapping postmeta. Category trees are
 * synced once via CategoryWriter.
 */
class ProductMigrator
{
    /**
     * Hard ceiling on generated combinations for a non-variable product whose
     * attributes are expanded into a cartesian of advanced variations. Mirrors
     * FluentCart's AdvancedVariationService::DEFAULT_MAX_COMBINATIONS so a
     * product with many informational attributes can't explode the migration.
     * Above this the product stays a simple single-variation product.
     */
    const MAX_COMBINATIONS = 500;

    /** @var array<int,int> WC term id => FC term id, built once per run */
    private $categoryMap = [];

    /**
     * @return array<int, int|\WP_Error> wcProductId => fctPostId|WP_Error
     */
    public function migrate($willUpdate = true)
    {
        $this->categoryMap = CategoryWriter::sync($this->sourceCategories());

        $productIds = wc_get_products([
            'limit'   => -1,
            'return'  => 'ids',
            'status'  => ['publish', 'private', 'draft'],
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);

        $writer  = new ProductWriter();
        $results = [];
        $i       = 0;
        foreach ($productIds as $productId) {
            try {
                $results[$productId] = $this->migrateProduct((int) $productId, $writer);
            } catch (\Throwable $e) {
                // A single bad product must not abort the whole step.
                $results[$productId] = new \WP_Error('woo_migrator_error', $e->getMessage());
            }

            // Reclaim memory periodically — WC product objects (esp. variable /
            // subscription) and Query Monitor's per-query backtraces accumulate
            // and can exhaust the limit over a large catalog.
            if (++$i % 5 === 0) {
                BatchRuntime::freeMemory();
            }
        }

        // After every product exists, wire up bundle relationships (children
        // must be migrated first to resolve their FluentCart variation ids).
        $this->syncBundles($writer);

        return $results;
    }

    /**
     * Map WooCommerce grouped products (core) and Product Bundles (extension)
     * to FluentCart's bundle model: the parent product's default variation gets
     * the child variation ids, and the product is flagged as a bundle.
     */
    private function syncBundles(ProductWriter $writer)
    {
        $types = ['grouped'];
        if (class_exists('WC_Product_Bundle')) {
            $types[] = 'bundle';
        }

        $parentIds = wc_get_products([
            'type'   => $types,
            'limit'  => -1,
            'return' => 'ids',
            'status' => ['publish', 'private', 'draft'],
        ]);

        $i = 0;
        foreach ($parentIds as $parentId) {
            $fctPostId = (int) get_post_meta($parentId, MigratorHelper::WC_TO_FCT_META, true);
            if (!$fctPostId) {
                continue;
            }

            $parent = wc_get_product($parentId);
            if (!$parent) {
                continue;
            }

            $childVariationIds = [];
            foreach ($this->bundleChildIds($parent) as $childWcId) {
                $varId = $this->firstFctVariationId((int) $childWcId);
                if ($varId) {
                    $childVariationIds[] = $varId;
                }
            }

            if ($childVariationIds) {
                $writer->markBundle($fctPostId, $childVariationIds);
            }

            if (++$i % 5 === 0) {
                BatchRuntime::freeMemory();
            }
        }
    }

    /**
     * Child WooCommerce product ids for a grouped or bundle parent.
     *
     * @return int[]
     */
    private function bundleChildIds($parent)
    {
        if ($parent->is_type('grouped')) {
            return array_map('intval', $parent->get_children());
        }

        // Product Bundles extension.
        if (method_exists($parent, 'get_bundled_items')) {
            $ids = [];
            foreach ($parent->get_bundled_items() as $item) {
                if (method_exists($item, 'get_product_id')) {
                    $ids[] = (int) $item->get_product_id();
                }
            }
            return $ids;
        }

        return [];
    }

    /**
     * The first (default) migrated FluentCart variation id for a WC product.
     */
    private function firstFctVariationId($wcProductId)
    {
        $fctPost = (int) get_post_meta($wcProductId, MigratorHelper::WC_TO_FCT_META, true);
        if (!$fctPost) {
            return 0;
        }

        $map = get_post_meta($fctPost, MigratorHelper::VARIATION_MAP_META, true);
        if (!is_array($map) || !$map) {
            return 0;
        }

        return (int) reset($map);
    }

    /**
     * @return int|\WP_Error fct product post id
     */
    public function migrateProduct($wcProductId, ProductWriter $writer = null)
    {
        $writer  = $writer ?: new ProductWriter();
        $product = wc_get_product($wcProductId);

        if (!$product || $product->get_type() === 'variation') {
            return new \WP_Error('woo_migrator_error', 'Product not found: ' . $wcProductId);
        }

        return $writer->write($this->buildProduct($product));
    }

    /* -----------------------------------------------------------------
     | Transform
     * ----------------------------------------------------------------- */

    private function buildProduct($product)
    {
        $createdAt  = MigratorHelper::date($product->get_date_created());
        $isVariable = $product->is_type(['variable', 'variable-subscription']);

        // Resolve every (variation-defining for variable products, all for
        // non-variable) WooCommerce attribute into the shared FluentCart
        // attribute library (groups + terms) up front, then build variations.
        // Any product carrying attributes becomes an advanced-variations product.
        $groups          = $this->resolveAttributeGroups($product, $isVariable);
        $attributeConfig = [];
        $variations      = null;

        if ($isVariable) {
            $variations = $this->buildVariableVariations($product, $createdAt, $groups);
        } elseif ($groups) {
            $variations = $this->buildCartesianVariations($product, $createdAt, $groups);
        }

        if ($variations === null) {
            // No attributes, or the cartesian exceeded MAX_COMBINATIONS — fall
            // back to a plain single-variation product.
            $variations = [$this->buildSimpleVariation($product, $createdAt)];
        } elseif ($groups) {
            $attributeConfig = $this->buildAttributeConfig($groups);
        }

        $data                    = new ProductData();
        $data->sourceId          = (int) $product->get_id();
        $data->postTitle         = $product->get_name();
        $data->postContent       = $product->get_description();
        $data->postExcerpt       = $product->get_short_description();
        $data->postStatus        = $product->get_status();
        $data->postName          = $product->get_slug();
        $data->createdAt         = $createdAt;
        $data->thumbnailId       = $product->get_image_id() ?: null;
        $data->isVariable        = $isVariable;
        $data->manageStock       = $product->get_manage_stock() ? 1 : 0;
        $data->stockAvailability = MigratorHelper::stockStatus($product->get_stock_status());
        $data->isDownloadable    = $product->is_downloadable() ? 1 : 0;
        $data->categories        = $this->resolveCategories($product);
        $data->variations        = $variations;
        $data->attributeConfig   = $attributeConfig;
        $data->mappingKeys       = [
            'source'       => MigratorHelper::WC_TO_FCT_META,
            'fct'          => MigratorHelper::FCT_FROM_WC_META,
            'variationMap' => MigratorHelper::VARIATION_MAP_META,
        ];

        return $data;
    }

    private function buildSimpleVariation($product, $createdAt)
    {
        return ProductVariationData::make($this->variationFields($product, $createdAt));
    }

    /**
     * The shared fct_product_variations field set sourced from a WC product (or
     * variation). Used directly for simple products and as the per-combination
     * price/stock base for cartesian advanced variations.
     *
     * @return array
     */
    private function variationFields($source, $createdAt)
    {
        $regular = MigratorHelper::toCents($source->get_regular_price());
        $price   = MigratorHelper::toCents($source->get_price() !== '' ? $source->get_price() : $source->get_regular_price());

        return [
            'mediaId'             => $source->get_image_id() ?: null,
            'serialIndex'         => 1,
            'variationTitle'      => '',
            'variationIdentifier' => '0',
            'sku'                 => (string) $source->get_sku(),
            'manageStock'         => $source->get_manage_stock() ? 1 : 0,
            'stockStatus'         => MigratorHelper::stockStatus($source->get_stock_status()),
            'backorders'          => $source->get_backorders() !== 'no' ? 1 : 0,
            'totalStock'          => $source->get_stock_quantity(),
            'available'           => (int) $source->get_stock_quantity(),
            'fulfillmentType'     => MigratorHelper::fulfillmentType($source),
            'itemPrice'           => $price,
            'comparePrice'        => $source->is_on_sale() ? $regular : 0,
            'downloadable'        => $source->is_downloadable() ? 1 : 0,
            'downloads'           => $source->is_downloadable() ? $this->buildDownloads($source) : [],
            'createdAt'           => $createdAt,
            'updatedAt'           => current_time('mysql'),
        ];
    }

    /**
     * One FluentCart variation per WooCommerce child variation, each carrying the
     * attribute terms (in group order) that define it.
     *
     * @param array $groups resolveAttributeGroups() output (by ref so terms
     *                       recovered per variation land in attribute_config)
     * @return ProductVariationData[]
     */
    private function buildVariableVariations($product, $createdAt, array &$groups)
    {
        $rows  = [];
        $index = 0;

        foreach ($product->get_children() as $variationId) {
            $variation = wc_get_product($variationId);
            if (!$variation) {
                continue;
            }

            $index++;
            $resolved = $this->resolveVariationTerms($variation, $groups);

            $fields                        = $this->variationFields($variation, $createdAt);
            $fields['mediaId']             = $variation->get_image_id() ?: ($product->get_image_id() ?: null);
            $fields['serialIndex']         = $index;
            $fields['variationIdentifier'] = (string) $variationId;
            $fields['variantTermIds']      = $resolved['pairs'];
            $fields['variationTitle']      = $resolved['title'] !== ''
                ? $resolved['title']
                : $this->variationTitle($variation);

            $rows[] = ProductVariationData::make($fields);
        }

        // A variable product with no published variations still needs one row.
        if (!$rows) {
            return [$this->buildSimpleVariation($product, $createdAt)];
        }

        return $rows;
    }

    /**
     * Expand a non-variable product's attributes into the cartesian product of
     * all their terms, one FluentCart variation per combination (all sharing the
     * source product's price/stock). Returns null if the combination count would
     * exceed MAX_COMBINATIONS, signalling the caller to keep the product simple.
     *
     * @param array $groups resolveAttributeGroups() output
     * @return ProductVariationData[]|null
     */
    private function buildCartesianVariations($product, $createdAt, array $groups)
    {
        // Cartesian of [['term_id','group_id','title'], ...] across all groups.
        $combos = [[]];
        foreach ($groups as $group) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($group['terms'] as $term) {
                    $next[] = array_merge($combo, [[
                        'term_id'  => $term['id'],
                        'group_id' => $group['group_id'],
                        'title'    => $term['title'],
                    ]]);
                }
            }
            $combos = $next;

            if (count($combos) > self::MAX_COMBINATIONS) {
                return null;
            }
        }

        $base  = $this->variationFields($product, $createdAt);
        $rows  = [];
        $index = 0;
        foreach ($combos as $combo) {
            $index++;
            $fields                        = $base;
            $fields['serialIndex']         = $index;
            $fields['variantTermIds']      = $combo;
            $fields['variationTitle']      = implode(' / ', array_column($combo, 'title'));
            // Keep the legacy '0' map key on the first combination so existing
            // orders for this (formerly simple) product still resolve to a
            // variation; the rest are keyed by their joined term ids.
            $fields['variationIdentifier'] = $index === 1
                ? '0'
                : implode('_', array_column($combo, 'term_id'));

            $rows[] = ProductVariationData::make($fields);
        }

        return $rows;
    }

    /**
     * @return ProductDownloadData[]
     */
    private function buildDownloads($product)
    {
        $downloads = [];
        foreach ($product->get_downloads() as $download) {
            $downloads[] = ProductDownloadData::make([
                'file' => $download->get_file(),
                'name' => $download->get_name(),
            ]);
        }
        return $downloads;
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
     | Attributes (advanced variations)
     * ----------------------------------------------------------------- */

    /**
     * Map a product's WooCommerce attributes onto the shared FluentCart attribute
     * library (creating groups + terms as needed) and return them in WC attribute
     * order. For variable products only variation-defining attributes are taken;
     * for non-variable products every attribute participates (it becomes an
     * advanced-variations product via the cartesian).
     *
     * Each returned group:
     *   [
     *     'key'      => WC attribute key (used to match variation meta),
     *     'group_id' => fct_atts_groups.id,
     *     'taxonomy' => taxonomy name or null (custom),
     *     'terms'    => [ ['id'=>fctTermId,'title'=>..,'valueKeys'=>[matchable values]], ... ],
     *   ]
     *
     * @return array<int,array>
     */
    private function resolveAttributeGroups($product, $variationAttrsOnly)
    {
        $groups = [];

        foreach ($product->get_attributes() as $key => $attribute) {
            if ($variationAttrsOnly && !$attribute->get_variation()) {
                continue;
            }

            $taxonomy = $attribute->is_taxonomy() ? $attribute->get_name() : null;
            $groupId  = $taxonomy
                ? AttributeWriter::ensureGroup(wc_attribute_label($taxonomy), $taxonomy)
                : AttributeWriter::ensureGroup($attribute->get_name(), $attribute->get_name());

            if (!$groupId) {
                continue;
            }

            $terms = [];
            if ($taxonomy) {
                foreach ($attribute->get_terms() as $term) {
                    $termId = AttributeWriter::ensureTerm($groupId, $term->name, $term->slug);
                    if ($termId) {
                        // WC variation meta stores the term slug.
                        $terms[] = ['id' => $termId, 'title' => $term->name, 'valueKeys' => [$term->slug]];
                    }
                }
            } else {
                foreach ($attribute->get_options() as $option) {
                    $termId = AttributeWriter::ensureTerm($groupId, $option, $option);
                    if ($termId) {
                        // WC may store the raw option or its sanitized form.
                        $terms[] = ['id' => $termId, 'title' => $option, 'valueKeys' => [$option, sanitize_title($option)]];
                    }
                }
            }

            if (!$terms) {
                continue;
            }

            $groups[] = [
                'key'      => $key,
                'group_id' => $groupId,
                'taxonomy' => $taxonomy,
                'terms'    => $terms,
            ];
        }

        return $groups;
    }

    /**
     * Resolve one WC variation's selected term per group, in group order, so the
     * variation identifier and title line up with attribute_config.
     *
     * @param array $groups resolveAttributeGroups() output (by ref so a value
     *                       recovered from the taxonomy is added to the group's
     *                       terms and thus to attribute_config)
     * @return array{pairs:array<int,array{term_id:int,group_id:int}>,title:string}
     */
    private function resolveVariationTerms($variation, array &$groups)
    {
        $selected = $variation->get_variation_attributes(); // attribute_<key> => value
        $pairs    = [];
        $titles   = [];

        foreach ($groups as &$group) {
            $value = $selected['attribute_' . $group['key']] ?? '';
            if ($value === '') {
                continue; // "any" — this variation doesn't constrain this attribute
            }

            $match = $this->matchTerm($group, $value);
            if (!$match) {
                continue;
            }

            $pairs[]  = ['term_id' => (int) $match['id'], 'group_id' => (int) $group['group_id']];
            $titles[] = $match['title'];
        }
        unset($group);

        return ['pairs' => $pairs, 'title' => implode(' / ', $titles)];
    }

    /**
     * Find the term in a group matching a WC variation value, recovering it from
     * the taxonomy / creating it from a custom value when the parent no longer
     * lists it (inconsistent WC data). A recovered term is appended to the group
     * so attribute_config and the relations stay in sync.
     *
     * @return array{id:int,title:string}|null
     */
    private function matchTerm(array &$group, $value)
    {
        foreach ($group['terms'] as $term) {
            if (in_array($value, $term['valueKeys'], true) || in_array(sanitize_title($value), $term['valueKeys'], true)) {
                return ['id' => $term['id'], 'title' => $term['title']];
            }
        }

        $termId = 0;
        $title  = $value;
        if ($group['taxonomy']) {
            $wcTerm = get_term_by('slug', $value, $group['taxonomy']);
            if ($wcTerm && !is_wp_error($wcTerm)) {
                $termId = AttributeWriter::ensureTerm($group['group_id'], $wcTerm->name, $wcTerm->slug);
                $title  = $wcTerm->name;
            }
        } else {
            $termId = AttributeWriter::ensureTerm($group['group_id'], $value, $value);
        }

        if (!$termId) {
            return null;
        }

        $group['terms'][] = ['id' => $termId, 'title' => $title, 'valueKeys' => [$value, sanitize_title($value)]];

        return ['id' => $termId, 'title' => $title];
    }

    /**
     * Build fct_product_details.other_info.attribute_config from resolved groups:
     * one entry per group with its full term set, in group order.
     *
     * @param array $groups resolveAttributeGroups() output
     * @return array<int,array{group_id:int,variants:int[]}>
     */
    private function buildAttributeConfig(array $groups)
    {
        $config = [];
        foreach ($groups as $group) {
            $config[] = [
                'group_id' => (int) $group['group_id'],
                'variants' => array_map('intval', array_column($group['terms'], 'id')),
            ];
        }
        return $config;
    }

    /* -----------------------------------------------------------------
     | Categories
     * ----------------------------------------------------------------- */

    /**
     * Flatten WooCommerce product_cat terms into the CategoryWriter input shape.
     *
     * @return array[]
     */
    private function sourceCategories()
    {
        if (!taxonomy_exists('product_cat')) {
            return [];
        }

        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_wp_error($terms) || !$terms) {
            return [];
        }

        $out = [];
        foreach ($terms as $term) {
            $out[] = [
                'source_id'        => (int) $term->term_id,
                'name'             => $term->name,
                'slug'             => $term->slug,
                'description'      => $term->description,
                'parent_source_id' => (int) $term->parent,
            ];
        }

        return $out;
    }

    /**
     * @return int[] resolved fct term ids for the product's categories
     */
    private function resolveCategories($product)
    {
        $ids = [];
        foreach ($product->get_category_ids() as $wcTermId) {
            if (isset($this->categoryMap[(int) $wcTermId])) {
                $ids[] = (int) $this->categoryMap[(int) $wcTermId];
            }
        }
        return $ids;
    }
}
