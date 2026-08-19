<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * One fct_order_tax_rate row. `order_id` is injected by the writer.
 */
class TaxRateData
{
    public $taxRateId = null;
    public $shippingTax = 0;
    public $orderTax = 0;
    public $totalTax = 0;
    public $meta = [];
    public $createdAt = '';
    public $updatedAt = '';

    public static function make(array $data)
    {
        $t = new self();
        foreach ($data as $key => $value) {
            if (property_exists($t, $key)) {
                $t->$key = $value;
            }
        }
        return $t;
    }

    public function toArray()
    {
        return [
            'tax_rate_id'  => $this->taxRateId,
            'shipping_tax' => $this->shippingTax,
            'order_tax'    => $this->orderTax,
            'total_tax'    => $this->totalTax,
            'meta'         => json_encode($this->meta),
            'created_at'   => $this->createdAt,
            'updated_at'   => $this->updatedAt,
        ];
    }
}
