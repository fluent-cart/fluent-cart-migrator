<?php

namespace FluentCartMigrator\Classes\Load;

use FluentCart\App\CPT\FluentProducts;
use FluentCartMigrator\Classes\Dto\ProductData;
use FluentCartMigrator\Classes\Support\Sku;

/**
 * The shared "Load" for products: creates the fct product CPT post,
 * fct_product_details, fct_product_variations (deduping SKUs) and their
 * fct_product_downloads, assigns categories, and writes the bidirectional
 * id-mapping postmeta (keys supplied by the source, so this stays
 * source-agnostic). Idempotent: a product already mapped is updated in place.
 *
 * @return int|\WP_Error fct product post id
 */
class ProductWriter
{
    public function write(ProductData $product)
    {
        $cpt       = FluentProducts::CPT_NAME;
        $createdAt = $product->createdAt ?: current_time('mysql');

        $postData = array_merge($product->postArray(), [
            'post_type'         => $cpt,
            'post_status'       => in_array($product->postStatus, ['publish', 'private'], true) ? 'publish' : 'draft',
            'post_date'         => $createdAt,
            'post_date_gmt'     => $createdAt,
            'post_modified'     => current_time('mysql'),
            'post_modified_gmt' => gmdate('Y-m-d H:i:s'),
        ]);

        $keys      = $product->mappingKeys;
        $sourceKey = $keys['source'] ?? '';
        $fctKey    = $keys['fct'] ?? '';
        $mapKey    = $keys['variationMap'] ?? '';

        // Idempotency: update the previously migrated post if present.
        $postId   = null;
        $isUpdate = false;
        if ($sourceKey) {
            $existingId = (int) get_post_meta($product->sourceId, $sourceKey, true);
            if ($existingId && get_post($existingId) && get_post_type($existingId) === $cpt) {
                wp_update_post(array_merge($postData, ['ID' => $existingId]));
                $postId   = $existingId;
                $isUpdate = true;
            }
        }

        if (!$postId) {
            $postId = wp_insert_post($postData);
        }

        if (is_wp_error($postId) || !$postId) {
            return new \WP_Error('product_write_failed', 'Failed to create product post for source #' . $product->sourceId);
        }

        if ($sourceKey) {
            update_post_meta($product->sourceId, $sourceKey, $postId);
        }
        if ($fctKey) {
            update_post_meta($postId, $fctKey, $product->sourceId);
        }

        if ($product->categories && taxonomy_exists(CategoryWriter::TAXONOMY)) {
            wp_set_object_terms($postId, array_map('intval', $product->categories), CategoryWriter::TAXONOMY, false);
        }

        if ($product->thumbnailId) {
            set_post_thumbnail($postId, $product->thumbnailId);
        }

        if ($isUpdate) {
            $this->cleanupExisting($postId);
        }

        $variationMap = $this->writeVariations($product, $postId);

        if ($mapKey) {
            update_post_meta($postId, $mapKey, $variationMap);
        }

        $this->writeDetails($product, $postId, $variationMap, $createdAt);

        return (int) $postId;
    }

    /**
     * @return array<string,int> variationIdentifier => fctVariationId
     */
    protected function writeVariations(ProductData $product, $postId)
    {
        $db  = fluentCart('db');
        $map = [];

        foreach ($product->variations as $variation) {
            $row            = $variation->toArray();
            $row['post_id'] = $postId;
            $row['sku']     = Sku::unique($variation->sku);

            $variationId = $db->table('fct_product_variations')->insertGetId($row);
            $map[(string) $variation->variationIdentifier] = (int) $variationId;

            $this->writeDownloads($postId, $variationId, $variation->downloads, $row['created_at']);
        }

        return $map;
    }

    protected function writeDownloads($postId, $variationId, $downloads, $createdAt)
    {
        if (!$downloads) {
            return;
        }

        $db     = fluentCart('db');
        $serial = 0;
        foreach ($downloads as $download) {
            $serial++;
            $file = $download->file;
            if (!$file) {
                continue;
            }

            $isUrl = (bool) preg_match('#^https?://#i', $file);
            $name  = $download->name ?: basename(parse_url($file, PHP_URL_PATH) ?: $file);
            $ext   = pathinfo(parse_url($file, PHP_URL_PATH) ?: $file, PATHINFO_EXTENSION);

            $db->table('fct_product_downloads')->insert([
                'download_identifier'  => md5($file . '_' . $postId . '_' . $variationId . '_' . $serial . '_' . wp_generate_uuid4()),
                'post_id'              => $postId,
                'product_variation_id' => json_encode($variationId ? [(int) $variationId] : []),
                'driver'               => $isUrl ? 'url' : 'local',
                'title'                => $name,
                'type'                 => $ext,
                'file_name'            => $name,
                'file_path'            => $file,
                'file_url'             => $file,
                'serial'               => $serial,
                'settings'             => json_encode(['download_limit' => '', 'download_expiry' => '']),
                'created_at'           => $createdAt,
                'updated_at'           => current_time('mysql'),
            ]);
        }
    }

    protected function writeDetails(ProductData $product, $postId, array $variationMap, $createdAt)
    {
        $prices = array_values(array_filter(array_map(function ($v) {
            return $v->itemPrice;
        }, $product->variations), function ($p) {
            return $p !== null;
        }));

        $fulfillments       = array_values(array_unique(array_map(function ($v) {
            return $v->fulfillmentType;
        }, $product->variations)));
        $productFulfillment = count($fulfillments) === 1 ? $fulfillments[0] : 'mixed';

        fluentCart('db')->table('fct_product_details')->insertGetId([
            'post_id'              => $postId,
            'fulfillment_type'     => $productFulfillment,
            'min_price'            => $prices ? min($prices) : 0,
            'max_price'            => $prices ? max($prices) : 0,
            'default_variation_id' => $variationMap ? reset($variationMap) : '',
            'default_media'        => json_encode([]),
            'manage_stock'         => $product->manageStock,
            'stock_availability'   => $product->stockAvailability,
            'variation_type'       => $product->isVariable ? 'simple_variations' : 'simple',
            'manage_downloadable'  => $product->isDownloadable,
            'other_info'           => json_encode([
                'group_pricing_by'  => 'repeat_interval',
                'use_pricing_table' => 'yes',
            ]),
            'created_at'           => $createdAt,
            'updated_at'           => gmdate('Y-m-d H:i:s'),
        ]);
    }

    protected function cleanupExisting($postId)
    {
        $db = fluentCart('db');
        $db->table('fct_product_variations')->where('post_id', $postId)->delete();
        $db->table('fct_product_details')->where('post_id', $postId)->delete();
        $db->table('fct_product_downloads')->where('post_id', $postId)->delete();
    }
}
