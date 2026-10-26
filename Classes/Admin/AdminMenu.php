<?php

namespace FluentCartMigrator\Classes\Admin;

/**
 * Puts the migrator screen into FluentCart's admin app as Settings → Migrator
 * (#/settings/migrator). The bundle is an ES module built against core's
 * @fluent-cart/* import map, so it renders with core's Vue instead of its own.
 */
class AdminMenu
{
    // FLUENTCART_MODULE_API the bundle was built against.
    const MIN_MODULE_API = 1;

    const HANDLE = 'fct-migrator-app';

    // The pre-module standalone page; kept only to redirect old bookmarks.
    const LEGACY_SLUG = 'fluent-cart-migrator';

    public function register()
    {
        if (!$this->coreSupportsModules()) {
            add_action('admin_notices', [$this, 'showCoreTooOldNotice']);
            return;
        }

        add_action('fluent_cart/loading_app', [$this, 'enqueueAssets']);
        add_filter('fluent_cart/admin_app_data', [$this, 'addAppData']);
        add_action('admin_menu', [$this, 'registerLegacyRedirect'], 99);
    }

    private function coreSupportsModules()
    {
        return defined('FLUENTCART_MODULE_API') && FLUENTCART_MODULE_API >= static::MIN_MODULE_API;
    }

    public function enqueueAssets()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_style(
            static::HANDLE,
            FLUENTCART_MIGRATOR_URL . 'assets/build/migrator-app.css',
            [],
            $this->assetVersion('assets/build/migrator-app.css')
        );

        wp_enqueue_script(
            static::HANDLE,
            FLUENTCART_MIGRATOR_URL . 'assets/build/migrator-app.js',
            ['wp-i18n'],
            $this->assetVersion('assets/build/migrator-app.js'),
            true
        );

        // JS translations: languages/fluent-cart-migrator-{locale}-{md5('assets/build/migrator-app.js')}.json
        // (generated with `wp i18n make-json languages/` or Loco Translate).
        wp_set_script_translations(
            static::HANDLE,
            'fluent-cart-migrator',
            FLUENTCART_MIGRATOR_PLUGIN_PATH . 'languages'
        );

        add_filter('script_loader_tag', [$this, 'makeModuleTag'], 10, 2);
    }

    /**
     * Plugin version plus the build's mtime: the stylesheet loads on every
     * FluentCart screen, so a stale cached copy after a rebuild would style
     * core's screens with old rules until the version changes.
     */
    private function assetVersion($relativePath)
    {
        $mtime = @filemtime(FLUENTCART_MIGRATOR_PLUGIN_PATH . $relativePath);

        return $mtime ? FLUENTCART_MIGRATOR_VERSION . '.' . $mtime : FLUENTCART_MIGRATOR_VERSION;
    }

    /**
     * The tag WordPress passes here also carries the inline translations
     * script, so only the tag with a src becomes a module.
     */
    public function makeModuleTag($tag, $handle)
    {
        if ($handle !== static::HANDLE) {
            return $tag;
        }

        return preg_replace('/<script(?=[^>]*\ssrc=)/', '<script type="module"', $tag, 1);
    }

    public function addAppData($data)
    {
        if (!current_user_can('manage_options')) {
            return $data;
        }

        $sidebar   = isset($data['addon_settings_sidebar']) ? (array)$data['addon_settings_sidebar'] : [];
        $sidebar[] = [
            'name'       => __('Migrator', 'fluent-cart-migrator'),
            'icon'       => 'ArrowLeftRight',
            // Same slug as the route meta.permission in assets/js/migrator-app.js.
            'permission' => ['store/sensitive'],
            'url'        => '/settings/migrator'
        ];
        $data['addon_settings_sidebar'] = $sidebar;

        $data['fct_migrator'] = [
            'restUrl'   => rest_url('fct-migrator/v1/'),
            'nonce'     => wp_create_nonce('wp_rest'),
            'adminUrl'  => admin_url(),
            'pluginUrl' => FLUENTCART_MIGRATOR_URL,
            'devMode'   => defined('FLUENT_CART_DEV_MODE') && FLUENT_CART_DEV_MODE,
        ];

        return $data;
    }

    /**
     * Hidden page under the old slug that sends existing links
     * (admin.php?page=fluent-cart-migrator) to the new settings route.
     */
    public function registerLegacyRedirect()
    {
        $hook = add_submenu_page(
            null,
            __('FluentCart Migrator', 'fluent-cart-migrator'),
            __('Migrator', 'fluent-cart-migrator'),
            'manage_options',
            static::LEGACY_SLUG,
            '__return_null'
        );

        if ($hook) {
            add_action('load-' . $hook, function () {
                wp_safe_redirect(admin_url('admin.php?page=fluent-cart#/settings/migrator'));
                exit;
            });
        }
    }

    public function showCoreTooOldNotice()
    {
        if (!current_user_can('update_plugins')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>' . esc_html__('FluentCart Migrator needs a newer version of FluentCart. Update FluentCart to use the migrator screen (WP-CLI commands keep working).', 'fluent-cart-migrator') . '</p></div>';
    }
}
