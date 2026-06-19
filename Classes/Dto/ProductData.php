<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * The normalized product aggregate handed to ProductWriter. The writer creates
 * the CPT post + fct_product_details, inserts variations (deduping SKUs) and
 * their downloads, assigns categories, and writes the bidirectional id-mapping
 * postmeta whose keys the source supplies via $mappingKeys (so the writer never
 * hardcodes source-specific meta keys).
 *
 * $mappingKeys: ['source' => meta key on the SOURCE product holding the fct id,
 *                'fct'    => meta key on the fct post holding the source id,
 *                'variationMap' => meta key on the fct post holding the
 *                                  sourceVariationId => fctVariationId map].
 */
class ProductData
{
    public $sourceId = 0;
    public $postTitle = '';
    public $postContent = '';
    public $postExcerpt = '';
    public $postStatus = 'publish';
    public $postName = '';
    public $createdAt = '';
    public $thumbnailId = null;
    public $isVariable = false;
    public $manageStock = 0;
    public $stockAvailability = 'in-stock';
    public $isDownloadable = 0;

    /** @var int[] resolved fct term ids (source resolves via CategoryWriter::sync) */
    public $categories = [];

    /** @var ProductVariationData[] */
    public $variations = [];

    /**
     * Advanced-variation attribute config for fct_product_details.other_info.
     * Each entry is ['group_id' => int, 'variants' => int[]] (fct group id and
     * the term ids selected for it). When non-empty the writer marks the product
     * detail variation_type = 'advanced_variations'.
     *
     * @var array<int,array{group_id:int,variants:int[]}>
     */
    public $attributeConfig = [];

    /** @var array{source:string,fct:string,variationMap:string} */
    public $mappingKeys = [];

    public static function make(array $data)
    {
        $p = new self();
        foreach ($data as $key => $value) {
            if (property_exists($p, $key)) {
                $p->$key = $value;
            }
        }
        return $p;
    }

    /**
     * The wp_posts row for the fct product CPT (post_type injected by the writer).
     */
    public function postArray()
    {
        return [
            'post_title'   => $this->postTitle,
            'post_content' => $this->postContent,
            'post_excerpt' => $this->postExcerpt,
            'post_status'  => $this->postStatus,
            'post_name'    => $this->postName,
            'post_date'    => $this->createdAt,
        ];
    }
}
