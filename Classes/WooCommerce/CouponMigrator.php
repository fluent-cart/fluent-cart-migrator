<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCartMigrator\Classes\Dto\CouponData;
use FluentCartMigrator\Classes\Load\CouponWriter;
use FluentCartMigrator\Classes\Support\TaxonomyResolver;

/**
 * WooCommerce coupon source: Extract + Transform.
 *
 * Reads shop_coupon posts via WC_Coupon and maps each to a normalized
 * CouponData (FluentCart type/amount/conditions), then hands it to the shared
 * CouponWriter. Product restrictions are mapped to the migrated FluentCart
 * product ids; category restrictions follow the admin's taxonomy mapping, so
 * they land on whichever FluentCart taxonomy product_cat was mapped to (and are
 * dropped when product_cat is not migrated at all).
 */
class CouponMigrator
{
    /** @var array<int,int> WC product_cat term id => FluentCart term id */
    private $categoryMap = [];

    /** @var TaxonomyResolver|null lazy: the source→FluentCart taxonomy mapping */
    private $taxonomies = null;

    /**
     * @return array<string,int|\WP_Error> couponCode => fctCouponId|WP_Error
     */
    public function migrate()
    {
        $ids = get_posts([
            'post_type'   => 'shop_coupon',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
            'orderby'     => 'ID',
            'order'       => 'ASC',
        ]);

        $writer  = new CouponWriter();
        $results = [];

        foreach ($ids as $couponId) {
            try {
                $coupon = new \WC_Coupon($couponId);
                $code   = $coupon->get_code();
                if (!$code) {
                    continue;
                }
                $results[$code] = $writer->write($this->build($coupon));
            } catch (\Throwable $e) {
                $results['#' . $couponId] = new \WP_Error('woo_coupon_error', $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Whether a coupon applies to subscription renewals. WC Subscriptions'
     * `recurring_*` / `renewal_*` discount types do; `sign_up_fee*` and regular
     * cart/product coupons apply to the first payment only.
     */
    private function isRecurringCoupon($discountType)
    {
        return (strpos((string) $discountType, 'recurring') === 0 || strpos((string) $discountType, 'renewal') === 0)
            ? 'yes'
            : 'no';
    }

    private function build(\WC_Coupon $coupon)
    {
        $discountType = $coupon->get_discount_type();
        $amountRaw    = (float) $coupon->get_amount();

        if (in_array($discountType, ['percent', 'percent_product'], true)) {
            $type   = 'percentage';
            $amount = $amountRaw;
        } else {
            $type   = 'fixed';
            $amount = MigratorHelper::toCents($amountRaw);
        }

        // A pure free-shipping coupon (no discount value) maps to FluentCart's
        // dedicated free_shipping type.
        if ($amount == 0 && $coupon->get_free_shipping()) {
            $type   = 'free_shipping';
            $amount = 0;
        }

        $conditions = [
            'max_uses'            => (int) $coupon->get_usage_limit(),
            'max_per_customer'    => (int) $coupon->get_usage_limit_per_user(),
            'max_discount_amount' => 0,
            'max_purchase_amount' => MigratorHelper::toCents($coupon->get_maximum_amount()),
            'min_purchase_amount' => MigratorHelper::toCents($coupon->get_minimum_amount()),
            'included_products'   => $this->mapProducts($coupon->get_product_ids()),
            'excluded_products'   => $this->mapProducts($coupon->get_excluded_product_ids()),
            'included_categories' => $this->mapCategories($coupon->get_product_categories()),
            'excluded_categories' => $this->mapCategories($coupon->get_excluded_product_categories()),
            'is_recurring'        => $this->isRecurringCoupon($discountType),
        ];

        $expires = $coupon->get_date_expires();

        return CouponData::make([
            'title'      => $coupon->get_code(),
            'code'       => $coupon->get_code(),
            'priority'   => 1,
            'type'       => $type,
            'amount'     => $amount,
            'conditions' => $conditions,
            'useCount'   => (int) $coupon->get_usage_count(),
            'status'     => $coupon->get_status() === 'publish' ? 'active' : 'disabled',
            'notes'      => (string) $coupon->get_description(),
            'stackable'  => $coupon->get_individual_use() ? 'no' : 'yes',
            'startDate'  => null,
            'endDate'    => $expires ? MigratorHelper::date($expires) : null,
            'createdAt'  => MigratorHelper::date($coupon->get_date_created()),
            'updatedAt'  => MigratorHelper::date($coupon->get_date_modified()),
        ]);
    }

    /**
     * Map WC product ids → migrated FluentCart post ids (drop unmigrated).
     */
    private function mapProducts($wcIds)
    {
        $out = [];
        foreach ((array) $wcIds as $wcId) {
            $fctId = (int) get_post_meta((int) $wcId, MigratorHelper::WC_TO_FCT_META, true);
            if ($fctId) {
                $out[] = $fctId;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Map WC product_cat term ids → FluentCart term ids, through the mapping.
     */
    private function mapCategories($wcTermIds)
    {
        $out = [];
        foreach ((array) $wcTermIds as $wcTermId) {
            $fctId = $this->fctCategoryId((int) $wcTermId);
            if ($fctId) {
                $out[] = $fctId;
            }
        }
        return array_values(array_unique($out));
    }

    private function fctCategoryId($wcTermId)
    {
        if (array_key_exists($wcTermId, $this->categoryMap)) {
            return $this->categoryMap[$wcTermId];
        }

        if ($this->taxonomies === null) {
            $this->taxonomies = new TaxonomyResolver('woocommerce');
        }

        // Resolving through the resolver also creates the term if the products
        // step has not run yet, so the restriction survives either order.
        $id = $this->taxonomies->mapTermId('product_cat', $wcTermId);

        $this->categoryMap[$wcTermId] = $id;
        return $id;
    }
}
