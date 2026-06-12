<?php
namespace Bunny_Offload\Integration;

use Bunny_Offload\Bunny\BunnyApiClient;
use Bunny_Offload\Bunny\BunnyCollectionHandler;
use Bunny_Offload\Bunny\BunnyVideoHandler;
use Bunny_Offload\REST\BunnyStreamStatusController;
use Bunny_Offload\Settings\BunnyConfigurationStore;
use Bunny_Offload\Utils\BunnyLogger;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyMediaLibrary {
    private const THUMBNAIL_SYNC_HOOK = 'indigetal_offload_sync_video_thumbnail';

    private const FRESH_VIDEO_OFFLOAD_WINDOW_SECONDS = 600;

    private const THUMBNAIL_SYNC_RETRY_DELAYS = [
        1 => 60,
        2 => 300,
        3 => 900,
        4 => 1800,
        5 => 3600,
        6 => 10800,
    ];

    /**
     * Shared Media Library integration instance.
     *
     * @var BunnyMediaLibrary|null
     */
    private static $instance = null;

    /**
     * Whether WordPress hooks have already been registered.
     *
     * @var bool
     */
    private static $hooks_registered = false;

    private $apiClient;

    public function __construct() {
        if (null === self::$instance) {
            self::$instance = $this;
        }

        $this->apiClient = BunnyApiClient::getInstance();

        if (self::$hooks_registered) {
            return;
        }

        self::$hooks_registered = true;

        add_filter('wp_get_attachment_url', [$this, 'filterBunnyVideoURL'], 10, 2);
        add_filter('wp_prepare_attachment_for_js', [$this, 'filterAttachmentForJs'], 10, 3);
        add_filter('wp_get_attachment_image_src', [$this, 'filterAttachmentImageSrc'], 10, 4);
        add_filter('wp_get_attachment_image_attributes', [$this, 'filterAttachmentImageAttributes'], 10, 3);
        add_filter('rest_prepare_attachment', [$this, 'filterRestAttachment'], 10, 3);
        add_filter('ajax_query_attachments_args', [$this, 'filterAjaxAttachmentQueries']);
        add_action('add_attachment', [$this, 'handleAttachmentMetadata'], 10, 1);
        add_action('delete_attachment', [$this, 'handleAttachmentDeletion']);
        add_action(self::THUMBNAIL_SYNC_HOOK, [$this, 'syncVideoThumbnailMetadata'], 10, 2);
    }

    /**
     * Return the shared Media Library integration instance.
     *
     * @return BunnyMediaLibrary
     */
    public static function getInstance() {
        if (null === self::$instance) {
            new self();
        }

        return self::$instance;
    }

    /**
     * Schedule the first background thumbnail sync attempt for an attachment.
     *
     * Public entry point for callers that need thumbnail hydration without going
     * through `add_attachment` (for example after manual metadata repair).
     *
     * @param int $attachmentId The attachment ID.
     * @return bool True when the event is scheduled or already pending, false otherwise.
     */
    public static function scheduleInitialThumbnailSyncForAttachment($attachmentId) {
        return self::scheduleThumbnailSyncRetryEvent($attachmentId, 1);
    }

    /**
     * Remove all pending background thumbnail sync events for an attachment.
     *
     * @param int $attachmentId The attachment ID.
     * @return void
     */
    public static function clearScheduledThumbnailSyncForAttachment($attachmentId) {
        self::clearScheduledThumbnailSyncEvents($attachmentId);
    }

    /**
     * Schedules the first background thumbnail sync attempt for an attachment.
     *
     * @param int $attachmentId The attachment ID.
     * @return bool True when the event is scheduled or already pending, false otherwise.
     */
    private function scheduleInitialThumbnailSync($attachmentId) {
        return self::scheduleInitialThumbnailSyncForAttachment($attachmentId);
    }

    /**
     * Schedules a bounded background thumbnail sync retry for an attachment.
     *
     * @param int $attachmentId The attachment ID.
     * @param int $attempt      The attempt number.
     * @return bool True when the event is scheduled or already pending, false otherwise.
     */
    private function scheduleThumbnailSyncRetry($attachmentId, $attempt) {
        return self::scheduleThumbnailSyncRetryEvent($attachmentId, $attempt);
    }

    /**
     * Schedules a bounded background thumbnail sync retry for an attachment.
     *
     * @param int $attachmentId The attachment ID.
     * @param int $attempt      The attempt number.
     * @return bool True when the event is scheduled or already pending, false otherwise.
     */
    private static function scheduleThumbnailSyncRetryEvent($attachmentId, $attempt) {
        $attachmentId = absint($attachmentId);
        $attempt      = max(1, min((int) $attempt, count(self::THUMBNAIL_SYNC_RETRY_DELAYS)));

        if ($attachmentId < 1) {
            return false;
        }

        $delay = self::getThumbnailSyncRetryDelay($attempt);
        if (null === $delay) {
            return false;
        }

        $eventArgs = [$attachmentId, $attempt];
        if (wp_next_scheduled(self::THUMBNAIL_SYNC_HOOK, $eventArgs)) {
            return true;
        }

        $scheduled = wp_schedule_single_event(time() + $delay, self::THUMBNAIL_SYNC_HOOK, $eventArgs);

        return false !== $scheduled;
    }

    /**
     * Removes any pending background thumbnail sync events for an attachment.
     *
     * @param int $attachmentId The attachment ID.
     * @return void
     */
    private function clearScheduledThumbnailSync($attachmentId) {
        self::clearScheduledThumbnailSyncEvents($attachmentId);
    }

    /**
     * Removes any pending background thumbnail sync events for an attachment.
     *
     * @param int $attachmentId The attachment ID.
     * @return void
     */
    private static function clearScheduledThumbnailSyncEvents($attachmentId) {
        $attachmentId = absint($attachmentId);
        if ($attachmentId < 1) {
            return;
        }

        foreach (array_keys(self::THUMBNAIL_SYNC_RETRY_DELAYS) as $attempt) {
            $eventArgs = [$attachmentId, (int) $attempt];
            $timestamp = wp_next_scheduled(self::THUMBNAIL_SYNC_HOOK, $eventArgs);

            while ($timestamp) {
                wp_unschedule_event($timestamp, self::THUMBNAIL_SYNC_HOOK, $eventArgs);
                $timestamp = wp_next_scheduled(self::THUMBNAIL_SYNC_HOOK, $eventArgs);
            }
        }
    }

    /**
     * Returns the bounded retry delay for a thumbnail sync attempt.
     *
     * @param int $attempt The attempt number.
     * @return int|null Delay in seconds, or null when out of range.
     */
    private static function getThumbnailSyncRetryDelay($attempt) {
        $attempt = (int) $attempt;

        if (!isset(self::THUMBNAIL_SYNC_RETRY_DELAYS[$attempt])) {
            return null;
        }

        return self::THUMBNAIL_SYNC_RETRY_DELAYS[$attempt];
    }

    /**
     * Schedule the next background thumbnail sync attempt, or log exhaustion.
     *
     * @param int    $attachmentId The attachment ID.
     * @param int    $attempt      The current attempt number.
     * @param string $reason       Why another attempt is being queued.
     * @return void
     */
    private function scheduleNextThumbnailSyncAttempt($attachmentId, $attempt, $reason) {
        $attachmentId = absint($attachmentId);
        $attempt      = max(1, min(absint($attempt), count(self::THUMBNAIL_SYNC_RETRY_DELAYS)));

        if ($attachmentId < 1) {
            return;
        }

        if ($attempt >= count(self::THUMBNAIL_SYNC_RETRY_DELAYS)) {
            BunnyLogger::log(
                "syncVideoThumbnailMetadata: Attachment {$attachmentId} exhausted automatic background thumbnail sync attempts after attempt {$attempt}.",
                'warning'
            );
            return;
        }

        $nextAttempt = $attempt + 1;

        if ($this->scheduleThumbnailSyncRetry($attachmentId, $nextAttempt)) {
            BunnyLogger::log(
                "syncVideoThumbnailMetadata: Scheduled retry {$nextAttempt} for attachment {$attachmentId} after {$reason}.",
                'info'
            );
            return;
        }

        BunnyLogger::log(
            "syncVideoThumbnailMetadata: Failed to schedule retry {$nextAttempt} for attachment {$attachmentId} after {$reason}.",
            'warning'
        );
    }

    /**
     * Offloads a video to Bunny.net with enhanced error handling and logging.
     *
     * @param array $upload  Data array representing the uploaded file.
     * @param int   $post_id The attachment post ID.
     * @param int   $user_id The ID of the user performing the upload.
     * @return array|WP_Error The modified upload array including Bunny.net video details or WP_Error on failure.
     */
    public function offloadVideo($upload, $post_id, $user_id) {

        BunnyLogger::log("offloadVideo: Processing upload for post ID {$post_id}, user ID {$user_id}.", 'debug');

        // Validate file existence.
        if (!isset($upload['file']) || !file_exists($upload['file'])) {
            BunnyLogger::log('Invalid file path provided for video offloading.', 'error');
            return new \WP_Error('invalid_file_path', __('The provided file path is invalid.', 'indigetal-media-offload-for-bunny-net'));
        }
        $filePath = $upload['file'];
    
        // Validate MIME type.
        $mimeValidation = BunnyVideoHandler::getInstance()->validateMimeType($filePath);
        if (is_wp_error($mimeValidation)) {
            BunnyLogger::log('MIME type validation failed: ' . $mimeValidation->get_error_message(), 'error');
            return $mimeValidation;
        }
    
        // Retrieve user data.
        $user = get_userdata($user_id);
        if (!$user) {
            BunnyLogger::log("Could not retrieve user data for user ID {$user_id}", 'error');
            return new \WP_Error('invalid_user', __('Invalid user specified.', 'indigetal-media-offload-for-bunny-net'));
        }
    
        // Step 1: Resolve the user's collection.
        $collectionId = BunnyCollectionHandler::getInstance()->resolveCollectionIdForUser($user_id);

        if (is_wp_error($collectionId)) {
            BunnyLogger::log("Failed to resolve collection for user ID {$user_id}: " . $collectionId->get_error_message(), 'error');
            return $collectionId;
        }
    
        // Step 2: Offload the video file using BunnyApi.
        $uploadResponse = BunnyVideoHandler::getInstance()->uploadVideo($filePath, $collectionId, $post_id);
        
        if (is_wp_error($uploadResponse)) {
            BunnyLogger::log("Video upload failed: " . $uploadResponse->get_error_message(), 'error');
            return $uploadResponse;
        }
    
        // Step 3: Validate API response.
        if (!is_array($uploadResponse) || !isset($uploadResponse['videoId']) || empty($uploadResponse['videoUrl'])) {
            BunnyLogger::log("Invalid API response received: " . json_encode($uploadResponse), 'error');
            return new \WP_Error('invalid_api_response', __('Bunny.net did not return a valid videoId or videoUrl.', 'indigetal-media-offload-for-bunny-net'));
        }
    
        // Step 4: Optionally delete the local file.
        if (BunnyConfigurationStore::shouldRemoveLocalVideoFiles() && file_exists($filePath)) {
            wp_delete_file($filePath);
        }
    
        // Step 5: Update the upload data with Bunny.net details.
        $upload['bunny_video_url'] = $uploadResponse['videoUrl'];
        $upload['video_id'] = $uploadResponse['videoId'];

        $this->finalizeSuccessfulStreamOffload($post_id, (string) $uploadResponse['videoId']);

        BunnyLogger::log("Video offloaded successfully. Video ID: " . $uploadResponse['videoId'], 'info');
        return $upload;
    }        

    /**
     * Persist Stream success meta and schedule thumbnail hydration.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $video_id      Bunny Stream video GUID.
     * @return void
     */
    private function finalizeSuccessfulStreamOffload($attachment_id, $video_id) {
        $attachment_id = absint($attachment_id);
        $video_id = sanitize_text_field((string) $video_id);

        if ($attachment_id < 1 || '' === $video_id) {
            return;
        }

        update_post_meta($attachment_id, BunnyMetadataManager::VIDEO_ID_META_KEY, $video_id);

        $bunny_thumbnail_url = (string) get_post_meta($attachment_id, BunnyMetadataManager::THUMBNAIL_URL_META_KEY, true);
        if ('' !== $bunny_thumbnail_url) {
            return;
        }

        if ($this->scheduleInitialThumbnailSync($attachment_id)) {
            BunnyLogger::log("finalizeSuccessfulStreamOffload: Scheduled thumbnail sync for post ID {$attachment_id}.", 'debug');
        } else {
            BunnyLogger::log("finalizeSuccessfulStreamOffload: Failed to schedule thumbnail sync for post ID {$attachment_id}.", 'warning');
        }
    }

    public function handleAttachmentMetadata($post_id) {
        $post_id = absint($post_id);
        if ($post_id < 1) {
            return;
        }

        // Check if this attachment is a video.
        $mime = (string) get_post_mime_type($post_id);
        if (0 !== strpos($mime, 'video/')) {
            return;
        }
    
        // Prevent WP Offload Media from triggering multiple uploads
        if (class_exists('AS3CF_Plugin')) {
            BunnyLogger::log("handleAttachmentMetadata: WP Offload Media detected. Skipping duplicate execution.", 'warning');
            return;
        }

        if (!BunnyConfigurationStore::isStreamEnabled()) {
            BunnyLogger::log("handleAttachmentMetadata: Stream uploads are disabled. Skipping post ID {$post_id}.", 'info');
            return;
        }

        if (!BunnyConfigurationStore::isStreamUploadRuntimeReady()) {
            BunnyLogger::log("handleAttachmentMetadata: Stream upload runtime is not configured. Skipping post ID {$post_id}.", 'warning');
            return;
        }
    
        // Prevent duplicate uploads using a transient lock
        $lock_key = "indigetal_offload_video_upload_lock_{$post_id}";
        if (get_transient($lock_key)) {
            BunnyLogger::log("handleAttachmentMetadata: Upload for post ID {$post_id} is already in progress.", 'warning');
            return;
        }
        set_transient($lock_key, true, 60); // Lock expires after 60 seconds
    
        // If offloading has already been done, skip.
        $bunny_video_id = get_post_meta($post_id, BunnyMetadataManager::VIDEO_ID_META_KEY, true);
        if (!empty($bunny_video_id)) {
            BunnyLogger::log("handleAttachmentMetadata: Video already offloaded (ID: {$bunny_video_id}).", 'info');
            return;
        }
    
        // Retrieve the file path.
        $filePath = get_attached_file($post_id);
        if (!$filePath || !file_exists($filePath)) {
            BunnyLogger::log("handleAttachmentMetadata: Invalid file path for post ID {$post_id}.", 'error');
            return;
        }
    
        // Get the user ID from the attachment post.
        $user_id = (int) get_post_field('post_author', $post_id);
        if (!$user_id) {
            BunnyLogger::log("handleAttachmentMetadata: No user found for post ID {$post_id}.", 'error');
            return;
        }
    
        // Build a minimal upload array for offloadVideo().
        $upload_data = ['file' => $filePath];
    
        BunnyLogger::log("handleAttachmentMetadata: Calling offloadVideo() for post ID {$post_id}.", 'debug');
    
        // Call offloadVideo() to offload the video.
        $result = $this->offloadVideo($upload_data, $post_id, $user_id);
        if (is_wp_error($result)) {
            BunnyLogger::log("handleAttachmentMetadata: Offloading failed for post ID {$post_id}: " . $result->get_error_message(), 'error');
            return;
        }
    
        if (isset($result['video_id'])) {
            BunnyLogger::log("handleAttachmentMetadata: Offloading succeeded for post ID {$post_id}.", 'info');
        }  
    }     
    
    /**
     * Handles the deletion of a WordPress media attachment.
     *
     * @param int $post_id The ID of the deleted attachment.
     */
    public function handleAttachmentDeletion($post_id) {
        $post_id = absint($post_id);
        if ($post_id < 1) {
            return;
        }

        $this->clearScheduledThumbnailSync($post_id);

        // Retrieve the Bunny.net video ID
        $bunny_video_id = get_post_meta($post_id, BunnyMetadataManager::VIDEO_ID_META_KEY, true);

        // Ensure video ID exists before proceeding
        if (!empty($bunny_video_id)) {
            // Retrieve the library ID using the existing API client method
            $library_id = $this->apiClient->getLibraryId();

            // Ensure library ID exists
            if (!empty($library_id)) {
                // Get BunnyVideoHandler instance
                $video_handler = BunnyVideoHandler::getInstance();

                // Call the deleteVideo method to remove the video from Bunny.net
                $video_handler->deleteVideo($library_id, $bunny_video_id);
            }
        }
    }

    /**
     * Background callback that reuses BunnyVideoHandler::getVideoStatus() to hydrate
     * Bunny thumbnail metadata for directly uploaded Media Library videos.
     *
     * @param int $attachmentId The attachment ID.
     * @param int $attempt      The current retry attempt.
     * @return void
     */
    public function syncVideoThumbnailMetadata($attachmentId, $attempt = 1) {
        $attachmentId = absint($attachmentId);
        $attempt      = max(1, min(absint($attempt), count(self::THUMBNAIL_SYNC_RETRY_DELAYS)));

        if ($attachmentId < 1) {
            return;
        }

        $attachment = get_post($attachmentId);
        if (!$attachment || 'attachment' !== $attachment->post_type) {
            $this->clearScheduledThumbnailSync($attachmentId);
            BunnyLogger::log("syncVideoThumbnailMetadata: Attachment {$attachmentId} no longer exists. Clearing pending sync events.", 'info');
            return;
        }

        if (0 !== strpos((string) $attachment->post_mime_type, 'video/')) {
            $this->clearScheduledThumbnailSync($attachmentId);
            BunnyLogger::log("syncVideoThumbnailMetadata: Attachment {$attachmentId} is no longer a video attachment. Clearing pending sync events.", 'info');
            return;
        }

        $thumbnailUrl = (string) get_post_meta($attachmentId, BunnyMetadataManager::THUMBNAIL_URL_META_KEY, true);
        if (!empty($thumbnailUrl)) {
            $this->clearScheduledThumbnailSync($attachmentId);
            BunnyLogger::log("syncVideoThumbnailMetadata: Attachment {$attachmentId} already has Bunny thumbnail metadata. Stopping retries.", 'debug');
            return;
        }

        $videoId = (string) get_post_meta($attachmentId, BunnyMetadataManager::VIDEO_ID_META_KEY, true);
        if (empty($videoId)) {
            $this->clearScheduledThumbnailSync($attachmentId);
            BunnyLogger::log("syncVideoThumbnailMetadata: Attachment {$attachmentId} is missing Stream video ID meta. Clearing pending sync events.", 'warning');
            return;
        }

        $this->clearScheduledThumbnailSync($attachmentId);

        $statusResponse = BunnyVideoHandler::getInstance()->getVideoStatus($videoId, $attachmentId);
        if (is_wp_error($statusResponse)) {
            BunnyLogger::log(
                "syncVideoThumbnailMetadata: Bunny status check failed for attachment {$attachmentId} on attempt {$attempt}: " . $statusResponse->get_error_message(),
                'warning'
            );
            $this->scheduleNextThumbnailSyncAttempt($attachmentId, $attempt, 'WP_Error status response');
            return;
        }

        $thumbnailUrl = (string) get_post_meta($attachmentId, BunnyMetadataManager::THUMBNAIL_URL_META_KEY, true);
        if (!empty($thumbnailUrl)) {
            BunnyLogger::log("syncVideoThumbnailMetadata: Attachment {$attachmentId} thumbnail metadata hydrated on attempt {$attempt}.", 'info');
            return;
        }

        $status = isset($statusResponse['status']) ? (int) $statusResponse['status'] : -1;

        if (5 === $status) {
            BunnyLogger::log("syncVideoThumbnailMetadata: Bunny reported permanent failure for attachment {$attachmentId}. Stopping retries.", 'error');
            return;
        }

        if (in_array($status, [0, 1, 2], true)) {
            $this->scheduleNextThumbnailSyncAttempt($attachmentId, $attempt, "status {$status}");
            return;
        }

        if (in_array($status, [3, 4], true)) {
            BunnyLogger::log(
                "syncVideoThumbnailMetadata: Attachment {$attachmentId} is playable (status {$status}) but thumbnail metadata is still missing after status refresh.",
                'warning'
            );
            return;
        }

        BunnyLogger::log(
            "syncVideoThumbnailMetadata: Attachment {$attachmentId} returned unexpected Bunny status {$status}. Stopping retries.",
            'warning'
        );
    }

    private function getBunnyThumbnailContext($attachment_id) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id < 1) {
            return null;
        }

        $attachment = get_post($attachment_id);
        if (!$attachment || 'attachment' !== $attachment->post_type) {
            return null;
        }

        if (0 !== strpos((string) $attachment->post_mime_type, 'video/')) {
            return null;
        }

        $bunnyThumbnailUrl = get_post_meta($attachment_id, BunnyMetadataManager::THUMBNAIL_URL_META_KEY, true);
        if (empty($bunnyThumbnailUrl)) {
            return null;
        }

        return [
            'url'    => esc_url_raw($bunnyThumbnailUrl),
            'width'  => max(0, (int) get_post_meta($attachment_id, BunnyMetadataManager::VIDEO_WIDTH_META_KEY, true)),
            'height' => max(0, (int) get_post_meta($attachment_id, BunnyMetadataManager::VIDEO_HEIGHT_META_KEY, true)),
        ];
    }

    /**
     * Build the wp-admin Stream preview notice state for an attachment.
     *
     * @param int $attachment_id Attachment ID.
     * @return array<string, mixed>|null
     */
    private function getStreamPreviewNoticeState($attachment_id) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id < 1) {
            return null;
        }

        $attachment = get_post($attachment_id);
        if (!$attachment || 'attachment' !== $attachment->post_type) {
            return null;
        }

        if (0 !== strpos((string) $attachment->post_mime_type, 'video/')) {
            return null;
        }

        $video_id = sanitize_text_field((string) get_post_meta($attachment_id, BunnyMetadataManager::VIDEO_ID_META_KEY, true));
        if ('' === $video_id) {
            if (!$this->isFreshStreamVideoOffloadCandidate($attachment)) {
                return null;
            }

            return [
                'processing'       => true,
                'offloading'       => true,
                'previewPreparing' => true,
                'hasThumbnail'     => false,
                'attachmentId'     => $attachment_id,
                'videoId'          => '',
                'restNamespace'    => BunnyStreamStatusController::REST_NAMESPACE,
                'statusRoute'      => '/' . BunnyStreamStatusController::REST_NAMESPACE . BunnyStreamStatusController::REST_ROUTE,
                'className'        => 'indigetal-offload-stream-preview-processing',
                'dataAttribute'    => 'data-indigetal-offload-stream-processing',
                'message'          => __('This video is being offloaded to Bunny Stream. Keep this page open or refresh it later to preview the offloaded video.', 'indigetal-media-offload-for-bunny-net'),
            ];
        }

        $bunny_thumbnail_url = (string) get_post_meta($attachment_id, BunnyMetadataManager::THUMBNAIL_URL_META_KEY, true);
        if ('' !== $bunny_thumbnail_url) {
            return null;
        }

        return [
            'processing'         => true,
            'previewPreparing'   => true,
            'hasThumbnail'       => false,
            'previewReadyStatus' => 3,
            'attachmentId'       => $attachment_id,
            'videoId'            => $video_id,
            'restNamespace'      => BunnyStreamStatusController::REST_NAMESPACE,
            'statusRoute'        => '/' . BunnyStreamStatusController::REST_NAMESPACE . BunnyStreamStatusController::REST_ROUTE,
            'className'          => 'indigetal-offload-stream-preview-processing',
            'dataAttribute'      => 'data-indigetal-offload-stream-processing',
            'message'            => __('Bunny Stream is still preparing this video preview. The preview will update when it is ready.', 'indigetal-media-offload-for-bunny-net'),
        ];
    }

    /**
     * Determine whether a local video attachment is likely in the initial Stream offload window.
     *
     * @param \WP_Post $attachment Attachment post object.
     * @return bool
     */
    private function isFreshStreamVideoOffloadCandidate($attachment) {
        if (!$attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type) {
            return false;
        }

        if (!BunnyConfigurationStore::isStreamEnabled() || !BunnyConfigurationStore::isStreamUploadRuntimeReady()) {
            return false;
        }

        $lock_key = 'indigetal_offload_video_upload_lock_' . (int) $attachment->ID;
        if (get_transient($lock_key)) {
            return true;
        }

        $created_at = get_post_time('U', true, $attachment);
        if (!is_numeric($created_at) || (int) $created_at < 1) {
            return false;
        }

        return (time() - (int) $created_at) <= self::FRESH_VIDEO_OFFLOAD_WINDOW_SECONDS;
    }

    /**
     * Trigger the bounded Bunny -> WordPress metadata refresh on admin/editor attachment reads.
     *
     * @param \WP_Post $attachment Attachment post object.
     * @return \WP_Post
     */
    private function maybeRefreshAttachmentFromRemote($attachment) {
        if (!$attachment instanceof \WP_Post || !current_user_can('upload_files')) {
            return $attachment;
        }

        BunnyVideoMetadataSync::refreshAttachmentFromRemote($attachment->ID);

        $fresh_attachment = get_post($attachment->ID);
        if ($fresh_attachment instanceof \WP_Post) {
            return $fresh_attachment;
        }

        return $attachment;
    }

    public function filterAttachmentForJs($response, $attachment, $meta) {
        if (!is_array($response) || !$attachment instanceof \WP_Post) {
            return $response;
        }

        $attachment = $this->maybeRefreshAttachmentFromRemote($attachment);
        $response['title'] = $attachment->post_title;
        $response['description'] = $attachment->post_content;

        $thumbnailContext = $this->getBunnyThumbnailContext($attachment->ID);
        $processing_state = $this->getStreamPreviewNoticeState($attachment->ID);
        if ($processing_state) {
            $response['indigetalOffloadStreamProcessing'] = $processing_state;
        }

        if (!$thumbnailContext) {
            return $response;
        }

        $orientation = $thumbnailContext['width'] > $thumbnailContext['height'] ? 'landscape' : 'portrait';

        if (!isset($response['image']) || !is_array($response['image'])) {
            $response['image'] = [];
        }

        $response['image']['src'] = $thumbnailContext['url'];
        $response['image']['width'] = $thumbnailContext['width'];
        $response['image']['height'] = $thumbnailContext['height'];

        if (!isset($response['thumb']) || !is_array($response['thumb'])) {
            $response['thumb'] = [];
        }

        $response['thumb']['src'] = $thumbnailContext['url'];
        $response['icon'] = $thumbnailContext['url'];

        if (!isset($response['sizes']) || !is_array($response['sizes']) || empty($response['sizes'])) {
            $response['sizes'] = [];

            foreach (['thumbnail', 'medium', 'large', 'full'] as $sizeName) {
                $response['sizes'][$sizeName] = [
                    'url'         => $thumbnailContext['url'],
                    'width'       => $thumbnailContext['width'],
                    'height'      => $thumbnailContext['height'],
                    'orientation' => $orientation,
                ];
            }
        } else {
            foreach ($response['sizes'] as $sizeName => $sizeData) {
                if (!is_array($sizeData)) {
                    $sizeData = [];
                }

                $response['sizes'][$sizeName] = array_merge(
                    $sizeData,
                    [
                        'url'         => $thumbnailContext['url'],
                        'width'       => $thumbnailContext['width'],
                        'height'      => $thumbnailContext['height'],
                        'orientation' => $orientation,
                    ]
                );
            }
        }

        return $response;
    }

    public function filterAttachmentImageSrc($image, $attachment_id, $size, $icon) {
        $thumbnailContext = $this->getBunnyThumbnailContext($attachment_id);
        if (!$thumbnailContext) {
            return $image;
        }

        return [
            $thumbnailContext['url'],
            $thumbnailContext['width'],
            $thumbnailContext['height'],
            false,
        ];
    }

    public function filterAttachmentImageAttributes($attr, $attachment, $size) {
        if (!$attachment instanceof \WP_Post) {
            return $attr;
        }

        $thumbnailContext = $this->getBunnyThumbnailContext($attachment->ID);
        if (!$thumbnailContext) {
            return $attr;
        }

        unset($attr['srcset'], $attr['sizes']);

        return $attr;
    }

    public function filterRestAttachment($response, $post, $request) {
        if (!method_exists($response, 'get_data') || !$post instanceof \WP_Post) {
            return $response;
        }

        $post = $this->maybeRefreshAttachmentFromRemote($post);

        $thumbnailContext = $this->getBunnyThumbnailContext($post->ID);

        $data = $response->get_data();
        $processing_state = $this->getStreamPreviewNoticeState($post->ID);
        if ($processing_state) {
            $data['indigetal_offload_stream_processing'] = $processing_state;
        }

        if (isset($data['title'])) {
            if (is_array($data['title'])) {
                if (array_key_exists('raw', $data['title'])) {
                    $data['title']['raw'] = $post->post_title;
                }

                if (array_key_exists('rendered', $data['title'])) {
                    $data['title']['rendered'] = $post->post_title;
                }
            } else {
                $data['title'] = $post->post_title;
            }
        }

        if (isset($data['description'])) {
            if (is_array($data['description'])) {
                if (array_key_exists('raw', $data['description'])) {
                    $data['description']['raw'] = $post->post_content;
                }

                if (array_key_exists('rendered', $data['description'])) {
                    $data['description']['rendered'] = $post->post_content;
                }
            } else {
                $data['description'] = $post->post_content;
            }
        }

        if (!$thumbnailContext) {
            $response->set_data($data);
            return $response;
        }

        if (!isset($data['media_details']) || !is_array($data['media_details'])) {
            $data['media_details'] = [];
        }

        if (!isset($data['media_details']['sizes']) || !is_array($data['media_details']['sizes'])) {
            $data['media_details']['sizes'] = [];
        }

        $fileName = basename($thumbnailContext['url']);

        foreach (['thumbnail', 'medium', 'large', 'full'] as $sizeName) {
            $data['media_details']['sizes'][$sizeName] = [
                'source_url' => $thumbnailContext['url'],
                'width'      => $thumbnailContext['width'],
                'height'     => $thumbnailContext['height'],
                'mime_type'  => 'image/jpeg',
                'file'       => $fileName,
            ];
        }

        $response->set_data($data);

        return $response;
    }

    /**
     * Pass through Media Library AJAX attachment query args unchanged.
     *
     * @param array<string, mixed> $query Attachment AJAX query args.
     * @return array<string, mixed>
     */
    public function filterAjaxAttachmentQueries($query) {
        return is_array($query) ? $query : [];
    }

    /**
     * Override wp_get_attachment_url to use the preserved Stream MP4 fallback URL if available.
     *
     * Free continues to expose `play_720p.mp4` as the primary WordPress
     * attachment URL and does not verify Bunny MP4 fallback availability.
     *
     * @param string $url The original attachment URL.
     * @param int    $postId The attachment post ID.
     * @return string The overridden Bunny.net URL if available, otherwise the original URL.
     */
    public function filterBunnyVideoURL($url, $postId) {
        $postId = (int) $postId;
        $videoHandler = BunnyVideoHandler::getInstance();
        $playbackUrls = $videoHandler->getPlaybackUrls($postId);

        if (empty($playbackUrls['mp4']) || !is_string($playbackUrls['mp4'])) {
            return $url;
        }

        $mp4 = $playbackUrls['mp4'];

        /**
         * Filter Stream MP4 attachment URLs produced by Free Media Library integration.
         *
         * @param string $mp4           MP4 URL.
         * @param int    $attachment_id Attachment ID.
         * @param array<string, mixed> $context Context (e.g. `source` => `attachment_mp4`, `context` => `primary`).
         */
        $filtered = apply_filters('indigetal_offload_stream_url', $mp4, $postId, ['source' => 'attachment_mp4', 'context' => 'primary']);

        return is_string($filtered) && '' !== $filtered ? esc_url_raw($filtered) : esc_url_raw($mp4);
    }

    /**
     * Read-only Stream attachment metadata for Pro and companion add-ons.
     *
     * Stable Free extension surface: method name, parameters, and return keys are maintained
     * for add-on compatibility unless a release explicitly documents a breaking change. Does
     * not call Bunny APIs. Keys: video_id, iframe_url, thumbnail_url, video_width, video_height,
     * collection_id (author user meta).
     *
     * @param int $attachment_id Attachment ID.
     * @return array<string, mixed> {
     *     @type string $video_id      Bunny Stream video GUID, if stored.
     *     @type string $iframe_url    Embed URL meta, if stored.
     *     @type string $thumbnail_url Bunny thumbnail URL meta, if stored.
     *     @type string $video_width   Reported width meta, if stored.
     *     @type string $video_height  Reported height meta, if stored.
     *     @type string $collection_id Author's Bunny collection id user meta, if stored.
     * }
     */
    public static function getAttachmentStreamMetadata($attachment_id) {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1) {
            return [];
        }

        return [
            'video_id'       => get_post_meta($attachment_id, BunnyMetadataManager::VIDEO_ID_META_KEY, true),
            'iframe_url'     => get_post_meta($attachment_id, BunnyMetadataManager::IFRAME_URL_META_KEY, true),
            'thumbnail_url'  => get_post_meta($attachment_id, BunnyMetadataManager::THUMBNAIL_URL_META_KEY, true),
            'video_width'    => get_post_meta($attachment_id, BunnyMetadataManager::VIDEO_WIDTH_META_KEY, true),
            'video_height'   => get_post_meta($attachment_id, BunnyMetadataManager::VIDEO_HEIGHT_META_KEY, true),
            'collection_id'  => get_user_meta((int) get_post_field('post_author', $attachment_id), BunnyMetadataManager::COLLECTION_ID_META_KEY, true),
        ];
    }

    /**
     * Read-only Stream offload status snapshot for Pro and companion add-ons.
     *
     * Stable Free extension surface: method name, parameters, and return keys are maintained
     * for add-on compatibility unless a release explicitly documents a breaking change. Does
     * not call Bunny APIs. Keys: attachment_id, stream_runtime_ready, has_stream_video_id.
     *
     * @param int $attachment_id Attachment ID.
     * @return array<string, mixed> {
     *     @type int  $attachment_id        Attachment ID (0 when input invalid).
     *     @type bool $stream_runtime_ready Whether Stream upload runtime is configured.
     *     @type bool $has_stream_video_id  Whether Stream video ID meta is non-empty.
     * }
     */
    public static function getAttachmentStreamStatus($attachment_id) {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1) {
            return [
                'attachment_id'         => 0,
                'stream_runtime_ready'  => false,
                'has_stream_video_id'   => false,
            ];
        }

        return [
            'attachment_id'         => $attachment_id,
            'stream_runtime_ready'  => BunnyConfigurationStore::isStreamUploadRuntimeReady(),
            'has_stream_video_id'   => '' !== (string) get_post_meta($attachment_id, BunnyMetadataManager::VIDEO_ID_META_KEY, true),
        ];
    }
}
