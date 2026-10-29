<template>
    <div class="fct-log-panel">
        <div v-if="loading && !loaded" class="fct-error-log-loading">
            <span class="fct-spinner fct-spinner--sm"></span> {{ __('Loading report...') }}
        </div>

        <template v-else>
            <!-- Header: totals + actions -->
            <div class="fct-log-panel-head">
                <div class="fct-log-panel-summary">
                    <strong>{{ sprintf(_n('%s record not migrated', '%s records not migrated', counts.total), counts.total) }}</strong>
                    <span v-if="counts.total" class="fct-log-panel-breakdown">
                        &middot; <span class="fct-text-warn">{{ sprintf(_n('%s skipped', '%s skipped', counts.skipped), counts.skipped) }}</span>
                        &middot; <span :class="counts.failed ? 'fct-text-danger' : ''">{{ sprintf(_n('%s failed', '%s failed', counts.failed), counts.failed) }}</span>
                    </span>
                    <span v-if="hasOrderTotals" class="fct-log-panel-breakdown">
                        &middot; {{ sprintf(__('%1$s of %2$s orders migrated'), totals.migrated_orders, totals.source_orders) }}
                    </span>
                </div>
                <div class="fct-log-panel-actions">
                    <el-button size="small" :disabled="loading" @click="load">
                        {{ loading ? __('Refreshing...') : __('Refresh') }}
                    </el-button>
                    <el-button tag="a" type="primary" size="small" v-if="counts.total" :href="exportUrl" download>
                        {{ __('Download CSV report') }}
                    </el-button>
                </div>
            </div>

            <p v-if="error" class="fct-log-panel-error">{{ error }}</p>

            <p v-else-if="!counts.total" class="fct-error-log-empty">
                {{ __('Nothing was skipped — every record was migrated.') }}
            </p>

            <template v-else>
                <p class="fct-log-panel-note">
                    {{ __('Skipped records are source data that cannot be migrated as-is (expected). Failed records hit an unexpected error and need attention.') }}
                </p>

                <!-- Grouped by reason -->
                <div v-if="groups.length" class="fct-log-groups">
                    <div v-for="group in groups" :key="group.key" class="fct-log-group" :class="'is-' + group.severity">
                        <button type="button" class="fct-log-group-head" @click="toggleGroup(group.key)">
                            <span class="badge small" :class="group.severity === 'failed' ? 'danger' : 'warning'">
                                {{ group.severity === 'failed' ? __('Failed') : __('Skipped') }}
                            </span>
                            <strong class="fct-log-group-title">{{ group.title }}</strong>
                            <span class="fct-log-group-count">{{ countLabel(group) }}</span>
                            <DynamicIcon name="ChevronDown" class="fct-log-group-chevron" :class="{ 'is-open': isOpen(group.key) }" />
                        </button>
                        <p class="fct-log-group-hint">{{ group.hint }}</p>

                        <div v-if="isOpen(group.key)" class="fct-error-log">
                            <el-table :data="visibleEntries(group)" row-key="key" class="fct-log-table">
                                <template v-if="group.type === 'customer'">
                                    <el-table-column :label="__('User ID')" min-width="90">
                                        <template #default="{ row }">
                                            <a v-if="row.context.url" :href="row.context.url" target="_blank" rel="noopener">#{{ row.id }}</a><span v-else>#{{ row.id }}</span>
                                        </template>
                                    </el-table-column>
                                    <el-table-column :label="__('Name')" min-width="120">
                                        <template #default="{ row }">{{ row.context.name || '-' }}</template>
                                    </el-table-column>
                                    <el-table-column :label="__('Email')" min-width="160">
                                        <template #default="{ row }">{{ row.context.email || '-' }}</template>
                                    </el-table-column>
                                    <el-table-column :label="__('Registered')" min-width="110">
                                        <template #default="{ row }"><span class="fct-nowrap">{{ row.context.date || '-' }}</span></template>
                                    </el-table-column>
                                    <el-table-column :label="__('Details')" min-width="200">
                                        <template #default="{ row }"><span class="fct-log-message">{{ row.message }}</span></template>
                                    </el-table-column>
                                </template>
                                <template v-else-if="group.type === 'review'">
                                    <el-table-column :label="__('Review')" min-width="90">
                                        <template #default="{ row }">
                                            <a v-if="row.context.url" :href="row.context.url" target="_blank" rel="noopener">#{{ row.id }}</a><span v-else>#{{ row.id }}</span>
                                        </template>
                                    </el-table-column>
                                    <el-table-column :label="__('Product ID')" min-width="90">
                                        <template #default="{ row }">{{ row.context.product_id || '-' }}</template>
                                    </el-table-column>
                                    <el-table-column :label="__('Reviewer')" min-width="150">
                                        <template #default="{ row }">{{ row.context.name || row.context.email || '-' }}</template>
                                    </el-table-column>
                                    <el-table-column :label="__('Date')" min-width="110">
                                        <template #default="{ row }"><span class="fct-nowrap">{{ row.context.date || '-' }}</span></template>
                                    </el-table-column>
                                    <el-table-column :label="__('Details')" min-width="200">
                                        <template #default="{ row }"><span class="fct-log-message">{{ row.message }}</span></template>
                                    </el-table-column>
                                </template>
                                <template v-else>
                                    <el-table-column :label="__('Order')" min-width="110">
                                        <template #default="{ row }">
                                            <a v-if="row.context.url" :href="row.context.url" target="_blank" rel="noopener">#{{ row.context.number || row.id }}</a>
                                            <span v-else>#{{ row.context.number || row.id }}</span>
                                            <span v-if="row.context.number && row.context.number !== row.id" class="fct-log-sub">({{ sprintf(__('ID %s'), row.id) }})</span>
                                        </template>
                                    </el-table-column>
                                    <el-table-column :label="__('Date')" min-width="110">
                                        <template #default="{ row }"><span class="fct-nowrap">{{ row.context.date || '-' }}</span></template>
                                    </el-table-column>
                                    <el-table-column :label="__('Status')" min-width="100">
                                        <template #default="{ row }">{{ row.context.status || '-' }}</template>
                                    </el-table-column>
                                    <el-table-column :label="__('Total')" min-width="90">
                                        <template #default="{ row }"><span class="fct-nowrap">{{ formatTotal(row) }}</span></template>
                                    </el-table-column>
                                    <el-table-column :label="__('Customer')" min-width="150">
                                        <template #default="{ row }">{{ row.context.email || row.context.name || '-' }}</template>
                                    </el-table-column>
                                    <el-table-column :label="__('Details')" min-width="200">
                                        <template #default="{ row }"><span class="fct-log-message">{{ row.message }}</span></template>
                                    </el-table-column>
                                </template>
                            </el-table>
                            <div v-if="remaining(group) > 0" class="fct-log-more">
                                <el-button size="small" @click="showMore(group.key)">
                                    {{ sprintf(__('Show more (%s remaining)'), remaining(group)) }}
                                </el-button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Flat fallback (sources without reason codes, e.g. EDD) -->
                <div v-else class="fct-error-log">
                    <el-table :data="flatVisible" row-key="key" class="fct-log-table">
                        <el-table-column prop="id" :label="__('Record ID')" min-width="100" />
                        <el-table-column :label="__('Stage')" min-width="100">
                            <template #default="{ row }">{{ row.stage || '-' }}</template>
                        </el-table-column>
                        <el-table-column :label="__('Message')" min-width="240">
                            <template #default="{ row }"><span class="fct-log-message">{{ row.message }}</span></template>
                        </el-table-column>
                    </el-table>
                    <div v-if="entries.length > flatLimit" class="fct-log-more">
                        <el-button size="small" @click="flatLimit += pageSize">
                            {{ sprintf(__('Show more (%s remaining)'), entries.length - flatLimit) }}
                        </el-button>
                    </div>
                </div>
            </template>
        </template>
    </div>
</template>

<script>
import { apiRequest, apiUrl } from '../api.js';
import { __, _n, sprintf, i18nMixin } from '../i18n.js';
import { DynamicIcon } from '@fluent-cart/ui';

/**
 * Skip / failure report for the current migration source. Loads GET /logs,
 * shows the totals, one collapsible group per reason (with the affected
 * records) and a CSV download link. Used on the running screen (live) and on
 * the post-migration summary.
 */
export default {
    mixins: [i18nMixin],
    name: 'MigrationLogPanel',
    components: {
        DynamicIcon: DynamicIcon
    },
    props: {
        // Explicit source key; falls back to the api.js current source.
        source: { type: String, default: '' }
    },
    emits: ['loaded'],
    data: function () {
        return {
            loading: false,
            loaded: false,
            error: null,
            entries: [],
            groups: [],
            counts: { total: 0, skipped: 0, failed: 0, orders: 0, customers: 0, reviews: 0 },
            totals: {},
            openGroups: {},
            limits: {},
            flatLimit: 50,
            pageSize: 50
        };
    },
    computed: {
        exportUrl: function () {
            return apiUrl('logs/export', this.source, true);
        },
        hasOrderTotals: function () {
            // A report holding only reviews says nothing about orders.
            var reviewsOnly = this.counts.total > 0 && this.counts.total === this.counts.reviews;
            return this.totals && typeof this.totals.source_orders === 'number' && !reviewsOnly;
        },
        flatVisible: function () {
            return this.entries.slice(0, this.flatLimit);
        }
    },
    mounted: function () {
        this.load();
    },
    methods: {
        load: async function () {
            this.loading = true;
            this.error = null;
            try {
                var data = await apiRequest('GET', 'logs', null, this.source);
                this.entries = Array.isArray(data.entries) ? data.entries : this.normalizeLegacy(data.logs);
                this.groups = Array.isArray(data.groups) ? data.groups : [];
                this.counts = data.counts || { total: this.entries.length, skipped: 0, failed: this.entries.length, orders: this.entries.length, customers: 0 };
                this.totals = data.totals || {};
                // Open the first group by default so the reasons are visible at a glance.
                if (this.groups.length && !Object.keys(this.openGroups).length) {
                    this.openGroups[this.groups[0].key] = true;
                }
                this.loaded = true;
                this.$emit('loaded', this.counts);
            } catch (e) {
                this.error = __('Could not load the report:') + ' ' + e.message;
            } finally {
                this.loading = false;
            }
        },
        // Old-style EDD log: { id: message } or { id: { message, stage } }.
        normalizeLegacy: function (logs) {
            if (!logs || typeof logs !== 'object') return [];
            var list = Array.isArray(logs) ? logs.map(function (v, i) { return [i, v]; }) : Object.entries(logs);
            return list.map(function (pair) {
                var d = pair[1];
                var obj = { key: String(pair[0]), id: String(pair[0]), type: 'order', severity: 'failed', code: '', message: '', stage: '', context: {} };
                if (typeof d === 'string') {
                    obj.message = d;
                } else if (d && typeof d === 'object') {
                    obj.message = d.message || '';
                    obj.stage = d.stage || '';
                }
                return obj;
            });
        },
        groupEntries: function (group) {
            return this.entries.filter(function (e) {
                return e.type === group.type && (e.code || '') === (group.code || '');
            });
        },
        visibleEntries: function (group) {
            var limit = this.limits[group.key] || this.pageSize;
            return this.groupEntries(group).slice(0, limit);
        },
        remaining: function (group) {
            var limit = this.limits[group.key] || this.pageSize;
            return Math.max(0, group.count - limit);
        },
        showMore: function (key) {
            this.limits[key] = (this.limits[key] || this.pageSize) + this.pageSize;
        },
        toggleGroup: function (key) {
            this.openGroups[key] = !this.openGroups[key];
        },
        isOpen: function (key) {
            return !!this.openGroups[key];
        },
        countLabel: function (group) {
            if (group.type === 'customer') {
                return sprintf(_n('%s customer', '%s customers', group.count), group.count);
            }
            if (group.type === 'review') {
                return sprintf(_n('%s review', '%s reviews', group.count), group.count);
            }
            return sprintf(_n('%s order', '%s orders', group.count), group.count);
        },
        formatTotal: function (entry) {
            var c = entry.context || {};
            if (!c.total) return '-';
            return c.total + (c.currency ? ' ' + c.currency : '');
        }
    }
};
</script>
