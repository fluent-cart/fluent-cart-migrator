<template>
    <div class="fct-overview">
        <!-- Stats card -->
        <div class="fct-card">
            <div class="fct-card-header">
                <h2>Pre-Migration Overview</h2>
                <p>Here is a summary of the data that will be migrated from EDD.</p>
            </div>

            <!-- Skeleton while loading -->
            <div v-if="loading" class="fct-skeleton fct-skeleton--block"></div>

            <template v-else-if="stats">
                <!-- Resume banner -->
                <div v-if="hasExistingMigration" class="fct-notice fct-notice--info">
                    <svg class="fct-notice-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="8" fill="#EEF2FF"/>
                        <path d="M10 6v5m0 2.5v.5" stroke="#4F46E5" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <div>
                        <strong>Previous migration detected.</strong> Some steps may already be completed. You can re-run individual steps below.
                        <button v-if="isDevMode" @click="$emit('reset')" class="fct-link-danger">Reset Migration</button>
                    </div>
                </div>

                <div class="fct-stats-grid">
                    <div class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.products_count }}</span>
                        <span class="fct-stat-label">Products</span>
                    </div>
                    <div class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.orders_count }}</span>
                        <span class="fct-stat-label">Orders</span>
                    </div>
                    <div class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.customers_count }}</span>
                        <span class="fct-stat-label">Customers</span>
                    </div>
                    <div v-if="stats.coupons_count" class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.coupons_count }}</span>
                        <span class="fct-stat-label">Coupons</span>
                    </div>
                    <div v-if="stats.has_subscriptions" class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.subscriptions_count }}</span>
                        <span class="fct-stat-label">Subscriptions</span>
                    </div>
                    <div v-if="stats.has_licenses" class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.licenses_count }}</span>
                        <span class="fct-stat-label">Licenses</span>
                    </div>
                    <div class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.transactions_count }}</span>
                        <span class="fct-stat-label">Transactions</span>
                    </div>
                </div>

                <div class="fct-stats-meta">
                    <p><strong>Payment Gateways:</strong> {{ stats.gateways.join(', ') || 'None' }}</p>
                    <p><strong>Order Statuses:</strong> {{ stats.statuses.join(', ') || 'None' }}</p>
                </div>
            </template>
        </div>

        <!-- Config card -->
        <div v-if="stats && !loading" class="fct-card">
            <div class="fct-card-header">
                <h2>Migration Steps</h2>
                <p>Select which steps to run. Completed steps will be skipped automatically.</p>
            </div>

            <div class="fct-config-group">
                <div class="fct-config-checks">
                    <label class="fct-check">
                        <input type="checkbox" v-model="localSteps.products">
                        <span class="fct-check-label">
                            Products
                            <span v-if="isStepDone('products')" class="fct-badge fct-badge--success">Completed</span>
                        </span>
                    </label>
                    <label class="fct-check">
                        <input type="checkbox" v-model="localSteps.tax_rates">
                        <span class="fct-check-label">
                            Tax Rates
                            <span v-if="isStepDone('tax_rates')" class="fct-badge fct-badge--success">Completed</span>
                        </span>
                    </label>
                    <label class="fct-check">
                        <input type="checkbox" v-model="localSteps.coupons">
                        <span class="fct-check-label">
                            Coupons
                            <span v-if="isStepDone('coupons')" class="fct-badge fct-badge--success">Completed</span>
                        </span>
                    </label>
                    <label class="fct-check">
                        <input type="checkbox" v-model="localSteps.payments">
                        <span class="fct-check-label">
                            Orders, Payments, Customers
                            <span v-if="stats.has_subscriptions">, Subscriptions</span>
                            <span v-if="stats.has_licenses">, Licenses</span>
                            <span v-if="isStepDone('payments')" class="fct-badge fct-badge--success">Completed</span>
                        </span>
                    </label>
                    <label class="fct-check">
                        <input type="checkbox" v-model="localSteps.recount">
                        <span class="fct-check-label">
                            Recount &amp; Verify
                            <span v-if="isStepDone('recount')" class="fct-badge fct-badge--success">Completed</span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="fct-card-footer">
                <button @click="$emit('go-back')" class="fct-btn fct-btn--secondary">Back</button>
                <button @click="onStart" class="fct-btn fct-btn--primary">
                    {{ hasExistingMigration ? 'Resume Migration' : 'Start Migration' }}
                </button>
            </div>
        </div>

        <!-- CLI hint -->
        <div v-if="stats && !loading" class="fct-card fct-cli-hint">
            <div class="fct-card-header">
                <h2>WP-CLI (Recommended for Large Stores)</h2>
                <p>For stores with thousands of orders, running via WP-CLI is faster and avoids browser timeouts.</p>
            </div>
            <div class="fct-cli-commands">
                <div class="fct-cli-row">
                    <span class="fct-cli-label">Full migration</span>
                    <code class="fct-cli-code">wp fluent_cart_migrator migrate_from_edd --all</code>
                </div>
                <div class="fct-cli-row">
                    <span class="fct-cli-label">Step by step</span>
                    <code class="fct-cli-code">wp fluent_cart_migrator migrate_from_edd --products</code>
                </div>
                <div class="fct-cli-row">
                    <span class="fct-cli-label"></span>
                    <code class="fct-cli-code">wp fluent_cart_migrator migrate_from_edd --tax_rates</code>
                </div>
                <div class="fct-cli-row">
                    <span class="fct-cli-label"></span>
                    <code class="fct-cli-code">wp fluent_cart_migrator migrate_from_edd --coupons</code>
                </div>
                <div class="fct-cli-row">
                    <span class="fct-cli-label"></span>
                    <code class="fct-cli-code">wp fluent_cart_migrator migrate_from_edd --payments</code>
                </div>
                <div class="fct-cli-row">
                    <span class="fct-cli-label"></span>
                    <code class="fct-cli-code">wp fluent_cart_migrator migrate_from_edd --recount</code>
                </div>
                <div class="fct-cli-row">
                    <span class="fct-cli-label">Check stats</span>
                    <code class="fct-cli-code">wp fluent_cart_migrator migrate_from_edd --stats</code>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'MigrationOverview',
    props: {
        stats: { type: Object, default: null },
        migrationStatus: { type: Object, default: null },
        isDevMode: { type: Boolean, default: false },
        loading: { type: Boolean, default: false }
    },
    emits: ['start', 'go-back', 'reset'],
    data: function () {
        return {
            localSteps: {
                products: true,
                tax_rates: true,
                coupons: true,
                payments: true,
                recount: true
            }
        };
    },
    computed: {
        hasExistingMigration: function () {
            var m = this.migrationStatus && this.migrationStatus.migration;
            return !!m;
        }
    },
    methods: {
        isStepDone: function (step) {
            var m = this.migrationStatus && this.migrationStatus.migration;
            if (!m) return false;
            return m[step] === 'yes';
        },
        onStart: function () {
            this.$emit('start', {
                stepsToRun: JSON.parse(JSON.stringify(this.localSteps))
            });
        }
    }
};
</script>
