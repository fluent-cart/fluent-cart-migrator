<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * The normalized order aggregate handed to OrderWriter. The source Transform
 * produces fully reconciled, cents-based, FluentCart-status-mapped values; the
 * writer owns idempotency, uuid/receipt/invoice generation, validation and the
 * actual inserts.
 *
 * Sub-collections hold child DTOs. `licenses` and `activities` are optional and
 * only populated by sources that have them (EDD); WooCommerce leaves them empty
 * and the writer no-ops.
 */
class OrderData
{
    public $id = 0;
    public $status = '';
    public $parentId = 0;
    public $receiptNumber = 0;
    public $invoiceNo = '';
    public $fulfillmentType = 'digital';
    public $type = 'payment';
    public $mode = 'live';
    public $shippingStatus = '';
    public $customerId = 0;
    public $paymentMethod = 'offline';
    public $paymentStatus = '';
    public $paymentMethodTitle = '';
    public $currency = '';
    public $subtotal = 0;
    public $discountTax = 0;
    public $manualDiscountTotal = 0;
    public $couponDiscountTotal = 0;
    public $shippingTax = 0;
    public $shippingTotal = 0;
    public $feeTotal = 0;
    public $taxTotal = 0;
    public $taxBehavior = 0;
    public $totalAmount = 0;
    public $totalPaid = 0;
    public $totalRefund = 0;
    public $rate = 1;
    public $note = '';
    public $ipAddress = '';
    public $completedAt = null;
    public $refundedAt = null;
    public $config = [];
    public $createdAt = '';
    public $updatedAt = '';

    /** @var OrderItemData[] */
    public $items = [];
    /** @var TransactionData[] charge transactions */
    public $transactions = [];
    /** @var TransactionData[] refund transactions */
    public $refunds = [];
    /** @var AddressData[] */
    public $addresses = [];
    /** @var AppliedCouponData[] */
    public $coupons = [];
    /** @var TaxRateData[] */
    public $taxRates = [];
    /** @var SubscriptionData[] subscriptions this order creates (parent orders) */
    public $subscriptions = [];
    /** @var int|null existing subscription id this order's transactions link to (renewal orders) */
    public $linkedSubscriptionId = null;
    /** @var LicenseData[] optional (EDD) */
    public $licenses = [];
    /** @var ActivityData[] optional (EDD) */
    public $activities = [];

    public static function make(array $data)
    {
        $o = new self();
        foreach ($data as $key => $value) {
            if (property_exists($o, $key)) {
                $o->$key = $value;
            }
        }
        return $o;
    }

    /**
     * The fct_orders row (without uuid, which the writer generates).
     */
    public function toArray()
    {
        return [
            'id'                    => $this->id,
            'status'                => $this->status,
            'parent_id'             => $this->parentId,
            'receipt_number'        => $this->receiptNumber ?: $this->id,
            'invoice_no'            => $this->invoiceNo,
            'fulfillment_type'      => $this->fulfillmentType,
            'type'                  => $this->type,
            'mode'                  => $this->mode,
            'shipping_status'       => $this->shippingStatus,
            'customer_id'           => $this->customerId,
            'payment_method'        => $this->paymentMethod,
            'payment_status'        => $this->paymentStatus,
            'payment_method_title'  => $this->paymentMethodTitle,
            'currency'              => $this->currency,
            'subtotal'              => $this->subtotal,
            'discount_tax'          => $this->discountTax,
            'manual_discount_total' => $this->manualDiscountTotal,
            'coupon_discount_total' => $this->couponDiscountTotal,
            'shipping_tax'          => $this->shippingTax,
            'shipping_total'        => $this->shippingTotal,
            'fee_total'             => $this->feeTotal,
            'tax_total'             => $this->taxTotal,
            'tax_behavior'          => $this->taxBehavior,
            'total_amount'          => $this->totalAmount,
            'total_paid'            => $this->totalPaid,
            'total_refund'          => $this->totalRefund,
            'rate'                  => $this->rate,
            'note'                  => $this->note,
            'ip_address'            => $this->ipAddress,
            'completed_at'          => $this->completedAt,
            'refunded_at'           => $this->refundedAt,
            'config'                => json_encode($this->config),
            'created_at'            => $this->createdAt,
            'updated_at'            => $this->updatedAt,
        ];
    }
}
