<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * One fct_product_variations row. `post_id` is injected by the writer and `sku`
 * is run through Support\Sku::unique before insert. `variationIdentifier` is
 * the source variation id (or '0' for a simple product) and is used to build
 * the source→fct variation map.
 */
class ProductVariationData
{
    public $mediaId = null;
    public $serialIndex = 1;
    public $variationTitle = '';
    public $variationIdentifier = '0';
    public $sku = '';
    public $manageStock = 0;
    public $paymentType = 'onetime';
    public $stockStatus = 'in-stock';
    public $backorders = 0;
    public $totalStock = null;
    public $available = 0;
    public $committed = 0;
    public $onHold = 0;
    public $fulfillmentType = 'physical';
    public $itemStatus = 'active';
    public $itemPrice = 0;
    public $comparePrice = 0;
    public $downloadable = 0;
    public $otherInfo = ['payment_type' => 'onetime'];
    public $createdAt = '';
    public $updatedAt = '';

    /**
     * Advanced-variation attribute terms this variation maps to. Each entry is
     * ['term_id' => int, 'group_id' => int] (resolved fct_atts_terms ids). When
     * non-empty the writer sets variation_identifier to the joined term ids,
     * stores them under other_info.variant, and writes fct_atts_relations rows.
     *
     * @var array<int,array{term_id:int,group_id:int}>
     */
    public $variantTermIds = [];

    /** @var ProductDownloadData[] */
    public $downloads = [];

    public static function make(array $data)
    {
        $v = new self();
        foreach ($data as $key => $value) {
            if (property_exists($v, $key)) {
                $v->$key = $value;
            }
        }
        return $v;
    }

    /**
     * The fct_product_variations row (without post_id; sku set by the writer
     * after de-duplication).
     */
    public function toArray()
    {
        return [
            'media_id'             => $this->mediaId,
            'serial_index'         => $this->serialIndex,
            'variation_title'      => $this->variationTitle,
            'variation_identifier' => (string) $this->variationIdentifier,
            'manage_stock'         => $this->manageStock,
            'payment_type'         => $this->paymentType,
            'stock_status'         => $this->stockStatus,
            'backorders'           => $this->backorders,
            'total_stock'          => $this->totalStock,
            'available'            => $this->available,
            'committed'            => $this->committed,
            'on_hold'              => $this->onHold,
            'fulfillment_type'     => $this->fulfillmentType,
            'item_status'          => $this->itemStatus,
            'item_price'           => $this->itemPrice,
            'compare_price'        => $this->comparePrice,
            'downloadable'         => $this->downloadable,
            'other_info'           => json_encode($this->otherInfo),
            'created_at'           => $this->createdAt,
            'updated_at'           => $this->updatedAt,
        ];
    }
}
