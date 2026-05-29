<?php
namespace Bunny_Offload\Integration;

use Bunny_Offload\Bunny\BunnyApiClient;
use Bunny_Offload\Bunny\BunnyCollectionHandler;
use Bunny_Offload\Utils\BunnyLogger;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyUserIntegration {
    private $bunnyApiClient;
    private $collectionHandler;

    public function __construct() {
        // Initialize BunnyApi instance
        $this->bunnyApiClient = BunnyApiClient::getInstance();
        $this->collectionHandler = BunnyCollectionHandler::getInstance();

        // Hook into user actions
        add_action('delete_user', [$this, 'handleUserDeletion']);
    }

    /**
     * Validate and process WordPress request for video uploads.
     *
     * @param array $request The $_POST and $_FILES data for the upload.
     *
     * @return array|\WP_Error Processed request data or error.
     */
    public function validateUploadRequest($request) {
        // Verify user permissions.
        if (!current_user_can('upload_files')) {
            return new \WP_Error('unauthorized_access', __('Unauthorized access.', 'indigetal-media-offload-for-bunny-net'));
        }

        // Check nonce for security
        if (!isset($request['_wpnonce']) || !wp_verify_nonce($request['_wpnonce'], 'bunny_upload_nonce')) {
            return new \WP_Error('invalid_nonce', __('Invalid nonce.', 'indigetal-media-offload-for-bunny-net'));
        }

        // Check for uploaded file and post ID.
        if (empty($request['file']) || empty($request['post_id'])) {
            return new \WP_Error('missing_parameters', __('Missing file or post ID.', 'indigetal-media-offload-for-bunny-net'));
        }

        // Sanitize post ID.
        $postId = absint( wp_unslash( (string) ( $request['post_id'] ?? 0 ) ) );
        return [
            'file' => $request['file'],
            'post_id' => $postId,
        ];
    }
    
    /**
     * Handle user deletion.
     * Delete the user's Bunny.net collection if it exists.
     *
     * @param int $userId The ID of the user being deleted.
     */
    public function handleUserDeletion($userId) {
        $collectionId = get_user_meta($userId, BunnyMetadataManager::COLLECTION_ID_META_KEY, true);

        if (empty($collectionId)) {
            $collectionId = $this->collectionHandler->getCollectionByName(
                $this->collectionHandler->collectionNameForUser((int) $userId)
            );
        }

        if (is_wp_error($collectionId)) {
            BunnyLogger::log(
                "Skipping Bunny Stream collection deletion for user {$userId}: " . $collectionId->get_error_message(),
                'error'
            );
            return;
        }

        if (empty($collectionId)) {
            return;
        }

        $response = $this->collectionHandler->deleteCollection($collectionId, $userId);
        if (is_wp_error($response)) {
            BunnyLogger::log("Failed to delete collection for user {$userId}: " . $response->get_error_message(), 'error');
        } else {
            BunnyLogger::log("Collection {$collectionId} for user {$userId} deleted successfully.", 'info');
        }
    }
}
