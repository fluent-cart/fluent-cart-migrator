<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCartMigrator\Classes\Dto\ProductData;
use FluentCartMigrator\Classes\Dto\ProductDownloadData;
use FluentCartMigrator\Classes\Dto\ProductVariationData;
use FluentCartMigrator\Classes\Load\CategoryWriter;
use FluentCartMigrator\Classes\Load\ProductWriter;

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
        foreach ($productIds as $productId) {
            try {
                $results[$productId] = $this->migrateProduct((int) $productId, $writer);
            } catch (\Throwable $e) {
                // A single bad product must not abort the whole step.
                $results[$productId] = new \WP_Error('woo_migrator_error', $e->getMessage());
            }
        }

        return $results;
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
        $data->variations        = $isVariable
            ? $this->buildVariableVariations($product, $createdAt)
            : [$this->buildSimpleVariation($product, $createdAt)];
        $data->mappingKeys       = [
            'source'       => MigratorHelper::WC_TO_FCT_META,
            'fct'          => MigratorHelper::FCT_FROM_WC_META,
            'variationMap' => MigratorHelper::VARIATION_MAP_META,
        ];

        return $data;
    }

    private function buildSimpleVariation($product, $createdAt)
    {
        $regular = MigratorHelper::toCents($product->get_regular_price());
        $price   = MigratorHelper::toCents($product->get_price() !== '' ? $product->get_price() : $product->get_regular_price());

        return ProductVariationData::make([
            'mediaId'             => $product->get_image_id() ?: null,
            'serialIndex'         => 1,
            'variationTitle'      => '',
            'variationIdentifier' => '0',
            'sku'                 => (string) $product->get_sku(),
            'manageStock'         => $product->get_manage_stock() ? 1 : 0,
            'stockStatus'         => MigratorHelper::stockStatus($product->get_stock_status()),
            'backorders'          => $product->get_backorders() !== 'no' ? 1 : 0,
            'totalStock'          => $product->get_stock_quantity(),
            'available'           => (int) $product->get_stock_quantity(),
            'fulfillmentType'     => MigratorHelper::fulfillmentType($product),
            'itemPrice'           => $price,
            'comparePrice'        => $product->is_on_sale() ? $regular : 0,
            'downloadable'        => $product->is_downloadable() ? 1 : 0,
            'downloads'           => $product->is_downloadable() ? $this->buildDownloads($product) : [],
            'createdAt'           => $createdAt,
            'updatedAt'           => current_time('mysql'),
        ]);
    }

    /**
     * @return ProductVariationData[]
     */
    private function buildVariableVariations($product, $createdAt)
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

            $rows[] = ProductVariationData::make([
                'mediaId'             => $variation->get_image_id() ?: ($product->get_image_id() ?: null),
                'serialIndex'         => $index,
                'variationTitle'      => $this->variationTitle($variation),
                'variationIdentifier' => (string) $variationId,
                'sku'                 => (string) $variation->get_sku(),
                'manageStock'         => $variation->get_manage_stock() ? 1 : 0,
                'stockStatus'         => MigratorHelper::stockStatus($variation->get_stock_status()),
                'backorders'          => $variation->get_backorders() !== 'no' ? 1 : 0,
                'totalStock'          => $variation->get_stock_quantity(),
                'available'           => (int) $variation->get_stock_quantity(),
                'fulfillmentType'     => MigratorHelper::fulfillmentType($variation),
                'itemPrice'           => $price,
                'comparePrice'        => $variation->is_on_sale() ? $regular : 0,
                'downloadable'        => $variation->is_downloadable() ? 1 : 0,
                'downloads'           => $variation->is_downloadable() ? $this->buildDownloads($variation) : [],
                'createdAt'           => $createdAt,
                'updatedAt'           => current_time('mysql'),
            ]);
        }

        // A variable product with no published variations still needs one row.
        if (!$rows) {
            return [$this->buildSimpleVariation($product, $createdAt)];
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
