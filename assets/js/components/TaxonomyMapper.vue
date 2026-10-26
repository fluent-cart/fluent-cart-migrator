<template>
    <div class="fct-card fct-card-border fct-taxonomy">
        <div class="fct-card-header">
            <div class="flex-1">
                <h2 class="fct-card-header-title">{{ __('Taxonomy Mapping') }}</h2>
                <p class="fct-card-header-text">{{ sprintf(__('Every FluentCart product taxonomy is listed below — pick the %s taxonomy that feeds it. Rows with an empty side are ignored.'), sourceName) }}</p>
            </div>
        </div>
        <div class="fct-card-body">

            <el-skeleton v-if="loading" animated :rows="3" />

            <div v-else-if="error" class="fct-alert fct-alert-warning fct-migrator-alert">
                <div class="fct-alert-icon"><DynamicIcon name="Warning"/></div>
                <div class="fct-alert-content">{{ error }}</div>
            </div>

            <template v-else>
                <div v-if="!sources.length" class="fct-alert fct-migrator-alert">
                    <div class="fct-alert-icon"><DynamicIcon name="Information"/></div>
                    <div class="fct-alert-content">{{ sprintf(__('No product taxonomies were found in your %s data, so there is nothing to map.'), sourceName) }}</div>
                </div>

                <template v-else>
                    <div class="fct-tax-head">
                        <span class="fct-tax-col">{{ __('FluentCart') }}</span>
                        <span class="fct-tax-arrow"></span>
                        <span class="fct-tax-col">{{ sourceName }}</span>
                        <span class="fct-tax-remove"></span>
                    </div>

                    <div class="fct-tax-rows">
                        <div v-for="(row, index) in rows" :key="index" class="fct-tax-item">
                            <div class="fct-tax-row" :class="{ 'is-ignored': rowStates[index].ignored }">
                                <el-select v-model="row.destination" class="fct-tax-select" filterable>
                                    <el-option value="" :label="__('— Not migrated —')" />
                                    <el-option v-for="dest in destinations" :key="dest.name" :value="dest.name" :label="dest.label + ' (' + dest.name + ')'" />
                                </el-select>

                                <span class="fct-tax-arrow" aria-hidden="true">&#8592;</span>

                                <el-select v-model="row.source" class="fct-tax-select" filterable>
                                    <el-option value="" :label="__('— Ignore —')" />
                                    <el-option v-for="src in sources" :key="src.name" :value="src.name" :label="src.label + ' (' + src.name + ') · ' + sprintf(_n('%s term', '%s terms', src.count), src.count)" />
                                </el-select>

                                <el-button link class="fct-tax-remove" :title="__('Remove row')" :aria-label="__('Remove row')" @click="removeRow(index)"><DynamicIcon name="Cross"/></el-button>
                            </div>
                            <p v-if="rowStates[index].note" class="fct-tax-row-note" :class="{ 'is-warn': rowStates[index].ignored }">
                                {{ rowStates[index].note }}
                            </p>
                        </div>
                    </div>

                    <div class="fct-tax-footer">
                        <el-button link @click="addRow">+ {{ __('Add mapping') }}</el-button>
                        <span class="fct-tax-summary">{{ summary }}</span>
                    </div>

                    <p class="fct-tax-hint">
                        {{ __('Saved changes are applied by the “Product Taxonomies” step below — editing the mapping re-opens that step even if it already ran.') }}
                    </p>
                </template>
            </template>
        </div>
    </div>
</template>

<script>
import { apiRequest } from '../api.js';
import { __, _n, sprintf, i18nMixin } from '../i18n.js';
import { DynamicIcon } from '@fluent-cart/ui';

export default {
    mixins: [i18nMixin],
    name: 'TaxonomyMapper',
    components: {
        DynamicIcon: DynamicIcon
    },
    props: {
        source: { type: Object, default: null }
    },
    emits: ['change'],
    data: function () {
        return {
            loading: true,
            error: null,
            destinations: [],
            sources: [],
            rows: []
        };
    },
    computed: {
        sourceName: function () {
            return (this.source && this.source.name) || __('the source store');
        },
        // Valid pairs only — both sides filled.
        validPairs: function () {
            var seen = {};
            var pairs = [];
            this.rows.forEach(function (row) {
                if (!row.destination || !row.source) return;
                var key = row.source + '|' + row.destination;
                if (seen[key]) return;
                seen[key] = true;
                pairs.push({ source: row.source, destination: row.destination });
            });
            return pairs;
        },
        /**
         * Per-row state: an exact duplicate pair is dropped (normalize() would
         * collapse it server-side anyway), and several sources aimed at one
         * FluentCart taxonomy are merged into it — both are worth saying out
         * loud rather than letting the row look like it does nothing.
         */
        rowStates: function () {
            var seen = {};
            var flags = [];
            var destCounts = {};

            // First pass: which destinations receive more than one distinct source.
            this.rows.forEach(function (row) {
                if (!row.destination || !row.source) return;
                var key = row.source + '|' + row.destination;
                if (seen[key]) return;
                seen[key] = true;
                destCounts[row.destination] = (destCounts[row.destination] || 0) + 1;
            });

            seen = {};

            var self = this;
            this.rows.forEach(function (row) {
                if (!row.destination || !row.source) {
                    flags.push({ ignored: true, note: '' });
                    return;
                }

                var key = row.source + '|' + row.destination;
                if (seen[key]) {
                    flags.push({ ignored: true, note: __('Duplicate of an earlier row — ignored.') });
                    return;
                }
                seen[key] = true;

                flags.push({
                    ignored: false,
                    note: destCounts[row.destination] > 1
                        ? sprintf(__('Merged with the other rows pointing at %s.'), self.destinationLabel(row.destination))
                        : ''
                });
            });

            return flags;
        },
        summary: function () {
            var count = this.validPairs.length;
            if (!count) {
                return __('No taxonomies will be migrated.');
            }
            return sprintf(_n('%s taxonomy will be migrated.', '%s taxonomies will be migrated.', count), count);
        }
    },
    watch: {
        validPairs: {
            handler: function (pairs) {
                this.$emit('change', pairs);
            },
            deep: true
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
                var data = await apiRequest('GET', 'taxonomies');
                this.destinations = data.destinations || [];
                this.sources = data.sources || [];
                this.rows = this.buildRows(data.map || []);
                this.$emit('change', this.validPairs);
            } catch (e) {
                this.error = __('Failed to load taxonomies:') + ' ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        destinationLabel: function (name) {
            for (var i = 0; i < this.destinations.length; i++) {
                if (this.destinations[i].name === name) return this.destinations[i].label;
            }
            return name;
        },

        /**
         * One row per FluentCart taxonomy, in the order FluentCart registers
         * them, carrying whatever is mapped into it (several rows when several
         * source taxonomies feed the same one). Pairs whose destination is no
         * longer registered are kept at the end so they stay editable.
         */
        buildRows: function (map) {
            var bySource = {};

            map.forEach(function (pair) {
                (bySource[pair.destination] = bySource[pair.destination] || []).push(pair.source);
            });

            var rows = [];

            this.destinations.forEach(function (dest) {
                var mapped = bySource[dest.name] || [];
                delete bySource[dest.name];

                if (!mapped.length) {
                    rows.push({ destination: dest.name, source: '' });
                    return;
                }

                mapped.forEach(function (source) {
                    rows.push({ destination: dest.name, source: source });
                });
            });

            Object.keys(bySource).forEach(function (destination) {
                bySource[destination].forEach(function (source) {
                    rows.push({ destination: destination, source: source });
                });
            });

            if (!rows.length) {
                rows.push({ destination: '', source: '' });
            }

            return rows;
        },

        addRow: function () {
            this.rows.push({ destination: '', source: '' });
        },

        removeRow: function (index) {
            this.rows.splice(index, 1);
            if (!this.rows.length) {
                this.addRow();
            }
        }
    }
};
</script>
