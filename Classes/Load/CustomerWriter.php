<?php

namespace FluentCartMigrator\Classes\Load;

use FluentCartMigrator\Classes\Dto\CustomerData;

/**
 * Find-or-create a FluentCart customer, deduped by email, with a per-batch
 * in-memory cache. Source-agnostic: every source builds a CustomerData and
 * calls this. Replaces the per-source customer logic that used to live in each
 * MigratorHelper.
 */
class CustomerWriter
{
    /** @var array<string,object> email (lowercased) => fct_customers row */
    protected static $cache = [];

    /**
     * @return object|\WP_Error fct_customers row
     */
    public static function findOrCreate(CustomerData $customer)
    {
        $email = trim((string) $customer->email);
        if (!$email) {
            return new \WP_Error('customer_no_email', 'Customer email is empty.');
        }

        $key = strtolower($email);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $existing = fluentCart('db')->table('fct_customers')->where('email', $email)->first();
        if ($existing) {
            self::$cache[$key] = $existing;
            return $existing;
        }

        $row         = $customer->toArray();
        $row['uuid'] = md5($email . '_' . wp_generate_uuid4());
        if (empty($row['created_at'])) {
            $row['created_at'] = current_time('mysql');
        }
        if (empty($row['updated_at'])) {
            $row['updated_at'] = current_time('mysql');
        }

        $id  = fluentCart('db')->table('fct_customers')->insertGetId($row);
        $new = fluentCart('db')->table('fct_customers')->find($id);

        self::$cache[$key] = $new;

        return $new;
    }

    /**
     * Clear the per-batch cache (call between pages, like the source resetCaches).
     */
    public static function resetCache()
    {
        self::$cache = [];
    }
}
