<template>
    <div class="fct-intro">
        <!-- Hero -->
        <div class="fct-intro-hero">
            <div class="fct-intro-hero-icon">
                <svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="48" height="48" rx="12" fill="#EEF2FF"/>
                    <path d="M16 20h-2a2 2 0 00-2 2v4a2 2 0 002 2h2" stroke="#4F46E5" stroke-width="2" stroke-linecap="round"/>
                    <path d="M32 20h2a2 2 0 012 2v4a2 2 0 01-2 2h-2" stroke="#4F46E5" stroke-width="2" stroke-linecap="round"/>
                    <rect x="16" y="16" width="16" height="16" rx="3" stroke="#4F46E5" stroke-width="2"/>
                    <path d="M21 24h6m-3-3v6" stroke="#4F46E5" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="fct-intro-hero-content">
                <h1>FluentCart Migrator</h1>
                <p>Seamlessly migrate your eCommerce data to FluentCart. Transfer products, orders, customers, subscriptions, licenses, and more — all in a few clicks.</p>
            </div>
        </div>

        <!-- Previous Migration Summary -->
        <div v-if="hasSummary" class="fct-card fct-previous-summary">
            <div class="fct-previous-summary-header">
                <div class="fct-previous-summary-icon">
                    <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
                        <circle cx="11" cy="11" r="10" fill="#059669"/>
                        <path d="M7 11l3 3 5-5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div>
                    <h3>EDD Migration Completed</h3>
                    <p v-if="migrationSummary.completed_at">Completed on {{ formattedDate }}</p>
                </div>
            </div>

            <!-- Stats row -->
            <div v-if="summaryStats" class="fct-summary-stats-row">
                <a v-if="summaryStats.products" :href="adminUrl + 'admin.php?page=fluent-cart#/products'" class="fct-summary-stat-cell fct-summary-stat-link">
                    <span class="fct-summary-stat-value">{{ summaryStats.products }}</span>
                    <span class="fct-summary-stat-label">Products</span>
                </a>
                <a v-if="summaryStats.orders" :href="adminUrl + 'admin.php?page=fluent-cart#/orders'" class="fct-summary-stat-cell fct-summary-stat-link">
                    <span class="fct-summary-stat-value">{{ summaryStats.orders }}</span>
                    <span class="fct-summary-stat-label">Orders</span>
                </a>
                <a v-if="summaryStats.customers" :href="adminUrl + 'admin.php?page=fluent-cart#/customers'" class="fct-summary-stat-cell fct-summary-stat-link">
                    <span class="fct-summary-stat-value">{{ summaryStats.customers }}</span>
                    <span class="fct-summary-stat-label">Customers</span>
                </a>
                <a v-if="summaryStats.coupons" :href="adminUrl + 'admin.php?page=fluent-cart#/coupons'" class="fct-summary-stat-cell fct-summary-stat-link">
                    <span class="fct-summary-stat-value">{{ summaryStats.coupons }}</span>
                    <span class="fct-summary-stat-label">Coupons</span>
                </a>
                <a v-if="summaryStats.subscriptions" :href="adminUrl + 'admin.php?page=fluent-cart#/subscriptions'" class="fct-summary-stat-cell fct-summary-stat-link">
                    <span class="fct-summary-stat-value">{{ summaryStats.subscriptions }}</span>
                    <span class="fct-summary-stat-label">Subscriptions</span>
                </a>
                <a v-if="summaryStats.licenses" :href="adminUrl + 'admin.php?page=fluent-cart#/licenses'" class="fct-summary-stat-cell fct-summary-stat-link">
                    <span class="fct-summary-stat-value">{{ summaryStats.licenses }}</span>
                    <span class="fct-summary-stat-label">Licenses</span>
                </a>
            </div>

            <!-- Completed step tags -->
            <div v-if="summarySteps" class="fct-previous-summary-steps">
                <span v-if="summarySteps.products && summarySteps.products.done" class="fct-step-tag fct-step-tag--done">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3.5 7l2.5 2.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Products
                </span>
                <span v-if="summarySteps.tax_rates && summarySteps.tax_rates.done" class="fct-step-tag fct-step-tag--done">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3.5 7l2.5 2.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Tax Rates
                </span>
                <span v-if="summarySteps.coupons && summarySteps.coupons.done" class="fct-step-tag fct-step-tag--done">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3.5 7l2.5 2.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Coupons
                </span>
                <span v-if="summarySteps.payments && summarySteps.payments.done" class="fct-step-tag fct-step-tag--done">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3.5 7l2.5 2.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Orders
                    <button v-if="summarySteps.payments.errors" class="fct-step-tag-warn fct-step-tag-btn" @click.stop="toggleErrorLog">
                        ({{ summarySteps.payments.errors }} failed)
                    </button>
                </span>
                <span v-if="summarySteps.recount && summarySteps.recount.done" class="fct-step-tag fct-step-tag--done">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3.5 7l2.5 2.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Verified
                </span>
            </div>

            <!-- Failed orders log -->
            <div v-if="showErrorLog" class="fct-error-log-wrap">
                <div v-if="loadingLogs" class="fct-error-log-loading">
                    <span class="fct-spinner fct-spinner--sm"></span> Loading error log...
                </div>
                <template v-else-if="errorLogEntries.length">
                    <div class="fct-error-log">
                        <table class="fct-table">
                            <thead>
                                <tr>
                                    <th>EDD Payment ID</th>
                                    <th>Stage</th>
                                    <th>Message</th>
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
                </template>
                <p v-else class="fct-error-log-empty">No error details available.</p>
            </div>

            <!-- License backward-compat notice -->
            <div v-if="migrationSummary.has_licenses" class="fct-notice fct-notice--warning">
                <svg class="fct-notice-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
                    <path d="M10 2L1.5 17h17L10 2z" stroke="#D97706" stroke-width="1.5" fill="#FFFBEB"/>
                    <path d="M10 8v3m0 2.5v.5" stroke="#D97706" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                <div>
                    <strong>Keep this plugin active</strong> — it provides backward compatibility for:
                    <ul style="margin: 6px 0 0; padding-left: 18px; list-style-type: disc;">
                        <li><strong>License API</strong> — activate, deactivate, and check-license endpoints still route here</li>
                        <li><strong>PayPal IPN</strong> — renewal notifications for existing subscriptions</li>
                        <li><strong>Stripe webhooks</strong> — charge ID resolution for legacy orders</li>
                        <li><strong>Download &amp; renewal URLs</strong> — legacy EDD links won't resolve without it</li>
                    </ul>
                </div>
            </div>

            <div class="fct-previous-summary-actions">
                <a :href="adminUrl + 'admin.php?page=fluent-cart#/'" class="fct-btn fct-btn--success">
                    View FluentCart Dashboard
                </a>
                <button v-if="isDevMode" class="fct-link-danger" @click="$emit('reset')">
                    Reset Migration
                </button>
            </div>
        </div>

        <!-- Source Selection -->
        <div class="fct-card">
            <div class="fct-card-header">
                <h2>Select Migration Source</h2>
                <p>Choose the platform you want to migrate data from.</p>
            </div>

            <!-- Loading skeleton -->
            <div v-if="loading" class="fct-source-grid">
                <div v-for="n in 2" :key="n" class="fct-source-card is-skeleton">
                    <div class="fct-skeleton fct-skeleton--icon"></div>
                    <div class="fct-skeleton fct-skeleton--text"></div>
                    <div class="fct-skeleton fct-skeleton--badge"></div>
                </div>
            </div>

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
                        <img v-if="source.key === 'edd'" :src="pluginUrl + 'assets/images/edd-logo.png'" alt="Easy Digital Downloads" class="fct-source-logo" />
                        <img v-else-if="source.key === 'woocommerce'" :src="pluginUrl + 'assets/images/woo-logo.png'" alt="WooCommerce" class="fct-source-logo" />
                        <img v-else-if="source.key === 'surecart'" :src="pluginUrl + 'assets/images/surecart-logo.svg'" alt="SureCart" class="fct-source-logo" />
                        <svg v-else width="40" height="40" viewBox="0 0 40 40" fill="none">
                            <rect width="40" height="40" rx="10" fill="#E5E7EB"/>
                            <path d="M14 16h12v8H14z" stroke="#9CA3AF" stroke-width="1.5"/>
                            <path d="M18 20h4" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h3>{{ source.name }}</h3>
                    <span v-if="source.coming_soon" class="fct-badge fct-badge--muted">Coming Soon</span>
                    <span v-else-if="source.detected" class="fct-badge fct-badge--success">Detected</span>
                    <span v-else class="fct-badge fct-badge--muted">Not Found</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { apiRequest } from '../api.js';

export default {
    name: 'IntroSection',
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
            showErrorLog: false,
            loadingLogs: false,
            errorLogEntries: []
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
        }
    },
    methods: {
        onSourceClick: function (source) {
            if (!source.detected || source.coming_soon) return;
            this.$emit('select-source', source);
        },
        toggleErrorLog: function () {
            this.showErrorLog = !this.showErrorLog;
            if (this.showErrorLog && !this.errorLogEntries.length) {
                this.fetchErrorLog();
            }
        },
        fetchErrorLog: async function () {
            this.loadingLogs = true;
            try {
                var data = await apiRequest('GET', 'logs');
                var logs = data.logs || {};
                if (Array.isArray(logs)) {
                    this.errorLogEntries = logs.map(function (entry, i) {
                        if (typeof entry === 'string') return { paymentId: i, message: entry };
                        return entry;
                    });
                } else {
                    this.errorLogEntries = Object.entries(logs).map(function (pair) {
                        var id = pair[0];
                        var d = pair[1];
                        var obj = { paymentId: id };
                        if (typeof d === 'string') {
                            obj.message = d;
                        } else {
                            Object.assign(obj, d);
                        }
                        return obj;
                    });
                }
            } catch (_) {
                this.errorLogEntries = [];
            } finally {
                this.loadingLogs = false;
            }
        }
    }
};
</script>
