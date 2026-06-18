<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * One fct_subscriptions row. `uuid`, `parent_order_id` and `customer_id` are
 * injected by the writer.
 */
class SubscriptionData
{
    public $productId = 0;
    public $itemName = '';
    public $quantity = 1;
    public $variationId = 0;
    public $billingInterval = 'monthly';
    public $signupFee = 0;
    public $initialTaxTotal = 0;
    public $recurringAmount = 0;
    public $recurringTaxTotal = 0;
    public $recurringTotal = 0;
    public $billTimes = 0;
    public $billCount = 0;
    public $expireAt = null;
    public $trialEndsAt = null;
    public $canceledAt = null;
    public $restoredAt = null;
    public $collectionMethod = 'automatic';
    public $nextBillingDate = null;
    public $trialDays = 0;
    public $vendorCustomerId = null;
    public $vendorPlanId = '';
    public $vendorSubscriptionId = '';
    public $status = 'pending';
    public $originalPlan = '';
    public $vendorResponse = '';
    public $currentPaymentMethod = '';
    public $config = [];
    public $createdAt = '';
    public $updatedAt = '';

    public static function make(array $data)
    {
        $s = new self();
        foreach ($data as $key => $value) {
            if (property_exists($s, $key)) {
                $s->$key = $value;
            }
        }
        return $s;
    }

    public function toArray()
    {
        return [
            'product_id'             => $this->productId,
            'item_name'              => $this->itemName,
            'quantity'               => $this->quantity,
            'variation_id'           => $this->variationId,
            'billing_interval'       => $this->billingInterval,
            'signup_fee'             => $this->signupFee,
            'initial_tax_total'      => $this->initialTaxTotal,
            'recurring_amount'       => $this->recurringAmount,
            'recurring_tax_total'    => $this->recurringTaxTotal,
            'recurring_total'        => $this->recurringTotal,
            'bill_times'             => $this->billTimes,
            'bill_count'             => $this->billCount,
            'expire_at'              => $this->expireAt,
            'trial_ends_at'          => $this->trialEndsAt,
            'canceled_at'            => $this->canceledAt,
            'restored_at'            => $this->restoredAt,
            'collection_method'      => $this->collectionMethod,
            'next_billing_date'      => $this->nextBillingDate,
            'trial_days'             => $this->trialDays,
            'vendor_customer_id'     => $this->vendorCustomerId,
            'vendor_plan_id'         => $this->vendorPlanId,
            'vendor_subscription_id' => $this->vendorSubscriptionId,
            'status'                 => $this->status,
            'original_plan'          => $this->originalPlan,
            'vendor_response'        => $this->vendorResponse,
            'current_payment_method' => $this->currentPaymentMethod,
            'config'                 => json_encode($this->config),
            'created_at'             => $this->createdAt,
            'updated_at'             => $this->updatedAt,
        ];
    }
}
