<?php

namespace FluentCartMigrator\Classes\Support;

/**
 * Structured skip/failure log shared by the source-generic migrators.
 *
 * Every record a migration could not bring over is stored in the source's
 * failed-log option as a small array keyed by record id. Entries written by the
 * newer sources carry a reason *code* (mapped to a translated title, a
 * severity — "skipped" for expected/unmigratable data vs "failed" for real
 * errors — and a hint on what to do about it) plus a bit of context (order
 * number, status, date, total, email, edit link) so the UI and the CSV report
 * can explain each record without re-reading the source store.
 *
 * Old-style entries (a bare message string, or an array with only
 * message/stage) are normalized into the same shape, so the report works for
 * the EDD source too — just with fewer columns filled.
 */
class MigrationLog
{
    const SKIPPED = 'skipped';
    const FAILED  = 'failed';

    /**
     * Reason catalog: code => [severity, title, hint].
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function reasons()
    {
        return [
            'woo_empty_order' => [
                self::SKIPPED,
                __('No line items in WooCommerce', 'fluent-cart-migrator'),
                __('WooCommerce has no products recorded for this order (typical for very old orders or orders whose items were deleted), so there is nothing to migrate. Open the order in WooCommerce — if products are shown there, please report this order.', 'fluent-cart-migrator'),
            ],
            'customer_no_email' => [
                self::SKIPPED,
                __('No customer email', 'fluent-cart-migrator'),
                __('The order has no billing email and no customer account, so it cannot be attached to a FluentCart customer. These are usually abandoned or placeholder orders.', 'fluent-cart-migrator'),
            ],
            'woo_unsupported_status' => [
                self::SKIPPED,
                __('Unregistered order status', 'fluent-cart-migrator'),
                __('The order uses a status that is not currently registered in WooCommerce (usually added by a plugin that is no longer active). Re-activate that plugin and run the orders step again to migrate these orders.', 'fluent-cart-migrator'),
            ],
            'customer_user_no_email' => [
                self::SKIPPED,
                __('User has no email address', 'fluent-cart-migrator'),
                __('The WordPress user account has no email address, so it cannot be created as a FluentCart customer.', 'fluent-cart-migrator'),
            ],
            'woo_no_order' => [
                self::FAILED,
                __('Order could not be loaded', 'fluent-cart-migrator'),
                __('WooCommerce returned no order for this id; it may have been deleted while the migration was running.', 'fluent-cart-migrator'),
            ],
            'order_validation' => [
                self::FAILED,
                __('Order totals do not reconcile', 'fluent-cart-migrator'),
                __('The migrated totals did not add up, so the order was not written to avoid corrupting the books. Please report the order id and details to support.', 'fluent-cart-migrator'),
            ],
            'woo_order_exception' => [
                self::FAILED,
                __('Unexpected error', 'fluent-cart-migrator'),
                __('An unexpected error happened while migrating this record. Please report the details to support.', 'fluent-cart-migrator'),
            ],
            'woo_review_product_not_migrated' => [
                self::SKIPPED,
                __('Product not migrated', 'fluent-cart-migrator'),
                __('This review belongs to a WooCommerce product that has not been migrated, so there is nothing to attach it to. Run the products step, then run the reviews step again.', 'fluent-cart-migrator'),
            ],
            'woo_review_orphan_parent' => [
                self::SKIPPED,
                __('Parent review is missing', 'fluent-cart-migrator'),
                __('This reply answers a review that no longer exists in WooCommerce, so it has nothing to hang on. Replies without a parent cannot be displayed.', 'fluent-cart-migrator'),
            ],
            'woo_review_empty_content' => [
                self::SKIPPED,
                __('Empty review', 'fluent-cart-migrator'),
                __('The review has no text and no star rating, so it would render as a blank card. Nothing was lost — there was nothing to show.', 'fluent-cart-migrator'),
            ],
            'woo_review_extra_reply' => [
                self::SKIPPED,
                __('Additional reply needs Pro', 'fluent-cart-migrator'),
                __('FluentCart shows one reply per review unless multiple replies are enabled, so only the first reply was imported. The reply is still in WooCommerce: enable multiple replies (FluentCart Pro), reset the migration, and run the reviews step again to bring them all across.', 'fluent-cart-migrator'),
            ],
            'woo_review_write_failed' => [
                self::FAILED,
                __('Review could not be saved', 'fluent-cart-migrator'),
                __('Writing this review to FluentCart failed. Please report the review id and details to support.', 'fluent-cart-migrator'),
            ],
        ];
    }

    /**
     * @return array{severity:string,title:string,hint:string}
     */
    public static function reason($code)
    {
        $reasons = self::reasons();
        if ($code && isset($reasons[$code])) {
            list($severity, $title, $hint) = $reasons[$code];
        } else {
            $severity = self::FAILED;
            $title    = __('Migration failed', 'fluent-cart-migrator');
            $hint     = __('The record could not be migrated. See the details for the exact message.', 'fluent-cart-migrator');
        }

        return ['severity' => $severity, 'title' => $title, 'hint' => $hint];
    }

    /* -----------------------------------------------------------------
     | Storage
     * ----------------------------------------------------------------- */

    /**
     * @return array raw option contents (key => entry)
     */
    public static function all($optionKey)
    {
        $logs = get_option($optionKey, []);
        return is_array($logs) ? $logs : [];
    }

    /**
     * Append/replace one entry. $key is the record id for orders (kept numeric
     * for back-compat with the older logs) or "customer:{id}" for customers.
     */
    public static function record($optionKey, $key, array $entry)
    {
        $logs = self::all($optionKey);

        $entry['type'] = $entry['type'] ?? 'order';
        $entry['id']   = $entry['id'] ?? self::idFromKey($key);
        if (empty($entry['severity'])) {
            $entry['severity'] = self::reason($entry['code'] ?? '')['severity'];
        }
        $entry['logged_at'] = current_time('mysql');

        $logs[$key] = $entry;
        update_option($optionKey, $logs, false);

        return $entry;
    }

    public static function key($type, $id)
    {
        return $type === 'order' ? (string) $id : $type . ':' . $id;
    }

    protected static function idFromKey($key)
    {
        $key = (string) $key;
        $pos = strrpos($key, ':');
        return $pos === false ? $key : substr($key, $pos + 1);
    }

    protected static function typeFromKey($key)
    {
        $key = (string) $key;
        $pos = strpos($key, ':');
        return $pos === false ? 'order' : substr($key, 0, $pos);
    }

    /* -----------------------------------------------------------------
     | Normalization / reporting
     * ----------------------------------------------------------------- */

    /**
     * Normalize one raw entry (string or array, old or new shape).
     *
     * @return array{key:string,type:string,id:string,severity:string,code:string,reason:string,hint:string,message:string,stage:string,context:array}
     */
    public static function normalize($key, $entry)
    {
        if (!is_array($entry)) {
            $entry = ['message' => (string) $entry];
        }

        $code   = (string) ($entry['code'] ?? $entry['error_type'] ?? '');
        $reason = self::reason($code);

        $context = isset($entry['context']) && is_array($entry['context']) ? $entry['context'] : [];

        return [
            'key'      => (string) $key,
            'type'     => (string) ($entry['type'] ?? self::typeFromKey($key)),
            'id'       => (string) ($entry['id'] ?? self::idFromKey($key)),
            'severity' => (string) ($entry['severity'] ?? $reason['severity']),
            'code'     => $code,
            'reason'   => $reason['title'],
            'hint'     => $reason['hint'],
            'message'  => (string) ($entry['message'] ?? ''),
            'stage'    => (string) ($entry['stage'] ?? ''),
            'context'  => [
                'number'   => (string) ($context['number'] ?? ''),
                'status'   => (string) ($context['status'] ?? ''),
                'date'     => (string) ($context['date'] ?? ''),
                'total'    => (string) ($context['total'] ?? ''),
                'currency' => (string) ($context['currency'] ?? ''),
                'email'    => (string) ($context['email'] ?? ''),
                'name'     => (string) ($context['name'] ?? ''),
                'url'      => (string) ($context['url'] ?? ''),
            ],
        ];
    }

    /**
     * @return array<int,array> normalized entries, in log order
     */
    public static function entries(array $logs)
    {
        $out = [];
        foreach ($logs as $key => $entry) {
            $out[] = self::normalize($key, $entry);
        }
        return $out;
    }

    /**
     * @param array<int,array> $entries normalized
     * @return array{total:int,skipped:int,failed:int,orders:int,customers:int}
     */
    public static function counts(array $entries)
    {
        $counts = ['total' => count($entries), 'skipped' => 0, 'failed' => 0, 'orders' => 0, 'customers' => 0];

        foreach ($entries as $entry) {
            if ($entry['severity'] === self::SKIPPED) {
                $counts['skipped']++;
            } else {
                $counts['failed']++;
            }
            if ($entry['type'] === 'customer') {
                $counts['customers']++;
            } else {
                $counts['orders']++;
            }
        }

        return $counts;
    }

    /**
     * Group normalized entries by (type, code): one row per reason, biggest first.
     *
     * @param array<int,array> $entries normalized
     * @return array<int,array{key:string,type:string,code:string,severity:string,title:string,hint:string,count:int}>
     */
    public static function groups(array $entries)
    {
        $groups = [];
        foreach ($entries as $entry) {
            $key = $entry['type'] . '|' . ($entry['code'] ?: '_');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'      => $key,
                    'type'     => $entry['type'],
                    'code'     => $entry['code'],
                    'severity' => $entry['severity'],
                    'title'    => $entry['reason'],
                    'hint'     => $entry['hint'],
                    'count'    => 0,
                ];
            }
            $groups[$key]['count']++;
        }

        $groups = array_values($groups);
        usort($groups, function ($a, $b) {
            // Failed first (they need attention), then by size.
            if ($a['severity'] !== $b['severity']) {
                return $a['severity'] === self::FAILED ? -1 : 1;
            }
            return $b['count'] - $a['count'];
        });

        return $groups;
    }

    /* -----------------------------------------------------------------
     | CSV report
     * ----------------------------------------------------------------- */

    /**
     * Column headers for the CSV report (also used by the CLI export).
     */
    public static function csvHeaders()
    {
        return [
            'Record Type',
            'Record ID',
            'Number',
            'Date',
            'Status',
            'Total',
            'Currency',
            'Customer Email',
            'Customer Name',
            'Result',
            'Reason Code',
            'Reason',
            'Details',
            'What To Do',
            'Stage',
            'Source Link',
        ];
    }

    /**
     * Flatten one normalized entry to a CSV row (same order as csvHeaders()).
     */
    public static function csvRow(array $entry)
    {
        $c = $entry['context'];

        return array_map([__CLASS__, 'csvSafe'], [
            $entry['type'],
            $entry['id'],
            $c['number'],
            $c['date'],
            $c['status'],
            $c['total'],
            $c['currency'],
            $c['email'],
            $c['name'],
            $entry['severity'],
            $entry['code'],
            $entry['reason'],
            $entry['message'],
            $entry['hint'],
            $entry['stage'],
            $c['url'],
        ]);
    }

    /**
     * Write the full report (header + one row per entry) to an open stream.
     *
     * @param array<int,array> $entries normalized
     * @param resource         $stream
     */
    public static function csv(array $entries, $stream)
    {
        fputcsv($stream, self::csvHeaders());
        foreach ($entries as $entry) {
            fputcsv($stream, self::csvRow($entry));
        }
    }

    /**
     * Neutralize spreadsheet formula injection (cells starting with = + - @).
     */
    protected static function csvSafe($value)
    {
        $value = (string) $value;
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'" . $value;
        }
        return $value;
    }
}
