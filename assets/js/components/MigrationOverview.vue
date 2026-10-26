<template>
    <div class="fct-overview">
        <!-- Stats card -->
        <div class="fct-card fct-card-border">
            <div class="fct-card-header">
                <div class="flex-1">
                    <h2 class="fct-card-header-title">{{ __('Pre-Migration Overview') }}</h2>
                    <p class="fct-card-header-text">{{ sprintf(__('Here is a summary of the data that will be migrated from %s.'), sourceName) }}</p>
                </div>
            </div>
            <div class="fct-card-body">

                <!-- Skeleton while loading -->
                <el-skeleton v-if="loading" animated :rows="4" />

                <template v-else-if="stats">
                    <!-- Resume banner -->
                    <div v-if="hasExistingMigration" class="fct-alert fct-migrator-alert">
                        <div class="fct-alert-icon"><DynamicIcon name="Information"/></div>
                        <div class="fct-alert-content">
                            <strong class="fct-alert-heading">{{ __('Previous migration detected.') }}</strong> {{ __('Some steps may already be completed. You can re-run individual steps below.') }}
                            <el-button type="danger" link v-if="isDevMode" @click="$emit('reset')">{{ __('Reset Migration') }}</el-button>
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
                        <div v-if="stats.reviews_count" class="fct-stat-card">
                            <span class="fct-stat-value">{{ stats.reviews_count }}</span>
                            <span class="fct-stat-label">{{ __('Reviews') }}</span>
                        </div>
                    </div>

                    <div class="fct-stats-meta">
                        <p><strong>{{ __('Payment Gateways:') }}</strong> {{ stats.gateways.join(', ') || __('None') }}</p>
                        <p><strong>{{ __('Order Statuses:') }}</strong> {{ stats.statuses.join(', ') || __('None') }}</p>
                    </div>
                </template>
            </div>
        </div>

        <!-- Taxonomy mapping -->
        <TaxonomyMapper
            v-if="stats && !loading"
            :source="source"
            @change="onTaxonomyMapChange"
        />

        <!-- Config card -->
        <div v-if="stats && !loading" class="fct-card fct-card-border">
            <div class="fct-card-header">
                <div class="flex-1">
                    <h2 class="fct-card-header-title">{{ __('Migration Steps') }}</h2>
                    <p class="fct-card-header-text">{{ __('Select which steps to run. Completed steps will be skipped automatically.') }}</p>
                </div>
            </div>
            <div class="fct-card-body">

                <div class="fct-config-group">
                    <div class="fct-config-checks">
                        <el-checkbox class="fct-check" v-model="localSteps.products">
                            <span class="fct-check-label">
                                {{ __('Products') }}
                                <span v-if="isStepDone('products')" class="badge success small">{{ __('Completed') }}</span>
                            </span>
                        </el-checkbox>
                        <el-checkbox class="fct-check" v-model="localSteps.taxonomies">
                            <span class="fct-check-label">
                                {{ __('Product Taxonomies') }}
                                <span v-if="isStepDone('taxonomies')" class="badge success small">{{ __('Completed') }}</span>
                            </span>
                        </el-checkbox>
                        <el-checkbox class="fct-check" v-model="localSteps.tax_rates">
                            <span class="fct-check-label">
                                {{ __('Tax Rates') }}
                                <span v-if="isStepDone('tax_rates')" class="badge success small">{{ __('Completed') }}</span>
                            </span>
                        </el-checkbox>
                        <el-checkbox class="fct-check" v-model="localSteps.coupons">
                            <span class="fct-check-label">
                                {{ __('Coupons') }}
                                <span v-if="isStepDone('coupons')" class="badge success small">{{ __('Completed') }}</span>
                            </span>
                        </el-checkbox>
                        <el-checkbox class="fct-check" v-model="localSteps.payments">
                            <span class="fct-check-label">
                                {{ __('Orders, Payments, Customers') }}
                                <span v-if="stats.has_subscriptions">, {{ __('Subscriptions') }}</span>
                                <span v-if="stats.has_licenses">, {{ __('Licenses') }}</span>
                                <span v-if="isStepDone('payments')" class="badge success small">{{ __('Completed') }}</span>
                            </span>
                        </el-checkbox>
                        <el-checkbox v-if="stats.customers_breakdown && stats.customers_breakdown.missing > 0" class="fct-check" v-model="localSteps.missing_customers">
                            <span class="fct-check-label">
                                {{ __('Missing Customers') }} ({{ sprintf(__('%s without orders'), stats.customers_breakdown.missing) }})
                                <span v-if="isStepDone('missing_customers')" class="badge success small">{{ __('Completed') }}</span>
                            </span>
                        </el-checkbox>
                        <el-checkbox v-if="stats.reviews_count > 0" class="fct-check" :class="{ 'fct-check--disabled': !reviewsAvailable }" v-model="localSteps.reviews" :disabled="!reviewsAvailable">
                            <span class="fct-check-label">
                                {{ __('Product Reviews') }} ({{ stats.reviews_count }})
                                <span v-if="isStepDone('reviews')" class="badge success small">{{ __('Completed') }}</span>
                            </span>
                        </el-checkbox>
                        <div v-if="stats.reviews_count > 0 && !reviewsAvailable" class="fct-alert fct-alert-error fct-migrator-alert">
                            <div class="fct-alert-icon"><DynamicIcon name="CrossCircle"/></div>
                            <div class="fct-alert-content">
                                <strong class="fct-alert-heading">{{ __('Product reviews cannot be migrated yet') }}</strong>
                                <p>{{ reviewsUnavailableMessage }}</p>
                            </div>
                        </div>
                        <div v-else-if="droppedRepliesCount > 0" class="fct-alert fct-migrator-alert">
                            <div class="fct-alert-icon"><DynamicIcon name="Information"/></div>
                            <div class="fct-alert-content">
                                <strong class="fct-alert-heading">{{ sprintf(__('%s replies will not be imported'), droppedRepliesCount) }}</strong>
                                <p>{{ droppedRepliesMessage }}</p>
                            </div>
                        </div>
                        <el-checkbox class="fct-check" v-model="localSteps.recount">
                            <span class="fct-check-label">
                                {{ __('Recount & Verify') }}
                                <span v-if="isStepDone('recount')" class="badge success small">{{ __('Completed') }}</span>
                            </span>
                        </el-checkbox>
                    </div>
                </div>
            </div>
            <div class="fct-card-footer fct-migrator-actions">
                <el-button @click="$emit('go-back')">{{ __('Back') }}</el-button>
                <el-button type="primary" @click="onStart">
                    {{ hasExistingMigration ? __('Resume Migration') : __('Start Migration') }}
                </el-button>
            </div>
        </div>

        <!-- CLI hint (sources with a WP-CLI command: EDD, WooCommerce) -->
        <div v-if="stats && !loading && cliCommand" class="fct-card fct-card-border fct-cli-hint">
            <div class="fct-card-header">
                <div class="flex-1">
                    <h2 class="fct-card-header-title">{{ __('WP-CLI (Recommended for Large Stores)') }}</h2>
                    <p class="fct-card-header-text">{{ __('For stores with thousands of orders, running via WP-CLI is faster and avoids browser timeouts.') }}</p>
                </div>
            </div>
            <div class="fct-card-body">
                <div class="fct-cli-commands">
                    <div class="fct-cli-row">
                        <span class="fct-cli-label">{{ __('Full migration') }}</span>
                        <code class="copyable-content fct-cli-code" :title="__('Copy to clipboard')">{{ cliCommand }} --all</code>
                    </div>
                    <div class="fct-cli-row">
                        <span class="fct-cli-label">{{ __('Step by step') }}</span>
                        <code class="copyable-content fct-cli-code" :title="__('Copy to clipboard')">{{ cliCommand }} --products</code>
                    </div>
                    <div class="fct-cli-row">
                        <span class="fct-cli-label"></span>
                        <code class="copyable-content fct-cli-code" :title="__('Copy to clipboard')">{{ cliCommand }} --tax_rates</code>
                    </div>
                    <div class="fct-cli-row">
                        <span class="fct-cli-label"></span>
                        <code class="copyable-content fct-cli-code" :title="__('Copy to clipboard')">{{ cliCommand }} --coupons</code>
                    </div>
                    <div class="fct-cli-row">
                        <span class="fct-cli-label"></span>
                        <code class="copyable-content fct-cli-code" :title="__('Copy to clipboard')">{{ cliCommand }} --payments</code>
                    </div>
                    <div v-if="stats.reviews_count" class="fct-cli-row">
                        <span class="fct-cli-label"></span>
                        <code class="copyable-content fct-cli-code" :title="__('Copy to clipboard')">{{ cliCommand }} --reviews</code>
                    </div>
                    <div class="fct-cli-row">
                        <span class="fct-cli-label"></span>
                        <code class="copyable-content fct-cli-code" :title="__('Copy to clipboard')">{{ cliCommand }} --recount</code>
                    </div>
                    <div class="fct-cli-row">
                        <span class="fct-cli-label">{{ __('Check stats') }}</span>
                        <code class="copyable-content fct-cli-code" :title="__('Copy to clipboard')">{{ cliCommand }} --stats</code>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { __, i18nMixin } from '../i18n.js';
import TaxonomyMapper from './TaxonomyMapper.vue';
import { DynamicIcon } from '@fluent-cart/ui';

export default {
    mixins: [i18nMixin],
    name: 'MigrationOverview',
    components: {
        DynamicIcon: DynamicIcon,
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
            // TaxonomyMapper only emits `change` after its GET /taxonomies
            // succeeds, so this stays false while the mapper is still loading
            // or failed to load — and the app must NOT save the (empty) map
            // then: a saved empty map means "migrate no taxonomies", which
            // would silently override the server-side defaults.
            taxonomyMapLoaded: false,
            localSteps: {
                products: true,
                taxonomies: true,
                tax_rates: true,
                coupons: true,
                payments: true,
                missing_customers: false,
                reviews: true,
                recount: true
            }
        };
    },
    computed: {
        // The reviews table ships in FluentCart core, but the migrator updates
        // on its own cycle — so a current migrator can meet an older
        // FluentCart. The server answers; the UI only reports the answer.
        reviewsAvailable: function () {
            return this.stats.reviews_available !== false;
        },
        reviewsUnavailableMessage: function () {
            return (this.stats.reviews_unavailable && this.stats.reviews_unavailable.message) || '';
        },
        // Only the replies that will actually be dropped: with multiple
        // replies enabled nothing is lost, so nothing is announced.
        droppedRepliesCount: function () {
            if (this.stats.multiple_replies_allowed) {
                return 0;
            }
            return this.stats.reviews_replies_count || 0;
        },
        droppedRepliesMessage: function () {
            // "Buy Pro" is the wrong instruction for a store that has Pro and
            // turned threading off, so the two cases are worded differently.
            if (this.stats.pro_active) {
                return __('Multiple replies per review are turned off, so only the first reply on each review is imported. The rest stay in WooCommerce.');
            }
            return __('Multiple replies per review require FluentCart Pro, so only the first reply on each review is imported. The rest stay in WooCommerce.');
        },
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
    mounted: function () {
        // Core attaches its `.copyable-content` click-to-copy listener on
        // demand (useAppMixin), as its own forms do.
        if (this.registerCopyAction) {
            this.registerCopyAction();
        }
    },
    methods: {
        onTaxonomyMapChange: function (pairs) {
            this.taxonomyMap = pairs;
            this.taxonomyMapLoaded = true;
        },
        isStepDone: function (step) {
            var m = this.migrationStatus && this.migrationStatus.migration;
            if (!m) return false;
            return m[step] === 'yes';
        },
        onStart: function () {
            // A disabled checkbox keeps whatever value it had, so an install
            // that cannot store reviews would still queue the step and take a
            // server-side error. Settle it here instead.
            if (!this.reviewsAvailable || !this.stats.reviews_count) {
                this.localSteps.reviews = false;
            }

            this.$emit('start', {
                stepsToRun: JSON.parse(JSON.stringify(this.localSteps)),
                taxonomyMap: JSON.parse(JSON.stringify(this.taxonomyMap)),
                taxonomyMapLoaded: this.taxonomyMapLoaded
            });
        }
    }
};
</script>
