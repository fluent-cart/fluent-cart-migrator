<template>
    <div class="fct-intro">
        <!-- Hero -->
        <div class="fct-card fct-card-border">
            <div class="fct-card-body fct-intro-hero">
                <div class="fct-intro-hero-icon">
                    <svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect class="fct-fill-info-soft" width="48" height="48" rx="12"/>
                        <path class="fct-stroke-info" d="M16 20h-2a2 2 0 00-2 2v4a2 2 0 002 2h2" stroke-width="2" stroke-linecap="round"/>
                        <path class="fct-stroke-info" d="M32 20h2a2 2 0 012 2v4a2 2 0 01-2 2h-2" stroke-width="2" stroke-linecap="round"/>
                        <rect class="fct-stroke-info" x="16" y="16" width="16" height="16" rx="3" stroke-width="2"/>
                        <path class="fct-stroke-info" d="M21 24h6m-3-3v6" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="fct-intro-hero-content">
                    <h1>{{ __('FluentCart Migrator') }}</h1>
                    <p>{{ __('Seamlessly migrate your eCommerce data to FluentCart. Transfer products, orders, customers, subscriptions, licenses, and more — all in a few clicks.') }} <a href="https://docs.fluentcart.com/guide/migration" target="_blank" rel="noopener noreferrer">{{ __('Read the documentation →') }}</a></p>
                </div>
            </div>
        </div>

        <!-- Previous Migration Summary -->
        <div v-if="hasSummary" class="fct-card fct-card-border fct-previous-summary">
            <div class="fct-card-body">
                <div class="fct-previous-summary-header">
                    <div class="fct-previous-summary-icon">
                        <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
                            <circle class="fct-fill-success" cx="11" cy="11" r="10"/>
                            <path class="fct-stroke-on-solid" d="M7 11l3 3 5-5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div>
                        <h3>{{ sprintf(__('%s Migration Completed'), sourceLabel) }}</h3>
                        <p v-if="migrationSummary.completed_at">{{ sprintf(__('Completed on %s'), formattedDate) }}</p>
                    </div>
                </div>

                <!-- Stats row -->
                <div v-if="summaryStats" class="fct-summary-stats-row">
                    <a v-if="summaryStats.products" :href="adminUrl + 'admin.php?page=fluent-cart#/products'" class="fct-summary-stat-cell fct-summary-stat-link">
                        <span class="fct-summary-stat-value">{{ summaryStats.products }}</span>
                        <span class="fct-summary-stat-label">{{ __('Products') }}</span>
                    </a>
                    <a v-if="summaryStats.orders" :href="adminUrl + 'admin.php?page=fluent-cart#/orders'" class="fct-summary-stat-cell fct-summary-stat-link">
                        <span class="fct-summary-stat-value">{{ summaryStats.orders }}</span>
                        <span class="fct-summary-stat-label">{{ __('Orders') }}</span>
                    </a>
                    <a v-if="summaryStats.customers" :href="adminUrl + 'admin.php?page=fluent-cart#/customers'" class="fct-summary-stat-cell fct-summary-stat-link">
                        <span class="fct-summary-stat-value">{{ summaryStats.customers }}</span>
                        <span class="fct-summary-stat-label">{{ __('Customers') }}</span>
                    </a>
                    <a v-if="summaryStats.coupons" :href="adminUrl + 'admin.php?page=fluent-cart#/coupons'" class="fct-summary-stat-cell fct-summary-stat-link">
                        <span class="fct-summary-stat-value">{{ summaryStats.coupons }}</span>
                        <span class="fct-summary-stat-label">{{ __('Coupons') }}</span>
                    </a>
                    <a v-if="summaryStats.subscriptions" :href="adminUrl + 'admin.php?page=fluent-cart#/subscriptions'" class="fct-summary-stat-cell fct-summary-stat-link">
                        <span class="fct-summary-stat-value">{{ summaryStats.subscriptions }}</span>
                        <span class="fct-summary-stat-label">{{ __('Subscriptions') }}</span>
                    </a>
                    <a v-if="summaryStats.licenses" :href="adminUrl + 'admin.php?page=fluent-cart#/licenses'" class="fct-summary-stat-cell fct-summary-stat-link">
                        <span class="fct-summary-stat-value">{{ summaryStats.licenses }}</span>
                        <span class="fct-summary-stat-label">{{ __('Licenses') }}</span>
                    </a>
                </div>

                <!-- Completed step tags -->
                <div v-if="summarySteps" class="fct-previous-summary-steps">
                    <span v-if="summarySteps.products && summarySteps.products.done" class="fct-step-tag fct-step-tag--done">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3.5 7l2.5 2.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ __('Products') }}
                    </span>
                    <span v-if="summarySteps.taxonomies && summarySteps.taxonomies.done" class="fct-step-tag fct-step-tag--done">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3.5 7l2.5 2.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ __('Taxonomies') }}
                    </span>
                    <span v-if="summarySteps.tax_rates && summarySteps.tax_rates.done" class="fct-step-tag fct-step-tag--done">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3.5 7l2.5 2.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ __('Tax Rates') }}
                    </span>
                    <span v-if="summarySteps.coupons && summarySteps.coupons.done" class="fct-step-tag fct-step-tag--done">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3.5 7l2.5 2.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ __('Coupons') }}
                    </span>
                    <span v-if="summarySteps.payments && summarySteps.payments.done" class="fct-step-tag fct-step-tag--done">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3.5 7l2.5 2.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ __('Orders') }}
                        <button v-if="skippedCount" class="fct-step-tag-warn fct-step-tag-btn is-skipped" @click.stop="toggleErrorLog">
                            ({{ sprintf(_n('%s skipped', '%s skipped', skippedCount), skippedCount) }})
                        </button>
                        <button v-if="failedCount" class="fct-step-tag-warn fct-step-tag-btn" @click.stop="toggleErrorLog">
                            ({{ sprintf(_n('%s failed', '%s failed', failedCount), failedCount) }})
                        </button>
                    </span>
                    <span v-if="summarySteps.reviews && summarySteps.reviews.done" class="fct-step-tag fct-step-tag--done">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3.5 7l2.5 2.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ __('Reviews') }}
                        <template v-if="reviewSummary">
                            ({{ sprintf(__('%s migrated'), reviewSummary.migrated || 0) }})
                            <button v-if="reviewSummary.skipped_replies" class="fct-step-tag-warn fct-step-tag-btn is-skipped" @click.stop="toggleErrorLog">
                                ({{ sprintf(_n('%s reply not imported', '%s replies not imported', reviewSummary.skipped_replies), reviewSummary.skipped_replies) }})
                            </button>
                            <button v-if="reviewOtherSkips" class="fct-step-tag-warn fct-step-tag-btn is-skipped" @click.stop="toggleErrorLog">
                                ({{ sprintf(_n('%s skipped', '%s skipped', reviewOtherSkips), reviewOtherSkips) }})
                            </button>
                            <button v-if="reviewSummary.failed" class="fct-step-tag-warn fct-step-tag-btn" @click.stop="toggleErrorLog">
                                ({{ sprintf(_n('%s failed', '%s failed', reviewSummary.failed), reviewSummary.failed) }})
                            </button>
                        </template>
                    </span>
                    <span v-if="summarySteps.recount && summarySteps.recount.done" class="fct-step-tag fct-step-tag--done">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3.5 7l2.5 2.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ __('Verified') }}
                    </span>
                </div>

                <!-- Skipped / failed records report -->
                <div v-if="showErrorLog" class="fct-error-log-wrap">
                    <MigrationLogPanel :source="summarySource" />
                </div>

                <!-- Backward-compat notice (EDD only — the migrator routes legacy EDD endpoints) -->
                <div v-if="isEdd" class="fct-alert fct-alert-warning fct-migrator-alert">
                    <div class="fct-alert-icon"><DynamicIcon name="Warning"/></div>
                    <div class="fct-alert-content">
                        <strong class="fct-alert-heading">{{ __('Keep this plugin active') }}</strong> — {{ __('it provides backward compatibility for:') }}
                        <ul class="fct-alert-list">
                            <li v-if="migrationSummary.has_licenses"><strong>{{ __('License API') }}</strong> — {{ __('activate, deactivate, and check-license endpoints still route here') }}</li>
                            <li><strong>{{ __('PayPal IPN') }}</strong> — {{ __('renewal notifications for existing subscriptions') }}</li>
                            <li><strong>{{ __('Stripe webhooks') }}</strong> — {{ __('charge ID resolution for legacy orders') }}</li>
                            <li><strong>{{ __('Download & renewal URLs') }}</strong> — {{ __("legacy EDD links won't resolve without it") }}</li>
                        </ul>
                    </div>
                </div>

                <!-- WooCommerce: only the Stripe charge-id fallback needs the plugin -->
                <div v-else-if="isWoo" class="fct-alert fct-alert-warning fct-migrator-alert">
                    <div class="fct-alert-icon"><DynamicIcon name="Warning"/></div>
                    <div class="fct-alert-content">
                        <strong class="fct-alert-heading">{{ __('Keep this plugin active') }}</strong> — {{ __('it provides backward compatibility for:') }}
                        <ul class="fct-alert-list">
                            <li><strong>{{ __('Stripe webhooks') }}</strong> — {{ __('charge ID resolution for migrated orders') }}</li>
                        </ul>
                    </div>
                </div>

                <div class="fct-previous-summary-actions">
                    <el-button tag="a" type="success" :href="adminUrl + 'admin.php?page=fluent-cart#/'">
                        {{ __('View FluentCart Dashboard') }}
                    </el-button>
                </div>
            </div>
        </div>

        <!-- Source Selection -->
        <div class="fct-card fct-card-border">
            <div class="fct-card-header">
                <div class="flex-1">
                    <h2 class="fct-card-header-title">{{ __('Select Migration Source') }}</h2>
                    <p class="fct-card-header-text">{{ __('Choose the platform you want to migrate data from.') }}</p>
                </div>
            </div>
            <div class="fct-card-body">

                <!-- Loading skeleton -->
                <el-skeleton v-if="loading" animated class="fct-source-grid">
                    <template #template>
                        <div v-for="n in 2" :key="n" class="fct-source-card is-skeleton">
                            <el-skeleton-item variant="circle" class="fct-skeleton--icon" />
                            <el-skeleton-item variant="text" class="fct-skeleton--text" />
                            <el-skeleton-item variant="text" class="fct-skeleton--badge" />
                        </div>
                    </template>
                </el-skeleton>

                <!-- Actual source cards -->
                <div v-else class="fct-source-grid">
                    <div
                        v-for="source in sources"
                        :key="source.key"
                        class="fct-source-card"
                        :class="{
                            'is-detected': source.detected && !source.coming_soon,
                            'is-disabled': !source.detected || source.coming_soon
                        }"
                        @click="onSourceClick(source)"
                    >
                        <div class="fct-source-card-icon">
                            <img v-if="source.key === 'edd'" :src="pluginUrl + 'assets/images/edd-logo.png'" alt="Easy Digital Downloads" class="fct-source-logo is-mono" />
                            <img v-else-if="source.key === 'woocommerce'" :src="pluginUrl + 'assets/images/woo-logo.png'" alt="WooCommerce" class="fct-source-logo" />
                            <img v-else-if="source.key === 'surecart'" :src="pluginUrl + 'assets/images/surecart-logo.svg'" alt="SureCart" class="fct-source-logo is-mono" />
                            <svg v-else width="40" height="40" viewBox="0 0 40 40" fill="none">
                                <rect class="fct-fill-muted-soft" width="40" height="40" rx="10"/>
                                <path class="fct-stroke-muted" d="M14 16h12v8H14z" stroke-width="1.5"/>
                                <path class="fct-stroke-muted" d="M18 20h4" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h3>{{ source.name }}</h3>
                        <span v-if="source.coming_soon" class="badge info small">{{ __('Coming Soon') }}</span>
                        <span v-else-if="source.detected" class="badge success small">{{ __('Detected') }}</span>
                        <span v-else class="badge info small">{{ __('Not Found') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { i18nMixin } from '../i18n.js';
import MigrationLogPanel from './MigrationLogPanel.vue';
import { DynamicIcon } from '@fluent-cart/ui';

export default {
    mixins: [i18nMixin],
    name: 'IntroSection',
    components: {
        DynamicIcon: DynamicIcon,
        MigrationLogPanel: MigrationLogPanel
    },
    props: {
        sources: { type: Array, default: function () { return []; } },
        migrationSummary: { type: Object, default: null },
        loading: { type: Boolean, default: false },
        adminUrl: { type: String, default: '' },
        pluginUrl: { type: String, default: '' },
        isDevMode: { type: Boolean, default: false }
    },
    emits: ['select-source', 'reset'],
    data: function () {
        return {
            showErrorLog: false
        };
    },
    computed: {
        hasSummary: function () {
            return !!this.migrationSummary;
        },
        summaryStats: function () {
            if (!this.migrationSummary || !this.migrationSummary.stats) return null;
            return this.migrationSummary.stats;
        },
        summarySteps: function () {
            if (!this.migrationSummary || !this.migrationSummary.steps) return null;
            return this.migrationSummary.steps;
        },
        // Older summaries carry only `done`, so the counts are optional.
        reviewSummary: function () {
            var r = this.summarySteps && this.summarySteps.reviews;
            return r && r.migrated !== undefined ? r : null;
        },
        // Dropped replies are skips too, but they get their own label.
        reviewOtherSkips: function () {
            var r = this.reviewSummary;
            return r ? Math.max(0, (parseInt(r.skipped, 10) || 0) - (parseInt(r.skipped_replies, 10) || 0)) : 0;
        },
        // Newer sources split the error count into skipped (expected,
        // unmigratable data) and failed (real errors); EDD only reports the
        // total, which is shown as "failed" like before.
        skippedCount: function () {
            var p = this.summarySteps && this.summarySteps.payments;
            return p ? (parseInt(p.skipped, 10) || 0) : 0;
        },
        failedCount: function () {
            var p = this.summarySteps && this.summarySteps.payments;
            if (!p) return 0;
            if (p.failed !== undefined || p.skipped !== undefined) {
                return parseInt(p.failed, 10) || 0;
            }
            return parseInt(p.errors, 10) || 0;
        },
        formattedDate: function () {
            if (!this.migrationSummary || !this.migrationSummary.completed_at) return '';
            var d = new Date(this.migrationSummary.completed_at.replace(' ', 'T'));
            if (isNaN(d.getTime())) return this.migrationSummary.completed_at;
            return d.toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        },
        eddSource: function () {
            return this.sources.find(function (s) { return s.key === 'edd'; }) || null;
        },
        summarySource: function () {
            return (this.migrationSummary && this.migrationSummary.source) || 'edd';
        },
        isEdd: function () {
            return this.summarySource === 'edd';
        },
        isWoo: function () {
            return this.summarySource === 'woocommerce';
        },
        sourceLabel: function () {
            var labels = { edd: 'EDD', woocommerce: 'WooCommerce', surecart: 'SureCart' };
            var key = this.summarySource;
            if (labels[key]) return labels[key];
            // Fall back to the matching source card's display name, else the raw key.
            var match = this.sources.find(function (s) { return s.key === key; });
            return (match && match.name) || key;
        }
    },
    methods: {
        onSourceClick: function (source) {
            if (!source.detected || source.coming_soon) return;
            this.$emit('select-source', source);
        },
        toggleErrorLog: function () {
            this.showErrorLog = !this.showErrorLog;
        }
    }
};
</script>
