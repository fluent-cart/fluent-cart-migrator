<?php

namespace FluentCartMigrator\Classes\PluginManager;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Updater
{
    private $store_url = '';
    private $name = '';
    private $slug = '';
    private $version = '';
    private $addon_slug = '';
    private $parent_product_id = '';
    private $license_key = '';
    private $activation_hash = '';
    private $plugin_title = '';

    private $response_transient_key;
    private $license_notice_transient_key;

    /**
     * @param string $_store_url   The FluentCart store URL.
     * @param string $_plugin_file Path to the plugin file.
     * @param array  $_config      Configuration: version, addon_slug, parent_product_id, license_key, activation_hash, plugin_title.
     */
    public function __construct($_store_url, $_plugin_file, $_config = [])
    {
        $this->store_url = rtrim($_store_url, '/');
        $this->name = plugin_basename($_plugin_file);
        $this->slug = basename($_plugin_file, '.php');

        $this->response_transient_key = md5(sanitize_key($this->name) . 'response_transient');
        $this->license_notice_transient_key = md5(sanitize_key($this->name) . 'license_notice_transient');

        $this->version = $_config['version'] ?? '1.0.0';
        $this->addon_slug = $_config['addon_slug'] ?? '';
        $this->parent_product_id = $_config['parent_product_id'] ?? '';
        $this->license_key = $_config['license_key'] ?? '';
        $this->activation_hash = $_config['activation_hash'] ?? '';
        $this->plugin_title = $_config['plugin_title'] ?? '';

        $this->init();
    }

    /**
     * Set up WordPress filters to hook into WP update process.
     *
     * @return void
     */
    public function init()
    {
        $this->maybe_delete_transients();

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update'], 51);
        add_action('delete_site_transient_update_plugins', [$this, 'delete_transients']);

        add_filter('plugins_api', [$this, 'plugins_api_filter'], 10, 3);
        remove_action('after_plugin_row_' . $this->name, 'wp_plugin_update_row');

        add_action('after_plugin_row_' . $this->name, [$this, 'show_update_notification'], 10, 2);
        add_action('admin_notices', [$this, 'show_license_activation_notice']);
    }

    public function check_update($_transient_data)
    {
        global $pagenow;

        if (!is_object($_transient_data)) {
            $_transient_data = new \stdClass();
        }

        if ('plugins.php' === $pagenow && is_multisite()) {
            return $_transient_data;
        }

        return $this->check_transient_data($_transient_data);
    }

    private function check_transient_data($_transient_data)
    {
        if (!is_object($_transient_data)) {
            $_transient_data = new \stdClass();
        }

        if (empty($_transient_data->checked)) {
            return $_transient_data;
        }

        $version_info = $this->get_transient($this->response_transient_key);

        if (false === $version_info) {
            $version_info = $this->api_request();
            if (is_wp_error($version_info)) {
                $version_info = new \stdClass();
                $version_info->error = true;
            }
            $this->set_transient($this->response_transient_key, $version_info);
        }

        if (!empty($version_info->error) || !$version_info) {
            // Ensure stale update payload is removed when API response is invalid.
            unset($_transient_data->response[$this->name]);
            unset($_transient_data->no_update[$this->name]);
            return $_transient_data;
        }

        if (is_object($version_info) && isset($version_info->new_version)) {
            $hasValidPackage = !empty($version_info->package) && wp_http_validate_url($version_info->package);

            if (version_compare($this->version, $version_info->new_version, '<') && $hasValidPackage) {
                $_transient_data->response[$this->name] = $version_info;
            } else {
                unset($_transient_data->response[$this->name]);
            }
            $_transient_data->last_checked = time();
            $_transient_data->checked[$this->name] = $this->version;
        }

        return $_transient_data;
    }

    /**
     * Show update notification row for multisite subsites.
     *
     * @param string $file
     * @param array  $plugin
     * @return void
     */
    public function show_update_notification($file, $plugin)
    {
        if (is_network_admin()) {
            return;
        }

        if (!current_user_can('update_plugins')) {
            return;
        }

        if ($this->name !== $file) {
            return;
        }

        remove_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);

        $update_cache = get_site_transient('update_plugins');
        $update_cache = $this->check_transient_data($update_cache);

        set_site_transient('update_plugins', $update_cache);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
    }

    /**
     * Updates plugin modal information with custom data.
     *
     * @param mixed  $_data
     * @param string $_action
     * @param object $_args
     * @return object
     */
    public function plugins_api_filter($_data, $_action = '', $_args = null)
    {
        if ('plugin_information' !== $_action) {
            return $_data;
        }

        if (!isset($_args->slug)) {
            return $_data;
        }

        if ($_args->slug !== $this->slug) {
            return $_data;
        }

        $cache_key = $this->slug . '_api_request_' . substr(md5(serialize($this->slug)), 0, 15);

        // Always fetch fresh on plugin-install page so modal shows current data.
        global $pagenow;
        $api_request_transient = ('plugin-install.php' === $pagenow) ? false : get_site_transient($cache_key);

        if (empty($api_request_transient)) {
            $api_request_transient = $this->api_request();

            if ($api_request_transient && !is_wp_error($api_request_transient)) {
                set_site_transient($cache_key, $api_request_transient, DAY_IN_SECONDS * 2);
            }
        }

        if ($api_request_transient && !is_wp_error($api_request_transient)) {
            $_data = $api_request_transient;
        } else {
            $_data = $this->get_fallback_plugin_info();
        }

        return $_data;
    }

    /**
     * Build minimal plugin info pointing to store page when remote API data is unavailable.
     *
     * @return \stdClass
     */
    private function get_fallback_plugin_info()
    {
        $plugin_page_url = $this->store_url ?: 'https://fluentcart.com';
        $plugin_name = $this->plugin_title ?: $this->slug;

        $info = new \stdClass();
        $info->name = $plugin_name;
        $info->slug = $this->slug;
        $info->version = $this->version;
        $info->homepage = $plugin_page_url;
        $info->author = '<a href="' . esc_url($plugin_page_url) . '">' . esc_html($plugin_name) . '</a>';
        $info->sections = [
            'description' => sprintf(
                '<p>%s</p><p><a href="%s" target="_blank" rel="noopener noreferrer" class="button button-primary">%s</a></p>',
                esc_html__('Full version details are available on the plugin page.', 'fluent-cart-migrator'),
                esc_url($plugin_page_url),
                esc_html__('View Plugin Page →', 'fluent-cart-migrator')
            ),
        ];

        return $info;
    }

    /**
     * Call FluentCart license API with addon_slug support.
     *
     * @return false|object|\WP_Error
     */
    private function api_request()
    {
        if ($this->store_url === home_url()) {
            return false;
        }

        $siteUrl = is_multisite() ? network_site_url() : home_url();

        $licenseKey = $this->license_key;
        $activationHash = $this->activation_hash;

        // Auto-detect license from FluentCart Pro stored settings.
        if (!$licenseKey && !$activationHash) {
            $stored = $this->get_parent_license_info();
            $licenseKey = $stored['license_key'];
            $activationHash = $stored['activation_hash'];
        }

        $url = add_query_arg(['fluent-cart' => 'get_license_version'], $this->store_url);

        $body = [
            'item_id' => $this->parent_product_id,
            'addon_slug' => $this->addon_slug,
            'license_key' => $licenseKey,
            'activation_hash' => $activationHash,
            'site_url' => $siteUrl,
            'current_version' => $this->version,
        ];

        $request = wp_remote_post($url, [
            'timeout' => 15,
            'sslverify' => true,
            'body' => $body,
        ]);

        if (is_wp_error($request)) {
            return $request;
        }

        $request = json_decode(wp_remote_retrieve_body($request));

        if ($request && isset($request->license_status) && $request->license_status !== 'valid') {
            $this->set_transient($this->license_notice_transient_key, [
                'status' => sanitize_text_field($request->license_status),
                'message' => sanitize_text_field($request->license_message ?? ''),
            ]);
        } else {
            $this->delete_transient($this->license_notice_transient_key);
        }

        if ($request && isset($request->sections)) {
            $sections = maybe_unserialize($request->sections);

            if (is_object($sections)) {
                $sections = (array) $sections;
            }

            if (!is_array($sections)) {
                $sections = [];
            }

            if (empty($sections['description'])) {
                $sections['description'] = sprintf(
                    '<p>%s</p>',
                    esc_html__('Full version details are available on the plugin page.', 'fluent-cart-migrator')
                );
            }

            if (empty($sections['changelog'])) {
                $sections['changelog'] = $sections['description'];
            }

            $request->sections = $sections;
            $request->slug = $this->slug;
            $request->plugin = $this->name;
        } else {
            $request = false;
        }

        return $request;
    }

    public function show_license_activation_notice()
    {
        global $pagenow;

        if (!in_array($pagenow, ['plugins.php', 'update-core.php'], true)) {
            return;
        }

        if (!current_user_can('update_plugins')) {
            return;
        }

        $notice = $this->get_transient($this->license_notice_transient_key);

        if (!$notice || (($notice['status'] ?? '') === 'valid')) {
            return;
        }

        $activateUrl = admin_url('admin.php?page=fluent-cart#/settings/licensing');
        $pluginTitle = $this->plugin_title ?: $this->slug;

        $message = sprintf(
            __('%1$s updates require an active FluentCart Pro license. Please activate your FluentCart Pro license to receive updates.', 'fluent-cart-migrator'),
            esc_html($pluginTitle)
        );

        printf(
            '<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
            wp_kses_post($message),
            esc_url($activateUrl),
            esc_html__('Activate License', 'fluent-cart-migrator')
        );
    }

    /**
     * Try to get parent product license info from FluentCart Pro stored settings.
     *
     * @return array
     */
    private function get_parent_license_info()
    {
        $settingsKey = '__fluent-cart-pro_sl_info';
        $licenseInfo = get_option($settingsKey, []);

        if (!empty($licenseInfo['license_key'])) {
            return [
                'license_key' => $licenseInfo['license_key'] ?? '',
                'activation_hash' => $licenseInfo['activation_hash'] ?? '',
            ];
        }

        return ['license_key' => '', 'activation_hash' => ''];
    }

    private function maybe_delete_transients()
    {
        global $pagenow;

        if ('update-core.php' === $pagenow && isset($_GET['force-check'])) {
            $this->delete_transients();
        }

        if (isset($_GET['fluent-cart-migrator-check-update'])) {
            if (current_user_can('update_plugins')) {
                $this->delete_transients();

                remove_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);

                $update_cache = get_site_transient('update_plugins');
                if ($update_cache && is_object($update_cache)) {
                    if (!empty($update_cache->response[$this->name])) {
                        unset($update_cache->response[$this->name]);
                    }
                    if (!empty($update_cache->no_update[$this->name])) {
                        unset($update_cache->no_update[$this->name]);
                    }
                }

                $update_cache = $this->check_transient_data($update_cache);

                set_site_transient('update_plugins', $update_cache);

                add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);

                wp_redirect(admin_url('plugins.php?s=fluent-cart-migrator&plugin_status=all'));
                exit();
            }
        }
    }

    public function delete_transients()
    {
        $this->delete_transient($this->response_transient_key);
        $this->delete_transient($this->license_notice_transient_key);
    }

    protected function delete_transient($cache_key)
    {
        delete_option($cache_key);
    }

    protected function get_transient($cache_key)
    {
        $cache_data = get_option($cache_key);

        if (empty($cache_data['timeout']) || current_time('timestamp') > $cache_data['timeout']) {
            // Cache is expired.
            return false;
        }

        return $cache_data['value'];
    }

    protected function set_transient($cache_key, $value, $expiration = 0)
    {
        if (empty($expiration)) {
            $expiration = strtotime('+12 hours', current_time('timestamp'));
        }

        $data = [
            'timeout' => $expiration,
            'value' => $value,
        ];

        update_option($cache_key, $data, false);
    }
}
