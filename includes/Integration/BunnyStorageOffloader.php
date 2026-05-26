<?php

namespace Bunny_Offload\Integration;

use Bunny_Offload\Bunny\BunnyStorageClient;
use Bunny_Offload\Settings\BunnyConfigurationStore;
use Bunny_Offload\Utils\BunnyLogger;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Phase 2 offloader for attachment originals.
 */
class BunnyStorageOffloader {
    /**
     * Bunny Storage HTTP client.
     *
     * @var BunnyStorageClient
     */
    private $storage_client;

    /**
     * Free-owned direct upload and manifest reconciliation runner.
     *
     * @var BunnyStorageAttachmentRunner
     */
    private $offload_runner;

    /**
     * Request-scoped guard against duplicate original uploads.
     *
     * @var array<string, bool>
     */
    private $processed_request_paths = [];

    /**
     * Request-scoped guard against duplicate metadata reconciliation passes.
     *
     * @var array<string, bool>
     */
    private $processed_metadata_hashes = [];

    /**
     * Constructor.
     */
    public function __construct() {
        $this->storage_client = BunnyStorageClient::getInstance();
        $this->offload_runner = new BunnyStorageAttachmentRunner($this->storage_client);

        add_filter('update_attached_file', [$this, 'handleAttachedFileUpdate'], 10, 2);
        add_filter('wp_update_attachment_metadata', [$this, 'handleAttachmentMetadataUpdate'], 10, 2);
        add_filter('wp_generate_attachment_metadata', [$this, 'handleGeneratedAttachmentMetadata'], 10, 3);
        add_action('added_post_meta', [$this, 'handleBackupSizesMetaChange'], 10, 4);
        add_action('updated_post_meta', [$this, 'handleBackupSizesMetaChange'], 10, 4);
        add_action('delete_attachment', [$this, 'handleAttachmentDelete'], 10, 1);
    }

    /**
     * Offload non-video attachment originals when WordPress finalizes the attached file path.
     *
     * @param string $file          Attached file path.
     * @param int    $attachment_id Attachment ID.
     * @return string
     */
    public function handleAttachedFileUpdate($file, $attachment_id) {
        $attachment_id = absint($attachment_id);
        $file = is_string($file) ? $file : '';

        if ($attachment_id < 1 || '' === $file) {
            return $file;
        }

        if (!BunnyConfigurationStore::isStorageOffloadPipelineReady()) {
            return $file;
        }

        if ($this->isCoreUpgraderAttachment($attachment_id)) {
            return $file;
        }

        if (!BunnyAttachmentManifest::isSupportedAttachment($attachment_id)) {
            return $file;
        }

        $request_key = $this->buildRequestKey($attachment_id, $file);

        if (isset($this->processed_request_paths[$request_key])) {
            return $file;
        }

        if (is_wp_error($this->offload_runner->offloadOriginalFile($attachment_id, $file))) {
            return $file;
        }

        $this->processed_request_paths[$request_key] = true;

        return $file;
    }

    /**
     * Reconcile generated image files when WordPress updates attachment metadata.
     *
     * @param mixed $metadata      Attachment metadata.
     * @param int   $attachment_id Attachment ID.
     * @return mixed
     */
    public function handleAttachmentMetadataUpdate($metadata, $attachment_id) {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1 || !is_array($metadata)) {
            return $metadata;
        }

        if (!$this->shouldReconcileAttachment($attachment_id)) {
            return $metadata;
        }

        $file_set = BunnyAttachmentManifest::buildAttachmentFileSetFromMetadata($attachment_id, $metadata);
        $reconciliation_key = $this->buildMetadataReconciliationKey($attachment_id, $file_set, 'update');

        if ('' === $reconciliation_key || isset($this->processed_metadata_hashes[$reconciliation_key])) {
            return $metadata;
        }

        $this->offload_runner->reconcileAttachmentFileSet($attachment_id, $file_set, false);
        $this->processed_metadata_hashes[$reconciliation_key] = true;

        return $metadata;
    }

    /**
     * Finalize image reconciliation after WordPress completes its generation pass.
     *
     * @param mixed  $metadata      Attachment metadata.
     * @param int    $attachment_id Attachment ID.
     * @param string $context       Generation context such as `create` or `update`.
     * @return mixed
     */
    public function handleGeneratedAttachmentMetadata($metadata, $attachment_id, $context) {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1 || !is_array($metadata)) {
            return $metadata;
        }

        if (!$this->shouldReconcileAttachment($attachment_id)) {
            return $metadata;
        }

        $file_set = BunnyAttachmentManifest::buildAttachmentFileSetFromMetadata($attachment_id, $metadata);
        $reconciliation_key = $this->buildMetadataReconciliationKey($attachment_id, $file_set, 'finalize');

        if ('' === $reconciliation_key || isset($this->processed_metadata_hashes[$reconciliation_key])) {
            return $metadata;
        }

        $this->offload_runner->reconcileAttachmentFileSet($attachment_id, $file_set, true);
        $this->processed_metadata_hashes[$reconciliation_key] = true;

        return $metadata;
    }

    /**
     * Reconcile backup-size files after WordPress stores `_wp_attachment_backup_sizes`.
     *
     * @param int    $meta_id    Meta row ID.
     * @param int    $object_id  Object ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value.
     * @return void
     */
    public function handleBackupSizesMetaChange($meta_id, $object_id, $meta_key, $meta_value) {
        $object_id = absint($object_id);
        $meta_key = is_string($meta_key) ? $meta_key : '';

        if ($object_id < 1 || '_wp_attachment_backup_sizes' !== $meta_key) {
            return;
        }

        if (!$this->shouldReconcileAttachment($object_id)) {
            return;
        }

        $file_set = BunnyAttachmentManifest::buildAttachmentFileSet($object_id);
        $reconciliation_key = $this->buildMetadataReconciliationKey($object_id, $file_set, 'backup');

        if ('' === $reconciliation_key || isset($this->processed_metadata_hashes[$reconciliation_key])) {
            return;
        }

        $this->offload_runner->reconcileAttachmentFileSet($object_id, $file_set, false);
        $this->processed_metadata_hashes[$reconciliation_key] = true;
    }

    /**
     * Delete manifest-tracked remote files when a non-video attachment is deleted.
     *
     * WordPress core remains responsible for any remaining local files. This path
     * only cleans up Bunny Storage objects and then clears the attachment's Phase 2
     * manifest/summary meta.
     *
     * @param int $attachment_id Attachment ID.
     * @return void
     */
    public function handleAttachmentDelete($attachment_id) {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1 || $this->isCoreUpgraderAttachment($attachment_id)) {
            return;
        }

        if (!BunnyAttachmentManifest::isSupportedAttachment($attachment_id)) {
            return;
        }

        $manifest = BunnyAttachmentManifest::getRawManifest($attachment_id);
        $summary_state = BunnyAttachmentManifest::getSummaryState($attachment_id);

        if (empty($manifest) && BunnyAttachmentManifest::SUMMARY_STATE_LOCAL === $summary_state) {
            return;
        }

        foreach ($manifest as $relative_path => $manifest_entry) {
            $remote_path = isset($manifest_entry['remote_path']) ? (string) $manifest_entry['remote_path'] : '';

            if ('' === $remote_path) {
                continue;
            }

            $delete_response = $this->storage_client->deleteFile($remote_path);

            if (is_wp_error($delete_response)) {
                if ($this->isAlreadyMissingRemoteDelete($delete_response)) {
                    BunnyLogger::log(
                        "handleAttachmentDelete: Remote file already missing for attachment {$attachment_id}: {$remote_path}",
                        'info'
                    );
                    continue;
                }

                BunnyLogger::log(
                    "handleAttachmentDelete: Failed deleting remote file for attachment {$attachment_id} ({$relative_path}): " . $delete_response->get_error_message(),
                    'warning'
                );
                continue;
            }

            BunnyLogger::log(
                "handleAttachmentDelete: Deleted remote file for attachment {$attachment_id}: {$remote_path}",
                'info'
            );
        }

        BunnyAttachmentManifest::deleteOffloadMeta($attachment_id);
    }

    /**
     * Read-only Storage manifest accessor for Pro and companion add-ons.
     *
     * Stable Free extension surface: method name, parameters, and return shape are maintained
     * for add-on compatibility unless a release explicitly documents a breaking change. Does
     * not call Bunny APIs. Returns normalized manifest rows from post meta via
     * BunnyAttachmentManifest::getRawManifest() (not the manifest passed through
     * indigetal_offload_attachment_manifest).
     *
     * @param int $attachment_id Attachment ID.
     * @return array<string, array<string, string>> Manifest path key => row fields.
     */
    public static function getAttachmentStorageManifest($attachment_id) {
        return BunnyAttachmentManifest::getRawManifest(absint($attachment_id));
    }

    /**
     * Read-only Storage offload status snapshot for Pro and companion add-ons.
     *
     * Stable Free extension surface: method name, parameters, and return keys are maintained
     * for add-on compatibility unless a release explicitly documents a breaking change. Does
     * not call Bunny APIs. Keys: summary_state (manifest summary), last_error, has_manifest_errors.
     *
     * @param int $attachment_id Attachment ID.
     * @return array<string, mixed> {
     *     @type string      $summary_state       Manifest summary state constant.
     *     @type string|null  $last_error          Last offload error message, if any.
     *     @type bool         $has_manifest_errors Whether any manifest row is in an error state.
     * }
     */
    public static function getAttachmentStorageOffloadStatus($attachment_id) {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1) {
            return [
                'summary_state'          => BunnyAttachmentManifest::SUMMARY_STATE_LOCAL,
                'last_error'             => null,
                'has_manifest_errors'    => false,
            ];
        }

        return [
            'summary_state'          => BunnyAttachmentManifest::getSummaryState($attachment_id),
            'last_error'             => BunnyAttachmentManifest::getLastOffloadError($attachment_id),
            'has_manifest_errors'    => BunnyAttachmentManifest::hasErrorEntries($attachment_id),
        ];
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
     * Determine whether the attachment should run live image reconciliation.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private function shouldReconcileAttachment($attachment_id) {
        if (!BunnyConfigurationStore::isStorageOffloadPipelineReady()) {
            return false;
        }

        if ($this->isCoreUpgraderAttachment($attachment_id)) {
            return false;
        }

        return BunnyAttachmentManifest::isSupportedAttachment($attachment_id) && $this->isImageAttachment($attachment_id);
    }

    /**
     * Build a request-scoped dedupe key for a metadata reconciliation pass.
     *
     * @param int    $attachment_id Attachment ID.
     * @param array  $file_set      Desired file set.
     * @param string $phase         Reconciliation stage within the current request.
     * @return string
     */
    private function buildMetadataReconciliationKey($attachment_id, array $file_set, $phase = 'update') {
        if ($attachment_id < 1 || empty($file_set)) {
            return '';
        }

        return $attachment_id . ':' . sanitize_key((string) $phase) . ':' . md5((string) wp_json_encode($file_set));
    }

    /**
     * Treat already-missing remote files as non-fatal delete cases.
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
     * Determine whether the attachment belongs to a core plugin/theme upgrader package upload.
     *
     * Core creates temporary private attachments for uploaded plugin/theme ZIP files.
     * The `_wp_attachment_context = upgrader` meta is added after the initial
     * `update_attached_file` call, so we also inspect the current request for the
     * canonical core upgrader actions and uploaded form fields.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private function isCoreUpgraderAttachment($attachment_id) {
        $attachment_context = sanitize_key((string) get_post_meta($attachment_id, '_wp_attachment_context', true));

        if ('upgrader' === $attachment_context) {
            return true;
        }

        // Read-only request classification for core upgrader uploads (security-nonce-audit.md).
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        $request_action = '';

        if (isset($_REQUEST['action'])) {
            $request_action = sanitize_key(wp_unslash($_REQUEST['action']));
        }

        if (in_array($request_action, ['upload-plugin', 'upload-theme'], true)) {
            // phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
            return true;
        }

        $is_upgrader_upload = $this->hasCoreUpgraderUploadField('pluginzip') || $this->hasCoreUpgraderUploadField('themezip');
        // phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

        return $is_upgrader_upload;
    }

    /**
     * Determine whether the current request includes a core upgrader upload field.
     *
     * @param string $field_name File field name.
     * @return bool
     */
    private function hasCoreUpgraderUploadField($field_name) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only request classification; filename sanitized below.
        if (!isset($_FILES[$field_name]) || !is_array($_FILES[$field_name])) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only request classification; filename sanitized on next line.
        $file_name = isset($_FILES[$field_name]['name']) ? wp_unslash($_FILES[$field_name]['name']) : '';
        $file_name = is_string($file_name) ? sanitize_file_name($file_name) : '';

        return '' !== $file_name;
    }

    /**
     * Build a request-scoped dedupe key for an attachment/file combination.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $file          Attached file path.
     * @return string
     */
    private function buildRequestKey($attachment_id, $file) {
        return $attachment_id . ':' . wp_normalize_path((string) $file);
    }
}
