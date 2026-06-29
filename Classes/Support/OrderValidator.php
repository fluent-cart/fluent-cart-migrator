<?php

namespace FluentCartMigrator\Classes\Support;

use FluentCartMigrator\Classes\Dto\OrderData;

/**
 * Validates the FluentCart accounting identity on a normalized order before it
 * is written. The source Transform is responsible for reconciliation; this is
 * the boundary guard that catches a malformed DTO so a bad order is logged and
 * skipped rather than silently corrupting the books.
 */
class OrderValidator
{
    /**
     * @return true|\WP_Error
     */
    public static function validate(OrderData $order)
    {
        $itemSubtotal = array_sum(array_map(function ($i) { return (int) $i->subtotal; }, $order->items));
        $itemDiscount = array_sum(array_map(function ($i) { return (int) $i->discountTotal; }, $order->items));

        if ((int) $order->subtotal !== $itemSubtotal) {
            return self::fail($order, 'subtotal mismatch (' . $order->subtotal . ' vs items ' . $itemSubtotal . ')');
        }

        if ((int) $order->couponDiscountTotal !== $itemDiscount) {
            return self::fail($order, 'coupon discount mismatch (' . $order->couponDiscountTotal . ' vs items ' . $itemDiscount . ')');
        }

        $expectedTotal = (int) $order->subtotal
            + (int) $order->taxTotal
            + (int) $order->shippingTax
            + (int) $order->shippingTotal
            + (int) $order->feeTotal
            - (int) $order->couponDiscountTotal
            - (int) $order->manualDiscountTotal;

        if ((int) $order->totalAmount !== $expectedTotal) {
            return self::fail($order, 'total mismatch (' . $order->totalAmount . ' vs computed ' . $expectedTotal . ')');
        }

        $chargeTotal = array_sum(array_map(function ($t) { return (int) $t->total; }, $order->transactions));
        if ((int) $order->totalPaid !== $chargeTotal) {
            return self::fail($order, 'paid/transaction mismatch (' . $order->totalPaid . ' vs charges ' . $chargeTotal . ')');
        }

        if ((int) $order->totalRefund > (int) $order->totalPaid) {
            return self::fail($order, 'refund exceeds paid (' . $order->totalRefund . ' > ' . $order->totalPaid . ')');
        }

        return true;
    }

    protected static function fail(OrderData $order, $message)
    {
        return new \WP_Error('order_validation', 'Order #' . $order->id . ' ' . $message);
    }
}
