<template>
    <div class="setting-wrap fct-migrator">
        <!-- Same header as core's settings screens (Settings/Parts/SettingsHeader.vue) -->
        <div class="fct-setting-header">
            <div class="fct-setting-header-content">
                <button type="button" class="fct-setting-menu-toggle" :aria-label="__('Toggle settings navigation')" @click="toggleSettingsMenu">
                    <DynamicIcon name="Window" />
                </button>
                <h3 class="fct-setting-head-title">{{ __('Migrator') }}</h3>
            </div>
            <div class="fct-setting-header-action">
                <el-button size="small" tag="a" href="https://docs.fluentcart.com/guide/migration" target="_blank" rel="noopener noreferrer">
                    {{ __('Documentation') }}
                </el-button>
            </div>
        </div>

        <div class="setting-wrap-inner">
            <!-- Step indicator (hidden on intro) -->
            <!-- Core's onboarding step style (onboarding.scss .fct-steps-wrap) -->
            <div v-if="currentStep !== 'intro'" class="fct-steps-wrap fct-migrator-steps">
                <el-steps :active="activeStepIndex + 1" finish-status="success" simple>
                    <el-step v-for="label in visibleSteps" :key="label.key" :title="label.text" />
                </el-steps>
                <span class="fct-migrator-steps-label">
                    {{ stepLabel }}
                </span>
            </div>

            <!-- Error banner -->
            <div v-if="error" class="fct-alert fct-alert-error fct-migrator-error">
                <div class="fct-alert-content">{{ error }}</div>
                <div class="fct-alert-close" role="button" :aria-label="__('Dismiss')" @click="error = null">
                    <DynamicIcon name="Cross" />
                </div>
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
                :source="selectedSource"
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
                ref="runner"
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
    </div>
</template>

<script>
import { apiRequest, setApiSource } from './api.js';
import { __, sprintf, i18nMixin } from './i18n.js';
import { config } from './config.js';
import { DynamicIcon } from '@fluent-cart/ui';
import IntroSection from './components/IntroSection.vue';
import CompatibilityCheck from './components/CompatibilityCheck.vue';
import MigrationOverview from './components/MigrationOverview.vue';
import MigrationRunner from './components/MigrationRunner.vue';
import ConfirmModal from './components/ConfirmModal.vue';

var STORAGE_KEY = 'fctMigratorState';

export default {
    mixins: [i18nMixin],
    name: 'MigratorApp',
    components: {
        DynamicIcon: DynamicIcon,
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
            isDevMode: !!config().devMode,
            adminUrl: config().adminUrl || '',
            pluginUrl: config().pluginUrl || '',
            migrationSummary: null,
            hasMigration: false,

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
                confirmText: __('Confirm'),
                action: null
            }
        };
    },
    computed: {
        visibleSteps: function () {
            return [
                { key: 'compatibility', text: __('Compatibility') },
                { key: 'overview', text: __('Overview') },
                { key: 'running', text: __('Migrating') }
            ];
        },
        stepLabel: function () {
            /* translators: 1: current step number, 2: number of steps, 3: step name */
            return sprintf(__('Step %1$s of %2$s · %3$s'), this.activeStepIndex + 1, this.visibleSteps.length, this.visibleSteps[this.activeStepIndex].text);
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
    beforeRouteLeave: function (to, from, next) {
        var runner = this.$refs.runner;
        if (!runner || !runner.running || runner.paused) {
            return next();
        }
        // Leaving stops the run after the current batch (see MigrationRunner).
        next(window.confirm(__('A migration is running. Leaving this page pauses it after the current batch. Leave anyway?')));
    },
    methods: {
        // Mobile: open core's settings sidebar, like SettingsHeader does.
        toggleSettingsMenu: function () {
            var sidebar = document.querySelector('.fct-settings-nav-container');
            var overlay = document.querySelector('.fct-settings-menu-overlay');
            if (sidebar) sidebar.classList.toggle('is-nav-open');
            if (overlay) overlay.classList.toggle('is-overlay-open');
        },

        init: async function () {
            this.loading = true;
            await this.loadSources();

            // Check for session-saved state (e.g. page refresh during migration)
            var saved = this.loadSessionState();
            if (saved && saved.source && saved.step === 'running' && saved.runProgress) {
                this.selectedSource = saved.source;
                setApiSource(saved.source.key);
                this.savedRunProgress = saved.runProgress;
                this.stepsToRun = saved.stepsToRun || {};
                this.goToStep('running');
            } else if (this.migrationSummary || (this.hasMigration && this.sources.length)) {
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
                this.migrationSummary = data.latest_summary || null;
                this.hasMigration = !!data.has_migration;
            } catch (e) {
                if (retries > 0) {
                    var self = this;
                    await new Promise(function (r) { setTimeout(r, 1000); });
                    return self.loadSources(retries - 1);
                }
                this.error = __('Failed to load sources:') + ' ' + e.message;
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
                this.error = __('Failed to load stats:') + ' ' + e.message;
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
            setApiSource(source.key);
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

            // Persist the taxonomy mapping first — the products step (and any
            // later WP-CLI run) reads it from the server, not from this payload.
            // Only when the mapper actually loaded: saving before then would
            // store an empty map (= "migrate no taxonomies") the admin never
            // chose; skipping the save leaves the server on its stored mapping
            // or the suggested defaults.
            if (config.taxonomyMapLoaded) {
                try {
                    await apiRequest('POST', 'taxonomies/map', { map: config.taxonomyMap || [] });
                } catch (e) {
                    this.error = __('Failed to save the taxonomy mapping:') + ' ' + e.message;
                    return;
                }
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
            this.confirmModal.title = __('Reset Migration');
            this.confirmModal.message = __('This will permanently delete all migrated data from FluentCart, including:');
            this.confirmModal.items = [
                __('Products and variations'),
                __('Orders and transactions'),
                __('Customers and subscriptions'),
                __('Coupons'),
                __('Migration progress')
            ];
            this.confirmModal.confirmText = __('Yes, Reset Everything');
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
                this.error = __('Reset failed:') + ' ' + e.message;
            } finally {
                this.loading = false;
            }
        }
    }
};
</script>
