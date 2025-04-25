<?php

namespace Celerate\WpAutoUpdator;

class WpAutoUpdator
{
    private array $pluginData;
    private string $baseUrl;

    public function __construct(string $pluginFilePath, string $baseUrl)
    {
        $this->baseUrl = $baseUrl;
        $this->loadPluginData($pluginFilePath);

        add_filter('plugins_api', [$this, 'getPluginInfo'], 20, 3);
        add_filter('site_transient_update_plugins', [$this, 'checkForUpdate']);
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

    private function maybeFetchPluginData()
    {
        if ($cached = get_transient("cau_${this->pluginData['slug']}")) {
            return $cached;
        }

        $remote = wp_remote_get(
            "{$this->baseUrl}/api/plugin/" . $this->pluginData['slug'],
            [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/json'],
            ]
        );

        if (is_wp_error($remote)) {
            return $remote;
        }

        if (wp_remote_retrieve_response_code($remote) === 200) {
            $parsed = json_decode(wp_remote_retrieve_body($remote));

            // Store the response for 2.5 minutes...
            set_transient("cau_${this->pluginData['slug']}", $parsed, 150);

            return $parsed;
        }

        return false;
    }

    public function getPluginInfo($result, $action, $args)
    {
        if ('plugin_information' !== $action || $this->pluginData['slug'] !== $args->slug) {
            return $result;
        }

        $remote = $this->maybeFetchPluginData();

        if (is_wp_error($remote) || $remote === false) {
            return $result;
        }

        $result                 = new \stdClass();
        $result->name           = $remote->name;
        $result->slug           = $remote->slug;
        $result->author         = $remote->author;
        $result->author_profile = $remote->author_profile;
        $result->version        = $remote->version;
        $result->tested         = $remote->tested;
        $result->requires       = $remote->requires;
        $result->requires_php   = $remote->requires_php;
        $result->download_link  = $remote->download_url;
        $result->trunk          = $remote->download_url;
        $result->last_updated   = $remote->last_updated;
        $result->sections       = (array) $remote->sections;

        if (!empty($remote->banners)) {
            $result->banners = (array) $remote->banners;
        }

        return $result;
    }

    public function checkForUpdate($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->maybeFetchPluginData();

        if (is_wp_error($remote) || $remote === false) {
            return $transient;
        }

        if (
            $remote &&
            version_compare($this->pluginData['Version'], $remote->version, '<') &&
            version_compare($remote->requires, get_bloginfo('version'), '<=') &&
            version_compare($remote->requires_php, PHP_VERSION, '<=')
        ) {
            $plugin_update = new \stdClass();
            $plugin_update->slug = $remote->slug;
            $plugin_update->plugin = $this->pluginData['file_path'];
            $plugin_update->new_version = $remote->version;
            $plugin_update->tested = $remote->tested;
            $plugin_update->package = $remote->download_url;

            $transient->response[$plugin_update->plugin] = $plugin_update;
        }

        return $transient;
    }
}
