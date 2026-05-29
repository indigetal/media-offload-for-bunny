<?php

namespace Bunny_Offload\Bunny;

use Bunny_Offload\Integration\BunnyMetadataManager;
use Bunny_Offload\Utils\BunnyLogger;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyCollectionHandler {
    private static $instance = null;
    private $apiClient;

    private function __construct() {
        $this->apiClient = BunnyApiClient::getInstance();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Remote Bunny Stream collection name for a WordPress user.
     *
     * Stable Free extension surface: remote name `user_{userId}` for Bunny.net; pairs with
     * {@see resolveCollectionIdForUser()}.
     *
     * @param int $userId WordPress user ID.
     * @return string Collection name sent to Bunny.net API.
     */
    public function collectionNameForUser(int $userId): string {
        return 'user_' . (string) $userId;
    }

        /**
     * Create a new collection within a library.
     *
     * @param string $collectionName The name of the collection.
     * @param array $additionalData (Optional) Additional data for the collection, like a description.
     * @param int|null $userId (Optional) The user ID for associating the collection in the database.
     * @return array|WP_Error The created collection data or WP_Error on failure.
     */
    public function createCollection($userId, $additionalData = []) {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to create a collection.', 'indigetal-media-offload-for-bunny-net'));
        }

        if (empty($userId)) {
            return new \WP_Error('missing_user_id', __('User ID is required to create a collection.', 'indigetal-media-offload-for-bunny-net'));
        }

        $collectionName = $this->collectionNameForUser((int) $userId);

        // Step 1: Prevent duplicate collection creation using a transient lock
        $lock_key = "indigetal_offload_collection_lock_{$userId}";
        if (get_transient($lock_key)) {
            return new \WP_Error('collection_creation_locked', __('Collection creation is already in progress. Try again later.', 'indigetal-media-offload-for-bunny-net'));
        }

        // Set transient lock to prevent simultaneous requests
        set_transient($lock_key, true, 10); // Lock expires after 10 seconds

        // Step 2: Check if the collection already exists on Bunny.net
        $collections = $this->listCollections();
        if (is_wp_error($collections)) {
            delete_transient($lock_key);
            return $collections;
        }

        foreach ($collections as $collection) {
            if ($collection['name'] === $collectionName) {
                delete_transient($lock_key); // Remove lock since no new collection is needed
                return $collection['guid']; // Return existing collection ID
            }
        }

        // Step 3: Create the collection on Bunny.net with the correct JSON format
        $endpoint = "library/{$library_id}/collections";
        $data = array_merge(['name' => $collectionName], $additionalData);

        $response = $this->apiClient->sendJsonToBunny($endpoint, 'POST', $data);

        // Remove the transient lock after request completes
        delete_transient($lock_key);

        if (is_wp_error($response) || empty($response['guid'])) {
            return new \WP_Error('collection_creation_failed', __('Failed to create collection on Bunny.net.', 'indigetal-media-offload-for-bunny-net'));
        }
        return $response['guid'];
    }

    /**
     * Check if a specific collection exists in the list of collections.
     *
     * @param string $collectionId The ID of the collection to check.
     * @return array|WP_Error The collection details or WP_Error if it doesn't exist.
     */
    public function getCollection($collectionId) {
        $collections = $this->listCollections();

        if (is_wp_error($collections)) {
            return $collections; // Return error if listing collections fails
        }

        foreach ($collections as $collection) {
            if ($collection['guid'] === $collectionId) {
                return $collection;
            }
        }

        return null;
    }

    /**
     * Find a collection by its name.
     *
     * @param string $name The collection name to search for.
     * @return string|\WP_Error|null The collection GUID if found, null if not found after a successful list, or WP_Error when listing fails.
     */
    public function getCollectionByName($name) {
        $collections = $this->listCollections();

        if (is_wp_error($collections)) {
            return $collections;
        }

        foreach ($collections as $collection) {
            if ($collection['name'] === $name) {
                return $collection['guid'];
            }
        }

        return null;
    }

    /**
     * Resolve the Bunny Stream collection GUID for a WordPress user.
     *
     * Stable Free extension surface: method name, parameters, and return semantics are
     * maintained for add-on compatibility unless a release explicitly documents a breaking
     * change. Calls Bunny.net Stream APIs (paginated collection list and optional create).
     *
     * Canonical user meta: {@see BunnyMetadataManager::COLLECTION_ID_META_KEY}
     * (`_indigetal_offload_collection_id`). Remote collection name: `user_{userId}` via
     * {@see collectionNameForUser()}.
     *
     * When meta stores a GUID, validates it against a full paginated
     * {@see listCollections()} result via {@see getCollection()}. Listing `WP_Error` is
     * returned as-is and user meta is not modified. Stale meta is deleted only after a
     * successful list proves the GUID is absent, then {@see createCollection()} reuses an
     * existing remote `user_{userId}` collection or POSTs a new one. `createCollection()`
     * requires a successful list and does not POST when listing fails.
     *
     * @param int $userId WordPress user ID.
     * @return string|\WP_Error Collection GUID on success, or `WP_Error` on failure.
     */
    public function resolveCollectionIdForUser(int $userId) {
        $userId = absint($userId);
        if ($userId < 1) {
            return new \WP_Error('invalid_user', __('A valid user is required to resolve a Bunny Stream collection.', 'indigetal-media-offload-for-bunny-net'));
        }

        $collectionId = trim((string) get_user_meta($userId, BunnyMetadataManager::COLLECTION_ID_META_KEY, true));
        if ('' !== $collectionId) {
            $collection = $this->getCollection($collectionId);

            if (is_wp_error($collection)) {
                return $collection;
            }

            if (null !== $collection) {
                return (string) $collection['guid'];
            }

            delete_user_meta($userId, BunnyMetadataManager::COLLECTION_ID_META_KEY);
        }

        $collectionId = $this->createCollection($userId);
        if (is_wp_error($collectionId)) {
            return $collectionId;
        }

        update_user_meta($userId, BunnyMetadataManager::COLLECTION_ID_META_KEY, $collectionId);

        return (string) $collectionId;
    }

    /**
     * Delete a collection by its ID.
     *
     * @param string $collectionId The ID of the collection to delete.
     * @return bool|WP_Error True on success, or WP_Error on failure.
     */
    public function deleteCollection($collectionId, $userId = null) {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            BunnyLogger::log('Library ID is missing or not set.', 'warning');
            return new \WP_Error('missing_library_id', __('Library ID is required to delete a collection.', 'indigetal-media-offload-for-bunny-net'));
        }

        if (empty($collectionId)) {
            return new \WP_Error('missing_collection_id', __('Collection ID is required.', 'indigetal-media-offload-for-bunny-net'));
        }

        $endpoint = "library/{$library_id}/collections/{$collectionId}";
        $response = $this->apiClient->sendJsonToBunny($endpoint, 'DELETE');

        if (is_wp_error($response)) {
            return $response;
        }

        if ($userId) {
            delete_user_meta($userId, \Bunny_Offload\Integration\BunnyMetadataManager::COLLECTION_ID_META_KEY);
        }

        return true;
    }

    /**
     * Retrieve a list of all collections for a given video library.
     *
     * @return array|WP_Error The collection list or WP_Error on failure.
     */
    public function listCollections() {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to fetch collections.', 'indigetal-media-offload-for-bunny-net'));
        }

        $collections = [];
        $page = 1;
        $itemsPerPage = 100;

        do {
            $endpoint = "library/{$library_id}/collections?page={$page}&itemsPerPage={$itemsPerPage}";
            $response = $this->apiClient->sendJsonToBunny($endpoint, 'GET');

            if (is_wp_error($response)) {
                return $response;
            }

            if (!isset($response['items']) || !is_array($response['items'])) {
                return new \WP_Error('invalid_collection_list', __('Invalid response from Bunny.net when listing collections.', 'indigetal-media-offload-for-bunny-net'));
            }

            $collections = array_merge($collections, $response['items']);
            $page++;
        } while (count($response['items']) === $itemsPerPage);

        return $collections;
    }

    /**
     * Update the details of an existing collection.
     *
     * @param string $collectionId The ID of the collection to update.
     * @param array $data The updated data for the collection (e.g., name, metadata).
     * @return array|WP_Error The updated collection details or WP_Error on failure.
     */
    public function updateCollection($collectionId, $data) {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            BunnyLogger::log('Library ID is missing or not set.', 'warning');
            return new \WP_Error('missing_library_id', __('Library ID is required to update a collection.', 'indigetal-media-offload-for-bunny-net'));
        }

        if (empty($collectionId)) {
            return new \WP_Error('missing_collection_id', __('Collection ID is required.', 'indigetal-media-offload-for-bunny-net'));
        }

        if (empty($data) || !is_array($data)) {
            return new \WP_Error('missing_update_data', __('Update data is required and must be an array.', 'indigetal-media-offload-for-bunny-net'));
        }

        $endpoint = "library/{$library_id}/collections/{$collectionId}";

        // Remove empty or unchanged values before sending the update
        $filteredData = array_filter($data, function($value) {
            return !is_null($value) && $value !== '';
        });

        if (empty($filteredData)) {
            return new \WP_Error('no_update_data', __('No changes detected for the collection update.', 'indigetal-media-offload-for-bunny-net'));
        }

        return $this->apiClient->sendJsonToBunny($endpoint, 'PUT', $filteredData);
    }
}
