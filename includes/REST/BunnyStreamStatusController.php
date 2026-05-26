<?php
/**
 * REST controller for the Bunny Stream encode-status polling endpoint.
 *
 * @package Bunny_Offload\REST
 */

namespace Bunny_Offload\REST;

use Bunny_Offload\Bunny\BunnyVideoHandler;
use Bunny_Offload\Integration\BunnyMetadataManager;
use Bunny_Offload\Integration\BunnyVideoMetadataSync;

if (!defined('ABSPATH')) {
    exit;
}

class BunnyStreamStatusController {

    const REST_NAMESPACE = 'indigetal-offload/v1';
    const REST_ROUTE = '/stream/video-status';

    /**
     * Bunny Stream video handler.
     *
     * @var BunnyVideoHandler
     */
    private $video_handler;

    /**
     * Register the REST controller.
     *
     * @param BunnyVideoHandler|null $video_handler Optional shared video handler instance.
     */
    public function __construct(?BunnyVideoHandler $video_handler = null) {
        $this->video_handler = $video_handler ?: BunnyVideoHandler::getInstance();

        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register the Stream status REST route.
     *
     * @return void
     */
    public function registerRoutes() {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'permission_callback' => [$this, 'permissionsCheck'],
                    'callback'            => [$this, 'getVideoStatus'],
                    'args'                => [
                        'video_id'      => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => [$this, 'sanitizeVideoId'],
                            'validate_callback' => [$this, 'validateVideoId'],
                        ],
                        'attachment_id' => [
                            'required'          => false,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                            'default'           => 0,
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Restrict status polling; attachment-scoped polls require edit_post and matching video ID.
     *
     * @param \WP_REST_Request $request REST request object.
     * @return bool
     */
    public function permissionsCheck($request) {
        return true === $this->authorizeAttachmentPoll($request);
    }

    /**
     * Authorize Stream status polling for the current user and request parameters.
     *
     * @param \WP_REST_Request $request REST request object.
     * @return true|\WP_Error True when allowed; WP_Error when denied.
     */
    private function authorizeAttachmentPoll($request) {
        if (!current_user_can('upload_files')) {
            return new \WP_Error(
                'rest_authorization_required',
                __('Sorry, you are not allowed to poll Stream status.', 'indigetal-media-offload-for-bunny-net'),
                ['status' => 401]
            );
        }

        $attachment_id = (int) $request->get_param('attachment_id');
        if ($attachment_id < 1) {
            return true;
        }

        $attachment = get_post($attachment_id);
        if (!$attachment || 'attachment' !== $attachment->post_type) {
            return new \WP_Error(
                'rest_forbidden',
                __('Invalid attachment for Stream status polling.', 'indigetal-media-offload-for-bunny-net'),
                ['status' => 403]
            );
        }

        if (!current_user_can('edit_post', $attachment_id)) {
            return new \WP_Error(
                'rest_forbidden',
                __('Sorry, you are not allowed to poll Stream status for this attachment.', 'indigetal-media-offload-for-bunny-net'),
                ['status' => 403]
            );
        }

        $video_id = $this->sanitizeVideoId($request->get_param('video_id'));
        $stored_video_id = (string) get_post_meta($attachment_id, BunnyMetadataManager::VIDEO_ID_META_KEY, true);
        if ('' === $stored_video_id || $video_id !== $stored_video_id) {
            return new \WP_Error(
                'rest_forbidden',
                __('Video ID does not match this attachment.', 'indigetal-media-offload-for-bunny-net'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Sanitize a Bunny Stream video GUID.
     *
     * @param mixed $value Raw value from the request.
     * @return string
     */
    public function sanitizeVideoId($value) {
        return preg_replace('/[^a-f0-9\-]/i', '', sanitize_text_field((string) $value));
    }

    /**
     * Reject empty or non-string video identifiers.
     *
     * @param mixed $value Raw value from the request.
     * @return bool
     */
    public function validateVideoId($value) {
        return is_string($value) && '' !== $value;
    }

    /**
     * Return the current Bunny Stream encoding status for an attachment.
     *
     * @param \WP_REST_Request $request REST request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function getVideoStatus($request) {
        $authorized = $this->authorizeAttachmentPoll($request);
        if (is_wp_error($authorized)) {
            return $authorized;
        }

        $video_id = (string) $request->get_param('video_id');
        $attachment_id = (int) $request->get_param('attachment_id');
        $should_refresh_attachment = false;

        if ($attachment_id > 0) {
            $should_refresh_attachment = BunnyVideoMetadataSync::primeRemoteRefreshAttempt($attachment_id);
        }

        $result = $this->video_handler->getVideoStatus($video_id, $attachment_id);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($should_refresh_attachment && isset($result['remoteResponse']) && is_array($result['remoteResponse'])) {
            BunnyVideoMetadataSync::refreshAttachmentFromRemoteResponse($attachment_id, $result['remoteResponse']);
        }

        return rest_ensure_response(
            [
                'success'        => true,
                'status'         => (int) $result['status'],
                'encodeProgress' => (int) $result['encodeProgress'],
            ]
        );
    }
}
