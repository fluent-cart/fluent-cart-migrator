<template>
    <div class="fct-card">
        <div class="fct-card-header">
            <h2>Compatibility Check</h2>
        </div>

        <div v-if="versionState === 'pass'" class="fct-compat-box fct-compat-box--pass">
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                <circle cx="16" cy="16" r="14" fill="#ECFDF5"/>
                <path d="M10 16l4 4 8-8" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <div>
                <h3>EDD 3.x detected (v{{ source.version }})</h3>
                <p>Your Easy Digital Downloads installation is compatible with the migration tool.</p>
            </div>
        </div>

        <div v-else-if="versionState === 'blocked'" class="fct-compat-box fct-compat-box--blocked">
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                <circle cx="16" cy="16" r="14" fill="#FEF2F2"/>
                <path d="M12 12l8 8m0-8l-8 8" stroke="#DC2626" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
            <div>
                <h3>EDD {{ source.version || '(unknown version)' }} detected</h3>
                <p>Migration requires <strong>EDD 3.0 or later</strong>. Please upgrade EDD first, then return here.</p>
            </div>
        </div>

        <div v-else-if="versionState === 'data_only'" class="fct-compat-box fct-compat-box--info">
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                <circle cx="16" cy="16" r="14" fill="#EEF2FF"/>
                <path d="M16 11v6m0 4v.5" stroke="#4F46E5" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
            <div>
                <h3>EDD data found</h3>
                <p>EDD is not currently active, but migration data (v3 tables) was detected. You can proceed.</p>
            </div>
        </div>

        <div class="fct-card-footer">
            <button @click="$emit('go-back')" class="fct-btn fct-btn--secondary">Back</button>
            <button
                v-if="versionState !== 'blocked'"
                @click="$emit('continue')"
                class="fct-btn fct-btn--primary"
            >Continue</button>
        </div>
    </div>
</template>

<script>
export default {
    name: 'CompatibilityCheck',
    props: {
        source: { type: Object, required: true }
    },
    emits: ['continue', 'go-back'],
    computed: {
        versionState: function () {
            var src = this.source;
            if (!src) return 'unknown';
            if (src.has_v3_tables && src.version) return 'pass';
            if (src.has_v3_tables && !src.version) return 'data_only';
            return 'blocked';
        }
    }
};
</script>
