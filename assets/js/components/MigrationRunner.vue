<template>
    <div class="fct-card">
        <div class="fct-card-header">
            <h2>Migration in Progress</h2>
            <p class="fct-duration">Elapsed: {{ formattedDuration }}</p>
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
                    <strong>Products</strong>
                    <span v-if="progress.products.status === 'completed'" class="fct-runner-meta">
                        {{ progress.products.migrated }} migrated<span v-if="progress.products.failed">, {{ progress.products.failed }} failed</span>
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
                    <strong>Tax Rates</strong>
                    <span v-if="progress.tax_rates.status === 'completed'" class="fct-runner-meta">Done</span>
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
                    <strong>Coupons</strong>
                    <span v-if="progress.coupons.status === 'completed'" class="fct-runner-meta">
                        {{ progress.coupons.migrated }} migrated
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
                    <strong>Orders &amp; Payments</strong>
                    <span v-if="progress.payments.status === 'running' || progress.payments.status === 'completed'" class="fct-runner-meta">
                        {{ progress.payments.processed }} orders processed
                        <span v-if="progress.payments.errorsCount" class="fct-text-danger">
                            ({{ progress.payments.errorsCount }} errors)
                        </span>
                    </span>
                    <div v-if="showPaymentProgress" class="fct-progress">
                        <div class="fct-progress-bar">
                            <div class="fct-progress-fill" :class="{ 'is-done': progress.payments.status === 'completed' }" :style="{ width: paymentsPercent + '%' }"></div>
                        </div>
                        <span class="fct-progress-text">
                            {{ progress.payments.processed }} of ~{{ totalOrders }} orders
                            <span v-if="etaText"> &middot; ~{{ etaText }} remaining</span>
                        </span>
                    </div>
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
                    <strong>Recount &amp; Verify</strong>
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
                Pause
            </button>
            <button v-else @click="resume" class="fct-btn fct-btn--primary">
                Resume
            </button>
        </div>
    </div>
</template>

<script>
import { apiRequest } from '../api.js';

export default {
    name: 'MigrationRunner',
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
            tax_rates: { status: 'pending' },
            coupons: { status: 'pending', total: 0, migrated: 0 },
            payments: { status: 'pending', processed: 0, hasMore: true, errorsCount: 0 },
            recount: {
                status: 'pending',
                substeps: {
                    fix_reactivations: 'pending',
                    fix_subs_uuid: 'pending',
                    coupons: 'pending',
                    customers: 'pending',
                    subscriptions: 'pending'
                }
            }
        };

        return {
            progress: this.initialProgress ? JSON.parse(JSON.stringify(this.initialProgress)) : defaultProgress,
            running: false,
            paused: false,
            startTime: null,
            endTime: null,
            paymentsStartTime: null,
            now: Date.now(),
            timer: null,
            substepLabels: {
                fix_reactivations: 'Reactivations',
                fix_subs_uuid: 'Subscriptions UUID',
                coupons: 'Coupons',
                customers: 'Customers',
                subscriptions: 'Subscriptions'
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
            if (secs < 60) return secs + 's';
            if (secs < 3600) return Math.round(secs / 60) + ' min';
            var h = Math.floor(secs / 3600);
            var m = Math.round((secs % 3600) / 60);
            return h + 'h ' + m + 'm';
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
            var steps = ['products', 'tax_rates', 'coupons', 'payments', 'recount'];

            for (var i = 0; i < steps.length; i++) {
                var step = steps[i];
                if (this.paused) break;
                if (!this.stepsToRun[step]) continue;
                if (this.progress[step].status === 'completed') continue;

                this.progress[step].status = 'running';

                try {
                    if (step === 'products') {
                        await this.runProducts();
                    } else if (step === 'tax_rates') {
                        await this.runTaxRates();
                    } else if (step === 'coupons') {
                        await this.runCoupons();
                    } else if (step === 'payments') {
                        await this.runPayments();
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
            var result = await apiRequest('POST', 'migrate/products');
            this.progress.products.total = result.total;
            this.progress.products.migrated = result.migrated;
            this.progress.products.failed = result.failed;
            this.progress.products.errors = result.errors || [];
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
        runRecount: async function () {
            var substeps = ['fix_reactivations', 'fix_subs_uuid', 'coupons', 'customers', 'subscriptions'];
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
