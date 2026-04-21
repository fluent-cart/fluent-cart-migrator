<template>
    <div class="fct-migrator">
        <!-- Step indicator (hidden on intro) -->
        <div v-if="currentStep !== 'intro'" class="fct-breadcrumb">
            <span
                v-for="(label, index) in visibleSteps"
                :key="label.key"
                class="fct-breadcrumb-item"
                :class="{
                    'is-active': label.key === currentStep,
                    'is-done': index < activeStepIndex
                }"
            >
                <span class="fct-breadcrumb-num">{{ index < activeStepIndex ? '\u2713' : index + 1 }}</span>
                {{ label.text }}
            </span>
        </div>

        <!-- Error banner -->
        <div v-if="error" class="fct-alert fct-alert--error">
            <p>{{ error }}</p>
            <button @click="error = null" class="fct-alert-dismiss">&times;</button>
        </div>

        <!-- Views -->
        <IntroSection
            v-if="currentStep === 'intro'"
            :sources="sources"
            :migration-summary="migrationSummary"
            :loading="loading"
            :admin-url="adminUrl"
            :plugin-url="pluginUrl"
            :is-dev-mode="isDevMode"
            @select-source="onSelectSource"
            @reset="onResetRequest"
        />

        <CompatibilityCheck
            v-if="currentStep === 'compatibility'"
            :source="selectedSource"
            @continue="goToStep('overview')"
            @go-back="goToStep('intro')"
        />

        <MigrationOverview
            v-if="currentStep === 'overview'"
            :stats="stats"
            :migration-status="migrationStatus"
            :is-dev-mode="isDevMode"
            :loading="loading"
            @start="onStartMigration"
            @go-back="goToStep('compatibility')"
            @reset="onResetRequest"
        />

        <MigrationRunner
            v-if="currentStep === 'running'"
            :key="runKey"
            :steps-to-run="stepsToRun"
            :stats="stats"
            :migration-status="migrationStatus"
            :initial-progress="savedRunProgress"
            @complete="onMigrationComplete"
            @error="onMigrationError"
        />

        <!-- Confirm modal -->
        <ConfirmModal
            v-if="confirmModal.show"
            :title="confirmModal.title"
            :message="confirmModal.message"
            :items="confirmModal.items"
            :confirm-text="confirmModal.confirmText"
            @confirm="onConfirmAction"
            @cancel="confirmModal.show = false"
        />
    </div>
</template>

<script>
import { apiRequest } from './api.js';
import IntroSection from './components/IntroSection.vue';
import CompatibilityCheck from './components/CompatibilityCheck.vue';
import MigrationOverview from './components/MigrationOverview.vue';
import MigrationRunner from './components/MigrationRunner.vue';
import ConfirmModal from './components/ConfirmModal.vue';

var STORAGE_KEY = 'fctMigratorState';

export default {
    name: 'MigratorApp',
    components: {
        IntroSection: IntroSection,
        CompatibilityCheck: CompatibilityCheck,
        MigrationOverview: MigrationOverview,
        MigrationRunner: MigrationRunner,
        ConfirmModal: ConfirmModal
    },
    data: function () {
        return {
            currentStep: 'intro',
            sources: [],
            selectedSource: null,
            stats: null,
            migrationStatus: null,
            error: null,
            loading: false,
            isDevMode: !!window.fctMigrator.devMode,
            adminUrl: window.fctMigrator.adminUrl,
            pluginUrl: window.fctMigrator.pluginUrl || '',
            migrationSummary: window.fctMigrator.migrationSummary || null,

            // Migration config
            stepsToRun: {},

            // Runner state for resume
            savedRunProgress: null,
            runKey: 0,

            // Confirm modal
            confirmModal: {
                show: false,
                title: '',
                message: '',
                items: [],
                confirmText: 'Confirm',
                action: null
            }
        };
    },
    computed: {
        visibleSteps: function () {
            return [
                { key: 'compatibility', text: 'Compatibility' },
                { key: 'overview', text: 'Overview' },
                { key: 'running', text: 'Migrating' }
            ];
        },
        activeStepIndex: function () {
            for (var i = 0; i < this.visibleSteps.length; i++) {
                if (this.visibleSteps[i].key === this.currentStep) return i;
            }
            return 0;
        }
    },
    mounted: function () {
        this.init();
    },
    methods: {
        init: async function () {
            this.loading = true;
            await this.loadSources();

            // Check for session-saved state (e.g. page refresh during migration)
            var saved = this.loadSessionState();
            if (saved && saved.source && saved.step === 'running' && saved.runProgress) {
                this.selectedSource = saved.source;
                this.savedRunProgress = saved.runProgress;
                this.stepsToRun = saved.stepsToRun || {};
                this.goToStep('running');
            } else if (this.migrationSummary || (window.fctMigrator.migration && this.sources.length)) {
                // Has migration data — stay on intro showing summary
                this.currentStep = 'intro';
            }

            this.loading = false;
        },

        loadSources: async function (retries) {
            if (retries === undefined) retries = 2;
            try {
                var data = await apiRequest('GET', 'sources');
                this.sources = data.sources;
            } catch (e) {
                if (retries > 0) {
                    var self = this;
                    await new Promise(function (r) { setTimeout(r, 1000); });
                    return self.loadSources(retries - 1);
                }
                this.error = 'Failed to load sources: ' + e.message;
            }
        },

        loadStats: async function () {
            this.loading = true;
            this.error = null;
            try {
                var results = await Promise.all([
                    apiRequest('GET', 'stats/' + this.selectedSource.key),
                    apiRequest('GET', 'status')
                ]);
                this.stats = results[0];
                this.migrationStatus = results[1];
            } catch (e) {
                this.error = 'Failed to load stats: ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        fetchSummary: async function () {
            try {
                var data = await apiRequest('GET', 'migration-summary');
                this.migrationSummary = data.summary || null;
            } catch (_) {
                // Ignore
            }
        },

        onSelectSource: function (source) {
            this.selectedSource = source;
            this.goToStep('compatibility');
        },

        goToStep: function (step) {
            this.currentStep = step;
            this.saveSessionState();

            if (step === 'overview') {
                this.loadStats();
            }
        },

        onStartMigration: async function (config) {
            this.error = null;
            try {
                await apiRequest('GET', 'can-migrate');
            } catch (e) {
                this.error = e.message;
                return;
            }

            this.stepsToRun = config.stepsToRun;
            this.savedRunProgress = null;
            this.runKey++;
            this.goToStep('running');
        },

        onMigrationComplete: async function () {
            sessionStorage.removeItem(STORAGE_KEY);
            // Fetch the server-saved summary and go to intro
            await this.fetchSummary();
            this.currentStep = 'intro';
        },

        onMigrationError: function (message) {
            this.error = message;
        },

        // Session persistence
        saveSessionState: function () {
            var state = {
                step: this.currentStep,
                source: this.selectedSource
            };
            if (this.currentStep === 'running') {
                state.runProgress = this.savedRunProgress;
                state.stepsToRun = this.stepsToRun;
            }
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        },

        loadSessionState: function () {
            try {
                var raw = sessionStorage.getItem(STORAGE_KEY);
                return raw ? JSON.parse(raw) : null;
            } catch (_) {
                return null;
            }
        },

        // Reset
        onResetRequest: function () {
            this.confirmModal.title = 'Reset Migration';
            this.confirmModal.message = 'This will permanently delete all migrated data from FluentCart, including:';
            this.confirmModal.items = [
                'Products and variations',
                'Orders and transactions',
                'Customers and subscriptions',
                'Coupons',
                'Migration progress'
            ];
            this.confirmModal.confirmText = 'Yes, Reset Everything';
            this.confirmModal.action = 'reset';
            this.confirmModal.show = true;
        },

        onConfirmAction: async function () {
            this.confirmModal.show = false;
            if (this.confirmModal.action === 'reset') {
                await this.doReset();
            }
        },

        doReset: async function () {
            this.loading = true;
            try {
                await apiRequest('POST', 'reset');
                this.migrationStatus = { migration: null, failed_log_count: 0 };
                this.migrationSummary = null;
                this.currentStep = 'intro';
            } catch (e) {
                this.error = 'Reset failed: ' + e.message;
            } finally {
                this.loading = false;
            }
        }
    }
};
</script>
