<template>
    <div class="fct-overview">
        <!-- Stats card -->
        <div class="fct-card">
            <div class="fct-card-header">
                <h2>{{ __('Pre-Migration Overview') }}</h2>
                <p>{{ sprintf(__('Here is a summary of the data that will be migrated from %s.'), sourceName) }}</p>
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
                        <strong>{{ __('Previous migration detected.') }}</strong> {{ __('Some steps may already be completed. You can re-run individual steps below.') }}
                        <button v-if="isDevMode" @click="$emit('reset')" class="fct-link-danger">{{ __('Reset Migration') }}</button>
                    </div>
                </div>

                <div class="fct-stats-grid">
                    <div class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.products_count }}</span>
                        <span class="fct-stat-label">{{ __('Products') }}</span>
                    </div>
                    <div class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.orders_count }}</span>
                        <span class="fct-stat-label">{{ __('Orders') }}</span>
                    </div>
                    <div class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.customers_count }}</span>
                        <span class="fct-stat-label">{{ __('Customers') }}</span>
                        <span v-if="stats.customers_breakdown && stats.customers_breakdown.missing > 0" class="fct-stat-meta">
                            {{ sprintf(__('%s without orders'), stats.customers_breakdown.missing) }}
                        </span>
                    </div>
                    <div v-if="stats.coupons_count" class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.coupons_count }}</span>
                        <span class="fct-stat-label">{{ __('Coupons') }}</span>
                    </div>
                    <div v-if="stats.has_subscriptions" class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.subscriptions_count }}</span>
                        <span class="fct-stat-label">{{ __('Subscriptions') }}</span>
                    </div>
                    <div v-if="stats.has_licenses" class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.licenses_count }}</span>
                        <span class="fct-stat-label">{{ __('Licenses') }}</span>
                    </div>
                    <div class="fct-stat-card">
                        <span class="fct-stat-value">{{ stats.transactions_count }}</span>
                        <span class="fct-stat-label">{{ __('Transactions') }}</span>
                    </div>
                </div>

                <div class="fct-stats-meta">
                    <p><strong>{{ __('Payment Gateways:') }}</strong> {{ stats.gateways.join(', ') || __('None') }}</p>
                    <p><strong>{{ __('Order Statuses:') }}</strong> {{ stats.statuses.join(', ') || __('None') }}</p>
                </div>
            </template>
        </div>

        <!-- Taxonomy mapping -->
        <TaxonomyMapper
            v-if="stats && !loading"
            :source="source"
            @change="onTaxonomyMapChange"
        />

        <!-- Config card -->
        <div v-if="stats && !loading" class="fct-card">
            <div class="fct-card-header">
                <h2>{{ __('Migration Steps') }}</h2>
                <p>{{ __('Select which steps to run. Completed steps will be skipped automatically.') }}</p>
            </div>

            <div class="fct-config-group">
                <div class="fct-config-checks">
                    <label class="fct-check">
                        <input type="checkbox" v-model="localSteps.products">
                        <span class="fct-check-label">
                            {{ __('Products') }}
                            <span v-if="isStepDone('products')" class="fct-badge fct-badge--success">{{ __('Completed') }}</span>
                        </span>
                    </label>
                    <label class="fct-check">
                        <input type="checkbox" v-model="localSteps.taxonomies">
                        <span class="fct-check-label">
                            {{ __('Product Taxonomies') }}
                            <span v-if="isStepDone('taxonomies')" class="fct-badge fct-badge--success">{{ __('Completed') }}</span>
                        </span>
                    </label>
                    <label class="fct-check">
                        <input type="checkbox" v-model="localSteps.tax_rates">
                        <span class="fct-check-label">
                            {{ __('Tax Rates') }}
                            <span v-if="isStepDone('tax_rates')" class="fct-badge fct-badge--success">{{ __('Completed') }}</span>
                        </span>
                    </label>
                    <label class="fct-check">
                        <input type="checkbox" v-model="localSteps.coupons">
                        <span class="fct-check-label">
                            {{ __('Coupons') }}
                            <span v-if="isStepDone('coupons')" class="fct-badge fct-badge--success">{{ __('Completed') }}</span>
                        </span>
                    </label>
                    <label class="fct-check">
                        <input type="checkbox" v-model="localSteps.payments">
                        <span class="fct-check-label">
                            {{ __('Orders, Payments, Customers') }}
                            <span v-if="stats.has_subscriptions">, {{ __('Subscriptions') }}</span>
                            <span v-if="stats.has_licenses">, {{ __('Licenses') }}</span>
                            <span v-if="isStepDone('payments')" class="fct-badge fct-badge--success">{{ __('Completed') }}</span>
                        </span>
                    </label>
                    <label v-if="stats.customers_breakdown && stats.customers_breakdown.missing > 0" class="fct-check">
                        <input type="checkbox" v-model="localSteps.missing_customers">
                        <span class="fct-check-label">
                            {{ __('Missing Customers') }} ({{ sprintf(__('%s without orders'), stats.customers_breakdown.missing) }})
                            <span v-if="isStepDone('missing_customers')" class="fct-badge fct-badge--success">{{ __('Completed') }}</span>
                        </span>
                    </label>
                    <label class="fct-check">
                        <input type="checkbox" v-model="localSteps.recount">
                        <span class="fct-check-label">
                            {{ __('Recount & Verify') }}
                            <span v-if="isStepDone('recount')" class="fct-badge fct-badge--success">{{ __('Completed') }}</span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="fct-card-footer">
                <button @click="$emit('go-back')" class="fct-btn fct-btn--secondary">{{ __('Back') }}</button>
                <button @click="onStart" class="fct-btn fct-btn--primary">
                    {{ hasExistingMigration ? __('Resume Migration') : __('Start Migration') }}
                </button>
            </div>
        </div>

        <!-- CLI hint (sources with a WP-CLI command: EDD, WooCommerce) -->
        <div v-if="stats && !loading && cliCommand" class="fct-card fct-cli-hint">
            <div class="fct-card-header">
                <h2>{{ __('WP-CLI (Recommended for Large Stores)') }}</h2>
                <p>{{ __('For stores with thousands of orders, running via WP-CLI is faster and avoids browser timeouts.') }}</p>
            </div>
            <div class="fct-cli-commands">
                <div class="fct-cli-row">
                    <span class="fct-cli-label">{{ __('Full migration') }}</span>
                    <code class="fct-cli-code">{{ cliCommand }} --all</code>
                </div>
                <div class="fct-cli-row">
                    <span class="fct-cli-label">{{ __('Step by step') }}</span>
                    <code class="fct-cli-code">{{ cliCommand }} --products</code>
                </div>
                <div class="fct-cli-row">
                    <span class="fct-cli-label"></span>
                    <code class="fct-cli-code">{{ cliCommand }} --tax_rates</code>
                </div>
                <div class="fct-cli-row">
                    <span class="fct-cli-label"></span>
                    <code class="fct-cli-code">{{ cliCommand }} --coupons</code>
                </div>
                <div class="fct-cli-row">
                    <span class="fct-cli-label"></span>
                    <code class="fct-cli-code">{{ cliCommand }} --payments</code>
                </div>
                <div class="fct-cli-row">
                    <span class="fct-cli-label"></span>
                    <code class="fct-cli-code">{{ cliCommand }} --recount</code>
                </div>
                <div class="fct-cli-row">
                    <span class="fct-cli-label">{{ __('Check stats') }}</span>
                    <code class="fct-cli-code">{{ cliCommand }} --stats</code>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { __ } from '../i18n.js';
import TaxonomyMapper from './TaxonomyMapper.vue';

export default {
    name: 'MigrationOverview',
    components: {
        TaxonomyMapper: TaxonomyMapper
    },
    props: {
        source: { type: Object, default: null },
        stats: { type: Object, default: null },
        migrationStatus: { type: Object, default: null },
        isDevMode: { type: Boolean, default: false },
        loading: { type: Boolean, default: false }
    },
    emits: ['start', 'go-back', 'reset'],
    data: function () {
        return {
            // source→FluentCart taxonomy pairs, saved when the migration starts
            taxonomyMap: [],
            localSteps: {
                products: true,
                taxonomies: true,
                tax_rates: true,
                coupons: true,
                payments: true,
                missing_customers: false,
                recount: true
            }
        };
    },
    computed: {
        sourceName: function () {
            return (this.source && this.source.name) || __('your store');
        },
        sourceKey: function () {
            return (this.source && this.source.key) || '';
        },
        cliCommand: function () {
            // Sources that ship a WP-CLI command.
            var map = { edd: 'migrate_from_edd', woocommerce: 'migrate_from_woo' };
            var cmd = map[this.sourceKey];
            return cmd ? 'wp fluent_cart_migrator ' + cmd : '';
        },
        hasExistingMigration: function () {
            var m = this.migrationStatus && this.migrationStatus.migration;
            return !!m;
        }
    },
    methods: {
        onTaxonomyMapChange: function (pairs) {
            this.taxonomyMap = pairs;
        },
        isStepDone: function (step) {
            var m = this.migrationStatus && this.migrationStatus.migration;
            if (!m) return false;
            return m[step] === 'yes';
        },
        onStart: function () {
            this.$emit('start', {
                stepsToRun: JSON.parse(JSON.stringify(this.localSteps)),
                taxonomyMap: JSON.parse(JSON.stringify(this.taxonomyMap))
            });
        }
    }
};
</script>
