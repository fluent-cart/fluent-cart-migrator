<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCartMigrator\Classes\Dto\ProductData;
use FluentCartMigrator\Classes\Dto\ProductDownloadData;
use FluentCartMigrator\Classes\Dto\ProductVariationData;
use FluentCartMigrator\Classes\Load\AttributeWriter;
use FluentCartMigrator\Classes\Load\ProductWriter;
use FluentCartMigrator\Classes\Support\BatchRuntime;
use FluentCartMigrator\Classes\Support\TaxonomyResolver;

/**
 * WooCommerce product source: Extract + Transform only.
 *
 * Reads through the WooCommerce CRUD API (wc_get_product / WC_Product), maps
 * each product to a normalized ProductData DTO, and hands it to the shared
 * ProductWriter, which owns the fct product/details/variations/downloads
 * inserts, SKU de-duplication and the id-mapping postmeta. Product taxonomies
 * (categories, brands, tags, ...) follow the admin's taxonomy mapping and are
 * synced through TaxonomyResolver.
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

    /** @var TaxonomyResolver|null source→FluentCart taxonomy mapping, built once per run */
    private $taxonomies = null;

    /** @var array<string,string>|null taxonomy => WC attribute_type, lazy-built */
    private $attributeTypeMap = null;

    /**
     * @return array<int, int|\WP_Error> wcProductId => fctPostId|WP_Error
     */
    public function migrate($willUpdate = true)
    {
        $this->taxonomyResolver()->prepare();

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
     * Migrate one page of products (resumable path used by the REST/UI loop so
     * large catalogs don't migrate in a single non-resumable request). Bundle
     * wiring is NOT done here — the caller runs syncBundles() once, after the
     * final page, because bundle children must all exist first.
     *
     * @return array{processed:int,migrated:int,failed:int,errors:array,has_more:bool}
     */
    public function migratePage($page, $perPage = 20)
    {
        // Term trees are cheap + idempotent; ensure they exist before the first
        // product references them (kept across pages on this instance).
        $this->taxonomyResolver()->prepare();

        $query = wc_get_products([
            'limit'    => $perPage,
            'page'     => $page,
            'paginate' => true,
            'return'   => 'ids',
            'status'   => ['publish', 'private', 'draft'],
            'orderby'  => 'ID',
            'order'    => 'ASC',
        ]);

        $ids     = $query->products ?? [];
        $hasMore = $page < (int) ($query->max_num_pages ?? 1);

        $writer   = new ProductWriter();
        $migrated = 0;
        $failed   = 0;
        $errors   = [];
        $i        = 0;
        foreach ($ids as $productId) {
            try {
                $result = $this->migrateProduct((int) $productId, $writer);
                if (is_wp_error($result)) {
                    $failed++;
                    $errors[] = ['wc_id' => (int) $productId, 'message' => $result->get_error_message()];
                } else {
                    $migrated++;
                }
            } catch (\Throwable $e) {
                // A single bad product must not abort the whole step.
                $failed++;
                $errors[] = ['wc_id' => (int) $productId, 'message' => $e->getMessage()];
            }

            if (++$i % 5 === 0) {
                BatchRuntime::freeMemory();
            }
        }

        return [
            'processed' => count($ids),
            'migrated'  => $migrated,
            'failed'    => $failed,
            'errors'    => $errors,
            'has_more'  => $hasMore,
        ];
    }

    /**
     * Map WooCommerce grouped products (core) and Product Bundles (extension)
     * to FluentCart's bundle model: the parent product's default variation gets
     * the child variation ids, and the product is flagged as a bundle.
     */
    public function syncBundles(ProductWriter $writer = null)
    {
        $writer = $writer ?: new ProductWriter();

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
            foreach ($this->bundleChildIds($parent) as $child) {
                $varId = $this->fctVariationId($child['product_id'], $child['variation_id']);
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
     * Child references for a grouped or bundle parent. Each entry is
     * ['product_id' => int, 'variation_id' => int] — variation_id is set only
     * when a Product Bundles item is pinned to a specific variation, else 0.
     *
     * @return array<int,array{product_id:int,variation_id:int}>
     */
    private function bundleChildIds($parent)
    {
        if ($parent->is_type('grouped')) {
            $out = [];
            foreach ($parent->get_children() as $childId) {
                $out[] = ['product_id' => (int) $childId, 'variation_id' => 0];
            }
            return $out;
        }

        // Product Bundles extension.
        if (method_exists($parent, 'get_bundled_items')) {
            $out = [];
            foreach ($parent->get_bundled_items() as $item) {
                if (!method_exists($item, 'get_product_id')) {
                    continue;
                }
                $variationId = 0;
                if (method_exists($item, 'get_product')) {
                    $bundled = $item->get_product();
                    if ($bundled && $bundled->is_type('variation')) {
                        $variationId = (int) $bundled->get_id();
                    }
                }
                $out[] = ['product_id' => (int) $item->get_product_id(), 'variation_id' => $variationId];
            }
            return $out;
        }

        return [];
    }

    /**
     * The migrated FluentCart variation id for a WC product — the specific
     * variation when $wcVariationId is given and mapped, else the first
     * (default) migrated variation.
     */
    private function fctVariationId($wcProductId, $wcVariationId = 0)
    {
        $fctPost = (int) get_post_meta($wcProductId, MigratorHelper::WC_TO_FCT_META, true);
        if (!$fctPost) {
            return 0;
        }

        $map = get_post_meta($fctPost, MigratorHelper::VARIATION_MAP_META, true);
        if (!is_array($map) || !$map) {
            return 0;
        }

        if ($wcVariationId && isset($map[(string) $wcVariationId])) {
            return (int) $map[(string) $wcVariationId];
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
        $isExternal = $product->is_type('external');
        $isVariable = !$isExternal && $product->is_type(['variable', 'variable-subscription']);

        // Resolve every (variation-defining for variable products, all for
        // non-variable) WooCommerce attribute into the shared FluentCart
        // attribute library (groups + terms) up front, then build variations.
        // Any product carrying attributes becomes an advanced-variations product.
        // External products skip this: they migrate as catalog-only entries.
        $groups          = $isExternal ? [] : $this->resolveAttributeGroups($product, $isVariable);
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

        // External/affiliate products have no cart flow in WooCommerce — they
        // link out. FluentCart has no equivalent type, so migrate the catalog
        // data but keep the variation inactive and out of stock so it can never
        // be checked out, and stash the outbound URL + button text as product
        // meta for a template/widget to read.
        if ($isExternal) {
            foreach ($variations as $variation) {
                $variation->itemStatus  = 'inactive';
                $variation->stockStatus = 'out-of-stock';
                $variation->manageStock = 1;
                $variation->totalStock  = 0;
                $variation->available   = 0;
            }
        }

        // FluentCart only enforces the stock check when BOTH
        // fct_product_details.manage_stock and the variation's own manage_stock
        // are truthy (ProductVariation::canPurchase). WC variable products
        // usually keep the parent flag off while stock lives on the variations,
        // so roll the flag up — otherwise a correctly-managed variation is
        // ignored and the product sells as unlimited stock.
        $anyManaged = (bool) $product->get_manage_stock();
        foreach ($variations as $variation) {
            $anyManaged = $anyManaged || (bool) $variation->manageStock;
        }

        $data                    = new ProductData();
        $data->sourceId          = (int) $product->get_id();
        $data->postTitle         = $product->get_name();
        $data->postContent       = $product->get_description();
        $data->postExcerpt       = $product->get_short_description();
        $data->postStatus        = $product->get_status();
        $data->postName          = $product->get_slug();
        $data->postAuthor        = (int) get_post_field('post_author', $product->get_id());
        $data->createdAt         = $createdAt;
        $data->thumbnailId       = $product->get_image_id() ?: null;
        $data->isVariable        = $isVariable;
        $data->manageStock       = $anyManaged ? 1 : 0;
        $data->stockAvailability = $isExternal ? 'out-of-stock' : MigratorHelper::stockStatus($product->get_stock_status());
        $data->isDownloadable    = $product->is_downloadable() ? 1 : 0;
        $data->taxonomies        = $this->taxonomyResolver()->resolveForObject($product->get_id());
        $data->variations        = $variations;
        $data->attributeConfig   = $attributeConfig;
        if ($isExternal) {
            $data->meta = [
                'is_external_product'     => 'yes',
                'external_product_url'    => (string) $product->get_product_url(),
                'external_product_button' => (string) ($product->get_button_text() ?: __('Buy Now', 'fluent-cart-migrator')),
            ];
        }
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

        // Subscription products carry recurring config on the variation (mirrors
        // EDD's getPriceVariationDetails); everything else is a one-time payment.
        // payment_type is always set explicitly so the column is never NULL.
        $isSubscription = class_exists('WC_Subscriptions_Product')
            && \WC_Subscriptions_Product::is_subscription($source);

        $stock = $this->resolveStock($source);

        $fields = [
            'mediaId'             => $source->get_image_id() ?: null,
            'serialIndex'         => 1,
            'variationTitle'      => '',
            'variationIdentifier' => '0',
            'sku'                 => (string) $source->get_sku(),
            'paymentType'         => $isSubscription ? 'subscription' : 'onetime',
            'manageStock'         => $stock['manage'],
            'stockStatus'         => MigratorHelper::stockStatus($source->get_stock_status()),
            'backorders'          => $source->get_backorders() !== 'no' ? 1 : 0,
            'totalStock'          => $stock['total'],
            'available'           => $stock['available'],
            'fulfillmentType'     => MigratorHelper::fulfillmentType($source),
            'itemPrice'           => $price,
            'comparePrice'        => $source->is_on_sale() ? $regular : 0,
            'downloadable'        => $source->is_downloadable() ? 1 : 0,
            'downloads'           => $source->is_downloadable() ? $this->buildDownloads($source) : [],
            'createdAt'           => $createdAt,
            'updatedAt'           => current_time('mysql'),
        ];

        if ($isSubscription) {
            $fields['otherInfo'] = $this->subscriptionVariantInfo($source);
        }

        return $fields;
    }

    /**
     * fct stock fields for a WC product/variation. FluentCart's canPurchase()
     * ignores stock_status entirely — it only blocks when manage_stock is on
     * and the requested quantity exceeds `available` — so a WC item marked out
     * of stock WITHOUT per-item stock management (a very common manual toggle)
     * must be forced onto managed stock with 0 available, or it migrates as
     * purchasable.
     *
     * get_manage_stock() is used deliberately over managing_stock(): the latter
     * is gated by the store-wide "Manage stock" option and returns false for
     * every product when that option is off, which would discard real per-item
     * quantities. (For variations it returns 'parent' when inheriting — truthy,
     * and get_stock_quantity() then reads the parent's quantity.)
     *
     * @return array{manage:int,total:int|null,available:int}
     */
    private function resolveStock($source)
    {
        if ($source->get_manage_stock()) {
            $qty = (int) $source->get_stock_quantity();
            return ['manage' => 1, 'total' => $qty, 'available' => max(0, $qty)];
        }

        if ($source->get_stock_status() === 'outofstock') {
            return ['manage' => 1, 'total' => 0, 'available' => 0];
        }

        return ['manage' => 0, 'total' => null, 'available' => 0];
    }

    /**
     * Recurring config for a subscription product's variation other_info,
     * matching the shape FluentCart's ProductVariation reads and EDD writes.
     *
     * @return array
     */
    private function subscriptionVariantInfo($source)
    {
        $period   = \WC_Subscriptions_Product::get_period($source);
        $interval = (int) \WC_Subscriptions_Product::get_interval($source);
        $length   = (int) \WC_Subscriptions_Product::get_length($source);        // 0 = until cancelled
        $trial    = (int) \WC_Subscriptions_Product::get_trial_length($source);
        $signup   = MigratorHelper::toCents(\WC_Subscriptions_Product::get_sign_up_fee($source));
        $repeat   = MigratorHelper::repeatInterval($period, $interval);

        $summary = $length > 0
            ? sprintf('%s every %s for %d times', $source->get_price(), $repeat, $length)
            : sprintf('%s every %s until cancelled', $source->get_price(), $repeat);

        return [
            'payment_type'     => 'subscription',
            'repeat_interval'  => $repeat,
            'times'            => $length,
            'trial_days'       => $trial,
            'manage_setup_fee' => $signup > 0 ? 'yes' : 'no',
            'signup_fee'       => $signup,
            'signup_fee_name'  => '',
            'installment'      => $length > 0 ? 'yes' : 'no',
            'billing_summary'  => $summary,
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

            // Swatch type: color/image global attributes become FluentCart
            // color/image groups (so the editor renders swatches); everything
            // else — custom attributes and plain select/text taxonomies — is an
            // "options" (text) group.
            $fctType = $taxonomy ? $this->attributeType($taxonomy) : 'options';

            $groupId = $taxonomy
                ? AttributeWriter::ensureGroup(wc_attribute_label($taxonomy), $taxonomy, ['type' => $fctType])
                : AttributeWriter::ensureGroup($attribute->get_name(), $attribute->get_name(), ['type' => 'options']);

            if (!$groupId) {
                continue;
            }

            $terms = [];
            if ($taxonomy) {
                foreach ($attribute->get_terms() as $term) {
                    $termId = AttributeWriter::ensureTerm($groupId, $term->name, $term->slug, $this->termSwatch($fctType, $term->term_id));
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
     * FluentCart attribute group type for a WC global attribute taxonomy. WC
     * stores the swatch type in woocommerce_attribute_taxonomies.attribute_type
     * (set by swatch plugins); `color`/`image` map through, everything else
     * (select/button/text/…) becomes `options`.
     */
    private function attributeType($taxonomy)
    {
        if ($this->attributeTypeMap === null) {
            $this->attributeTypeMap = [];
            if (function_exists('wc_get_attribute_taxonomies')) {
                foreach (wc_get_attribute_taxonomies() as $tax) {
                    // Taxonomy name is 'pa_' . attribute_name.
                    $this->attributeTypeMap['pa_' . $tax->attribute_name] = $tax->attribute_type;
                }
            }
        }

        $wcType = $this->attributeTypeMap[$taxonomy] ?? '';

        return in_array($wcType, ['color', 'image'], true) ? $wcType : 'options';
    }

    /**
     * Term swatch settings for a color/image group, read from the WC term meta
     * the common swatch plugins write (`product_attribute_color` hex,
     * `product_attribute_image` attachment id). Empty when not a swatch type or
     * no value is stored — the term still migrates as a plain value.
     *
     * @return array
     */
    private function termSwatch($fctType, $wcTermId)
    {
        if ($fctType === 'color') {
            $color = get_term_meta($wcTermId, 'product_attribute_color', true);
            return $color ? ['color' => $color] : [];
        }

        if ($fctType === 'image') {
            $imageId = get_term_meta($wcTermId, 'product_attribute_image', true);
            if ($imageId && is_numeric($imageId)) {
                $url = wp_get_attachment_url((int) $imageId);
                return $url ? ['image' => $url] : [];
            }
        }

        return [];
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
     | Taxonomies
     * ----------------------------------------------------------------- */

    /**
     * The source→FluentCart taxonomy mapping for this run, created once so the
     * synced term trees are reused across products and pages.
     */
    private function taxonomyResolver()
    {
        if ($this->taxonomies === null) {
            $this->taxonomies = new TaxonomyResolver('woocommerce');
        }

        return $this->taxonomies;
    }
}
