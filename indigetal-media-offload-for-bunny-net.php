<?php
/**
 * Plugin Name: Indigetal Media Offload for Bunny.net
 * Plugin URI: https://wordpress.org/plugins/indigetal-media-offload-for-bunny-net/
 * Description: Seamlessly offload the WordPress Media Library to Bunny Storage and stream videos from Bunny Stream.
 * Version: 1.0.5
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Indigetal WebCraft
 * Author URI: https://indigetal.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: indigetal-media-offload-for-bunny-net
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Semantic version of the Free plugin runtime (keep in sync with the Version header).
 */
define('INDIGETAL_OFFLOAD_VERSION', '1.0.5');

if (!function_exists('indigetal_offload_free_version')) {
    /**
     * Return the active Free plugin version string for Pro add-on compatibility checks.
     *
     * Pro must not ship code that loads from Free; call this function from Pro after Free is active.
     *
     * @return string
     */
    function indigetal_offload_free_version() {
        return INDIGETAL_OFFLOAD_VERSION;
    }
}

if (!function_exists('indigetal_offload_clear_removed_tool_cron_jobs')) {
    /**
     * Clears Storage/Stream tool batch crons from older installs after the Tools stack was removed from Free.
     *
     * Hook names live here (not under includes/) so static scans stay aligned with the Free extraction plan.
     *
     * @return void
     */
    function indigetal_offload_clear_removed_tool_cron_jobs() {
        $legacy_hooks = [
            'indigetal_offload_process_tool_batch',
            'indigetal_offload_process_stream_tool_batch',
            'indigetal_offload_cleanup_stream_upload_placeholder',
        ];

        foreach ($legacy_hooks as $hook) {
            wp_clear_scheduled_hook($hook);

            $cron = _get_cron_array();

            if (!is_array($cron)) {
                continue;
            }

            foreach ($cron as $timestamp => $events) {
                if (empty($events[$hook]) || !is_array($events[$hook])) {
                    continue;
                }

                foreach ($events[$hook] as $event) {
                    $args = isset($event['args']) && is_array($event['args']) ? $event['args'] : [];
                    wp_unschedule_event((int) $timestamp, $hook, $args);
                }
            }
        }
    }
}

spl_autoload_register(
    static function ($class) {
        $prefix = 'Bunny_Offload\\';

        if (0 !== strpos($class, $prefix)) {
            return;
        }

        $relative_class = substr($class, strlen($prefix));

        if (false === $relative_class || '' === $relative_class) {
            return;
        }

        $file = __DIR__ . '/includes/' . str_replace('\\', '/', $relative_class) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
);

add_action('plugins_loaded', [\Bunny_Offload\Bootstrap\BunnyPlugin::class, 'boot']);
register_deactivation_hook(__FILE__, 'indigetal_offload_clear_removed_tool_cron_jobs');
register_deactivation_hook(__FILE__, [\Bunny_Offload\Bootstrap\BunnyPlugin::class, 'deactivate']);
