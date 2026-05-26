<?php
/**
 * Bunny-backed video metadata sync state and authority helpers.
 *
 * @package Bunny_Offload\Integration
 */

namespace Bunny_Offload\Integration;

use Bunny_Offload\Bunny\BunnyVideoHandler;
use Bunny_Offload\Utils\BunnyLogger;

if (!defined('ABSPATH')) {
    exit;
}

class BunnyVideoMetadataSync {
    const DESCRIPTION_META_TAG_PROPERTY = 'description';
    const REMOTE_REFRESH_THROTTLE_SECONDS = 30;
    const LAST_REMOTE_REFRESH_ATTEMPT_AT_META_KEY = '_indigetal_offload_video_last_remote_refresh_attempt_at';
    const LAST_SUCCESSFUL_REMOTE_REFRESH_AT_META_KEY = '_indigetal_offload_video_last_successful_remote_refresh_at';
    const TITLE_DIRTY_META_KEY = '_indigetal_offload_video_title_dirty';
    const DESCRIPTION_DIRTY_META_KEY = '_indigetal_offload_video_description_dirty';
    const LAST_SYNCED_TITLE_META_KEY = '_indigetal_offload_video_last_synced_title';
    const LAST_SYNCED_DESCRIPTION_META_KEY = '_indigetal_offload_video_last_synced_description';
    const TITLE_SYNC_ERROR_META_KEY = '_indigetal_offload_video_title_sync_error';
    const DESCRIPTION_SYNC_ERROR_META_KEY = '_indigetal_offload_video_description_sync_error';

    const TITLE_FIELD = 'title';
    const DESCRIPTION_FIELD = 'description';

    private const FIELD_DEFINITIONS = [
        self::TITLE_FIELD => [
            'post_field'          => 'post_title',
            'remote_field'        => 'title',
            'remote_storage'      => 'title',
            'dirty_meta_key'      => self::TITLE_DIRTY_META_KEY,
            'last_synced_meta_key'=> self::LAST_SYNCED_TITLE_META_KEY,
            'sync_error_meta_key' => self::TITLE_SYNC_ERROR_META_KEY,
        ],
        self::DESCRIPTION_FIELD => [
            'post_field'          => 'post_content',
            'remote_field'        => 'description',
            'remote_storage'      => 'metaTags.description',
            'dirty_meta_key'      => self::DESCRIPTION_DIRTY_META_KEY,
            'last_synced_meta_key'=> self::LAST_SYNCED_DESCRIPTION_META_KEY,
            'sync_error_meta_key' => self::DESCRIPTION_SYNC_ERROR_META_KEY,
        ],
    ];

    private const WORDPRESS_ONLY_FIELDS = [
        'post_excerpt',
    ];

    /**
     * Track attachments already processed in the current request.
     *
     * @var array<int, bool>
     */
    private static $processed_attachment_updates = [];

    /**
     * Track attachments whose remote metadata refresh was already handled this request.
     *
     * @var array<int, bool>
     */
    private static $processed_remote_refreshes = [];

    /**
     * Track attachment updates originated from Bunny -> WordPress refreshes.
     *
     * @var array<int, bool>
     */
    private static $suppressed_attachment_updates = [];

    /**
     * Shared Bunny video helper for existing-video read/write operations.
     *
     * @var BunnyVideoHandler
     */
    private $video_handler;

    /**
     * Register WordPress-side metadata sync hooks.
     */
    public function __construct() {
        $this->video_handler = BunnyVideoHandler::getInstance();

        add_action('attachment_updated', [$this, 'handleAttachmentUpdated'], 10, 3);
    }

    /**
     * Push supported WordPress attachment metadata edits to Bunny after save.
     *
     * @param int      $post_id    Updated post ID.
     * @param \WP_Post $post_after Attachment state after the save.
     * @param \WP_Post $post_before Attachment state before the save.
     * @return void
     */
    public function handleAttachmentUpdated($post_id, $post_after, $post_before) {
        $post_id = absint($post_id);

        if (!empty(self::$suppressed_attachment_updates[$post_id])) {
            return;
        }

        if (!$this->shouldSyncUpdatedAttachment($post_id, $post_after, $post_before)) {
            return;
        }

        $changed_fields = $this->getChangedTrackedFields($post_after, $post_before);
        if ([] === $changed_fields) {
            return;
        }

        if (!empty(self::$processed_attachment_updates[$post_id])) {
            return;
        }

        self::$processed_attachment_updates[$post_id] = true;

        $video_id = (string) get_post_meta($post_id, BunnyMetadataManager::VIDEO_ID_META_KEY, true);
        if ('' === $video_id) {
            return;
        }

        $requested_updates = [];

        foreach ($changed_fields as $field_key => $new_value) {
            self::markFieldDirty($post_id, $field_key);

            if (self::TITLE_FIELD === $field_key && '' === trim($new_value)) {
                self::setFieldSyncError(
                    $post_id,
                    $field_key,
                    __('Bunny title sync requires a non-empty WordPress title.', 'indigetal-media-offload-for-bunny-net')
                );

                continue;
            }

            $requested_updates[$field_key] = $new_value;
        }

        if ([] === $requested_updates) {
            return;
        }

        $response = $this->video_handler->updateExistingVideoMetadata($video_id, $requested_updates);

        if (is_wp_error($response)) {
            foreach (array_keys($requested_updates) as $field_key) {
                self::setFieldSyncError($post_id, $field_key, $response->get_error_message());
            }

            BunnyLogger::log(
                sprintf(
                    'Bunny video metadata sync failed for attachment %1$d: %2$s',
                    $post_id,
                    $response->get_error_message()
                ),
                'error'
            );

            return;
        }

        foreach (array_keys($requested_updates) as $field_key) {
            $synced_value = array_key_exists($field_key, $response)
                ? (string) $response[$field_key]
                : (string) $requested_updates[$field_key];

            self::recordOutboundSyncSuccess($post_id, $field_key, $synced_value);
        }
    }

    /**
     * Refresh WordPress title/description from Bunny on bounded admin/editor reads.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool|\WP_Error
     */
    public static function refreshAttachmentFromRemote($attachment_id) {
        $attachment_id = absint($attachment_id);

        if (!self::beginRemoteRefreshAttempt($attachment_id)) {
            return false;
        }

        $video_id = self::getAttachmentVideoId($attachment_id);
        if ('' === $video_id) {
            return false;
        }

        $response = BunnyVideoHandler::getInstance()->getExistingVideoMetadata($video_id);
        if (is_wp_error($response)) {
            BunnyLogger::log(
                sprintf(
                    'Bunny video metadata refresh failed for attachment %1$d: %2$s',
                    $attachment_id,
                    $response->get_error_message()
                ),
                'warning'
            );

            return $response;
        }

        return self::applyRemoteRefreshResponse($attachment_id, $response);
    }

    /**
     * Refresh WordPress title/description from an already-fetched Bunny response.
     *
     * @param int   $attachment_id  Attachment ID.
     * @param mixed $remote_response Raw Bunny video-details response.
     * @return bool|\WP_Error
     */
    public static function refreshAttachmentFromRemoteResponse($attachment_id, $remote_response) {
        if (!self::isBunnyVideoAttachment($attachment_id)) {
            return false;
        }

        return self::applyRemoteRefreshResponse($attachment_id, $remote_response);
    }

    /**
     * Prime a bounded remote refresh attempt before a caller performs Bunny GET work.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public static function primeRemoteRefreshAttempt($attachment_id) {
        return self::beginRemoteRefreshAttempt($attachment_id, true);
    }

    /**
     * Return the supported field mapping for Bunny-backed video metadata sync.
     *
     * @return array<string, array<string, string>>
     */
    public static function getFieldDefinitions() {
        return self::FIELD_DEFINITIONS;
    }

    /**
     * Return the WordPress-only attachment fields that never sync to Bunny.
     *
     * @return string[]
     */
    public static function getWordPressOnlyFields() {
        return self::WORDPRESS_ONLY_FIELDS;
    }

    /**
     * Return the field definition for a supported metadata field.
     *
     * @param string $field_key Supported sync field key.
     * @return array<string, string>|null
     */
    public static function getFieldDefinition($field_key) {
        if (!isset(self::FIELD_DEFINITIONS[$field_key])) {
            return null;
        }

        return self::FIELD_DEFINITIONS[$field_key];
    }

    /**
     * Check whether the given WordPress attachment post field participates in sync.
     *
     * @param string $post_field WordPress post field name.
     * @return bool
     */
    public static function isTrackedPostField($post_field) {
        foreach (self::FIELD_DEFINITIONS as $definition) {
            if ($definition['post_field'] === $post_field) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the given WordPress attachment post field is intentionally local-only.
     *
     * @param string $post_field WordPress post field name.
     * @return bool
     */
    public static function isWordPressOnlyField($post_field) {
        return in_array($post_field, self::WORDPRESS_ONLY_FIELDS, true);
    }

    /**
     * Return the current metadata sync state for an attachment.
     *
     * @param int $attachment_id Attachment ID.
     * @return array<string, int|string|bool>
     */
    public static function getAttachmentState($attachment_id) {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1) {
            return [];
        }

        return [
            'last_remote_refresh_attempt_at'   => self::getTimestampMeta($attachment_id, self::LAST_REMOTE_REFRESH_ATTEMPT_AT_META_KEY),
            'last_successful_remote_refresh_at'=> self::getTimestampMeta($attachment_id, self::LAST_SUCCESSFUL_REMOTE_REFRESH_AT_META_KEY),
            'title_dirty'                      => self::isFieldDirty($attachment_id, self::TITLE_FIELD),
            'description_dirty'                => self::isFieldDirty($attachment_id, self::DESCRIPTION_FIELD),
            'last_synced_title'                => self::getStringMeta($attachment_id, self::LAST_SYNCED_TITLE_META_KEY),
            'last_synced_description'          => self::getStringMeta($attachment_id, self::LAST_SYNCED_DESCRIPTION_META_KEY),
            'title_sync_error'                 => self::getStringMeta($attachment_id, self::TITLE_SYNC_ERROR_META_KEY),
            'description_sync_error'           => self::getStringMeta($attachment_id, self::DESCRIPTION_SYNC_ERROR_META_KEY),
        ];
    }

    /**
     * Mark a tracked field dirty so local WordPress edits remain authoritative.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $field_key     Supported sync field key.
     * @return bool True when the field is tracked and the state update was attempted.
     */
    public static function markFieldDirty($attachment_id, $field_key) {
        $definition = self::getFieldDefinition($field_key);
        if (null === $definition) {
            return false;
        }

        return self::updateMetaValue(absint($attachment_id), $definition['dirty_meta_key'], true);
    }

    /**
     * Check whether a tracked field currently has a pending local change.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $field_key     Supported sync field key.
     * @return bool
     */
    public static function isFieldDirty($attachment_id, $field_key) {
        $definition = self::getFieldDefinition($field_key);
        if (null === $definition) {
            return false;
        }

        $value = get_post_meta(absint($attachment_id), $definition['dirty_meta_key'], true);

        return rest_sanitize_boolean($value);
    }

    /**
     * Record a field-level sync error without affecting unrelated fields.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $field_key     Supported sync field key.
     * @param string $message       Human-readable sync error message.
     * @return bool True when the field is tracked and the state update was attempted.
     */
    public static function setFieldSyncError($attachment_id, $field_key, $message) {
        $definition = self::getFieldDefinition($field_key);
        if (null === $definition) {
            return false;
        }

        return self::updateMetaValue(
            absint($attachment_id),
            $definition['sync_error_meta_key'],
            sanitize_text_field((string) $message)
        );
    }

    /**
     * Clear the recorded sync error for one tracked field.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $field_key     Supported sync field key.
     * @return bool True when the field is tracked and the error state was cleared.
     */
    public static function clearFieldSyncError($attachment_id, $field_key) {
        $definition = self::getFieldDefinition($field_key);
        if (null === $definition) {
            return false;
        }

        return self::deleteMetaValue(absint($attachment_id), $definition['sync_error_meta_key']);
    }

    /**
     * Record a successful outbound sync for one tracked field.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $field_key     Supported sync field key.
     * @param string $synced_value  Newly synchronized field value.
     * @return bool True when all field updates succeeded.
     */
    public static function recordOutboundSyncSuccess($attachment_id, $field_key, $synced_value) {
        $definition = self::getFieldDefinition($field_key);
        if (null === $definition) {
            return false;
        }

        $attachment_id = absint($attachment_id);
        $synced_value  = self::normalizeBaselineValue($synced_value);

        $baseline_updated = self::updateMetaValue($attachment_id, $definition['last_synced_meta_key'], $synced_value);
        $dirty_cleared    = self::deleteMetaValue($attachment_id, $definition['dirty_meta_key']);
        $error_cleared    = self::deleteMetaValue($attachment_id, $definition['sync_error_meta_key']);

        return $baseline_updated && $dirty_cleared && $error_cleared;
    }

    /**
     * Reset a field baseline when Bunny and WordPress already agree.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $field_key     Supported sync field key.
     * @param string $current_value Matching WordPress/Bunny value.
     * @return bool True when the baseline and stale markers were reset.
     */
    public static function resetFieldBaseline($attachment_id, $field_key, $current_value) {
        return self::recordOutboundSyncSuccess($attachment_id, $field_key, $current_value);
    }

    /**
     * Check whether Bunny refresh is allowed to overwrite a local field.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $field_key     Supported sync field key.
     * @return bool
     */
    public static function canOverwriteFieldFromRemote($attachment_id, $field_key) {
        return !self::isFieldDirty($attachment_id, $field_key);
    }

    /**
     * Record when a remote refresh attempt starts for the attachment.
     *
     * @param int      $attachment_id Attachment ID.
     * @param int|null $timestamp     Optional Unix timestamp override.
     * @return bool
     */
    public static function markRemoteRefreshAttempt($attachment_id, $timestamp = null) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id < 1) {
            return false;
        }

        return self::updateMetaValue(
            $attachment_id,
            self::LAST_REMOTE_REFRESH_ATTEMPT_AT_META_KEY,
            self::normalizeTimestamp($timestamp)
        );
    }

    /**
     * Record a successful remote refresh for the attachment.
     *
     * @param int      $attachment_id Attachment ID.
     * @param int|null $timestamp     Optional Unix timestamp override.
     * @return bool
     */
    public static function markSuccessfulRemoteRefresh($attachment_id, $timestamp = null) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id < 1) {
            return false;
        }

        return self::updateMetaValue(
            $attachment_id,
            self::LAST_SUCCESSFUL_REMOTE_REFRESH_AT_META_KEY,
            self::normalizeTimestamp($timestamp)
        );
    }

    /**
     * Return the current WordPress value for a tracked field.
     *
     * @param \WP_Post $attachment Attachment post object.
     * @param string   $field_key  Supported sync field key.
     * @return string|null
     */
    public static function getCurrentWordPressFieldValue($attachment, $field_key) {
        $definition = self::getFieldDefinition($field_key);
        if (null === $definition || !($attachment instanceof \WP_Post)) {
            return null;
        }

        $post_field = $definition['post_field'];

        if (!isset($attachment->$post_field)) {
            return null;
        }

        return self::normalizeBaselineValue($attachment->$post_field);
    }

    /**
     * Return the stored last-synced baseline for a tracked field.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $field_key     Supported sync field key.
     * @return string|null
     */
    public static function getLastSyncedValue($attachment_id, $field_key) {
        $definition = self::getFieldDefinition($field_key);
        if (null === $definition) {
            return null;
        }

        return self::getStringMeta(absint($attachment_id), $definition['last_synced_meta_key']);
    }

    /**
     * Normalize Bunny video-details responses into a stable internal metadata shape.
     *
     * @param mixed $response Raw Bunny GET/POST response body.
     * @return array<string, mixed>|\WP_Error
     */
    public static function normalizeRemoteVideoMetadata($response) {
        if (!is_array($response)) {
            return new \WP_Error(
                'invalid_remote_video_metadata',
                __('Unexpected Bunny video metadata response.', 'indigetal-media-offload-for-bunny-net')
            );
        }

        if (isset($response['data']) && is_array($response['data'])) {
            $response = $response['data'];
        }

        $meta_tags = self::normalizeRemoteMetaTags($response['metaTags'] ?? []);
        $description = '';
        $description_from_meta_tags = self::getDescriptionFromMetaTags($meta_tags);

        if (null !== $description_from_meta_tags) {
            $description = $description_from_meta_tags;
        } elseif (array_key_exists('metaTags', $response) && is_array($response['metaTags'])) {
            // When Bunny returns metaTags without a description entry, treat description as cleared
            // even if the top-level response field is stale.
            $description = '';
        } elseif (array_key_exists('description', $response) && null !== $response['description']) {
            $description = (string) $response['description'];
        }

        return [
            self::TITLE_FIELD       => isset($response['title']) ? (string) $response['title'] : '',
            self::DESCRIPTION_FIELD => $description,
            'metaTags'              => $meta_tags,
        ];
    }

    /**
     * Build the Bunny existing-video metadata update payload for title/description sync.
     *
     * @param array<string, mixed> $current_remote_metadata Current normalized Bunny metadata.
     * @param array<string, mixed> $requested_updates       Requested title/description updates.
     * @return array<string, mixed>|\WP_Error
     */
    public static function buildRemoteVideoMetadataUpdatePayload(array $current_remote_metadata, array $requested_updates) {
        $payload = [];

        if (array_key_exists(self::TITLE_FIELD, $requested_updates)) {
            $title = (string) $requested_updates[self::TITLE_FIELD];

            if ('' === trim($title)) {
                return new \WP_Error(
                    'invalid_video_title',
                    __('A non-empty Bunny video title is required for metadata updates.', 'indigetal-media-offload-for-bunny-net')
                );
            }

            $payload['title'] = $title;
        }

        if (array_key_exists(self::DESCRIPTION_FIELD, $requested_updates)) {
            $payload['metaTags'] = self::replaceDescriptionMetaTag(
                self::normalizeRemoteMetaTags($current_remote_metadata['metaTags'] ?? []),
                (string) $requested_updates[self::DESCRIPTION_FIELD]
            );
        }

        if ([] === $payload) {
            return new \WP_Error(
                'no_video_metadata_updates',
                __('No Bunny video metadata updates were requested.', 'indigetal-media-offload-for-bunny-net')
            );
        }

        return $payload;
    }

    /**
     * Extract the Bunny description value from a normalized metaTags array.
     *
     * @param array<int, array<string, mixed>> $meta_tags Normalized Bunny metaTags.
     * @return string|null
     */
    public static function getDescriptionFromMetaTags(array $meta_tags) {
        foreach ($meta_tags as $meta_tag) {
            if (!self::isDescriptionMetaTag($meta_tag)) {
                continue;
            }

            return isset($meta_tag['value']) ? (string) $meta_tag['value'] : '';
        }

        return null;
    }

    /**
     * Check whether the updated post represents a Bunny-backed video attachment.
     *
     * @param int      $post_id     Updated post ID.
     * @param \WP_Post $post_after  Attachment state after the save.
     * @param \WP_Post $post_before Attachment state before the save.
     * @return bool
     */
    private function shouldSyncUpdatedAttachment($post_id, $post_after, $post_before) {
        if ($post_id < 1) {
            return false;
        }

        if (!($post_after instanceof \WP_Post) || !($post_before instanceof \WP_Post)) {
            return false;
        }

        if (!self::isVideoAttachmentPost($post_after) || !self::isVideoAttachmentPost($post_before)) {
            return false;
        }

        if ('' === (string) get_post_meta($post_id, BunnyMetadataManager::VIDEO_ID_META_KEY, true)) {
            return false;
        }

        return true;
    }

    /**
     * Diff the tracked WordPress fields between the saved attachment states.
     *
     * @param \WP_Post $post_after  Attachment state after the save.
     * @param \WP_Post $post_before Attachment state before the save.
     * @return array<string, string>
     */
    private function getChangedTrackedFields($post_after, $post_before) {
        $changed_fields = [];

        foreach (self::getFieldDefinitions() as $field_key => $definition) {
            $post_field = $definition['post_field'];
            $before_value = isset($post_before->$post_field) ? (string) $post_before->$post_field : '';
            $after_value  = isset($post_after->$post_field) ? (string) $post_after->$post_field : '';

            if ($before_value === $after_value) {
                continue;
            }

            $changed_fields[$field_key] = $after_value;
        }

        return $changed_fields;
    }

    /**
     * Normalize sync-baseline values without re-sanitizing saved attachment content.
     *
     * @param mixed $value Raw stored or post-field value.
     * @return string
     */
    private static function normalizeBaselineValue($value) {
        if (null === $value) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Normalize Bunny metaTags while preserving unrelated tag data.
     *
     * @param mixed $meta_tags Raw Bunny metaTags payload.
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeRemoteMetaTags($meta_tags) {
        if (!is_array($meta_tags)) {
            return [];
        }

        $normalized_meta_tags = [];

        foreach ($meta_tags as $meta_tag) {
            if (!is_array($meta_tag) || !array_key_exists('property', $meta_tag)) {
                continue;
            }

            $normalized_tag = $meta_tag;
            $normalized_tag['property'] = (string) $meta_tag['property'];
            $normalized_tag['value']    = array_key_exists('value', $meta_tag) ? (string) $meta_tag['value'] : '';

            $normalized_meta_tags[] = $normalized_tag;
        }

        return $normalized_meta_tags;
    }

    /**
     * Replace or remove only the Bunny description metaTag while preserving others.
     *
     * @param array<int, array<string, mixed>> $meta_tags    Current normalized Bunny metaTags.
     * @param string                           $description New description value.
     * @return array<int, array<string, mixed>>
     */
    private static function replaceDescriptionMetaTag(array $meta_tags, $description) {
        $description          = (string) $description;
        $updated_meta_tags    = [];
        $description_written  = false;

        foreach ($meta_tags as $meta_tag) {
            if (!self::isDescriptionMetaTag($meta_tag)) {
                $updated_meta_tags[] = $meta_tag;
                continue;
            }

            if ($description_written || '' === $description) {
                continue;
            }

            $meta_tag['property'] = self::DESCRIPTION_META_TAG_PROPERTY;
            $meta_tag['value']    = $description;

            $updated_meta_tags[] = $meta_tag;
            $description_written = true;
        }

        if (!$description_written && '' !== $description) {
            $updated_meta_tags[] = [
                'property' => self::DESCRIPTION_META_TAG_PROPERTY,
                'value'    => $description,
            ];
        }

        return array_values($updated_meta_tags);
    }

    /**
     * Check whether a metaTag entry stores Bunny's description value.
     *
     * @param array<string, mixed> $meta_tag Normalized Bunny metaTag entry.
     * @return bool
     */
    private static function isDescriptionMetaTag(array $meta_tag) {
        if (!array_key_exists('property', $meta_tag)) {
            return false;
        }

        return self::DESCRIPTION_META_TAG_PROPERTY === strtolower(trim((string) $meta_tag['property']));
    }

    /**
     * Start a bounded Bunny -> WordPress refresh attempt for this request.
     *
     * @param int  $attachment_id         Attachment ID.
     * @param bool $should_mark_attempt   Whether to write the refresh-attempt timestamp now.
     * @return bool
     */
    private static function beginRemoteRefreshAttempt($attachment_id, $should_mark_attempt = true) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id < 1) {
            return false;
        }

        if (!self::isBunnyVideoAttachment($attachment_id) || !self::isRemoteRefreshDue($attachment_id)) {
            self::$processed_remote_refreshes[$attachment_id] = true;
            return false;
        }

        self::$processed_remote_refreshes[$attachment_id] = true;

        if (!$should_mark_attempt) {
            return true;
        }

        if (self::markRemoteRefreshAttempt($attachment_id)) {
            return true;
        }

        BunnyLogger::log(
            sprintf(
                'Skipped Bunny video metadata refresh for attachment %d because the refresh-attempt marker could not be stored.',
                $attachment_id
            ),
            'warning'
        );

        return false;
    }

    /**
     * Apply normalized Bunny title/description state to a WordPress attachment.
     *
     * @param int   $attachment_id   Attachment ID.
     * @param mixed $remote_response Raw Bunny video-details response.
     * @return bool|\WP_Error
     */
    private static function applyRemoteRefreshResponse($attachment_id, $remote_response) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id < 1) {
            return false;
        }

        if (!self::isBunnyVideoAttachment($attachment_id)) {
            return false;
        }

        $remote_metadata = self::normalizeRemoteVideoMetadata($remote_response);
        if (is_wp_error($remote_metadata)) {
            BunnyLogger::log(
                sprintf(
                    'Bunny video metadata refresh returned an invalid payload for attachment %1$d: %2$s',
                    $attachment_id,
                    $remote_metadata->get_error_message()
                ),
                'warning'
            );

            return $remote_metadata;
        }

        self::markSuccessfulRemoteRefresh($attachment_id);

        $attachment = get_post($attachment_id);
        if (!($attachment instanceof \WP_Post) || 'attachment' !== $attachment->post_type) {
            return false;
        }

        $post_updates = [
            'ID' => $attachment_id,
        ];
        $reset_fields = [];

        foreach (self::getFieldDefinitions() as $field_key => $definition) {
            $remote_value = self::normalizeBaselineValue($remote_metadata[$field_key] ?? '');
            $current_value = self::getCurrentWordPressFieldValue($attachment, $field_key);

            if (null === $current_value) {
                continue;
            }

            if (!self::canOverwriteFieldFromRemote($attachment_id, $field_key)) {
                continue;
            }

            if ($current_value === $remote_value) {
                self::resetFieldBaseline($attachment_id, $field_key, $remote_value);
                continue;
            }

            $post_updates[$definition['post_field']] = $remote_value;
            $reset_fields[$field_key] = $remote_value;
        }

        if (count($post_updates) > 1) {
            $update_result = self::updateAttachmentFromRemote($attachment_id, $post_updates);
            if (is_wp_error($update_result)) {
                BunnyLogger::log(
                    sprintf(
                        'Failed to apply Bunny video metadata refresh to attachment %1$d: %2$s',
                        $attachment_id,
                        $update_result->get_error_message()
                    ),
                    'warning'
                );

                return $update_result;
            }
        }

        foreach ($reset_fields as $field_key => $remote_value) {
            self::resetFieldBaseline($attachment_id, $field_key, $remote_value);
        }

        return true;
    }

    /**
     * Determine whether the attachment can issue a new remote refresh now.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private static function isRemoteRefreshDue($attachment_id) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id < 1) {
            return false;
        }

        if (!empty(self::$processed_remote_refreshes[$attachment_id])) {
            return false;
        }

        $last_attempt = self::getTimestampMeta($attachment_id, self::LAST_REMOTE_REFRESH_ATTEMPT_AT_META_KEY);
        if ($last_attempt < 1) {
            return true;
        }

        return (time() - $last_attempt) >= self::REMOTE_REFRESH_THROTTLE_SECONDS;
    }

    /**
     * Update WordPress attachment fields from Bunny without re-triggering outbound sync.
     *
     * @param int                   $attachment_id Attachment ID.
     * @param array<string, mixed>  $post_updates  Post fields to update.
     * @return bool|\WP_Error
     */
    private static function updateAttachmentFromRemote($attachment_id, array $post_updates) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id < 1) {
            return false;
        }

        self::$suppressed_attachment_updates[$attachment_id] = true;

        try {
            $updated = wp_update_post(wp_slash($post_updates), true);
        } finally {
            unset(self::$suppressed_attachment_updates[$attachment_id]);
        }

        if (is_wp_error($updated)) {
            return $updated;
        }

        return true;
    }

    /**
     * Check whether this attachment is a Bunny-backed video eligible for refresh.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private static function isBunnyVideoAttachment($attachment_id) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id < 1) {
            return false;
        }

        $attachment = get_post($attachment_id);
        if (!self::isVideoAttachmentPost($attachment)) {
            return false;
        }

        return '' !== self::getAttachmentVideoId($attachment_id);
    }

    /**
     * Check whether a post object is a video attachment.
     *
     * @param mixed $attachment Candidate post object.
     * @return bool
     */
    private static function isVideoAttachmentPost($attachment) {
        if (!($attachment instanceof \WP_Post) || 'attachment' !== $attachment->post_type) {
            return false;
        }

        return 0 === strpos((string) $attachment->post_mime_type, 'video/');
    }

    /**
     * Read the stored Bunny video GUID for an attachment.
     *
     * @param int $attachment_id Attachment ID.
     * @return string
     */
    private static function getAttachmentVideoId($attachment_id) {
        return preg_replace('/[^a-f0-9\-]/i', '', (string) get_post_meta(absint($attachment_id), BunnyMetadataManager::VIDEO_ID_META_KEY, true));
    }

    /**
     * Normalize timestamps before writing restart-safe state to attachment meta.
     *
     * @param int|null $timestamp Optional Unix timestamp override.
     * @return int
     */
    private static function normalizeTimestamp($timestamp) {
        if (null === $timestamp) {
            return time();
        }

        return max(0, (int) $timestamp);
    }

    /**
     * Read a timestamp attachment meta value with a stable integer fallback.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $meta_key      Attachment meta key.
     * @return int
     */
    private static function getTimestampMeta($attachment_id, $meta_key) {
        return max(0, (int) get_post_meta($attachment_id, $meta_key, true));
    }

    /**
     * Read a string attachment meta value with a stable empty-string fallback.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $meta_key      Attachment meta key.
     * @return string
     */
    private static function getStringMeta($attachment_id, $meta_key) {
        return self::normalizeBaselineValue(get_post_meta($attachment_id, $meta_key, true));
    }

    /**
     * Update attachment meta while treating idempotent writes as success.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $meta_key      Attachment meta key.
     * @param mixed  $value         Meta value to store.
     * @return bool
     */
    private static function updateMetaValue($attachment_id, $meta_key, $value) {
        $updated = update_post_meta($attachment_id, $meta_key, $value);
        if (false !== $updated) {
            return true;
        }

        return self::normalizeBaselineValue(get_post_meta($attachment_id, $meta_key, true)) === self::normalizeBaselineValue($value);
    }

    /**
     * Delete attachment meta while treating missing keys as already clear.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $meta_key      Attachment meta key.
     * @return bool
     */
    private static function deleteMetaValue($attachment_id, $meta_key) {
        $deleted = delete_post_meta($attachment_id, $meta_key);
        if (false !== $deleted) {
            return true;
        }

        return '' === get_post_meta($attachment_id, $meta_key, true);
    }
}
