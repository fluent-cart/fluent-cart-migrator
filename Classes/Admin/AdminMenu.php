<?php

namespace FluentCartMigrator\Classes\Admin;

class AdminMenu
{
    public function register()
    {
        add_action('fluent_cart/admin_submenu_added', [$this, 'addMigratorSubmenu']);
        add_action('admin_menu', [$this, 'registerStandalonePage'], 99);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('in_admin_header', [$this, 'removeAdminNotices'], 999);
    }

    public function addMigratorSubmenu()
    {
        global $submenu;
        $submenu['fluent-cart']['migrator'] = [
            __('Migrator', 'fluent-cart-migrator'),
            'manage_options',
            'admin.php?page=fluent-cart-migrator',
            '',
            'fluent_cart_migrator'
        ];
    }

    public function registerStandalonePage()
    {
        add_submenu_page(
            null,
            __('FluentCart Migrator', 'fluent-cart-migrator'),
            __('Migrator', 'fluent-cart-migrator'),
            'manage_options',
            'fluent-cart-migrator',
            [$this, 'renderPage']
        );
    }

    public function renderPage()
    {
        include FLUENTCART_MIGRATOR_PLUGIN_PATH . 'views/admin-page.php';
    }

    public function removeAdminNotices()
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'admin_page_fluent-cart-migrator') {
            return;
        }

        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
    }

    public function enqueueAssets($hook)
    {
        if ($hook !== 'admin_page_fluent-cart-migrator') {
            return;
        }

        wp_enqueue_style(
            'fct-migrator-app',
            FLUENTCART_MIGRATOR_URL . 'assets/build/migrator-app.css',
            [],
            FLUENTCART_MIGRATOR_VERSION
        );

        wp_enqueue_script(
            'fct-migrator-app',
            FLUENTCART_MIGRATOR_URL . 'assets/build/migrator-app.js',
            [],
            FLUENTCART_MIGRATOR_VERSION,
            true
        );

        wp_localize_script('fct-migrator-app', 'fctMigrator', [
            'restUrl'          => rest_url('fct-migrator/v1/'),
            'nonce'            => wp_create_nonce('wp_rest'),
            'migration'        => get_option('__fluent_cart_edd3_migration_steps', false),
            'migrationSummary' => get_option('__fluent_cart_migration_summary', null),
            'adminUrl'         => admin_url(),
            'pluginUrl'        => FLUENTCART_MIGRATOR_URL,
            'devMode'          => defined('FLUENT_CART_DEV_MODE') && FLUENT_CART_DEV_MODE,
        ]);
    }
}
