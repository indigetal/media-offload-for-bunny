<?php
/**
 * REST controller for the Bunny Stream encode-status polling endpoint.
 *
 * @package Bunny_Offload\REST
 */

namespace Bunny_Offload\REST;

use Bunny_Offload\Bunny\BunnyVideoHandler;
use Bunny_Offload\Integration\BunnyVideoMetadataSync;

if (!defined('ABSPATH')) {
    exit;
}

class BunnyStreamStatusController {

    const REST_NAMESPACE = 'bunny-offload/v1';
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
     * Restrict status polling to users who can upload media.
     *
     * @return bool
     */
    public function permissionsCheck() {
        return current_user_can('upload_files');
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
