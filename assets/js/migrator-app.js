import { createApp, ref, reactive, computed, onMounted } from 'vue';
import '../css/migrator-app.css';

const app = createApp({
    setup() {
        // ─── State ───────────────────────────────────────────────
        const currentStep = ref('source');
        const loading = ref(false);
        const error = ref(null);

        // Source step
        const sources = ref([]);

        // Selected source data
        const selectedSource = ref(null);

        // Stats
        const stats = ref(null);

        // Migration status
        const migrationStatus = ref(null);

        // Config
        const batchSize = ref(100);
        const stepsToRun = reactive({
            products: true,
            coupons: true,
            payments: true,
            recount: true,
        });

        // Runner
        const isPaused = ref(false);
        const isRunning = ref(false);
        const currentRunStep = ref(null);
        const runProgress = reactive({
            products: { status: 'pending', total: 0, migrated: 0, failed: 0, errors: [] },
            coupons: { status: 'pending', total: 0, migrated: 0 },
            payments: { status: 'pending', page: 0, processed: 0, hasMore: true, errorsCount: 0 },
            recount: { status: 'pending', substeps: { coupons: 'pending', customers: 'pending', subscriptions: 'pending' } },
        });
        const startTime = ref(null);
        const endTime = ref(null);
        const errorLog = ref([]);
        const showErrorLog = ref(false);

        // Completion
        const duration = computed(() => {
            if (!startTime.value) return '';
            const end = endTime.value || Date.now();
            const seconds = Math.floor((end - startTime.value) / 1000);
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = seconds % 60;
            return [h, m, s].map(v => String(v).padStart(2, '0')).join(':');
        });

        // ─── API Helper ──────────────────────────────────────────
        async function apiRequest(method, path, data = {}) {
            const opts = {
                method,
                headers: {
                    'X-WP-Nonce': fctMigrator.nonce,
                    'Content-Type': 'application/json',
                },
            };
            if (method !== 'GET') {
                opts.body = JSON.stringify(data);
            }
            const res = await fetch(fctMigrator.restUrl + path, opts);
            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                throw new Error(err.message || `HTTP ${res.status}`);
            }
            return res.json();
        }

        // ─── Step 1: Source Selection ────────────────────────────
        async function loadSources() {
            loading.value = true;
            error.value = null;
            try {
                const data = await apiRequest('GET', 'sources');
                sources.value = data.sources;
            } catch (e) {
                error.value = 'Failed to load sources: ' + e.message;
            } finally {
                loading.value = false;
            }
        }

        function selectSource(source) {
            if (!source.detected || source.coming_soon) return;
            selectedSource.value = source;
            currentStep.value = 'version';
        }

        // ─── Step 2: Version Gate ────────────────────────────────
        const versionState = computed(() => {
            const src = selectedSource.value;
            if (!src) return 'unknown';
            if (src.has_v3_tables && src.version) return 'pass';
            if (src.has_v3_tables && !src.version) return 'data_only';
            return 'blocked';
        });

        // ─── Step 3: Overview ────────────────────────────────────
        async function loadOverview() {
            loading.value = true;
            error.value = null;
            try {
                const [statsData, statusData] = await Promise.all([
                    apiRequest('GET', 'stats/' + selectedSource.value.key),
                    apiRequest('GET', 'status'),
                ]);
                stats.value = statsData;
                migrationStatus.value = statusData;
            } catch (e) {
                error.value = 'Failed to load stats: ' + e.message;
            } finally {
                loading.value = false;
            }
        }

        // ─── Step 4: Config ──────────────────────────────────────
        function isStepCompleted(step) {
            const m = migrationStatus.value?.migration;
            if (!m) return false;
            return m[step] === 'yes';
        }

        // ─── Step 5: Runner ──────────────────────────────────────
        async function startMigration() {
            isRunning.value = true;
            isPaused.value = false;
            startTime.value = Date.now();
            endTime.value = null;
            error.value = null;
            currentStep.value = 'running';

            // Reset progress
            Object.keys(runProgress).forEach(key => {
                if (key === 'recount') {
                    runProgress[key].status = 'pending';
                    runProgress[key].substeps = { coupons: 'pending', customers: 'pending', subscriptions: 'pending' };
                } else if (key === 'payments') {
                    Object.assign(runProgress[key], { status: 'pending', page: 0, processed: 0, hasMore: true, errorsCount: 0 });
                } else {
                    Object.assign(runProgress[key], { status: 'pending', total: 0, migrated: 0, failed: 0, errors: [] });
                }
            });

            const steps = [];
            if (stepsToRun.products) steps.push('products');
            if (stepsToRun.coupons) steps.push('coupons');
            if (stepsToRun.payments) steps.push('payments');
            if (stepsToRun.recount) steps.push('recount');

            for (const step of steps) {
                if (isPaused.value) break;

                currentRunStep.value = step;
                runProgress[step].status = 'running';

                try {
                    if (step === 'products') {
                        await runProducts();
                    } else if (step === 'coupons') {
                        await runCoupons();
                    } else if (step === 'payments') {
                        await runPayments();
                    } else if (step === 'recount') {
                        await runRecount();
                    }

                    if (!isPaused.value) {
                        runProgress[step].status = 'completed';
                    }
                } catch (e) {
                    runProgress[step].status = 'error';
                    error.value = `Error in ${step}: ${e.message}`;
                    break;
                }
            }

            if (!isPaused.value) {
                endTime.value = Date.now();
                isRunning.value = false;
                currentStep.value = 'complete';
                await loadLogs();
            }
        }

        async function runProducts() {
            const result = await apiRequest('POST', 'migrate/products');
            runProgress.products.total = result.total;
            runProgress.products.migrated = result.migrated;
            runProgress.products.failed = result.failed;
            runProgress.products.errors = result.errors || [];
        }

        async function runCoupons() {
            const result = await apiRequest('POST', 'migrate/coupons');
            runProgress.coupons.total = result.total;
            runProgress.coupons.migrated = result.migrated;
        }

        async function runPayments() {
            const migration = migrationStatus.value?.migration;
            let page = (migration && migration.last_order_page && migration.payments !== 'yes')
                ? migration.last_order_page
                : 1;
            let hasMore = true;

            while (hasMore && !isPaused.value) {
                const result = await apiRequest('POST', 'migrate/payments', {
                    page,
                    per_page: batchSize.value,
                });
                hasMore = result.has_more;
                runProgress.payments.page = page;
                runProgress.payments.processed += result.processed;
                runProgress.payments.hasMore = hasMore;
                runProgress.payments.errorsCount = result.errors_in_batch;
                page++;
            }
        }

        async function runRecount() {
            const substeps = ['coupons', 'customers', 'subscriptions'];
            for (const sub of substeps) {
                if (isPaused.value) break;
                runProgress.recount.substeps[sub] = 'running';
                await apiRequest('POST', 'migrate/recount', { substep: sub });
                runProgress.recount.substeps[sub] = 'completed';
            }
        }

        function pauseMigration() {
            isPaused.value = true;
        }

        function resumeMigration() {
            isPaused.value = false;
            // Re-run from current step
            startMigration();
        }

        // ─── Step 6: Complete ────────────────────────────────────
        async function loadLogs() {
            try {
                const data = await apiRequest('GET', 'logs');
                errorLog.value = data.logs || {};
            } catch (_) {
                // Ignore
            }
        }

        function goToFluentCart() {
            window.location.href = fctMigrator.adminUrl + 'admin.php?page=fluent-cart#/';
        }

        function runAgain() {
            currentStep.value = 'config';
        }

        // ─── Navigation ──────────────────────────────────────────
        function goToStep(step) {
            currentStep.value = step;
            if (step === 'overview') {
                loadOverview();
            }
        }

        function goBack() {
            const map = {
                version: 'source',
                overview: 'version',
                config: 'overview',
                running: 'config',
                complete: 'config',
            };
            const prev = map[currentStep.value];
            if (prev) goToStep(prev);
        }

        // ─── Reset ──────────────────────────────────────────────
        async function resetMigration() {
            if (!confirm('Are you sure you want to reset all migration progress? This cannot be undone.')) return;
            loading.value = true;
            try {
                await apiRequest('POST', 'reset');
                migrationStatus.value = { migration: null, failed_log_count: 0 };
                currentStep.value = 'overview';
                await loadOverview();
            } catch (e) {
                error.value = 'Reset failed: ' + e.message;
            } finally {
                loading.value = false;
            }
        }

        // ─── Init ────────────────────────────────────────────────
        onMounted(() => {
            loadSources();
        });

        // Step labels
        const stepLabels = {
            source: 'Select Source',
            version: 'Compatibility',
            overview: 'Overview',
            config: 'Configure',
            running: 'Migrating',
            complete: 'Complete',
        };
        const stepOrder = ['source', 'version', 'overview', 'config', 'running', 'complete'];

        const activeStepIndex = computed(() => stepOrder.indexOf(currentStep.value));

        const totalPaymentsEstimate = computed(() => {
            if (!stats.value) return 0;
            return Math.ceil(stats.value.orders_count / batchSize.value);
        });

        const errorLogEntries = computed(() => {
            const logs = errorLog.value;
            if (!logs || typeof logs !== 'object') return [];
            return Object.entries(logs).map(([id, data]) => ({
                paymentId: id,
                ...(typeof data === 'string' ? { message: data } : data),
            }));
        });

        return {
            // State
            currentStep, loading, error, sources, selectedSource, stats, migrationStatus,
            batchSize, stepsToRun, isPaused, isRunning, currentRunStep, runProgress,
            startTime, endTime, duration, errorLog, showErrorLog, errorLogEntries,
            // Computed
            versionState, activeStepIndex, totalPaymentsEstimate,
            // Step info
            stepLabels, stepOrder,
            // Methods
            selectSource, goToStep, goBack, isStepCompleted,
            startMigration, pauseMigration, resumeMigration,
            goToFluentCart, runAgain, resetMigration, loadOverview,
        };
    },

    template: `
    <div class="fct-migrator">
        <!-- Step Indicator -->
        <div class="fct-steps-indicator">
            <div
                v-for="(step, index) in stepOrder"
                :key="step"
                class="fct-step-dot"
                :class="{
                    'is-active': currentStep === step,
                    'is-completed': index < activeStepIndex,
                }"
            >
                <span class="fct-step-number">{{ index < activeStepIndex ? '&#10003;' : index + 1 }}</span>
                <span class="fct-step-label">{{ stepLabels[step] }}</span>
            </div>
        </div>

        <!-- Error Banner -->
        <div v-if="error" class="fct-notice fct-notice--error">
            <p>{{ error }}</p>
            <button @click="error = null" class="fct-notice-dismiss">&times;</button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="fct-loading">
            <span class="spinner is-active"></span> Loading...
        </div>

        <!-- STEP 1: Source Selection -->
        <div v-if="currentStep === 'source' && !loading" class="fct-step">
            <h2>Select Migration Source</h2>
            <p class="fct-step-desc">Choose the platform you want to migrate data from.</p>
            <div class="fct-source-cards">
                <div
                    v-for="source in sources"
                    :key="source.key"
                    class="fct-source-card"
                    :class="{
                        'is-detected': source.detected && !source.coming_soon,
                        'is-disabled': !source.detected || source.coming_soon,
                    }"
                    @click="selectSource(source)"
                >
                    <div class="fct-source-icon">
                        <span v-if="source.key === 'edd'">&#128230;</span>
                        <span v-else>&#128722;</span>
                    </div>
                    <h3>{{ source.name }}</h3>
                    <span v-if="source.coming_soon" class="fct-badge fct-badge--muted">Coming Soon</span>
                    <span v-else-if="source.detected" class="fct-badge fct-badge--success">Detected</span>
                    <span v-else class="fct-badge fct-badge--muted">Not Found</span>
                </div>
            </div>
        </div>

        <!-- STEP 2: Version Gate -->
        <div v-if="currentStep === 'version'" class="fct-step">
            <h2>Compatibility Check</h2>

            <div v-if="versionState === 'pass'" class="fct-version-box fct-version-box--pass">
                <span class="fct-version-icon">&#9989;</span>
                <div>
                    <h3>EDD 3.x detected (v{{ selectedSource.version }})</h3>
                    <p>Your EDD installation is compatible with the migration tool.</p>
                </div>
            </div>

            <div v-else-if="versionState === 'blocked'" class="fct-version-box fct-version-box--blocked">
                <span class="fct-version-icon">&#9888;&#65039;</span>
                <div>
                    <h3>EDD {{ selectedSource.version || '(unknown version)' }} detected</h3>
                    <p>Migration requires <strong>EDD 3.0 or later</strong>. Please upgrade EDD first, then return here.</p>
                </div>
            </div>

            <div v-else-if="versionState === 'data_only'" class="fct-version-box fct-version-box--info">
                <span class="fct-version-icon">&#8505;&#65039;</span>
                <div>
                    <h3>EDD data found</h3>
                    <p>EDD is not currently active, but migration data (v3 tables) was detected. You can proceed.</p>
                </div>
            </div>

            <div class="fct-step-actions">
                <button @click="goBack" class="button">Go Back</button>
                <button
                    v-if="versionState !== 'blocked'"
                    @click="goToStep('overview')"
                    class="button button-primary"
                >Continue</button>
            </div>
        </div>

        <!-- STEP 3: Overview -->
        <div v-if="currentStep === 'overview' && !loading" class="fct-step">
            <h2>Pre-Migration Overview</h2>

            <!-- Resume banner -->
            <div v-if="migrationStatus?.migration" class="fct-notice fct-notice--info">
                <p><strong>Previous migration detected.</strong> Some steps may already be completed.</p>
                <button @click="resetMigration" class="button button-link-delete" style="margin-top:4px;">Reset Migration Progress</button>
            </div>

            <div v-if="stats" class="fct-stats-grid">
                <div class="fct-stat-card">
                    <span class="fct-stat-number">{{ stats.products_count }}</span>
                    <span class="fct-stat-label">Products</span>
                </div>
                <div class="fct-stat-card">
                    <span class="fct-stat-number">{{ stats.orders_count }}</span>
                    <span class="fct-stat-label">Orders</span>
                </div>
                <div class="fct-stat-card">
                    <span class="fct-stat-number">{{ stats.transactions_count }}</span>
                    <span class="fct-stat-label">Transactions</span>
                </div>
                <div class="fct-stat-card">
                    <span class="fct-stat-number">{{ stats.customers_count }}</span>
                    <span class="fct-stat-label">Customers</span>
                </div>
                <div v-if="stats.has_subscriptions" class="fct-stat-card">
                    <span class="fct-stat-number">{{ stats.subscriptions_count }}</span>
                    <span class="fct-stat-label">Subscriptions</span>
                </div>
                <div v-if="stats.has_licenses" class="fct-stat-card">
                    <span class="fct-stat-number">{{ stats.licenses_count }}</span>
                    <span class="fct-stat-label">Licenses</span>
                </div>
            </div>

            <div v-if="stats" class="fct-stats-detail">
                <p><strong>Payment Gateways:</strong> {{ stats.gateways.join(', ') || 'None' }}</p>
                <p><strong>Order Statuses:</strong> {{ stats.statuses.join(', ') || 'None' }}</p>
            </div>

            <div class="fct-step-actions">
                <button @click="goBack" class="button">Go Back</button>
                <button @click="goToStep('config')" class="button button-primary">Continue to Configuration</button>
            </div>
        </div>

        <!-- STEP 4: Config -->
        <div v-if="currentStep === 'config'" class="fct-step">
            <h2>Migration Configuration</h2>

            <div class="fct-config-section">
                <label class="fct-config-label">
                    <strong>Batch Size</strong>
                    <span class="fct-config-hint">Number of orders to process per request</span>
                </label>
                <select v-model.number="batchSize" class="fct-select">
                    <option :value="50">50</option>
                    <option :value="100">100 (Default)</option>
                    <option :value="250">250</option>
                    <option :value="500">500</option>
                    <option :value="1000">1000</option>
                </select>
            </div>

            <div class="fct-config-section">
                <label class="fct-config-label"><strong>Steps to Run</strong></label>
                <div class="fct-config-checkboxes">
                    <label class="fct-checkbox">
                        <input type="checkbox" v-model="stepsToRun.products">
                        Products
                        <span v-if="isStepCompleted('products')" class="fct-badge fct-badge--success">Completed</span>
                    </label>
                    <label class="fct-checkbox">
                        <input type="checkbox" v-model="stepsToRun.coupons">
                        Coupons
                        <span v-if="isStepCompleted('coupons')" class="fct-badge fct-badge--success">Completed</span>
                    </label>
                    <label class="fct-checkbox">
                        <input type="checkbox" v-model="stepsToRun.payments">
                        Payments / Orders
                        <span v-if="isStepCompleted('payments')" class="fct-badge fct-badge--success">Completed</span>
                    </label>
                    <label class="fct-checkbox">
                        <input type="checkbox" v-model="stepsToRun.recount">
                        Recount Stats
                    </label>
                </div>
            </div>

            <div class="fct-step-actions">
                <button @click="goBack" class="button">Go Back</button>
                <button @click="startMigration" class="button button-primary">
                    {{ migrationStatus?.migration ? 'Resume Migration' : 'Start Migration' }}
                </button>
            </div>
        </div>

        <!-- STEP 5: Running -->
        <div v-if="currentStep === 'running'" class="fct-step">
            <h2>Migration in Progress</h2>
            <p class="fct-step-desc">Elapsed: {{ duration }}</p>

            <div class="fct-runner-steps">
                <!-- Products -->
                <div v-if="stepsToRun.products" class="fct-runner-step" :class="'is-' + runProgress.products.status">
                    <span class="fct-runner-icon">
                        <span v-if="runProgress.products.status === 'completed'">&#9989;</span>
                        <span v-else-if="runProgress.products.status === 'running'" class="spinner is-active"></span>
                        <span v-else-if="runProgress.products.status === 'error'">&#10060;</span>
                        <span v-else>&#9711;</span>
                    </span>
                    <div class="fct-runner-info">
                        <strong>Products</strong>
                        <span v-if="runProgress.products.status === 'completed'">
                            &mdash; {{ runProgress.products.migrated }} migrated
                            <span v-if="runProgress.products.failed">, {{ runProgress.products.failed }} failed</span>
                        </span>
                    </div>
                </div>

                <!-- Coupons -->
                <div v-if="stepsToRun.coupons" class="fct-runner-step" :class="'is-' + runProgress.coupons.status">
                    <span class="fct-runner-icon">
                        <span v-if="runProgress.coupons.status === 'completed'">&#9989;</span>
                        <span v-else-if="runProgress.coupons.status === 'running'" class="spinner is-active"></span>
                        <span v-else-if="runProgress.coupons.status === 'error'">&#10060;</span>
                        <span v-else>&#9711;</span>
                    </span>
                    <div class="fct-runner-info">
                        <strong>Coupons</strong>
                        <span v-if="runProgress.coupons.status === 'completed'">
                            &mdash; {{ runProgress.coupons.migrated }} migrated
                        </span>
                    </div>
                </div>

                <!-- Payments -->
                <div v-if="stepsToRun.payments" class="fct-runner-step" :class="'is-' + runProgress.payments.status">
                    <span class="fct-runner-icon">
                        <span v-if="runProgress.payments.status === 'completed'">&#9989;</span>
                        <span v-else-if="runProgress.payments.status === 'running'" class="spinner is-active"></span>
                        <span v-else-if="runProgress.payments.status === 'error'">&#10060;</span>
                        <span v-else>&#9711;</span>
                    </span>
                    <div class="fct-runner-info">
                        <strong>Payments</strong>
                        <span v-if="runProgress.payments.status === 'running' || runProgress.payments.status === 'completed'">
                            &mdash; Page {{ runProgress.payments.page }}<span v-if="totalPaymentsEstimate"> of ~{{ totalPaymentsEstimate }}</span>
                            | {{ runProgress.payments.processed }} orders processed
                        </span>
                        <span v-if="runProgress.payments.errorsCount" class="fct-text-warning">
                            ({{ runProgress.payments.errorsCount }} errors logged)
                        </span>
                    </div>
                </div>

                <!-- Recount -->
                <div v-if="stepsToRun.recount" class="fct-runner-step" :class="'is-' + runProgress.recount.status">
                    <span class="fct-runner-icon">
                        <span v-if="runProgress.recount.status === 'completed'">&#9989;</span>
                        <span v-else-if="runProgress.recount.status === 'running'" class="spinner is-active"></span>
                        <span v-else-if="runProgress.recount.status === 'error'">&#10060;</span>
                        <span v-else>&#9711;</span>
                    </span>
                    <div class="fct-runner-info">
                        <strong>Recount Stats</strong>
                        <div v-if="runProgress.recount.status === 'running' || runProgress.recount.status === 'completed'" class="fct-recount-subs">
                            <span v-for="(st, name) in runProgress.recount.substeps" :key="name" class="fct-recount-sub" :class="'is-' + st">
                                {{ name }}
                                <span v-if="st === 'completed'">&#10003;</span>
                                <span v-else-if="st === 'running'" class="spinner is-active" style="float:none;margin:0 0 0 2px;"></span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="fct-step-actions">
                <button v-if="!isPaused" @click="pauseMigration" class="button">
                    Pause
                </button>
                <button v-else @click="resumeMigration" class="button button-primary">
                    Resume
                </button>
            </div>
        </div>

        <!-- STEP 6: Complete -->
        <div v-if="currentStep === 'complete'" class="fct-step">
            <h2>Migration Complete</h2>
            <p class="fct-step-desc">Duration: {{ duration }}</p>

            <div class="fct-completion-summary">
                <div v-if="stepsToRun.products" class="fct-completion-row">
                    <strong>Products:</strong> {{ runProgress.products.migrated }} migrated
                    <span v-if="runProgress.products.failed">, {{ runProgress.products.failed }} failed</span>
                </div>
                <div v-if="stepsToRun.coupons" class="fct-completion-row">
                    <strong>Coupons:</strong> {{ runProgress.coupons.migrated }} migrated
                </div>
                <div v-if="stepsToRun.payments" class="fct-completion-row">
                    <strong>Payments:</strong> {{ runProgress.payments.processed }} orders processed
                    <span v-if="runProgress.payments.errorsCount">({{ runProgress.payments.errorsCount }} errors)</span>
                </div>
                <div v-if="stepsToRun.recount" class="fct-completion-row">
                    <strong>Recount:</strong> Completed
                </div>
            </div>

            <!-- Error log -->
            <div v-if="errorLogEntries.length" class="fct-error-log-section">
                <button @click="showErrorLog = !showErrorLog" class="button">
                    {{ showErrorLog ? 'Hide' : 'Show' }} Error Log ({{ errorLogEntries.length }})
                </button>
                <div v-if="showErrorLog" class="fct-error-log">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Payment ID</th>
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
            </div>

            <div class="fct-step-actions">
                <button @click="runAgain" class="button">Run Again</button>
                <button @click="goToFluentCart" class="button button-primary">View FluentCart Dashboard</button>
            </div>
        </div>
    </div>
    `,
});

app.mount('#fct-migrator-app');
