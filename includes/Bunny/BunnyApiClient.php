<?php

namespace Bunny_Offload\Bunny;

use Bunny_Offload\Admin\BunnySettings;
use Bunny_Offload\Settings\BunnyConfigurationStore;
use Bunny_Offload\Utils\BunnyLogger;
use Bunny_Offload\Utils\Constants;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyApiClient {
    private static $instance = null;
    public $video_base_url = 'https://video.bunnycdn.com/';
    private const RETRY_AFTER_TRANSIENT_KEY = 'indigetal_offload_api_retry_after';
    private $access_key;
    private $library_id;

    private function __construct() {
        $this->access_key = BunnyConfigurationStore::getApiKey();
        $this->library_id = BunnyConfigurationStore::getStreamLibraryId();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self(); // Correct way to call private constructor
        }
        return self::$instance;
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialization
    public function __wakeup() {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    public function getAccessKey() {
        return $this->access_key;
    }

    /**
     * Generic method to send JSON requests to Bunny.net with retry logic.
     */
    public function sendJsonToBunny($endpoint, $method, $data = []) {
        $url = $this->video_base_url . ltrim($endpoint, '/');

        // Validate HTTP method
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            return new \WP_Error('invalid_http_method', __('Invalid HTTP method provided.', 'indigetal-media-offload-for-bunny-net'));
        }

        $json_body = '';

        // Prepare headers
        $headers = [
            'AccessKey' => $this->access_key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // Build request arguments
        $args = [
            'method'  => $method,
        ];

        // Add body if not a GET request
        if ($method !== 'GET' && !empty($data)) {
            $json_body = wp_json_encode($data);

            if (false === $json_body) {
                return new \WP_Error('indigetal_offload_api_json_encode_failed', __('Failed to encode the Bunny.net API request body.', 'indigetal-media-offload-for-bunny-net'));
            }

            $args['body'] = $json_body;
            $headers['Content-Length'] = strlen($json_body);
        }

        $args['headers'] = $headers;

        // Log API request details before making the request
        BunnyLogger::log("Sending API request to Bunny.net. Endpoint: {$endpoint}, Method: {$method}, Library ID: {$this->library_id}", 'debug');
        BunnyLogger::log("Headers: " . wp_json_encode($this->redactCredentialHeaders($headers)), 'debug');
        if (!empty($data)) {
            BunnyLogger::log("Request Body: " . json_encode($data), 'debug');
        }

        $response = $this->retryApiCall(
            function() use ($url, $args) {
                return wp_remote_request($url, $args);
            },
            3,
            $endpoint,
            'Bunny.net API'
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $response_body = wp_remote_retrieve_body($response);
        BunnyLogger::log("sendJsonToBunny received a response for {$endpoint}.", 'debug');

        return $this->decodeJsonResponse($response_body, $endpoint, 'indigetal_offload_api_invalid_json', 'Bunny.net API');
    }

    /**
     * Retry failed API calls using raw wp_remote_request() responses.
     */
    protected function retryApiCall($callback, $maxAttempts = 3, $endpoint = '', $apiLabel = 'Bunny.net API', $httpErrorCode = 'indigetal_offload_api_http_error') {
        $attempt = 0;
        $last_error = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            BunnyLogger::log("API Attempt #{$attempt} for {$endpoint}", 'info');
            $this->maybeWaitForRetryAfter();

            $response = $callback();

            if (is_wp_error($response)) {
                $last_error = $this->prepareTransportError($response, $endpoint, $apiLabel);

                if ($attempt >= $maxAttempts) {
                    return $last_error;
                }

                $delay_seconds = 2 ** ($attempt - 1);
                BunnyLogger::log("Transport error calling {$endpoint}: {$response->get_error_message()}. Retrying in {$delay_seconds} seconds.", 'warning');
                sleep($delay_seconds);
                continue;
            }

            $response_code = (int) wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response) ?: '';

            if ($response_code >= 200 && $response_code < 300) {
                BunnyLogger::log("API Response (Success, HTTP {$response_code}) for {$endpoint}.", 'debug');
                return $response;
            }

            $last_error = $this->buildHttpError($httpErrorCode, $response_code, $response_body, $endpoint, $apiLabel);

            if ($response_code === 429) {
                if ($attempt >= $maxAttempts) {
                    return $last_error;
                }

                $retry_after_seconds = $this->getRetryDelaySeconds($response, $attempt);
                set_transient(self::RETRY_AFTER_TRANSIENT_KEY, time() + $retry_after_seconds, $retry_after_seconds);
                BunnyLogger::log("Rate limit hit (429). Respecting Retry-After: {$retry_after_seconds} seconds.", 'warning');
                sleep($retry_after_seconds);
                continue;
            }

            if ($response_code >= 500 && $response_code < 600) {
                if ($attempt >= $maxAttempts) {
                    return $last_error;
                }

                $delay_seconds = 2 ** ($attempt - 1);
                BunnyLogger::log("Retrying {$endpoint} after 5xx response (HTTP {$response_code}) in {$delay_seconds} seconds.", 'warning');
                sleep($delay_seconds);
                continue;
            }

            BunnyLogger::log("Non-retryable {$apiLabel} failure for {$endpoint} (HTTP {$response_code}).", 'error');
            return $last_error;
        }

        return $last_error ?: new \WP_Error('api_failure', __('Bunny.net API failed after multiple attempts.', 'indigetal-media-offload-for-bunny-net'));
    }

    public function executeWithRetry($callback, $maxAttempts = 3) {
        return $this->retryApiCall($callback, $maxAttempts, 'raw-request', 'Bunny.net API', 'indigetal_offload_api_http_error');
    }

    /**
     * Redact credential-bearing headers before debug logging.
     *
     * @param array<string, mixed> $headers Request headers.
     * @return array<string, mixed>
     */
    private function redactCredentialHeaders(array $headers) {
        foreach ($headers as $header_name => $header_value) {
            $normalized_name = strtolower((string) $header_name);

            if (in_array($normalized_name, ['accesskey', 'authorization', 'x-api-key'], true)) {
                $headers[$header_name] = '[redacted]';
            }
        }

        return $headers;
    }

    /**
     * Redact likely credential fields from response bodies before debug logging.
     *
     * @param string $response_body Raw response body.
     * @return string
     */
    private function redactResponseBodyForLogging($response_body) {
        $response_body = (string) $response_body;

        if ('' === trim($response_body)) {
            return 'No response body';
        }

        $decoded = json_decode($response_body, true);

        if (JSON_ERROR_NONE !== json_last_error() || !is_array($decoded)) {
            return '[redacted non-JSON response body]';
        }

        $encoded = wp_json_encode($this->redactCredentialFields($decoded));

        return false === $encoded ? '[redacted response body]' : $encoded;
    }

    /**
     * Recursively redact credential-like fields from decoded response data.
     *
     * @param array<string|int, mixed> $data Decoded response data.
     * @return array<string|int, mixed>
     */
    private function redactCredentialFields(array $data) {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redactCredentialFields($value);
                continue;
            }

            $normalized_key = strtolower((string) $key);

            if (false !== strpos($normalized_key, 'key') || false !== strpos($normalized_key, 'password') || false !== strpos($normalized_key, 'token') || false !== strpos($normalized_key, 'secret')) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }

    public function getLibraryId() {
        return $this->library_id;
    }

    /**
     * Read the full metadata/details payload for an existing Bunny Stream video.
     *
     * @param int|string $libraryId Bunny Stream library ID.
     * @param string     $videoId   Bunny Stream video GUID.
     * @return array|\WP_Error
     */
    public function getVideoDetails($libraryId, $videoId) {
        $endpoint = $this->buildVideoDetailsEndpoint($libraryId, $videoId);
        if (is_wp_error($endpoint)) {
            return $endpoint;
        }

        return $this->sendJsonToBunny($endpoint, 'GET');
    }

    /**
     * Update title and/or metaTags for an existing Bunny Stream video.
     *
     * @param int|string          $libraryId Bunny Stream library ID.
     * @param string              $videoId   Bunny Stream video GUID.
     * @param array<string, mixed> $data     JSON body for the Bunny update route.
     * @return array|\WP_Error
     */
    public function updateVideoDetails($libraryId, $videoId, array $data) {
        $endpoint = $this->buildVideoDetailsEndpoint($libraryId, $videoId);
        if (is_wp_error($endpoint)) {
            return $endpoint;
        }

        return $this->sendJsonToBunny($endpoint, 'POST', $data);
    }

    /**
     * Decode a successful Bunny API JSON response after HTTP validation.
     *
     * @param string $response_body Raw response body.
     * @param string $endpoint      API endpoint used for error context.
     * @param string $error_code    WP_Error code for JSON failures.
     * @param string $api_label     Human-readable API label for errors/logging.
     * @return array|\WP_Error
     */
    private function decodeJsonResponse($response_body, $endpoint, $error_code, $api_label) {
        $response_body = (string) $response_body;

        if ('' === trim($response_body)) {
            return [];
        }

        $decoded = json_decode($response_body, true);

        if (JSON_ERROR_NONE !== json_last_error() || !is_array($decoded)) {
            BunnyLogger::log("{$api_label} returned an invalid JSON body for {$endpoint}.", 'error');

            return new \WP_Error(
                $error_code,
                sprintf(
                    /* translators: 1: API label, 2: endpoint path, 3: JSON error message */
                    __('%1$s returned an invalid JSON response for %2$s: %3$s', 'indigetal-media-offload-for-bunny-net'),
                    $api_label,
                    $endpoint,
                    json_last_error_msg()
                ),
                [
                    'endpoint' => $endpoint,
                    'body'     => $response_body,
                ]
            );
        }

        return $decoded;
    }

    /**
     * Wait for any stored Retry-After window before issuing the next request.
     *
     * @return void
     */
    private function maybeWaitForRetryAfter() {
        $retry_after_time = (int) get_transient(self::RETRY_AFTER_TRANSIENT_KEY);

        if ($retry_after_time <= time()) {
            delete_transient(self::RETRY_AFTER_TRANSIENT_KEY);
            return;
        }

        $sleep_seconds = $retry_after_time - time();
        BunnyLogger::log("Waiting {$sleep_seconds} seconds for stored Retry-After window before the next Bunny API attempt.", 'warning');
        sleep($sleep_seconds);
    }

    /**
     * Determine the retry delay for a retryable response.
     *
     * @param array $response HTTP response array.
     * @param int   $attempt  Current attempt number (1-indexed).
     * @return int
     */
    private function getRetryDelaySeconds($response, $attempt) {
        $retry_after = wp_remote_retrieve_header($response, 'Retry-After');

        if (is_numeric($retry_after)) {
            return max(1, (int) $retry_after);
        }

        if (is_string($retry_after) && '' !== trim($retry_after)) {
            $retry_after_timestamp = strtotime($retry_after);

            if (false !== $retry_after_timestamp) {
                return max(1, $retry_after_timestamp - time());
            }
        }

        return 2 ** ($attempt - 1);
    }

    /**
     * Attach request context to transport-level WP_Error objects.
     *
     * @param \WP_Error $error    Transport error from wp_remote_request().
     * @param string    $endpoint API endpoint.
     * @param string    $apiLabel API label for debugging context.
     * @return \WP_Error
     */
    private function prepareTransportError($error, $endpoint, $apiLabel) {
        $error->add_data(
            [
                'endpoint' => $endpoint,
                'api'      => $apiLabel,
            ],
            $error->get_error_code()
        );

        return $error;
    }

    /**
     * Build a WP_Error containing HTTP response details for failed Bunny requests.
     *
     * @param string $error_code    WP_Error code.
     * @param int    $response_code HTTP status code.
     * @param string $response_body Raw response body.
     * @param string $endpoint      API endpoint.
     * @param string $api_label     API label for the error message.
     * @return \WP_Error
     */
    private function buildHttpError($error_code, $response_code, $response_body, $endpoint, $api_label) {
        $response_body = '' !== trim($response_body) ? $response_body : 'No response body';

        BunnyLogger::log("{$api_label} request failed: {$endpoint} (HTTP {$response_code})", 'error');
        BunnyLogger::log("Response Body: " . $this->redactResponseBodyForLogging($response_body), 'debug');

        return new \WP_Error(
            $error_code,
            sprintf(
                /* translators: 1: API label, 2: endpoint path, 3: HTTP status code, 4: response body */
                __('%1$s error for %2$s (HTTP %3$d): %4$s', 'indigetal-media-offload-for-bunny-net'),
                $api_label,
                $endpoint,
                $response_code,
                $response_body
            ),
            [
                'status'        => $response_code,
                'response_code' => $response_code,
                'body'          => $response_body,
                'endpoint'      => $endpoint,
            ]
        );
    }

    /**
     * Build the existing-video Bunny route path after validating required IDs.
     *
     * @param int|string $libraryId Bunny Stream library ID.
     * @param string     $videoId   Bunny Stream video GUID.
     * @return string|\WP_Error
     */
    private function buildVideoDetailsEndpoint($libraryId, $videoId) {
        $libraryId = trim((string) $libraryId);
        $videoId   = trim((string) $videoId);

        if ('' === $libraryId) {
            return new \WP_Error('missing_library_id', __('Library ID is required for Bunny video metadata requests.', 'indigetal-media-offload-for-bunny-net'));
        }

        if ('' === $videoId) {
            return new \WP_Error('missing_video_id', __('Video ID is required for Bunny video metadata requests.', 'indigetal-media-offload-for-bunny-net'));
        }

        return sprintf(
            'library/%s/videos/%s',
            rawurlencode($libraryId),
            rawurlencode($videoId)
        );
    }
}
