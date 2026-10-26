<template>
    <div class="fct-complete">
        <!-- Success header -->
        <div class="fct-card fct-card-border fct-complete-hero">
            <div class="fct-card-body">
                <div class="fct-complete-hero-inner">
                    <svg width="56" height="56" viewBox="0 0 56 56" fill="none">
                        <circle class="fct-fill-success-soft" cx="28" cy="28" r="28"/>
                        <circle class="fct-fill-success" cx="28" cy="28" r="20"/>
                        <path class="fct-stroke-on-solid" d="M18 28l7 7 13-13" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                    </svg>
                    <div>
                        <h2>{{ __('Migration Complete') }}</h2>
                        <p>{{ sprintf(__('Finished in %s'), formattedDuration) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary -->
        <div class="fct-card fct-card-border">
            <div class="fct-card-header">
                <h2 class="fct-card-header-title">{{ __('Migration Summary') }}</h2>
            </div>
            <div class="fct-card-body">

                <div class="fct-summary-rows">
                    <div v-if="stepsToRun.products" class="fct-summary-row">
                        <span class="fct-summary-row-label">{{ __('Products') }}</span>
                        <span class="fct-summary-row-value">
                            {{ sprintf(__('%s migrated'), runProgress.products.migrated) }}
                            <span v-if="runProgress.products.failed" class="fct-text-danger">, {{ sprintf(_n('%s failed', '%s failed', runProgress.products.failed), runProgress.products.failed) }}</span>
                        </span>
                    </div>
                    <div v-if="stepsToRun.taxonomies" class="fct-summary-row">
                        <span class="fct-summary-row-label">{{ __('Product Taxonomies') }}</span>
                        <span class="fct-summary-row-value">{{ sprintf(__('%s updated'), runProgress.taxonomies ? runProgress.taxonomies.updated : 0) }}</span>
                    </div>
                    <div v-if="stepsToRun.tax_rates" class="fct-summary-row">
                        <span class="fct-summary-row-label">{{ __('Tax Rates') }}</span>
                        <span class="fct-summary-row-value">{{ __('Completed') }}</span>
                    </div>
                    <div v-if="stepsToRun.coupons" class="fct-summary-row">
                        <span class="fct-summary-row-label">{{ __('Coupons') }}</span>
                        <span class="fct-summary-row-value">{{ sprintf(__('%s migrated'), runProgress.coupons.migrated) }}</span>
                    </div>
                    <div v-if="stepsToRun.payments" class="fct-summary-row">
                        <span class="fct-summary-row-label">{{ __('Orders & Payments') }}</span>
                        <span class="fct-summary-row-value">
                            {{ sprintf(__('%s processed'), runProgress.payments.processed) }}
                            <span v-if="runProgress.payments.errorsCount" class="fct-text-danger">({{ sprintf(_n('%s error', '%s errors', runProgress.payments.errorsCount), runProgress.payments.errorsCount) }})</span>
                        </span>
                    </div>
                    <div v-if="stepsToRun.reviews" class="fct-summary-row">
                        <span class="fct-summary-row-label">{{ __('Product Reviews') }}</span>
                        <span class="fct-summary-row-value">
                            {{ sprintf(__('%s migrated'), runProgress.reviews ? runProgress.reviews.migrated : 0) }}
                            <span v-if="skippedReplies" class="fct-text-warn">, {{ sprintf(_n('%s reply not imported', '%s replies not imported', skippedReplies), skippedReplies) }}</span>
                        </span>
                    </div>
                    <div v-if="stepsToRun.recount" class="fct-summary-row">
                        <span class="fct-summary-row-label">{{ __('Recount & Verify') }}</span>
                        <span class="fct-summary-row-value">{{ __('Completed') }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Backward-compat notice -->
        <div class="fct-card fct-card-border fct-license-card">
            <div class="fct-card-body">
                <div class="fct-alert fct-alert-warning fct-migrator-alert">
                    <div class="fct-alert-icon"><DynamicIcon name="Warning"/></div>
                    <div class="fct-alert-content">
                        <strong class="fct-alert-heading">{{ __('Keep this plugin active') }}</strong>
                        <p>{{ __('The FluentCart Migrator plugin must remain active to handle backward compatibility for:') }}</p>
                        <ul>
                            <li v-if="hasLicenses"><strong>{{ __('License API') }}</strong> — {{ __('activate, deactivate, and check-license endpoints still route here') }}</li>
                            <li><strong>{{ __('PayPal IPN') }}</strong> — {{ __('renewal notifications for existing subscriptions') }}</li>
                            <li><strong>{{ __('Stripe webhooks') }}</strong> — {{ __('charge ID resolution for legacy orders') }}</li>
                            <li><strong>{{ __('Download & renewal URLs') }}</strong> — {{ __("legacy EDD links won't resolve without it") }}</li>
                        </ul>
                        <p>{{ __('Deactivating this plugin may break customer integrations that still reference EDD endpoints.') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Next Steps -->
        <div class="fct-card fct-card-border">
            <div class="fct-card-header">
                <div class="flex-1">
                    <h2 class="fct-card-header-title">{{ __('Next Steps') }}</h2>
                    <p class="fct-card-header-text">{{ __('Verify your migrated data and configure FluentCart.') }}</p>
                </div>
            </div>
            <div class="fct-card-body">

                <div class="fct-next-grid">
                    <a :href="adminUrl + 'admin.php?page=fluent-cart#/products'" class="fct-next-card" target="_blank">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect class="fct-stroke-secondary" x="3" y="3" width="18" height="18" rx="3" stroke-width="1.5"/><path class="fct-stroke-secondary" d="M8 12h8m-4-4v8" stroke-width="1.5" stroke-linecap="round"/></svg>
                        <div>
                            <strong>{{ __('Verify Products') }}</strong>
                            <span>{{ __('Check that all products and pricing migrated correctly.') }}</span>
                        </div>
                    </a>
                    <a :href="adminUrl + 'admin.php?page=fluent-cart#/orders'" class="fct-next-card" target="_blank">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect class="fct-stroke-secondary" x="3" y="3" width="18" height="18" rx="3" stroke-width="1.5"/><path class="fct-stroke-secondary" d="M8 9h8M8 13h5" stroke-width="1.5" stroke-linecap="round"/></svg>
                        <div>
                            <strong>{{ __('Review Orders') }}</strong>
                            <span>{{ __('Spot-check a few orders to confirm data integrity.') }}</span>
                        </div>
                    </a>
                    <a :href="adminUrl + 'admin.php?page=fluent-cart#/customers'" class="fct-next-card" target="_blank">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle class="fct-stroke-secondary" cx="12" cy="8" r="4" stroke-width="1.5"/><path class="fct-stroke-secondary" d="M4 20c0-3.3 3.6-6 8-6s8 2.7 8 6" stroke-width="1.5" stroke-linecap="round"/></svg>
                        <div>
                            <strong>{{ __('Check Customers') }}</strong>
                            <span>{{ __('Verify customer records and purchase history.') }}</span>
                        </div>
                    </a>
                    <a :href="adminUrl + 'admin.php?page=fluent-cart#/settings/store-settings/'" class="fct-next-card" target="_blank">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle class="fct-stroke-secondary" cx="12" cy="12" r="3" stroke-width="1.5"/><path class="fct-stroke-secondary" d="M12 2v3m0 14v3M2 12h3m14 0h3M4.9 4.9l2.1 2.1m9.9 9.9l2.1 2.1M4.9 19.1l2.1-2.1m9.9-9.9l2.1-2.1" stroke-width="1.5" stroke-linecap="round"/></svg>
                        <div>
                            <strong>{{ __('Configure Settings') }}</strong>
                            <span>{{ __('Set up payment gateways, emails, and store options.') }}</span>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        <!-- Error Log -->
        <div v-if="errorLogEntries.length" class="fct-card fct-card-border">
            <div class="fct-card-header">
                <h2 class="fct-card-header-title">{{ __('Error Log') }}</h2>
                <div class="fct-card-header-actions">
                    <el-button size="small" @click="showLog = !showLog">
                        {{ showLog ? __('Hide') : __('Show') }} ({{ sprintf(_n('%s entry', '%s entries', errorLogEntries.length), errorLogEntries.length) }})
                    </el-button>
                </div>
            </div>
            <div class="fct-card-body">
                <div v-if="showLog" class="fct-error-log">
                    <el-table :data="errorLogEntries" row-key="paymentId" class="fct-log-table">
                        <el-table-column prop="paymentId" :label="__('Payment ID')" min-width="100" />
                        <el-table-column :label="__('Stage')" min-width="100">
                            <template #default="{ row }">{{ row.stage || '-' }}</template>
                        </el-table-column>
                        <el-table-column prop="message" :label="__('Message')" min-width="240" />
                    </el-table>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="fct-card fct-card-border">
            <div class="fct-card-body fct-migrator-actions">
                <el-button @click="$emit('run-again')">{{ __('Run Again') }}</el-button>
                <el-button tag="a" type="primary" :href="adminUrl + 'admin.php?page=fluent-cart#/'">{{ __('View FluentCart Dashboard') }}</el-button>
            </div>
        </div>
    </div>
</template>

<script>
import { i18nMixin } from '../i18n.js';
import { DynamicIcon } from '@fluent-cart/ui';
export default {
    mixins: [i18nMixin],
    name: 'MigrationComplete',
    components: {
        DynamicIcon: DynamicIcon
    },
    props: {
        runProgress: { type: Object, required: true },
        stepsToRun: { type: Object, required: true },
        startTime: { type: Number, default: null },
        endTime: { type: Number, default: null },
        hasLicenses: { type: Boolean, default: false },
        errorLog: { type: [Object, Array], default: function () { return {}; } },
        adminUrl: { type: String, default: '' }
    },
    emits: ['run-again'],
    data: function () {
        return {
            showLog: false
        };
    },
    computed: {
        // Replies left in WooCommerce because multiple replies per review are
        // off — reported by the step itself, like every other row's counters.
        skippedReplies: function () {
            return (this.runProgress.reviews && this.runProgress.reviews.skippedReplies) || 0;
        },
        formattedDuration: function () {
            if (!this.startTime || !this.endTime) return '00:00:00';
            var seconds = Math.floor((this.endTime - this.startTime) / 1000);
            var h = Math.floor(seconds / 3600);
            var m = Math.floor((seconds % 3600) / 60);
            var s = seconds % 60;
            return [h, m, s].map(function (v) { return String(v).padStart(2, '0'); }).join(':');
        },
        errorLogEntries: function () {
            var logs = this.errorLog;
            if (!logs || typeof logs !== 'object') return [];
            if (Array.isArray(logs)) {
                return logs.map(function (entry, i) {
                    if (typeof entry === 'string') return { paymentId: i, message: entry };
                    return entry;
                });
            }
            return Object.entries(logs).map(function (pair) {
                var id = pair[0];
                var data = pair[1];
                var obj = { paymentId: id };
                if (typeof data === 'string') {
                    obj.message = data;
                } else {
                    Object.assign(obj, data);
                }
                return obj;
            });
        }
    }
};
</script>
