<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCart\App\Helpers\Status;
use FluentCartMigrator\Classes\Dto\AddressData;
use FluentCartMigrator\Classes\Dto\AppliedCouponData;
use FluentCartMigrator\Classes\Dto\CustomerData;
use FluentCartMigrator\Classes\Dto\OrderData;
use FluentCartMigrator\Classes\Dto\OrderItemData;
use FluentCartMigrator\Classes\Dto\SubscriptionData;
use FluentCartMigrator\Classes\Dto\TaxRateData;
use FluentCartMigrator\Classes\Dto\TransactionData;
use FluentCartMigrator\Classes\Load\CustomerWriter;
use FluentCartMigrator\Classes\Load\OrderWriter;
use FluentCartMigrator\Classes\Support\BatchRuntime;

/**
 * WooCommerce order source: Extract + Transform only.
 *
 * Reads through the WooCommerce CRUD API (wc_get_orders / WC_Order, HPOS-safe),
 * maps each order to a normalized OrderData DTO (reconciled, cents-based,
 * FluentCart-status-mapped), and hands it to the shared OrderWriter, which owns
 * all FluentCart inserts, idempotency, validation and uuid generation. Nothing
 * here knows the FluentCart order schema.
 */
class OrderMigrator
{
    /**
     * Migrate one page of WooCommerce orders.
     *
     * @return array{processed:int,has_more:bool}
     */
    public function migratePage($page = 1, $perPage = 100)
    {
        $query = wc_get_orders([
            'limit'    => $perPage,
            'page'     => $page,
            'paginate' => true,
            'type'     => 'shop_order',
            'status'   => $this->migratableStatuses(),
            'orderby'  => 'ID',
            'order'    => 'ASC',
            'return'   => 'ids',
        ]);

        $ids     = $query->orders ?? [];
        $hasMore = $page < (int) ($query->max_num_pages ?? 1);

        $writer    = new OrderWriter();
        $processed = 0;
        foreach ($ids as $orderId) {
            $result = $this->migrateOrder((int) $orderId, $writer);
            if (is_wp_error($result)) {
                $this->logFailed((int) $orderId, $result);
            }

            if (++$processed % 25 === 0) {
                BatchRuntime::freeMemory();
            }
        }

        return [
            'processed' => count($ids),
            'has_more'  => $hasMore,
        ];
    }

    /**
     * Transform one WC order to an OrderData and write it.
     *
     * @return int|\WP_Error created order id
     */
    public function migrateOrder($wcOrderId, OrderWriter $writer = null)
    {
        $writer = $writer ?: new OrderWriter();

        $order = wc_get_order($wcOrderId);
        if (!$order || !($order instanceof \WC_Order)) {
            return new \WP_Error('woo_no_order', 'WooCommerce order not found: ' . $wcOrderId);
        }

        $currency  = $order->get_currency();
        $createdAt = MigratorHelper::date($order->get_date_created());

        $customer = CustomerWriter::findOrCreate($this->buildCustomer($order, $createdAt));
        if (is_wp_error($customer)) {
            return $customer;
        }

        $items = $this->buildItems($order, $currency, $createdAt);
        if (!$items) {
            return new \WP_Error('woo_empty_order', 'Order #' . $wcOrderId . ' has no migratable line items.');
        }

        // --- Totals (derived from items, reconciled to the WC order total) ---
        $subtotal       = array_sum(array_map(function ($i) { return $i->subtotal; }, $items));
        $couponDiscount = array_sum(array_map(function ($i) { return $i->discountTotal; }, $items));
        $taxTotal       = MigratorHelper::toCents($order->get_total_tax(), $currency);
        $shippingTotal  = MigratorHelper::toCents($order->get_shipping_total(), $currency);
        $shippingTax    = MigratorHelper::toCents($order->get_shipping_tax(), $currency);
        $feeTotal       = $this->feeTotal($order, $currency);
        $orderTotal     = MigratorHelper::toCents($order->get_total(), $currency);

        $components     = $subtotal + $taxTotal + $shippingTotal + $feeTotal - $couponDiscount;
        $diff           = $components - $orderTotal;
        $manualDiscount = 0;
        if ($diff > 0) {
            $manualDiscount = $diff;
        } elseif ($diff < 0) {
            $feeTotal += -$diff; // WC total exceeds our components: absorb as a fee
        }

        $paymentStatus = MigratorHelper::paymentStatus($order);
        $isPaid        = in_array($paymentStatus, [
            Status::PAYMENT_PAID,
            Status::PAYMENT_REFUNDED,
            Status::PAYMENT_PARTIALLY_REFUNDED,
        ], true);
        $totalPaid   = $isPaid ? $orderTotal : 0;
        $totalRefund = min(MigratorHelper::toCents($order->get_total_refunded(), $currency), $totalPaid);

        $orderType   = $this->resolveOrderType($order);
        $completedAt = $order->get_date_completed() ? MigratorHelper::date($order->get_date_completed()) : null;
        $modifiedAt  = MigratorHelper::date($order->get_date_modified());

        $data                      = new OrderData();
        $data->id                  = $wcOrderId;
        $data->status              = MigratorHelper::orderStatus($order->get_status());
        $data->parentId            = (int) $order->get_parent_id();
        $data->receiptNumber       = $wcOrderId;
        $data->invoiceNo           = (string) $order->get_order_number();
        $data->fulfillmentType     = $this->orderFulfillmentType($items);
        $data->type                = $orderType;
        $data->mode                = Status::ORDER_MODE_LIVE;
        $data->customerId          = (int) $customer->id;
        $data->paymentMethod       = $order->get_payment_method() ?: 'offline';
        $data->paymentStatus       = $paymentStatus;
        $data->paymentMethodTitle  = $order->get_payment_method_title() ?: ucfirst($order->get_payment_method() ?: 'Offline');
        $data->currency            = $currency;
        $data->subtotal            = $subtotal;
        $data->manualDiscountTotal = $manualDiscount;
        $data->couponDiscountTotal = $couponDiscount;
        $data->shippingTax         = $shippingTax;
        $data->shippingTotal       = $shippingTotal;
        $data->feeTotal            = $feeTotal;
        $data->taxTotal            = $taxTotal;
        $data->taxBehavior         = $taxTotal > 0 ? 1 : 0;
        $data->totalAmount         = $orderTotal;
        $data->totalPaid           = $totalPaid;
        $data->totalRefund         = $totalRefund;
        $data->note                = (string) $order->get_customer_note();
        $data->ipAddress           = (string) $order->get_customer_ip_address();
        $data->completedAt         = $completedAt;
        $data->refundedAt          = $totalRefund > 0 ? $modifiedAt : null;
        $data->config              = ['migrated_from' => 'woocommerce'];
        $data->createdAt           = $createdAt;
        $data->updatedAt           = $modifiedAt;

        $data->items   = $items;
        $data->addresses = $this->buildAddresses($order, $createdAt);
        $data->coupons   = $this->buildCoupons($order, $currency, $createdAt);
        $data->taxRates  = $this->buildTaxRates($order, $currency, $createdAt);

        $data->transactions = [$this->buildCharge($order, $orderType, $totalPaid, $currency, $createdAt)];
        $data->refunds      = $this->buildRefunds($order, $orderType, $currency);

        // Subscriptions: parent orders create them; renewal orders link to one.
        if ($orderType === Status::ORDER_TYPE_SUBSCRIPTION) {
            $data->subscriptions = $this->buildSubscriptions($order, $wcOrderId, $currency, $createdAt);
        } elseif ($orderType === Status::ORDER_TYPE_RENEWAL) {
            $data->linkedSubscriptionId = $this->findRenewalSubscriptionId($wcOrderId);
        }

        $result = $writer->write($data);
        if (is_wp_error($result)) {
            return $result;
        }

        return $result['order_id'];
    }

    /* -----------------------------------------------------------------
     | Transform helpers
     * ----------------------------------------------------------------- */

    private function buildCustomer($order, $createdAt)
    {
        $email  = $order->get_billing_email();
        $userId = (int) $order->get_customer_id();
        if (!$email && $userId) {
            $user  = get_user_by('id', $userId);
            $email = $user ? $user->user_email : '';
        }

        return CustomerData::make([
            'userId'    => $userId ?: null,
            'email'     => $email,
            'firstName' => $order->get_billing_first_name(),
            'lastName'  => $order->get_billing_last_name(),
            'country'   => $order->get_billing_country(),
            'city'      => $order->get_billing_city(),
            'state'     => $order->get_billing_state(),
            'postcode'  => $order->get_billing_postcode(),
            'createdAt' => $createdAt,
            'updatedAt' => current_time('mysql'),
        ]);
    }

    /**
     * @return OrderItemData[]
     */
    private function buildItems($order, $currency, $createdAt)
    {
        $items     = [];
        $cartIndex = 0;

        foreach ($order->get_items() as $lineItem) {
            $cartIndex++;
            /** @var \WC_Order_Item_Product $lineItem */
            $quantity = max(1, (int) $lineItem->get_quantity());

            $subtotal      = MigratorHelper::toCents($lineItem->get_subtotal(), $currency);
            $lineTotal     = MigratorHelper::toCents($lineItem->get_total(), $currency);
            $discountTotal = max(0, $subtotal - $lineTotal);
            $taxAmount     = MigratorHelper::toCents($lineItem->get_total_tax(), $currency);
            $unitPrice     = $quantity > 0 ? intdiv($subtotal, $quantity) : $subtotal;

            $map            = $this->mapProduct((int) $lineItem->get_product_id(), (int) $lineItem->get_variation_id());
            $product        = $lineItem->get_product();
            $fulfillment    = $product ? MigratorHelper::fulfillmentType($product) : 'physical';
            $isSubscription = $product && $this->isSubscriptionProduct($product);

            $items[] = OrderItemData::make([
                'postId'         => $map['post_id'],
                'fulfillmentType' => $fulfillment,
                'paymentType'    => $isSubscription ? 'subscription' : 'onetime',
                'postTitle'      => $map['post_title'] ?: $lineItem->get_name(),
                'title'          => $map['title'],
                'objectId'       => $map['variation_id'],
                'quantity'       => $quantity,
                'unitPrice'      => $unitPrice,
                'subtotal'       => $subtotal,
                'taxAmount'      => $taxAmount,
                'discountTotal'  => $discountTotal,
                'lineTotal'      => $subtotal - $discountTotal,
                'otherInfo'      => ['payment_type' => $isSubscription ? 'subscription' : 'onetime'],
                'cartIndex'      => $cartIndex,
                'createdAt'      => $createdAt,
                'updatedAt'      => $createdAt,
            ]);
        }

        return $items;
    }

    private function buildCharge($order, $orderType, $totalPaid, $currency, $createdAt)
    {
        return TransactionData::make([
            'orderType'         => $orderType,
            'transactionType'   => Status::TRANSACTION_TYPE_CHARGE,
            'vendorChargeId'    => (string) $order->get_transaction_id(),
            'paymentMethod'     => $order->get_payment_method() ?: 'offline',
            'paymentMode'       => Status::ORDER_MODE_LIVE,
            'status'            => $totalPaid > 0 ? Status::TRANSACTION_SUCCEEDED : Status::TRANSACTION_PENDING,
            'currency'          => $currency,
            'total'             => $totalPaid,
            'meta'              => ['fallback' => 1],
            'createdAt'         => $createdAt,
            'updatedAt'         => current_time('mysql'),
        ]);
    }

    /**
     * @return TransactionData[]
     */
    private function buildRefunds($order, $orderType, $currency)
    {
        $refunds = [];
        foreach ($order->get_refunds() as $refund) {
            $amount = MigratorHelper::toCents($refund->get_amount(), $currency);
            if (!$amount) {
                continue;
            }
            $refunds[] = TransactionData::make([
                'orderType'       => $orderType,
                'transactionType' => Status::TRANSACTION_TYPE_REFUND,
                'paymentMethod'   => $order->get_payment_method() ?: 'offline',
                'paymentMode'     => Status::ORDER_MODE_LIVE,
                'status'          => Status::TRANSACTION_REFUNDED,
                'currency'        => $currency,
                'total'           => $amount,
                'createdAt'       => MigratorHelper::date($refund->get_date_created()),
                'updatedAt'       => current_time('mysql'),
            ]);
        }
        return $refunds;
    }

    /**
     * @return AddressData[]
     */
    private function buildAddresses($order, $createdAt)
    {
        return [
            AddressData::make([
                'type'      => 'billing',
                'name'      => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'address1'  => $order->get_billing_address_1(),
                'address2'  => $order->get_billing_address_2(),
                'city'      => $order->get_billing_city(),
                'state'     => $order->get_billing_state(),
                'postcode'  => $order->get_billing_postcode(),
                'country'   => $order->get_billing_country(),
                'meta'      => array_filter([
                    'company_name' => $order->get_billing_company(),
                    'phone'        => $order->get_billing_phone(),
                    'email'        => $order->get_billing_email(),
                ]),
                'createdAt' => $createdAt,
                'updatedAt' => $createdAt,
            ]),
            AddressData::make([
                'type'      => 'shipping',
                'name'      => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
                'address1'  => $order->get_shipping_address_1(),
                'address2'  => $order->get_shipping_address_2(),
                'city'      => $order->get_shipping_city(),
                'state'     => $order->get_shipping_state(),
                'postcode'  => $order->get_shipping_postcode(),
                'country'   => $order->get_shipping_country(),
                'meta'      => array_filter(['company_name' => $order->get_shipping_company()]),
                'createdAt' => $createdAt,
                'updatedAt' => $createdAt,
            ]),
        ];
    }

    /**
     * @return AppliedCouponData[]
     */
    private function buildCoupons($order, $currency, $createdAt)
    {
        $coupons = [];
        foreach ($order->get_items('coupon') as $coupon) {
            /** @var \WC_Order_Item_Coupon $coupon */
            $code = $coupon->get_code();
            if (!$code) {
                continue;
            }
            $coupons[] = AppliedCouponData::make([
                'code'      => $code,
                'amount'    => MigratorHelper::toCents($coupon->get_discount(), $currency),
                'couponId'  => MigratorHelper::couponIdByCode($code),
                'createdAt' => $createdAt,
                'updatedAt' => $createdAt,
            ]);
        }
        return $coupons;
    }

    /**
     * @return TaxRateData[]
     */
    private function buildTaxRates($order, $currency, $createdAt)
    {
        $rates   = [];
        $rateMap = TaxRateMigrator::rateMap();

        foreach ($order->get_items('tax') as $tax) {
            /** @var \WC_Order_Item_Tax $tax */
            $orderTax    = MigratorHelper::toCents($tax->get_tax_total(), $currency);
            $shippingTax = MigratorHelper::toCents($tax->get_shipping_tax_total(), $currency);
            $taxAmount   = $orderTax + $shippingTax;
            if (!$taxAmount) {
                continue;
            }

            // fct_order_tax_rate.tax_rate_id is NOT NULL — only write the
            // per-rate breakdown row when the WC rate maps to a FluentCart rate
            // (run the tax-rates step first). The order header tax_total still
            // carries the amount regardless.
            $fctRateId = $rateMap[(int) $tax->get_rate_id()] ?? null;
            if (!$fctRateId) {
                continue;
            }

            $rates[] = TaxRateData::make([
                'taxRateId'   => $fctRateId,
                'shippingTax' => $shippingTax,
                'orderTax'    => $orderTax,
                'totalTax'    => $taxAmount,
                'meta'        => [
                    'inclusive'     => false,
                    'rates'         => [[
                        'label'      => $tax->get_label(),
                        'tax_amount' => $taxAmount,
                        'rate_id'    => (int) $tax->get_rate_id(),
                    ]],
                    'tax_country'   => $order->get_billing_country(),
                    'migrated_from' => 'woocommerce',
                ],
                'createdAt'   => $createdAt,
                'updatedAt'   => $createdAt,
            ]);
        }
        return $rates;
    }

    /**
     * @return SubscriptionData[]
     */
    private function buildSubscriptions($order, $orderId, $currency, $createdAt)
    {
        if (!function_exists('wcs_get_subscriptions_for_order')) {
            return [];
        }

        $subscriptions = wcs_get_subscriptions_for_order($orderId, ['order_type' => ['parent']]);
        $out           = [];

        foreach ($subscriptions as $wcSub) {
            /** @var \WC_Subscription $wcSub */
            $productId = 0;
            $variation = 0;
            $itemName  = '';
            foreach ($wcSub->get_items() as $subItem) {
                $productId = (int) $subItem->get_product_id();
                $variation = (int) $subItem->get_variation_id();
                $itemName  = $subItem->get_name();
                break;
            }

            $map       = $this->mapProduct($productId, $variation);
            $recurring = MigratorHelper::toCents($wcSub->get_total(), $currency);
            $signupFee = method_exists($wcSub, 'get_sign_up_fee')
                ? MigratorHelper::toCents($wcSub->get_sign_up_fee(), $currency)
                : 0;

            $out[] = SubscriptionData::make([
                'productId'            => $map['post_id'],
                'itemName'             => $itemName,
                'variationId'          => $map['variation_id'] ?: 0,
                'billingInterval'      => MigratorHelper::billingInterval($wcSub->get_billing_period()),
                'signupFee'            => max(0, $signupFee),
                'recurringAmount'      => $recurring,
                'recurringTotal'       => $recurring,
                'expireAt'             => $this->nullableDate($wcSub->get_date('end')),
                'trialEndsAt'          => $this->nullableDate($wcSub->get_date('trial_end')),
                'canceledAt'           => $wcSub->get_status() === 'cancelled' ? $this->nullableDate($wcSub->get_date('cancelled')) : null,
                'nextBillingDate'      => $this->nullableDate($wcSub->get_date('next_payment')),
                'vendorSubscriptionId' => (string) $wcSub->get_id(),
                'status'               => MigratorHelper::subscriptionStatus($wcSub->get_status()),
                'currentPaymentMethod' => $order->get_payment_method() ?: 'offline',
                'createdAt'            => $createdAt,
                'updatedAt'            => current_time('mysql'),
                'config'               => [
                    'wc_subscription_id' => $wcSub->get_id(),
                    'billing_interval'   => (int) $wcSub->get_billing_interval(),
                    'currency'           => $currency,
                ],
            ]);
        }

        return $out;
    }

    private function findRenewalSubscriptionId($orderId)
    {
        if (!function_exists('wcs_get_subscriptions_for_renewal_order')) {
            return null;
        }

        foreach (wcs_get_subscriptions_for_renewal_order($orderId) as $wcSub) {
            $parentId = (int) $wcSub->get_parent_id();
            if (!$parentId) {
                continue;
            }
            $fctSub = fluentCart('db')->table('fct_subscriptions')->where('parent_order_id', $parentId)->first();
            if ($fctSub) {
                return (int) $fctSub->id;
            }
        }

        return null;
    }

    /* -----------------------------------------------------------------
     | Product mapping & misc
     * ----------------------------------------------------------------- */

    /**
     * @return array{post_id:int,variation_id:?int,post_title:string,title:string}
     */
    private function mapProduct($wcProductId, $wcVariationId)
    {
        $result = ['post_id' => 0, 'variation_id' => null, 'post_title' => '', 'title' => ''];

        if (!$wcProductId) {
            return $result;
        }

        $fctPostId = (int) get_post_meta($wcProductId, MigratorHelper::WC_TO_FCT_META, true);
        if ($fctPostId && get_post($fctPostId)) {
            $result['post_id']    = $fctPostId;
            $result['post_title'] = get_the_title($fctPostId);

            $lookupKey = $wcVariationId ?: '0';
            $varMap    = get_post_meta($fctPostId, MigratorHelper::VARIATION_MAP_META, true);
            if (is_array($varMap) && isset($varMap[$lookupKey])) {
                $result['variation_id'] = (int) $varMap[$lookupKey];
            }
        }

        if ($wcVariationId) {
            $variation = wc_get_product($wcVariationId);
            if ($variation && method_exists($variation, 'get_attribute_summary')) {
                $result['title'] = (string) $variation->get_attribute_summary();
            }
        }

        return $result;
    }

    private function feeTotal($order, $currency)
    {
        $total = 0;
        foreach ($order->get_items('fee') as $fee) {
            $total += MigratorHelper::toCents($fee->get_total(), $currency);
        }
        return $total;
    }

    /**
     * @param OrderItemData[] $items
     */
    private function orderFulfillmentType($items)
    {
        $types = array_values(array_unique(array_map(function ($i) { return $i->fulfillmentType; }, $items)));
        return count($types) === 1 ? $types[0] : 'physical';
    }

    private function resolveOrderType($order)
    {
        if (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order)) {
            return Status::ORDER_TYPE_RENEWAL;
        }
        if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order, 'parent')) {
            return Status::ORDER_TYPE_SUBSCRIPTION;
        }
        return Status::ORDER_TYPE_PAYMENT;
    }

    private function nullableDate($value)
    {
        if (!$value || $value === '0' || strpos((string) $value, '0000-00-00') === 0) {
            return null;
        }
        return $value;
    }

    private function isSubscriptionProduct($product)
    {
        return class_exists('WC_Subscriptions_Product')
            && \WC_Subscriptions_Product::is_subscription($product);
    }

    private function migratableStatuses()
    {
        $statuses = array_keys(wc_get_order_statuses()); // ['wc-pending', ...]

        return array_values(array_filter($statuses, function ($status) {
            return !in_array($status, ['wc-checkout-draft'], true);
        }));
    }

    private function logFailed($orderId, $error)
    {
        $optionKey = '_fluent_cart_woocommerce_failed_logs';
        $logs      = get_option($optionKey, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        $logs[$orderId] = [
            'message'    => $error->get_error_message(),
            'error_type' => $error->get_error_code(),
            'stage'      => 'order_migration',
        ];

        update_option($optionKey, $logs, false);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::warning('Order #' . $orderId . ' failed: ' . $error->get_error_message());
        }
    }
}
