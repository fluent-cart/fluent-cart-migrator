<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * One fct_order_transactions row (charge or refund). `order_id`,
 * `subscription_id` and `uuid` are injected by the writer.
 */
class TransactionData
{
    public $orderType = 'payment';
    public $transactionType = 'charge';
    public $cardLast4 = '';
    public $cardBrand = '';
    public $vendorChargeId = '';
    public $paymentMethod = '';
    public $paymentMode = 'live';
    public $paymentMethodType = '';
    public $status = '';
    public $currency = '';
    public $total = 0;
    public $rate = 1;
    public $meta = [];
    public $createdAt = '';
    public $updatedAt = '';

    public static function make(array $data)
    {
        $txn = new self();
        foreach ($data as $key => $value) {
            if (property_exists($txn, $key)) {
                $txn->$key = $value;
            }
        }
        return $txn;
    }

    public function toArray()
    {
        return [
            'order_type'          => $this->orderType,
            'transaction_type'    => $this->transactionType,
            'card_last_4'         => $this->cardLast4,
            'card_brand'          => $this->cardBrand,
            'vendor_charge_id'    => $this->vendorChargeId,
            'payment_method'      => $this->paymentMethod,
            'payment_mode'        => $this->paymentMode,
            'payment_method_type' => $this->paymentMethodType,
            'status'              => $this->status,
            'currency'            => $this->currency,
            'total'               => $this->total,
            'rate'                => $this->rate,
            'meta'                => json_encode($this->meta),
            'created_at'          => $this->createdAt,
            'updated_at'          => $this->updatedAt,
        ];
    }
}
