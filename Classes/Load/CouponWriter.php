<?php

namespace FluentCartMigrator\Classes\Load;

use FluentCartMigrator\Classes\Dto\CouponData;

/**
 * The shared "Load" for coupons: upserts a normalized CouponData into
 * fct_coupons keyed by the unique `code`, so re-runs update in place rather
 * than duplicating. Source-agnostic.
 *
 * @return int fct_coupons id
 */
class CouponWriter
{
    public function write(CouponData $coupon)
    {
        $db  = fluentCart('db');
        $row = $coupon->toArray();

        $existing = $db->table('fct_coupons')->where('code', $coupon->code)->first();
        if ($existing) {
            $db->table('fct_coupons')->where('id', $existing->id)->update($row);
            return (int) $existing->id;
        }

        return (int) $db->table('fct_coupons')->insertGetId($row);
    }
}
