<?php
/**
 * Free-owned direct Storage upload and manifest reconciliation for Media Library offload.
 *
 * @package Bunny_Offload\Integration
 */

namespace Bunny_Offload\Integration;

use Bunny_Offload\Bunny\BunnyStorageClient;
use Bunny_Offload\Integration\BunnyAttachmentManifest;
use Bunny_Offload\Settings\BunnyConfigurationStore;
use Bunny_Offload\Utils\BunnyLogger;

if (!defined('ABSPATH')) {
    exit;
}

class BunnyStorageAttachmentRunner {

    /**
     * Bunny Storage HTTP client.
     *
     * @var BunnyStorageClient
     */
    private $storage_client;

    /**
     * Constructor.
     *
     * @param BunnyStorageClient|null $storage_client Optional Bunny Storage client.
     */
    public function __construct(?BunnyStorageClient $storage_client = null) {
        $this->storage_client = $storage_client ?: BunnyStorageClient::getInstance();
    }

    /**
     * Offload a non-video attachment original.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $file          Attached file path.
     * @return true|\WP_Error
     */
    public function offloadOriginalFile($attachment_id, $file) {
        $attachment_id = absint($attachment_id);
        $file = is_string($file) ? $file : '';

        if ($attachment_id < 1 || '' === $file) {
            return $this->recordPreflightFailure(
                $attachment_id,
                new \WP_Error(
                    'indigetal_offload_storage_invalid_original',
                    __('A valid attachment ID and file path are required for Bunny Storage offload.', 'indigetal-media-offload-for-bunny-net')
                )
            );
        }

        $original_entry = $this->buildOriginalManifestEntry($file);

        if (is_wp_error($original_entry)) {
            BunnyLogger::log(
                "offloadOriginalFile: Could not build original manifest entry for attachment {$attachment_id}: " . $original_entry->get_error_message(),
                'warning'
            );

            return $this->recordPreflightFailure($attachment_id, $original_entry);
        }

        $response = $this->storage_client->uploadFile($original_entry['local_path'], $original_entry['remote_path']);

        if (is_wp_error($response)) {
            $this->persistManifestEntry(
                $attachment_id,
                $original_entry,
                BunnyAttachmentManifest::FILE_STATE_ERROR,
                $response->get_error_message()
            );

            BunnyLogger::log(
                "offloadOriginalFile: Bunny Storage upload failed for attachment {$attachment_id}: " . $response->get_error_message(),
                'error'
            );

            return $response;
        }

        if (!$this->persistManifestEntry($attachment_id, $original_entry, BunnyAttachmentManifest::FILE_STATE_COMPLETE, '')) {
            BunnyLogger::log(
                "offloadOriginalFile: Failed to persist complete manifest state for attachment {$attachment_id}; skipping local deletion.",
                'error'
            );

            return new \WP_Error(
                'indigetal_offload_storage_manifest_persist_failed',
                __('The Bunny Storage manifest could not be updated after the original upload completed.', 'indigetal-media-offload-for-bunny-net')
            );
        }

        if ($this->isImageAttachment($attachment_id)) {
            BunnyLogger::log(
                "offloadOriginalFile: Deferred deletion of image original for attachment {$attachment_id} until reconciliation finishes.",
                'debug'
            );

            return true;
        }

        $this->deleteLocalFile($original_entry['local_path'], $attachment_id, 'non-image original');

        return true;
    }

    /**
     * Reconcile the current image file set against the stored manifest.
     *
     * @param int   $attachment_id         Attachment ID.
     * @param array $file_set              Desired file set keyed by relative upload path.
     * @param bool  $allow_original_delete Whether the current pass may delete the image original.
     * @return array
     */
    public function reconcileAttachmentFileSet($attachment_id, array $file_set, $allow_original_delete = false) {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1 || empty($file_set)) {
            return [];
        }

        $manifest = BunnyAttachmentManifest::getRawManifest($attachment_id);

        foreach ($file_set as $file_entry) {
            if (!is_array($file_entry)) {
                continue;
            }

            $manifest = $this->reconcileManagedImageFile($attachment_id, $file_entry, $manifest);
        }

        $manifest = $this->cleanupStaleManifestEntries($attachment_id, $manifest, $file_set);

        if ($allow_original_delete) {
            $this->deleteEligibleImageOriginal($attachment_id, $manifest, $file_set);
        }

        return $manifest;
    }

    /**
     * Persist one manifest row and recompute the summary state.
     *
     * @param int        $attachment_id Attachment ID.
     * @param array      $entry         File entry.
     * @param string     $state         File state.
     * @param string     $last_error    Last error message.
     * @param array|null $manifest      Existing manifest.
     * @return array|false
     */
    private function persistManifestEntry($attachment_id, array $entry, $state, $last_error, ?array $manifest = null) {
        if (null === $manifest) {
            $manifest = BunnyAttachmentManifest::getRawManifest($attachment_id);
        }

        $prior_summary = BunnyAttachmentManifest::getSummaryState($attachment_id);

        $manifest[$entry['relative_path']] = [
            'relative_path' => $entry['relative_path'],
            'state'         => $state,
            'remote_path'   => $entry['remote_path'],
            'last_error'    => sanitize_text_field($last_error),
        ];

        BunnyAttachmentManifest::setManifest($attachment_id, $manifest);

        $stored_manifest = BunnyAttachmentManifest::getRawManifest($attachment_id);

        if (
            empty($stored_manifest[$entry['relative_path']])
            || $stored_manifest[$entry['relative_path']]['state'] !== $state
            || $stored_manifest[$entry['relative_path']]['remote_path'] !== $entry['remote_path']
        ) {
            return false;
        }

        $summary_state = $this->deriveSummaryState($stored_manifest);
        BunnyAttachmentManifest::setSummaryState($attachment_id, $summary_state);

        if (
            BunnyAttachmentManifest::FILE_STATE_COMPLETE === $state
            && BunnyAttachmentManifest::SUMMARY_STATE_COMPLETE === $summary_state
            && BunnyAttachmentManifest::SUMMARY_STATE_COMPLETE !== $prior_summary
        ) {
            BunnyAttachmentManifest::clearLastOffloadError($attachment_id);
        }

        return BunnyAttachmentManifest::getSummaryState($attachment_id) === $summary_state ? $stored_manifest : false;
    }

    /**
     * Record a pre-flight offload failure: durable last-error meta plus summary error state.
     *
     * @param int       $attachment_id Attachment ID.
     * @param \WP_Error $error         Error to record and return.
     * @return \WP_Error
     */
    private function recordPreflightFailure($attachment_id, \WP_Error $error) {
        $attachment_id = absint($attachment_id);

        BunnyAttachmentManifest::setLastOffloadErrorFromWpError($attachment_id, $error);
        BunnyAttachmentManifest::setSummaryState($attachment_id, BunnyAttachmentManifest::SUMMARY_STATE_ERROR);

        return $error;
    }

    /**
     * Remove a stale manifest entry and recompute the summary state.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $relative_path Relative upload path.
     * @param array  $manifest      Current manifest.
     * @return array|false
     */
    private function removeManifestEntry($attachment_id, $relative_path, array $manifest) {
        unset($manifest[$relative_path]);
        BunnyAttachmentManifest::setManifest($attachment_id, $manifest);

        $stored_manifest = BunnyAttachmentManifest::getRawManifest($attachment_id);

        if (isset($stored_manifest[$relative_path])) {
            return false;
        }

        $summary_state = $this->deriveSummaryState($stored_manifest);
        BunnyAttachmentManifest::setSummaryState($attachment_id, $summary_state);

        return BunnyAttachmentManifest::getSummaryState($attachment_id) === $summary_state ? $stored_manifest : false;
    }

    /**
     * Derive the attachment summary state from file-level manifest rows.
     *
     * @param array $manifest Normalized manifest.
     * @return string
     */
    private function deriveSummaryState(array $manifest) {
        if (empty($manifest)) {
            return BunnyAttachmentManifest::SUMMARY_STATE_LOCAL;
        }

        $has_complete = false;
        $has_error = false;
        $has_local = false;

        foreach ($manifest as $entry) {
            $state = isset($entry['state']) ? (string) $entry['state'] : BunnyAttachmentManifest::FILE_STATE_LOCAL;

            if (BunnyAttachmentManifest::FILE_STATE_COMPLETE === $state) {
                $has_complete = true;
                continue;
            }

            if (BunnyAttachmentManifest::FILE_STATE_ERROR === $state) {
                $has_error = true;
                continue;
            }

            $has_local = true;
        }

        if ($has_complete && !$has_error && !$has_local) {
            return BunnyAttachmentManifest::SUMMARY_STATE_COMPLETE;
        }

        if ($has_error && !$has_complete && !$has_local) {
            return BunnyAttachmentManifest::SUMMARY_STATE_ERROR;
        }

        if ($has_local && !$has_complete && !$has_error) {
            return BunnyAttachmentManifest::SUMMARY_STATE_LOCAL;
        }

        return BunnyAttachmentManifest::SUMMARY_STATE_PARTIAL;
    }

    /**
     * Reconcile one non-original derived file against the manifest.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $file_entry    Desired file entry.
     * @param array $manifest      Current manifest.
     * @return array
     */
    private function reconcileManagedImageFile($attachment_id, array $file_entry, array $manifest) {
        $role = isset($file_entry['role']) ? (string) $file_entry['role'] : '';

        if ('original' === $role || !$this->isManagedDerivedRole($role)) {
            return $manifest;
        }

        $relative_path = isset($file_entry['relative_path']) ? (string) $file_entry['relative_path'] : '';
        $local_path = isset($file_entry['local_path']) ? (string) $file_entry['local_path'] : '';
        $remote_path = isset($file_entry['remote_path']) ? (string) $file_entry['remote_path'] : '';
        $current_entry = $manifest[$relative_path] ?? null;

        if (
            is_array($current_entry)
            && BunnyAttachmentManifest::FILE_STATE_COMPLETE === ($current_entry['state'] ?? '')
            && $remote_path === (string) ($current_entry['remote_path'] ?? '')
        ) {
            $this->deleteLocalFile($local_path, $attachment_id, "reconciled {$role}");
            return $manifest;
        }

        if ('' === $local_path || !file_exists($local_path)) {
            $stored_manifest = $this->persistManifestEntry(
                $attachment_id,
                $file_entry,
                BunnyAttachmentManifest::FILE_STATE_ERROR,
                __('The local file was missing before Bunny Storage reconciliation.', 'indigetal-media-offload-for-bunny-net'),
                $manifest
            );

            return is_array($stored_manifest) ? $stored_manifest : $manifest;
        }

        $response = $this->storage_client->uploadFile($local_path, $remote_path);

        if (is_wp_error($response)) {
            $stored_manifest = $this->persistManifestEntry(
                $attachment_id,
                $file_entry,
                BunnyAttachmentManifest::FILE_STATE_ERROR,
                $response->get_error_message(),
                $manifest
            );

            return is_array($stored_manifest) ? $stored_manifest : $manifest;
        }

        $stored_manifest = $this->persistManifestEntry(
            $attachment_id,
            $file_entry,
            BunnyAttachmentManifest::FILE_STATE_COMPLETE,
            '',
            $manifest
        );

        if (is_array($stored_manifest)) {
            $this->deleteLocalFile($local_path, $attachment_id, "reconciled {$role}");
            return $stored_manifest;
        }

        return $manifest;
    }

    /**
     * Remove stale manifest entries that no longer appear in metadata.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $manifest      Current manifest.
     * @param array $file_set      Desired file set.
     * @return array
     */
    private function cleanupStaleManifestEntries($attachment_id, array $manifest, array $file_set) {
        if (empty($manifest)) {
            return $manifest;
        }

        $desired_paths = array_fill_keys(array_keys($file_set), true);

        foreach ($manifest as $relative_path => $manifest_entry) {
            if (isset($desired_paths[$relative_path])) {
                continue;
            }

            $remote_path = isset($manifest_entry['remote_path']) ? (string) $manifest_entry['remote_path'] : '';
            $state = isset($manifest_entry['state']) ? (string) $manifest_entry['state'] : BunnyAttachmentManifest::FILE_STATE_LOCAL;
            $local_path = $this->buildLocalPath($relative_path, $attachment_id);

            if (BunnyAttachmentManifest::FILE_STATE_COMPLETE === $state && '' !== $remote_path) {
                $delete_response = $this->storage_client->deleteFile($remote_path);

                if (is_wp_error($delete_response) && !$this->isAlreadyMissingRemoteDelete($delete_response)) {
                    $stored_manifest = $this->persistManifestEntry(
                        $attachment_id,
                        [
                            'relative_path' => $relative_path,
                            'remote_path'   => $remote_path,
                        ],
                        BunnyAttachmentManifest::FILE_STATE_ERROR,
                        $delete_response->get_error_message(),
                        $manifest
                    );

                    $manifest = is_array($stored_manifest) ? $stored_manifest : $manifest;
                    continue;
                }
            }

            $this->deleteLocalFile($local_path, $attachment_id, 'stale derivative');
            $stored_manifest = $this->removeManifestEntry($attachment_id, $relative_path, $manifest);

            if (is_array($stored_manifest)) {
                $manifest = $stored_manifest;
                continue;
            }

            BunnyLogger::log(
                "cleanupStaleManifestEntries: Failed to remove stale manifest entry {$relative_path} for attachment {$attachment_id}.",
                'warning'
            );
        }

        return $manifest;
    }

    /**
     * Delete the image original after the reconcile/finalize pass confirms it.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $manifest      Current manifest.
     * @param array $file_set      Desired file set.
     * @return void
     */
    private function deleteEligibleImageOriginal($attachment_id, array $manifest, array $file_set) {
        foreach ($file_set as $file_entry) {
            if (!is_array($file_entry) || 'original' !== ($file_entry['role'] ?? '')) {
                continue;
            }

            $relative_path = isset($file_entry['relative_path']) ? (string) $file_entry['relative_path'] : '';

            if (
                isset($manifest[$relative_path])
                && BunnyAttachmentManifest::FILE_STATE_COMPLETE === (string) ($manifest[$relative_path]['state'] ?? '')
            ) {
                $this->deleteLocalFile(
                    isset($file_entry['local_path']) ? (string) $file_entry['local_path'] : '',
                    $attachment_id,
                    'image original after reconciliation'
                );
            }

            return;
        }
    }

    /**
     * Build the original manifest entry from the attached file path.
     *
     * @param string $file Attached file path.
     * @return array|\WP_Error
     */
    private function buildOriginalManifestEntry($file) {
        $upload_dir = wp_upload_dir();

        if (!empty($upload_dir['error']) || empty($upload_dir['basedir']) || empty($upload_dir['baseurl'])) {
            return new \WP_Error(
                'indigetal_offload_storage_upload_dir_unavailable',
                __('The WordPress uploads directory is not available for Bunny Storage offload.', 'indigetal-media-offload-for-bunny-net')
            );
        }

        $local_path = $this->normalizeLocalPath($file, $upload_dir);
        $relative_path = $this->normalizeRelativeUploadPath($file, $upload_dir);

        if ('' === $local_path || '' === $relative_path || !file_exists($local_path)) {
            return new \WP_Error(
                'indigetal_offload_storage_invalid_original_path',
                __('The attachment original could not be normalized for Bunny Storage offload.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'file'          => $file,
                    'local_path'    => $local_path,
                    'relative_path' => $relative_path,
                ]
            );
        }

        return [
            'local_path'    => $local_path,
            'relative_path' => $relative_path,
            'remote_path'   => $this->buildRemotePath($relative_path, $upload_dir),
        ];
    }

    /**
     * Normalize an absolute local path from an attached file path.
     *
     * @param string $file       Attached file path.
     * @param array  $upload_dir Current uploads directory data.
     * @return string
     */
    private function normalizeLocalPath($file, array $upload_dir) {
        $file = wp_normalize_path((string) $file);

        if ('' === $file) {
            return '';
        }

        if ($this->pathStartsWith($file, wp_normalize_path((string) $upload_dir['basedir']))) {
            return $file;
        }

        return wp_normalize_path(path_join((string) $upload_dir['basedir'], ltrim($file, '/')));
    }

    /**
     * Normalize a relative uploads path from an attached file path.
     *
     * @param string $file       Attached file path.
     * @param array  $upload_dir Current uploads directory data.
     * @return string
     */
    private function normalizeRelativeUploadPath($file, array $upload_dir) {
        $file = wp_normalize_path((string) $file);
        $basedir = wp_normalize_path((string) $upload_dir['basedir']);

        if ('' === $file) {
            return '';
        }

        if ($this->pathStartsWith($file, $basedir)) {
            $file = ltrim(substr($file, strlen($basedir)), '/');
        }

        return ltrim($file, '/');
    }

    /**
     * Build the remote uploads path using the site's actual uploads URL path.
     *
     * @param string $relative_path Relative uploads path.
     * @param array  $upload_dir    Current uploads directory data.
     * @return string
     */
    private function buildRemotePath($relative_path, array $upload_dir) {
        $base_url_path = wp_parse_url((string) $upload_dir['baseurl'], PHP_URL_PATH);
        $base_url_path = is_string($base_url_path) ? trim(wp_normalize_path($base_url_path), '/') : '';

        if ('' === $base_url_path) {
            return ltrim($relative_path, '/');
        }

        return trailingslashit($base_url_path) . ltrim($relative_path, '/');
    }

    /**
     * Build an absolute local path from a manifest relative path.
     *
     * @param string $relative_path Relative uploads path.
     * @param int    $attachment_id Attachment ID for logging context.
     * @return string
     */
    private function buildLocalPath($relative_path, $attachment_id = 0) {
        $upload_dir = wp_upload_dir();

        if (!empty($upload_dir['error']) || empty($upload_dir['basedir'])) {
            return '';
        }

        $basedir = wp_normalize_path((string) $upload_dir['basedir']);
        $relative_path = ltrim(wp_normalize_path((string) $relative_path), '/');

        if ('' === $relative_path || $this->relativePathLeavesBaseDirectory($relative_path)) {
            BunnyLogger::log(
                "buildLocalPath: Rejected unsafe manifest path for attachment " . absint($attachment_id) . ": " . sanitize_text_field($relative_path),
                'warning'
            );
            return '';
        }

        $local_path = wp_normalize_path(path_join($basedir, $relative_path));

        if (!$this->pathStartsWith($local_path, $basedir)) {
            BunnyLogger::log(
                "buildLocalPath: Rejected manifest path outside uploads for attachment " . absint($attachment_id) . ": " . sanitize_text_field($relative_path),
                'warning'
            );
            return '';
        }

        return $local_path;
    }

    /**
     * Detect relative path traversal segments before building local paths.
     *
     * @param string $relative_path Relative uploads path.
     * @return bool
     */
    private function relativePathLeavesBaseDirectory($relative_path) {
        return in_array('..', explode('/', wp_normalize_path((string) $relative_path)), true);
    }

    /**
     * Delete a local file after its manifest state allows it.
     *
     * @param string $local_path    Absolute local path.
     * @param int    $attachment_id Attachment ID.
     * @param string $context       Logging context.
     * @return void
     */
    private function deleteLocalFile($local_path, $attachment_id, $context = 'managed file') {
        $local_path = wp_normalize_path((string) $local_path);
        $context = sanitize_text_field((string) $context);

        if (!BunnyConfigurationStore::shouldRemoveLocalFiles()) {
            return;
        }

        if ('' === $local_path || !file_exists($local_path)) {
            return;
        }

        $deleted_path = wp_delete_file($local_path);

        if (empty($deleted_path) || file_exists($local_path)) {
            BunnyLogger::log(
                "deleteLocalFile: Failed to delete local {$context} for attachment {$attachment_id}: {$local_path}",
                'warning'
            );
            return;
        }

        BunnyLogger::log(
            "deleteLocalFile: Deleted local {$context} for attachment {$attachment_id}: {$local_path}",
            'info'
        );
    }

    /**
     * Determine whether an attachment is an image.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private function isImageAttachment($attachment_id) {
        return 0 === strpos((string) get_post_mime_type($attachment_id), 'image/');
    }

    /**
     * Determine whether the role is managed by derived-file reconciliation.
     *
     * @param string $role File role.
     * @return bool
     */
    private function isManagedDerivedRole($role) {
        return in_array($role, ['original_image', 'size', 'backup'], true);
    }

    /**
     * Treat already-missing remote files as non-fatal stale cleanup cases.
     *
     * @param \WP_Error $error Delete error from Bunny Storage.
     * @return bool
     */
    private function isAlreadyMissingRemoteDelete($error) {
        $status = 0;

        if ($error instanceof \WP_Error) {
            $error_data = $error->get_error_data('indigetal_offload_storage_http_error');

            if (is_array($error_data) && isset($error_data['status'])) {
                $status = (int) $error_data['status'];
            }
        }

        return 404 === $status;
    }

    /**
     * Determine whether a normalized path starts with a normalized prefix.
     *
     * @param string $path   Normalized path.
     * @param string $prefix Normalized prefix.
     * @return bool
     */
    private function pathStartsWith($path, $prefix) {
        if ('' === $prefix) {
            return false;
        }

        return 0 === strpos($path, trailingslashit($prefix)) || $path === $prefix;
    }
}
