<?php

namespace FluentCartMigrator\Classes\Admin;

use FluentCartMigrator\Classes\MigratorService;
use FluentCartMigrator\Classes\SourceManager;
use FluentCartMigrator\Classes\Support\MigrationLog;
use FluentCartMigrator\Classes\Support\TaxonomyMap;

class RestApi
{
    private $namespace = 'fct-migrator/v1';

    public function register()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes()
    {
        register_rest_route($this->namespace, '/sources', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getSources'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/stats/(?P<source>[a-z]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getStats'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getStatus'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/can-migrate', [
            'methods'             => 'GET',
            'callback'            => [$this, 'canMigrate'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/taxonomies', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getTaxonomies'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/taxonomies/map', [
            'methods'             => 'POST',
            'callback'            => [$this, 'saveTaxonomyMap'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/migrate/taxonomies', [
            'methods'             => 'POST',
            'callback'            => [$this, 'migrateTaxonomies'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/migrate/products', [
            'methods'             => 'POST',
            'callback'            => [$this, 'migrateProducts'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/migrate/coupons', [
            'methods'             => 'POST',
            'callback'            => [$this, 'migrateCoupons'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/migrate/tax-rates', [
            'methods'             => 'POST',
            'callback'            => [$this, 'migrateTaxRates'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/migrate/payments', [
            'methods'             => 'POST',
            'callback'            => [$this, 'migratePayments'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/migrate/reviews', [
            'methods'             => 'POST',
            'callback'            => [$this, 'migrateReviews'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/migrate/missing-customers', [
            'methods'             => 'POST',
            'callback'            => [$this, 'migrateMissingCustomers'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/migrate/recount', [
            'methods'             => 'POST',
            'callback'            => [$this, 'recountStats'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/logs', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getLogs'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/logs/export', [
            'methods'             => 'GET',
            'callback'            => [$this, 'exportLogs'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/verify-licenses', [
            'methods'             => 'POST',
            'callback'            => [$this, 'verifyLicenses'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/reset', [
            'methods'             => 'POST',
            'callback'            => [$this, 'resetMigration'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, '/migration-summary', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getMigrationSummary'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

    }

    public function checkPermission()
    {
        return current_user_can('manage_options');
    }

    /**
     * Run a migration step with a safety net so the browser always receives a
     * readable JSON error instead of a blank response:
     *
     *  - An uncaught exception/Error is turned into a WP_Error carrying the real
     *    message (WordPress's fatal handler would only say "critical error").
     *  - If the request is terminated early — third-party code calling exit()/die()
     *    from a save_post / term / option hook fired by the migration, or a fatal
     *    error while WP's fatal handler is disabled — PHP would send HTTP 200 with
     *    an empty body, which the UI reports as "JSON.parse: unexpected end of
     *    data". A shutdown guard detects that and writes a JSON error (with the
     *    hook stack at the time of termination) so the cause is visible.
     *
     * @param callable $fn returns the step result (array|WP_Error)
     * @return \WP_REST_Response|\WP_Error
     */
    private function guarded(callable $fn)
    {
        $finished = false;

        register_shutdown_function(function () use (&$finished) {
            if ($finished || headers_sent()) {
                return; // normal completion, or something already responded
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
            $lastError  = error_get_last();

            if ($lastError && in_array((int) $lastError['type'], $fatalTypes, true)) {
                $message = sprintf(
                    /* translators: 1: PHP error message, 2: file path, 3: line number */
                    __('PHP fatal error: %1$s in %2$s on line %3$d', 'fluent-cart-migrator'),
                    $lastError['message'],
                    $lastError['file'],
                    $lastError['line']
                );
            } else {
                $message = __('The migration request was terminated early by the server or by another plugin (exit/die) before the migrator could respond. Check your PHP error log, try deactivating other plugins on the staging site, or run the migration via WP-CLI.', 'fluent-cart-migrator');
                $hooks   = !empty($GLOBALS['wp_current_filter']) && is_array($GLOBALS['wp_current_filter'])
                    ? array_values(array_filter($GLOBALS['wp_current_filter'], 'is_string'))
                    : [];
                if ($hooks) {
                    /* translators: %s: list of WordPress hook names */
                    $message .= ' ' . sprintf(__('(terminated while running hook: %s)', 'fluent-cart-migrator'), implode(' > ', $hooks));
                }
            }

            if (function_exists('error_log')) {
                error_log('[FluentCart Migrator] ' . wp_strip_all_tags($message));
            }

            status_header(500);
            header('Content-Type: application/json; charset=' . get_option('blog_charset'));
            echo wp_json_encode([
                'code'    => 'fct_migrator_terminated',
                'message' => $message,
                'data'    => ['status' => 500],
            ]);
        });

        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $finished = true;

            $message = sprintf(
                /* translators: 1: exception message, 2: file path, 3: line number */
                __('%1$s (in %2$s on line %3$d)', 'fluent-cart-migrator'),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
            error_log('[FluentCart Migrator] ' . $message);

            return new \WP_Error('fct_migrator_exception', $message, ['status' => 500]);
        }

        $finished = true;

        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    /**
     * Resolve the migrator for the request's source (defaults to 'edd' for
     * backward compatibility with the existing front-end calls).
     *
     * @return \FluentCartMigrator\Classes\Contracts\SourceMigratorInterface|\WP_Error
     */
    private function resolveMigrator(\WP_REST_Request $request)
    {
        $source = $request->get_param('source');
        if (!$source) {
            $source = 'edd';
        }

        $manager = new SourceManager();
        if (!$manager->has($source)) {
            /* translators: %s: migration source key */
            return new \WP_Error('invalid_source', sprintf(__('Unsupported migration source: %s', 'fluent-cart-migrator'), $source), ['status' => 400]);
        }

        return $manager->resolve($source);
    }

    /**
     * The request's source key (defaults to 'edd', like resolveMigrator()).
     */
    private function requestSource(\WP_REST_Request $request)
    {
        return sanitize_key($request->get_param('source') ?: 'edd');
    }

    public function getSources()
    {
        $service = new MigratorService();
        return rest_ensure_response($service->getSources());
    }

    public function getStats(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return rest_ensure_response($migrator->getStats());
    }

    public function getStatus(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return rest_ensure_response($migrator->getStatus());
    }

    public function canMigrate(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        $result = $migrator->canMigrate();
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(['can_migrate' => true]);
    }

    /**
     * Everything the taxonomy mapper UI needs: the FluentCart product
     * taxonomies available as destinations, the taxonomies this source has,
     * and the currently effective mapping (saved, or the suggested defaults).
     */
    public function getTaxonomies(\WP_REST_Request $request)
    {
        $source = $this->requestSource($request);

        if (!TaxonomyMap::supports($source)) {
            /* translators: %s: migration source key */
            return new \WP_Error('invalid_source', sprintf(__('Unsupported migration source: %s', 'fluent-cart-migrator'), $source), ['status' => 400]);
        }

        return rest_ensure_response([
            'source'       => $source,
            'destinations' => TaxonomyMap::destinations(),
            'sources'      => TaxonomyMap::sources($source),
            'map'          => TaxonomyMap::get($source),
            'defaults'     => TaxonomyMap::defaults($source),
            'customized'   => TaxonomyMap::isCustomized($source),
        ]);
    }

    /**
     * Persist the mapping for a source. Rows with an empty side are dropped —
     * that is how the UI expresses "do not migrate this taxonomy".
     */
    public function saveTaxonomyMap(\WP_REST_Request $request)
    {
        $source = $this->requestSource($request);

        if (!TaxonomyMap::supports($source)) {
            /* translators: %s: migration source key */
            return new \WP_Error('invalid_source', sprintf(__('Unsupported migration source: %s', 'fluent-cart-migrator'), $source), ['status' => 400]);
        }

        $map = $request->get_param('map');
        if (!is_array($map)) {
            return new \WP_Error('invalid_map', __('A taxonomy map is required.', 'fluent-cart-migrator'), ['status' => 400]);
        }

        return rest_ensure_response([
            'success' => true,
            'source'  => $source,
            'map'     => TaxonomyMap::save($source, $map),
        ]);
    }

    /**
     * Apply the taxonomy mapping to the migrated catalog. Resumable like the
     * products/payments steps: the front-end re-calls while `has_more` is true
     * and the server resumes from its own saved page.
     */
    public function migrateTaxonomies(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return $this->guarded(function () use ($migrator) {
            return $migrator->migrateTaxonomies();
        });
    }

    public function migrateProducts(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return $this->guarded(function () use ($migrator) {
            return $migrator->migrateProducts();
        });
    }

    public function migrateTaxRates(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return $this->guarded(function () use ($migrator) {
            return $migrator->migrateTaxRates();
        });
    }

    public function migrateCoupons(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return $this->guarded(function () use ($migrator) {
            return $migrator->migrateCoupons();
        });
    }

    public function migratePayments(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        // Smaller pages keep peak memory bounded on large stores; the migrator
        // also memory-boxes the batch and the front-end loops until has_more is
        // false, so this only affects how often we hand back to the browser.
        return $this->guarded(function () use ($migrator) {
            $page = $migrator->getPaymentResumePage();
            return $migrator->migratePayments($page, 50, 25);
        });
    }

    /**
     * Reviews step. Like payments, the migrator owns the cursor and the phase,
     * so the browser just re-posts while has_more is true.
     */
    public function migrateReviews(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return $this->guarded(function () use ($migrator) {
            return $migrator->migrateReviews(200, 25);
        });
    }

    public function migrateMissingCustomers(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return $this->guarded(function () use ($migrator) {
            return $migrator->migrateMissingCustomers();
        });
    }

    public function recountStats(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        $substep = $request->get_param('substep');
        if (!in_array($substep, $migrator->getRecountSubsteps(), true)) {
            /* translators: %s: recount substep key */
            return new \WP_Error('invalid_substep', sprintf(__('Invalid substep: %s', 'fluent-cart-migrator'), $substep), ['status' => 400]);
        }

        return $this->guarded(function () use ($migrator, $substep) {
            return $migrator->recountStats($substep);
        });
    }

    public function getLogs(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return rest_ensure_response($migrator->getLogs());
    }

    /**
     * Download the skip/failure report as CSV. Served raw (not JSON) — the
     * front-end links to this URL with the REST nonce as `_wpnonce`, so the
     * browser gets a normal file download.
     */
    public function exportLogs(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        // Normalize from the raw log so every CSV row carries the full reason
        // + hint (getLogs() trims the hint from entries for the UI payload).
        $data    = $migrator->getLogs();
        $entries = MigrationLog::entries(is_array($data['logs'] ?? null) ? $data['logs'] : []);

        $source   = sanitize_key($request->get_param('source') ?: 'edd');
        $filename = sprintf('fluent-cart-migration-report-%s-%s.csv', $source, gmdate('Y-m-d-His'));

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
        MigrationLog::csv($entries, $out);
        fclose($out);

        exit;
    }

    public function verifyLicenses(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        // License verification is EDD-specific; not all sources implement it.
        if (!method_exists($migrator, 'verifyLicenses')) {
            return new \WP_Error('not_supported', __('License verification is not supported for this source.', 'fluent-cart-migrator'), ['status' => 400]);
        }

        return $this->guarded(function () use ($migrator) {
            return $migrator->verifyLicenses();
        });
    }

    public function resetMigration(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return $this->guarded(function () use ($migrator) {
            return $migrator->reset();
        });
    }

    public function getMigrationSummary(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return rest_ensure_response(['summary' => $migrator->getMigrationSummary()]);
    }

}
