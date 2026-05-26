<?php

namespace Bunny_Offload\Bunny;

use Bunny_Offload\Settings\BunnyConfigurationStore;
use Bunny_Offload\Utils\BunnyLogger;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Bunny Storage HTTP client for Phase 2 file upload, download, and delete operations.
 */
class BunnyStorageClient {
    private static $instance = null;

    const RETRY_AFTER_TRANSIENT_KEY = 'indigetal_offload_storage_retry_after';
    const DEFAULT_TIMEOUT = 20;
    const UPLOAD_TIMEOUT = 120;
    const DOWNLOAD_TIMEOUT = 120;
    const MAX_ATTEMPTS = 3;

    /**
     * Published Bunny Storage base URLs by primary region.
     *
     * @var array<string, string>
     */
    private static $region_base_urls = [
        'de'  => 'https://storage.bunnycdn.com/',
        'uk'  => 'https://uk.storage.bunnycdn.com/',
        'se'  => 'https://se.storage.bunnycdn.com/',
        'ny'  => 'https://ny.storage.bunnycdn.com/',
        'la'  => 'https://la.storage.bunnycdn.com/',
        'sg'  => 'https://sg.storage.bunnycdn.com/',
        'syd' => 'https://syd.storage.bunnycdn.com/',
        'br'  => 'https://br.storage.bunnycdn.com/',
        'jh'  => 'https://jh.storage.bunnycdn.com/',
    ];

    /**
     * Configured Bunny Storage zone name.
     *
     * @var string
     */
    private $storage_zone_name;

    /**
     * Configured Bunny Storage primary region.
     *
     * @var string
     */
    private $storage_region;

    /**
     * Configured Bunny Storage zone password.
     *
     * @var string
     */
    private $storage_password;

    /**
     * Constructor.
     */
    private function __construct() {
        $this->storage_zone_name = BunnyConfigurationStore::getStorageZoneName();
        $this->storage_region = BunnyConfigurationStore::getStorageRegion();
        $this->storage_password = BunnyConfigurationStore::getStoragePassword();
    }

    /**
     * Return the storage client singleton.
     *
     * @return self
     */
    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Prevent unserialization.
     *
     * @throws \Exception Always thrown.
     */
    public function __wakeup() {
        throw new \Exception('Cannot unserialize a singleton.');
    }

    /**
     * Return the configured Bunny Storage base URL.
     *
     * @return string
     */
    public function getBaseUrl() {
        return self::getBaseUrlForRegion($this->storage_region);
    }

    /**
     * Return the published Bunny Storage base URL for a region code.
     *
     * @param string $region Region code.
     * @return string
     */
    public static function getBaseUrlForRegion($region) {
        $region = sanitize_key((string) $region);

        return self::$region_base_urls[$region] ?? '';
    }

    /**
     * Return the configured default headers for Bunny Storage requests.
     *
     * @return array<string, string>
     */
    public function getDefaultHeaders() {
        return [
            'AccessKey' => $this->storage_password,
        ];
    }

    /**
     * Build upload request args for a local file.
     *
     * @param string $local_path Absolute local file path.
     * @return array|\WP_Error
     */
    public function buildUploadRequestArgs($local_path) {
        if (!is_string($local_path) || '' === $local_path || !file_exists($local_path) || !is_readable($local_path)) {
            return new \WP_Error(
                'indigetal_offload_storage_missing_file',
                __('The local file could not be read for Bunny Storage upload.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'local_path' => $local_path,
                ]
            );
        }

        $body = file_get_contents($local_path);

        if (false === $body) {
            return new \WP_Error(
                'indigetal_offload_storage_read_failed',
                __('Failed to read the local file for Bunny Storage upload.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'local_path' => $local_path,
                ]
            );
        }

        $headers = $this->getDefaultHeaders();
        $checksum = $this->calculateFileChecksum($local_path);

        if ('' !== $checksum) {
            $headers['Checksum'] = $checksum;
        }

        $filetype = wp_check_filetype(basename($local_path));

        if (!empty($filetype['type'])) {
            $headers['Content-Type'] = $filetype['type'];
        }

        return [
            'method'      => 'PUT',
            'headers'     => $headers,
            'body'        => $body,
            'timeout'     => self::UPLOAD_TIMEOUT,
            'redirection' => 0,
        ];
    }

    /**
     * Build delete request args for a Bunny Storage file delete.
     *
     * @return array<string, mixed>
     */
    public function buildDeleteRequestArgs() {
        return [
            'method'      => 'DELETE',
            'headers'     => $this->getDefaultHeaders(),
            'timeout'     => self::DEFAULT_TIMEOUT,
            'redirection' => 0,
        ];
    }

    /**
     * Build download request args for streaming a Bunny Storage file to disk.
     *
     * @param string $temporary_path Absolute temporary file path.
     * @return array<string, mixed>
     */
    public function buildDownloadRequestArgs($temporary_path) {
        return [
            'method'      => 'GET',
            'headers'     => $this->getDefaultHeaders(),
            'timeout'     => self::DOWNLOAD_TIMEOUT,
            'redirection' => 0,
            'stream'      => true,
            'filename'    => wp_normalize_path((string) $temporary_path),
        ];
    }

    /**
     * Calculate the Bunny Storage checksum header value for a local file.
     *
     * @param string $local_path Absolute local file path.
     * @return string
     */
    public function calculateFileChecksum($local_path) {
        if (!is_string($local_path) || '' === $local_path || !file_exists($local_path) || !is_readable($local_path)) {
            return '';
        }

        $hash = hash_file('sha256', $local_path);

        return false === $hash ? '' : strtoupper($hash);
    }

    /**
     * Upload a local file to Bunny Storage.
     *
     * @param string $local_path  Absolute local file path.
     * @param string $remote_path Remote object path relative to the storage zone root.
     * @return array|\WP_Error
     */
    public function uploadFile($local_path, $remote_path) {
        $configuration = $this->validateConfiguration();

        if (is_wp_error($configuration)) {
            return $configuration;
        }

        $request_url = $this->buildRequestUrl($remote_path);

        if (is_wp_error($request_url)) {
            return $request_url;
        }

        $request_args = $this->buildUploadRequestArgs($local_path);

        if (is_wp_error($request_args)) {
            return $request_args;
        }

        BunnyLogger::log("Uploading file to Bunny Storage: {$remote_path}", 'info');

        return $this->requestWithRetry($request_url, $request_args, $remote_path, 'upload', 201);
    }

    /**
     * Delete a remote file from Bunny Storage.
     *
     * @param string $remote_path Remote object path relative to the storage zone root.
     * @return array|\WP_Error
     */
    public function deleteFile($remote_path) {
        $configuration = $this->validateConfiguration();

        if (is_wp_error($configuration)) {
            return $configuration;
        }

        $request_url = $this->buildRequestUrl($remote_path);

        if (is_wp_error($request_url)) {
            return $request_url;
        }

        BunnyLogger::log("Deleting file from Bunny Storage: {$remote_path}", 'info');

        return $this->requestWithRetry($request_url, $this->buildDeleteRequestArgs(), $remote_path, 'delete', 200);
    }

    /**
     * Download one Bunny Storage object to a local destination path.
     *
     * @param string $remote_path      Remote object path relative to the storage zone root.
     * @param string $destination_path Absolute local destination path.
     * @return array|\WP_Error
     */
    public function downloadFile($remote_path, $destination_path) {
        $configuration = $this->validateConfiguration();

        if (is_wp_error($configuration)) {
            return $configuration;
        }

        $request_url = $this->buildRequestUrl($remote_path);

        if (is_wp_error($request_url)) {
            return $request_url;
        }

        $prepared_destination = $this->prepareDownloadDestination($destination_path);

        if (is_wp_error($prepared_destination)) {
            return $prepared_destination;
        }

        $temporary_path = $this->createDownloadTemporaryPath($prepared_destination);

        if (is_wp_error($temporary_path)) {
            return $temporary_path;
        }

        BunnyLogger::log("Downloading file from Bunny Storage: {$remote_path}", 'info');

        $response = $this->requestWithRetry(
            $request_url,
            $this->buildDownloadRequestArgs($temporary_path),
            $remote_path,
            'download',
            200
        );

        if (is_wp_error($response)) {
            $this->deleteTemporaryDownloadFile($temporary_path);
            return $response;
        }

        $finalize_result = $this->finalizeDownloadedFile($temporary_path, $prepared_destination, $remote_path);

        if (is_wp_error($finalize_result)) {
            return $finalize_result;
        }

        return $response;
    }

    /**
     * Build the full Bunny Storage request URL for a remote path.
     *
     * @param string $remote_path Remote object path relative to the storage zone root.
     * @return string|\WP_Error
     */
    public function buildRequestUrl($remote_path) {
        $base_url = $this->getBaseUrl();
        $normalized_path = $this->normalizePath($remote_path);

        if ('' === $base_url) {
            return new \WP_Error(
                'indigetal_offload_storage_invalid_region',
                __('The configured Bunny Storage region is invalid.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'region' => $this->storage_region,
                ]
            );
        }

        if ('' === $normalized_path) {
            return new \WP_Error(
                'indigetal_offload_storage_invalid_path',
                __('The requested Bunny Storage path is invalid.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'remote_path' => $remote_path,
                ]
            );
        }

        return trailingslashit($base_url) . ltrim($normalized_path, '/');
    }

    /**
     * Retry a Bunny Storage request for transport errors, 429, and 5xx responses.
     *
     * @param string $request_url   Full request URL.
     * @param array  $request_args  Request args for wp_remote_request().
     * @param string $remote_path   Remote path for context/logging.
     * @param string $operation     Human-readable operation label.
     * @param int    $success_code  Expected success HTTP status.
     * @return array|\WP_Error
     */
    private function requestWithRetry($request_url, array $request_args, $remote_path, $operation, $success_code) {
        $attempt = 0;
        $last_error = null;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;
            $this->maybeWaitForRetryAfter();

            $response = wp_remote_request($request_url, $request_args);

            if (is_wp_error($response)) {
                $last_error = $this->prepareTransportError($response, $remote_path, $operation);

                if ($attempt >= self::MAX_ATTEMPTS) {
                    return $last_error;
                }

                $delay_seconds = 2 ** ($attempt - 1);
                BunnyLogger::log("Transport error during Bunny Storage {$operation} for {$remote_path}. Retrying in {$delay_seconds} seconds.", 'warning');
                sleep($delay_seconds);
                continue;
            }

            $response_code = (int) wp_remote_retrieve_response_code($response);

            if ($response_code === $success_code) {
                BunnyLogger::log("Bunny Storage {$operation} succeeded for {$remote_path} (HTTP {$response_code}).", 'debug');
                return $response;
            }

            $response_body = (string) wp_remote_retrieve_body($response);
            $last_error = $this->buildHttpError($operation, $response_code, $response_body, $remote_path);

            if (429 === $response_code) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    return $last_error;
                }

                $retry_after_seconds = $this->getRetryDelaySeconds($response, $attempt);
                set_transient(self::RETRY_AFTER_TRANSIENT_KEY, time() + $retry_after_seconds, $retry_after_seconds);
                BunnyLogger::log("Bunny Storage {$operation} hit a 429 for {$remote_path}. Retrying in {$retry_after_seconds} seconds.", 'warning');
                sleep($retry_after_seconds);
                continue;
            }

            if ($response_code >= 500 && $response_code < 600) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    return $last_error;
                }

                $delay_seconds = 2 ** ($attempt - 1);
                BunnyLogger::log("Bunny Storage {$operation} received HTTP {$response_code} for {$remote_path}. Retrying in {$delay_seconds} seconds.", 'warning');
                sleep($delay_seconds);
                continue;
            }

            return $last_error;
        }

        return $last_error ?: new \WP_Error(
            'indigetal_offload_storage_request_failed',
            __('Bunny Storage request failed after multiple attempts.', 'indigetal-media-offload-for-bunny-net'),
            [
                'remote_path' => $remote_path,
                'operation'   => $operation,
            ]
        );
    }

    /**
     * Validate the configured Bunny Storage credentials and region.
     *
     * @return true|\WP_Error
     */
    private function validateConfiguration() {
        if ('' === $this->storage_zone_name || '' === $this->storage_password || '' === $this->storage_region) {
            return new \WP_Error(
                'indigetal_offload_storage_not_configured',
                __('Bunny Storage is not fully configured.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'storage_zone_name' => $this->storage_zone_name,
                    'storage_region'    => $this->storage_region,
                ]
            );
        }

        if ('' === $this->getBaseUrl()) {
            return new \WP_Error(
                'indigetal_offload_storage_invalid_region',
                __('The configured Bunny Storage region is invalid.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'storage_region' => $this->storage_region,
                ]
            );
        }

        return true;
    }

    /**
     * Validate and prepare a local download destination path.
     *
     * @param string $destination_path Absolute local destination path.
     * @return string|\WP_Error
     */
    private function prepareDownloadDestination($destination_path) {
        $destination_path = wp_normalize_path((string) $destination_path);

        if ('' === $destination_path) {
            return new \WP_Error(
                'indigetal_offload_storage_download_invalid_destination',
                __('A valid local destination path is required for Bunny Storage download.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                ]
            );
        }

        $allowed_path = $this->assertDownloadDestinationPathAllowed($destination_path);

        if (is_wp_error($allowed_path)) {
            return $allowed_path;
        }

        if (file_exists($destination_path) && is_dir($destination_path)) {
            return new \WP_Error(
                'indigetal_offload_storage_download_destination_is_directory',
                __('The Bunny Storage download destination is a directory, not a file path.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                ]
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local download path check; not admin FTP filesystem.
        if (file_exists($destination_path) && !is_writable($destination_path)) {
            return new \WP_Error(
                'indigetal_offload_storage_download_destination_not_writable',
                __('The existing local destination file is not writable for Bunny Storage download.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                ]
            );
        }

        $destination_dir = dirname($destination_path);

        if ('' === $destination_dir || '.' === $destination_dir) {
            return new \WP_Error(
                'indigetal_offload_storage_download_invalid_destination_directory',
                __('A valid local destination directory is required for Bunny Storage download.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                ]
            );
        }

        if (!wp_mkdir_p($destination_dir)) {
            return new \WP_Error(
                'indigetal_offload_storage_download_directory_create_failed',
                __('The local destination directory could not be created for Bunny Storage download.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                    'directory'        => $destination_dir,
                ]
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local download path check; not admin FTP filesystem.
        if (!is_writable($destination_dir)) {
            return new \WP_Error(
                'indigetal_offload_storage_download_directory_not_writable',
                __('The local destination directory is not writable for Bunny Storage download.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                    'directory'        => $destination_dir,
                ]
            );
        }

        return $destination_path;
    }

    /**
     * Ensure a download destination stays within uploads and outside plugin directories.
     *
     * @param string $destination_path Normalized absolute destination path.
     * @return true|\WP_Error
     */
    private function assertDownloadDestinationPathAllowed($destination_path) {
        if (wp_is_stream($destination_path)) {
            return new \WP_Error(
                'indigetal_offload_storage_download_path_not_allowed',
                __('Bunny Storage downloads must be saved under the WordPress uploads directory.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                ]
            );
        }

        $path_segments = explode('/', trim($destination_path, '/'));

        if (in_array('..', $path_segments, true)) {
            return new \WP_Error(
                'indigetal_offload_storage_download_path_not_allowed',
                __('Bunny Storage downloads must be saved under the WordPress uploads directory.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                ]
            );
        }

        $destination_dir = dirname($destination_path);
        $plugins_dir = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));

        if (
            str_starts_with($destination_path, $plugins_dir)
            || ('' !== $destination_dir && '.' !== $destination_dir && str_starts_with($destination_dir, $plugins_dir))
        ) {
            return new \WP_Error(
                'indigetal_offload_storage_download_path_not_allowed',
                __('Bunny Storage downloads cannot be saved inside the plugins directory.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                ]
            );
        }

        $upload_dir = wp_upload_dir();

        if (!empty($upload_dir['error'])) {
            return new \WP_Error(
                'indigetal_offload_storage_download_path_not_allowed',
                __('Bunny Storage downloads require a valid WordPress uploads directory.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                    'upload_dir_error' => $upload_dir['error'],
                ]
            );
        }

        $uploads_basedir = trailingslashit(wp_normalize_path((string) $upload_dir['basedir']));

        if (!str_starts_with($destination_path, $uploads_basedir)) {
            return new \WP_Error(
                'indigetal_offload_storage_download_path_not_allowed',
                __('Bunny Storage downloads must be saved under the WordPress uploads directory.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                    'uploads_basedir'  => $uploads_basedir,
                ]
            );
        }

        $resolved_uploads = realpath($uploads_basedir);

        if (false === $resolved_uploads) {
            return true;
        }

        $resolved_uploads = trailingslashit(wp_normalize_path($resolved_uploads));
        $resolved_boundary = $this->resolveDownloadDestinationBoundary($destination_path, $uploads_basedir);

        if (
            false === $resolved_boundary
            || !str_starts_with(trailingslashit(wp_normalize_path($resolved_boundary)), $resolved_uploads)
        ) {
            return new \WP_Error(
                'indigetal_offload_storage_download_path_not_allowed',
                __('Bunny Storage downloads must be saved under the WordPress uploads directory.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                    'uploads_basedir'  => $uploads_basedir,
                ]
            );
        }

        return true;
    }

    /**
     * Resolve the nearest existing filesystem path for download boundary checks.
     *
     * @param string $destination_path Normalized destination file path.
     * @param string $uploads_basedir  Normalized uploads basedir with trailing slash.
     * @return string|false
     */
    private function resolveDownloadDestinationBoundary($destination_path, $uploads_basedir) {
        $candidate = $destination_path;

        if (file_exists($candidate)) {
            return realpath($candidate);
        }

        $candidate = dirname($destination_path);
        $uploads_basedir = trailingslashit(wp_normalize_path($uploads_basedir));

        while ('' !== $candidate && '.' !== $candidate && str_starts_with($candidate, $uploads_basedir)) {
            if (file_exists($candidate)) {
                return realpath($candidate);
            }

            $parent = dirname($candidate);

            if ($parent === $candidate) {
                break;
            }

            $candidate = $parent;
        }

        return realpath(untrailingslashit($uploads_basedir));
    }

    /**
     * Create a temporary file path in the destination directory.
     *
     * @param string $destination_path Prepared destination path.
     * @return string|\WP_Error
     */
    private function createDownloadTemporaryPath($destination_path) {
        $destination_dir = dirname($destination_path);
        $temporary_path = tempnam($destination_dir, basename($destination_path) . '.bunny-download-');

        if (!is_string($temporary_path) || '' === $temporary_path) {
            return new \WP_Error(
                'indigetal_offload_storage_download_temporary_create_failed',
                __('A temporary file could not be created for Bunny Storage download.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'destination_path' => $destination_path,
                    'directory'        => $destination_dir,
                ]
            );
        }

        return wp_normalize_path($temporary_path);
    }

    /**
     * Move a streamed temporary download into its final destination.
     *
     * @param string $temporary_path   Temporary download file path.
     * @param string $destination_path Prepared destination path.
     * @param string $remote_path      Remote object path for context.
     * @return true|\WP_Error
     */
    private function finalizeDownloadedFile($temporary_path, $destination_path, $remote_path) {
        $temporary_path = wp_normalize_path((string) $temporary_path);
        $destination_path = wp_normalize_path((string) $destination_path);

        if ('' === $temporary_path || !file_exists($temporary_path) || !is_readable($temporary_path)) {
            return new \WP_Error(
                'indigetal_offload_storage_download_temporary_missing',
                __('The streamed Bunny Storage download file was not available to finalize.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'remote_path'      => $remote_path,
                    'temporary_path'   => $temporary_path,
                    'destination_path' => $destination_path,
                ]
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic local temp-to-final move for Storage download.
        if (!@rename($temporary_path, $destination_path)) {
            $this->deleteTemporaryDownloadFile($temporary_path);

            return new \WP_Error(
                'indigetal_offload_storage_download_finalize_failed',
                __('The Bunny Storage download could not be moved into its final local path.', 'indigetal-media-offload-for-bunny-net'),
                [
                    'remote_path'      => $remote_path,
                    'temporary_path'   => $temporary_path,
                    'destination_path' => $destination_path,
                ]
            );
        }

        return true;
    }

    /**
     * Delete a temporary download file if it exists.
     *
     * @param string $temporary_path Temporary download file path.
     * @return void
     */
    private function deleteTemporaryDownloadFile($temporary_path) {
        $temporary_path = wp_normalize_path((string) $temporary_path);

        if ('' !== $temporary_path && file_exists($temporary_path)) {
            wp_delete_file($temporary_path);
        }
    }

    /**
     * Normalize a remote path and prefix it with the storage zone name when needed.
     *
     * @param string $remote_path Remote path relative to the storage zone root.
     * @return string
     */
    private function normalizePath($remote_path) {
        $remote_path = trim(wp_normalize_path((string) $remote_path));
        $remote_path = ltrim(preg_replace('#/+#', '/', str_replace('\\', '/', $remote_path)), '/');

        if ('' === $remote_path) {
            return '';
        }

        if ($remote_path === $this->storage_zone_name || 0 === strpos($remote_path, $this->storage_zone_name . '/')) {
            return $remote_path;
        }

        return trailingslashit($this->storage_zone_name) . $remote_path;
    }

    /**
     * Wait for any stored Retry-After window before the next Bunny Storage request.
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
        BunnyLogger::log("Waiting {$sleep_seconds} seconds for the Bunny Storage Retry-After window to expire.", 'warning');
        sleep($sleep_seconds);
    }

    /**
     * Determine the retry delay from a response or fall back to exponential backoff.
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
     * Attach Bunny Storage context to a transport-level WP_Error.
     *
     * @param \WP_Error $error      Transport error from wp_remote_request().
     * @param string    $remotePath Remote path being requested.
     * @param string    $operation  Request operation.
     * @return \WP_Error
     */
    private function prepareTransportError($error, $remotePath, $operation) {
        $error->add_data(
            [
                'remote_path' => $remotePath,
                'operation'   => $operation,
                'api'         => 'Bunny Storage',
            ],
            $error->get_error_code()
        );

        return $error;
    }

    /**
     * Build a WP_Error containing Bunny Storage HTTP failure details.
     *
     * @param string $operation    Request operation.
     * @param int    $responseCode HTTP status code.
     * @param string $responseBody Raw response body.
     * @param string $remotePath   Remote path being requested.
     * @return \WP_Error
     */
    private function buildHttpError($operation, $responseCode, $responseBody, $remotePath) {
        $parsed_message = $this->extractResponseMessage($responseBody);
        $message = '' !== $parsed_message ? $parsed_message : 'No response body';
        $error_code = 'indigetal_offload_storage_http_error';

        if ('upload' === $operation && 400 === $responseCode) {
            $message = __('Checksum and file contents mismatched.', 'indigetal-media-offload-for-bunny-net');
        }

        if ('download' === $operation && 404 === $responseCode) {
            $error_code = 'indigetal_offload_storage_download_not_found';
            $message = __('The Bunny Storage object was not found. Confirm the manifest remote path still exists in Bunny Storage.', 'indigetal-media-offload-for-bunny-net');
        } elseif ('download' === $operation && in_array($responseCode, [401, 403], true)) {
            $error_code = 'indigetal_offload_storage_download_unauthorized';
            $message = __('Bunny Storage rejected the download request. Confirm the Storage zone password still has access to this object.', 'indigetal-media-offload-for-bunny-net');
        }

        BunnyLogger::log("Bunny Storage {$operation} failed for {$remotePath} (HTTP {$responseCode}): {$message}", 'error');

        return new \WP_Error(
            $error_code,
            sprintf(
                /* translators: 1: operation name (upload or download), 2: remote object path, 3: HTTP status code, 4: error message */
                __('Bunny Storage %1$s error for %2$s (HTTP %3$d): %4$s', 'indigetal-media-offload-for-bunny-net'),
                $operation,
                $remotePath,
                $responseCode,
                $message
            ),
            [
                'status'        => $responseCode,
                'response_code' => $responseCode,
                'body'          => $responseBody,
                'message'       => $message,
                'remote_path'   => $remotePath,
                'operation'     => $operation,
            ]
        );
    }

    /**
     * Extract a useful message from a Bunny Storage response body when possible.
     *
     * @param string $response_body Raw response body.
     * @return string
     */
    private function extractResponseMessage($response_body) {
        $response_body = trim((string) $response_body);

        if ('' === $response_body) {
            return '';
        }

        $decoded = json_decode($response_body, true);

        if (is_array($decoded) && !empty($decoded['Message']) && is_string($decoded['Message'])) {
            return $decoded['Message'];
        }

        return $response_body;
    }
}
