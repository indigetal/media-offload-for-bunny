<?php
/**
 * Central plugin bootstrap handoff.
 *
 * @package Bunny_Offload\Bootstrap
 */

namespace Bunny_Offload\Bootstrap;

use Bunny_Offload\Admin\BunnyMediaPreviewAssets;
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
        new BunnyMediaPreviewAssets();
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
         * admin media preview assets, Stream metadata sync, REST status routes, public Storage URL
         * rewriting, Storage offload, user integration, and Stream Media Library hooks.
         *
         * @since 0.8.2
         */
        do_action('indigetal_offload_loaded');

        add_action('admin_init', [self::class, 'registerPrivacySuggestedContent']);
    }

    /**
     * Register suggested privacy policy copy for Tools → Privacy → Policy guide.
     *
     * Mirrors readme `== Privacy ==` and the About & Privacy tab (factual disclosure only).
     *
     * @return void
     */
    public static function registerPrivacySuggestedContent() {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $html = self::getPrivacyPolicySuggestedHtml();

        wp_add_privacy_policy_content(
            __('Indigetal Media Offload for Bunny.net', 'indigetal-media-offload-for-bunny-net'),
            wp_kses_post($html)
        );
    }

    /**
     * Build HTML for {@see registerPrivacySuggestedContent()}.
     *
     * @return string
     */
    private static function getPrivacyPolicySuggestedHtml() {
        $link_allowed = [
            'a'      => [
                'href'   => true,
                'target' => true,
                'rel'    => true,
            ],
            'strong' => [],
        ];

        $html = '';

        $html .= '<p>' . wp_kses(
            sprintf(
                /* translators: 1: Bunny.net terms URL, 2: Bunny.net privacy URL */
                __('This plugin sends media and configuration-related data to <strong>Bunny.net</strong> only when you enable Stream and/or Storage features and use the workflows this plugin provides. See Bunny.net’s <a href="%1$s" target="_blank" rel="noopener noreferrer">Terms of Service</a> and <a href="%2$s" target="_blank" rel="noopener noreferrer">privacy policy</a>.', 'indigetal-media-offload-for-bunny-net'),
                esc_url('https://bunny.net/tos/'),
                esc_url('https://bunny.net/privacy/')
            ),
            $link_allowed
        ) . '</p>';

        $html .= '<p>' . esc_html__('Stream (when enabled): the site’s server makes requests to Bunny Stream API hosts such as video.bunnycdn.com to create, update, inspect, and delete videos and collections. Playback-related URLs may use hostnames such as player.mediadelivery.net and the Stream Pull Zone hostname you configure in settings.', 'indigetal-media-offload-for-bunny-net') . '</p>';

        $html .= '<p>' . esc_html__('Storage (when enabled): the site’s server makes requests to the regional Bunny Storage API host for the zone you select, and public file URLs use the Storage Pull Zone hostname you configure.', 'indigetal-media-offload-for-bunny-net') . '</p>';

        $html .= '<p>' . esc_html__('Credentials: API keys, passwords, zone identifiers, and related options are stored in the WordPress database (encrypted where this plugin applies encryption) and are used only to contact Bunny services on your behalf.', 'indigetal-media-offload-for-bunny-net') . '</p>';

        $html .= '<p>' . esc_html__('On Media → Indigetal Media Offload for Bunny.net → Settings, help text may link to Bunny.net documentation on docs.bunny.net, the customer dashboard on dash.bunny.net, a support article on support.bunny.net, and CDN product pages used to explain pull-zone hostnames. Opening those links is optional; they remain under Bunny.net’s terms and privacy policy linked above.', 'indigetal-media-offload-for-bunny-net') . '</p>';

        $html .= '<p>' . esc_html__('Delivery scope (Free): this Free release does not generate HMAC-signed or token-authenticated CDN URLs from PHP for Storage or Stream. Whether URLs are publicly readable or restricted is determined by your Bunny Pull Zone, Storage, and Stream configuration outside WordPress.', 'indigetal-media-offload-for-bunny-net') . '</p>';

        $html .= '<p>' . esc_html__('Deleting content: removing a WordPress attachment can remove linked Bunny Storage objects and the Stream video where that behavior is implemented. By default, uninstall keeps this plugin’s settings, saved credentials, offload records, and media-related metadata unless you enable Advanced → Remove plugin-owned WordPress data on uninstall on the Settings tab; uninstall never deletes your site’s media files on disk or removes objects in your Bunny Storage zones or Stream library. See About & Privacy under the same Media menu for the same details.', 'indigetal-media-offload-for-bunny-net') . '</p>';

        return $html;
    }

    /**
     * Clear scheduled events on plugin deactivation.
     *
     * @return void
     */
    public static function deactivate() {
        self::clearScheduledHookEvents('indigetal_offload_sync_video_thumbnail');
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
}
