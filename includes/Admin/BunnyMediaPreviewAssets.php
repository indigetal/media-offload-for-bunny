<?php
/**
 * Admin Media Library preview assets.
 *
 * @package Bunny_Offload\Admin
 */

namespace Bunny_Offload\Admin;

use Bunny_Offload\REST\BunnyStreamStatusController;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers wp-admin assets for Bunny Stream processing preview notices.
 */
class BunnyMediaPreviewAssets {
    private const SCRIPT_HANDLE = 'indigetal-offload-stream-preview-notice';
    private const STYLE_HANDLE = 'indigetal-offload-stream-preview-notice';
    private const SCRIPT_PATH = 'assets/js/stream-preview-notice.js';
    private const STYLE_PATH = 'assets/css/stream-preview-notice.css';
    private const POLLING_INTERVAL_SECONDS = 5;
    private const POLLING_TIMEOUT_SECONDS = 600;

    /**
     * Register admin hooks.
     */
    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueueMediaPreviewAssets']);
    }

    /**
     * Enqueue assets only on wp-admin screens that can open Media Library previews.
     *
     * @param string $hook_suffix Current admin page hook suffix.
     * @return void
     */
    public function enqueueMediaPreviewAssets($hook_suffix) {
        if (!$this->shouldEnqueueForScreen((string) $hook_suffix)) {
            return;
        }

        $config_json = wp_json_encode($this->getLocalizedConfig());
        if (false === $config_json) {
            return;
        }

        wp_enqueue_media();

        $script_url = plugins_url(self::SCRIPT_PATH, dirname(__DIR__, 2) . '/indigetal-media-offload-for-bunny-net.php');
        $style_url = plugins_url(self::STYLE_PATH, dirname(__DIR__, 2) . '/indigetal-media-offload-for-bunny-net.php');

        wp_enqueue_style(
            self::STYLE_HANDLE,
            $style_url,
            [],
            $this->getAssetVersion(self::STYLE_PATH)
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $script_url,
            ['jquery', 'media-views', 'wp-api-fetch'],
            $this->getAssetVersion(self::SCRIPT_PATH),
            true
        );

        wp_add_inline_script(
            self::SCRIPT_HANDLE,
            'window.indigetalOffloadStreamPreviewNotice = ' . $config_json . ';',
            'before'
        );
    }

    /**
     * Whether the current admin page can use Media Library or media modal previews.
     *
     * @param string $hook_suffix Current admin page hook suffix.
     * @return bool
     */
    private function shouldEnqueueForScreen($hook_suffix) {
        $allowed_hook_suffixes = [
            'upload.php',
            'media-new.php',
            'post.php',
            'post-new.php',
        ];

        return in_array($hook_suffix, $allowed_hook_suffixes, true);
    }

    /**
     * Build localized configuration for the admin preview script.
     *
     * @return array<string, mixed>
     */
    private function getLocalizedConfig() {
        return [
            'restRoot'               => esc_url_raw(rest_url()),
            'restNamespace'          => BunnyStreamStatusController::REST_NAMESPACE,
            'statusRoute'            => '/' . BunnyStreamStatusController::REST_NAMESPACE . BunnyStreamStatusController::REST_ROUTE,
            'nonce'                  => wp_create_nonce('wp_rest'),
            'pollingIntervalSeconds' => self::POLLING_INTERVAL_SECONDS,
            'pollingTimeoutSeconds'  => self::POLLING_TIMEOUT_SECONDS,
            'classes'                => [
                'overlay' => 'indigetal-offload-stream-preview-notice',
                'spinner' => 'indigetal-offload-stream-preview-notice__spinner',
                'message' => 'indigetal-offload-stream-preview-notice__message',
            ],
            'dataAttributes'         => [
                'processing' => 'data-indigetal-offload-stream-processing',
                'attachment' => 'data-indigetal-offload-attachment-id',
            ],
            'messages'               => [
                'processing' => __('Bunny Stream is still processing this video. The preview will update when it is ready.', 'indigetal-media-offload-for-bunny-net'),
                'ready'      => __('This video is ready on Bunny Stream. Refresh the page to preview the offloaded video.', 'indigetal-media-offload-for-bunny-net'),
                'timeout'    => __('Bunny Stream is still processing this video. Refresh the page later to preview the offloaded video.', 'indigetal-media-offload-for-bunny-net'),
                'error'      => __('The Bunny Stream processing status could not be checked. Refresh the page later to preview the offloaded video.', 'indigetal-media-offload-for-bunny-net'),
            ],
        ];
    }

    /**
     * Resolve an asset version from file mtime when available.
     *
     * @param string $relative_path Plugin-relative asset path.
     * @return string
     */
    private function getAssetVersion($relative_path) {
        $path = dirname(__DIR__, 2) . '/' . ltrim((string) $relative_path, '/');

        if (is_readable($path)) {
            $mtime = filemtime($path);
            if (false !== $mtime) {
                return (string) $mtime;
            }
        }

        return defined('INDIGETAL_OFFLOAD_VERSION') ? INDIGETAL_OFFLOAD_VERSION : '1.0.0';
    }
}
