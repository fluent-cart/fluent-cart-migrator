<?php

namespace FluentCartMigrator\Classes\Load;

use FluentCartMigrator\Classes\Dto\ReviewData;

/**
 * The shared "Load" for product reviews: writes fct_product_reviews rows and
 * the bidirectional id mapping, flattening any reply depth onto the root
 * review. Source-agnostic — the meta key comes from ReviewData::$mappingKeys.
 *
 * Also owns the availability guard. The reviews table ships in FluentCart core,
 * but the migrator updates on its own cycle, so a current migrator can meet an
 * older FluentCart. Every caller (stats, REST, CLI) asks this class, so they
 * all give the admin the same answer.
 *
 * Rows are written straight to the table rather than through the ProductReview
 * model on purpose: the model guards the trust fields against mass assignment
 * (user_id, customer_id, order_id, status, is_verified, is_admin_reply), and a
 * migration has to set exactly those. A direct insert is explicit by nature,
 * and it keeps per-row model events from firing a rating recalculation for
 * every row — aggregates are rebuilt once, in the step's second phase.
 */
class ReviewWriter
{
    const TABLE = 'fct_product_reviews';

    /**
     * The FluentCart release that ships fct_product_reviews.
     *
     * Mirrors core's own cross-plugin floor (FLUENTCART_MIN_PRO_VERSION). Only
     * used to phrase the "please update" message — the guard itself checks for
     * the class and the table, which is the fact that actually matters.
     */
    const MIN_FLUENTCART_VERSION = '1.7.0';

    const REVIEW_MODEL = '\FluentCart\App\Models\ProductReview';

    /** @var bool|null memoized per request */
    protected static $available = null;

    /* -----------------------------------------------------------------
     | Availability
     * ----------------------------------------------------------------- */

    /**
     * True when this FluentCart install can store reviews.
     *
     * Checks the class first (cheap) and then the table, because they can
     * disagree: DBMigrator::maybeMigrateDBChanges() runs behind an advisory
     * lock and can be skipped for a request, and a schema update can fail
     * outright — so an install can ship the class without the table.
     */
    public static function isAvailable()
    {
        if (self::$available !== null) {
            return self::$available;
        }

        self::$available = self::hasModel() && self::hasTable();

        return self::$available;
    }

    /**
     * '' when available, otherwise which of the two states we are in — the fix
     * differs, so the caller can phrase the right instruction.
     *
     * @return string '' | 'needs_update' | 'needs_db_upgrade'
     */
    public static function unavailableReason()
    {
        if (self::isAvailable()) {
            return '';
        }

        return self::hasModel() ? 'needs_db_upgrade' : 'needs_update';
    }

    /**
     * The blocking error for callers that bypass the UI (CLI, direct REST).
     *
     * @return \WP_Error|null
     */
    public static function unavailableError()
    {
        $reason = self::unavailableReason();

        if (!$reason) {
            return null;
        }

        if ($reason === 'needs_db_upgrade') {
            return new \WP_Error(
                'fluent_cart_db_upgrade_pending',
                __("FluentCart's database update hasn't finished, so product reviews cannot be migrated yet. Open any FluentCart admin page to complete it, then try again.", 'fluent-cart-migrator')
            );
        }

        return new \WP_Error(
            'fluent_cart_update_required',
            sprintf(
                /* translators: 1: required FluentCart version, 2: installed FluentCart version */
                __('Migrating product reviews requires FluentCart %1$s or later. This site is running %2$s. Please update FluentCart and try again.', 'fluent-cart-migrator'),
                self::MIN_FLUENTCART_VERSION,
                defined('FLUENTCART_VERSION') ? FLUENTCART_VERSION : __('an older version', 'fluent-cart-migrator')
            )
        );
    }

    protected static function hasModel()
    {
        return class_exists(self::REVIEW_MODEL);
    }

    protected static function hasTable()
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /* -----------------------------------------------------------------
     | Write
     * ----------------------------------------------------------------- */

    /**
     * Insert or update one review/reply.
     *
     * Idempotent: a source record already mapped to a live fct row is updated
     * in place, so replaying a half-written batch converges instead of
     * duplicating.
     *
     * @return int|\WP_Error fct_product_reviews.id
     */
    public static function write(ReviewData $review)
    {
        $error = self::unavailableError();
        if ($error) {
            return $error;
        }

        if (!$review->postId) {
            return new \WP_Error('review_no_product', 'No FluentCart product resolved for source review #' . $review->sourceId);
        }

        $metaKey = $review->mappingKeys['source'] ?? '';

        $parentId = null;
        if ($review->isReply()) {
            $parentId = self::rootReviewId($review->parentSourceId, $metaKey);

            // A reply whose parent never made it across has nothing to hang on.
            if (!$parentId) {
                return new \WP_Error('review_orphan_parent', 'Parent review not migrated for source reply #' . $review->sourceId);
            }
        }

        $now = gmdate('Y-m-d H:i:s');

        $media = array_values((array) $review->media);

        $existing = self::existingRow($review->sourceId, $metaKey);

        // A re-run keeps the keys FluentCart wrote after the import (approval
        // and reply notice state), so it never re-sends a notice.
        $otherInfo = $existing ? self::decodeOtherInfo($existing->other_info) : [];
        $otherInfo['source'] = $review->source;
        $otherInfo['source_comment_id'] = (int) $review->sourceId;
        if ($media) {
            $otherInfo['media'] = $media;
        } else {
            unset($otherInfo['media']);
        }

        $row = [
            'parent_id'      => $parentId,
            'post_id'        => (int) $review->postId,
            'order_id'       => $review->orderId ? (int) $review->orderId : null,
            'customer_id'    => $review->customerId ? (int) $review->customerId : null,
            'user_id'        => $review->userId ? (int) $review->userId : null,
            'reviewer_name'  => self::truncate($review->reviewerName, 192),
            'reviewer_email' => self::truncate($review->reviewerEmail, 192),
            'title'          => $review->title ? self::truncate($review->title, 192) : null,
            'review'         => (string) $review->content,
            // Replies never carry a rating, and an unrated review stays NULL so
            // it is counted in review_count but left out of the average.
            'rating'         => $review->isReply() ? null : self::normalizeRating($review->rating),
            'status'         => $review->status,
            // Only a top-level review carries the verified badge, as in core.
            'is_verified'    => (!$review->isReply() && $review->isVerified) ? 1 : 0,
            'is_admin_reply' => $review->isAdminReply ? 1 : 0,
            'media_count'    => count($media),
            'ip_address'     => $review->ipAddress ? substr((string) $review->ipAddress, 0, 45) : null,
            'other_info'     => wp_json_encode($otherInfo),
            'created_at'     => $review->createdAt ?: $now,
            'updated_at'     => $now,
        ];

        $db = fluentCart('db');

        if ($existing) {
            $db->table(self::TABLE)->where('id', $existing->id)->update($row);
            $reviewId = (int) $existing->id;
        } else {
            $reviewId = (int) $db->table(self::TABLE)->insertGetId($row);
        }

        if (!$reviewId) {
            return new \WP_Error('review_write_failed', 'Failed to write source review #' . $review->sourceId);
        }

        if ($metaKey) {
            update_comment_meta($review->sourceId, $metaKey, $reviewId);
        }

        return $reviewId;
    }

    /**
     * The migrated fct row id for a source record, or 0.
     */
    public static function existingId($sourceId, $metaKey)
    {
        $row = self::existingRow($sourceId, $metaKey);

        return $row ? (int) $row->id : 0;
    }

    /**
     * The migrated fct row for a source record, or null.
     *
     * The mapping is trusted only when the row points back at the same source
     * record. After a reset the table restarts at id 1, so a leftover mapping
     * can name a row that now holds a different review; updating it would
     * overwrite that review. Never memoized, so a deleted row is re-inserted.
     */
    public static function existingRow($sourceId, $metaKey)
    {
        if (!$metaKey || !$sourceId) {
            return null;
        }

        $mapped = (int) get_comment_meta($sourceId, $metaKey, true);
        if (!$mapped) {
            return null;
        }

        $row = fluentCart('db')->table(self::TABLE)
            ->select(['id', 'parent_id', 'other_info'])
            ->where('id', $mapped)
            ->first();

        if (!$row) {
            return null;
        }

        $info = self::decodeOtherInfo($row->other_info);
        if ((int) ($info['source_comment_id'] ?? 0) !== (int) $sourceId) {
            return null;
        }

        return $row;
    }

    /**
     * Resolve a source parent id to the ROOT fct review id.
     *
     * FluentCart has no reply-to-reply nesting at any tier, so a deep source
     * thread is flattened here. One hop is always enough: rows are migrated in
     * ascending source-id order, so a parent is always written before its
     * child, and by this same rule the parent row can only ever be a review or
     * a first-level reply.
     *
     * @return int 0 when the parent was never migrated
     */
    public static function rootReviewId($parentSourceId, $metaKey)
    {
        $parent = self::existingRow($parentSourceId, $metaKey);
        if (!$parent) {
            return 0;
        }

        // parent_id is what tells a reply from a review in core.
        return $parent->parent_id ? (int) $parent->parent_id : (int) $parent->id;
    }

    /**
     * Whether a root review already has at least one reply. Drives the
     * first-reply-only rule when multiple replies are not enabled.
     */
    public static function hasReply($rootReviewId)
    {
        if (!$rootReviewId) {
            return false;
        }

        return (bool) fluentCart('db')->table(self::TABLE)
            ->select(['id'])
            ->where('parent_id', $rootReviewId)
            ->first();
    }

    /**
     * Distinct products carrying reviews, past $afterPostId, for the aggregate
     * pass. Ordered so the cursor advances monotonically across requests.
     *
     * @return int[]
     */
    public static function productIdsAfter($afterPostId, $limit = 200)
    {
        // groupBy, not a bare where+limit: a product with 400 reviews would
        // otherwise fill the whole page with one product id and the cursor
        // would crawl forward one product per request.
        $rows = fluentCart('db')->table(self::TABLE)
            ->select('post_id')
            ->where('post_id', '>', (int) $afterPostId)
            ->groupBy('post_id')
            ->orderBy('post_id', 'ASC')
            ->limit((int) $limit)
            ->get();

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->post_id;
        }

        return $ids;
    }

    protected static function decodeOtherInfo($value)
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = $value ? json_decode((string) $value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    protected static function normalizeRating($rating)
    {
        $rating = (int) $rating;

        return ($rating >= 1 && $rating <= 5) ? $rating : null;
    }

    protected static function truncate($value, $length)
    {
        $value = (string) $value;

        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
