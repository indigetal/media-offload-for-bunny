<?php
/**
 * Central plugin bootstrap handoff.
 *
 * @package Bunny_Offload\Bootstrap
 */

namespace Bunny_Offload\Bootstrap;

use Bunny_Offload\Admin\BunnySettings;
use Bunny_Offload\Integration\BunnyCdnUrlRewriter;
use Bunny_Offload\Integration\BunnyMediaLibrary;
use Bunny_Offload\Integration\BunnyMetadataManager;
use Bunny_Offload\Integration\BunnyStorageOffloader;
use Bunny_Offload\Integration\BunnyUserIntegration;
use Bunny_Offload\Integration\BunnyVideoMetadataSync;
use Bunny_Offload\REST\BunnyStreamStatusController;
use Bunny_Offload\Settings\BunnyConfigurationStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Free bootstrap: owns shared settings and encrypted credentials, Storage manifests,
 * Stream attachment metadata, URL rewriting hooks, Media Library integration, and
 * default uninstall-preservation of offload-critical data. `uninstall.php` always clears
 * internal lock transients and the Stream thumbnail sync cron; it deletes options, credentials,
 * manifests, and attachment meta only when the operator has enabled advanced cleanup (see
 * About & Privacy). Pro extends via documented hooks; it must not assume Free uninstall deletes
 * shared options/meta unless the operator has enabled full plugin-data cleanup (see
 * uninstall.php and About & Privacy).
 */
class BunnyPlugin {

    /**
     * Ensure plugin services only boot once per request.
     *
     * @var bool
     */
    private static $booted = false;

    /**
     * Bootstrap plugin services.
     *
     * @return void
     */
    public static function boot() {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        new BunnySettings();
        new BunnyConfigurationStore();
        new BunnyMetadataManager();
        new BunnyVideoMetadataSync();
        new BunnyStreamStatusController();
        new BunnyCdnUrlRewriter();
        new BunnyStorageOffloader();
        new BunnyUserIntegration();
        new BunnyMediaLibrary();

        /**
         * Fires after Free has registered settings, configuration storage, attachment metadata,
         * Stream metadata sync, REST status routes, public Storage URL rewriting, Storage offload,
         * user integration, and Stream Media Library hooks.
         *
         * @since 0.8.2
         */
        do_action('bunny_offload_loaded');
    }

    /**
     * Clear scheduled events on plugin deactivation.
     *
     * @return void
     */
    public static function deactivate() {
        self::clearScheduledHookEvents('bunny_offload_sync_video_thumbnail');
    }

    /**
     * Clear all scheduled events for a hook, including events with arguments.
     *
     * @param string $hook Scheduled event hook.
     * @return void
     */
    private static function clearScheduledHookEvents($hook) {
        wp_clear_scheduled_hook($hook);

        $cron = _get_cron_array();

        if (!is_array($cron)) {
            return;
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

    /**
     * Load plugin translations.
     *
     * @return void
     */
    public static function loadTextdomain() {
        load_plugin_textdomain('media-offload-for-bunny', false, dirname(plugin_basename(__DIR__ . '/../../media-offload-for-bunny.php')) . '/languages');
    }
}
