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

    /** @var int replies skipped because multiple replies are not enabled */
    protected $skippedReplies = 0;

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

    /* -----------------------------------------------------------------
     | One page
     * ----------------------------------------------------------------- */

    /**
     * Migrate one page of comments after $afterId.
     *
     * @return array{processed:int,has_more:bool,last_id:int,skipped_replies:int,errors:array}
     */
    public function migratePage($afterId, $perPage = 200)
    {
        global $wpdb;

        $types = self::typesInClause();

        $comments = $wpdb->get_results($wpdb->prepare(
            "SELECT c.comment_ID, c.comment_post_ID, c.comment_parent, c.comment_author,
                    c.comment_author_email, c.comment_author_IP, c.comment_date_gmt,
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

        // skipped_replies is this migrator's running total, not a per-page
        // figure, so it must be seeded here as well: the loop that drives
        // migratePage() reads the LAST batch, and the last batch is the empty
        // one that ends the walk. Returning 0 there would erase the count.
        $result = [
            'processed'       => 0,
            'has_more'        => false,
            'last_id'         => (int) $afterId,
            'skipped_replies' => $this->skippedReplies,
            'errors'          => [],
        ];

        if (!$comments) {
            return $result;
        }

        $meta = $this->loadMeta(wp_list_pluck($comments, 'comment_ID'));

        foreach ($comments as $comment) {
            $result['last_id'] = (int) $comment->comment_ID;

            $outcome = $this->migrateComment($comment, $meta);
            if ($outcome === true) {
                $result['processed']++;
            } elseif (is_array($outcome)) {
                $result['errors'][] = $outcome;
            }
        }

        $result['has_more']        = count($comments) >= (int) $perPage;
        $result['skipped_replies'] = $this->skippedReplies;

        return $result;
    }

    /**
     * @return true|array true written, array skip/failure descriptor
     */
    protected function migrateComment($comment, array $meta)
    {
        $commentId = (int) $comment->comment_ID;
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

        $alreadyMigrated = (bool) ReviewWriter::existingId($commentId, self::WC_TO_REVIEW_META);

        if ($isReply && !$alreadyMigrated && !$this->allowMultipleReplies) {
            $rootId = ReviewWriter::rootReviewId((int) $comment->comment_parent, self::WC_TO_REVIEW_META);

            // Ascending-id order means the first reply reaching this check is
            // the oldest one, so "the review already has a reply" is exactly
            // the first-reply-only rule. Re-running is safe: a reply that was
            // already migrated took the branch above and is updated instead.
            if ($rootId && ReviewWriter::hasReply($rootId)) {
                $this->skippedReplies++;
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
            'createdAt'      => $comment->comment_date_gmt,
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

        $isStaff = user_can($userId, 'manage_woocommerce') || user_can($userId, 'edit_posts');

        $this->staffCache[$userId] = $isStaff ? 1 : 0;

        return $this->staffCache[$userId];
    }

    /**
     * rating + verified for a page of comments, in one query.
     *
     * @return array<int,array<string,string>>
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
               AND meta_key IN ('rating', 'verified')"
        );

        $meta = [];
        foreach ($rows as $row) {
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
                'date'       => (string) $comment->comment_date_gmt,
                'url'        => get_edit_comment_link($commentId) ?: '',
            ],
        ];
    }
}
