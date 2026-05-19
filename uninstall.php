<?php
/**
 * Uninstall handler for Media Offload for Bunny.net.
 *
 * By default this file preserves offload-critical WordPress data (settings,
 * encrypted credentials, Storage manifests, Stream attachment metadata, and
 * related state) so a reinstall can resume delivery. It never deletes local
 * media files or remote Bunny Storage/Stream objects.
 *
 * When the operator has enabled advanced cleanup in plugin settings
 * (`bunny_offload_delete_plugin_data_on_uninstall` stored as exactly `1`),
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
const BUNNY_OFFLOAD_DELETE_PLUGIN_DATA_OPTION = 'bunny_offload_delete_plugin_data_on_uninstall';

/**
 * Whether aggressive plugin-data cleanup is enabled (readable without autoloading plugin classes).
 *
 * @return bool
 */
function bunny_offload_uninstall_should_delete_plugin_data() {
    return '1' === (string) get_option(BUNNY_OFFLOAD_DELETE_PLUGIN_DATA_OPTION, '0');
}

// Always: runtime locks and scheduled plugin hooks (not offload-critical delivery records).
bunny_offload_uninstall_delete_dynamic_transients();
bunny_offload_uninstall_clear_scheduled_events();

if (!bunny_offload_uninstall_should_delete_plugin_data()) {
    return;
}

bunny_offload_uninstall_delete_options();
bunny_offload_uninstall_delete_transients();
bunny_offload_uninstall_delete_user_meta();
bunny_offload_uninstall_delete_post_meta();

delete_option(BUNNY_OFFLOAD_DELETE_PLUGIN_DATA_OPTION);

/**
 * Delete plugin-owned options (advanced cleanup only).
 *
 * @return void
 */
function bunny_offload_uninstall_delete_options() {
    $options = [
        'bunny_net_access_key',
        'bunny_net_library_id',
        'bunny_net_pull_zone',
        'bunny_net_account_api_key',
        'bunny_offload_stream_enabled',
        'bunny_offload_remove_local_video_files',
        'bunny_net_storage_zone',
        'bunny_net_storage_region',
        'bunny_net_storage_password',
        'bunny_net_storage_pull_zone',
        'bunny_net_url_token_key',
        'bunny_offload_storage_enabled',
        'bunny_offload_remove_local_files',
        'bunny_net_storage_pull_zone_identity',
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
function bunny_offload_uninstall_delete_transients() {
    $transients = [
        'bunny_stream_token_config',
        'bunny_storage_token_state',
        'bunny_api_retry_after',
        'bunny_storage_retry_after',
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
function bunny_offload_uninstall_delete_dynamic_transients() {
    global $wpdb;

    $prefixes = [
        'wpbs_collection_lock_',
        'wpbs_video_upload_lock_',
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
function bunny_offload_uninstall_delete_user_meta() {
    delete_metadata('user', 0, '_bunny_collection_id', '', true);
}

/**
 * Delete plugin-owned attachment/post/page meta (advanced cleanup only).
 *
 * @return void
 */
function bunny_offload_uninstall_delete_post_meta() {
    $meta_keys = [
        '_bunny_video_id',
        '_bunny_iframe_url',
        '_bunny_thumbnail_url',
        '_bunny_video_width',
        '_bunny_video_height',
        '_bunny_video_last_remote_refresh_attempt_at',
        '_bunny_video_last_successful_remote_refresh_at',
        '_bunny_video_title_dirty',
        '_bunny_video_description_dirty',
        '_bunny_video_last_synced_title',
        '_bunny_video_last_synced_description',
        '_bunny_video_title_sync_error',
        '_bunny_video_description_sync_error',
        '_bunny_offloaded',
        '_bunny_offload_manifest',
        '_bunny_offload_last_error',
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
function bunny_offload_uninstall_clear_scheduled_events() {
    $hooks = [
        'bunny_offload_sync_video_thumbnail',
    ];

    foreach ($hooks as $hook) {
        bunny_offload_uninstall_clear_scheduled_hook($hook);
    }
}

/**
 * Clear all scheduled events for a hook, including events with arguments.
 *
 * @param string $hook Scheduled event hook.
 * @return void
 */
function bunny_offload_uninstall_clear_scheduled_hook($hook) {
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
