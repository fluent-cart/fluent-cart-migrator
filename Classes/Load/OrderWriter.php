<?php

namespace FluentCartMigrator\Classes\Load;

use FluentCartMigrator\Classes\Dto\OrderData;
use FluentCart\App\Helpers\Status;
use FluentCartMigrator\Classes\Support\OrderDeleter;
use FluentCartMigrator\Classes\Support\OrderValidator;

/**
 * The shared "Load" for orders: takes a normalized OrderData (already
 * reconciled, cents-based, status-mapped by the source) and writes every
 * FluentCart row for it. Source-agnostic — the single owner of the FluentCart
 * order schema, idempotency, and uuid/receipt generation.
 *
 * Uses raw table inserts (never Eloquent ::create) to reuse the source order id
 * as fct_orders.id, keep historical timestamps, set uuid/receipt/invoice
 * explicitly, and bypass model boot hooks (which would auto-generate those and
 * could fire emails/webhooks on the `created` event).
 */
class OrderWriter
{
    /**
     * @return array{order_id:int,transaction_id:?int,subscription_id:?int,refund_id:?int}|\WP_Error
     */
    public function write(OrderData $order)
    {
        $valid = OrderValidator::validate($order);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $db      = fluentCart('db');
        $orderId = (int) $order->id;

        // Idempotency: replace any prior migration of this order.
        if ($db->table('fct_orders')->where('id', $orderId)->first()) {
            OrderDeleter::deleteById($orderId);
        }

        $orderRow         = $order->toArray();
        $orderRow['uuid'] = md5($orderId . '_' . wp_generate_uuid4());
        $db->table('fct_orders')->insert($orderRow);

        $this->writeItems($order, $orderId);

        $subscriptionId = $this->writeSubscriptions($order, $orderId);
        if (!$subscriptionId) {
            $subscriptionId = $order->linkedSubscriptionId ? (int) $order->linkedSubscriptionId : null;
        }

        $transactionId = $this->writeCharges($order, $orderId, $subscriptionId);
        $refundId      = $this->writeRefunds($order, $orderId, $subscriptionId);

        $this->writeAddresses($order, $orderId);
        $this->writeCoupons($order, $orderId);
        $this->writeTaxRates($order, $orderId);
        $this->writeOperations($order, $orderId, $orderRow['uuid']);
        $this->writeLicenses($order, $orderId, $subscriptionId);
        $this->writeActivities($order, $orderId);

        return [
            'order_id'        => $orderId,
            'transaction_id'  => $transactionId,
            'subscription_id' => $subscriptionId,
            'refund_id'       => $refundId,
        ];
    }

    protected function writeItems(OrderData $order, $orderId)
    {
        $db = fluentCart('db');

        foreach ($order->items as $item) {
            $row             = $item->toArray();
            $row['order_id'] = $orderId;
            $createdItemId   = $db->table('fct_order_items')->insertGetId($row);

            if (empty($item->bundleItems)) {
                continue;
            }

            $childIds = [];
            foreach ($item->bundleItems as $child) {
                $childRow              = $child->toArray();
                $childRow['order_id']  = $orderId;
                $childMeta             = json_decode($childRow['line_meta'], true) ?: [];
                $childMeta['bundle_parent_item_id'] = $createdItemId;
                $childRow['line_meta'] = json_encode($childMeta);
                $childIds[]            = $db->table('fct_order_items')->insertGetId($childRow);
            }

            $parentMeta = json_decode($row['line_meta'], true) ?: [];
            $parentMeta['bundle_item_ids'] = $childIds;
            $db->table('fct_order_items')->where('id', $createdItemId)
                ->update(['line_meta' => json_encode($parentMeta)]);
        }
    }

    /**
     * @return int|null first created subscription id (for linking transactions)
     */
    protected function writeSubscriptions(OrderData $order, $orderId)
    {
        $db      = fluentCart('db');
        $firstId = null;

        foreach ($order->subscriptions as $sub) {
            $row                    = $sub->toArray();
            $row['uuid']            = md5('subscription_' . $orderId . '_' . wp_generate_uuid4());
            $row['parent_order_id'] = $orderId;
            $row['customer_id']     = (int) $order->customerId;

            $id = $db->table('fct_subscriptions')->insertGetId($row);

            // Subscription meta → fct_subscription_meta. Carries the reusable
            // `active_payment_method` token that a `system` subscription's
            // renewal charge reads at fire time. Array/object values are JSON
            // encoded to match SubscriptionMeta's meta_value cast.
            if (!empty($sub->meta)) {
                $now = current_time('mysql');
                foreach ($sub->meta as $metaKey => $metaValue) {
                    if (is_array($metaValue) || is_object($metaValue)) {
                        $metaValue = json_encode($metaValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $db->table('fct_subscription_meta')->insert([
                        'subscription_id' => $id,
                        'meta_key'        => $metaKey,
                        'meta_value'      => $metaValue,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);
                }
            }

            // Subscription notes → fct_activity (module = Subscription).
            if (!empty($sub->activities)) {
                foreach ($sub->activities as $activity) {
                    $arow                = $activity->toArray();
                    $arow['module_id']   = $id;
                    $arow['module_type'] = 'FluentCart\App\Models\Subscription';
                    $arow['module_name'] = 'Subscription';
                    $db->table('fct_activity')->insert($arow);
                }
            }

            if (!$firstId) {
                $firstId = $id;
            }
        }

        return $firstId;
    }

    /**
     * @return int|null first charge transaction id
     */
    protected function writeCharges(OrderData $order, $orderId, $subscriptionId)
    {
        $db    = fluentCart('db');
        $first = null;

        foreach ($order->transactions as $txn) {
            $row                    = $txn->toArray();
            $row['order_id']        = $orderId;
            $row['subscription_id'] = $subscriptionId;
            $row['uuid']            = md5('payment_' . $orderId . '_' . wp_generate_uuid4());

            $id = $db->table('fct_order_transactions')->insertGetId($row);
            if (!$first) {
                $first = $id;
            }
        }

        return $first;
    }

    /**
     * @return int|null last refund transaction id
     */
    protected function writeRefunds(OrderData $order, $orderId, $subscriptionId)
    {
        $db   = fluentCart('db');
        $last = null;

        foreach ($order->refunds as $refund) {
            $row                    = $refund->toArray();
            $row['order_id']        = $orderId;
            $row['subscription_id'] = $subscriptionId;
            $row['uuid']            = md5('refund_' . $orderId . '_' . wp_generate_uuid4());

            $last = $db->table('fct_order_transactions')->insertGetId($row);
        }

        return $last;
    }

    protected function writeAddresses(OrderData $order, $orderId)
    {
        $db = fluentCart('db');
        foreach ($order->addresses as $address) {
            if (!$address->isMeaningful()) {
                continue;
            }
            $row             = $address->toArray();
            $row['order_id'] = $orderId;
            $db->table('fct_order_addresses')->insert($row);
        }
    }

    protected function writeCoupons(OrderData $order, $orderId)
    {
        $db = fluentCart('db');
        foreach ($order->coupons as $coupon) {
            if (!$coupon->code) {
                continue;
            }
            $row             = $coupon->toArray();
            $row['order_id'] = $orderId;
            $db->table('fct_applied_coupons')->insert($row);
        }
    }

    protected function writeTaxRates(OrderData $order, $orderId)
    {
        $db = fluentCart('db');
        foreach ($order->taxRates as $tax) {
            if (!(int) $tax->totalTax) {
                continue;
            }
            $row             = $tax->toArray();
            $row['order_id'] = $orderId;
            $db->table('fct_order_tax_rate')->insert($row);
        }
    }

    protected function writeOperations(OrderData $order, $orderId, $orderUuid)
    {
        if (in_array($order->status, [Status::ORDER_FAILED, Status::ORDER_ON_HOLD], true)) {
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
            'cart_hash'      => $orderUuid,
            'refer_url'      => '',
            'meta'           => json_encode([]),
            'created_at'     => $order->createdAt,
            'updated_at'     => $order->updatedAt ?: $order->createdAt,
        ]);
    }

    protected function writeLicenses(OrderData $order, $orderId, $subscriptionId)
    {
        if (empty($order->licenses)) {
            return;
        }

        $db = fluentCart('db');
        foreach ($order->licenses as $license) {
            $row                    = $license->toArray();
            $row['order_id']        = $orderId;
            $row['customer_id']     = (int) $order->customerId;
            $row['subscription_id'] = $subscriptionId;

            $licenseId = $db->table('fct_licenses')->insertGetId($row);

            foreach ($license->activations as $activation) {
                $activation['license_id'] = $licenseId;
                $db->table('fct_license_activations')->insert($activation);
            }
        }
    }

    protected function writeActivities(OrderData $order, $orderId)
    {
        if (empty($order->activities)) {
            return;
        }

        $db = fluentCart('db');
        foreach ($order->activities as $activity) {
            $row = $activity->toArray();
            if (empty($row['module_id'])) {
                $row['module_id'] = $orderId;
            }
            $db->table('fct_activity')->insert($row);
        }
    }
}
