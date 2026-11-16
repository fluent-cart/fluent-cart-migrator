<template>
    <!-- Not dismissable: shown until Woo has no pending actions for migrated subscriptions -->
    <div class="fct-alert fct-alert-warning fct-migrator-alert">
        <div class="fct-alert-icon"><DynamicIcon name="Warning"/></div>
        <div class="fct-alert-content">
            <strong class="fct-alert-heading">{{ __('WooCommerce can still charge migrated subscriptions') }}</strong>
            {{ message }}
            <p v-if="wcsActive">{{ __('After you check FluentCart, deactivate WooCommerce Subscriptions. It can schedule them again.') }}</p>
            <p>
                <fct-button size="small" type="warning" :loading="stopping" :disabled="stopping" @click="$emit('stop')">
                    {{ __('Stop WooCommerce renewals') }}
                </fct-button>
            </p>
        </div>
    </div>
</template>

<script>
import { _n, sprintf, i18nMixin } from '../i18n.js';
import { DynamicIcon } from '@fluent-cart/ui';

export default {
    mixins: [i18nMixin],
    name: 'WooRenewalsAlert',
    components: {
        DynamicIcon: DynamicIcon
    },
    props: {
        count: { type: Number, required: true },
        wcsActive: { type: Boolean, default: false },
        stopping: { type: Boolean, default: false }
    },
    emits: ['stop'],
    computed: {
        message: function () {
            var count = this.count;
            /* translators: %d: number of migrated subscriptions */
            return sprintf(_n('%d migrated subscription still has renewals scheduled in WooCommerce. FluentCart bills it now, so the customer can be charged twice.', '%d migrated subscriptions still have renewals scheduled in WooCommerce. FluentCart bills them now, so customers can be charged twice.', count), count);
        }
    }
};
</script>
