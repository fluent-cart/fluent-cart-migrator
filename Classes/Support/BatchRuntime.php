<?php

namespace FluentCartMigrator\Classes\Support;

/**
 * Runtime memory hygiene for bulk migration loops, shared by every source.
 *
 * WordPress (and WooCommerce on top of it) caches every loaded post/order/meta
 * in the in-memory object cache, SAVEQUERIES retains every query, and dev
 * tooling such as the FluentStudio debugger buffers every query plus a
 * backtrace until shutdown. Across thousands of records this exhausts PHP's
 * memory limit, so the paginated loop flushes the runtime caches between pages
 * (never touching a persistent cache backend) and stops a batch before it
 * crosses the limit — the caller resumes in the next (fresh) request.
 */
class BatchRuntime
{
    public static function freeMemory()
    {
        global $wpdb, $wp_object_cache;

        if (is_object($wpdb) && property_exists($wpdb, 'queries')) {
            $wpdb->queries = [];
        }

        // WP 6.0+ asks the (possibly persistent) object-cache drop-in to drop
        // only its in-memory runtime cache. Fall back to clearing the known
        // properties directly; a drop-in may declare them private without a
        // __set() shim, so never let that abort the batch.
        if (function_exists('wp_cache_flush_runtime') && (!function_exists('wp_cache_supports') || wp_cache_supports('flush_runtime'))) {
            wp_cache_flush_runtime();
        } elseif (is_object($wp_object_cache)) {
            foreach (['cache', 'group_ops', 'stats', 'memcache_debug'] as $prop) {
                if (!property_exists($wp_object_cache, $prop)) {
                    continue;
                }
                try {
                    $wp_object_cache->$prop = [];
                } catch (\Throwable $e) {
                    // not writable from here — skip
                }
            }
        }

        if (isset($GLOBALS['fluent_query_buffer']) && is_array($GLOBALS['fluent_query_buffer'])) {
            $GLOBALS['fluent_query_buffer'] = [];
        }
    }

    /**
     * The PHP memory_limit in bytes (0 = unlimited / not set).
     */
    public static function memoryLimitBytes()
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $num  = (int) $value;
        switch ($unit) {
            case 'g':
                $num *= 1024;
                // no break
            case 'm':
                $num *= 1024;
                // no break
            case 'k':
                $num *= 1024;
        }

        return $num;
    }

    /**
     * True when the process is using more than $fraction of the memory limit.
     */
    public static function memoryNearLimit($fraction = 0.7)
    {
        $limit = self::memoryLimitBytes();
        if ($limit <= 0) {
            return false; // unlimited — e.g. CLI
        }

        return memory_get_usage(true) >= (int) ($limit * $fraction);
    }
}
