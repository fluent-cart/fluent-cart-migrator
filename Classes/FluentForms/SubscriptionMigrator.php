<?php

namespace FluentCartMigrator\Classes\FluentForms;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Services\OrderService;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;
use FluentCartMigrator\Classes\Dto\ActivityData;
use FluentCartMigrator\Classes\Dto\AddressData;
use FluentCartMigrator\Classes\Dto\CustomerData;
use FluentCartMigrator\Classes\Dto\OrderData;
use FluentCartMigrator\Classes\Dto\OrderItemData;
use FluentCartMigrator\Classes\Dto\ProductData;
use FluentCartMigrator\Classes\Dto\ProductVariationData;
use FluentCartMigrator\Classes\Dto\SubscriptionData;
use FluentCartMigrator\Classes\Dto\TransactionData;
use FluentCartMigrator\Classes\Load\CustomerWriter;
use FluentCartMigrator\Classes\Load\OrderWriter;
use FluentCartMigrator\Classes\Load\ProductWriter;
use FluentCartMigrator\Classes\Support\OrderValidator;
use FluentCartMigrator\Classes\Support\Sku;
use FluentCartMigrator\Classes\WooCommerce\MigratorHelper;

/**
 * Moves Fluent Forms Stripe subscriptions into FluentCart: one product per
 * form payment field, a parent order for the first paid charge, a renewal
 * order per later charge, and the subscription keyed by the same Stripe
 * subscription id so FluentCart's Stripe resync and webhooks pick it up.
 *
 * Each subscription is written in one DB transaction. In dry-run mode nothing
 * is written; the same orders are built and described instead.
 */
class SubscriptionMigrator
{
    const PRODUCT_SOURCE_KEY = '_fluentform_migrated_from';
    const PRODUCT_MAP_KEY    = '_fluentform_variation_map';

    private $dryRun;

    private $products = [];

    const CREATED_CUSTOMERS_OPTION = 'fct_migrator_ff_created_customers';

    /** @var array<int,true> customers the migrator created, across runs; only these get their stats recounted */
    private $createdCustomers = [];

    public function __construct($dryRun = false)
    {
        $this->dryRun = (bool) $dryRun;

        $ids = (array) get_option(self::CREATED_CUSTOMERS_OPTION, []);
        $this->createdCustomers = array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * @return array{status:string,message:string,details:string[],subscription_id?:int,code?:string}
     */
    public function migrate($ffSub)
    {
        $db = fluentCart('db');

        $charges = $this->paidCharges($ffSub->id);
        if (!$charges) {
            return $this->result('skipped', 'no paid charge', null, [], 'ff_never_paid');
        }

        if ((string) $ffSub->vendor_subscription_id !== '') {
            $existing = $db->table('fct_subscriptions')
                ->where('vendor_subscription_id', $ffSub->vendor_subscription_id)
                ->first();
            if ($existing) {
                return $this->result('skipped', 'already in FluentCart as subscription #' . $existing->id, null, [], 'ff_already_migrated');
            }
        }

        $method = (string) $charges[0]->payment_method;
        if ($method === 'paypal') {
            return $this->result('skipped', 'PayPal — not supported yet', null, [], 'ff_paypal');
        }
        if ($method !== 'stripe') {
            return $this->result('skipped', 'not a Stripe subscription (' . $method . ')', null, [], 'ff_not_stripe');
        }

        $submission = $db->table('fluentform_submissions')->find($ffSub->submission_id);
        if (!$submission) {
            return $this->result('failed', 'submission #' . $ffSub->submission_id . ' not found', null, [], 'ff_no_submission');
        }

        if ($this->dryRun) {
            return $this->run($ffSub, $submission, $charges);
        }

        $source           = $this->productSource($ffSub);
        $hadProduct       = isset($this->products[$source]);
        $createdCustomers = $this->createdCustomers;

        $db->beginTransaction();
        try {
            $result = $this->run($ffSub, $submission, $charges);
        } catch (\Throwable $e) {
            $result = $this->result('failed', $e->getMessage());
        }

        if ($result['status'] === 'migrated') {
            $db->commit();
            if ($this->createdCustomers !== $createdCustomers) {
                update_option(self::CREATED_CUSTOMERS_OPTION, array_keys($this->createdCustomers), false);
            }
            return $result;
        }

        $db->rollBack();

        // The rolled-back rows may still sit in the in-memory caches.
        CustomerWriter::resetCache();
        $this->createdCustomers = $createdCustomers;
        if (isset($this->products[$source])) {
            clean_post_cache($this->products[$source]);
            if (!$hadProduct) {
                unset($this->products[$source]);
            }
        }

        return $result;
    }

    private function run($ffSub, $submission, array $charges)
    {
        $details   = [];
        $addresses = $this->addresses($submission, $charges[0]);

        $customer = $this->customer($submission, $charges[0], $addresses, $details);
        if (is_wp_error($customer)) {
            return $this->result('failed', $customer->get_error_message(), null, $details);
        }

        $product = $this->dryRun ? $this->previewProduct($ffSub, $details) : $this->resolveProduct($ffSub);
        if (is_wp_error($product)) {
            return $this->result('failed', $product->get_error_message(), null, $details);
        }

        $refunds = $this->refundSplit($ffSub, $charges, $details);

        $first  = array_shift($charges);
        $parent = $this->buildOrder($ffSub, $first, $product, $customer['id'], Status::ORDER_TYPE_SUBSCRIPTION, $refunds, $addresses, $details);
        $parent->subscriptions = [$this->buildSubscription($ffSub, $first, $charges, $product)];

        $renewals = [];
        foreach ($charges as $charge) {
            $renewals[] = $this->buildOrder($ffSub, $charge, $product, $customer['id'], Status::ORDER_TYPE_RENEWAL, $refunds, $addresses, $details);
        }

        $activities = count($parent->subscriptions[0]->activities) - 1;
        $details[]  = sprintf('subscription: %s, %d Fluent Forms log(s) as activities', $parent->subscriptions[0]->status, $activities);

        if ($this->dryRun) {
            foreach (array_merge([$parent], $renewals) as $order) {
                $valid = OrderValidator::validate($order);
                if (is_wp_error($valid)) {
                    return $this->result('failed', $valid->get_error_message(), null, $details);
                }
            }

            return $this->result('migrated', sprintf('would create a FluentCart subscription with %d order(s)', count($renewals) + 1), null, $details);
        }

        $written = $this->writeOrder($parent);
        if (is_wp_error($written)) {
            return $this->result('failed', $written->get_error_message(), null, $details);
        }

        $subscriptionId = (int) $written['subscription_id'];
        fluentCart('db')->table('fct_subscriptions')->where('id', $subscriptionId)->update(['currency' => $parent->currency]);

        foreach ($renewals as $renewal) {
            $renewal->parentId             = $written['order_id'];
            $renewal->linkedSubscriptionId = $subscriptionId;

            $result = $this->writeOrder($renewal);
            if (is_wp_error($result)) {
                return $this->result('failed', 'renewal order: ' . $result->get_error_message(), null, $details);
            }
        }

        if (isset($this->createdCustomers[$customer['id']])) {
            $model = Customer::find($customer['id']);
            if ($model) {
                $model->recountStat();
            }
        }

        return $this->result('migrated', sprintf('FluentCart subscription #%d, %d order(s)', $subscriptionId, count($renewals) + 1), $subscriptionId, $details);
    }

    /**
     * Writes the order, then the links OrderWriter does not make: the signup
     * fee line to its plan line, and each refund to the charge it refunds.
     */
    private function writeOrder(OrderData $order)
    {
        // Take the id from auto-increment so a live checkout can never hold it;
        // OrderWriter replaces this placeholder row with the real order.
        $order->id = (int) fluentCart('db')->table('fct_orders')->insertGetId([
            'currency'             => $order->currency,
            'ip_address'           => '',
            'note'                 => '',
            'payment_method'       => '',
            'payment_method_title' => '',
            'uuid'                 => wp_generate_uuid4(),
        ]);

        $order->receiptNumber = OrderService::getNextReceiptNumber();
        $order->invoiceNo     = OrderService::getInvoicePrefix() . $order->receiptNumber;

        $written = (new OrderWriter())->write($order);
        if (is_wp_error($written)) {
            return $written;
        }

        $db    = fluentCart('db');
        $items = $db->table('fct_order_items')->where('order_id', $written['order_id'])->orderBy('id', 'ASC')->get();
        $plan  = $items->firstWhere('payment_type', 'subscription');
        $fee   = $items->firstWhere('payment_type', 'signup_fee');

        if ($plan && $fee) {
            $feeMeta                   = (array) json_decode((string) $fee->line_meta, true);
            $feeMeta['parent_item_id'] = (int) $plan->id;
            $db->table('fct_order_items')->where('id', $fee->id)->update(['line_meta' => json_encode($feeMeta)]);

            $planMeta                        = (array) json_decode((string) $plan->line_meta, true);
            $planMeta['additional_item_ids'] = [(int) $fee->id];
            $db->table('fct_order_items')->where('id', $plan->id)->update(['line_meta' => json_encode($planMeta)]);
        }

        if ($written['refund_id'] && $written['transaction_id']) {
            $db->table('fct_order_transactions')
                ->where('order_id', $written['order_id'])
                ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
                ->update(['meta' => json_encode(['parent_id' => (int) $written['transaction_id']])]);
        }

        return $written;
    }

    private function buildOrder($ffSub, $charge, array $product, $customerId, $type, array $refunds, array $addresses, array &$details)
    {
        $total     = $this->chargedAmount($charge);
        $createdAt = get_gmt_from_date($charge->created_at);
        $mode      = $charge->payment_mode === 'test' ? 'test' : 'live';
        $currency  = strtoupper($charge->currency);

        $parts = $type === Status::ORDER_TYPE_SUBSCRIPTION
            ? $this->firstChargeParts($ffSub, (int) $ffSub->submission_id, $total, $details)
            : $this->renewalParts($ffSub, $total);

        $items = [OrderItemData::make([
            'postId'          => $product['post_id'],
            'fulfillmentType' => 'digital',
            'paymentType'     => 'subscription',
            'postTitle'       => $product['title'],
            'title'           => $ffSub->plan_name ?: $ffSub->item_name,
            'objectId'        => $product['variation_id'],
            'quantity'        => 1,
            'unitPrice'       => $parts['plan'],
            'subtotal'        => $parts['plan'],
            'taxAmount'       => $parts['tax'],
            'lineTotal'       => $parts['plan'],
            'otherInfo'       => $product['other_info'],
            'createdAt'       => $createdAt,
            'updatedAt'       => $createdAt,
        ])];

        if ($parts['signup_fee']) {
            $items[] = OrderItemData::make([
                'postId'          => $product['post_id'],
                'fulfillmentType' => 'digital',
                'paymentType'     => 'signup_fee',
                'postTitle'       => $product['title'],
                'title'           => 'Signup Fee',
                'objectId'        => $product['variation_id'],
                'quantity'        => 1,
                'unitPrice'       => $parts['signup_fee'],
                'subtotal'        => $parts['signup_fee'],
                'lineTotal'       => $parts['signup_fee'],
                'createdAt'       => $createdAt,
                'updatedAt'       => $createdAt,
            ]);
        }

        foreach ($parts['singles'] as $single) {
            $items[] = OrderItemData::make([
                'fulfillmentType' => 'digital',
                'paymentType'     => 'onetime',
                'postTitle'       => $single['title'],
                'title'           => $single['title'],
                'quantity'        => $single['quantity'],
                'unitPrice'       => $single['unit_price'],
                'subtotal'        => $single['subtotal'],
                'discountTotal'   => $single['discount'],
                'lineTotal'       => $single['subtotal'] - $single['discount'],
                'otherInfo'       => ['payment_type' => 'onetime', 'is_custom' => true],
                'createdAt'       => $createdAt,
                'updatedAt'       => $createdAt,
            ]);
        }

        $subtotal = array_sum(array_map(function ($item) {
            return (int) $item->subtotal;
        }, $items));

        $config = [
            'migrated_from'      => 'fluentforms',
            'ff_submission_id'   => (int) $ffSub->submission_id,
            'ff_subscription_id' => (int) $ffSub->id,
            'ff_transaction_id'  => (int) $charge->id,
        ];
        if ((int) $ffSub->quantity > 1) {
            $config['ff_quantity'] = (int) $ffSub->quantity;
        }

        $order = OrderData::make([
            'status'              => Status::ORDER_COMPLETED,
            'fulfillmentType'     => 'digital',
            'type'                => $type,
            'mode'                => $mode,
            'customerId'          => $customerId,
            'paymentMethod'       => 'stripe',
            'paymentStatus'       => Status::PAYMENT_PAID,
            'paymentMethodTitle'  => 'Stripe',
            'currency'            => $currency,
            'subtotal'            => $subtotal,
            'couponDiscountTotal' => $parts['discount'],
            'taxTotal'            => $parts['tax'],
            'taxBehavior'         => $parts['tax'] ? 1 : 0,
            'totalAmount'         => $total,
            'totalPaid'           => $total,
            'completedAt'         => $createdAt,
            'config'              => $config,
            'createdAt'           => $createdAt,
            'updatedAt'           => $createdAt,
            'addresses'           => $this->orderAddresses($addresses, $charge, $createdAt),
        ]);
        $order->items = $items;

        $card     = $this->cardDetails($ffSub, $charge);
        $refund   = isset($refunds[$charge->id]) ? $refunds[$charge->id] : null;
        $chargeTx = TransactionData::make([
            'orderType'         => $type,
            'transactionType'   => Status::TRANSACTION_TYPE_CHARGE,
            'cardLast4'         => (string) Arr::get($card, 'card.last4', $charge->card_last_4 ? str_pad((string) $charge->card_last_4, 4, '0', STR_PAD_LEFT) : ''),
            'cardBrand'         => (string) Arr::get($card, 'card.brand', $charge->card_brand),
            'vendorChargeId'    => (string) $charge->charge_id,
            'paymentMethod'     => 'stripe',
            'paymentMode'       => $mode,
            'paymentMethodType' => 'card',
            'status'            => Status::TRANSACTION_SUCCEEDED,
            'currency'          => $currency,
            'total'             => $total,
            'createdAt'         => $createdAt,
            'updatedAt'         => $createdAt,
        ]);

        if ($refund) {
            $refundedAt = $this->gmt($refund['at']) ?: $createdAt;
            $full       = $refund['amount'] >= $total;

            $chargeTx->meta       = ['refunded_total' => $refund['amount']];
            $order->totalRefund   = $refund['amount'];
            $order->paymentStatus = $full ? Status::PAYMENT_REFUNDED : Status::PAYMENT_PARTIALLY_REFUNDED;
            $order->refundedAt    = $full ? $refundedAt : null;
            $order->refunds       = [TransactionData::make([
                'orderType'         => $type,
                'transactionType'   => Status::TRANSACTION_TYPE_REFUND,
                'paymentMethod'     => 'stripe',
                'paymentMode'       => $mode,
                'paymentMethodType' => 'card',
                'status'            => Status::TRANSACTION_REFUNDED,
                'currency'          => $currency,
                'total'             => $refund['amount'],
                'createdAt'         => $refundedAt,
                'updatedAt'         => $refundedAt,
            ])];
            $this->spreadItemRefund($items, $refund['amount']);
        }

        $order->transactions = [$chargeTx];

        $details[] = sprintf(
            '%s order, charge %s on %s: %s = %s%s',
            $type === Status::ORDER_TYPE_SUBSCRIPTION ? 'parent' : 'renewal',
            $charge->charge_id,
            $createdAt,
            $this->describeItems($items, $parts),
            $this->money($total),
            $refund ? sprintf(', refunded %s (%s)', $this->money($refund['amount']), $order->paymentStatus) : ''
        );

        return $order;
    }

    /**
     * Splits the first charge into the plan line, the signup fee line and the
     * form's one-time items. Fluent Forms already took coupons off the plan's
     * recurring and signup amounts; its `discount` rows only cover the
     * one-time items. When the parts do not add up to what Stripe charged,
     * the whole charge goes on the plan line.
     */
    private function firstChargeParts($ffSub, $submissionId, $total, array &$details)
    {
        $rows = fluentCart('db')->table('fluentform_order_items')
            ->where('submission_id', $submissionId)
            ->orderBy('id', 'ASC')
            ->get();

        $singles  = [];
        $discount = 0;
        foreach ($rows as $row) {
            if ($row->type === 'discount') {
                $discount += (int) $row->line_total;
            } elseif ($row->type === 'single') {
                $singles[] = [
                    'title'      => (string) $row->item_name,
                    'quantity'   => max(1, (int) $row->quantity),
                    'unit_price' => (int) $row->item_price,
                    'subtotal'   => (int) $row->line_total,
                    'discount'   => 0,
                ];
            }
        }

        $quantity    = max(1, (int) $ffSub->quantity);
        $plan        = (int) $ffSub->trial_days > 0 ? 0 : (int) $ffSub->recurring_amount * $quantity;
        $signupFee   = max(0, (int) $ffSub->initial_amount);
        $tax         = max(0, (int) $ffSub->initial_tax);
        $singleTotal = array_sum(array_column($singles, 'subtotal'));
        $expected    = $plan + $signupFee + $singleTotal - $discount + $tax;

        if ($expected !== $total || $discount > $singleTotal) {
            $details[] = sprintf(
                'warning: first charge %s does not match the form items (%s); recorded as one plan line',
                $this->money($total),
                $this->money($expected)
            );

            return ['plan' => $total, 'signup_fee' => 0, 'singles' => [], 'discount' => 0, 'tax' => 0];
        }

        $left = $discount;
        foreach ($singles as $index => $single) {
            $share = $index === count($singles) - 1
                ? $left
                : (int) floor($discount * $single['subtotal'] / max(1, $singleTotal));
            $share = min($share, $single['subtotal'], $left);

            $singles[$index]['discount'] = $share;
            $left -= $share;
        }

        return ['plan' => $plan, 'signup_fee' => $signupFee, 'singles' => $singles, 'discount' => $discount, 'tax' => $tax];
    }

    private function renewalParts($ffSub, $total)
    {
        $tax = min(max(0, (int) $ffSub->recurring_tax), $total);

        return ['plan' => $total - $tax, 'signup_fee' => 0, 'singles' => [], 'discount' => 0, 'tax' => $tax];
    }

    /**
     * @param OrderItemData[] $items
     */
    private function spreadItemRefund(array $items, $amount)
    {
        $base = array_sum(array_map(function ($item) {
            return (int) $item->lineTotal + (int) $item->taxAmount;
        }, $items));

        $left = $amount;
        foreach ($items as $index => $item) {
            $line  = (int) $item->lineTotal + (int) $item->taxAmount;
            $share = $index === count($items) - 1 ? $left : (int) floor($amount * $line / max(1, $base));
            $share = min($share, $left);

            $item->refundTotal = $share;
            $left -= $share;
        }
    }

    /**
     * Fluent Forms keeps one refund row per submission: each Stripe refund
     * deletes the earlier rows and writes the refunded charge's cumulative
     * total. A row is matched to its charge first; a `refunded` charge is
     * refunded in full; any unmatched amount goes to the remaining
     * `partially-refunded` charges, newest first.
     *
     * @return array<int,array{amount:int,at:string}> keyed by FF transaction id
     */
    private function refundSplit($ffSub, array $charges, array &$details)
    {
        $rows = fluentCart('db')->table('fluentform_transactions')
            ->where('submission_id', $ffSub->submission_id)
            ->where('transaction_type', 'refund')
            ->orderBy('id', 'ASC')
            ->get();

        $split     = [];
        $unmatched = 0;
        $lastAt    = null;

        foreach ($rows as $row) {
            $amount = abs((int) $row->payment_total);
            $charge = $this->chargeForRefund($row, $charges);
            if (!$charge) {
                $unmatched += $amount;
                $lastAt     = $row->created_at;
                continue;
            }

            $already          = isset($split[$charge->id]) ? $split[$charge->id]['amount'] : 0;
            $split[$charge->id] = [
                'amount' => min($already + $amount, $this->chargedAmount($charge)),
                'at'     => $row->created_at,
            ];
        }

        foreach ($charges as $charge) {
            if ($charge->status === 'refunded') {
                $split[$charge->id] = [
                    'amount' => $this->chargedAmount($charge),
                    'at'     => isset($split[$charge->id]) ? $split[$charge->id]['at'] : $charge->updated_at,
                ];
            }
        }

        foreach (array_reverse($charges) as $charge) {
            if ($charge->status !== 'partially-refunded' || isset($split[$charge->id])) {
                continue;
            }

            if ($unmatched <= 0) {
                $details[] = sprintf('warning: charge %s is partially refunded in Fluent Forms but the amount is unknown; no refund recorded', $charge->charge_id);
                continue;
            }

            $amount             = min($unmatched, $this->chargedAmount($charge));
            $split[$charge->id] = ['amount' => $amount, 'at' => $lastAt ?: $charge->updated_at];
            $unmatched         -= $amount;
        }

        if ($unmatched > 0) {
            $details[] = sprintf('warning: %s refunded on entry #%d matches no charge; not recorded', $this->money($unmatched), $ffSub->submission_id);
        }

        return array_filter($split, function ($refund) {
            return $refund['amount'] > 0;
        });
    }

    private function chargeForRefund($refundRow, array $charges)
    {
        $id = (string) $refundRow->charge_id;
        if (!$id) {
            return null;
        }

        foreach ($charges as $charge) {
            $note = $this->decode($charge->payment_note);
            $ids  = [
                $charge->charge_id,
                Arr::get($note, 'id'),
                Arr::get($note, 'payment_intent'),
                Arr::get($note, 'charge'),
                Arr::get($note, 'latest_charge'),
            ];
            if (in_array($id, array_filter($ids, 'is_string'), true)) {
                return $charge;
            }
        }

        return null;
    }

    /**
     * What Stripe actually took for this charge, in cents. Falls back to the
     * amount Fluent Forms recorded.
     */
    private function chargedAmount($charge)
    {
        $note   = $this->decode($charge->payment_note);
        $object = Arr::get($note, 'object');
        $amount = null;

        if ($object === 'payment_intent') {
            $amount = Arr::get($note, 'amount_received');
        } elseif ($object === 'invoice') {
            $amount = Arr::get($note, 'amount_paid');
        }

        if (!is_numeric($amount) || (int) $amount <= 0) {
            return (int) $charge->payment_total;
        }

        return CurrenciesHelper::isZeroDecimal(strtoupper($charge->currency)) ? (int) $amount * 100 : (int) $amount;
    }

    private function buildSubscription($ffSub, $first, array $renewals, array $product)
    {
        $createdAt = get_gmt_from_date($ffSub->created_at);
        $status    = $this->mapStatus($ffSub->status);
        $charges   = array_merge([$first], $renewals);

        $meta = [];
        $card = $this->cardDetails($ffSub, end($charges));
        if ($card) {
            $meta['active_payment_method'] = PaymentHelper::parsePaymentMethodDetails('stripe', $card);
        }

        $trialDays = (int) $ffSub->trial_days;
        $billTimes = (int) $ffSub->bill_times;
        $billCount = count(array_filter($charges, function ($charge) {
            return (int) $charge->payment_total > 0;
        }));

        // Same rule as CheckoutProcessor::syncInitialCycleCounting(): a signup-fee
        // charge at trial start is not a billed cycle.
        if ($trialDays > 0 && $billTimes > 0 && (int) $first->payment_total > 0 && $this->isTrialStartCharge($ffSub, $first)) {
            $meta['billed_cycles_deduction'] = 1;
            $billCount--;
        }

        // Fluent Forms counts a $0 cycle as billed (StripeProcessor::isCountableSubscriptionPayment);
        // calculateBillCount() skips $0 charges, so the offset keeps it through every resync.
        $freeCycles = count(array_filter($charges, function ($charge) use ($trialDays) {
            if ((int) $charge->payment_total > 0) {
                return false;
            }
            $reason = Arr::get($this->decode($charge->payment_note), 'billing_reason');

            return $reason === 'subscription_cycle' || ($reason === 'subscription_create' && !$trialDays);
        }));
        if ($billTimes > 0 && $freeCycles) {
            $meta['billed_cycles_offset'] = $freeCycles;
            $billCount += $freeCycles;
        }

        $recurring    = (int) $ffSub->recurring_amount * max(1, (int) $ffSub->quantity);
        $recurringTax = max(0, (int) $ffSub->recurring_tax);

        $config = [
            'migration_source'   => 'fluentforms',
            'ff_subscription_id' => (int) $ffSub->id,
        ];
        if ((int) $ffSub->quantity > 1) {
            $config['ff_quantity'] = (int) $ffSub->quantity;
        }

        $activities = [ActivityData::make([
            'title'     => 'Subscription migrated from Fluent Forms',
            'content'   => sprintf('Fluent Forms subscription #%d (entry #%d)', $ffSub->id, $ffSub->submission_id),
            'createdAt' => current_time('mysql', true),
            'updatedAt' => current_time('mysql', true),
        ])];

        return SubscriptionData::make([
            'productId'            => $product['post_id'],
            'itemName'             => $product['title'],
            'variationId'          => $product['variation_id'],
            'billingInterval'      => MigratorHelper::repeatInterval($ffSub->billing_interval),
            'signupFee'            => (int) $ffSub->initial_amount,
            'initialTaxTotal'      => max(0, (int) $ffSub->initial_tax),
            'recurringAmount'      => $recurring,
            'recurringTaxTotal'    => $recurringTax,
            'recurringTotal'       => $recurring + $recurringTax,
            'billTimes'            => $billTimes,
            'billCount'            => $billCount,
            'trialDays'            => $trialDays,
            'trialEndsAt'          => $trialDays > 0 ? $this->gmt($ffSub->expiration_at) : null,
            'canceledAt'           => $status === Status::SUBSCRIPTION_CANCELED ? $this->gmt($ffSub->updated_at) : null,
            'collectionMethod'     => 'automatic',
            'nextBillingDate'      => in_array($status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING], true)
                ? $this->nextBillingDate($ffSub, $charges)
                : null,
            'vendorCustomerId'     => (string) $ffSub->vendor_customer_id,
            'vendorPlanId'         => (string) $ffSub->vendor_plan_id,
            'vendorSubscriptionId' => (string) $ffSub->vendor_subscription_id,
            'status'               => $status,
            'currentPaymentMethod' => 'stripe',
            'config'               => $config,
            'meta'                 => $meta,
            'activities'           => array_merge($activities, $this->logActivities($ffSub)),
            'createdAt'            => $createdAt,
            'updatedAt'            => $createdAt,
        ]);
    }

    /**
     * @return ActivityData[]
     */
    private function logActivities($ffSub)
    {
        $logs = fluentCart('db')->table('fluentform_logs')
            ->where('source_type', 'submission_item')
            ->where('source_id', $ffSub->submission_id)
            ->where('component', 'Payment')
            ->orderBy('id', 'ASC')
            ->get();

        $statuses   = ['success' => 'success', 'info' => 'info', 'warning' => 'warning', 'failed' => 'failed', 'error' => 'failed'];
        $activities = [];
        foreach ($logs as $log) {
            $at           = $this->gmt($log->created_at) ?: current_time('mysql', true);
            $activities[] = ActivityData::make([
                'status'    => isset($statuses[$log->status]) ? $statuses[$log->status] : 'info',
                'title'     => (string) $log->title,
                'content'   => (string) $log->description,
                'createdBy' => 'Fluent Forms',
                'createdAt' => $at,
                'updatedAt' => $at,
            ]);
        }

        return $activities;
    }

    /**
     * One product per form payment field. Every plan any FF subscription used
     * on that field becomes a variation up front; a plan first seen on a
     * later run is appended.
     */
    private function resolveProduct($ffSub)
    {
        $source = $this->productSource($ffSub);

        if (!isset($this->products[$source])) {
            $postId = $this->findMigratedProduct($source);
            if (!$postId) {
                $postId = $this->createProduct($ffSub, $source);
                if (is_wp_error($postId)) {
                    return $postId;
                }
            }
            $this->products[$source] = $postId;
        }

        $postId    = $this->products[$source];
        $map       = (array) get_post_meta($postId, self::PRODUCT_MAP_KEY, true);
        $key       = $this->planKey($ffSub);
        $variation = $this->variationData($ffSub, count($map) + 1);

        if (empty($map[$key])) {
            $row            = $variation->toArray();
            $row['post_id'] = $postId;
            $row['sku']     = Sku::unique($variation->sku);

            $map[$key] = (int) fluentCart('db')->table('fct_product_variations')->insertGetId($row);
            update_post_meta($postId, self::PRODUCT_MAP_KEY, $map);

            $prices = fluentCart('db')->table('fct_product_variations')->where('post_id', $postId)->pluck('item_price')->all();
            fluentCart('db')->table('fct_product_details')->where('post_id', $postId)->update([
                'variation_type' => 'simple_variations',
                'min_price'      => min($prices),
                'max_price'      => max($prices),
                'updated_at'     => gmdate('Y-m-d H:i:s'),
            ]);
        }

        return [
            'post_id'      => $postId,
            'title'        => get_post_field('post_title', $postId),
            'variation_id' => (int) $map[$key],
            'other_info'   => $variation->otherInfo,
        ];
    }

    private function previewProduct($ffSub, array &$details)
    {
        $source    = $this->productSource($ffSub);
        $postId    = $this->findMigratedProduct($source);
        $variation = $this->variationData($ffSub, 1);
        $product   = ['post_id' => 0, 'title' => $this->productTitle($ffSub), 'variation_id' => 0, 'other_info' => $variation->otherInfo];

        if (!$postId) {
            $details[] = sprintf('product: would create "%s", variation "%s"', $product['title'], $variation->variationTitle);
            return $product;
        }

        $map = (array) get_post_meta($postId, self::PRODUCT_MAP_KEY, true);
        $key = $this->planKey($ffSub);

        $product['post_id']      = $postId;
        $product['title']        = get_post_field('post_title', $postId);
        $product['variation_id'] = empty($map[$key]) ? 0 : (int) $map[$key];

        $details[] = $product['variation_id']
            ? sprintf('product: #%d, variation #%d', $postId, $product['variation_id'])
            : sprintf('product: #%d, would add variation "%s"', $postId, $variation->variationTitle);

        return $product;
    }

    private function productSource($ffSub)
    {
        return 'form_' . (int) $ffSub->form_id . ':' . $ffSub->element_id;
    }

    private function productTitle($ffSub)
    {
        $form  = fluentCart('db')->table('fluentform_forms')->find($ffSub->form_id);
        $title = $form ? $form->title . ' – ' . $ffSub->item_name : $ffSub->item_name;

        return $title ?: 'Fluent Forms subscription';
    }

    private function findMigratedProduct($source)
    {
        $posts = get_posts([
            'post_type'      => FluentProducts::CPT_NAME,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => self::PRODUCT_SOURCE_KEY,
            'meta_value'     => $source,
        ]);

        return $posts ? (int) $posts[0] : 0;
    }

    private function createProduct($ffSub, $source)
    {
        $plans = fluentCart('db')->table('fluentform_subscriptions')
            ->where('form_id', $ffSub->form_id)
            ->where('element_id', $ffSub->element_id)
            ->orderBy('id', 'ASC')
            ->get();

        $variations = [];
        foreach ($plans as $plan) {
            $key = $this->planKey($plan);
            if (!isset($variations[$key])) {
                $variations[$key] = $this->variationData($plan, count($variations) + 1);
            }
        }

        $postId = (new ProductWriter())->write(ProductData::make([
            'sourceId'    => $source,
            'postTitle'   => $this->productTitle($ffSub),
            'isVariable'  => count($variations) > 1,
            'createdAt'   => current_time('mysql'),
            'variations'  => array_values($variations),
            'mappingKeys' => [
                'fct'          => self::PRODUCT_SOURCE_KEY,
                'variationMap' => self::PRODUCT_MAP_KEY,
            ],
        ]));

        if (is_wp_error($postId)) {
            return $postId;
        }

        // Existing subscribers only: the migrated plans are not for sale.
        wp_update_post(['ID' => $postId, 'post_status' => 'private']);

        return $postId;
    }

    private function variationData($plan, $serialIndex)
    {
        $times   = (int) $plan->bill_times;
        $repeat  = MigratorHelper::repeatInterval($plan->billing_interval);
        $price   = (int) $plan->recurring_amount;
        $signup  = (int) $plan->initial_amount;
        $amount  = number_format($price / 100, 2, '.', '');
        $summary = $times > 0
            ? sprintf('%s every %s for %d times', $amount, $repeat, $times)
            : sprintf('%s every %s until cancelled', $amount, $repeat);

        return ProductVariationData::make([
            'serialIndex'         => $serialIndex,
            'variationTitle'      => $plan->plan_name ?: $plan->item_name,
            'variationIdentifier' => $this->planKey($plan),
            'paymentType'         => 'subscription',
            'fulfillmentType'     => 'digital',
            'itemPrice'           => $price,
            'otherInfo'           => [
                'payment_type'     => 'subscription',
                'repeat_interval'  => $repeat,
                'times'            => $times,
                'trial_days'       => (int) $plan->trial_days,
                'manage_setup_fee' => $signup > 0 ? 'yes' : 'no',
                'signup_fee'       => $signup,
                'signup_fee_name'  => '',
                'installment'      => $times > 0 ? 'yes' : 'no',
                'billing_summary'  => $summary,
            ],
            'createdAt'           => current_time('mysql'),
            'updatedAt'           => current_time('mysql'),
        ]);
    }

    private function planKey($plan)
    {
        return md5(implode('|', [
            $plan->plan_name,
            $plan->billing_interval,
            (int) $plan->recurring_amount,
            (int) $plan->bill_times,
            (int) $plan->trial_days,
            (int) $plan->initial_amount,
        ]));
    }

    /**
     * The FluentCart customer for the submission's WordPress user, else for
     * its email. Only a customer this run creates gets an address book.
     *
     * @return array{id:int,created:bool}|\WP_Error
     */
    private function customer($submission, $charge, array $addresses, array &$details)
    {
        $data = $this->customerData($submission, $charge, $addresses);
        $db   = fluentCart('db');

        $found = $data->userId ? $db->table('fct_customers')->where('user_id', $data->userId)->first() : null;
        if (!$found && $data->email) {
            $found = $db->table('fct_customers')->where('email', $data->email)->first();
        }

        if ($found) {
            $details[] = sprintf('customer: #%d (%s)', $found->id, $found->email);
            return ['id' => (int) $found->id, 'created' => false];
        }

        if ($this->dryRun) {
            if (!$data->email) {
                return new \WP_Error('customer_no_email', 'Customer email is empty.');
            }
            $details[] = sprintf('customer: would create %s', $data->email);
            return ['id' => 0, 'created' => true];
        }

        $row = CustomerWriter::findOrCreate($data);
        if (is_wp_error($row)) {
            return $row;
        }

        $this->createdCustomers[(int) $row->id] = true;
        $details[] = sprintf('customer: created #%d (%s)', $row->id, $row->email);

        return ['id' => (int) $row->id, 'created' => true];
    }

    private function customerData($submission, $charge, array $addresses)
    {
        $user  = $submission->user_id ? get_user_by('id', $submission->user_id) : null;
        $email = $user ? $user->user_email : (string) $charge->payer_email;

        if (!$email) {
            $response = json_decode((string) $submission->response, true);
            $email    = (string) Arr::get((array) $response, 'email', '');
        }

        $name = $user
            ? [$user->first_name ?: $user->display_name, $user->last_name]
            : array_pad(explode(' ', trim((string) $charge->payer_name), 2), 2, '');

        $createdAt = get_gmt_from_date($submission->created_at);
        $book      = [];
        foreach ($addresses as $type => $address) {
            $book[] = array_merge($address, [
                'is_primary' => 1,
                'type'       => $type,
                'name'       => trim($name[0] . ' ' . $name[1]),
                'email'      => $email,
                'created_at' => $createdAt,
            ]);
        }

        $billing = Arr::get($addresses, 'billing', []);

        return CustomerData::make([
            'userId'    => $user ? $user->ID : null,
            'email'     => $email,
            'firstName' => (string) $name[0],
            'lastName'  => (string) $name[1],
            'country'   => (string) Arr::get($billing, 'country', ''),
            'city'      => (string) Arr::get($billing, 'city', ''),
            'state'     => (string) Arr::get($billing, 'state', ''),
            'postcode'  => (string) Arr::get($billing, 'postcode', ''),
            'addresses' => $book,
            'createdAt' => $createdAt,
            'updatedAt' => $createdAt,
        ]);
    }

    /**
     * Billing comes from the form's customer address field, else the address
     * Stripe Checkout collected, else the flat string on the charge. Shipping
     * only exists as that flat string.
     *
     * @return array<string,array<string,string>> customer-address columns keyed by type
     */
    private function addresses($submission, $charge)
    {
        $response = $this->decode($submission->response);
        $field    = $this->formAddressField($submission->form_id);
        $form     = $field ? Arr::get($response, $field) : null;
        $stripe   = Arr::get($response, '__checkout_billing_address_details');
        $billing  = [];

        if (is_array($form) && array_filter($form)) {
            $billing = [
                'address_1' => (string) Arr::get($form, 'address_line_1', ''),
                'address_2' => (string) Arr::get($form, 'address_line_2', ''),
                'city'      => (string) Arr::get($form, 'city', ''),
                'state'     => (string) Arr::get($form, 'state', ''),
                'postcode'  => (string) Arr::get($form, 'zip', ''),
                'country'   => (string) Arr::get($form, 'country', ''),
            ];
        } elseif (is_array($stripe) && array_filter($stripe)) {
            $billing = [
                'address_1' => (string) Arr::get($stripe, 'line1', ''),
                'address_2' => (string) Arr::get($stripe, 'line2', ''),
                'city'      => (string) Arr::get($stripe, 'city', ''),
                'state'     => (string) Arr::get($stripe, 'state', ''),
                'postcode'  => (string) Arr::get($stripe, 'postal_code', ''),
                'country'   => (string) Arr::get($stripe, 'country', ''),
            ];
        } elseif (trim((string) $charge->billing_address)) {
            $billing = ['address_1' => trim((string) $charge->billing_address)];
        }

        $shipping = trim((string) $charge->shipping_address)
            ? ['address_1' => trim((string) $charge->shipping_address)]
            : [];

        return array_filter(['billing' => $billing, 'shipping' => $shipping]);
    }

    private function formAddressField($formId)
    {
        foreach (['\FluentForm\App\Modules\Payments\PaymentHelper', '\FluentFormPro\Payments\PaymentHelper'] as $helper) {
            if (class_exists($helper) && method_exists($helper, 'getFormSettings')) {
                return (string) Arr::get((array) $helper::getFormSettings($formId, 'admin'), 'customer_address', '');
            }
        }

        return '';
    }

    /**
     * @return AddressData[]
     */
    private function orderAddresses(array $addresses, $charge, $createdAt)
    {
        $list = [];
        foreach ($addresses as $type => $address) {
            $list[] = AddressData::make([
                'type'      => $type,
                'name'      => trim((string) $charge->payer_name),
                'address1'  => Arr::get($address, 'address_1', ''),
                'address2'  => Arr::get($address, 'address_2', ''),
                'city'      => Arr::get($address, 'city', ''),
                'state'     => Arr::get($address, 'state', ''),
                'postcode'  => Arr::get($address, 'postcode', ''),
                'country'   => Arr::get($address, 'country', ''),
                'meta'      => array_filter(['email' => (string) $charge->payer_email]),
                'createdAt' => $createdAt,
                'updatedAt' => $createdAt,
            ]);
        }

        return $list;
    }

    /**
     * The Stripe PaymentMethod behind a charge, shaped like Stripe's object
     * (id, type, card) for PaymentHelper::parsePaymentMethodDetails(). The
     * first charge stores the PaymentIntent, a renewal the invoice.
     */
    private function cardDetails($ffSub, $charge)
    {
        foreach ([$this->decode($charge->payment_note), $this->decode($ffSub->vendor_response)] as $object) {
            $intent = Arr::get($object, 'object') === 'payment_intent' ? $object : Arr::get($object, 'payment_intent');
            if (!is_array($intent)) {
                continue;
            }

            $card = Arr::get($intent, 'charges.data.0.payment_method_details.card');
            $pmId = Arr::get($intent, 'payment_method');
            if ($card && is_string($pmId)) {
                return ['id' => $pmId, 'type' => 'card', 'card' => $card];
            }
        }

        return [];
    }

    private function nextBillingDate($ffSub, array $charges)
    {
        $sources = array_map(function ($charge) {
            return $this->decode($charge->payment_note);
        }, array_reverse($charges));
        $sources[] = $this->decode($ffSub->vendor_response);

        foreach ($sources as $invoice) {
            $end = (int) Arr::get($invoice, 'lines.data.0.period.end');
            if ($end) {
                return gmdate('Y-m-d H:i:s', $end);
            }
        }

        return null;
    }

    private function mapStatus($status)
    {
        $map = [
            'active'    => Status::SUBSCRIPTION_ACTIVE,
            'trialling' => Status::SUBSCRIPTION_TRIALING,
            'cancelled' => Status::SUBSCRIPTION_CANCELED,
            'refunded'  => Status::SUBSCRIPTION_CANCELED,
            'completed'=> Status::SUBSCRIPTION_COMPLETED,
            'failing'   => Status::SUBSCRIPTION_FAILING,
            'pending'   => Status::SUBSCRIPTION_PENDING,
        ];

        return isset($map[$status]) ? $map[$status] : Status::SUBSCRIPTION_PENDING;
    }

    private function paidCharges($ffSubId)
    {
        return fluentCart('db')->table('fluentform_transactions')
            ->where('subscription_id', $ffSubId)
            ->where('transaction_type', 'subscription')
            ->whereIn('status', ['paid', 'refunded', 'partially-refunded'])
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->all();
    }

    /**
     * @param OrderItemData[] $items
     */
    private function describeItems(array $items, array $parts)
    {
        $lines = array_map(function ($item) {
            return sprintf('%s %s', $item->title, $this->money($item->subtotal));
        }, $items);

        if ($parts['discount']) {
            $lines[] = 'discount -' . $this->money($parts['discount']);
        }
        if ($parts['tax']) {
            $lines[] = 'tax ' . $this->money($parts['tax']);
        }

        return implode(' + ', $lines);
    }

    private function money($cents)
    {
        return number_format((int) $cents / 100, 2, '.', '');
    }

    /**
     * Mirrors Fluent Forms' StripeProcessor::isTrialStartInvoice(). The oldest paid row is
     * not always the trial start: 6.2.13 and earlier stored a $0 trial-start invoice with status '0'.
     */
    private function isTrialStartCharge($ffSub, $charge)
    {
        $note = $this->decode($charge->payment_note);
        if (Arr::get($note, 'billing_reason') === 'subscription_create') {
            return true;
        }

        $storedId = (string) Arr::get($note, 'id', '');
        if ($storedId === '') {
            return false;
        }

        $initialInvoice = $this->decode($ffSub->vendor_response);
        $object = Arr::get($note, 'object');
        if ($object === 'invoice') {
            return (string) Arr::get($initialInvoice, 'id', '') === $storedId;
        }
        if ($object !== 'payment_intent') {
            return false;
        }

        $initialIntent = Arr::get($initialInvoice, 'payment_intent');
        if (is_array($initialIntent)) {
            $initialIntent = Arr::get($initialIntent, 'id');
        }

        return (string) $initialIntent === $storedId;
    }

    private function decode($value)
    {
        $value = maybe_unserialize($value);
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) || is_object($value) ? json_decode(wp_json_encode($value), true) : [];
    }

    private function gmt($date)
    {
        return $date && strpos($date, '0000') !== 0 ? get_gmt_from_date($date) : null;
    }

    private function result($status, $message, $subscriptionId = null, array $details = [], $code = null)
    {
        return array_filter([
            'status'          => $status,
            'message'         => $message,
            'subscription_id' => $subscriptionId,
            'code'            => $code,
        ]) + ['details' => $details];
    }
}
