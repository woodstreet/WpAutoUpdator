<?php

namespace Celerate\WpAutoUpdator;

class WpAutoUpdator
{
    private $pluginData;
    private $baseUrl;

    public function __construct($pluginFilePath, $baseUrl)
    {
        if (is_admin()) {
            $this->baseUrl = $baseUrl;
            $this->loadPluginData($pluginFilePath);
            add_filter('plugins_api', [$this, 'getPluginInfo'], 20, 3);
            add_filter('site_transient_update_plugins', [$this, 'checkForUpdate']);
        }
    }

    private function loadPluginData($pluginFilePath)
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $this->pluginData = get_plugin_data($pluginFilePath);
        $this->pluginData['file_path'] = plugin_basename($pluginFilePath);
        $this->pluginData['slug'] = dirname($this->pluginData['file_path']);
    }

    public function getPluginInfo($res, $action, $args)
    {
        if ('plugin_information' !== $action || $this->pluginData['slug'] !== $args->slug) {
            return $res;
        }

        $remote = wp_remote_get(
            "{$this->baseUrl}/api/plugin/" . $args->slug,
            [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/json'],
            ]
        );

        if (
            is_wp_error($remote) ||
            200 !== wp_remote_retrieve_response_code($remote) ||
            empty(wp_remote_retrieve_body($remote))
        ) {
            return $res;
        }

        $remote = json_decode(wp_remote_retrieve_body($remote));

        $res = new \stdClass();
        $res->name = $remote->name;
        $res->slug = $remote->slug;
        $res->author = $remote->author;
        $res->author_profile = $remote->author_profile;
        $res->version = $remote->version;
        $res->tested = $remote->tested;
        $res->requires = $remote->requires;
        $res->requires_php = $remote->requires_php;
        $res->download_link = $remote->download_url;
        $res->trunk = $remote->download_url;
        $res->last_updated = $remote->last_updated;
        $res->sections = (array) $remote->sections;

        if (!empty($remote->banners)) {
            $res->banners = (array) $remote->banners;
        }

        return $res;
    }

    public function checkForUpdate($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = wp_remote_get(
            "{$this->baseUrl}/api/plugin/" . $this->pluginData['slug'],
            [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/json'],
            ]
        );

        if (
            is_wp_error($remote) ||
            200 !== wp_remote_retrieve_response_code($remote) ||
            empty(wp_remote_retrieve_body($remote))
        ) {
            return $transient;
        }

        $remote_data = json_decode(wp_remote_retrieve_body($remote));

        if (
            $remote_data &&
            version_compare($this->pluginData['Version'], $remote_data->version, '<') &&
            version_compare($remote_data->requires, get_bloginfo('version'), '<=') &&
            version_compare($remote_data->requires_php, PHP_VERSION, '<=')
        ) {
            $plugin_update = new \stdClass();
            $plugin_update->slug = $remote_data->slug;
            $plugin_update->plugin = $this->pluginData['file_path'];
            $plugin_update->new_version = $remote_data->version;
            $plugin_update->tested = $remote_data->tested;
            $plugin_update->package = $remote_data->download_url;

            $transient->response[$plugin_update->plugin] = $plugin_update;
        }

        return $transient;
    }
}
