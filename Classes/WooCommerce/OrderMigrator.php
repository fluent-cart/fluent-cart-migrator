<?php

namespace FluentCartMigrator\Classes\WooCommerce;

use FluentCart\App\Helpers\Status;

/**
 * Migrates WooCommerce orders into FluentCart.
 *
 * Reads through the WooCommerce CRUD API (wc_get_orders / WC_Order) so it is
 * agnostic to HPOS vs legacy post storage. Each WC order becomes:
 *   - one fct_orders row (id reused from the WC order id)
 *   - one fct_order_items row per line item (mapped to migrated products)
 *   - one charge fct_order_transactions row (+ one per WC refund)
 *   - one fct_order_addresses row per billing/shipping address
 *   - fct_applied_coupons rows for used coupons
 *   - fct_order_tax_rate rows for tax lines
 *   - fct_subscriptions rows when WooCommerce Subscriptions is active
 *   - one fct_order_operations row for completed orders
 *
 * Idempotent: re-running deletes the previously migrated order (and its child
 * records) before re-inserting, keyed on the reused WC order id.
 *
 * The per-order assembly mirrors the EDD source's PaymentMigrate: totals are
 * derived from the line items and reconciled against the WC order total so the
 * FluentCart accounting identity (total = subtotal + tax + shipping + fee
 * - discounts) always holds.
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

        $processed = 0;
        foreach ($ids as $orderId) {
            $result = $this->migrateOrder((int) $orderId);
            if (is_wp_error($result)) {
                $this->logFailed((int) $orderId, $result);
            }

            // Periodically reclaim memory within the page too, so a large
            // perPage (e.g. a CLI run) can't exhaust the memory limit before
            // the between-pages cleanup runs.
            if (++$processed % 25 === 0) {
                MigratorHelper::freeMemory();
            }
        }

        return [
            'processed' => count($ids),
            'has_more'  => $hasMore,
        ];
    }

    /**
     * @return int|\WP_Error created order id
     */
    public function migrateOrder($wcOrderId)
    {
        $order = wc_get_order($wcOrderId);
        if (!$order || !($order instanceof \WC_Order)) {
            return new \WP_Error('woo_no_order', 'WooCommerce order not found: ' . $wcOrderId);
        }

        $currency = $order->get_currency();

        $customer = MigratorHelper::findOrCreateCustomer($order);
        if (is_wp_error($customer)) {
            return $customer;
        }

        // --- Line items ---------------------------------------------------
        $createdAt = MigratorHelper::date($order->get_date_created());
        $items     = $this->buildItems($order, $currency, $createdAt);
        if (is_wp_error($items)) {
            return $items;
        }
        if (!$items) {
            return new \WP_Error('woo_empty_order', 'Order #' . $wcOrderId . ' has no migratable line items.');
        }

        // --- Totals (derived from items, reconciled to the WC total) ------
        $subtotal      = array_sum(array_column($items, 'subtotal'));
        $couponDiscount = array_sum(array_column($items, 'discount_total'));
        $taxTotal      = MigratorHelper::toCents($order->get_total_tax(), $currency);
        $shippingTotal = MigratorHelper::toCents($order->get_shipping_total(), $currency);
        $shippingTax   = MigratorHelper::toCents($order->get_shipping_tax(), $currency);
        $feeTotal      = $this->feeTotal($order, $currency);
        $orderTotal    = MigratorHelper::toCents($order->get_total(), $currency);

        // Reconcile: components - manualDiscount must equal the real WC total.
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
        $totalPaid    = $isPaid ? $orderTotal : 0;
        $totalRefund  = min(MigratorHelper::toCents($order->get_total_refunded(), $currency), $totalPaid);

        $orderType = $this->resolveOrderType($order);

        $completedAt = $order->get_date_completed() ? MigratorHelper::date($order->get_date_completed()) : null;
        $modifiedAt  = MigratorHelper::date($order->get_date_modified());

        $orderData = [
            'id'                    => $wcOrderId,
            'status'                => MigratorHelper::orderStatus($order->get_status()),
            'parent_id'             => (int) $order->get_parent_id(),
            'receipt_number'        => $wcOrderId,
            'invoice_no'            => (string) $order->get_order_number(),
            'fulfillment_type'      => $this->orderFulfillmentType($items),
            'type'                  => $orderType,
            'mode'                  => Status::ORDER_MODE_LIVE,
            'shipping_status'       => '',
            'customer_id'           => $customer->id,
            'payment_method'        => $order->get_payment_method() ?: 'offline',
            'payment_status'        => $paymentStatus,
            'payment_method_title'  => $order->get_payment_method_title() ?: ucfirst($order->get_payment_method() ?: 'Offline'),
            'currency'              => $currency,
            'subtotal'              => $subtotal,
            'discount_tax'          => 0,
            'manual_discount_total' => $manualDiscount,
            'coupon_discount_total' => $couponDiscount,
            'shipping_tax'          => $shippingTax,
            'shipping_total'        => $shippingTotal,
            'fee_total'             => $feeTotal,
            'tax_total'             => $taxTotal,
            'tax_behavior'          => $taxTotal > 0 ? 1 : 0,
            'total_amount'          => $orderTotal,
            'total_paid'            => $totalPaid,
            'total_refund'          => $totalRefund,
            'rate'                  => 1,
            'note'                  => (string) $order->get_customer_note(),
            'ip_address'            => (string) $order->get_customer_ip_address(),
            'completed_at'          => $completedAt,
            'refunded_at'           => $totalRefund > 0 ? $modifiedAt : null,
            'uuid'                  => md5($wcOrderId . '_' . $order->get_order_key() . '_' . wp_generate_uuid4()),
            'config'                => json_encode(['migrated_from' => 'woocommerce']),
            'created_at'            => $createdAt,
            'updated_at'            => $modifiedAt,
        ];

        // --- Transaction --------------------------------------------------
        $transactionStatus = $totalPaid > 0 ? Status::TRANSACTION_SUCCEEDED : Status::TRANSACTION_PENDING;
        $transactionData = [
            'order_id'            => $wcOrderId,
            'order_type'          => $orderType,
            'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
            'subscription_id'     => null,
            'card_last_4'         => '',
            'card_brand'          => '',
            'vendor_charge_id'    => (string) $order->get_transaction_id(),
            'payment_method'      => $order->get_payment_method() ?: 'offline',
            'payment_mode'        => Status::ORDER_MODE_LIVE,
            'payment_method_type' => '',
            'status'              => $transactionStatus,
            'currency'            => $currency,
            'total'               => $totalPaid,
            'rate'                => 1,
            'uuid'                => md5('payment_' . $wcOrderId . '_' . $order->get_order_key() . '_' . wp_generate_uuid4()),
            'meta'                => json_encode(['fallback' => 1]),
            'created_at'          => $createdAt,
            'updated_at'          => current_time('mysql'),
        ];

        // --- Validate the accounting identity before writing -------------
        $validation = $this->validate($orderData, $items, $transactionData);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // --- Idempotency: replace any prior migration of this order -------
        $exist = fluentCart('db')->table('fct_orders')->where('id', $wcOrderId)->first();
        if ($exist) {
            MigratorHelper::deleteOrderById($wcOrderId);
        }

        // --- Insert -------------------------------------------------------
        fluentCart('db')->table('fct_orders')->insert($orderData);

        foreach ($items as $item) {
            unset($item['_meta']);
            $item['order_id'] = $wcOrderId;
            fluentCart('db')->table('fct_order_items')->insert($item);
        }

        // Subscriptions (parent orders create them; renewals link to them).
        $subscriptionId = null;
        if ($orderType === Status::ORDER_TYPE_SUBSCRIPTION) {
            $subscriptionId = $this->createSubscriptions($order, $wcOrderId, $customer->id, $currency, $createdAt);
        } elseif ($orderType === Status::ORDER_TYPE_RENEWAL) {
            $subscriptionId = $this->findRenewalSubscriptionId($wcOrderId);
        }

        $transactionData['subscription_id'] = $subscriptionId;
        fluentCart('db')->table('fct_order_transactions')->insert($transactionData);

        // Refund transactions.
        foreach ($order->get_refunds() as $refund) {
            $amount = MigratorHelper::toCents($refund->get_amount(), $currency);
            if (!$amount) {
                continue;
            }
            fluentCart('db')->table('fct_order_transactions')->insert([
                'order_id'            => $wcOrderId,
                'order_type'          => $orderType,
                'transaction_type'    => Status::TRANSACTION_TYPE_REFUND,
                'subscription_id'     => $subscriptionId,
                'card_last_4'         => '',
                'card_brand'          => '',
                'vendor_charge_id'    => '',
                'payment_method'      => $order->get_payment_method() ?: 'offline',
                'payment_mode'        => Status::ORDER_MODE_LIVE,
                'payment_method_type' => '',
                'status'              => Status::TRANSACTION_REFUNDED,
                'currency'            => $currency,
                'total'               => $amount,
                'rate'                => 1,
                'uuid'                => md5('refund_' . $wcOrderId . '_' . $refund->get_id() . '_' . wp_generate_uuid4()),
                'meta'                => json_encode([]),
                'created_at'          => MigratorHelper::date($refund->get_date_created()),
                'updated_at'          => current_time('mysql'),
            ]);
        }

        $this->insertAddresses($order, $wcOrderId, $createdAt);
        $this->insertCoupons($order, $wcOrderId, $currency, $createdAt);
        $this->insertTaxRates($order, $wcOrderId, $currency, $createdAt);
        $this->insertOperations($orderData, $wcOrderId, $createdAt, $modifiedAt);

        return $wcOrderId;
    }

    /* -----------------------------------------------------------------
     | Line items
     * ----------------------------------------------------------------- */

    /**
     * @return array<int,array>|\WP_Error
     */
    private function buildItems($order, $currency, $createdAt)
    {
        $items     = [];
        $cartIndex = 0;

        foreach ($order->get_items() as $lineItem) {
            $cartIndex++;
            /** @var \WC_Order_Item_Product $lineItem */
            $quantity = max(1, (int) $lineItem->get_quantity());

            $subtotal      = MigratorHelper::toCents($lineItem->get_subtotal(), $currency);      // pre-discount
            $lineTotal     = MigratorHelper::toCents($lineItem->get_total(), $currency);          // post-discount
            $discountTotal = max(0, $subtotal - $lineTotal);
            $taxAmount     = MigratorHelper::toCents($lineItem->get_total_tax(), $currency);
            $unitPrice     = $quantity > 0 ? intdiv($subtotal, $quantity) : $subtotal;

            $map = $this->mapProduct((int) $lineItem->get_product_id(), (int) $lineItem->get_variation_id());

            $product         = $lineItem->get_product();
            $fulfillment     = $product ? MigratorHelper::fulfillmentType($product) : 'physical';
            $isSubscription  = $product && $this->isSubscriptionProduct($product);

            $items[] = [
                'post_id'          => $map['post_id'],
                'fulfillment_type' => $fulfillment,
                'payment_type'     => $isSubscription ? 'subscription' : 'onetime',
                'post_title'       => $map['post_title'] ?: $lineItem->get_name(),
                'title'            => $map['title'],
                'object_id'        => $map['variation_id'],
                'quantity'         => $quantity,
                'unit_price'       => $unitPrice,
                'cost'             => 0,
                'subtotal'         => $subtotal,
                'tax_amount'       => $taxAmount,
                'shipping_charge'  => 0,
                'discount_total'   => $discountTotal,
                'line_total'       => $subtotal - $discountTotal,
                'refund_total'     => 0,
                'rate'             => 1,
                'other_info'       => json_encode(['payment_type' => $isSubscription ? 'subscription' : 'onetime']),
                'line_meta'        => json_encode([]),
                'cart_index'       => $cartIndex,
                'created_at'       => $createdAt,
                'updated_at'       => $createdAt,
            ];
        }

        return $items;
    }

    /**
     * Map a WC product/variation to the migrated FluentCart product post and
     * variation. Falls back to no mapping (post_id 0) when the product wasn't
     * migrated, so the order still carries the historical line.
     *
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

    private function orderFulfillmentType($items)
    {
        $types = array_values(array_unique(array_column($items, 'fulfillment_type')));
        if (count($types) === 1) {
            return $types[0];
        }

        return 'physical';
    }

    /* -----------------------------------------------------------------
     | Validation (mirrors the EDD accounting identity)
     * ----------------------------------------------------------------- */

    private function validate($orderData, $items, $transactionData)
    {
        if ($orderData['subtotal'] !== array_sum(array_column($items, 'subtotal'))) {
            return new \WP_Error('woo_validation', 'Order #' . $orderData['id'] . ' subtotal mismatch.');
        }

        if ($orderData['coupon_discount_total'] !== array_sum(array_column($items, 'discount_total'))) {
            return new \WP_Error('woo_validation', 'Order #' . $orderData['id'] . ' coupon discount mismatch.');
        }

        $expectedTotal = $orderData['subtotal']
            + $orderData['tax_total']
            + $orderData['shipping_total']
            + $orderData['fee_total']
            - $orderData['coupon_discount_total']
            - $orderData['manual_discount_total'];

        if ($orderData['total_amount'] !== $expectedTotal) {
            return new \WP_Error('woo_validation', 'Order #' . $orderData['id'] . ' total mismatch (' . $orderData['total_amount'] . ' vs ' . $expectedTotal . ').');
        }

        if ($orderData['total_paid'] !== $transactionData['total']) {
            return new \WP_Error('woo_validation', 'Order #' . $orderData['id'] . ' paid/transaction mismatch.');
        }

        if ($orderData['total_refund'] > $orderData['total_paid']) {
            return new \WP_Error('woo_validation', 'Order #' . $orderData['id'] . ' refund exceeds paid.');
        }

        return true;
    }

    /* -----------------------------------------------------------------
     | Addresses, coupons, taxes, operations
     * ----------------------------------------------------------------- */

    private function insertAddresses($order, $orderId, $createdAt)
    {
        $billing = [
            'order_id'   => $orderId,
            'type'       => 'billing',
            'name'       => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'address_1'  => $order->get_billing_address_1(),
            'address_2'  => $order->get_billing_address_2(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
            'meta'       => json_encode(array_filter([
                'company_name' => $order->get_billing_company(),
                'phone'        => $order->get_billing_phone(),
                'email'        => $order->get_billing_email(),
            ])),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];

        if (array_filter([$billing['address_1'], $billing['city'], $billing['country'], $billing['name']])) {
            fluentCart('db')->table('fct_order_addresses')->insert($billing);
        }

        $shipping = [
            'order_id'   => $orderId,
            'type'       => 'shipping',
            'name'       => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
            'address_1'  => $order->get_shipping_address_1(),
            'address_2'  => $order->get_shipping_address_2(),
            'city'       => $order->get_shipping_city(),
            'state'      => $order->get_shipping_state(),
            'postcode'   => $order->get_shipping_postcode(),
            'country'    => $order->get_shipping_country(),
            'meta'       => json_encode(array_filter([
                'company_name' => $order->get_shipping_company(),
            ])),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];

        if (array_filter([$shipping['address_1'], $shipping['city'], $shipping['country']])) {
            fluentCart('db')->table('fct_order_addresses')->insert($shipping);
        }
    }

    private function insertCoupons($order, $orderId, $currency, $createdAt)
    {
        foreach ($order->get_items('coupon') as $coupon) {
            /** @var \WC_Order_Item_Coupon $coupon */
            $code = $coupon->get_code();
            if (!$code) {
                continue;
            }

            fluentCart('db')->table('fct_applied_coupons')->insert([
                'order_id'   => $orderId,
                'code'       => $code,
                'amount'     => MigratorHelper::toCents($coupon->get_discount(), $currency),
                'coupon_id'  => MigratorHelper::couponIdByCode($code),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    private function insertTaxRates($order, $orderId, $currency, $createdAt)
    {
        foreach ($order->get_items('tax') as $tax) {
            /** @var \WC_Order_Item_Tax $tax */
            $taxAmount = MigratorHelper::toCents($tax->get_tax_total(), $currency)
                + MigratorHelper::toCents($tax->get_shipping_tax_total(), $currency);

            if (!$taxAmount) {
                continue;
            }

            fluentCart('db')->table('fct_order_tax_rate')->insert([
                'order_id'     => $orderId,
                'tax_rate_id'  => null,
                'shipping_tax' => MigratorHelper::toCents($tax->get_shipping_tax_total(), $currency),
                'order_tax'    => MigratorHelper::toCents($tax->get_tax_total(), $currency),
                'total_tax'    => $taxAmount,
                'meta'         => json_encode([
                    'inclusive'     => false,
                    'rates'         => [[
                        'label'      => $tax->get_label(),
                        'tax_amount' => $taxAmount,
                        'rate_id'    => (int) $tax->get_rate_id(),
                    ]],
                    'tax_country'   => $order->get_billing_country(),
                    'migrated_from' => 'woocommerce',
                ]),
                'created_at'   => $createdAt,
                'updated_at'   => $createdAt,
            ]);
        }
    }

    private function insertOperations($orderData, $orderId, $createdAt, $modifiedAt)
    {
        if (in_array($orderData['status'], [Status::ORDER_FAILED, Status::ORDER_ON_HOLD], true)) {
            return;
        }

        fluentCart('db')->table('fct_order_operations')->insert([
            'order_id'       => $orderId,
            'created_via'    => 'migration',
            'emails_sent'    => 1,
            'sales_recorded' => 1,
            'utm_campaign'   => '',
            'utm_medium'     => '',
            'utm_source'     => '',
            'utm_content'    => '',
            'utm_term'       => '',
            'utm_id'         => '',
            'cart_hash'      => $orderData['uuid'],
            'refer_url'      => '',
            'meta'           => json_encode([]),
            'created_at'     => $createdAt,
            'updated_at'     => $modifiedAt,
        ]);
    }

    /* -----------------------------------------------------------------
     | Subscriptions (WooCommerce Subscriptions)
     * ----------------------------------------------------------------- */

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

    /**
     * @return int|null first created subscription id (for the transaction link)
     */
    private function createSubscriptions($order, $orderId, $customerId, $currency, $createdAt)
    {
        if (!function_exists('wcs_get_subscriptions_for_order')) {
            return null;
        }

        $subscriptions = wcs_get_subscriptions_for_order($orderId, ['order_type' => ['parent']]);
        $firstId       = null;

        foreach ($subscriptions as $wcSub) {
            /** @var \WC_Subscription $wcSub */
            $product   = null;
            $variation = 0;
            $productId = 0;
            $itemName  = '';
            foreach ($wcSub->get_items() as $subItem) {
                $product   = $subItem->get_product();
                $productId = (int) $subItem->get_product_id();
                $variation = (int) $subItem->get_variation_id();
                $itemName  = $subItem->get_name();
                break;
            }

            $map        = $this->mapProduct($productId, $variation);
            $recurring  = MigratorHelper::toCents($wcSub->get_total(), $currency);
            $signupFee  = method_exists($wcSub, 'get_sign_up_fee')
                ? MigratorHelper::toCents($wcSub->get_sign_up_fee(), $currency)
                : 0;

            $row = [
                'uuid'                   => md5('subscription_' . $orderId . '_' . $wcSub->get_id() . '_' . wp_generate_uuid4()),
                'customer_id'            => $customerId,
                'parent_order_id'        => $orderId,
                'product_id'             => $map['post_id'],
                'item_name'              => $itemName,
                'quantity'               => 1,
                'variation_id'           => $map['variation_id'] ?: 0,
                'billing_interval'       => MigratorHelper::billingInterval($wcSub->get_billing_period()),
                'signup_fee'             => max(0, $signupFee),
                'initial_tax_total'      => 0,
                'recurring_amount'       => $recurring,
                'recurring_tax_total'    => 0,
                'recurring_total'        => $recurring,
                'bill_times'             => 0,
                'bill_count'             => 0,
                'expire_at'              => $this->nullableDate($wcSub->get_date('end')),
                'trial_ends_at'          => $this->nullableDate($wcSub->get_date('trial_end')),
                'canceled_at'            => $wcSub->get_status() === 'cancelled' ? $this->nullableDate($wcSub->get_date('cancelled')) : null,
                'restored_at'            => null,
                'collection_method'      => 'automatic',
                'next_billing_date'      => $this->nullableDate($wcSub->get_date('next_payment')),
                'trial_days'             => 0,
                'vendor_customer_id'     => null,
                'vendor_plan_id'         => '',
                'vendor_subscription_id' => (string) $wcSub->get_id(),
                'status'                 => MigratorHelper::subscriptionStatus($wcSub->get_status()),
                'original_plan'          => '',
                'vendor_response'        => '',
                'current_payment_method' => $order->get_payment_method() ?: 'offline',
                'created_at'             => $createdAt,
                'updated_at'             => current_time('mysql'),
                'config'                 => json_encode([
                    'wc_subscription_id' => $wcSub->get_id(),
                    'billing_interval'   => (int) $wcSub->get_billing_interval(),
                    'currency'           => $currency,
                ]),
            ];

            $subId = fluentCart('db')->table('fct_subscriptions')->insertGetId($row);
            if (!$firstId) {
                $firstId = $subId;
            }
        }

        return $firstId;
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

            $fctSub = fluentCart('db')->table('fct_subscriptions')
                ->where('parent_order_id', $parentId)
                ->first();

            if ($fctSub) {
                return (int) $fctSub->id;
            }
        }

        return null;
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

    /* -----------------------------------------------------------------
     | Status filtering & failure logging
     * ----------------------------------------------------------------- */

    private function migratableStatuses()
    {
        $statuses = array_keys(wc_get_order_statuses()); // ['wc-pending', ...]

        // Drafts/abandoned carts aren't real orders.
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
