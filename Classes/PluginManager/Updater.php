<?php

namespace FluentCartMigrator\Classes\PluginManager;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Updater
{
    private $api_url = '';
    private $api_data = array();
    private $name = '';
    private $slug = '';
    private $version = '';
    private $license_status = '';
    private $admin_page_url = '';
    private $purchase_url = '';
    private $plugin_title = '';

    private $response_transient_key;

    function __construct($_api_url, $_plugin_file, $_api_data = null, $_plugin_update_data = [])
    {
        $this->api_url = trailingslashit($_api_url);
        $this->api_data = $_api_data;
        $this->name = plugin_basename($_plugin_file);
        $this->slug = basename($_plugin_file, '.php');

        $this->response_transient_key = md5(sanitize_key($this->name) . 'response_transient');

        $this->version = $_api_data['version'];

        if (is_array($_plugin_update_data)
            && isset($_plugin_update_data['license_status'], $_plugin_update_data['admin_page_url'], $_plugin_update_data['purchase_url'], $_plugin_update_data['plugin_title'])
        ) {
            $this->license_status = $_plugin_update_data['license_status'];
            $this->admin_page_url = $_plugin_update_data['admin_page_url'];
            $this->purchase_url   = $_plugin_update_data['purchase_url'];
            $this->plugin_title   = $_plugin_update_data['plugin_title'];
        }

        $this->init();
    }

    public function init()
    {
        $this->maybe_delete_transients();

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'), 51);
        add_action('delete_site_transient_update_plugins', [$this, 'delete_transients']);

        add_filter('plugins_api', array($this, 'plugins_api_filter'), 10, 3);
        remove_action('after_plugin_row_' . $this->name, 'wp_plugin_update_row');

        add_action('after_plugin_row_' . $this->name, [$this, 'show_update_notification'], 10, 2);
    }

    function check_update($_transient_data)
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
            $version_info = $this->api_request('plugin_latest_version', array('slug' => $this->slug));
            if (is_wp_error($version_info)) {
                $version_info = new \stdClass();
                $version_info->error = true;
            }
            $this->set_transient($this->response_transient_key, $version_info);
        }

        if (!empty($version_info->error) || !$version_info) {
            return $_transient_data;
        }

        if (is_object($version_info) && isset($version_info->new_version)) {
            if (version_compare($this->version, $version_info->new_version, '<')) {
                $_transient_data->response[$this->name] = $version_info;
            }
            $_transient_data->last_checked        = time();
            $_transient_data->checked[$this->name] = $this->version;
        }

        return $_transient_data;
    }

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

    function plugins_api_filter($_data, $_action = '', $_args = null)
    {
        if ('plugin_information' !== $_action) {
            return $_data;
        }

        if (!isset($_args->slug) || $_args->slug !== $this->slug) {
            return $_data;
        }

        $cache_key             = $this->slug . '_api_request_' . substr(md5(serialize($this->slug)), 0, 15);
        $api_request_transient = get_site_transient($cache_key);

        if (empty($api_request_transient)) {
            $to_send = array(
                'slug'   => $this->slug,
                'is_ssl' => is_ssl(),
                'fields' => array('banners' => false, 'reviews' => false),
            );
            $api_request_transient = $this->api_request('plugin_information', $to_send);
            set_site_transient($cache_key, $api_request_transient, DAY_IN_SECONDS * 2);
        }

        if (false !== $api_request_transient) {
            $_data = $api_request_transient;
        }

        return $_data;
    }

    private function api_request($_action, $_data)
    {
        $data = array_merge($this->api_data, $_data);

        if ($data['slug'] != $this->slug) {
            return;
        }

        if ($this->api_url == home_url()) {
            return false;
        }

        $siteUrl = is_multisite() ? network_site_url() : home_url();

        $api_params = array(
            'edd_action' => 'get_version',
            'license'    => !empty($data['license']) ? $data['license'] : '',
            'item_id'    => isset($data['item_id']) ? $data['item_id'] : false,
            'slug'       => $data['slug'],
            'author'     => $data['author'],
            'url'        => $siteUrl,
        );

        $request = wp_remote_post($this->api_url, array('timeout' => 15, 'sslverify' => false, 'body' => $api_params));

        if (!is_wp_error($request)) {
            $request = json_decode(wp_remote_retrieve_body($request));
        }

        if ($request && isset($request->sections)) {
            $request->sections = maybe_unserialize($request->sections);
            $request->slug     = $this->slug;
        } else {
            $request = false;
        }

        return $request;
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
    }

    protected function delete_transient($cache_key)
    {
        delete_option($cache_key);
    }

    protected function get_transient($cache_key)
    {
        $cache_data = get_option($cache_key);

        if (empty($cache_data['timeout']) || current_time('timestamp') > $cache_data['timeout']) {
            return false;
        }

        return $cache_data['value'];
    }

    protected function set_transient($cache_key, $value, $expiration = 0)
    {
        if (empty($expiration)) {
            $expiration = strtotime('+12 hours', current_time('timestamp'));
        }

        update_option($cache_key, ['timeout' => $expiration, 'value' => $value], 'no');
    }
}
