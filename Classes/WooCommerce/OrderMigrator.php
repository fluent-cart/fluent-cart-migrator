<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCart\App\Helpers\Status;
use FluentCartMigrator\Classes\Dto\ActivityData;
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
            try {
                $result = $this->migrateOrder((int) $orderId, $writer);
            } catch (\Throwable $e) {
                $result = new \WP_Error(
                    'woo_order_exception',
                    sprintf('Order #%d failed: %s', (int) $orderId, $e->getMessage()),
                    ['exception' => get_class($e)]
                );
            }

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

        $items = $this->buildItems($order, $currency, $createdAt, $this->refundedPerItem($order, $currency));
        if (!$items) {
            return new \WP_Error('woo_empty_order', 'Order #' . $wcOrderId . ' has no migratable line items.');
        }

        // --- Totals (derived from items, reconciled to the WC order total) ---
        $subtotal       = array_sum(array_map(function ($i) { return $i->subtotal; }, $items));
        $couponDiscount = array_sum(array_map(function ($i) { return $i->discountTotal; }, $items));
        // WC get_total_tax() = cart tax + shipping tax. FluentCart stores cart tax
        // in tax_total and shipping tax in shipping_tax as *separate* additive
        // components (Order::… tax_total + shipping_tax), so use cart tax only
        // here — otherwise shipping tax is counted twice on shipping-taxed stores.
        $taxTotal       = MigratorHelper::toCents($order->get_cart_tax(), $currency);
        $shippingTotal  = MigratorHelper::toCents($order->get_shipping_total(), $currency);
        $shippingTax    = MigratorHelper::toCents($order->get_shipping_tax(), $currency);
        $feeTotal       = $this->feeTotal($order, $currency);
        $orderTotal     = MigratorHelper::toCents($order->get_total(), $currency);

        $components     = $subtotal + $taxTotal + $shippingTax + $shippingTotal + $feeTotal - $couponDiscount;
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

        // If the refund wasn't allocated per line item by WooCommerce, spread
        // the order-level refund across items proportionally to their line total.
        if ($totalRefund > 0 && array_sum(array_map(function ($i) { return $i->refundTotal; }, $items)) === 0) {
            $base = array_sum(array_map(function ($i) { return $i->lineTotal; }, $items));
            if ($base > 0) {
                foreach ($items as $item) {
                    if ($item->lineTotal <= 0) {
                        continue;
                    }
                    $item->refundTotal = min(
                        (int) round(($item->lineTotal / $base) * $totalRefund),
                        $item->lineTotal
                    );
                }
            }
        }

        $orderType   = $this->resolveOrderType($order);
        $completedAt = $order->get_date_completed() ? MigratorHelper::date($order->get_date_completed()) : null;
        $modifiedAt  = MigratorHelper::date($order->get_date_modified());
        // Resolved once and threaded through the transactions, so an order can
        // never disagree with its own charge about which account it belongs to.
        $mode        = MigratorHelper::orderMode($order);

        $data                      = new OrderData();
        $data->id                  = $wcOrderId;
        $data->status              = MigratorHelper::orderStatus($order->get_status());
        $data->parentId            = (int) $order->get_parent_id();
        $data->receiptNumber       = $wcOrderId;
        $data->invoiceNo           = (string) $order->get_order_number();
        $data->fulfillmentType     = $this->orderFulfillmentType($items);
        $data->type                = $orderType;
        $data->mode                = $mode;
        $data->customerId          = (int) $customer->id;
        $data->paymentMethod       = MigratorHelper::gatewaySlug($order->get_payment_method());
        $data->paymentStatus       = $paymentStatus;
        $data->paymentMethodTitle  = $order->get_payment_method_title() ?: ucfirst($order->get_payment_method() ?: 'Offline Payment');
        $data->currency            = $currency;
        $data->subtotal            = $subtotal;
        $data->manualDiscountTotal = $manualDiscount;
        $data->couponDiscountTotal = $couponDiscount;
        $data->shippingTax         = $shippingTax;
        $data->shippingTotal       = $shippingTotal;
        $data->feeTotal            = $feeTotal;
        $data->taxTotal            = $taxTotal;
        // FluentCart tax_behavior: 0 = none, 1 = exclusive, 2 = inclusive.
        $pricesIncludeTax          = function_exists('wc_prices_include_tax') && wc_prices_include_tax();
        $data->taxBehavior         = $taxTotal > 0 ? ($pricesIncludeTax ? 2 : 1) : 0;
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
        $data->activities = $this->buildActivities($order, $wcOrderId);

        $data->transactions = [$this->buildCharge($order, $orderType, $totalPaid, $currency, $createdAt, $mode)];
        $data->refunds      = $this->buildRefunds($order, $orderType, $currency, $mode);

        // Subscriptions: parent orders create them; renewal orders link to one
        // (or, if the parent subscription is missing, get a dummy so the renewal
        // transaction still attaches to a subscription — mirrors the EDD source).
        if ($orderType === Status::ORDER_TYPE_SUBSCRIPTION) {
            $data->subscriptions = $this->buildSubscriptions($order, $wcOrderId, $currency, $createdAt);
        } elseif ($orderType === Status::ORDER_TYPE_RENEWAL) {
            $sourceSubIds = $this->renewalSubscriptionIds($order);
            $linked       = $this->findRenewalSubscription($wcOrderId, $sourceSubIds);
            if ($linked) {
                $data->linkedSubscriptionId = $linked['id'];
                // Stamp the parent (subscription's first) order id so the shared
                // recountSubscriptions/fixReactivations can attribute this renewal
                // to its subscription — WC renewal orders have no WP post parent.
                $data->parentId = $linked['parent_order_id'];
            } else {
                // No migrated parent (and no prior placeholder): create a single
                // dummy keyed by the source subscription id, so every later
                // renewal of the same deleted subscription links to this one
                // instead of spawning its own.
                $dummy = $this->buildDummySubscription($order, $currency, $createdAt, reset($sourceSubIds) ?: 0);
                if ($dummy) {
                    $data->subscriptions = [$dummy];
                }
            }
        }

        // Best-effort switch/upgrade marker (WC Subscriptions only). Record the
        // related subscription ids so the upgrade/downgrade chain isn't entirely
        // lost (mirrors EDD's upgraded_from/to intent). UNVERIFIED — needs the
        // WC Subscriptions switching feature.
        if (function_exists('wcs_order_contains_switch') && wcs_order_contains_switch($order)) {
            $data->config['contains_switch'] = true;
            if (function_exists('wcs_get_subscriptions_for_switch_order')) {
                $switchedIds = array_values(array_map('intval', array_keys(wcs_get_subscriptions_for_switch_order($order))));
                if ($switchedIds) {
                    $data->config['switched_subscription_ids'] = $switchedIds;
                }
            }
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
            'addresses' => $this->buildCustomerAddresses($order, $email, $createdAt),
        ]);
    }

    /**
     * Address-book rows for the customer, seeded from the order (the writer
     * only inserts these when it creates the customer, so the customer's first
     * migrated order wins). Billing carries the phone the order addresses only
     * keep in meta.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildCustomerAddresses($order, $email, $createdAt)
    {
        $addresses = [];

        if ($order->get_billing_address_1() || $order->get_billing_city()) {
            $addresses[] = [
                'is_primary' => 1,
                'type'       => 'billing',
                'name'       => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'phone'      => $order->get_billing_phone(),
                'email'      => $email,
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
                'created_at' => $createdAt,
            ];
        }

        if ($order->has_shipping_address() && $order->get_shipping_address_1()) {
            $addresses[] = [
                'is_primary' => 0,
                'type'       => 'shipping',
                'name'       => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'city'       => $order->get_shipping_city(),
                'state'      => $order->get_shipping_state(),
                'phone'      => method_exists($order, 'get_shipping_phone') ? $order->get_shipping_phone() : '',
                'postcode'   => $order->get_shipping_postcode(),
                'country'    => $order->get_shipping_country(),
                'created_at' => $createdAt,
            ];
        }

        return $addresses;
    }

    /**
     * @return OrderItemData[]
     */
    private function buildItems($order, $currency, $createdAt, array $refundMap = [])
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
                'refundTotal'    => (int) ($refundMap[(int) $lineItem->get_id()] ?? 0),
                'otherInfo'      => ['payment_type' => $isSubscription ? 'subscription' : 'onetime'],
                'cartIndex'      => $cartIndex,
                'createdAt'      => $createdAt,
                'updatedAt'      => $createdAt,
            ]);
        }

        return $items;
    }

    /**
     * Sum the per-line refunded amount (incl. tax) keyed by the original WC
     * order item id, from each refund's line items (_refunded_item_id).
     *
     * @return array<int,int> wcOrderItemId => refundedCents
     */
    private function refundedPerItem($order, $currency)
    {
        $map = [];
        foreach ($order->get_refunds() as $refund) {
            foreach ($refund->get_items() as $refItem) {
                $origId = (int) $refItem->get_meta('_refunded_item_id');
                if (!$origId) {
                    continue;
                }
                $amount = abs(MigratorHelper::toCents($refItem->get_total(), $currency))
                    + abs(MigratorHelper::toCents($refItem->get_total_tax(), $currency));
                if (!$amount) {
                    continue;
                }
                $map[$origId] = ($map[$origId] ?? 0) + $amount;
            }
        }
        return $map;
    }

    private function buildCharge($order, $orderType, $totalPaid, $currency, $createdAt, $mode)
    {
        $method = MigratorHelper::gatewaySlug($order->get_payment_method());

        return TransactionData::make([
            'orderType'         => $orderType,
            'transactionType'   => Status::TRANSACTION_TYPE_CHARGE,
            'vendorChargeId'    => (string) $order->get_transaction_id(),
            'paymentMethod'     => $method,
            'paymentMethodType' => $method === 'stripe' ? 'card' : '',
            'paymentMode'       => $mode,
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
    private function buildRefunds($order, $orderType, $currency, $mode)
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
                'paymentMethod'   => MigratorHelper::gatewaySlug($order->get_payment_method()),
                'paymentMode'     => $mode,
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
        $rates     = [];
        $rateMap   = TaxRateMigrator::rateMap();
        $inclusive = function_exists('wc_prices_include_tax') && wc_prices_include_tax();
        $country   = $order->get_billing_country();
        $db        = fluentCart('db');

        foreach ($order->get_items('tax') as $tax) {
            /** @var \WC_Order_Item_Tax $tax */
            $orderTax    = MigratorHelper::toCents($tax->get_tax_total(), $currency);
            $shippingTax = MigratorHelper::toCents($tax->get_shipping_tax_total(), $currency);
            $taxAmount   = $orderTax + $shippingTax;
            if (!$taxAmount) {
                continue;
            }

            // FluentCart permits only one row for each (order_id, tax_rate_id).
            // Multiple Woo rates can map to the same FluentCart country/state
            // rate, so accumulate their totals into that one row and retain each
            // source component in meta.rates.
            $sourceRateId = (int) $tax->get_rate_id();
            $fctRateId    = (int) ($rateMap[$sourceRateId] ?? 0);
            $fctRate      = $fctRateId ? $db->table('fct_tax_rates')->where('id', $fctRateId)->first() : null;
            $ratePct      = method_exists($tax, 'get_rate_percent')
                ? (float) $tax->get_rate_percent()
                : ($fctRate ? (float) $fctRate->rate : 0.0);

            if (!isset($rates[$fctRateId])) {
                $rates[$fctRateId] = TaxRateData::make([
                    'taxRateId' => $fctRateId,
                    'meta'      => [
                        'inclusive'     => $inclusive,
                        'rates'         => [],
                        'tax_country'   => $country,
                        'migrated_from' => 'woocommerce',
                    ],
                    'createdAt' => $createdAt,
                    'updatedAt' => $createdAt,
                ]);
            }

            $rate = $rates[$fctRateId];
            $rate->shippingTax += $shippingTax;
            $rate->orderTax    += $orderTax;
            $rate->totalTax    += $taxAmount;
            $rate->meta['rates'][] = [
                'rate_id'        => $fctRateId,
                'source_rate_id' => $sourceRateId,
                'label'          => $tax->get_label(),
                'tax_amount'     => $taxAmount,
                'rate'           => $ratePct,
                'rate_percent'   => $ratePct,
                'for_shipping'   => $fctRate ? ($fctRate->for_shipping ?? '') : ($shippingTax > 0 ? 'yes' : ''),
                'country'        => $fctRate ? ($fctRate->country ?: $country) : $country,
                'is_compound'    => method_exists($tax, 'is_compound')
                    ? (bool) $tax->is_compound()
                    : (bool) ($fctRate->is_compound ?? false),
                'taxable_amount' => $ratePct > 0 ? (int) round($taxAmount * 100 / $ratePct) : 0,
            ];
        }

        return array_values($rates);
    }

    /**
     * Map WooCommerce order notes (system + customer) to fct_activity rows.
     *
     * @return ActivityData[]
     */
    private function buildActivities($order, $orderId)
    {
        if (!function_exists('wc_get_order_notes')) {
            return [];
        }

        $activities = [];
        foreach (wc_get_order_notes(['order_id' => $orderId]) as $note) {
            $content = trim((string) $note->content);
            if ($content === '') {
                continue;
            }

            $date = ($note->date_created instanceof \WC_DateTime)
                ? $note->date_created->date('Y-m-d H:i:s')
                : (string) $note->date_created;

            $activities[] = ActivityData::make([
                'moduleType' => 'FluentCart\App\Models\Order',
                'moduleId'   => $orderId,
                'moduleName' => 'Order',
                'title'      => $note->customer_note ? 'Note to customer' : 'Order note',
                'content'    => $content,
                'createdBy'  => $note->added_by ?: 'Migrator',
                'createdAt'  => $date,
                'updatedAt'  => $date,
            ]);
        }

        return $activities;
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
            $signupFee = method_exists($wcSub, 'get_sign_up_fee')
                ? MigratorHelper::toCents($wcSub->get_sign_up_fee(), $currency)
                : 0;

            // WC get_total() is tax-inclusive; split it so recurring_amount is
            // ex-tax, recurring_tax_total is the tax, recurring_total the gross
            // (recurring_amount + recurring_tax_total) — matches EDD semantics.
            $recurringTotal = MigratorHelper::toCents($wcSub->get_total(), $currency);
            $recurringTax   = method_exists($wcSub, 'get_total_tax')
                ? MigratorHelper::toCents($wcSub->get_total_tax(), $currency)
                : 0;
            $recurringAmount = max(0, $recurringTotal - $recurringTax);

            // bill_times / trial_days live on the (variation) product; without
            // bill_times the recount can never auto-complete a fixed-term sub.
            $subProduct = wc_get_product($variation ?: $productId);
            $billTimes  = ($subProduct && class_exists('WC_Subscriptions_Product'))
                ? (int) \WC_Subscriptions_Product::get_length($subProduct)
                : 0;
            $trialDays  = ($subProduct && class_exists('WC_Subscriptions_Product'))
                ? (int) \WC_Subscriptions_Product::get_trial_length($subProduct)
                : 0;

            // Renewals are handled by FluentCart's store-managed invoice engine
            // (WooCommerce Subscriptions has no gateway subscription object to
            // hand off). The collection method decides how each renewal is paid:
            //   - system : a reusable gateway token is on file and FluentCart's
            //              gateway can auto-charge it (Stripe pm_ + customer).
            //   - manual : no auto-chargeable token — FluentCart issues the
            //              renewal invoice and the customer pays it.
            $collection = $this->resolveSubscriptionCollection($wcSub);

            $out[] = SubscriptionData::make([
                'productId'            => $map['post_id'],
                'itemName'             => $itemName,
                'variationId'          => $map['variation_id'] ?: 0,
                'billingInterval'      => MigratorHelper::repeatInterval($wcSub->get_billing_period(), $wcSub->get_billing_interval()),
                'signupFee'            => max(0, $signupFee),
                'initialTaxTotal'      => MigratorHelper::toCents($order->get_total_tax(), $currency),
                'recurringAmount'      => $recurringAmount,
                'recurringTaxTotal'    => $recurringTax,
                'recurringTotal'       => $recurringTotal,
                'billTimes'            => $billTimes,
                'trialDays'            => $trialDays,
                'collectionMethod'     => $collection['method'],
                'expireAt'             => $this->nullableDate($wcSub->get_date('end')),
                'trialEndsAt'          => $this->nullableDate($wcSub->get_date('trial_end')),
                'canceledAt'           => $wcSub->get_status() === 'cancelled' ? $this->nullableDate($wcSub->get_date('cancelled')) : null,
                'nextBillingDate'      => $this->nullableDate($wcSub->get_date('next_payment')),
                'vendorSubscriptionId' => (string) $wcSub->get_id(),
                'vendorCustomerId'     => $collection['customerId'] ?: null,
                'status'               => MigratorHelper::subscriptionStatus($wcSub->get_status(), $wcSub->get_date('end')),
                'currentPaymentMethod' => MigratorHelper::gatewaySlug($order->get_payment_method()),
                'createdAt'            => $createdAt,
                'updatedAt'            => current_time('mysql'),
                'activities'           => $this->buildSubscriptionNotes($wcSub->get_id(), $createdAt),
                'meta'                 => $collection['meta'],
                'config'               => [
                    'wc_subscription_id' => $wcSub->get_id(),
                    'billing_interval'   => (int) $wcSub->get_billing_interval(),
                    'currency'           => $currency,
                    'switched'           => (function_exists('wcs_order_contains_switch') && wcs_order_contains_switch($order)) ? 'yes' : 'no',
                    'migration_source'   => 'woocommerce',
                ],
            ]);
        }

        return $out;
    }

    /**
     * Decide how a migrated WooCommerce subscription's renewals get paid, and
     * carry the reusable token when FluentCart can auto-charge it.
     *
     * `system` is returned ONLY when the saved token is genuinely chargeable by
     * FluentCart's system-charge engine, so a migrated subscription never lands
     * in a state that fails every renewal:
     *   - the subscription is not flagged for manual renewal;
     *   - the gateway is Stripe with a PaymentMethod token (`pm_…`) plus its
     *     Stripe customer id — the exact pair Stripe::chargeRenewalInvoice()
     *     needs (vendor_customer_id + active_payment_method token);
     *   - the FluentCart Stripe gateway actually declares `system_subscription`.
     *
     * Everything else stays `manual` (store-issued invoice the customer pays):
     *   - PayPal here stores a Billing Agreement id (`B-…`), not a PayPal Vault
     *     id, so FluentCart's vault-based charge cannot use it — the payer id is
     *     still kept as vendor_customer_id for reference;
     *   - legacy Stripe source/card tokens (`src_…`/`card_…`) aren't usable as a
     *     PaymentMethod;
     *   - offline / unknown gateways have nothing to charge.
     *
     * @return array{method:string,customerId:string,meta:array<string,mixed>}
     */
    private function resolveSubscriptionCollection($wcSub)
    {
        $slug = MigratorHelper::gatewaySlug($wcSub->get_payment_method());

        $result = [
            'method'     => 'manual',
            'customerId' => $this->subscriptionCustomerId($wcSub),
            'meta'       => [],
        ];

        // Use the stored manual-renewal flag, NOT WC_Subscription::is_manual():
        // is_manual() also returns true under the WooCommerce Subscriptions
        // "duplicate site" lock (any staging clone) and when the live gateway
        // isn't currently available — staging/runtime artifacts that would
        // wrongly downgrade every token-backed subscription to manual. The flag
        // reflects the merchant/customer's actual choice.
        $requiresManual = method_exists($wcSub, 'get_requires_manual_renewal')
            ? $wcSub->get_requires_manual_renewal()
            : $wcSub->get_meta('_requires_manual_renewal');
        if (filter_var($requiresManual, FILTER_VALIDATE_BOOLEAN)) {
            return $result;
        }

        if ($slug === 'stripe' && $this->gatewayCanSystemCharge('stripe')) {
            $customer = (string) $wcSub->get_meta('_stripe_customer_id');
            $token    = (string) $wcSub->get_meta('_stripe_source_id');

            // Only a PaymentMethod (pm_) is chargeable off-session via
            // payment_intents; src_/card_ legacy tokens are not.
            if ($customer !== '' && strpos($token, 'pm_') === 0) {
                $result['method']     = 'system';
                $result['customerId'] = $customer;
                $result['meta']['active_payment_method'] = [
                    'method'           => 'stripe',
                    'type'             => 'card',
                    'vendor_method_id' => $token,
                    'details'          => array_merge(
                        ['type' => 'card', 'payment_method_id' => $token],
                        $this->stripeCardDetails($token)
                    ),
                ];
            }
        }

        return $result;
    }

    /**
     * Whether the installed FluentCart gateway advertises token-based renewal
     * charging (`system_subscription`). Cached per slug. Degrades to false when
     * the gateway addon isn't registered.
     */
    private function gatewayCanSystemCharge($slug)
    {
        static $cache = [];

        if (!isset($cache[$slug])) {
            $can = false;
            if (class_exists('\FluentCart\App\Modules\PaymentMethods\Core\GatewayManager')) {
                $gateway = \FluentCart\App\Modules\PaymentMethods\Core\GatewayManager::getInstance($slug);
                $can = $gateway && method_exists($gateway, 'has') && $gateway->has('system_subscription');
            }
            $cache[$slug] = $can;
        }

        return $cache[$slug];
    }

    /**
     * Card display info (brand / last4 / expiry) for a Stripe PaymentMethod, read
     * from the WooCommerce payment-tokens vault (WC_Payment_Token_CC). FluentCart's
     * admin + customer portal render the card-on-file from
     * active_payment_method.details.brand/last_4 (Subscription::getPaymentMethodText),
     * so without these the saved card shows blank even though the token charges fine.
     *
     * Looks the token up by its string in the vault (not scoped to the
     * subscription's customer — WooCommerce can hold the same card under a
     * different user row), then hydrates the WC_Payment_Token for its getters.
     * Returns an empty array when the store kept only the raw source id (no vault
     * row) — the token still migrates, the display is just sparser.
     *
     * @return array{brand?:string,last_4?:string,exp_month?:string,exp_year?:string}
     */
    private function stripeCardDetails($token)
    {
        if (!$token || !class_exists('WC_Payment_Tokens')) {
            return [];
        }

        global $wpdb;
        $tokenId = $wpdb->get_var($wpdb->prepare(
            "SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE token = %s ORDER BY token_id DESC LIMIT 1",
            $token
        ));
        if (!$tokenId) {
            return [];
        }

        $wcToken = \WC_Payment_Tokens::get((int) $tokenId);
        if (!($wcToken instanceof \WC_Payment_Token_CC)) {
            return [];
        }

        return array_filter([
            'brand'     => $wcToken->get_card_type(),
            'last_4'    => $wcToken->get_last4(),
            'exp_month' => $wcToken->get_expiry_month(),
            'exp_year'  => $wcToken->get_expiry_year(),
        ], function ($v) {
            return $v !== '' && $v !== null;
        });
    }

    /**
     * Gateway customer id stored on the WC subscription (Stripe customer or PayPal
     * payer), kept on the FluentCart subscription as an accurate reference.
     *
     * @return string
     */
    private function subscriptionCustomerId($wcSub)
    {
        $method = (string) $wcSub->get_payment_method();

        if (strpos($method, 'stripe') !== false) {
            return (string) $wcSub->get_meta('_stripe_customer_id');
        }
        if (strpos($method, 'ppcp') === 0 || strpos($method, 'paypal') !== false) {
            return (string) $wcSub->get_meta('_paypal_payer_id');
        }

        return '';
    }

    /**
     * WooCommerce subscription notes (a subscription is order-like) → ActivityData.
     * Module type/id are set by the writer when the subscription is inserted.
     *
     * @return ActivityData[]
     */
    private function buildSubscriptionNotes($wcSubscriptionId, $fallbackDate)
    {
        if (!function_exists('wc_get_order_notes')) {
            return [];
        }

        $out = [];
        foreach (wc_get_order_notes(['order_id' => $wcSubscriptionId]) as $note) {
            $content = trim((string) $note->content);
            if ($content === '') {
                continue;
            }
            $date = ($note->date_created instanceof \WC_DateTime)
                ? $note->date_created->date('Y-m-d H:i:s')
                : (string) $note->date_created;

            $out[] = ActivityData::make([
                'title'     => $note->customer_note ? 'Note to customer' : 'Subscription note',
                'content'   => $content,
                'createdBy' => $note->added_by ?: 'Migrator',
                'createdAt' => $date ?: $fallbackDate,
                'updatedAt' => $date ?: $fallbackDate,
            ]);
        }

        return $out;
    }

    /**
     * Build a placeholder (canceled) subscription for a renewal order whose
     * parent subscription wasn't migrated, so the renewal transaction still
     * links to a subscription. Mirrors the EDD source's dummy subscription.
     */
    private function buildDummySubscription($order, $currency, $createdAt, $wcSubscriptionId = 0)
    {
        $item = null;
        foreach ($order->get_items() as $lineItem) {
            $item = $lineItem;
            break;
        }
        if (!$item) {
            return null;
        }

        $map       = $this->mapProduct((int) $item->get_product_id(), (int) $item->get_variation_id());
        $recurring = MigratorHelper::toCents($order->get_total(), $currency);

        return SubscriptionData::make([
            'productId'            => $map['post_id'],
            'itemName'             => $item->get_name(),
            'variationId'          => $map['variation_id'] ?: 0,
            'billingInterval'      => 'monthly',
            'recurringAmount'      => $recurring,
            'recurringTotal'       => $recurring,
            // Key the placeholder by the source subscription id so subsequent
            // renewals of the same (deleted) subscription dedupe onto this row.
            'vendorSubscriptionId' => $wcSubscriptionId ? (string) $wcSubscriptionId : '',
            'status'               => Status::SUBSCRIPTION_CANCELED,
            'collectionMethod'     => 'manual',
            'currentPaymentMethod' => MigratorHelper::gatewaySlug($order->get_payment_method()),
            'createdAt'            => $createdAt,
            'updatedAt'            => current_time('mysql'),
            'config'               => [
                'dummy'              => true,
                'currency'           => $currency,
                'reason'             => 'renewal_without_parent_subscription',
                'wc_subscription_id' => $wcSubscriptionId ?: null,
            ],
        ]);
    }

    /**
     * Resolve the migrated FluentCart subscription a WC renewal order belongs to,
     * preferring the real migrated subscription and falling back to a placeholder
     * already created for a deleted/trashed source subscription.
     *
     * @param int[] $sourceSubIds source WC subscription ids this renewal renews
     *                            (incl. ids whose subscription post is now gone)
     * @return array{id:int,parent_order_id:int}|null id = fct_subscriptions.id,
     *         parent_order_id = the subscription's first order id (reused 1:1 as
     *         the FluentCart order id), used to stamp the renewal's parent_id.
     */
    private function findRenewalSubscription($orderId, array $sourceSubIds = [])
    {
        $db = fluentCart('db');

        // 1. Real subscription: the renewal's parent order was migrated and
        //    created a subscription (matched by parent_order_id, reused 1:1).
        if (function_exists('wcs_get_subscriptions_for_renewal_order')) {
            foreach (wcs_get_subscriptions_for_renewal_order($orderId) as $wcSub) {
                $parentId = (int) $wcSub->get_parent_id();
                if (!$parentId) {
                    continue;
                }
                $fctSub = $db->table('fct_subscriptions')->where('parent_order_id', $parentId)->first();
                if ($fctSub) {
                    return ['id' => (int) $fctSub->id, 'parent_order_id' => $parentId];
                }
            }
        }

        // 2. A placeholder subscription already stands in for this (deleted)
        //    source subscription — link to it instead of spawning another, so
        //    each missing subscription yields exactly one dummy, not one per
        //    renewal order. Keyed on the source subscription id we stamped onto
        //    the placeholder's vendor_subscription_id.
        foreach ($sourceSubIds as $wcSubId) {
            $fctSub = $db->table('fct_subscriptions')
                ->where('vendor_subscription_id', (string) $wcSubId)
                ->first();
            if ($fctSub) {
                return ['id' => (int) $fctSub->id, 'parent_order_id' => (int) $fctSub->parent_order_id];
            }
        }

        return null;
    }

    /**
     * Source WooCommerce subscription ids a renewal order renews — read straight
     * from order meta so the link survives even when the subscription post was
     * deleted (WC's wcs_get_subscriptions_for_renewal_order can't rehydrate a
     * missing subscription). HPOS-safe via the order's own meta accessor.
     *
     * @return int[]
     */
    private function renewalSubscriptionIds($order)
    {
        $ids = [];
        foreach ((array) $order->get_meta('_subscription_renewal', false) as $meta) {
            $value = (is_object($meta) && isset($meta->value)) ? $meta->value : $meta;
            $id    = (int) $value;
            if ($id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
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
