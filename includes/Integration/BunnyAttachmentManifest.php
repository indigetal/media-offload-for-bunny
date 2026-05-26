<?php

namespace Bunny_Offload\Integration;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Attachment manifest helper for Phase 2 Bunny Storage offload state.
 */
class BunnyAttachmentManifest {
    const SUMMARY_META_KEY = '_indigetal_offloaded';
    const MANIFEST_META_KEY = '_indigetal_offload_manifest';
    const LAST_ERROR_META_KEY = '_indigetal_offload_last_error';

    const SUMMARY_STATE_LOCAL = 'local';
    const SUMMARY_STATE_PARTIAL = 'partial';
    const SUMMARY_STATE_COMPLETE = 'complete';
    const SUMMARY_STATE_ERROR = 'error';

    const FILE_STATE_LOCAL = 'local';
    const FILE_STATE_COMPLETE = 'complete';
    const FILE_STATE_ERROR = 'error';

    /**
     * Return the normalized offload summary state for an attachment.
     *
     * @param int $attachment_id Attachment ID.
     * @return string
     */
    public static function getSummaryState($attachment_id) {
        $attachment_id = absint($attachment_id);

        if (!$attachment_id) {
            return self::SUMMARY_STATE_LOCAL;
        }

        return self::normalizeSummaryState(get_post_meta($attachment_id, self::SUMMARY_META_KEY, true));
    }

    /**
     * Whether any attachment has a non-local Bunny Storage offload summary.
     *
     * Stable Free extension surface: method name, signature, and semantics are maintained
     * for add-on compatibility unless a release explicitly documents a breaking change.
     * Free does not call this method internally. Companion add-ons (e.g. Media Offload for
     * Bunny Pro) use it for storage URL token-key admin warnings and Site Health when token
     * auth is enabled but no key is saved.
     *
     * Answers: "Does any attachment have a non-local offload summary?" Uses post meta key
     * SUMMARY_META_KEY (`_indigetal_offloaded`) with summary states partial, complete, or error
     * (excludes local). Bounded existence query: at most one attachment ID returned.
     *
     * Does not call Bunny APIs.
     *
     * @return bool True when at least one attachment has partial, complete, or error summary.
     */
    public static function hasAnyOffloadedAttachments() {
        $attachment_ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Extension API: bounded existence probe (posts_per_page 1).
            'meta_query' => [
                [
                    'key' => self::SUMMARY_META_KEY,
                    'value' => [
                        self::SUMMARY_STATE_PARTIAL,
                        self::SUMMARY_STATE_COMPLETE,
                        self::SUMMARY_STATE_ERROR,
                    ],
                    'compare' => 'IN',
                ],
            ],
        ]);

        return !empty($attachment_ids);
    }

    /**
     * Persist the normalized offload summary state for an attachment.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $state         Summary state.
     * @return bool
     */
    public static function setSummaryState($attachment_id, $state) {
        $attachment_id = absint($attachment_id);

        if (!$attachment_id || !self::isSupportedAttachment($attachment_id)) {
            return false;
        }

        return false !== update_post_meta($attachment_id, self::SUMMARY_META_KEY, self::normalizeSummaryState($state));
    }

    /**
     * Delete the summary and manifest meta for an attachment.
     *
     * @param int $attachment_id Attachment ID.
     * @return void
     */
    public static function deleteOffloadMeta($attachment_id) {
        $attachment_id = absint($attachment_id);

        if (!$attachment_id) {
            return;
        }

        delete_post_meta($attachment_id, self::SUMMARY_META_KEY);
        delete_post_meta($attachment_id, self::MANIFEST_META_KEY);
        delete_post_meta($attachment_id, self::LAST_ERROR_META_KEY);
    }

    /**
     * Store durable last-error context from a WP_Error (pre-flight or diagnostic).
     *
     * @param int       $attachment_id Attachment ID.
     * @param \WP_Error $error         Error object.
     * @return bool
     */
    public static function setLastOffloadErrorFromWpError($attachment_id, \WP_Error $error) {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1 || 'attachment' !== get_post_type($attachment_id)) {
            return false;
        }

        $payload = [
            'code'         => sanitize_key($error->get_error_code()),
            'message'      => sanitize_text_field(wp_strip_all_tags($error->get_error_message())),
            'recorded_at'  => gmdate('c'),
        ];

        return false !== update_post_meta($attachment_id, self::LAST_ERROR_META_KEY, $payload);
    }

    /**
     * Read stored last-error payload, if any.
     *
     * @param int $attachment_id Attachment ID.
     * @return array{code?: string, message?: string, recorded_at?: string}
     */
    public static function getLastOffloadError($attachment_id) {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1) {
            return [];
        }

        $raw = get_post_meta($attachment_id, self::LAST_ERROR_META_KEY, true);

        return is_array($raw) ? $raw : [];
    }

    /**
     * Remove durable last-error meta after recovery or meta purge.
     *
     * @param int $attachment_id Attachment ID.
     * @return void
     */
    public static function clearLastOffloadError($attachment_id) {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1) {
            return;
        }

        delete_post_meta($attachment_id, self::LAST_ERROR_META_KEY);
    }

    /**
     * Return the normalized manifest keyed by relative upload path.
     *
     * This read surface is intentionally filterable for downstream inspection.
     * Internal persistence code should use getRawManifest() instead so filtered
     * data cannot influence stored state.
     *
     * @param int $attachment_id Attachment ID.
     * @return array<string, array<string, string>>
     */
    public static function getManifest($attachment_id) {
        $manifest = self::getRawManifest($attachment_id);

        /**
         * Filter the normalized Bunny attachment manifest.
         *
         * @param array $manifest      Manifest keyed by relative upload path.
         * @param int   $attachment_id Attachment ID.
         */
        return apply_filters('indigetal_offload_attachment_manifest', $manifest, absint($attachment_id));
    }

    /**
     * Return the normalized stored manifest without applying external filters.
     *
     * Internal persistence and validation logic must use this accessor so
     * filtered read data cannot affect stored manifest state.
     *
     * @param int $attachment_id Attachment ID.
     * @return array<string, array<string, string>>
     */
    public static function getRawManifest($attachment_id) {
        $attachment_id = absint($attachment_id);

        if (!$attachment_id || !self::isSupportedAttachment($attachment_id)) {
            return [];
        }

        $upload_dir = wp_upload_dir();

        if (!empty($upload_dir['error']) || empty($upload_dir['baseurl'])) {
            return [];
        }

        $raw_manifest = get_post_meta($attachment_id, self::MANIFEST_META_KEY, true);
        
        return self::normalizeStoredManifest(is_array($raw_manifest) ? $raw_manifest : [], $upload_dir);
    }

    /**
     * Determine whether the stored manifest contains one or more error rows.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public static function hasErrorEntries($attachment_id) {
        return self::countErrorEntries($attachment_id) > 0;
    }

    /**
     * Count manifest rows currently marked as error.
     *
     * @param int $attachment_id Attachment ID.
     * @return int
     */
    public static function countErrorEntries($attachment_id) {
        $count = 0;

        foreach (self::getRawManifest($attachment_id) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (self::FILE_STATE_ERROR === self::normalizeFileState($entry['state'] ?? self::FILE_STATE_LOCAL)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Persist a normalized manifest for an attachment.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $manifest      Raw or normalized manifest data.
     * @return bool
     */
    public static function setManifest($attachment_id, array $manifest) {
        $attachment_id = absint($attachment_id);

        if (!$attachment_id || !self::isSupportedAttachment($attachment_id)) {
            return false;
        }

        $upload_dir = wp_upload_dir();

        if (!empty($upload_dir['error']) || empty($upload_dir['baseurl'])) {
            return false;
        }

        $normalized_manifest = self::normalizeStoredManifest($manifest, $upload_dir);

        return false !== update_post_meta($attachment_id, self::MANIFEST_META_KEY, $normalized_manifest);
    }

    /**
     * Return the normalized file set for an attachment from current metadata.
     *
     * @param int $attachment_id Attachment ID.
     * @return array<string, array<string, string>>
     */
    public static function buildAttachmentFileSet($attachment_id) {
        return self::buildAttachmentFileSetFromMetadata($attachment_id, wp_get_attachment_metadata($attachment_id));
    }

    /**
     * Return the current metadata-derived original relative path for an attachment.
     *
     * @param int $attachment_id Attachment ID.
     * @return string
     */
    public static function getOriginalRelativePath($attachment_id) {
        foreach (self::buildAttachmentFileSet($attachment_id) as $file_entry) {
            if (!is_array($file_entry) || 'original' !== (string) ($file_entry['role'] ?? '')) {
                continue;
            }

            return (string) ($file_entry['relative_path'] ?? '');
        }

        return '';
    }

    /**
     * Return the normalized file set for an attachment using explicit attachment metadata.
     *
     * This lets reconciliation code compare the manifest against the metadata being
     * written in the current request, not only the metadata already stored in post meta.
     *
     * @param int   $attachment_id       Attachment ID.
     * @param mixed $attachment_metadata Attachment metadata array.
     * @return array<string, array<string, string>>
     */
    public static function buildAttachmentFileSetFromMetadata($attachment_id, $attachment_metadata) {
        $attachment_id = absint($attachment_id);

        if (!$attachment_id || !self::isSupportedAttachment($attachment_id)) {
            return [];
        }

        $upload_dir = wp_upload_dir();

        if (!empty($upload_dir['error']) || empty($upload_dir['basedir']) || empty($upload_dir['baseurl'])) {
            return [];
        }

        $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $main_relative_path = self::normalizeRelativeUploadPath($attached_file, $upload_dir);

        if ('' === $main_relative_path) {
            return [];
        }

        $file_set = [];

        self::addFileSetEntry($file_set, $main_relative_path, $upload_dir, 'original');

        if (is_array($attachment_metadata)) {
            if (!empty($attachment_metadata['original_image']) && is_string($attachment_metadata['original_image'])) {
                self::addFileSetEntry(
                    $file_set,
                    self::buildSiblingRelativePath($main_relative_path, $attachment_metadata['original_image']),
                    $upload_dir,
                    'original_image'
                );
            }

            if (!empty($attachment_metadata['sizes']) && is_array($attachment_metadata['sizes'])) {
                foreach ($attachment_metadata['sizes'] as $size_name => $size_data) {
                    if (empty($size_data['file']) || !is_string($size_data['file'])) {
                        continue;
                    }

                    self::addFileSetEntry(
                        $file_set,
                        self::buildSiblingRelativePath($main_relative_path, $size_data['file']),
                        $upload_dir,
                        'size',
                        (string) $size_name
                    );
                }
            }
        }

        $backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);

        if (is_array($backup_sizes)) {
            foreach ($backup_sizes as $backup_name => $backup_data) {
                if (!is_array($backup_data) || empty($backup_data['file']) || !is_string($backup_data['file'])) {
                    continue;
                }

                self::addFileSetEntry(
                    $file_set,
                    self::buildSiblingRelativePath($main_relative_path, $backup_data['file']),
                    $upload_dir,
                    'backup',
                    (string) $backup_name
                );
            }
        }

        ksort($file_set);

        return $file_set;
    }

    /**
     * Determine whether an attachment should participate in the storage manifest.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public static function isSupportedAttachment($attachment_id) {
        $attachment_id = absint($attachment_id);

        if (!$attachment_id || 'attachment' !== get_post_type($attachment_id)) {
            return false;
        }

        $attachment_context = sanitize_key((string) get_post_meta($attachment_id, '_wp_attachment_context', true));

        // Core plugin/theme installers create temporary "upgrader" attachments in uploads.
        // Phase 2 must never offload or delete those local package files.
        if ('upgrader' === $attachment_context) {
            return false;
        }

        $mime_type = (string) get_post_mime_type($attachment_id);

        return 0 !== strpos($mime_type, 'video/');
    }

    /**
     * Normalize and add a single file-set entry.
     *
     * @param array  $file_set       Existing file set.
     * @param string $relative_path  Relative uploads path.
     * @param array  $upload_dir     Current uploads directory data.
     * @param string $role           File role.
     * @param string $variant_name   Optional size/backup key.
     * @return void
     */
    private static function addFileSetEntry(array &$file_set, $relative_path, array $upload_dir, $role, $variant_name = '') {
        $relative_path = self::normalizeRelativeUploadPath($relative_path, $upload_dir);

        if ('' === $relative_path || isset($file_set[$relative_path])) {
            return;
        }

        $file_set[$relative_path] = [
            'relative_path' => $relative_path,
            'local_path'    => self::buildLocalPath($relative_path, $upload_dir),
            'remote_path'   => self::buildRemotePath($relative_path, $upload_dir),
            'state'         => self::FILE_STATE_LOCAL,
            'last_error'    => '',
            'role'          => sanitize_key($role),
            'variant_name'  => sanitize_key($variant_name),
        ];
    }

    /**
     * Normalize stored manifest data before reads/writes.
     *
     * @param array $manifest   Raw manifest array.
     * @param array $upload_dir Current uploads directory data.
     * @return array<string, array<string, string>>
     */
    private static function normalizeStoredManifest(array $manifest, array $upload_dir) {
        $normalized_manifest = [];

        foreach ($manifest as $manifest_key => $manifest_entry) {
            if (!is_array($manifest_entry)) {
                continue;
            }

            $candidate_relative_path = '';

            if (isset($manifest_entry['relative_path']) && is_string($manifest_entry['relative_path'])) {
                $candidate_relative_path = $manifest_entry['relative_path'];
            } elseif (is_string($manifest_key)) {
                $candidate_relative_path = $manifest_key;
            }

            $relative_path = self::normalizeRelativeUploadPath($candidate_relative_path, $upload_dir);

            if ('' === $relative_path) {
                continue;
            }

            $normalized_manifest[$relative_path] = [
                'relative_path' => $relative_path,
                'state'         => self::normalizeFileState($manifest_entry['state'] ?? self::FILE_STATE_LOCAL),
                'remote_path'   => self::normalizeRemotePath($manifest_entry['remote_path'] ?? '', $relative_path, $upload_dir),
                'last_error'    => sanitize_text_field((string) ($manifest_entry['last_error'] ?? '')),
            ];
        }

        ksort($normalized_manifest);

        return $normalized_manifest;
    }

    /**
     * Normalize the summary state.
     *
     * @param mixed $state Raw state.
     * @return string
     */
    private static function normalizeSummaryState($state) {
        $state = sanitize_key((string) $state);

        $allowed_states = [
            self::SUMMARY_STATE_LOCAL,
            self::SUMMARY_STATE_PARTIAL,
            self::SUMMARY_STATE_COMPLETE,
            self::SUMMARY_STATE_ERROR,
        ];

        return in_array($state, $allowed_states, true) ? $state : self::SUMMARY_STATE_LOCAL;
    }

    /**
     * Normalize a file-level state.
     *
     * @param mixed $state Raw state.
     * @return string
     */
    private static function normalizeFileState($state) {
        $state = sanitize_key((string) $state);

        $allowed_states = [
            self::FILE_STATE_LOCAL,
            self::FILE_STATE_COMPLETE,
            self::FILE_STATE_ERROR,
        ];

        return in_array($state, $allowed_states, true) ? $state : self::FILE_STATE_LOCAL;
    }

    /**
     * Normalize a manifest remote path or derive it from the relative uploads path.
     *
     * @param mixed  $remote_path    Raw remote path.
     * @param string $relative_path  Relative uploads path.
     * @param array  $upload_dir     Current uploads directory data.
     * @return string
     */
    private static function normalizeRemotePath($remote_path, $relative_path, array $upload_dir) {
        if (is_string($remote_path) && '' !== $remote_path) {
            return ltrim(trim(wp_normalize_path($remote_path)), '/');
        }

        return self::buildRemotePath($relative_path, $upload_dir);
    }

    /**
     * Build a sibling relative path beside the main attachment file.
     *
     * @param string $base_relative_path Main attachment relative path.
     * @param string $file_name          Sibling file name.
     * @return string
     */
    private static function buildSiblingRelativePath($base_relative_path, $file_name) {
        $base_relative_path = ltrim(wp_normalize_path($base_relative_path), '/');
        $file_name = ltrim(wp_normalize_path((string) $file_name), '/');
        $directory = wp_normalize_path(dirname($base_relative_path));

        if ('' === $file_name) {
            return '';
        }

        if ('.' === $directory || '/' === $directory) {
            return $file_name;
        }

        return trailingslashit($directory) . $file_name;
    }

    /**
     * Build an absolute local path from the uploads basedir and relative path.
     *
     * @param string $relative_path Relative uploads path.
     * @param array  $upload_dir    Current uploads directory data.
     * @return string
     */
    private static function buildLocalPath($relative_path, array $upload_dir) {
        return wp_normalize_path(path_join((string) $upload_dir['basedir'], ltrim($relative_path, '/')));
    }

    /**
     * Build a remote path using the site's actual uploads URL path.
     *
     * @param string $relative_path Relative uploads path.
     * @param array  $upload_dir    Current uploads directory data.
     * @return string
     */
    private static function buildRemotePath($relative_path, array $upload_dir) {
        $base_url_path = wp_parse_url((string) $upload_dir['baseurl'], PHP_URL_PATH);
        $base_url_path = is_string($base_url_path) ? trim(wp_normalize_path($base_url_path), '/') : '';

        if ('' === $base_url_path) {
            return ltrim($relative_path, '/');
        }

        return trailingslashit($base_url_path) . ltrim($relative_path, '/');
    }

    /**
     * Normalize a relative uploads path from attachment meta or a filesystem path.
     *
     * @param mixed $relative_path Raw relative path.
     * @param array $upload_dir    Current uploads directory data.
     * @return string
     */
    private static function normalizeRelativeUploadPath($relative_path, array $upload_dir) {
        if (!is_string($relative_path) || '' === $relative_path) {
            return '';
        }

        $relative_path = wp_normalize_path($relative_path);
        $basedir = wp_normalize_path((string) ($upload_dir['basedir'] ?? ''));

        if ('' !== $basedir && self::pathStartsWith($relative_path, $basedir)) {
            $relative_path = ltrim(substr($relative_path, strlen($basedir)), '/');
        }

        return ltrim($relative_path, '/');
    }

    /**
     * Determine whether a normalized path starts with the normalized prefix.
     *
     * @param string $path   Normalized path.
     * @param string $prefix Normalized prefix.
     * @return bool
     */
    private static function pathStartsWith($path, $prefix) {
        if ('' === $prefix) {
            return false;
        }

        return 0 === strpos($path, trailingslashit($prefix)) || $path === $prefix;
    }
}
