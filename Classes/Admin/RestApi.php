<?php

namespace FluentCartMigrator\Classes\Admin;

use FluentCartMigrator\Classes\MigratorService;
use FluentCartMigrator\Classes\SourceManager;

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
            return new \WP_Error('invalid_source', 'Unsupported migration source: ' . $source, ['status' => 400]);
        }

        return $manager->resolve($source);
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

    public function migrateProducts(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return rest_ensure_response($migrator->migrateProducts());
    }

    public function migrateTaxRates(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return rest_ensure_response($migrator->migrateTaxRates());
    }

    public function migrateCoupons(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return rest_ensure_response($migrator->migrateCoupons());
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
        $page   = $migrator->getPaymentResumePage();
        $result = $migrator->migratePayments($page, 50, 25);

        return rest_ensure_response($result);
    }

    public function migrateMissingCustomers(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return rest_ensure_response($migrator->migrateMissingCustomers());
    }

    public function recountStats(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        $substep = $request->get_param('substep');
        if (!in_array($substep, $migrator->getRecountSubsteps(), true)) {
            return new \WP_Error('invalid_substep', 'Invalid substep: ' . $substep, ['status' => 400]);
        }

        return rest_ensure_response($migrator->recountStats($substep));
    }

    public function getLogs(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        return rest_ensure_response($migrator->getLogs());
    }

    public function verifyLicenses(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        // License verification is EDD-specific; not all sources implement it.
        if (!method_exists($migrator, 'verifyLicenses')) {
            return new \WP_Error('not_supported', 'License verification is not supported for this source.', ['status' => 400]);
        }

        return rest_ensure_response($migrator->verifyLicenses());
    }

    public function resetMigration(\WP_REST_Request $request)
    {
        $migrator = $this->resolveMigrator($request);
        if (is_wp_error($migrator)) {
            return $migrator;
        }

        $result = $migrator->reset();
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
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
