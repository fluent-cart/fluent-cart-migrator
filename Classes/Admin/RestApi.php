<?php

namespace FluentCartMigrator\Classes\Admin;

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

        register_rest_route($this->namespace, '/migrate/payments', [
            'methods'             => 'POST',
            'callback'            => [$this, 'migratePayments'],
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
    }

    public function checkPermission()
    {
        return current_user_can('manage_options');
    }

    public function getSources()
    {
        $service = new MigratorService();
        return rest_ensure_response($service->getSources());
    }

    public function getStats(\WP_REST_Request $request)
    {
        $source = $request->get_param('source');

        if ($source !== 'edd') {
            return new \WP_Error('invalid_source', 'Unsupported source: ' . $source, ['status' => 400]);
        }

        $service = new MigratorService();
        return rest_ensure_response($service->getEddStats());
    }

    public function getStatus()
    {
        $service = new MigratorService();
        return rest_ensure_response($service->getStatus());
    }

    public function canMigrate()
    {
        $service = new MigratorService();
        $result = $service->canMigrate();

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(['can_migrate' => true]);
    }

    public function migrateProducts()
    {
        $service = new MigratorService();
        $result = $service->migrateProducts();
        return rest_ensure_response($result);
    }

    public function migrateCoupons()
    {
        $service = new MigratorService();
        $result = $service->migrateCoupons();
        return rest_ensure_response($result);
    }

    public function migratePayments(\WP_REST_Request $request)
    {
        $page    = (int) $request->get_param('page') ?: 1;
        $perPage = (int) $request->get_param('per_page') ?: 100;

        $service = new MigratorService();
        $result = $service->migratePayments($page, $perPage);
        return rest_ensure_response($result);
    }

    public function recountStats(\WP_REST_Request $request)
    {
        $substep = $request->get_param('substep');

        if (!in_array($substep, ['fix_reactivations', 'fix_subs_uuid', 'coupons', 'customers', 'subscriptions'])) {
            return new \WP_Error('invalid_substep', 'Invalid substep: ' . $substep, ['status' => 400]);
        }

        $service = new MigratorService();
        $result = $service->recountStats($substep);
        return rest_ensure_response($result);
    }

    public function getLogs()
    {
        $service = new MigratorService();
        return rest_ensure_response($service->getLogs());
    }

    public function verifyLicenses()
    {
        $service = new MigratorService();
        $result = $service->verifyLicenses();
        return rest_ensure_response($result);
    }

    public function resetMigration()
    {
        $service = new MigratorService();
        $result = $service->resetMigration();
        return rest_ensure_response($result);
    }
}
