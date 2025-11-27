<?php

namespace FluentCartMigrator\Classes\Edd3;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\Framework\Support\Arr;

class PaymentMigrate
{
    private $payment;
    private $formattedMeta;

    private $isRecoverd = false;

    private $currency = null;

    public $error = null;

    private $eddSubscriptionId = null;

    private $transactionType = 'payment';

    private $paymentMode = 'live';

    private $paymentMethod = '';

    private $renewwingLicense = null;

    private $upgradedFromPayment = null;

    private $dicountCode = '';

    private $orderItems = [];

    private $orderStatus = 'pending';
    private $paymentStatus = 'pending';

    private $mainTransaction = null;

    private $refundTransactions = [];

    private $infoItems = [];

    private $couponCodes = [];

    private $orderTotals = [
        'subtotal'              => 0,
        'discount_tax'          => 0,
        'manual_discount_total' => 0,
        'coupon_discount_total' => 0,
        'shipping_tax'          => 0,
        'shipping_total'        => 0,
        'tax_total'             => 0,
        'total_amount'          => 0,
        'total_paid'            => 0,
        'total_refund'          => 0
    ];

    private $refundData = [];

    private $orderData = [];

    private $transactionData = [];

    private $customer = null;

    private $parentOrderId = null;

    private $subscripionData = [];

    private $billingAddress = [];

    private $licenses = [];

    private $activities = [];

    private $orderType = 'sale';

    private $eddCurrency = '';

    public function __construct($payment, $formattedMeta = [])
    {
        $this->payment = $payment;
        if (!$formattedMeta) {
            $formattedMeta = MigratorHelper::getFormattedOrderMeta($payment->ID);
        }

        $this->formattedMeta = $formattedMeta;
        $this->orderType = $payment->type;
        $this->orderStatus = $this->getOrderStatus($payment->status);
        $this->paymentStatus = MigratorHelper::getPaymentStatus($payment->status);
        $this->eddCurrency = $payment->currency;

        $this->currency = (new StoreSettings)->getCurrency();


        $this->paymentMode = $payment->mode == 'test' ? 'test' : 'live';

        // let's prepare the order data first
        $this->paymentMethod = MigratorHelper::getGatewaySlug($payment->gateway);
        $this->parentOrderId = $this->payment->parent ? (int)$this->payment->parent : null;
    }

    public function setupData()
    {
        $this->mainTransaction = fluentCart('db')->table('edd_order_transactions')
            ->where('object_id', $this->payment->id)
            ->where('object_type', 'order')
            ->first();

        $this->activities = MigratorHelper::getActivities($this->payment);

        $formattedMeta = $this->formattedMeta;
        $this->eddSubscriptionId = Arr::get($formattedMeta, 'subscription_id', '');

        if ($this->payment->status == 'edd_subscription') {
            $this->transactionType = 'renewal';
            if (!$this->eddSubscriptionId) {
                return new \WP_Error('no_sub_id', 'EDD Subscription ID is empty for Payment ID: ' . $this->payment->id, $this->payment);
            }
        } else {
            $subscription = fluentCart('db')->table('edd_subscriptions')->where('parent_payment_id', $this->payment->id)->first();
            if ($subscription) {
                $this->eddSubscriptionId = $subscription->id;
                $this->transactionType = 'subscription';
            }
        }

        $renewingLicenseKey = Arr::get($formattedMeta, '_edd_sl_renewal_key', '');
        if ($renewingLicenseKey) {
            $this->renewwingLicense = fluentCart('db')->table('fct_licenses')
                ->where('license_key', $renewingLicenseKey)
                ->first();
        }

        $upgradedPaymentId = Arr::get($formattedMeta, '_edd_sl_upgraded_payment_id', '');

        if ($upgradedPaymentId) {
            $upgradeFromPayment = fluentCart('db')->table('posts')
                ->where('ID', $upgradedPaymentId)
                ->first();

            if ($upgradeFromPayment) {
                $this->upgradedFromPayment = $upgradeFromPayment;
                if (!$this->renewwingLicense) {
                    $licenseOrderId = $upgradeFromPayment->ID;
                    if ($upgradeFromPayment->post_parent) {
                        $licenseOrderId = $upgradeFromPayment->post_parent;
                    }
                    $this->renewwingLicense = fluentCart('db')->table('fct_licenses')
                        ->where('order_id', $licenseOrderId)
                        ->first();
                }
            }
        }

        $this->customer = $this->getCustomer();
        if (!$this->customer || is_wp_error($this->customer)) {
            return new \WP_Error('invalid_customer', 'Customer could not be resolved.', $this->payment);
        }

        $subscription = $this->setupSubscriptionData();
        if (is_wp_error($subscription)) {
            return $subscription;
        }

        $this->setupLicenses(); // preparing licenses

        $items = $this->prepareOrderItems(); // preparing order items
        if (is_wp_error($items)) {
            return $items;
        }

        $orderData = $this->setupMainOrderData(); // preparing main order data
        if (is_wp_error($orderData)) {
            return $orderData;
        }

        // Prepare Transaction Data
        $this->setupTransactionData();

        return $this;
    }

    public function migrate($checkExist = false, $deleteExisting = false)
    {
        if (!$this->orderData) {
            return new \WP_Error('no_order_data', 'Order data not found.', $this->payment);
        }

        if ($checkExist) {
            // check if exist
            $exist = fluentCart('db')->table('fct_orders')
                ->where('id', $this->payment->id)
                ->first();

            if ($exist) {
                if (!$deleteExisting) {
                    return new \WP_Error('order_exist', 'Order already exists with ID: ' . $this->payment->id, $this->payment);
                }
                // delete existing order
                MigratorHelper::deleteOrderById($exist->id);
            }
        }

        $transactionData = $this->transactionData;

        if ($this->transactionType == 'renewal') {
            if ($this->renewwingLicense && $this->renewwingLicense->subscription_id) {
                $transactionData['subscription_id'] = $this->renewwingLicense->subscription_id;
            } else if ($this->parentOrderId) {
                // find the subscription id from parent order
                $parentSubscription = fluentCart('db')->table('fct_subscriptions')
                    ->where('parent_order_id', $this->parentOrderId)
                    ->first();

                if ($parentSubscription) {
                    $transactionData['subscription_id'] = $parentSubscription->id;
                }
            }
        }

        if ($this->transactionType == 'renewal' && empty($transactionData['subscription_id'])) {
            // we should not proceed if we don't have subscription ID
            $dummySubscription = $this->maybeCreateDummySubscription();

            if (!$dummySubscription) {
                return new \WP_Error('no_subscription_id', 'No subscription ID found for renewal transaction. ' . $this->payment->ID . ' => ' . $this->customer->id, $this->payment);
            }

            $this->addActivityLog('Dummy Subscription Created for renewal payment', 'A dummy subscription was created for the renewal transaction. Dummy Subscription ID: ' . $dummySubscription->id, $dummySubscription->parent_order_id);

            if (defined('WP_CLI')) {
                \WP_CLI::line('Dummy Subscription Created for renewal payment: ' . $this->payment->ID . ' Subscription ID: ' . $dummySubscription->id);
            }

            $transactionData['subscription_id'] = $dummySubscription->id;
        }

        // we should migrate this now. What's the steps?
        // 1. Add order data
        $fctUpgradedFrom = null;
        if ($this->upgradedFromPayment) {
            $fctUpgradedFrom = fluentCart('db')->table('fct_orders')
                ->where('id', $this->upgradedFromPayment->ID)
                ->first();
        }

        $orderData = $this->orderData;
        if ($fctUpgradedFrom) {
            $orderData['config']['upgraded_from'] = $fctUpgradedFrom->id;
        }
        $orderData['config'] = \json_encode($orderData['config']);

        $createdOrderId = fluentCart('db')->table('fct_orders')
            ->insertGetId($orderData);

        if ($fctUpgradedFrom) {
            $existingConfig = json_decode($fctUpgradedFrom->config, true);
            if (!is_array($existingConfig)) {
                $existingConfig = [];
            }


            $existingConfig['upgraded_to'] = $createdOrderId;
            fluentCart('db')->table('fct_orders')
                ->where('id', $fctUpgradedFrom->id)
                ->update(['config' => \json_encode($existingConfig)]);
        }
        // Completed Main Order Data Migration

        // 2. Add Order Items Data
        foreach ($this->orderItems as $orderItem) {
            $orderItem = Arr::only($orderItem, [
                'order_id',
                'post_id',
                'fulfillment_type',
                'payment_type',
                'post_title',
                'title',
                'object_id',
                'quantity',
                'unit_price',
                'cost',
                'subtotal',
                'tax_amount',
                'shipping_charge',
                'discount_total',
                'line_total',
                'refund_total',
                'rate',
                'other_info',
                'line_meta',
                'created_at',
                'updated_at',
            ]);

            $orderItem['order_id'] = $createdOrderId;

            fluentCart('db')->table('fct_order_items')
                ->insert($orderItem);
        }

        $createdSubscriptionId = null;
        $upgradedFromSubscription = null;
        // 3. Add Subscription Data
        if ($this->subscripionData) {
            $subscriptionData = $this->subscripionData;
            if ($fctUpgradedFrom) {
                $upgradedFromSubscription = fluentCart('db')->table('fct_subscriptions')
                    ->where('id', $fctUpgradedFrom->id)
                    ->first();
                if ($upgradedFromSubscription) {
                    $subscriptionData['config']['upgraded_from_sub_id'] = $upgradedFromSubscription->id;
                }
            }
            $subscriptionData['config'] = \json_encode($subscriptionData['config']);
            $createdSubscriptionId = fluentCart('db')->table('fct_subscriptions')
                ->insertGetId($subscriptionData);
            if ($upgradedFromSubscription) {
                $config = \json_decode($upgradedFromSubscription->config, true);
                if (!is_array($config)) {
                    $config = [];
                }
                $config['upgraded_to_sub_id'] = $createdSubscriptionId;
                fluentCart('db')->table('fct_subscriptions')
                    ->where('id', $upgradedFromSubscription->id)
                    ->update(['config' => json_encode($config)]);
            }
        }

        // 4. Add Transaction Data
        if ($createdSubscriptionId) {
            $transactionData['subscription_id'] = $createdSubscriptionId;
        }
        $transactionData['order_id'] = $createdOrderId;
        $createdTransactionId = fluentCart('db')->table('fct_order_transactions')
            ->insertGetId($transactionData);

        // 5. Let's handle refund Data
        $createdRefundId = null;
        if ($this->refundTransactions) {

            foreach ($this->refundTransactions as $refundData) {
                $refundData['order_id'] = $createdOrderId;
                if ($createdSubscriptionId) {
                    $refundData['subscription_id'] = $createdSubscriptionId;
                }

                $createdRefundId = fluentCart('db')->table('fct_order_transactions')
                    ->insertGetId($refundData);
            }
        }

        // 6. Add Licenses Data
        $licenses = $this->licenses;
        foreach ($licenses as $license) {
            $licenseData = Arr::only($license, [
                'status', 'limit', 'activation_count', 'license_key', 'product_id', 'variation_id', 'order_id', 'customer_id',
                'expiration_date', 'created_at', 'updated_at',
            ]);
            $licenseData['customer_id'] = $this->customer->id;

            if (!empty($transactionData['subscription_id'])) {
                $licenseData['subscription_id'] = $transactionData['subscription_id'];
            }

            $licenseData['config'] = \json_encode([]);

            $licenseId = fluentCart('db')->table('fct_licenses')
                ->insertGetId($licenseData);

            if ($checkExist) {
                \WP_CLI::line('License ID: ' . $licenseId . ' created for Order ID: ' . $createdOrderId . ' => ' . $licenseData['license_key']);
            }

            if (!empty($license['activations'])) {
                $activations = $license['activations'];
                foreach ($activations as $index => $activation) {
                    $activations[$index]['license_id'] = $licenseId;
                }

                MigratorHelper::doBulkInsert('fct_license_activations', $activations);
            }
        }

        // 7. house keeping
        if ($this->renewwingLicense && $this->renewwingLicense->customer_id != $this->customer->id) {
            MigratorHelper::moveOrderToNewCustomer($this->renewwingLicense->order_id, $this->customer->id, $this->getPaymentDate('post_date'));
            $this->addActivityLog('Migrated Previous License Order', 'The previous license order was migrated to the new customer. Previous Customer ID: ' . $this->renewwingLicense->customer_id . '. New Customer ID: ' . $this->customer->id);
            $this->renewwingLicense = fluentCart('db')->table('fct_licenses')
                ->where('id', $this->renewwingLicense->id)
                ->first();
        }

        if ($this->renewwingLicense && $this->transactionType == 'payment' && $this->renewwingLicense->subscription_id) {
            $oldConfig = \json_decode($this->renewwingLicense->config, true);
            if (!$oldConfig) {
                $oldConfig = [];
            }
            if (empty($oldConfig['prev_order_ids'])) {
                $oldConfig['prev_order_ids'] = [];
            }
            $oldConfig['prev_order_ids'][] = $this->renewwingLicense->order_id;
            $oldConfig['prev_order_ids'] = array_values(array_unique($oldConfig['prev_order_ids']));

            // we should detach the subscription ID
            fluentCart('db')->table('fct_licenses')
                ->where('id', $this->renewwingLicense->id)
                ->update([
                    'subscription_id' => null,
                    'order_id'        => $createdOrderId,
                    'config'          => \json_encode($oldConfig)
                ]);

            $this->addActivityLog('License moved from existing order', 'The license was moved from the existing order to the new order. License ID: ' . $this->renewwingLicense->id . '. Old Order ID: ' . $this->renewwingLicense->order_id);
            $this->addActivityLog('License moved to the new order', 'The license has been moved to the new order to the new order. License ID: ' . $this->renewwingLicense->id . '. New Order ID: ' . $createdOrderId, $this->renewwingLicense->order_id);
        }

        if ($this->renewwingLicense && $createdSubscriptionId) {
            $createdSubscription = fluentCart('db')->table('fct_subscriptions')
                ->where('id', $createdSubscriptionId)
                ->first();

            $oldConfig = \json_decode($this->renewwingLicense->config, true);
            if (!$oldConfig) {
                $oldConfig = [];
            }
            if (empty($oldConfig['prev_order_ids'])) {
                $oldConfig['prev_order_ids'] = [];
            }
            $oldConfig['prev_order_ids'][] = $this->renewwingLicense->order_id;
            $oldConfig['prev_order_ids'] = array_values(array_unique($oldConfig['prev_order_ids']));

            // update the license with the new subscription ID
            fluentCart('db')->table('fct_licenses')
                ->where('id', $this->renewwingLicense->id)
                ->update([
                    'subscription_id' => $createdSubscription->id,
                    'order_id'        => $createdOrderId,
                    'product_id'      => $createdSubscription->product_id,
                    'variation_id'    => $createdSubscription->variation_id,
                    'config'          => \json_encode($oldConfig)
                ]);

            $this->addActivityLog('License moved from existing Order', 'The license was moved from the existing order to the new order. License ID: ' . $this->renewwingLicense->id . '. Old Order ID: ' . $this->renewwingLicense->order_id);
        }

        // 8. Migrate Activities
        MigratorHelper::doBulkInsert('fct_activity', $this->activities);

        // 9. Migrate the used coupon code
        if ($this->couponCodes) {
            foreach ($this->couponCodes as $codeData) {
                $coupon = MigratorHelper::getCouponByCode($codeData['code']);

                $discountData = [
                    'order_id'    => $this->payment->id,
                    'customer_id' => $orderData['customer_id'],
                    'code'        => $codeData['code'],
                    'amount'      => $codeData['amount'],
                    'created_at'  => $orderData['created_at'],
                    'updated_at'  => $orderData['updated_at'],
                    'coupon_id'   => $coupon ? $coupon->id : 0
                ];

                fluentCart('db')->table('fct_applied_coupons')->insert($discountData);
            }
        }

        // 10. Add Order Billing address
        if ($this->billingAddress) {
            $address = $this->billingAddress;
            $address['order_id'] = $createdOrderId;
            $address['type'] = 'billing';
            $address['created_at'] = $this->getPaymentDate('post_date');
            $address['updated_at'] = $this->getPaymentDate('post_date');
            fluentCart('db')->table('fct_order_addresses')
                ->insert($address);
        }

        // 11. Add Order Optional Info
        $invalidStatuses = ['pending', 'failed'];
        if (!in_array($this->orderStatus, $invalidStatuses)) {
            $utmDetails = Arr::get($this->formattedMeta, '_f_utm_details', []);
            $orderOperation = array_filter([
                'order_id'       => $createdOrderId,
                'created_via'    => 'migration',
                'emails_sent'    => 1,
                'sales_recorded' => 1,
                'utm_campaign'   => Arr::get($utmDetails, 'utm_c', ''),
                'utm_medium'     => Arr::get($utmDetails, 'utm_m', ''),
                'utm_source'     => Arr::get($utmDetails, 'utm_s', ''),
                'utm_content'    => Arr::get($utmDetails, 'utm_con', ''),
                'utm_term'       => Arr::get($utmDetails, 'utm_t', ''),
                'utm_id'         => '',
                'cart_hash'      => $orderData['uuid'],
                'refer_url'      => Arr::get($utmDetails, 'refer', ''),
                'meta'           => \json_encode([]),
                'created_at'     => $this->payment->date_created, //$payment->post_date_gmt,
                'updated_at'     => $this->payment->date_modified, //$payment->post_date_gmt,
            ]);
            fluentCart('db')->table('fct_order_operations')
                ->insert($orderOperation);
        }

        return [
            'order_id'         => $createdOrderId,
            'transaction_id'   => $createdTransactionId,
            'subscription_id'  => $createdSubscriptionId,
            'refund_id'        => $createdRefundId,
            'transaction_type' => $this->transactionType,
            'is_upgraded'      => $fctUpgradedFrom ? true : false,
        ];
    }

    public function validate()
    {
        if (!$this->orderData) {
            return new \WP_Error('no_order_data', 'Order data not found.', $this->payment);
        }

        // validation rules
        // order.subtotal = SUM(orderItems.subtotal)
        if ($this->orderData['subtotal'] !== array_sum(array_column($this->orderItems, 'subtotal'))) {
            return new \WP_Error('validation_error', 'Order subtotal does not match order items subtotal.', [
                'orderData'  => $this->orderData,
                'orderItems' => $this->orderItems,
            ]);
        }
        // order.coupon_discount_total = SUM(orderItems.discount_total)

        if ($this->orderData['coupon_discount_total'] !== array_sum(array_column($this->orderItems, 'discount_total'))) {
            return new \WP_Error('validation_error', 'Order coupon discount total does not match order items discount total.', [
                'orderData'  => $this->orderData,
                'orderItems' => $this->orderItems,
            ]);
        }

        // order.total_amount =  SUM(orderItems.subtotal) - (discount_total + coupon_discount_total)

        if ($this->orderData['total_amount'] !== ($this->orderData['subtotal'] - $this->orderData['coupon_discount_total'] - $this->orderData['manual_discount_total'] + $this->orderData['tax_total'])) {
            return new \WP_Error('validation_error', 'Order total amount does not match order items total.', [
                'orderData'  => $this->orderData,
                'orderItems' => $this->orderItems,
            ]);
        }

        // order.total_refund = refundTransaction.total
        if ($this->orderData['total_refund'] !== Arr::get($this->refundData, 'total', 0)) {
            return new \WP_Error('validation_error', 'Order total refund does not match refund transaction total. TotalRedund: ' . $this->orderData['total_refund'] . '. Given: ' . Arr::get($this->refundData, 'total', 0), [
                'orderData'  => $this->orderData,
                'refundData' => $this->refundData,
            ]);
        }

        // order.total_paid = transaction.total

        if ($this->orderData['total_paid'] !== $this->transactionData['total']) {
            return new \WP_Error('validation_error', 'Order total paid does not match transaction total.', [
                'orderData'       => $this->orderData,
                'transactionData' => $this->transactionData,
            ]);
        }

        if ($this->paymentStatus != 'pending') {
            // order.paid_total = order.subtotal - order.coupon_discount_total - order.manual_discount_total
            if ($this->orderData['total_paid'] !== ($this->orderData['subtotal'] - $this->orderData['coupon_discount_total'] - $this->orderData['manual_discount_total'] + $this->orderData['tax_total'])) {
                return new \WP_Error('validation_error', 'Order total paid does not match order totals.', [
                    'orderData' => $this->orderData,
                ]);
            }
        }

        // refund total can't be greater than order.total_amount and order.total_paid
        if ($this->orderData['total_refund'] > $this->orderData['total_paid']) {
            return new \WP_Error('validation_error', 'Order refund amount cannot be greater than order total_paid.', [
                'orderData' => $this->orderData,
            ]);
        }

        if ($this->orderData['total_paid'] != $this->transactionData['total']) {
            return new \WP_Error('validation_error', 'Order total paid does not match transaction total.', [
                'orderData'       => $this->orderData,
                'transactionData' => $this->transactionData,
            ]);
        }

        return true;
    }

    private function setupSubscriptionData()
    {
        if ($this->transactionType != 'subscription') {
            return null;
        }

        $eddSubscription = fluentCart('db')->table('edd_subscriptions')
            ->where('id', $this->eddSubscriptionId)
            ->first();


        if (!$eddSubscription) {
            return new \WP_Error('no_subscription', 'No subscriptions found for Payment ID: ' . $this->payment->ID, $this->payment);
        }

        if (!$eddSubscription) {
            return new \WP_Error('no_subscription', 'EDD Subscription not found for Payment ID: ' . $this->payment->ID, $this->payment);
        }

        $fctProductDetails = MigratorHelper::getTransformedProductDetails($eddSubscription->product_id, $eddSubscription->price_id);

        if (is_wp_error($fctProductDetails)) {
            return $fctProductDetails;
        }

        $vendorCustomerId = null;
        if ($this->paymentMethod == 'stripe') {
            $vendorCustomerId = Arr::get($this->formattedMeta, '_edds_stripe_customer_id');
        }

        $this->subscripionData = [
            'uuid'                   => md5('subscription_' . $this->payment->id . '_' . wp_generate_uuid4() . '_' . microtime(true)),
            'customer_id'            => $this->customer->id,
            'parent_order_id'        => $this->payment->id,
            'product_id'             => $fctProductDetails['id'],
            'item_name'              => Arr::get($fctProductDetails, 'full_title'),
            'quantity'               => 1,
            'variation_id'           => Arr::get($fctProductDetails, 'variation_id', NULL),
            'billing_interval'       => $this->getPeriodSlug($eddSubscription->period),
            'signup_fee'             => MigratorHelper::toCents($eddSubscription->initial_amount, $this->eddCurrency),
            'initial_tax_total'      => 0,
            'recurring_amount'       => MigratorHelper::toCents($eddSubscription->recurring_amount, $this->eddCurrency),
            'recurring_tax_total'    => 0,
            'recurring_total'        => MigratorHelper::toCents($eddSubscription->recurring_amount, $this->eddCurrency),
            'bill_times'             => $eddSubscription->bill_times,
            'bill_count'             => 0,
            'expire_at'              => $eddSubscription->expiration,
            'trial_ends_at'          => NULL,
            'canceled_at'            => NULL,
            'restored_at'            => NULL,
            'collection_method'      => 'automatic',
            'next_billing_date'      => $eddSubscription->expiration,
            'trial_days'             => 0,
            'vendor_customer_id'     => $vendorCustomerId,
            'vendor_plan_id'         => '',
            'vendor_subscription_id' => $eddSubscription->profile_id,
            'status'                 => $this->getSubscriptionStatus($eddSubscription->status),
            'original_plan'          => '',
            'vendor_response'        => '',
            'current_payment_method' => $this->paymentMethod,
            'created_at'             => $eddSubscription->created,
            'updated_at'             => current_time('mysql'),
            'config'                 => [
                'edd_id' => $eddSubscription->id
            ],
        ];

        return $this->subscripionData;
    }

    private function prepareOrderItems()
    {
        $eddItems = fluentCart('db')->table('edd_order_items')
            ->where('order_id', $this->payment->id)
            ->get();

        $fallbackDiscountTotal = 0;
        $orderItems = [];
        foreach ($eddItems as $index => $eddCartItem) {
            $parsedItems = MigratorHelper::formatOrderItem($this->payment, $eddCartItem, $this->transactionType);

            if (is_wp_error($parsedItems)) {
                return $parsedItems;
            }

            if (!$parsedItems) {
                return new \WP_Error('empty_cart_item', 'Parsed items are empty. Payment ID: ' . $this->payment->ID, $this->payment);
            }

            foreach ($parsedItems as $newIndex => $parsedItem) {
                $fallbackDiscountTotal += (int)Arr::get($parsedItem, '_fallback_discount', 0);
                $parsedItem['cart_index'] = $index + $newIndex + 1;
                $orderItems[] = $parsedItem;
            }
        }

        if (!$orderItems) {
            return new \WP_Error('empty_cart_item', 'No order items found. Payment ID: ' . $this->payment->ID, $this->payment);
        }

        $this->orderItems = $orderItems;

        $totalRefundAmount = 0;
        // Check if we have refunds
        if ($this->paymentStatus === 'refunded' || $this->paymentStatus === 'partially_refunded') {
            // let's find out the refund orders
            $refunds = fluentCart('db')->table('edd_orders')
                ->where('type', 'refund')
                ->where('parent', $this->payment->id)
                ->where('status', 'complete')
                ->get();

            $refundTransactions = [];
            foreach ($refunds as $refund) {
                $totalRefundAmount += absint(MigratorHelper::toCents($refund->total, $this->eddCurrency));
                $refundTransactions[] = [
                    'order_id'            => $this->payment->id,
                    'status'              => Status::TRANSACTION_REFUNDED,
                    'total'               => absint(MigratorHelper::toCents($refund->total, $this->eddCurrency)),
                    'created_at'          => $refund->date_created,
                    'updated_at'          => $refund->date_modified,
                    'order_type'          => $this->transactionType,
                    'transaction_type'    => 'refund',
                    'subscription_id'     => !empty($transactionData['subscription_id']) ? $transactionData['subscription_id'] : null,
                    'card_last_4'         => '',
                    'card_brand'          => '',
                    'vendor_charge_id'    => '',
                    'payment_method'      => $this->paymentMethod,
                    'payment_mode'        => $this->paymentMode,
                    'payment_method_type' => $this->paymentMethod === 'stripe' ? 'card' : '',
                    'currency'            => $this->currency,
                    'rate'                => 1,
                    'uuid'                => md5('refund_' . $this->payment->id . '_' . $refund->uuid . '_' . microtime(true)),
                    'meta'                => \json_encode([]),
                ];

                $this->refundData['created_at'] = $refund->date_created;

            }
            $this->orderTotals['total_refund'] = $totalRefundAmount;
            $this->refundTransactions = $refundTransactions;

            $this->refundData['total'] = $totalRefundAmount;

            if ($totalRefundAmount) {

                $totalAmount = array_sum(array_column($this->orderItems, 'line_total'));

                // we have to adjust the order items refund total
                foreach ($this->orderItems as $itemIndex => $orderItem) {
                    $itemLineTotal = $orderItem['line_total'];
                    if ($itemLineTotal <= 0 && $totalAmount <= 0) {
                        continue;
                    }

                    $proportionateRefund = ($itemLineTotal / $totalAmount) * $totalRefundAmount;
                    $proportionateRefund = min($proportionateRefund, $itemLineTotal);

                    $this->orderItems[$itemIndex]['refund_total'] = (int)round($proportionateRefund);
                }
            }
        }


        $this->calculateTotals();


        if ($this->orderTotals['coupon_discount_total']) {
            // let's find the coupon codes
            $usedCoupons = fluentCart('db')->table('edd_order_adjustments')
                ->where('object_id', $this->payment->id)
                ->where('object_type', 'order')
                ->where('type', 'discount')
                ->get();

            $formattedCoupons = [];
            if ($usedCoupons) {
                foreach ($usedCoupons as $usedCoupon) {
                    $formattedCoupons[] = [
                        'order_id' => $this->payment->id,
                        'code'     => $usedCoupon->description,
                        'amount'   => MigratorHelper::toCents($usedCoupon->total, $this->eddCurrency)
                    ];
                }
            }

            $this->couponCodes = $formattedCoupons;
        }

        // $this->maybeAdjustOrderItems();
        // $this->adjustFallbackDiscounts();

        return $this->orderItems;
    }

    private function setupMainOrderData()
    {
        $completedDate = $this->payment->date_completed;
        if (!$completedDate) {
            $completedDate = null;
        }

        $refundedAt = null;
        if ($this->refundData && $this->refundData['created_at']) {
            $refundedAt = $this->refundData['created_at'];
        }

        $this->orderData = [
            'id'                    => $this->payment->id,
            'status'                => $this->orderStatus,
            'parent_id'             => (int)$this->parentOrderId,
            'receipt_number'        => (int)$this->payment->id,
            'invoice_no'            => $this->payment->order_number,
            'fulfillment_type'      => 'digital',
            'type'                  => $this->transactionType,
            'mode'                  => $this->paymentMode,
            'shipping_status'       => '',
            'customer_id'           => $this->customer->id,
            'payment_method'        => $this->paymentMethod,
            'payment_status'        => MigratorHelper::getPaymentStatus($this->payment->status),
            'payment_method_title'  => ucfirst($this->paymentMethod),
            'currency'              => $this->currency,
            'subtotal'              => $this->orderTotals['subtotal'],
            'discount_tax'          => 0,
            'manual_discount_total' => $this->orderTotals['manual_discount_total'],
            'coupon_discount_total' => $this->orderTotals['coupon_discount_total'],
            'shipping_tax'          => 0,
            'shipping_total'        => 0,
            'tax_total'             => MigratorHelper::toCents($this->payment->tax, $this->eddCurrency),
            'total_amount'          => $this->orderTotals['total_amount'],
            'total_paid'            => $this->orderTotals['total_paid'],
            'total_refund'          => $this->orderTotals['total_refund'],
            'rate'                  => 1,
            'note'                  => '',
            'ip_address'            => $this->payment->ip,
            'completed_at'          => $completedDate,
            'refunded_at'           => $refundedAt,
            'uuid'                  => md5($this->payment->ID . '_' . $this->payment->uuid . '_' . microtime(true)),
            'config'                => [],
            'created_at'            => $this->payment->date_created, //$payment->post_date_gmt,
            'updated_at'            => $this->payment->date_modified, //$payment->post_modified_gmt,
        ];

        return $this->orderData;
    }

    private function setupTransactionData()
    {
        if ($this->mainTransaction) {

            $transaction = $this->mainTransaction;

            if ($this->paymentMethod === 'stripe') {
                $vendorIntentId = Arr::get($this->formattedMeta, '_edds_stripe_payment_intent_id') ?? '';
            } else {
                $vendorIntentId = $transaction->transaction_id;
            }

            $this->transactionData = [
                'order_id'            => $this->payment->id,
                'order_type'          => $this->transactionType,
                'transaction_type'    => 'charge',
                'subscription_id'     => NULL, // we will set it when we will insert.
                'card_last_4'         => '',
                'card_brand'          => '',
                'vendor_charge_id'    => $vendorIntentId,
                'payment_method'      => MigratorHelper::getGatewaySlug($transaction->gateway),
                'payment_mode'        => $this->paymentMode,
                'payment_method_type' => $this->paymentMode === 'stripe' ? 'card' : '',
                'status'              => ($transaction->status === 'complete') ? 'succeeded' : Status::PAYMENT_PENDING,
                'currency'            => $this->currency,
                'total'               => MigratorHelper::toCents($transaction->total, $this->eddCurrency),
                'rate'                => 1,
                'uuid'                => md5('payment_' . $transaction->uuid . '_' . microtime(true)),
                'meta'                => \json_encode([]),
                'created_at'          => $transaction->date_created,
                'updated_at'          => current_time('mysql'),
            ];
            return;
        }

        $transactionStatus = '';
        $validSatuses = Status::getOrderPaymentSuccessStatuses();

        $validSatuses[] = 'refunded';
        $validSatuses[] = 'partially_refunded';

        if (in_array($this->paymentStatus, $validSatuses) || !$this->orderTotals['total_paid']) {
            $transactionStatus = Status::TRANSACTION_SUCCEEDED;
        } else {
            $transactionStatus = 'pending';
        }

        // we are creating a dummy transaction here
        $this->transactionData = [
            'order_id'            => $this->payment->id,
            'order_type'          => $this->transactionType,
            'transaction_type'    => 'charge',
            'subscription_id'     => NULL, // we will set it when we will insert.
            'card_last_4'         => '',
            'card_brand'          => '',
            'vendor_charge_id'    => Arr::get($this->formattedMeta, '_edds_stripe_payment_intent_id') ?? '',
            'payment_method'      => $this->paymentMethod,
            'payment_mode'        => $this->paymentMode,
            'payment_method_type' => $this->paymentMode === 'stripe' ? 'card' : '',
            'status'              => $transactionStatus,
            'currency'            => $this->currency,
            'total'               => $this->orderTotals['total_paid'],
            'rate'                => 1,
            'uuid'                => md5('payment_' . $this->payment->ID . '_' . Arr::get($this->formattedMeta, '_edd_payment_purchase_key', '') . '_' . microtime(true)),
            'meta'                => \json_encode([
                'fallback' => 1
            ]),
            'created_at'          => $this->getPaymentDate('post_date'), //$payment->post_date_gmt,
            'updated_at'          => current_time('mysql'),
        ];
    }

    private function setupLicenses()
    {
        $licenses = MigratorHelper::getLicenses($this->payment->id);
        foreach ($licenses as $index => $license) {
            $licenses[$index]['customer_id'] = (int)$this->customer->id;
        }

        $this->licenses = $licenses;

        if ($this->licenses && $this->renewwingLicense) {
            \WP_CLI::line('Got new licenses for renewal license: ' . $this->payment->ID);
        }
    }

    private function calculateTotals()
    {
        $this->orderTotals['subtotal'] = array_sum(array_column($this->orderItems, 'subtotal'));
        $this->orderTotals['coupon_discount_total'] = array_sum(array_column($this->orderItems, 'discount_total'));
        $taxTotal = array_sum(array_column($this->orderItems, 'tax_amount'));
        $this->orderTotals['total_amount'] = $this->orderTotals['subtotal'] - $this->orderTotals['coupon_discount_total'] - $this->orderTotals['manual_discount_total'] + $taxTotal;

        if ($this->mainTransaction) {
            $this->orderTotals['total_paid'] = MigratorHelper::toCents($this->mainTransaction->total, $this->eddCurrency);
        } else {
            $this->orderTotals['total_paid'] = 0;
        }

        $diff = $this->orderTotals['total_amount'] - $this->orderTotals['total_paid'];

        if ($diff > 0) {
            $this->orderTotals['manual_discount_total'] += $diff;
            $this->orderTotals['total_amount'] -= $diff;
        }
    }

    private function maybeAdjustOrderItems()
    {
        if ($this->orderTotals['total_paid'] > $this->orderTotals['total_amount']) {
            // something is wrong here!
            // we have net paid amount greater than total order amount
            // So EDD might have some issues with this payment
            // Let's adjust that with total order amount
            // we can distribute the surplus amount to order items

            $requiredSurplus = $this->orderTotals['total_paid'] - $this->orderTotals['total_amount'];

            $itemsCount = count($this->orderItems);
            $perItemSurplus = (int)($requiredSurplus / $itemsCount);
            $recordedSurplus = 0;

            foreach ($this->orderItems as $index => $orderItem) {
                $surplusPerQuantity = (int)($perItemSurplus / $orderItem['quantity']);
                $this->orderItems[$index]['unit_price'] += $surplusPerQuantity;
                $this->orderItems[$index]['line_total'] += $perItemSurplus;
                $this->orderItems[$index]['subtotal'] += $perItemSurplus;
                $recordedSurplus += $perItemSurplus;
            }

            if ($recordedSurplus < $requiredSurplus) {
                // we still have some surplus left, let's adjust that with the first item
                $this->orderItems[0]['unit_price'] += ($requiredSurplus - $recordedSurplus);
                $this->orderItems[0]['line_total'] += ($requiredSurplus - $recordedSurplus);
                $this->orderItems[0]['subtotal'] += ($requiredSurplus - $recordedSurplus);
            }

            $this->calculateTotals();

            $this->infoItems[] = [
                'key'   => 'surplus_adjustment',
                'value' => 'Adjusted surplus amount of ' . $requiredSurplus . ' to order items.',
            ];
        }


        if ($this->orderTotals['total_amount'] == 0) {
            $this->orderTotals['total_paid'] = 0;
        } else if ($this->orderTotals['total_amount'] < $this->orderTotals['total_paid']) {
            $this->orderTotals['total_paid'] = $this->orderTotals['total_amount'];
        }
    }

    private function adjustFallbackDiscounts()
    {
        $diffAmount = $this->orderTotals['total_amount'] - $this->orderTotals['total_paid'];

        if ($diffAmount) {
            // maybe it's a fallback discount amount
            $fallBackDiscountTotal = (int)array_sum(array_column($this->orderItems, '_fallback_discount'));
            if ($fallBackDiscountTotal && $fallBackDiscountTotal <= $diffAmount) {
                if ($this->dicountCode) {
                    // we had discount code and also fallback discount. This is shit!
                    // WHY EDD! WHY!!! WHAT THE F**K REALLY!
                    // let's adjust the order items and give the adjusted discounts
                    foreach ($this->orderItems as $index => $orderItem) {
                        if (empty($orderItem['_fallback_discount'])) {
                            continue;
                        }
                        $amount = $orderItem['_fallback_discount'];
                        $orderItem['discount_total'] += $amount;
                        $orderItem['line_total'] = $orderItem['subtotal'] - $orderItem['discount_total'];
                        $this->orderItems[$index] = $orderItem;
                        $diffAmount = $diffAmount - $amount;
                    }
                } else {
                    $this->orderTotals['manual_discount_total'] = $fallBackDiscountTotal;
                    $diffAmount -= $fallBackDiscountTotal;
                }

                $this->calculateTotals();
            } else if ($this->dicountCode) {
                $this->orderTotals['manual_discount_total'] = $diffAmount;
                $diffAmount = 0;
            } else if ($this->paymentMethod == 'manual_purchases') {
                $this->orderTotals['manual_discount_total'] = $diffAmount;
                $diffAmount = 0;
            }
        }

        if ($this->paymentStatus == Status::PAYMENT_REFUNDED) {
            $diffAmount = $this->orderTotals['total_paid'];
        } else if ($this->paymentStatus == Status::PAYMENT_PARTIALLY_REFUNDED) {

        }

        if ($diffAmount > 0) {
            // we have some refund amount to process
            // @todo: find the refund date from activities
            $this->refundData = [
                'order_id'   => $this->payment->ID,
                'status'     => Status::TRANSACTION_REFUNDED,
                'total'      => $diffAmount,
                'created_at' => $this->guessRefundDate(),
                'updated_at' => $this->guessRefundDate(),
            ];

            if ($this->paymentStatus !== Status::PAYMENT_REFUNDED && $this->paymentStatus !== Status::PAYMENT_PENDING) {
                $this->paymentStatus = Status::PAYMENT_PARTIALLY_REFUNDED;
            }

            $itemsLineTotal = array_sum(array_column($this->orderItems, 'line_total'));
            // now let's add inline refunds to payment items
            foreach ($this->orderItems as $index => $orderItem) {
                $this->orderItems[$index]['refund_total'] = (int)round(($orderItem['line_total'] / $itemsLineTotal) * $diffAmount, 2);
                if ($this->orderItems[$index]['refund_total'] < 0) {
                    $this->orderItems[$index]['refund_total'] = 0;
                }
            }

            $linesRefundTotal = array_sum(array_column($this->orderItems, 'refund_total'));
            if ($linesRefundTotal < $diffAmount) {
                // we have some rounding issues, let's adjust the last item
                $lastIndex = count($this->orderItems) - 1;
                $this->orderItems[$lastIndex]['refund_total'] += ($diffAmount - $linesRefundTotal);
            }
            $this->orderTotals['total_refund'] = $diffAmount;
        }

        if ($this->paymentStatus == Status::PAYMENT_PENDING) {
            $this->orderTotals['total_paid'] = 0;
            $this->refundData = [];
            $this->orderTotals['total_refund'] = 0;
        }

        if ($this->paymentStatus == Status::PAYMENT_REFUNDED) {
            $this->orderStatus = Status::ORDER_CANCELED;
            $this->orderTotals['total_refund'] = $this->orderTotals['total_paid'];
        }

        if ($this->orderTotals['total_refund']) {
            if ($this->paymentStatus == 'partially_refunded' || $this->paymentStatus == 'refunded') {
                if ($this->orderTotals['total_paid'] < $this->orderTotals['total_amount']) {
                    $this->orderTotals['total_paid'] = $this->orderTotals['total_amount'];
                }
            }
        }

        $this->calculateTotals();
    }

    private function getPaymentStatus($eddStatus)
    {
        $maps = [
            'edd_subscription' => Status::PAYMENT_PAID,
            'publish'          => Status::PAYMENT_PAID,
            'processing'       => Status::PAYMENT_PAID,
            'revoked'          => Status::PAYMENT_PENDING,
            'refunded'         => Status::PAYMENT_REFUNDED,
            'pending'          => Status::PAYMENT_PENDING,
            'failed'           => Status::PAYMENT_PENDING,
            'cancelled'        => Status::ORDER_CANCELED,
            'abandoned'        => Status::PAYMENT_PENDING,
        ];

        if (isset($maps[$eddStatus])) {
            return $maps[$eddStatus];
        }

        return '';
    }

    private function getSubscriptionStatus($eddStatus)
    {
        $maps = [
            // for subscriptions
            'active'    => Status::SUBSCRIPTION_ACTIVE,
            'failing'   => Status::SUBSCRIPTION_FAILING,
            'expired'   => Status::SUBSCRIPTION_EXPIRED,
            'completed' => Status::SUBSCRIPTION_COMPLETED,
            'pending'   => Status::SUBSCRIPTION_PENDING,
            'cancelled' => Status::SUBSCRIPTION_CANCELED,
            'trialling' => Status::SUBSCRIPTION_TRIALING,
        ];

        if (isset($maps[$eddStatus])) {
            return $maps[$eddStatus];
        }

        return '';
    }

    private function getOrderStatus($eddStatus)
    {
        $maps = [
            'complete'           => Status::ORDER_COMPLETED,
            'refunded'           => Status::ORDER_CANCELED,
            'partially_refunded' => Status::ORDER_COMPLETED,
            'revoked'            => Status::ORDER_CANCELED,
            'on_hold'            => Status::ORDER_ON_HOLD,
            'edd_subscription'   => Status::ORDER_COMPLETED,
            'publish'            => Status::ORDER_COMPLETED,
            'processing'         => Status::ORDER_PROCESSING,
            'pending'            => Status::ORDER_ON_HOLD,
            'failed'             => Status::ORDER_FAILED,
            'cancelled'          => Status::ORDER_CANCELED,
            'abandoned'          => Status::ORDER_FAILED
        ];

        if (isset($maps[$eddStatus])) {
            return $maps[$eddStatus];
        }

        return '';
    }

    private function getCustomer()
    {
        // let's create a customer here
        $orderAddress = fluentCart('db')
            ->table('edd_order_addresses')
            ->where('type', 'billing')
            ->where('order_id', $this->payment->id)
            ->first();

        if ($orderAddress) {
            $this->billingAddress = [
                'type'      => 'billing',
                'name'      => $orderAddress->name,
                'address_1' => $orderAddress->address,
                'address_2' => $orderAddress->address2,
                'city'      => $orderAddress->city,
                'state'     => $orderAddress->region,
                'postcode'  => $orderAddress->postal_code,
                'country'   => $orderAddress->country,
            ];
        }

        $userId = $this->payment->user_id;
        $customerData = [];

        if ($userId) {
            $user = get_user_by('ID', $userId);
            if ($user) {
                $customerData = [
                    'user_id'    => $userId,
                    'first_name' => $user->first_name,
                    'last_name'  => $user->last_name,
                    'email'      => $user->user_email,
                    'full_name'  => $user->display_name
                ];
            }
        } else {
            $user = get_user_by('email', $this->payment->email);
            if ($user) {
                $customerData = [
                    'user_id'    => $user->ID,
                    'first_name' => $user->first_name,
                    'last_name'  => $user->last_name,
                    'email'      => $user->user_email,
                    'full_name'  => $user->display_name
                ];
            }
        }

        if (!$customerData) {
            $eddCustomer = fluentCart('db')
                ->table('edd_customers')
                ->where('id', $this->payment->customer_id)
                ->first();

            if ($eddCustomer) {
                $customerData = [
                    'user_id'   => null,
                    'full_name' => $eddCustomer->name,
                    'email'     => $eddCustomer->email
                ];
            }
        }

        if (!$customerData) {
            $customerData = [
                'user_id' => null,
                'email'   => $this->payment->email
            ];
        }

        if (!$customerData) {
            return new \WP_Error('no_customer_data', 'No customer data found for Payment ID: ' . $this->payment->id, $this->payment);
        }

        // Check if we have customer
        $customer = Customer::query()->where('email', $customerData['email'])->first();
        if ($customer) {
            $this->customer = $customer;
            return $customer;
        }

        if ($this->billingAddress) {
            $customerData['country'] = $this->billingAddress['country'] ?? '';
            $customerData['city'] = $this->billingAddress['city'] ?? '';
            $customerData['state'] = $this->billingAddress['state'] ?? '';
            $customerData['postcode'] = $this->billingAddress['postcode'] ?? '';

            if (empty($customerData['full_name'])) {
                $customerData['full_name'] = trim(($this->billingAddress['name'] ?? ''));
            }
        }

        $customerData = MigratorHelper::maybeExplodeFullName($customerData);

        $this->customer = Customer::create($customerData);

        return $this->customer;
    }

    private function getPaymentDate($key = 'post_date')
    {
        $date = MigratorHelper::getPostDate($this->payment, $key);
        $year = (int)substr($date, 0, 4);
        if ($year < 2010) {
            // let's find the first date from meta
            $completedDate = Arr::get($this->formattedMeta, '_edd_completed_date');
            if ($completedDate) {
                return $completedDate;
            }
        }

        return $date;
    }

    private function getPeriodSlug($slug)
    {
        $maps = [
            'day'       => 'daily',
            'week'      => 'weekly',
            'month'     => 'monthly',
            'quarter'   => 'quarterly',
            'semi-year' => 'semi-yearly',
            'year'      => 'yearly'
        ];

        if (isset($maps[$slug])) {
            return $maps[$slug];
        }

        return $slug;
    }

    private function getSiteID($siteName)
    {
        $site = fluentCart('db')
            ->table('fct_license_sites')
            ->where('site_url', $siteName)
            ->first();

        if ($site) {
            return $site->id;
        }

        $siteId = fluentCart('db')
            ->table('fct_license_sites')
            ->insertGetId([
                'site_url'   => $siteName,
                'other'      => \json_encode([]),
                'created_at' => $this->getPaymentDate('post_date'),
                'updated_at' => $this->getPaymentDate('post_date')
            ]);

        return $siteId;
    }

    protected function guessRefundDate()
    {
        if (!$this->activities) {
            return $this->getPaymentDate('post_date');
        }

        $refundDate = null;
        foreach ($this->activities as $activity) {
            $content = strtolower(Arr::get($activity, 'content', ''));
            if (str_contains($content, 'refund')) {
                $refundDate = Arr::get($activity, 'created_at');
                break;
            }
        }

        if (!$refundDate) {
            // get the last activity date
            $refundDate = $this->activities[count($this->activities) - 1]['created_at'] ?? null;
        }

        if (!$refundDate) {
            return $this->getPaymentDate('post_date');
        }

        return $refundDate;
    }

    protected function maybeCreateDummySubscription()
    {
        if (!$this->renewwingLicense || !$this->renewwingLicense->expiration_date) {
            return null;
        }

        // check for existing subscription
        $existingSubscription = fluentCart('db')
            ->table('fct_subscriptions')
            ->where('parent_order_id', $this->parentOrderId)
            ->first();

        if ($existingSubscription) {
            return $existingSubscription;
        }

        $parentOrderPost = fluentCart('db')
            ->table('posts')
            ->where('ID', $this->parentOrderId)
            ->first();

        if (!$parentOrderPost || $parentOrderPost->post_status != 'publish' || $parentOrderPost->post_type != 'edd_payment' || $parentOrderPost->post_parent) {
            return null;
        }

        $mainOrderPaymentMeta = get_post_meta($this->parentOrderId, '_edd_payment_meta', true);

        $cartDetails = Arr::get($mainOrderPaymentMeta, 'cart_details', []);

        $subscriptionItem = null;

        foreach ($cartDetails as $cartItem) {
            if ($subscriptionItem) {
                continue;
            }

            $fctProductDetails = MigratorHelper::getTransformedProductDetails(Arr::get($cartItem, 'item_number.id'), Arr::get($cartItem, 'item_number.options.price_id'));
            if (!$fctProductDetails || is_wp_error($fctProductDetails)) {
                continue;
            }

            $period = Arr::pull($cartItem, 'item_number.options.recurring.period', '');

            $itemPrice = MigratorHelper::toCents(Arr::get($cartItem, 'item_price', 0), $this->eddCurrency);

            if (!$itemPrice) {
                continue;
            }

            if ($period) {
                $subscriptionItem = [
                    'customer_id'            => $this->customer->id,
                    'parent_order_id'        => $parentOrderPost->ID,
                    'product_id'             => $fctProductDetails['id'],
                    'item_name'              => Arr::get($fctProductDetails, 'full_title'),
                    'quantity'               => 1,
                    'variation_id'           => Arr::get($fctProductDetails, 'variation_id', NULL),
                    'billing_interval'       => $this->getPeriodSlug($period),
                    'signup_fee'             => 0,
                    'initial_tax_total'      => 0,
                    'recurring_amount'       => $itemPrice,
                    'recurring_tax_total'    => 0,
                    'recurring_total'        => $itemPrice,
                    'bill_times'             => 0,
                    'bill_count'             => 0,
                    'expire_at'              => $this->renewwingLicense->expiration_date,
                    'trial_ends_at'          => NULL,
                    'canceled_at'            => NULL,
                    'restored_at'            => NULL,
                    'collection_method'      => 'automatic',
                    'next_billing_date'      => $this->renewwingLicense->expiration_date,
                    'trial_days'             => 0,
                    'vendor_customer_id'     => '',
                    'vendor_plan_id'         => '',
                    'vendor_subscription_id' => '',
                    'status'                 => 'canceled',
                    'original_plan'          => '',
                    'vendor_response'        => '',
                    'current_payment_method' => $this->paymentMethod,
                    'created_at'             => $parentOrderPost->post_date_gmt,
                    'updated_at'             => current_time('mysql'),
                    'config'                 => \json_encode([
                        'note' => 'Dummy subscription created for renewwing license.',
                    ]),
                ];
            }
        }

        if (!$subscriptionItem) {
            return null;
        }

        $dummySubId = fluentCart('db')
            ->table('fct_subscriptions')
            ->insertGetId($subscriptionItem);

        $existingSubscription = fluentCart('db')
            ->table('fct_subscriptions')
            ->where('id', $dummySubId)
            ->first();

        // now let's make the parent order's transaction type as subscription
        fluentCart('db')->table('fct_orders')
            ->where('id', $parentOrderPost->ID)
            ->update([
                'type' => 'subscription'
            ]);

        // transactions
        fluentCart('db')->table('fct_order_transactions')
            ->where('order_id', $parentOrderPost->ID)
            ->where('order_type', 'payment')
            ->update([
                'order_type'      => 'subscription',
                'subscription_id' => $dummySubId,
            ]);

        // now let's update the license subscription id

        if ($this->renewwingLicense) {
            fluentCart('db')->table('fct_licenses')
                ->where('id', $this->renewwingLicense->id)
                ->update([
                    'subscription_id' => $dummySubId,
                ]);
        }


        return $existingSubscription;
    }

    protected function addActivityLog($title, $description, $paymentId = null)
    {
        $this->activities[] = [
            'status'      => 'info',
            'log_type'    => 'activity',
            'module_type' => 'FluentCart\App\Models\Order',
            'module_id'   => $paymentId ? $paymentId : $this->payment->ID,
            'module_name' => 'Order',
            'user_id'     => NULL,
            'title'       => $title,
            'content'     => $description,
            'read_status' => 'read',
            'created_by'  => 'Migrator',
            'created_at'  => $this->getPaymentDate('post_date'),
            'updated_at'  => $this->getPaymentDate('post_date'),
        ];
    }

}
