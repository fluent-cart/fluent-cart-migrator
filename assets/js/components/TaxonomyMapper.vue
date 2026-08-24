<template>
    <div class="fct-card fct-taxonomy">
        <div class="fct-card-header">
            <h2>{{ __('Taxonomy Mapping') }}</h2>
            <p>{{ sprintf(__('Every FluentCart product taxonomy is listed below — pick the %s taxonomy that feeds it. Rows with an empty side are ignored.'), sourceName) }}</p>
        </div>

        <div v-if="loading" class="fct-skeleton fct-skeleton--block"></div>

        <div v-else-if="error" class="fct-notice fct-notice--warning">
            <div>{{ error }}</div>
        </div>

        <template v-else>
            <div v-if="!sources.length" class="fct-notice fct-notice--info">
                <div>{{ sprintf(__('No product taxonomies were found in your %s data, so there is nothing to map.'), sourceName) }}</div>
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
                            <select v-model="row.destination" class="fct-tax-select">
                                <option value="">{{ __('— Not migrated —') }}</option>
                                <option v-for="dest in destinations" :key="dest.name" :value="dest.name">
                                    {{ dest.label }} ({{ dest.name }})
                                </option>
                            </select>

                            <span class="fct-tax-arrow" aria-hidden="true">&#8592;</span>

                            <select v-model="row.source" class="fct-tax-select">
                                <option value="">{{ __('— Ignore —') }}</option>
                                <option v-for="src in sources" :key="src.name" :value="src.name">
                                    {{ src.label }} ({{ src.name }}) &middot; {{ sprintf(_n('%s term', '%s terms', src.count), src.count) }}
                                </option>
                            </select>

                            <button type="button" class="fct-tax-remove" :title="__('Remove row')" @click="removeRow(index)">&times;</button>
                        </div>
                        <p v-if="rowStates[index].note" class="fct-tax-row-note" :class="{ 'is-warn': rowStates[index].ignored }">
                            {{ rowStates[index].note }}
                        </p>
                    </div>
                </div>

                <div class="fct-tax-footer">
                    <button type="button" class="fct-btn fct-btn--link" @click="addRow">+ {{ __('Add mapping') }}</button>
                    <span class="fct-tax-summary">{{ summary }}</span>
                </div>

                <p class="fct-tax-hint">
                    {{ __('Saved changes are applied by the “Product Taxonomies” step below — editing the mapping re-opens that step even if it already ran.') }}
                </p>
            </template>
        </template>
    </div>
</template>

<script>
import { apiRequest } from '../api.js';
import { __, _n, sprintf } from '../i18n.js';

export default {
    name: 'TaxonomyMapper',
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
