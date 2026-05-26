<?php

namespace FluentCartMigrator\Classes\EDD3;

class PaddleBackfill
{
    /**
     * Backfill vendor_subscription_id for Paddle subscriptions migrated before
     * this fix was applied. Those rows carry SmartPay-EDD's synthetic profile_id
     * placeholder instead of the real Paddle sub_xxx ID.
     *
     * Requires EDD Recurring to be active (provides edd_recurring_get_subscription_meta).
     *
     * @param  bool  $dryRun  When true, report changes without writing.
     * @return array{scanned:int, updated:int, unresolved:int, rows:array}
     */
    public function run($dryRun = false)
    {
        $result = [
            'scanned'    => 0,
            'updated'    => 0,
            'unresolved' => 0,
            'rows'       => [],
        ];

        if (!function_exists('edd_recurring_get_subscription_meta')) {
            $result['error'] = 'EDD Recurring plugin is not active. Cannot read subscription meta.';
            return $result;
        }

        $subscriptions = fluentCart('db')
            ->table('fct_subscriptions')
            ->whereIn('current_payment_method', ['paddle', 'smartpay_paddle'])
            ->get();

        foreach ($subscriptions as $sub) {
            $hasRealSubId = str_starts_with((string) $sub->vendor_subscription_id, 'sub_');
            $hasCorrectMethod = $sub->current_payment_method === 'paddle';

            if ($hasRealSubId && $hasCorrectMethod) {
                // Already correct — skip.
                continue;
            }

            $result['scanned']++;

            // If sub ID is already real but payment_method wrong, patch method only.
            if ($hasRealSubId && !$hasCorrectMethod) {
                if (!$dryRun) {
                    fluentCart('db')
                        ->table('fct_subscriptions')
                        ->where('id', $sub->id)
                        ->update(['current_payment_method' => 'paddle']);
                }
                $result['updated']++;
                $result['rows'][] = [
                    'fct_sub_id'     => $sub->id,
                    'status'         => $dryRun ? 'would_update' : 'updated',
                    'reason'         => null,
                    'current_id'     => $sub->vendor_subscription_id,
                    'new_id'         => $sub->vendor_subscription_id,
                    'payment_method' => 'smartpay_paddle → paddle',
                ];
                continue;
            }

            $config = json_decode($sub->config ?? '{}', true);
            $eddSubId = $config['edd_id'] ?? null;

            if (!$eddSubId) {
                $result['unresolved']++;
                $result['rows'][] = [
                    'fct_sub_id'  => $sub->id,
                    'status'      => 'unresolved',
                    'reason'      => 'No edd_id in config',
                    'current_id'  => $sub->vendor_subscription_id,
                    'new_id'      => null,
                ];
                continue;
            }

            $realSubId = edd_recurring_get_subscription_meta($eddSubId, '_wpsmartpay_edd_subscription_id', true);
            if (empty($realSubId)) {
                $realSubId = edd_recurring_get_subscription_meta($eddSubId, '_wpsmartpay_edd_sandbox_subscription_id', true);
            }

            $realSubId = apply_filters(
                'fluent_cart_migrator/edd_paddle_billing_subscription_id',
                $realSubId,
                (object) ['id' => $eddSubId],
                null
            );

            if (empty($realSubId)) {
                $result['unresolved']++;
                $result['rows'][] = [
                    'fct_sub_id'  => $sub->id,
                    'status'      => 'unresolved',
                    'reason'      => 'No Paddle subscription ID found in EDD recurring meta',
                    'current_id'  => $sub->vendor_subscription_id,
                    'new_id'      => null,
                ];
                continue;
            }

            $updates = ['vendor_subscription_id' => $realSubId];

            // Normalize legacy gateway slug so reSyncFromRemote() can dispatch to Paddle gateway.
            if ($sub->current_payment_method !== 'paddle') {
                $updates['current_payment_method'] = 'paddle';
            }

            if (!$dryRun) {
                fluentCart('db')
                    ->table('fct_subscriptions')
                    ->where('id', $sub->id)
                    ->update($updates);
            }

            $result['updated']++;
            $result['rows'][] = [
                'fct_sub_id'       => $sub->id,
                'status'           => $dryRun ? 'would_update' : 'updated',
                'reason'           => null,
                'current_id'       => $sub->vendor_subscription_id,
                'new_id'           => $realSubId,
                'payment_method'   => $updates['current_payment_method'] ?? 'paddle (already correct)',
            ];
        }

        return $result;
    }
}
