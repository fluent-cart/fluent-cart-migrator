<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * A single normalized review or reply handed to ReviewWriter.
 *
 * FluentCart stores reviews and replies in one table (fct_product_reviews): a
 * review is type = 'review' with parent_id NULL, a reply is type = 'reply'
 * whose parent_id points at the ROOT review. There is no deeper nesting at any
 * tier, so a source with an arbitrarily deep comment tree hands the writer the
 * source id of its immediate parent and the writer flattens it.
 *
 * $mappingKeys: ['source' => meta key stored on the SOURCE record holding the
 * migrated fct review id]. The reverse pointer lives on the fct row itself, in
 * other_info.source_comment_id, so no second meta key is needed.
 */
class ReviewData
{
    /** Source record id (e.g. a WooCommerce comment_ID). */
    public $sourceId = 0;

    /** Source id of the immediate parent, 0 for a top-level review. */
    public $parentSourceId = 0;

    /** FluentCart product post id (already resolved by the source migrator). */
    public $postId = 0;

    public $reviewerName = '';
    public $reviewerEmail = '';
    public $title = null;
    public $content = '';

    /** 1-5, or null for replies and unrated reviews. */
    public $rating = null;

    /** approved | pending | spam | trash */
    public $status = 'pending';

    public $isVerified = 0;
    public $isAdminReply = 0;

    public $userId = null;
    public $customerId = null;
    public $orderId = null;

    public $ipAddress = '';

    /** MySQL datetime in GMT. */
    public $createdAt = '';

    /** Source slug written into other_info.source, e.g. 'woocommerce'. */
    public $source = '';

    /** @var array{source:string} */
    public $mappingKeys = [];

    public static function make(array $data)
    {
        $review = new self();
        foreach ($data as $key => $value) {
            if (property_exists($review, $key)) {
                $review->$key = $value;
            }
        }
        return $review;
    }

    public function isReply()
    {
        return $this->parentSourceId > 0;
    }
}
