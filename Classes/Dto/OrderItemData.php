<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * One fct_order_items row. `order_id` is injected by the writer after the order
 * insert; bundle children are nested and the writer back-patches their
 * line_meta.bundle_parent_item_id.
 */
class OrderItemData
{
    public $postId = 0;
    public $fulfillmentType = 'digital';
    public $paymentType = 'onetime';
    public $postTitle = '';
    public $title = '';
    public $objectId = null;
    public $quantity = 1;
    public $unitPrice = 0;
    public $cost = 0;
    public $subtotal = 0;
    public $taxAmount = 0;
    public $shippingCharge = 0;
    public $discountTotal = 0;
    public $lineTotal = 0;
    public $refundTotal = 0;
    public $rate = 1;
    public $otherInfo = [];
    public $lineMeta = [];
    public $cartIndex = 0;
    public $createdAt = '';
    public $updatedAt = '';

    /** @var OrderItemData[] optional nested bundle children */
    public $bundleItems = [];

    public static function make(array $data)
    {
        $item = new self();
        foreach ($data as $key => $value) {
            if (property_exists($item, $key)) {
                $item->$key = $value;
            }
        }
        return $item;
    }

    /**
     * The fct_order_items row (without order_id, which the writer sets).
     */
    public function toArray()
    {
        return [
            'post_id'          => $this->postId,
            'fulfillment_type' => $this->fulfillmentType,
            'payment_type'     => $this->paymentType,
            'post_title'       => $this->postTitle,
            'title'            => $this->title,
            'object_id'        => $this->objectId,
            'quantity'         => $this->quantity,
            'unit_price'       => $this->unitPrice,
            'cost'             => $this->cost,
            'subtotal'         => $this->subtotal,
            'tax_amount'       => $this->taxAmount,
            'shipping_charge'  => $this->shippingCharge,
            'discount_total'   => $this->discountTotal,
            'line_total'       => $this->lineTotal,
            'refund_total'     => $this->refundTotal,
            'rate'             => $this->rate,
            'other_info'       => json_encode($this->otherInfo),
            'line_meta'        => json_encode($this->lineMeta),
            'cart_index'       => $this->cartIndex,
            'created_at'       => $this->createdAt,
            'updated_at'       => $this->updatedAt,
        ];
    }
}
