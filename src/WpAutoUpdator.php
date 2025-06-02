<?php

namespace Celerate\WpAutoUpdator;

class WpAutoUpdator
{
    private string $path;
    private array $pluginData;
    private string $baseUrl;

    public function __construct(string $pluginFilePath, string $baseUrl)
    {
        $this->path = $pluginFilePath;
        $this->baseUrl = $baseUrl;

        add_filter('plugins_api', [$this, 'getPluginInfo'], 20, 3);
        add_filter('site_transient_update_plugins', [$this, 'checkForUpdate']);
    }

    private function lazyLoadPluginData(): void
    {
        $this->pluginData ??= $this->loadPluginData();
    }

    private function loadPluginData(): array
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data($this->path);
        $data['file_path'] = plugin_basename($this->path);
        $data['slug'] = dirname($data['file_path']);

        return $data;
    }

    private function maybeFetchPluginData()
    {
        if ($cached = get_transient("cau_{$this->pluginData['slug']}")) {
            // Freshen the version info
            $cached->version = $this->pluginData['Version'];

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

            // Match to WP schema
            $result                 = new \stdClass();
            $result->name           = $parsed->name;
            $result->slug           = $parsed->slug;
            $result->author         = $parsed->author;
            $result->author_profile = $parsed->author_profile;
            // The new version comes from the server response.
            $result->new_version    = $parsed->version;
            // The current/old version comes from the parsed plugin data.
            $result->version        = $this->pluginData['Version'];
            $result->tested         = $parsed->tested;
            $result->requires       = $parsed->requires;
            $result->requires_php   = $parsed->requires_php;
            $result->download_link  = $parsed->download_url;
            $result->trunk          = $parsed->download_url;
            $result->package        = $parsed->download_url;
            $result->last_updated   = $parsed->last_updated;
            $result->sections       = (array) $parsed->sections;

            if (!empty($parsed->banners)) {
                $result->banners = (array) $parsed->banners;
            }

            // Store the response for 2.5 minutes...
            set_transient("cau_{$this->pluginData['slug']}", $result, 150);

            return $result;
        }

        return false;
    }

    public function getPluginInfo($result, $action, $args)
    {
        $this->lazyLoadPluginData();

        if ('plugin_information' !== $action || strcasecmp($this->pluginData['slug'], $args->slug) !== 0) {
            return $result;
        }

        $remote = $this->maybeFetchPluginData();

        if ($remote === false || is_wp_error($remote)) {
            return $result;
        }

        return $remote;
    }

    public function checkForUpdate($transient)
    {
        $this->lazyLoadPluginData();

        if ( ! is_object( $transient ) )
			return $transient;

        if (!empty($transient->response[$this->pluginData['file_path']])) {
            return $transient;
        }

        $remote = $this->maybeFetchPluginData();

        if ($remote === false || is_wp_error($remote)) {
            return $transient;
        }

        if ($remote) {
            $transient->response[$this->pluginData['file_path']] = $remote;
        }

        return $transient;
    }
}