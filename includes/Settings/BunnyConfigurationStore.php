<?php

namespace Bunny_Offload\Settings;

use Bunny_Offload\Admin\BunnySettings;
use Bunny_Offload\Bunny\BunnyApiClient;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyConfigurationStore {
    private const OPTION_STORAGE_PULL_ZONE_IDENTITY = 'indigetal_offload_storage_pull_zone_identity';

    private const STORAGE_PULL_ZONE_IDENTITY_DEFAULT = [
        'id'       => '',
        'name'     => '',
        'hostname' => '',
    ];

    /**
     * BunnyApiClient instance.
     */
    private $bunnyApiClient;

    /**
     * Constructor
     */
    public function __construct() {
        $this->bunnyApiClient = BunnyApiClient::getInstance();
    }

    /**
     * Store API keys securely using encryption.
     */
    public static function encrypt_api_key($key) {
        $key = is_scalar($key) ? (string) $key : '';
        $encryption_key = \wp_salt();
        return base64_encode(openssl_encrypt($key, 'aes-256-cbc', $encryption_key, 0, substr($encryption_key, 0, 16)));
    }

    /**
     * Decrypt API keys when retrieving.
     */
    public static function decrypt_api_key($encrypted_key) {
        if (!is_string($encrypted_key) || '' === $encrypted_key) {
            return '';
        }

        $encryption_key = \wp_salt();
        $decoded_key = base64_decode($encrypted_key, true);

        if (false === $decoded_key) {
            return '';
        }

        $decrypted_key = openssl_decrypt($decoded_key, 'aes-256-cbc', $encryption_key, 0, substr($encryption_key, 0, 16));

        return false === $decrypted_key ? '' : $decrypted_key;
    }

    /**
     * Save encrypted API key to database.
     */
    public function saveApiKey($key) {
        \update_option(BunnySettings::OPTION_ACCESS_KEY, self::encrypt_api_key($key));
    }

    /**
     * Retrieve decrypted API key from database.
     */
    public static function getApiKey() {
        $encrypted_key = \get_option(BunnySettings::OPTION_ACCESS_KEY, '');
        return self::decrypt_api_key($encrypted_key);
    }

    /**
     * Retrieve the configured Bunny Storage zone password.
     *
     * @return string
     */
    public static function getStoragePassword() {
        $encrypted_key = \get_option(BunnySettings::OPTION_STORAGE_PASSWORD, '');

        return self::decrypt_api_key($encrypted_key);
    }

    /**
     * Retrieve the configured Bunny Storage zone name.
     *
     * @return string
     */
    public static function getStorageZoneName() {
        return self::getPlaintextOption(BunnySettings::OPTION_STORAGE_ZONE);
    }

    /**
     * Retrieve the configured Bunny Storage region code.
     *
     * @return string
     */
    public static function getStorageRegion() {
        return self::getPlaintextOption(BunnySettings::OPTION_STORAGE_REGION);
    }

    /**
     * Retrieve the configured Bunny Stream CDN hostname (Pull Zone hostname from the Stream library API tab).
     *
     * @return string
     */
    public static function getStreamPullZoneHostname() {
        return self::getPlaintextOption(BunnySettings::OPTION_PULL_ZONE);
    }

    /**
     * Retrieve the configured Bunny Stream library ID (plaintext).
     *
     * Legacy installs may still store an AES-encrypted blob; this resolves to a
     * plaintext identifier when decrypt + validation succeed.
     *
     * @return string
     */
    public static function getStreamLibraryId() {
        $raw = \get_option(BunnySettings::OPTION_LIBRARY_ID, '');

        return self::normalizeStoredStreamLibraryId(is_scalar($raw) ? (string) $raw : '');
    }

    /**
     * Whether a string looks like this plugin's base64-wrapped ciphertext (not a valid library id).
     *
     * @param string $value Candidate value.
     * @return bool
     */
    public static function streamLibraryIdLooksLikeEncryptedPayload($value) {
        $value = trim((string) $value);

        if ('' === $value || preg_match('/^[0-9]+$/', $value)) {
            return false;
        }

        if (strlen($value) < 24) {
            return false;
        }

        return (bool) preg_match('#^[A-Za-z0-9+/]+=*$#', $value);
    }

    /**
     * Whether the value is an acceptable Bunny Stream library identifier (public, not secret).
     *
     * @param string $value Trimmed plaintext candidate.
     * @return bool
     */
    public static function isValidStreamLibraryId($value) {
        $value = trim((string) $value);

        if ('' === $value) {
            return false;
        }

        if (self::streamLibraryIdLooksLikeEncryptedPayload($value)) {
            return false;
        }

        if (preg_match('/^[0-9]{1,20}$/', $value)) {
            return true;
        }

        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value)) {
            return true;
        }

        if (strlen($value) <= 64 && preg_match('/^[A-Za-z0-9-]+$/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * Normalize a stored option value to a plaintext library id when possible.
     *
     * @param string $raw Raw option string from the database.
     * @return string Plaintext library id or empty string if unusable.
     */
    public static function normalizeStoredStreamLibraryId($raw) {
        $raw = trim((string) $raw);

        if ('' === $raw) {
            return '';
        }

        if (self::isValidStreamLibraryId($raw)) {
            return $raw;
        }

        $decrypted = self::decrypt_api_key($raw);

        if ('' !== $decrypted && self::isValidStreamLibraryId($decrypted)) {
            return $decrypted;
        }

        return '';
    }

    /**
     * Retrieve the configured Bunny Storage delivery Pull Zone hostname.
     *
     * @return string
     */
    public static function getStoragePullZoneHostname() {
        return self::getPlaintextOption(BunnySettings::OPTION_STORAGE_PULL_ZONE);
    }

    /**
     * Return whether future uploads should enter the Storage offload pipeline.
     *
     * @return bool
     */
    public static function isStorageOffloadEnabled() {
        return self::getBooleanOption(BunnySettings::OPTION_STORAGE_ENABLED);
    }

    /**
     * Return whether future uploads should enter the Stream upload pipeline.
     *
     * @return bool
     */
    public static function isStreamEnabled() {
        return self::getBooleanOption(BunnySettings::OPTION_STREAM_ENABLED);
    }

    /**
     * Return whether successful Storage uploads should delete local files.
     *
     * @return bool
     */
    public static function shouldRemoveLocalFiles() {
        return self::getBooleanOption(BunnySettings::OPTION_REMOVE_LOCAL_FILES);
    }

    /**
     * Return whether successful Stream uploads should delete local video files.
     *
     * @return bool
     */
    public static function shouldRemoveLocalVideoFiles() {
        return self::getBooleanOption(BunnySettings::OPTION_REMOVE_LOCAL_VIDEO_FILES);
    }

    /**
     * Return whether the Stream upload/read runtime inputs are present.
     *
     * @return bool
     */
    public static function isStreamConfigured() {
        return '' !== self::getApiKey()
            && '' !== self::getStreamLibraryId()
            && '' !== self::getStreamPullZoneHostname();
    }

    /**
     * Return whether the Stream upload runtime is ready to run.
     *
     * @return bool
     */
    public static function isStreamUploadRuntimeReady() {
        return self::isStreamConfigured();
    }

    /**
     * Return whether the storage HTTP transport inputs are present.
     *
     * @return bool
     */
    public static function isStorageTransportConfigured() {
        $configuration = self::getStorageOffloadConfiguration();

        return '' !== $configuration['storage_zone']
            && '' !== $configuration['storage_region']
            && '' !== $configuration['storage_password'];
    }

    /**
     * Return whether the storage offload runtime is ready to run.
     *
     * @return bool
     */
    public static function isStorageOffloadRuntimeReady() {
        $configuration = self::getStorageOffloadConfiguration();

        return self::isStorageTransportConfigured()
            && '' !== $configuration['storage_pull_zone'];
    }

    /**
     * Back-compat alias for storage offload runtime readiness.
     *
     * @return bool
     */
    public static function isStorageOffloadConfigured() {
        return self::isStorageOffloadRuntimeReady();
    }

    /**
     * Return whether the future-upload Storage pipeline is enabled and ready.
     *
     * @return bool
     */
    public static function isStorageOffloadPipelineReady() {
        return self::isStorageOffloadEnabled() && self::isStorageOffloadRuntimeReady();
    }

    /**
     * Return the current Phase 2/3 Bunny Storage offload configuration payload.
     *
     * @return array<string, string>
     */
    public static function getStorageOffloadConfiguration() {
        return [
            'storage_zone'      => self::getStorageZoneName(),
            'storage_region'    => self::getStorageRegion(),
            'storage_password'  => self::getStoragePassword(),
            'storage_pull_zone' => self::getStoragePullZoneHostname(),
        ];
    }

    /**
     * Return the stored storage pull-zone identity when it still matches the
     * currently configured delivery hostname.
     *
     * @return array{id: string, name: string, hostname: string}
     */
    public static function getStoredStoragePullZoneIdentity() {
        $raw_identity = \get_option(
            self::OPTION_STORAGE_PULL_ZONE_IDENTITY,
            self::STORAGE_PULL_ZONE_IDENTITY_DEFAULT
        );

        if (!is_array($raw_identity)) {
            return self::STORAGE_PULL_ZONE_IDENTITY_DEFAULT;
        }

        $identity = [
            'id'       => self::sanitizeStoredScalar($raw_identity['id'] ?? ''),
            'name'     => self::sanitizeStoredScalar($raw_identity['name'] ?? ''),
            'hostname' => self::normalizeHostname($raw_identity['hostname'] ?? ''),
        ];

        $configured_hostname = self::normalizeHostname(self::getStoragePullZoneHostname());

        if ('' === $configured_hostname || $configured_hostname !== $identity['hostname']) {
            return self::STORAGE_PULL_ZONE_IDENTITY_DEFAULT;
        }

        return $identity;
    }

    /**
     * Return the stored storage pull-zone ID for the current delivery hostname.
     *
     * @return string
     */
    public static function getStoredStoragePullZoneId() {
        $identity = self::getStoredStoragePullZoneIdentity();

        return $identity['id'];
    }

    /**
     * Return the stored storage pull-zone name for the current delivery hostname.
     *
     * @return string
     */
    public static function getStoredStoragePullZoneName() {
        $identity = self::getStoredStoragePullZoneIdentity();

        return $identity['name'];
    }

    /**
     * Return whether a stored pull-zone identity is available for the current
     * delivery hostname.
     *
     * @return bool
     */
    public static function hasStoredStoragePullZoneIdentity() {
        return '' !== self::getStoredStoragePullZoneId();
    }

    /**
     * Persist the storage pull-zone identity for the current delivery hostname.
     *
     * @param mixed  $pull_zone_id   Pull-zone ID.
     * @param mixed  $pull_zone_name Pull-zone name.
     * @param string $hostname       Optional hostname override.
     * @return void
     */
    public static function setStoredStoragePullZoneIdentity($pull_zone_id, $pull_zone_name, $hostname = '') {
        $identity = [
            'id'       => self::sanitizeStoredScalar($pull_zone_id),
            'name'     => self::sanitizeStoredScalar($pull_zone_name),
            'hostname' => self::normalizeHostname(
                '' !== $hostname ? $hostname : self::getStoragePullZoneHostname()
            ),
        ];

        if ('' === $identity['id'] || '' === $identity['name'] || '' === $identity['hostname']) {
            self::clearStoredStoragePullZoneIdentity();
            return;
        }

        \update_option(self::OPTION_STORAGE_PULL_ZONE_IDENTITY, $identity);
    }

    /**
     * Clear the stored storage pull-zone identity.
     *
     * @return void
     */
    public static function clearStoredStoragePullZoneIdentity() {
        \delete_option(self::OPTION_STORAGE_PULL_ZONE_IDENTITY);
    }

    /**
     * Retrieve a site-scoped plaintext Bunny option as a string.
     *
     * @param string $option_name Option key.
     * @return string
     */
    private static function getPlaintextOption($option_name) {
        $value = \get_option($option_name, '');

        return is_string($value) ? $value : '';
    }

    /**
     * Retrieve a stored boolean option from the WordPress options table.
     *
     * @param string $option_name Option key.
     * @return bool
     */
    private static function getBooleanOption($option_name, $default = '0') {
        $value = \get_option($option_name, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return false;
        }

        return '1' === (string) $value;
    }

    /**
     * Normalize a scalar value stored in an internal option payload.
     *
     * @param mixed $value Option value.
     * @return string
     */
    private static function sanitizeStoredScalar($value) {
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Normalize a Bunny delivery hostname for comparisons.
     *
     * @param mixed $hostname Hostname candidate.
     * @return string
     */
    private static function normalizeHostname($hostname) {
        if (!is_scalar($hostname)) {
            return '';
        }

        $normalized = strtolower(trim((string) $hostname));
        $normalized = (string) preg_replace('#^https?://#i', '', $normalized);

        return trim($normalized, "/ \t\n\r\0\x0B");
    }
}
