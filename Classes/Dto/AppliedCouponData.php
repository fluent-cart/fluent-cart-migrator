<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * One fct_applied_coupons row. `order_id` is injected by the writer.
 */
class AppliedCouponData
{
    public $code = '';
    public $amount = 0;
    public $couponId = 0;
    public $createdAt = '';
    public $updatedAt = '';

    public static function make(array $data)
    {
        $c = new self();
        foreach ($data as $key => $value) {
            if (property_exists($c, $key)) {
                $c->$key = $value;
            }
        }
        return $c;
    }

    public function toArray()
    {
        return [
            'code'       => $this->code,
            'amount'     => $this->amount,
            'coupon_id'  => $this->couponId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
