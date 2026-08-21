<template>
    <div class="fct-complete">
        <!-- Success header -->
        <div class="fct-card fct-complete-hero">
            <div class="fct-complete-hero-inner">
                <svg width="56" height="56" viewBox="0 0 56 56" fill="none">
                    <circle cx="28" cy="28" r="28" fill="#ECFDF5"/>
                    <circle cx="28" cy="28" r="20" fill="#059669"/>
                    <path d="M18 28l7 7 13-13" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                </svg>
                <div>
                    <h2>{{ __('Migration Complete') }}</h2>
                    <p>{{ sprintf(__('Finished in %s'), formattedDuration) }}</p>
                </div>
            </div>
        </div>

        <!-- Summary -->
        <div class="fct-card">
            <div class="fct-card-header">
                <h2>{{ __('Migration Summary') }}</h2>
            </div>

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
                <div v-if="stepsToRun.recount" class="fct-summary-row">
                    <span class="fct-summary-row-label">{{ __('Recount & Verify') }}</span>
                    <span class="fct-summary-row-value">{{ __('Completed') }}</span>
                </div>
            </div>
        </div>

        <!-- Backward-compat notice -->
        <div class="fct-card fct-license-card">
            <div class="fct-notice fct-notice--warning fct-notice--block">
                <svg class="fct-notice-icon" width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M12 2L2 20h20L12 2z" stroke="#D97706" stroke-width="1.5" fill="#FFFBEB"/>
                    <path d="M12 10v4m0 3v.5" stroke="#D97706" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                <div>
                    <strong>{{ __('Keep this plugin active') }}</strong>
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

        <!-- Next Steps -->
        <div class="fct-card">
            <div class="fct-card-header">
                <h2>{{ __('Next Steps') }}</h2>
                <p>{{ __('Verify your migrated data and configure FluentCart.') }}</p>
            </div>

            <div class="fct-next-grid">
                <a :href="adminUrl + 'admin.php?page=fluent-cart#/products'" class="fct-next-card" target="_blank">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="3" stroke="#6B7280" stroke-width="1.5"/><path d="M8 12h8m-4-4v8" stroke="#6B7280" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <div>
                        <strong>{{ __('Verify Products') }}</strong>
                        <span>{{ __('Check that all products and pricing migrated correctly.') }}</span>
                    </div>
                </a>
                <a :href="adminUrl + 'admin.php?page=fluent-cart#/orders'" class="fct-next-card" target="_blank">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="3" stroke="#6B7280" stroke-width="1.5"/><path d="M8 9h8M8 13h5" stroke="#6B7280" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <div>
                        <strong>{{ __('Review Orders') }}</strong>
                        <span>{{ __('Spot-check a few orders to confirm data integrity.') }}</span>
                    </div>
                </a>
                <a :href="adminUrl + 'admin.php?page=fluent-cart#/customers'" class="fct-next-card" target="_blank">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="#6B7280" stroke-width="1.5"/><path d="M4 20c0-3.3 3.6-6 8-6s8 2.7 8 6" stroke="#6B7280" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <div>
                        <strong>{{ __('Check Customers') }}</strong>
                        <span>{{ __('Verify customer records and purchase history.') }}</span>
                    </div>
                </a>
                <a :href="adminUrl + 'admin.php?page=fluent-cart#/settings/store-settings/'" class="fct-next-card" target="_blank">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3" stroke="#6B7280" stroke-width="1.5"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3M4.9 4.9l2.1 2.1m9.9 9.9l2.1 2.1M4.9 19.1l2.1-2.1m9.9-9.9l2.1-2.1" stroke="#6B7280" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <div>
                        <strong>{{ __('Configure Settings') }}</strong>
                        <span>{{ __('Set up payment gateways, emails, and store options.') }}</span>
                    </div>
                </a>
            </div>
        </div>

        <!-- Error Log -->
        <div v-if="errorLogEntries.length" class="fct-card">
            <div class="fct-card-header">
                <h2>{{ __('Error Log') }}</h2>
                <button @click="showLog = !showLog" class="fct-btn fct-btn--secondary fct-btn--sm">
                    {{ showLog ? __('Hide') : __('Show') }} ({{ sprintf(_n('%s entry', '%s entries', errorLogEntries.length), errorLogEntries.length) }})
                </button>
            </div>
            <div v-if="showLog" class="fct-error-log">
                <table class="fct-table">
                    <thead>
                        <tr>
                            <th>{{ __('Payment ID') }}</th>
                            <th>{{ __('Stage') }}</th>
                            <th>{{ __('Message') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in errorLogEntries" :key="entry.paymentId">
                            <td>{{ entry.paymentId }}</td>
                            <td>{{ entry.stage || '-' }}</td>
                            <td>{{ entry.message }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Actions -->
        <div class="fct-card">
            <div class="fct-card-footer fct-card-footer--flat">
                <button @click="$emit('run-again')" class="fct-btn fct-btn--secondary">{{ __('Run Again') }}</button>
                <a :href="adminUrl + 'admin.php?page=fluent-cart#/'" class="fct-btn fct-btn--primary">{{ __('View FluentCart Dashboard') }}</a>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'MigrationComplete',
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
