<?php
/**
 * Uninstall handler for Indigetal Media Offload for Bunny.net.
 *
 * By default this file preserves offload-critical WordPress data (settings,
 * encrypted credentials, Storage manifests, Stream attachment metadata, and
 * related state) so a reinstall can resume delivery. It never deletes local
 * media files or remote Bunny Storage/Stream objects.
 *
 * When the operator has enabled advanced cleanup in plugin settings
 * (`indigetal_offload_delete_plugin_data_on_uninstall` stored as exactly `1`),
 * plugin-owned options, transients, user meta, and attachment meta listed below
 * are removed, then the cleanup opt-in option itself is deleted.
 *
 * Runtime-only data (upload/collection lock transients and scheduled
 * thumbnail-sync events) is always cleared so no callbacks target removed code.
 *
 * @package Bunny_Offload
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Option key for the advanced uninstall cleanup checkbox (must match BunnySettings).
 */
const INDIGETAL_OFFLOAD_DELETE_PLUGIN_DATA_OPTION = 'indigetal_offload_delete_plugin_data_on_uninstall';

/**
 * Whether aggressive plugin-data cleanup is enabled (readable without autoloading plugin classes).
 *
 * @return bool
 */
function indigetal_offload_uninstall_should_delete_plugin_data() {
    return '1' === (string) get_option(INDIGETAL_OFFLOAD_DELETE_PLUGIN_DATA_OPTION, '0');
}

// Always: runtime locks and scheduled plugin hooks (not offload-critical delivery records).
indigetal_offload_uninstall_delete_dynamic_transients();
indigetal_offload_uninstall_clear_scheduled_events();

if (!indigetal_offload_uninstall_should_delete_plugin_data()) {
    return;
}

indigetal_offload_uninstall_delete_options();
indigetal_offload_uninstall_delete_transients();
indigetal_offload_uninstall_delete_user_meta();
indigetal_offload_uninstall_delete_post_meta();

delete_option(INDIGETAL_OFFLOAD_DELETE_PLUGIN_DATA_OPTION);

/**
 * Delete plugin-owned options (advanced cleanup only).
 *
 * @return void
 */
function indigetal_offload_uninstall_delete_options() {
    $options = [
        'indigetal_offload_stream_access_key',
        'indigetal_offload_stream_library_id',
        'indigetal_offload_stream_pull_zone',
        'indigetal_offload_stream_enabled',
        'indigetal_offload_remove_local_video_files',
        'indigetal_offload_storage_zone',
        'indigetal_offload_storage_region',
        'indigetal_offload_storage_password',
        'indigetal_offload_storage_pull_zone',
        'indigetal_offload_storage_enabled',
        'indigetal_offload_remove_local_files',
        'indigetal_offload_storage_pull_zone_identity',
    ];

    foreach ($options as $option) {
        delete_option($option);
    }
}

/**
 * Delete plugin-owned fixed transients (advanced cleanup only).
 *
 * @return void
 */
function indigetal_offload_uninstall_delete_transients() {
    $transients = [
        'indigetal_offload_stream_token_config',
        'indigetal_offload_api_retry_after',
        'indigetal_offload_storage_retry_after',
    ];

    foreach ($transients as $transient) {
        delete_transient($transient);
    }
}

/**
 * Delete plugin-owned dynamic transient records (lock rows in options table).
 *
 * @return void
 */
function indigetal_offload_uninstall_delete_dynamic_transients() {
    global $wpdb;

    $prefixes = [
        'indigetal_offload_collection_lock_',
        'indigetal_offload_video_upload_lock_',
    ];

    foreach ($prefixes as $prefix) {
        $transient_like = $wpdb->esc_like('_transient_' . $prefix) . '%';
        $timeout_like = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin transients.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $transient_like,
                $timeout_like
            )
        );
    }
}

/**
 * Delete plugin-owned user meta (advanced cleanup only).
 *
 * @return void
 */
function indigetal_offload_uninstall_delete_user_meta() {
    delete_metadata('user', 0, '_indigetal_offload_collection_id', '', true);
}

/**
 * Delete plugin-owned attachment/post/page meta (advanced cleanup only).
 *
 * @return void
 */
function indigetal_offload_uninstall_delete_post_meta() {
    $meta_keys = [
        '_indigetal_offload_video_id',
        '_indigetal_offload_iframe_url',
        '_indigetal_offload_thumbnail_url',
        '_indigetal_offload_video_width',
        '_indigetal_offload_video_height',
        '_indigetal_offload_video_last_remote_refresh_attempt_at',
        '_indigetal_offload_video_last_successful_remote_refresh_at',
        '_indigetal_offload_video_title_dirty',
        '_indigetal_offload_video_description_dirty',
        '_indigetal_offload_video_last_synced_title',
        '_indigetal_offload_video_last_synced_description',
        '_indigetal_offload_video_title_sync_error',
        '_indigetal_offload_video_description_sync_error',
        '_indigetal_offloaded',
        '_indigetal_offload_manifest',
        '_indigetal_offload_last_error',
    ];

    foreach ($meta_keys as $meta_key) {
        delete_metadata('post', 0, $meta_key, '', true);
    }
}

/**
 * Clear plugin-owned scheduled events without running their callbacks.
 *
 * @return void
 */
function indigetal_offload_uninstall_clear_scheduled_events() {
    $hooks = [
        'indigetal_offload_sync_video_thumbnail',
    ];

    foreach ($hooks as $hook) {
        indigetal_offload_uninstall_clear_scheduled_hook($hook);
    }
}

/**
 * Clear all scheduled events for a hook, including events with arguments.
 *
 * @param string $hook Scheduled event hook.
 * @return void
 */
function indigetal_offload_uninstall_clear_scheduled_hook($hook) {
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
