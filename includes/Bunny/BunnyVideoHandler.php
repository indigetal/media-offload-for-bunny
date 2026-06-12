<?php

namespace Bunny_Offload\Bunny;

use Bunny_Offload\Integration\BunnyMetadataManager;
use Bunny_Offload\Integration\BunnyVideoMetadataSync;
use Bunny_Offload\Settings\BunnyConfigurationStore;
use Bunny_Offload\Utils\BunnyLogger;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyVideoHandler {
    private static $instance = null;
    private const DIRECT_UPLOAD_MEMORY_SAFETY_BUFFER = 16777216; // 16 MiB.
    /**
     * Bunny-compatible subset of WordPress default video MIME types for Media Library uploads.
     *
     * This is not Bunny's full documented container list, and it does not extend
     * WordPress's default upload MIME support.
     */
    private const SUPPORTED_STREAM_MIME_TYPES = [
        'video/mp4',
        'video/x-matroska',
        'video/webm',
        'video/quicktime',
        'video/avi',
        'video/x-flv',
        'video/x-ms-wmv',
        'video/mpeg',
    ];

    private $apiClient;
    public $video_base_url;
    private $access_key;

    private function __construct() {
        $this->apiClient = BunnyApiClient::getInstance();
        $this->video_base_url = $this->apiClient->video_base_url;
        $this->access_key = $this->apiClient->getAccessKey();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the Stream playback URLs currently exposed by the Free Media Library path.
     *
     * The primary attachment URL intentionally remains Bunny's `play_720p.mp4`
     * MP4 fallback URL. This assumes MP4 fallback was enabled in Bunny before
     * upload; Free does not verify fallback enablement or file availability.
     */
    public function getPlaybackUrls($postId) {
        $videoId = get_post_meta($postId, BunnyMetadataManager::VIDEO_ID_META_KEY, true);
        if (empty($videoId)) {
            return null;
        }

        $pullZone = BunnyConfigurationStore::getStreamPullZoneHostname();
        $iframeUrl = get_post_meta($postId, BunnyMetadataManager::IFRAME_URL_META_KEY, true);

        return [
            'mp4'    => "https://{$pullZone}/{$videoId}/play_720p.mp4",
            'iframe' => $iframeUrl, // No need to reconstruct dynamically
        ];
    }

    /**
     * Upload a video to Bunny.net.
     *
     * Collection management is handled by BunnyMediaLibrary::offloadVideo() before
     * calling this method. Pass the resolved $collectionId directly.
     *
     * @param string      $filePath     The path to the video file on the server.
     * @param string|null $collectionId The collection ID to associate the video with.
     * @param int|null    $postId       The post ID (optional).
     * @return array|\WP_Error The API response or WP_Error on failure.
     */
    public function uploadVideo($filePath, $collectionId = null, $postId = null) {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to upload a video.', 'indigetal-media-offload-for-bunny-net'));
        }

        if (!is_string($filePath) || !file_exists($filePath)) {
            return new \WP_Error('invalid_file_path', __('Invalid file path for video upload.', 'indigetal-media-offload-for-bunny-net'));
        }

        $memoryPreflight = $this->validateDirectUploadMemory($filePath);
        if (is_wp_error($memoryPreflight)) {
            return $memoryPreflight;
        }

        // Create a new video object in the collection
        $videoId = $this->createVideoObject(basename($filePath), $collectionId);

        if (is_wp_error($videoId)) {
            BunnyLogger::log("uploadVideo: Failed to create video object. Error: " . $videoId->get_error_message(), 'error');
            return $videoId;
        }

        BunnyLogger::log("uploadVideo: Created video ID {$videoId}. Uploading file to Bunny.net.", 'debug');

        // Step 3: Upload the video file using a PUT request
        if (empty($library_id) || empty($videoId)) {
            BunnyLogger::log("uploadVideo: ERROR - Missing Library ID or Video ID. Library ID: {$library_id}, Video ID: {$videoId}", 'error');
            return new \WP_Error('missing_video_data', __('Missing library ID or video ID.', 'indigetal-media-offload-for-bunny-net'));
        }

        $uploadEndpoint = "library/{$library_id}/videos/{$videoId}";

        $videoData = file_get_contents($filePath);
        if ($videoData === false || strlen($videoData) === 0) {
            BunnyLogger::log("uploadVideo: Failed to read video file for {$filePath}.", 'error');
            $readError = new \WP_Error('video_file_read_failed', __('Failed to read the video file before uploading.', 'indigetal-media-offload-for-bunny-net'));
            $this->rollbackJustCreatedVideo($library_id, $videoId, 'file read failure');
            return $readError;
        }

        $uploadResponse = $this->apiClient->executeWithRetry(function() use ($uploadEndpoint, $videoData) {
            return wp_remote_request($this->video_base_url . $uploadEndpoint, [
                'method'    => 'PUT',
                'headers'   => [
                    'AccessKey'    => $this->apiClient->getAccessKey(),
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/octet-stream'
                ],
                'body'      => $videoData,
                'timeout'   => 300,
            ]);
        });

        if (is_wp_error($uploadResponse)) {
            BunnyLogger::log("uploadVideo: File upload failed for {$filePath}. Error: " . $uploadResponse->get_error_message(), 'error');
            $this->rollbackJustCreatedVideo($library_id, $videoId, 'direct PUT WP_Error');
            return $uploadResponse;
        }

        $uploadResponseCode = (int) wp_remote_retrieve_response_code($uploadResponse);
        $responseBody = wp_remote_retrieve_body($uploadResponse);

        if ($uploadResponseCode < 200 || $uploadResponseCode >= 300) {
            BunnyLogger::log("uploadVideo: Bunny.net rejected upload for {$filePath} (HTTP {$uploadResponseCode}).", 'error');
            BunnyLogger::log("uploadVideo: Upload failure response body: " . ($responseBody ?: 'No response body'), 'debug');

            $uploadError = new \WP_Error(
                'video_upload_failed',
                sprintf(
                    /* translators: 1: HTTP status code, 2: response body from Bunny.net */
                    __('Bunny.net rejected the video upload (HTTP %1$d): %2$s', 'indigetal-media-offload-for-bunny-net'),
                    $uploadResponseCode,
                    $responseBody ?: 'No response body'
                ),
                [
                    'status'        => $uploadResponseCode,
                    'response_code' => $uploadResponseCode,
                    'body'          => $responseBody ?: 'No response body',
                    'endpoint'      => $uploadEndpoint,
                ]
            );

            $this->rollbackJustCreatedVideo($library_id, $videoId, "direct PUT HTTP {$uploadResponseCode}");
            return $uploadError;
        }

        // Log the full response from Bunny.net
        BunnyLogger::log('uploadVideo: Bunny.net Response - ' . ($responseBody ?: 'No response body'), 'debug');

        $pullZone = BunnyConfigurationStore::getStreamPullZoneHostname();
        if (empty($pullZone)) {
            BunnyLogger::log('uploadVideo: CDN hostname is missing.', 'error');
            return new \WP_Error('missing_pull_zone', __('CDN hostname is required to build video playback URLs.', 'indigetal-media-offload-for-bunny-net'));
        }

        if ($postId) {
            $library_id = $this->apiClient->getLibraryId();
            if (!empty($library_id)) {
                update_post_meta($postId, BunnyMetadataManager::IFRAME_URL_META_KEY, "https://player.mediadelivery.net/embed/{$library_id}/{$videoId}");
            }

            update_post_meta($postId, BunnyMetadataManager::VIDEO_ID_META_KEY, $videoId);
        }

        $pullZone = BunnyConfigurationStore::getStreamPullZoneHostname();
        // Preserve the primary attachment URL contract; Free does not probe MP4 fallback availability.
        $playbackUrl = "https://{$pullZone}/{$videoId}/play_720p.mp4";

        return [
            'videoId'   => $videoId,
            'videoUrl'  => $playbackUrl, // Dynamically constructed MP4 URL
            'iframeUrl' => get_post_meta($postId, BunnyMetadataManager::IFRAME_URL_META_KEY, true), // Retrieve stored iframe URL
        ];

    }

    /**
     * Ensure the direct PUT upload path has enough PHP memory to read the file.
     *
     * @param string $filePath Absolute local video path.
     * @return true|\WP_Error
     */
    private function validateDirectUploadMemory($filePath) {
        clearstatcache(true, $filePath);

        $fileSize = filesize($filePath);
        if (false === $fileSize) {
            return new \WP_Error(
                'video_file_size_unavailable',
                __('Could not determine the video file size before uploading.', 'indigetal-media-offload-for-bunny-net')
            );
        }

        $memoryLimit = $this->getMemoryLimitBytes();
        if ($memoryLimit <= 0) {
            return true;
        }

        $currentUsage = memory_get_usage(true);
        $remainingMemory = max(0, $memoryLimit - $currentUsage);
        $requiredMemory = (int) $fileSize + self::DIRECT_UPLOAD_MEMORY_SAFETY_BUFFER;

        if ($remainingMemory >= $requiredMemory) {
            return true;
        }

        return new \WP_Error(
            'video_direct_upload_memory_limit_exceeded',
            sprintf(
                /* translators: 1: video file size, 2: remaining PHP memory, 3: required PHP memory */
                __('This video is too large for the server-side Stream upload path. File size: %1$s. Available PHP memory: %2$s. Required memory: %3$s. Reduce the file size, raise the PHP memory limit, or upload from an environment with a higher memory ceiling.', 'indigetal-media-offload-for-bunny-net'),
                size_format((int) $fileSize),
                size_format((int) $remainingMemory),
                size_format((int) $requiredMemory)
            ),
            [
                'file_size'        => (int) $fileSize,
                'memory_limit'     => (int) $memoryLimit,
                'memory_usage'     => (int) $currentUsage,
                'remaining_memory' => (int) $remainingMemory,
                'required_memory'  => (int) $requiredMemory,
            ]
        );
    }

    /**
     * Return the configured PHP memory limit in bytes.
     *
     * @return int
     */
    private function getMemoryLimitBytes() {
        $memoryLimit = ini_get('memory_limit');

        if (false === $memoryLimit || '' === $memoryLimit) {
            return 0;
        }

        return (int) wp_convert_hr_to_bytes($memoryLimit);
    }

    /**
     * Delete a Bunny Stream object created by this direct upload attempt.
     *
     * @param string $libraryId Bunny Stream library ID.
     * @param string $videoId   Bunny Stream video GUID created in this request.
     * @param string $reason    Short log-only rollback reason.
     * @return void
     */
    private function rollbackJustCreatedVideo($libraryId, $videoId, $reason) {
        $libraryId = (string) $libraryId;
        $videoId   = (string) $videoId;
        $reason    = sanitize_text_field((string) $reason);

        if ('' === $libraryId || '' === $videoId) {
            BunnyLogger::log('uploadVideo: Skipping rollback because the just-created video or library ID is missing.', 'warning');
            return;
        }

        BunnyLogger::log("uploadVideo: Rolling back just-created Bunny Stream video {$videoId} after {$reason}.", 'warning');

        if (!$this->deleteVideo($libraryId, $videoId)) {
            BunnyLogger::log("uploadVideo: Rollback failed for just-created Bunny Stream video {$videoId}; original upload error was preserved.", 'error');
        }
    }

    /**
     * Read normalized metadata for an existing Bunny-backed video.
     *
     * @param string $videoId Bunny Stream video GUID.
     * @return array<string, mixed>|\WP_Error
     */
    public function getExistingVideoMetadata($videoId) {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to read Bunny video metadata.', 'indigetal-media-offload-for-bunny-net'));
        }

        $videoId = $this->normalizeExistingVideoId($videoId);
        if ('' === $videoId) {
            return new \WP_Error('invalid_video_id', __('A valid Bunny video ID is required to read video metadata.', 'indigetal-media-offload-for-bunny-net'));
        }

        $response = $this->apiClient->getVideoDetails($library_id, $videoId);
        if (is_wp_error($response)) {
            return $response;
        }

        return BunnyVideoMetadataSync::normalizeRemoteVideoMetadata($response);
    }

    /**
     * Update title and/or description for an existing Bunny-backed video.
     *
     * Description updates preserve all non-description metaTags by reading the
     * current remote payload first, then replacing or removing only the
     * `description` metaTag before sending the full intended array back.
     *
     * @param string              $videoId Bunny Stream video GUID.
     * @param array<string, mixed> $updates Requested title/description updates.
     * @return array<string, mixed>|\WP_Error
     */
    public function updateExistingVideoMetadata($videoId, array $updates) {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to update Bunny video metadata.', 'indigetal-media-offload-for-bunny-net'));
        }

        $videoId = $this->normalizeExistingVideoId($videoId);
        if ('' === $videoId) {
            return new \WP_Error('invalid_video_id', __('A valid Bunny video ID is required to update video metadata.', 'indigetal-media-offload-for-bunny-net'));
        }

        $current_metadata = $this->getExistingVideoMetadata($videoId);
        if (is_wp_error($current_metadata)) {
            return $current_metadata;
        }

        $payload = BunnyVideoMetadataSync::buildRemoteVideoMetadataUpdatePayload($current_metadata, $updates);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $response = $this->apiClient->updateVideoDetails($library_id, $videoId, $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        return BunnyVideoMetadataSync::normalizeRemoteVideoMetadata($response);
    }

    /**
    * Create a video object
    */
    public function createVideoObject($title, $collectionId) {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to create a video object.', 'indigetal-media-offload-for-bunny-net'));
        }

        if (empty($collectionId)) {
            return new \WP_Error('missing_collection_id', __('Collection ID is required to create a video object.', 'indigetal-media-offload-for-bunny-net'));
        }

        $videoData = [
            'title' => $title,
            'collectionId' => trim($collectionId), // No need for an if check; collectionId is always required
        ];

        $response = $this->apiClient->sendJsonToBunny("library/{$library_id}/videos", 'POST', $videoData);

        if (is_wp_error($response)) {
            return $response;
        }

        if (is_string($response)) {
            BunnyLogger::log("createVideoObject: Response was a string, decoding it now.", 'warning');
            $response = json_decode($response, true);
        }

        if (empty($response['guid'])) {
            return new \WP_Error('video_creation_failed', __('Failed to create video object.', 'indigetal-media-offload-for-bunny-net'));
        }

        $videoId = $this->normalizeExistingVideoId($response['guid']);
        if ('' === $videoId) {
            return new \WP_Error('invalid_video_id', __('Bunny returned an invalid video ID.', 'indigetal-media-offload-for-bunny-net'));
        }

        return $videoId;
    }

    /**
     * Update a video's thumbnail on Bunny.net.
     *
     * @param string   $videoId    The ID of the video.
     * @param int|null $timestamp  Optional timestamp (in seconds) to generate the thumbnail from.
     * @param int|null $postId     Unused legacy parameter retained for backward compatibility.
     * @return bool|WP_Error True on success, WP_Error on failure.
    */
    public function setThumbnail($videoId, $timestamp = null, $postId = null) {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            BunnyLogger::log('Library ID is missing or not set.', 'warning');
            return new \WP_Error('missing_library_id', __('Library ID is required to set a thumbnail.', 'indigetal-media-offload-for-bunny-net'));
        }

        if (empty($videoId)) {
            return new \WP_Error('missing_video_id', __('Video ID is required to set a thumbnail.', 'indigetal-media-offload-for-bunny-net'));
        }

        $pullZone = BunnyConfigurationStore::getStreamPullZoneHostname();
        if (empty($pullZone)) {
            BunnyLogger::log('Pull Zone is missing or not set.', 'warning');
            return new \WP_Error('missing_pull_zone', __('Pull Zone is required to set a thumbnail.', 'indigetal-media-offload-for-bunny-net'));
        }

        // If a timestamp is provided, make a request to update the thumbnail
        if (!is_null($timestamp)) {
            $endpoint = "library/{$library_id}/videos/{$videoId}/thumbnail";
            $data = ['time' => $timestamp];

            $response = $this->apiClient->sendJsonToBunny($endpoint, 'POST', $data);

            if (is_wp_error($response)) {
                BunnyLogger::log('Bunny API Error: Failed to set video thumbnail: ' . $response->get_error_message(), 'error');
                return $response;
            }
        }

        return true;
    }

    /**
     * Validate MIME type before file upload.
     */
    public static function getSupportedMimeTypes() {
        return self::SUPPORTED_STREAM_MIME_TYPES;
    }

    /**
     * Determine whether a MIME type is supported for the default Stream upload path.
     *
     * @param string $mimeType MIME type to check.
     * @return bool True when the MIME type is supported.
     */
    public static function isSupportedMimeType($mimeType) {
        if (!is_string($mimeType)) {
            return false;
        }

        return in_array($mimeType, self::SUPPORTED_STREAM_MIME_TYPES, true);
    }

    public function validateMimeType($filePath) {
        $mime_type = mime_content_type($filePath);
        if (!self::isSupportedMimeType($mime_type)) {
            return new \WP_Error('invalid_mime', __('Invalid file type.', 'indigetal-media-offload-for-bunny-net'));
        }
        return true;
    }

    /**
     * Deletes a video from Bunny.net's video library.
     *
     * @param string $library_id The Bunny.net library ID.
     * @param string $video_id   The Bunny.net video ID to be deleted.
     * @return bool True on success, false on failure.
     */
    public function deleteVideo($library_id, $video_id) {
        // Validate input parameters
        if (empty($library_id) || empty($video_id)) {
            BunnyLogger::log("deleteVideo: Missing library ID or video ID. Library ID: {$library_id}, Video ID: {$video_id}", 'error');
            return false;
        }

        // Construct the API endpoint
        $deleteEndpoint = "library/{$library_id}/videos/{$video_id}";

        // Execute the DELETE request using sendJsonToBunny
        $response = $this->apiClient->sendJsonToBunny($deleteEndpoint, 'DELETE');

        // Handle response errors
        if (is_wp_error($response)) {
            BunnyLogger::log("deleteVideo: API request failed. Error: " . $response->get_error_message(), 'error');
            return false;
        }

        // Ensure response is an array and contains expected fields
        if (!is_array($response) || !isset($response['success'], $response['statusCode'])) {
            BunnyLogger::log("deleteVideo: Unexpected response structure from Bunny.net. Response: " . json_encode($response), 'error');
            return false;
        }

        // Extract statusCode from the response body
        $statusCode = (int) $response['statusCode'];
        $responseBody = json_encode($response);

        // Handle responses based on Bunny.net API documentation
        switch ($statusCode) {
            case 200:
                BunnyLogger::log("deleteVideo: Successfully deleted video ID {$video_id} from library {$library_id}. Response: {$responseBody}", 'info');
                return true;
            case 401:
                BunnyLogger::log("deleteVideo: Authorization failed. Check your Bunny.net Access Key.", 'error');
                return false;
            case 404:
                BunnyLogger::log("deleteVideo: Video ID {$video_id} not found in library {$library_id}. It may have already been deleted.", 'warning');
                return true; // No need to retry, since it's already gone.
            case 500:
                BunnyLogger::log("deleteVideo: Internal server error at Bunny.net. Retry may be required.", 'error');
                return false;
            default:
                BunnyLogger::log("deleteVideo: Unexpected response from Bunny.net. Status Code: {$statusCode}, Response: {$responseBody}", 'error');
                return false;
        }
    }

    /**
     * Retrieve encoding status for a Bunny Stream video.
     *
     * Bunny returns:
     * - 3 = fully finished
     * - 4 = one resolution finished and playable
     * - 5 = failed
     *
     * @param string $videoId Bunny Stream video GUID.
     * @param int    $attachmentId Optional attachment ID for thumbnail metadata writes
     *                             when reused by editor polling or internal cron sync.
     * @return array{status: int, encodeProgress: int, remoteResponse: array<string, mixed>}|\WP_Error
     */
    public function getVideoStatus($videoId, $attachmentId = 0) {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to check video status.', 'indigetal-media-offload-for-bunny-net'));
        }

        $attachmentId = absint($attachmentId);
        $videoId = preg_replace('/[^a-f0-9\-]/i', '', (string) $videoId);
        if (empty($videoId)) {
            return new \WP_Error('invalid_video_id', __('A valid video ID is required to check video status.', 'indigetal-media-offload-for-bunny-net'));
        }

        $response = $this->apiClient->sendJsonToBunny("library/{$library_id}/videos/{$videoId}", 'GET');
        if (\is_wp_error($response)) {
            return $response;
        }

        if (!is_array($response) || !isset($response['status'])) {
            return new \WP_Error('invalid_video_status_response', __('Unexpected response while checking Bunny video status.', 'indigetal-media-offload-for-bunny-net'));
        }

        $status = (int) $response['status'];
        $encodeProgress = 0;

        if (isset($response['encodeProgress'])) {
            $encodeProgress = (int) $response['encodeProgress'];
        } elseif (isset($response['encode_progress'])) {
            $encodeProgress = (int) $response['encode_progress'];
        }

        if ($attachmentId > 0 && in_array($status, [3, 4], true)) {
            $stored_video_id = (string) get_post_meta($attachmentId, BunnyMetadataManager::VIDEO_ID_META_KEY, true);

            if ('' !== $stored_video_id && $videoId === $stored_video_id) {
                $attachment = get_post($attachmentId);

                if ($attachment && 'attachment' === $attachment->post_type) {
                    $this->updatePlayableAttachmentMeta($attachmentId, $videoId, $response);
                }
            }
        }

        return [
            'status'         => $status,
            'encodeProgress' => max(0, min(100, $encodeProgress)),
            'remoteResponse' => $response,
        ];
    }

    /**
     * Write attachment metadata that is confirmed by a playable Bunny status.
     *
     * @param int                  $attachmentId Attachment ID.
     * @param string               $videoId      Bunny Stream video GUID.
     * @param array<string, mixed> $response     Bunny video status response.
     * @return void
     */
    private function updatePlayableAttachmentMeta($attachmentId, $videoId, array $response) {
        if (empty(get_post_meta($attachmentId, BunnyMetadataManager::THUMBNAIL_URL_META_KEY, true))) {
            $pullZone = BunnyConfigurationStore::getStreamPullZoneHostname();

            if (!empty($pullZone)) {
                $thumbnailFileName = sanitize_file_name($response['thumbnailFileName'] ?? 'thumbnail.jpg');
                $thumbnailUrl = sprintf('https://%s/%s/%s', $pullZone, $videoId, $thumbnailFileName);

                update_post_meta($attachmentId, BunnyMetadataManager::THUMBNAIL_URL_META_KEY, esc_url_raw($thumbnailUrl));

                $width = (int) ($response['width'] ?? 0);
                $height = (int) ($response['height'] ?? 0);

                if ($width > 0 && $height > 0) {
                    update_post_meta($attachmentId, BunnyMetadataManager::VIDEO_WIDTH_META_KEY, $width);
                    update_post_meta($attachmentId, BunnyMetadataManager::VIDEO_HEIGHT_META_KEY, $height);
                }
            }
        }
    }

    /**
     * Normalize existing-video GUIDs before using the metadata route.
     *
     * @param string $videoId Raw Bunny Stream video GUID.
     * @return string
     */
    private function normalizeExistingVideoId($videoId) {
        return preg_replace('/[^a-f0-9\-]/i', '', (string) $videoId);
    }
}
