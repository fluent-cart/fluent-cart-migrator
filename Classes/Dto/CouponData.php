<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * A normalized coupon for fct_coupons. The writer upserts by `code` (unique),
 * so re-runs update in place. Mirrors the column shape the EDD source proved
 * against FluentCart.
 */
class CouponData
{
    public $title = '';
    public $code = '';
    public $priority = 1;
    public $type = 'fixed';            // fixed | percentage | free_shipping
    public $amount = 0;                // cents for fixed; raw percent for percentage
    public $conditions = [];
    public $useCount = 0;
    public $status = 'active';         // active | disabled
    public $notes = '';
    public $stackable = 'yes';         // 'no' when the source coupon is individual-use
    public $showOnCheckout = 'yes';
    public $startDate = null;
    public $endDate = null;
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
            'title'            => $this->title,
            'code'             => $this->code,
            'priority'         => $this->priority,
            'type'             => $this->type,
            'amount'           => $this->amount,
            'conditions'       => json_encode($this->conditions),
            'use_count'        => $this->useCount,
            'status'           => $this->status,
            'notes'            => (string) $this->notes,
            'stackable'        => $this->stackable,
            'show_on_checkout' => $this->showOnCheckout,
            'start_date'       => $this->startDate,
            'end_date'         => $this->endDate,
            'created_at'       => $this->createdAt,
            'updated_at'       => $this->updatedAt,
        ];
    }
}
