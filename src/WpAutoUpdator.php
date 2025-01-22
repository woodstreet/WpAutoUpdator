<?php

namespace Celerate\WpAutoUpdator;

class WpAutoUpdator
{
    private static $pluginData;
    private static $baseUrl;

    /**
     * Initialize hooks for plugin updates.
     *
     * @param string $pluginFilePath The main plugin file path.
     * @param string $baseUrl        The base URL for API calls.
     */
    public static function init($pluginFilePath, $baseUrl)
    {
        self::loadPluginData($pluginFilePath);
        self::$baseUrl = rtrim($baseUrl, '/'); // Ensure no trailing slash

        add_action('plugins_loaded', function () {
            add_filter('plugins_api', [__CLASS__, 'getPluginInfo'], 20, 3);
            add_filter('site_transient_update_plugins', [__CLASS__, 'checkForUpdate']);

            error_log('Filters registered');
        });
    }

    /**
     * Load plugin data for dynamic values.
     *
     * @param string $pluginFilePath The main plugin file path.
     */
    private static function loadPluginData($pluginFilePath)
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        self::$pluginData = get_plugin_data($pluginFilePath);
        self::$pluginData['file_path'] = plugin_basename($pluginFilePath);
        self::$pluginData['slug'] = dirname(self::$pluginData['file_path']);
    }

    /**
     * Fetch custom plugin information.
     *
     * @param mixed  $res    The result object. Default null.
     * @param string $action The action being performed.
     * @param object $args   Plugin API arguments.
     *
     * @return mixed Plugin information or the original result.
     */
    public static function getPluginInfo($res, $action, $args)
    {
        if ('plugin_information' !== $action || self::$pluginData['slug'] !== $args->slug) {
            return $res;
        }

        $remote = wp_remote_get(
            self::$baseUrl . '/api/plugin/' . $args->slug,
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/json',
                ],
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

    /**
     * Check for plugin updates and push them to the transient.
     *
     * @param object $transient The transient object.
     *
     * @return object The modified transient object.
     */
    public static function checkForUpdate($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = wp_remote_get(
            self::$baseUrl . '/api/plugin/' . self::$pluginData['slug'],
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/json',
                ],
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
            version_compare(self::$pluginData['Version'], $remote_data->version, '<') &&
            version_compare($remote_data->requires, get_bloginfo('version'), '<=') &&
            version_compare($remote_data->requires_php, PHP_VERSION, '<=')
        ) {
            $plugin_update = new \stdClass();
            $plugin_update->slug = $remote_data->slug;
            $plugin_update->plugin = self::$pluginData['file_path'];
            $plugin_update->new_version = $remote_data->version;
            $plugin_update->tested = $remote_data->tested;
            $plugin_update->package = $remote_data->download_url;

            $transient->response[$plugin_update->plugin] = $plugin_update;
        }

        return $transient;
    }
}
