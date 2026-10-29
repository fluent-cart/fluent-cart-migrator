<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCartMigrator\Classes\Dto\ReviewData;
use FluentCartMigrator\Classes\Load\ReviewWriter;
use FluentCartMigrator\Classes\Support\MigrationLog;

/**
 * Reads WooCommerce product reviews out of wp_comments and hands them to
 * ReviewWriter.
 *
 * Woo stores a review as a comment on a `product` post. Its comment_type is
 * 'review' on anything submitted through the review form since WC 3.x, but
 * older rows carry '' or 'comment' — Woo itself queries all three, so this does
 * too. The rating and verified-purchase flag live in wp_commentmeta under the
 * unprefixed keys `rating` and `verified`.
 *
 * Paging is a monotonic comment_ID cursor rather than an offset: an offset over
 * a table that is still being written to skips or repeats rows. Ascending id
 * order also means a reply is always reached after its parent (WP
 * auto-increment), which is what lets replies resolve their parent in one pass
 * with no orphan queue.
 */
class ReviewMigrator
{
    /**
     * comment_meta key on the Woo comment holding the migrated fct review id.
     */
    const WC_TO_REVIEW_META = '_fct_migrated_review_id';

    const SOURCE = 'woocommerce';

    /** Comment types Woo treats as a product review. */
    const REVIEW_TYPES = ['review', '', 'comment'];

    /** @var array<int,int> woo user id => is-staff (per batch) */
    protected $staffCache = [];

    /** @var array<string,int|null> lowercased email => fct customer id (per batch) */
    protected $customerCache = [];

    /** @var bool */
    protected $allowMultipleReplies;

    /**
     * Customer Reviews for WooCommerce keeps each locally stored photo as one
     * comment-meta row holding an attachment id. Its other key,
     * ivole_review_image, holds remote URLs, which are not media-library files.
     */
    const PHOTO_META = 'ivole_review_image2';

    /** Upper bound on photos carried over per review. */
    const MAX_PHOTOS = 20;

    public function __construct()
    {
        $this->allowMultipleReplies = self::multipleRepliesAllowed();
    }

    /**
     * Whether every reply may be imported, or only the first on each review.
     *
     * Reads FluentCart's own switch, not a Pro plugin check: the helper folds
     * in both Pro presence and the allow_threaded_replies filter, and it is
     * what every enforcement point in the UI consults — so the import and the
     * screen it lands on agree by construction, including on a store that
     * turns threading off deliberately.
     */
    public static function multipleRepliesAllowed()
    {
        if (!class_exists('\FluentCart\App\Services\ProductReviewService')) {
            return false;
        }

        return (bool) \FluentCart\App\Services\ProductReviewService::isMultipleRepliesAllowed();
    }

    /* -----------------------------------------------------------------
     | Counts (stats screen)
     * ----------------------------------------------------------------- */

    public static function countReviews()
    {
        return self::countByDepth('top');
    }

    public static function countReplies()
    {
        return self::countByDepth('replies');
    }

    protected static function countByDepth($which)
    {
        global $wpdb;

        $types  = self::typesInClause();
        $parent = $which === 'replies' ? '>' : '=';

        $sql = "SELECT COUNT(*)
                FROM {$wpdb->comments} c
                INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
                WHERE p.post_type = 'product'
                  AND c.comment_type IN ({$types})
                  AND c.comment_parent {$parent} 0";

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Replies that will be left behind when multiple replies are off: every
     * reply except the first on each review. A thread always starts with a
     * direct reply, so the kept ones are the reviews that have one.
     */
    public static function countDroppedReplies()
    {
        global $wpdb;

        $types = self::typesInClause();

        $kept = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT r.comment_parent)
             FROM {$wpdb->comments} r
             INNER JOIN {$wpdb->comments} t ON t.comment_ID = r.comment_parent AND t.comment_parent = 0
             INNER JOIN {$wpdb->posts} p ON p.ID = r.comment_post_ID
             WHERE p.post_type = 'product'
               AND r.comment_type IN ({$types})
               AND t.comment_type IN ({$types})"
        );

        return max(0, self::countReplies() - $kept);
    }

    /**
     * Whether any product review exists past the given comment id.
     */
    public static function hasCommentsAfter($afterId)
    {
        global $wpdb;

        $types = self::typesInClause();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT c.comment_ID
             FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
             WHERE p.post_type = 'product'
               AND c.comment_type IN ({$types})
               AND c.comment_ID > %d
             LIMIT 1",
            (int) $afterId
        ));
    }

    /* -----------------------------------------------------------------
     | One page
     * ----------------------------------------------------------------- */

    /**
     * Migrate one page of comments after $afterId.
     *
     * @return array{processed:int,has_more:bool,last_id:int,migrated_ids:int[],errors:array}
     */
    public function migratePage($afterId, $perPage = 200)
    {
        global $wpdb;

        $types = self::typesInClause();

        $comments = $wpdb->get_results($wpdb->prepare(
            "SELECT c.comment_ID, c.comment_post_ID, c.comment_parent, c.comment_author,
                    c.comment_author_email, c.comment_author_IP, c.comment_date, c.comment_date_gmt,
                    c.comment_content, c.comment_approved, c.user_id
             FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
             WHERE p.post_type = 'product'
               AND c.comment_type IN ({$types})
               AND c.comment_ID > %d
             ORDER BY c.comment_ID ASC
             LIMIT %d",
            (int) $afterId,
            (int) $perPage
        ));

        $result = [
            'processed'    => 0,
            'has_more'     => false,
            'last_id'      => (int) $afterId,
            'migrated_ids' => [],
            'errors'       => [],
        ];

        if (!$comments) {
            return $result;
        }

        $commentIds = wp_list_pluck($comments, 'comment_ID');
        $meta = $this->loadMeta($commentIds);

        // The id-mapping lookups read comment meta per row; one query fills it.
        update_meta_cache('comment', $commentIds);

        // One page commits or rolls back as a whole, mapping meta included,
        // so a crash mid-page leaves nothing half-written behind the cursor.
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($comments as $comment) {
                $result['last_id'] = (int) $comment->comment_ID;

                $outcome = $this->migrateComment($comment, $meta);
                if ($outcome === true || $outcome === 'existing') {
                    // A retry re-reads rows already imported; only new ones count.
                    if ($outcome === true) {
                        $result['processed']++;
                    }
                    $result['migrated_ids'][] = (int) $comment->comment_ID;
                } elseif (is_array($outcome)) {
                    $result['errors'][] = $outcome;
                }
            }
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $wpdb->query('COMMIT');

        $result['has_more'] = count($comments) >= (int) $perPage;

        return $result;
    }

    /**
     * @return true|string|array true inserted, 'existing' already imported, array skip/failure descriptor
     */
    protected function migrateComment($comment, array $meta)
    {
        $commentId = (int) $comment->comment_ID;

        // A retry re-reads imported rows; leave them as FluentCart has them,
        // so moderation and edits made after the import survive.
        if (ReviewWriter::existingRow($commentId, self::WC_TO_REVIEW_META)) {
            return 'existing';
        }

        $content   = trim((string) $comment->comment_content);
        $rating    = (int) ($meta[$commentId]['rating'] ?? 0);
        $isReply   = ((int) $comment->comment_parent) > 0;

        $postId = $this->fctProductId((int) $comment->comment_post_ID);
        if (!$postId) {
            return $this->skip($commentId, 'woo_review_product_not_migrated', $comment);
        }

        // A row with neither words nor a rating renders as an empty card.
        if ($content === '' && !$rating) {
            return $this->skip($commentId, 'woo_review_empty_content', $comment);
        }

        if ($isReply && !$this->allowMultipleReplies) {
            $rootId = ReviewWriter::rootReviewId((int) $comment->comment_parent, self::WC_TO_REVIEW_META);

            // A reply to a reply that was itself dropped has no mapped parent,
            // but it belongs to the same review and is dropped for the same reason.
            if (!$rootId) {
                $rootId = ReviewWriter::rootReviewId($this->sourceRootId((int) $comment->comment_parent), self::WC_TO_REVIEW_META);
            }

            // Ascending-id order means the first reply reaching this check is
            // the oldest one, so "the review already has a reply" is exactly
            // the first-reply-only rule. Re-running is safe: a reply that was
            // already migrated returned early above.
            if ($rootId && ReviewWriter::hasReply($rootId)) {
                return $this->skip($commentId, 'woo_review_extra_reply', $comment);
            }
        }

        $data = ReviewData::make([
            'sourceId'       => $commentId,
            'parentSourceId' => (int) $comment->comment_parent,
            'postId'         => $postId,
            'reviewerName'   => (string) $comment->comment_author,
            'reviewerEmail'  => (string) $comment->comment_author_email,
            'content'        => $content,
            'rating'         => $isReply ? null : $rating,
            'status'         => self::status($comment->comment_approved),
            'isVerified'     => !empty($meta[$commentId]['verified']) ? 1 : 0,
            'isAdminReply'   => $isReply ? $this->isStaff((int) $comment->user_id) : 0,
            'userId'         => (int) $comment->user_id ?: null,
            'customerId'     => $this->fctCustomerId((string) $comment->comment_author_email),
            // Woo records THAT the reviewer bought the product, never which
            // order, so there is no order to link without inventing one.
            'orderId'        => null,
            'ipAddress'      => (string) $comment->comment_author_IP,
            'media'          => $isReply ? [] : $this->mediaItems($commentId, $comment, $meta),
            'createdAt'      => self::createdAt($comment),
            'source'         => self::SOURCE,
            'mappingKeys'    => ['source' => self::WC_TO_REVIEW_META],
        ]);

        $written = ReviewWriter::write($data);

        if (is_wp_error($written)) {
            $code = $written->get_error_code() === 'review_orphan_parent'
                ? 'woo_review_orphan_parent'
                : 'woo_review_write_failed';

            return $this->skip($commentId, $code, $comment, $written->get_error_message());
        }

        return true;
    }

    /* -----------------------------------------------------------------
     | Mapping helpers
     * ----------------------------------------------------------------- */

    /**
     * Top-level ancestor of a source comment. Bounded so a corrupt parent
     * loop cannot spin forever.
     */
    protected function sourceRootId($commentId)
    {
        for ($hops = 0; $commentId && $hops < 50; $hops++) {
            $source = get_comment($commentId);
            if (!$source || !(int) $source->comment_parent) {
                break;
            }
            $commentId = (int) $source->comment_parent;
        }

        return (int) $commentId;
    }

    /**
     * GMT creation date. Some importers leave comment_date_gmt zeroed, so
     * fall back to the local date; '' lets the writer use now.
     */
    protected static function createdAt($comment)
    {
        $gmt = (string) $comment->comment_date_gmt;
        if ($gmt !== '' && strpos($gmt, '0000-00-00') !== 0) {
            return $gmt;
        }

        $local = (string) ($comment->comment_date ?? '');
        if ($local !== '' && strpos($local, '0000-00-00') !== 0) {
            return get_gmt_from_date($local);
        }

        return '';
    }

    /**
     * Map a WordPress comment_approved value to FluentCart's review status
     * vocabulary. 'post-trashed' appears when the product itself was trashed.
     */
    public static function status($approved)
    {
        $maps = [
            '1'            => 'approved',
            'approve'      => 'approved',
            '0'            => 'pending',
            'hold'         => 'pending',
            'spam'         => 'spam',
            'trash'        => 'trash',
            'post-trashed' => 'trash',
        ];

        return $maps[(string) $approved] ?? 'pending';
    }

    protected function fctProductId($wcProductId)
    {
        if (!$wcProductId) {
            return 0;
        }

        return (int) get_post_meta($wcProductId, MigratorHelper::WC_TO_FCT_META, true);
    }

    /**
     * Existing FluentCart customer for this email, or null.
     *
     * Never creates one: a reviewer who never ordered is not a customer, and
     * the missing_customers step already owns that question. Uses the same
     * query-builder lookup as CustomerWriter, the migrator's own convention.
     */
    protected function fctCustomerId($email)
    {
        $email = trim($email);
        if (!$email) {
            return null;
        }

        $key = strtolower($email);
        if (array_key_exists($key, $this->customerCache)) {
            return $this->customerCache[$key];
        }

        $customer = fluentCart('db')->table('fct_customers')->where('email', $email)->first();

        $this->customerCache[$key] = $customer ? (int) $customer->id : null;

        return $this->customerCache[$key];
    }

    /**
     * Woo has no admin-reply flag, so it is derived from the author's caps.
     * Cached per user id — the same few staff accounts wrote every reply.
     */
    protected function isStaff($userId)
    {
        if (!$userId) {
            return 0;
        }

        if (isset($this->staffCache[$userId])) {
            return $this->staffCache[$userId];
        }

        // moderate_comments, not edit_posts: Contributors can edit posts but
        // are not store staff.
        $isStaff = user_can($userId, 'manage_woocommerce') || user_can($userId, 'moderate_comments');

        $this->staffCache[$userId] = $isStaff ? 1 : 0;

        return $this->staffCache[$userId];
    }

    /**
     * Photo items for a review, in Pro's other_info.media shape. Only image
     * attachments that still exist are kept.
     *
     * @return array
     */
    protected function mediaItems($commentId, $comment, array $meta)
    {
        $attachmentIds = $meta[$commentId][self::PHOTO_META] ?? [];

        // Lets a site on another review plugin hand over its attachment ids.
        $attachmentIds = (array) apply_filters('fluent_cart_migrator_woo_review_attachment_ids', $attachmentIds, $comment);
        $attachmentIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $attachmentIds)))), 0, self::MAX_PHOTOS);

        if (!$attachmentIds) {
            return [];
        }

        _prime_post_caches($attachmentIds, false, true);

        $items = [];
        foreach ($attachmentIds as $attachmentId) {
            $attachment = get_post($attachmentId);
            if (!$attachment || $attachment->post_type !== 'attachment' || strpos((string) $attachment->post_mime_type, 'image/') !== 0) {
                continue;
            }

            $filePath = get_attached_file($attachmentId);

            $items[] = [
                'attachment_id' => $attachmentId,
                'url'           => (string) wp_get_attachment_url($attachmentId),
                'type'          => 'image',
                'name'          => sanitize_text_field($filePath ? basename($filePath) : $attachment->post_title),
                'size'          => ($filePath && file_exists($filePath)) ? (int) filesize($filePath) : 0,
            ];
        }

        return $items;
    }

    /**
     * Turn reviews off on every migrated product whose WooCommerce reviews
     * are closed. One set-based statement; re-running it changes nothing.
     *
     * @return int products updated
     */
    public static function syncClosedProductReviews()
    {
        global $wpdb;

        $details = $wpdb->prefix . 'fct_product_details';

        // $wpdb, not the query builder: WPFluent turns every double quote in
        // a query into a backtick, which breaks JSON text. JSON_VALID skips a
        // malformed row instead of failing the whole statement.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$details} d
             INNER JOIN {$wpdb->postmeta} pm ON pm.meta_key = %s AND d.post_id = CAST(pm.meta_value AS UNSIGNED)
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             SET d.other_info = JSON_MERGE_PATCH(
                     IF(d.other_info IS NULL OR d.other_info = '', '{}', d.other_info),
                     JSON_OBJECT('reviews_enabled', 'no')
                 ),
                 d.updated_at = %s
             WHERE p.post_type = 'product'
               AND p.comment_status = 'closed'
               AND (
                   d.other_info IS NULL OR d.other_info = ''
                   OR (JSON_VALID(d.other_info) AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.other_info, '$.reviews_enabled')), '') <> 'no')
               )",
            MigratorHelper::WC_TO_FCT_META,
            gmdate('Y-m-d H:i:s')
        ));

        return $updated === false ? 0 : (int) $updated;
    }

    /**
     * rating, verified and photo ids for a page of comments, in one query.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function loadMeta(array $commentIds)
    {
        global $wpdb;

        $commentIds = array_map('intval', array_filter($commentIds));
        if (!$commentIds) {
            return [];
        }

        $ids  = implode(',', $commentIds);
        $rows = $wpdb->get_results(
            "SELECT comment_id, meta_key, meta_value
             FROM {$wpdb->commentmeta}
             WHERE comment_id IN ({$ids})
               AND meta_key IN ('rating', 'verified', '" . self::PHOTO_META . "')
             ORDER BY meta_id ASC"
        );

        $meta = [];
        foreach ($rows as $row) {
            // Photos are one row per attachment, so they collect into a list.
            if ($row->meta_key === self::PHOTO_META) {
                $meta[(int) $row->comment_id][self::PHOTO_META][] = $row->meta_value;
                continue;
            }
            $meta[(int) $row->comment_id][$row->meta_key] = $row->meta_value;
        }

        return $meta;
    }

    protected static function typesInClause()
    {
        $quoted = array_map(function ($type) {
            return "'" . esc_sql($type) . "'";
        }, self::REVIEW_TYPES);

        return implode(',', $quoted);
    }

    /**
     * Build the structured skip descriptor the step turns into a log entry.
     */
    protected function skip($commentId, $code, $comment, $message = '')
    {
        $reason = MigrationLog::reason($code);

        return [
            'id'      => $commentId,
            'code'    => $code,
            'message' => $message ?: $reason['title'],
            'context' => [
                'product_id' => (int) $comment->comment_post_ID,
                'author'     => (string) $comment->comment_author,
                'name'       => (string) $comment->comment_author,
                'email'      => (string) $comment->comment_author_email,
                'date'       => self::createdAt($comment),
                'url'        => get_edit_comment_link($commentId) ?: '',
            ],
        ];
    }
}
