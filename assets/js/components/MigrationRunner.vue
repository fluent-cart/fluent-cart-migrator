<template>
    <div class="fct-card">
        <div class="fct-card-header">
            <h2>{{ __('Migration in Progress') }}</h2>
            <p class="fct-duration">{{ sprintf(__('Elapsed: %s'), formattedDuration) }}</p>
        </div>

        <div class="fct-runner-steps">
            <!-- Products -->
            <div v-if="stepsToRun.products" class="fct-runner-row" :class="statusClass(progress.products.status)">
                <span class="fct-runner-icon">
                    <svg v-if="progress.products.status === 'completed'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#ECFDF5"/><path d="M6 10l3 3 5-5" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span v-else-if="progress.products.status === 'running'" class="fct-spinner"></span>
                    <svg v-else-if="progress.products.status === 'error'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#FEF2F2"/><path d="M7 7l6 6m0-6l-6 6" stroke="#DC2626" stroke-width="2" stroke-linecap="round"/></svg>
                    <span v-else class="fct-runner-pending"></span>
                </span>
                <div class="fct-runner-detail">
                    <strong>{{ __('Products') }}</strong>
                    <span v-if="progress.products.status === 'completed'" class="fct-runner-meta">
                        {{ sprintf(__('%s migrated'), progress.products.migrated) }}<span v-if="progress.products.failed">, {{ sprintf(_n('%s failed', '%s failed', progress.products.failed), progress.products.failed) }}</span>
                    </span>
                </div>
            </div>

            <!-- Product Taxonomies -->
            <div v-if="stepsToRun.taxonomies" class="fct-runner-row" :class="statusClass(progress.taxonomies.status)">
                <span class="fct-runner-icon">
                    <svg v-if="progress.taxonomies.status === 'completed'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#ECFDF5"/><path d="M6 10l3 3 5-5" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span v-else-if="progress.taxonomies.status === 'running'" class="fct-spinner"></span>
                    <svg v-else-if="progress.taxonomies.status === 'error'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#FEF2F2"/><path d="M7 7l6 6m0-6l-6 6" stroke="#DC2626" stroke-width="2" stroke-linecap="round"/></svg>
                    <span v-else class="fct-runner-pending"></span>
                </span>
                <div class="fct-runner-detail">
                    <strong>{{ __('Product Taxonomies') }}</strong>
                    <span v-if="progress.taxonomies.status === 'completed'" class="fct-runner-meta">
                        <template v-if="progress.taxonomies.message">{{ progress.taxonomies.message }}</template>
                        <template v-else>{{ sprintf(_n('%s product updated', '%s products updated', progress.taxonomies.updated), progress.taxonomies.updated) }}</template>
                    </span>
                </div>
            </div>

            <!-- Tax Rates -->
            <div v-if="stepsToRun.tax_rates" class="fct-runner-row" :class="statusClass(progress.tax_rates.status)">
                <span class="fct-runner-icon">
                    <svg v-if="progress.tax_rates.status === 'completed'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#ECFDF5"/><path d="M6 10l3 3 5-5" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span v-else-if="progress.tax_rates.status === 'running'" class="fct-spinner"></span>
                    <svg v-else-if="progress.tax_rates.status === 'error'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#FEF2F2"/><path d="M7 7l6 6m0-6l-6 6" stroke="#DC2626" stroke-width="2" stroke-linecap="round"/></svg>
                    <span v-else class="fct-runner-pending"></span>
                </span>
                <div class="fct-runner-detail">
                    <strong>{{ __('Tax Rates') }}</strong>
                    <span v-if="progress.tax_rates.status === 'completed'" class="fct-runner-meta">{{ __('Done') }}</span>
                </div>
            </div>

            <!-- Coupons -->
            <div v-if="stepsToRun.coupons" class="fct-runner-row" :class="statusClass(progress.coupons.status)">
                <span class="fct-runner-icon">
                    <svg v-if="progress.coupons.status === 'completed'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#ECFDF5"/><path d="M6 10l3 3 5-5" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span v-else-if="progress.coupons.status === 'running'" class="fct-spinner"></span>
                    <svg v-else-if="progress.coupons.status === 'error'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#FEF2F2"/><path d="M7 7l6 6m0-6l-6 6" stroke="#DC2626" stroke-width="2" stroke-linecap="round"/></svg>
                    <span v-else class="fct-runner-pending"></span>
                </span>
                <div class="fct-runner-detail">
                    <strong>{{ __('Coupons') }}</strong>
                    <span v-if="progress.coupons.status === 'completed'" class="fct-runner-meta">
                        {{ sprintf(__('%s migrated'), progress.coupons.migrated) }}
                    </span>
                </div>
            </div>

            <!-- Payments -->
            <div v-if="stepsToRun.payments" class="fct-runner-row" :class="statusClass(progress.payments.status)">
                <span class="fct-runner-icon">
                    <svg v-if="progress.payments.status === 'completed'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#ECFDF5"/><path d="M6 10l3 3 5-5" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span v-else-if="progress.payments.status === 'running'" class="fct-spinner"></span>
                    <svg v-else-if="progress.payments.status === 'error'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#FEF2F2"/><path d="M7 7l6 6m0-6l-6 6" stroke="#DC2626" stroke-width="2" stroke-linecap="round"/></svg>
                    <span v-else class="fct-runner-pending"></span>
                </span>
                <div class="fct-runner-detail">
                    <strong>{{ __('Orders & Payments') }}</strong>
                    <span v-if="progress.payments.status === 'running' || progress.payments.status === 'completed'" class="fct-runner-meta">
                        {{ sprintf(_n('%s order processed', '%s orders processed', progress.payments.processed), progress.payments.processed) }}
                        <template v-if="paymentLogCounts">
                            <span v-if="paymentLogCounts.skipped" class="fct-text-warn">
                                ({{ sprintf(_n('%s skipped', '%s skipped', paymentLogCounts.skipped), paymentLogCounts.skipped) }})
                            </span>
                            <span v-if="paymentLogCounts.failed" class="fct-text-danger">
                                ({{ sprintf(_n('%s failed', '%s failed', paymentLogCounts.failed), paymentLogCounts.failed) }})
                            </span>
                        </template>
                        <span v-else-if="progress.payments.errorsCount" class="fct-text-danger">
                            ({{ sprintf(_n('%s error', '%s errors', progress.payments.errorsCount), progress.payments.errorsCount) }})
                        </span>
                        <button v-if="progress.payments.errorsCount" type="button" class="fct-link-btn" @click="showLog = !showLog">
                            {{ showLog ? __('Hide details') : __('Why?') }}
                        </button>
                    </span>
                    <div v-if="showPaymentProgress" class="fct-progress">
                        <div class="fct-progress-bar">
                            <div class="fct-progress-fill" :class="{ 'is-done': progress.payments.status === 'completed' }" :style="{ width: paymentsPercent + '%' }"></div>
                        </div>
                        <span class="fct-progress-text">
                            {{ sprintf(__('%1$s of ~%2$s orders'), progress.payments.processed, totalOrders) }}
                            <span v-if="etaText"> &middot; {{ sprintf(__('~%s remaining'), etaText) }}</span>
                        </span>
                    </div>
                    <div v-if="showLog" class="fct-runner-log">
                        <MigrationLogPanel ref="logPanel" />
                    </div>
                </div>
            </div>

            <!-- Missing Customers -->
            <div v-if="stepsToRun.missing_customers" class="fct-runner-row" :class="statusClass(progress.missing_customers.status)">
                <span class="fct-runner-icon">
                    <svg v-if="progress.missing_customers.status === 'completed'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#ECFDF5"/><path d="M6 10l3 3 5-5" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span v-else-if="progress.missing_customers.status === 'running'" class="fct-spinner"></span>
                    <svg v-else-if="progress.missing_customers.status === 'error'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#FEF2F2"/><path d="M7 7l6 6m0-6l-6 6" stroke="#DC2626" stroke-width="2" stroke-linecap="round"/></svg>
                    <span v-else class="fct-runner-pending"></span>
                </span>
                <div class="fct-runner-detail">
                    <strong>{{ __('Missing Customers') }}</strong>
                    <span v-if="progress.missing_customers.status === 'completed'" class="fct-runner-meta">
                        {{ sprintf(__('%s migrated'), progress.missing_customers.migrated) }}
                        <span v-if="progress.missing_customers.skipped" class="fct-text-warn">
                            ({{ sprintf(_n('%s skipped', '%s skipped', progress.missing_customers.skipped), progress.missing_customers.skipped) }})
                        </span>
                        <button v-if="progress.missing_customers.skipped" type="button" class="fct-link-btn" @click="showLog = !showLog">
                            {{ showLog ? __('Hide details') : __('Why?') }}
                        </button>
                    </span>
                    <span v-else-if="stats && stats.customers_breakdown && stats.customers_breakdown.missing > 0" class="fct-runner-meta">
                        {{ sprintf(__('%s to migrate'), stats.customers_breakdown.missing) }}
                    </span>
                </div>
            </div>

            <!-- Product Reviews -->
            <div v-if="stepsToRun.reviews" class="fct-runner-row" :class="statusClass(progress.reviews.status)">
                <span class="fct-runner-icon">
                    <svg v-if="progress.reviews.status === 'completed'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#ECFDF5"/><path d="M6 10l3 3 5-5" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span v-else-if="progress.reviews.status === 'running'" class="fct-spinner"></span>
                    <svg v-else-if="progress.reviews.status === 'error'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#FEF2F2"/><path d="M7 7l6 6m0-6l-6 6" stroke="#DC2626" stroke-width="2" stroke-linecap="round"/></svg>
                    <span v-else class="fct-runner-pending"></span>
                </span>
                <div class="fct-runner-detail">
                    <strong>{{ __('Product Reviews') }}</strong>
                    <span v-if="progress.reviews.status === 'completed'" class="fct-runner-meta">
                        {{ sprintf(__('%s migrated'), progress.reviews.migrated) }}
                    </span>
                    <span v-else-if="progress.reviews.status === 'running'" class="fct-runner-meta">
                        <!-- The step runs in two phases; naming the current one
                             stops the rebuild reading as a stall. -->
                        {{ progress.reviews.phase === 'aggregate' ? __('Rebuilding rating summaries...') : sprintf(__('%s migrated'), progress.reviews.migrated) }}
                    </span>
                    <span v-else-if="stats && stats.reviews_count" class="fct-runner-meta">
                        {{ sprintf(__('%s to migrate'), stats.reviews_count) }}
                    </span>
                </div>
            </div>

            <!-- Recount -->
            <div v-if="stepsToRun.recount" class="fct-runner-row" :class="statusClass(progress.recount.status)">
                <span class="fct-runner-icon">
                    <svg v-if="progress.recount.status === 'completed'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#ECFDF5"/><path d="M6 10l3 3 5-5" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span v-else-if="progress.recount.status === 'running'" class="fct-spinner"></span>
                    <svg v-else-if="progress.recount.status === 'error'" width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#FEF2F2"/><path d="M7 7l6 6m0-6l-6 6" stroke="#DC2626" stroke-width="2" stroke-linecap="round"/></svg>
                    <span v-else class="fct-runner-pending"></span>
                </span>
                <div class="fct-runner-detail">
                    <strong>{{ __('Recount & Verify') }}</strong>
                    <div v-if="progress.recount.status === 'running' || progress.recount.status === 'completed'" class="fct-recount-tags">
                        <span
                            v-for="(st, name) in progress.recount.substeps"
                            :key="name"
                            class="fct-recount-tag"
                            :class="'is-' + st"
                        >
                            {{ substepLabels[name] || name }}
                            <svg v-if="st === 'completed'" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 6l2.5 2.5L9 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span v-else-if="st === 'running'" class="fct-spinner fct-spinner--sm"></span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="fct-card-footer">
            <button v-if="!paused" @click="pause" class="fct-btn fct-btn--secondary" :disabled="!running">
                {{ __('Pause') }}
            </button>
            <button v-else @click="resume" class="fct-btn fct-btn--primary">
                {{ __('Resume') }}
            </button>
        </div>
    </div>
</template>

<script>
import { apiRequest } from '../api.js';
import { __, sprintf } from '../i18n.js';
import MigrationLogPanel from './MigrationLogPanel.vue';

export default {
    name: 'MigrationRunner',
    components: {
        MigrationLogPanel: MigrationLogPanel
    },
    props: {
        stepsToRun: { type: Object, required: true },
        stats: { type: Object, default: null },
        migrationStatus: { type: Object, default: null },
        initialProgress: { type: Object, default: null }
    },
    emits: ['complete', 'error'],
    data: function () {
        var defaultProgress = {
            products: { status: 'pending', total: 0, migrated: 0, failed: 0, errors: [] },
            taxonomies: { status: 'pending', updated: 0, message: '' },
            tax_rates: { status: 'pending' },
            coupons: { status: 'pending', total: 0, migrated: 0 },
            payments: { status: 'pending', processed: 0, hasMore: true, errorsCount: 0, logCounts: null },
            missing_customers: { status: 'pending', migrated: 0, skipped: 0 },
            reviews: { status: 'pending', migrated: 0, phase: 'import', skippedReplies: 0, errorsCount: 0 },
            recount: {
                status: 'pending',
                substeps: {}
            }
        };

        return {
            // Merge over the defaults so a run resumed from an older saved
            // shape still has an entry for every step.
            progress: this.initialProgress
                ? Object.assign({}, defaultProgress, JSON.parse(JSON.stringify(this.initialProgress)))
                : defaultProgress,
            running: false,
            paused: false,
            showLog: false,
            startTime: null,
            endTime: null,
            paymentsStartTime: null,
            now: Date.now(),
            timer: null,
            substepLabels: {
                fix_reactivations: __('Reactivations'),
                fix_subs_uuid: __('Subscriptions UUID'),
                coupons: __('Coupons'),
                customers: __('Customers'),
                subscriptions: __('Subscriptions')
            }
        };
    },
    computed: {
        formattedDuration: function () {
            if (!this.startTime) return '00:00:00';
            var end = this.endTime || this.now;
            var seconds = Math.floor((end - this.startTime) / 1000);
            var h = Math.floor(seconds / 3600);
            var m = Math.floor((seconds % 3600) / 60);
            var s = seconds % 60;
            return [h, m, s].map(function (v) { return String(v).padStart(2, '0'); }).join(':');
        },
        totalOrders: function () {
            if (!this.stats) return 0;
            return this.stats.orders_count;
        },
        // Per-severity counts from the server (newer sources); null for sources
        // that only report a total error count.
        paymentLogCounts: function () {
            var c = this.progress.payments.logCounts;
            return c && typeof c === 'object' ? c : null;
        },
        recountSubsteps: function () {
            // Driven by the source (EDD vs WooCommerce vs ...). Fall back to the
            // EDD set for older status payloads that don't include the list.
            var m = this.migrationStatus;
            if (m && Array.isArray(m.recount_substeps)) {
                return m.recount_substeps;
            }
            return ['fix_reactivations', 'fix_subs_uuid', 'coupons', 'customers', 'subscriptions'];
        },
        paymentsPercent: function () {
            if (!this.totalOrders || !this.progress.payments.processed) return 0;
            return Math.min(100, Math.round((this.progress.payments.processed / this.totalOrders) * 100));
        },
        showPaymentProgress: function () {
            var st = this.progress.payments.status;
            return (st === 'running' || st === 'completed') && this.totalOrders > 0;
        },
        etaText: function () {
            if (!this.paymentsStartTime || !this.progress.payments.processed || this.progress.payments.status !== 'running') return '';
            var elapsed = this.now - this.paymentsStartTime;
            var remaining = this.totalOrders - this.progress.payments.processed;
            if (remaining <= 0) return '';
            var msPerOrder = elapsed / this.progress.payments.processed;
            var secs = Math.round((msPerOrder * remaining) / 1000);
            /* translators: %s: number of seconds */
            if (secs < 60) return sprintf(__('%ss'), secs);
            /* translators: %s: number of minutes */
            if (secs < 3600) return sprintf(__('%s min'), Math.round(secs / 60));
            var h = Math.floor(secs / 3600);
            var m = Math.round((secs % 3600) / 60);
            /* translators: 1: hours, 2: minutes */
            return sprintf(__('%1$sh %2$sm'), h, m);
        }
    },
    mounted: function () {
        this.timer = setInterval(this.tick, 1000);
        this.start();
    },
    beforeUnmount: function () {
        if (this.timer) clearInterval(this.timer);
    },
    methods: {
        tick: function () {
            this.now = Date.now();
        },
        statusClass: function (status) {
            return 'is-' + status;
        },
        pause: function () {
            this.paused = true;
        },
        resume: function () {
            this.paused = false;
            this.start();
        },
        start: function () {
            var self = this;
            self.running = true;
            if (!self.startTime) {
                self.startTime = Date.now();
            }
            self.endTime = null;

            self.runPipeline().then(function () {
                if (!self.paused) {
                    self.endTime = Date.now();
                    self.running = false;
                    self.$emit('complete', {
                        progress: JSON.parse(JSON.stringify(self.progress)),
                        startTime: self.startTime,
                        endTime: self.endTime
                    });
                }
            }).catch(function (err) {
                self.endTime = Date.now();
                self.running = false;
                self.$emit('error', err.message);
            });
        },
        runPipeline: async function () {
            // Reviews run after missing_customers on purpose: reviews are
            // matched to customers by email, so the customer table should be
            // at its fullest before that lookup happens.
            var steps = ['products', 'taxonomies', 'tax_rates', 'coupons', 'payments', 'missing_customers', 'reviews', 'recount'];

            for (var i = 0; i < steps.length; i++) {
                var step = steps[i];
                if (this.paused) break;
                if (!this.stepsToRun[step]) continue;
                if (this.progress[step].status === 'completed') continue;

                this.progress[step].status = 'running';

                try {
                    if (step === 'products') {
                        await this.runProducts();
                    } else if (step === 'taxonomies') {
                        await this.runTaxonomies();
                    } else if (step === 'tax_rates') {
                        await this.runTaxRates();
                    } else if (step === 'coupons') {
                        await this.runCoupons();
                    } else if (step === 'payments') {
                        await this.runPayments();
                    } else if (step === 'missing_customers') {
                        await this.runMissingCustomers();
                    } else if (step === 'reviews') {
                        await this.runReviews();
                    } else if (step === 'recount') {
                        await this.runRecount();
                    }

                    if (!this.paused) {
                        this.progress[step].status = 'completed';
                    }
                } catch (e) {
                    this.progress[step].status = 'error';
                    throw e;
                }
            }
        },
        runProducts: async function () {
            var hasMore = true;
            var retries = 0;
            var maxRetries = 2;

            while (hasMore && !this.paused) {
                try {
                    // Server paginates + time-boxes (~25s per call); loop until
                    // the whole catalog is migrated, just like payments.
                    var result = await apiRequest('POST', 'migrate/products');
                    hasMore = result.has_more;
                    this.progress.products.migrated = this.progress.products.migrated + (result.migrated || 0);
                    this.progress.products.failed = this.progress.products.failed + (result.failed || 0);
                    if (result.errors && result.errors.length) {
                        this.progress.products.errors = this.progress.products.errors.concat(result.errors);
                    }
                    retries = 0;
                } catch (e) {
                    if (retries < maxRetries) {
                        retries++;
                        await new Promise(function (r) { setTimeout(r, 2000); });
                    } else {
                        throw e;
                    }
                }
            }
        },
        runTaxonomies: async function () {
            var hasMore = true;

            while (hasMore && !this.paused) {
                // Server paginates + time-boxes and resumes from its own saved
                // page, exactly like products and payments.
                var result = await apiRequest('POST', 'migrate/taxonomies');
                hasMore = result.has_more;
                this.progress.taxonomies.updated += (result.updated || 0);
                if (result.message) {
                    this.progress.taxonomies.message = result.message;
                }
            }
        },
        runTaxRates: async function () {
            await apiRequest('POST', 'migrate/tax-rates');
        },
        runCoupons: async function () {
            var result = await apiRequest('POST', 'migrate/coupons');
            this.progress.coupons.total = result.total;
            this.progress.coupons.migrated = result.migrated;
        },
        runPayments: async function () {
            this.paymentsStartTime = Date.now();
            var hasMore = true;
            var retries = 0;
            var maxRetries = 2;

            while (hasMore && !this.paused) {
                try {
                    // Server handles pagination and time-boxing (~25s per call)
                    var result = await apiRequest('POST', 'migrate/payments');
                    hasMore = result.has_more;
                    this.progress.payments.processed = this.progress.payments.processed + result.processed;
                    this.progress.payments.hasMore = hasMore;
                    this.progress.payments.errorsCount = result.errors_in_batch;
                    if (result.log_counts) {
                        this.progress.payments.logCounts = result.log_counts;
                    }
                    this.refreshLogPanel();
                    retries = 0;
                } catch (e) {
                    if (retries < maxRetries) {
                        retries++;
                        await new Promise(function (r) { setTimeout(r, 2000); });
                    } else {
                        throw e;
                    }
                }
            }
        },
        runMissingCustomers: async function () {
            var result = await apiRequest('POST', 'migrate/missing-customers');
            this.progress.missing_customers.migrated = result.migrated || 0;
            this.progress.missing_customers.skipped = result.skipped || 0;
            if (result.log_counts) {
                this.progress.payments.logCounts = result.log_counts;
                this.progress.payments.errorsCount = result.log_counts.total;
            }
            this.refreshLogPanel();
        },
        // Two server-side phases (import, then rebuilding the rating
        // summaries) behind one row: re-post while has_more, exactly like
        // payments, and let the server own the cursor and the phase.
        runReviews: async function () {
            var total = 0;
            var result;

            do {
                if (this.paused) break;

                result = await apiRequest('POST', 'migrate/reviews');

                total += result.processed || 0;
                this.progress.reviews.migrated = total;
                this.progress.reviews.phase = result.phase || 'import';
                this.progress.reviews.skippedReplies = result.skipped_replies || 0;

                if (result.log_counts) {
                    this.progress.reviews.errorsCount = result.log_counts.total;
                }

                this.refreshLogPanel();
            } while (result && result.has_more);
        },
        // Keep the open report in sync while batches keep logging.
        refreshLogPanel: function () {
            if (this.showLog && this.$refs.logPanel && !this.$refs.logPanel.loading) {
                this.$refs.logPanel.load();
            }
        },
        runRecount: async function () {
            var substeps = this.recountSubsteps;

            // Build the per-source substep progress map up front (replaces any
            // stale EDD-shaped map) so the UI only shows this source's substeps.
            var map = {};
            for (var k = 0; k < substeps.length; k++) {
                map[substeps[k]] = 'pending';
            }
            this.progress.recount.substeps = map;

            for (var i = 0; i < substeps.length; i++) {
                if (this.paused) break;
                var sub = substeps[i];
                this.progress.recount.substeps[sub] = 'running';
                await apiRequest('POST', 'migrate/recount', { substep: sub });
                this.progress.recount.substeps[sub] = 'completed';
            }
        }
    }
};
</script>
