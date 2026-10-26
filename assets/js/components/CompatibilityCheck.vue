<template>
    <div class="fct-card fct-card-border">
        <div class="fct-card-header">
            <h2 class="fct-card-header-title">{{ __('Compatibility Check') }}</h2>
        </div>
        <div class="fct-card-body">

            <div v-if="versionState === 'pass'" class="fct-alert fct-alert-success fct-migrator-alert">
                <div class="fct-alert-icon"><DynamicIcon name="CheckCircle"/></div>
                <div class="fct-alert-content">
                    <h3 class="fct-alert-title">{{ compat.title }}</h3>
                    <p>{{ compat.message }}</p>
                </div>
            </div>

            <div v-else-if="versionState === 'blocked'" class="fct-alert fct-alert-error fct-migrator-alert">
                <div class="fct-alert-icon"><DynamicIcon name="CrossCircle"/></div>
                <div class="fct-alert-content">
                    <h3 class="fct-alert-title">{{ compat.title }}</h3>
                    <p v-html="compat.message"></p>
                </div>
            </div>

            <div v-else-if="versionState === 'data_only'" class="fct-alert fct-migrator-alert">
                <div class="fct-alert-icon"><DynamicIcon name="Information"/></div>
                <div class="fct-alert-content">
                    <h3 class="fct-alert-title">{{ compat.title }}</h3>
                    <p>{{ compat.message }}</p>
                </div>
            </div>
        </div>

        <div class="fct-card-footer fct-migrator-actions">
            <el-button @click="$emit('go-back')">{{ __('Back') }}</el-button>
            <el-button v-if="versionState !== 'blocked'" type="primary" @click="$emit('continue')">{{ __('Continue') }}</el-button>
        </div>
    </div>
</template>

<script>
import { __, sprintf, i18nMixin } from '../i18n.js';
import { DynamicIcon } from '@fluent-cart/ui';

export default {
    mixins: [i18nMixin],
    name: 'CompatibilityCheck',
    components: {
        DynamicIcon: DynamicIcon
    },
    props: {
        source: { type: Object, required: true }
    },
    emits: ['continue', 'go-back'],
    computed: {
        // Each source declares its own compatibility verdict in detect().
        // Fall back to the legacy EDD-shaped logic for older payloads.
        compat: function () {
            var src = this.source;
            if (!src) {
                return { state: 'unknown', title: __('Unknown source'), message: '' };
            }
            if (src.compatibility && src.compatibility.state) {
                return src.compatibility;
            }
            return this.legacyEddCompat(src);
        },
        versionState: function () {
            return this.compat.state;
        }
    },
    methods: {
        legacyEddCompat: function (src) {
            if (src.has_v3_tables && src.version) {
                return {
                    state: 'pass',
                    /* translators: %s: EDD version number */
                    title: sprintf(__('EDD 3.x detected (v%s)'), src.version),
                    message: __('Your Easy Digital Downloads installation is compatible with the migration tool.')
                };
            }
            if (src.has_v3_tables && !src.version) {
                return {
                    state: 'data_only',
                    title: __('EDD data found'),
                    message: __('EDD is not currently active, but migration data (v3 tables) was detected. You can proceed.')
                };
            }
            return {
                state: 'blocked',
                /* translators: %s: EDD version number */
                title: sprintf(__('EDD %s detected'), src.version || __('(unknown version)')),
                message: __('Migration requires <strong>EDD 3.0 or later</strong>. Please upgrade EDD first, then return here.')
            };
        }
    }
};
</script>
