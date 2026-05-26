<?php
/**
 * Bunny.net Settings Page
 * Provides a WordPress admin page for storing Bunny.net credentials.
 *
 * @package WPBunnyStream\Admin
 * @since 0.1.0
 */

namespace Bunny_Offload\Admin;

use Bunny_Offload\Bunny\BunnyApiClient;
use Bunny_Offload\Settings\BunnyConfigurationStore;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnySettings {

    const PAGE_SLUG = 'indigetal-media-offload-for-bunny-net';
    const TAB_SETTINGS = 'settings';
    const TAB_ABOUT_PRIVACY = 'about-privacy';

    /**
     * Settings API option group for this plugin's wp_options rows.
     */
    const SETTINGS_GROUP = 'indigetal_offload_settings';

    /**
     * Option keys for storing credentials.
     */
    const OPTION_ACCESS_KEY = 'indigetal_offload_stream_access_key';
    const OPTION_LIBRARY_ID = 'indigetal_offload_stream_library_id';
    const OPTION_PULL_ZONE = 'indigetal_offload_stream_pull_zone';
    const OPTION_STREAM_ENABLED = 'indigetal_offload_stream_enabled';
    const OPTION_REMOVE_LOCAL_VIDEO_FILES = 'indigetal_offload_remove_local_video_files';
    const OPTION_STORAGE_ZONE = 'indigetal_offload_storage_zone';
    const OPTION_STORAGE_REGION = 'indigetal_offload_storage_region';
    const OPTION_STORAGE_PASSWORD = 'indigetal_offload_storage_password';
    const OPTION_STORAGE_PULL_ZONE = 'indigetal_offload_storage_pull_zone';
    const OPTION_STORAGE_ENABLED = 'indigetal_offload_storage_enabled';
    const OPTION_REMOVE_LOCAL_FILES = 'indigetal_offload_remove_local_files';
    /**
     * When set to "1", uninstall deletes plugin-owned WordPress options/meta/transients
     * (see uninstall.php). Never deletes local media files or remote Bunny objects.
     */
    const OPTION_DELETE_PLUGIN_DATA_ON_UNINSTALL = 'indigetal_offload_delete_plugin_data_on_uninstall';

    /**
     * Direct Bunny.net affiliate landing URL (must not be cloaked or redirected for WordPress.org compliance).
     *
     * @var string
     */
    private const BUNNY_NET_AFFILIATE_URL = 'https://bunny.net?ref=t6n8vh4ksm';

    /**
     * Bunny.net customer dashboard (no affiliate parameters).
     *
     * @var string
     */
    private const BUNNY_NET_DASHBOARD_URL = 'https://dash.bunny.net/';

    /**
     * Public URL for information about the optional Pro companion (author site).
     *
     *
     * @var string
     */
    private const PRO_COMPANION_INFO_URL = 'https://indigetal.com/';

    /**
     * Supported Bunny Storage regions keyed by region code.
     */
    const STORAGE_REGIONS = [
        'de' => 'Frankfurt',
        'uk' => 'London',
        'ny' => 'New York',
        'la' => 'Los Angeles',
        'sg' => 'Singapore',
        'se' => 'Stockholm',
        'br' => 'Sao Paulo',
        'jh' => 'Johannesburg',
        'syd' => 'Sydney',
    ];

    /**
     * BunnyApiClient instance.
     */
    private $bunnyApiClient;

    /**
     * Initialize the settings page.
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueSettingsAssets']);

        // Seed uninstall cleanup opt-in before Settings API saves (add_option no-op if present).
        $this->seedUninstallCleanupOption();

        // Initialize BunnyApiClient instance
        $this->bunnyApiClient = BunnyApiClient::getInstance();
    }

    /**
     * Ensure the uninstall cleanup opt-in row exists: default "0", autoload disabled.
     * `add_option` is a no-op when the option already exists.
     *
     * @return void
     */
    private function seedUninstallCleanupOption() {
        add_option(self::OPTION_DELETE_PLUGIN_DATA_ON_UNINSTALL, '0', '', false);
    }

    /**
     * Whether dependent Storage controls are currently rendered disabled.
     *
     * @var bool
     */
    private $storage_dependent_controls_disabled = false;

    /**
     * Whether dependent Stream controls are currently rendered disabled.
     *
     * @var bool
     */
    private $stream_dependent_controls_disabled = false;

    /**
     * Enqueue admin styles for the plugin Media submenu settings page.
     *
     * @param string $hook_suffix Current admin screen hook suffix.
     * @return void
     */
    public function enqueueSettingsAssets($hook_suffix) {
        if ('media_page_' . self::PAGE_SLUG !== $hook_suffix) {
            return;
        }

        $plugin_file = dirname(__DIR__, 2) . '/indigetal-media-offload-for-bunny-net.php';
        $style_rel    = 'assets/css/indigetal-offload-admin.css';
        $style_path   = dirname(__DIR__, 2) . '/' . $style_rel;
        $script_rel   = 'assets/js/bunny-settings.js';
        $script_path  = dirname(__DIR__, 2) . '/' . $script_rel;

        wp_enqueue_style(
            'indigetal-offload-admin',
            plugins_url($style_rel, $plugin_file),
            [],
            is_readable($style_path) ? (string) filemtime($style_path) : false
        );

        wp_enqueue_script(
            'indigetal-offload-settings',
            plugins_url($script_rel, $plugin_file),
            [],
            is_readable($script_path) ? (string) filemtime($script_path) : false,
            true
        );
    }

    /**
     * URL for a file under the plugin root (cache-busted when the file exists).
     *
     * @param string $relative_path Path from the plugin root, e.g. assets/example.svg.
     * @return string
     */
    private static function pluginAssetUrl($relative_path) {
        $plugin_root = dirname(__DIR__, 2);
        $plugin_file = $plugin_root . '/indigetal-media-offload-for-bunny-net.php';
        $relative_path = ltrim((string) $relative_path, '/');
        $filesystem_path = $plugin_root . '/' . $relative_path;
        $url = plugins_url($relative_path, $plugin_file);

        if (is_readable($filesystem_path)) {
            return add_query_arg('ver', (string) filemtime($filesystem_path), $url);
        }

        return $url;
    }

    /**
     * Render the top-of-panel Bunny.net promo strip on the Settings tab (single layout; affiliate link in footnote).
     *
     * @return void
     */
    private function renderSettingsPromoBanner() {
        $rocket_src = self::pluginAssetUrl('assets/bunny-rocket.png');

        echo '<div class="bmo-settings-promo">';
        echo '<div class="bmo-settings-promo__grid">';
        echo '<div class="bmo-settings-promo__copy">';
        echo '<p class="bmo-settings-promo__eyebrow">' . esc_html__('About bunny.net', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '<p class="bmo-card__title bmo-settings-promo__title bmo-settings-promo__title--about">';
        echo esc_html__('Supercharge your delivery', 'indigetal-media-offload-for-bunny-net');
        echo '</p>';
        echo '<p class="bmo-card__description bmo-settings-promo__body">';
        echo esc_html__(
            'Bunny.net helps you accelerate your website and supercharge your web presence. Through a network of over 100 global datacenters, Bunny\'s CDN stores your files right next to your users and delivers them with lightning speed.',
            'indigetal-media-offload-for-bunny-net'
        );
        echo '</p>';
        echo '<p class="bmo-settings-promo__actions">';
        echo '<a class="button button-primary bmo-settings-promo__cta" href="' . esc_url(self::BUNNY_NET_DASHBOARD_URL) . '" target="_blank" rel="noopener noreferrer">';
        echo esc_html__('Visit the Bunny.net Dashboard', 'indigetal-media-offload-for-bunny-net');
        echo '</a>';
        echo '</p>';
        echo '<p class="bmo-card__description">';
        echo wp_kses(
            sprintf(
                /* translators: %s: affiliate signup URL (bunny.net with ref) */
                __(
                    'New to Bunny.net? Start a <a href="%s" target="_blank" rel="noopener noreferrer sponsored">14-day free trial</a> on bunny.net (affiliate link).',
                    'indigetal-media-offload-for-bunny-net'
                ),
                esc_url(self::BUNNY_NET_AFFILIATE_URL)
            ),
            [
                'a' => [
                    'href'   => true,
                    'target' => true,
                    'rel'    => true,
                ],
            ]
        );
        echo '</p>';
        echo '</div>';
        echo '<div class="bmo-settings-promo__media" aria-hidden="true">';
        echo '<img src="' . esc_url($rocket_src) . '" alt="" width="336" height="264" loading="lazy" decoding="async">';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Add the Bunny.net settings page to the WordPress admin menu.
     */
    public function addSettingsPage() {
        add_media_page(
            __('Indigetal Media Offload', 'indigetal-media-offload-for-bunny-net'),
            __('Indigetal Media Offload', 'indigetal-media-offload-for-bunny-net'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Default admin tabs before the `indigetal_offload_admin_tabs` filter.
     *
     * Each tab is an array with keys: `label` (string), `url` (string). Optional: `capability`
     * (string) for future consumers; Free rendering still requires `manage_options` on the page.
     *
     * @return array<string, array{label: string, url: string}>
     */
    public static function getAdminTabs() {
        $tabs = [
            self::TAB_SETTINGS => [
                'label' => __('Settings', 'indigetal-media-offload-for-bunny-net'),
                'url'   => admin_url('upload.php?page=' . self::PAGE_SLUG . '&tab=' . self::TAB_SETTINGS),
            ],
            self::TAB_ABOUT_PRIVACY => [
                'label' => __('About & Privacy', 'indigetal-media-offload-for-bunny-net'),
                'url'   => admin_url('upload.php?page=' . self::PAGE_SLUG . '&tab=' . self::TAB_ABOUT_PRIVACY),
            ],
        ];

        /**
         * Filter admin nav tabs for this plugin's Media screen. Pro add-ons append tabs here
         * and render bodies via `indigetal_offload_render_admin_tab` using the same slug keys.
         *
         * @param array<string, array{label: string, url: string}> $tabs Tab slug => tab data.
         */
        $filtered = apply_filters('indigetal_offload_admin_tabs', $tabs);

        return is_array($filtered) ? $filtered : $tabs;
    }

    /**
     * Render the plugin-owned admin tabs for Settings and About & Privacy (after filter).
     *
     * @param string $active_tab Current active tab slug.
     * @return void
     */
    public static function renderAdminTabs($active_tab) {
        $tabs = self::getAdminTabs();

        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Indigetal Media Offload tabs', 'indigetal-media-offload-for-bunny-net') . '">';

        foreach ($tabs as $tab_slug => $tab) {
            if (!is_array($tab) || empty($tab['label']) || empty($tab['url'])) {
                continue;
            }

            $class_name = 'nav-tab';

            if ($active_tab === $tab_slug) {
                $class_name .= ' nav-tab-active';
            }

            echo '<a class="' . esc_attr($class_name) . '" href="' . esc_url((string) $tab['url']) . '">';
            echo esc_html((string) $tab['label']);
            echo '</a>';
        }

        echo '</nav>';
    }

    /**
     * Register settings for Bunny.net credentials.
     */
    public function registerSettings() {
        register_setting(self::SETTINGS_GROUP, self::OPTION_DELETE_PLUGIN_DATA_ON_UNINSTALL, [
            'default'           => '0',
            'sanitize_callback' => [$this, 'sanitizeDeletePluginDataOnUninstallSetting'],
        ]);
        register_setting(self::SETTINGS_GROUP, self::OPTION_ACCESS_KEY, [
            'sanitize_callback' => function($value) {
                return $this->sanitizeEncryptedSetting(self::OPTION_ACCESS_KEY, $value);
            }
        ]);
        register_setting(self::SETTINGS_GROUP, self::OPTION_LIBRARY_ID, [
            'sanitize_callback' => [$this, 'sanitizeStreamLibraryIdSetting']
        ]);
        register_setting(self::SETTINGS_GROUP, self::OPTION_PULL_ZONE, [
            'sanitize_callback' => function($value) {
                return $this->sanitizeStreamPlaintextSetting(self::OPTION_PULL_ZONE, $value);
            }
        ]);
        register_setting(self::SETTINGS_GROUP, self::OPTION_STREAM_ENABLED, [
            'default'           => '0',
            'sanitize_callback' => [$this, 'sanitizeStreamEnabledSetting']
        ]);
        register_setting(self::SETTINGS_GROUP, self::OPTION_REMOVE_LOCAL_VIDEO_FILES, [
            'default'           => '0',
            'sanitize_callback' => function($value) {
                return $this->sanitizeStreamDependentBooleanSetting(self::OPTION_REMOVE_LOCAL_VIDEO_FILES, $value);
            }
        ]);
        register_setting(self::SETTINGS_GROUP, self::OPTION_STORAGE_ENABLED, [
            'default'           => '0',
            'sanitize_callback' => [$this, 'sanitizeBooleanSetting']
        ]);
        register_setting(self::SETTINGS_GROUP, self::OPTION_REMOVE_LOCAL_FILES, [
            'default'           => '0',
            'sanitize_callback' => function($value) {
                return $this->sanitizeStorageDependentBooleanSetting(self::OPTION_REMOVE_LOCAL_FILES, $value);
            }
        ]);
        register_setting(self::SETTINGS_GROUP, self::OPTION_STORAGE_ZONE, [
            'sanitize_callback' => function($value) {
                return $this->sanitizeStoragePlaintextSetting(self::OPTION_STORAGE_ZONE, $value);
            }
        ]);
        register_setting(self::SETTINGS_GROUP, self::OPTION_STORAGE_REGION, [
            'sanitize_callback' => [$this, 'sanitizeStorageRegionSetting']
        ]);
        register_setting(self::SETTINGS_GROUP, self::OPTION_STORAGE_PASSWORD, [
            'sanitize_callback' => function($value) {
                return $this->sanitizeEncryptedSetting(self::OPTION_STORAGE_PASSWORD, $value);
            }
        ]);
        register_setting(self::SETTINGS_GROUP, self::OPTION_STORAGE_PULL_ZONE, [
            'sanitize_callback' => function($value) {
                return $this->sanitizeStoragePlaintextSetting(self::OPTION_STORAGE_PULL_ZONE, $value);
            }
        ]);
    }

    /**
     * Sanitize a plaintext settings value.
     *
     * @param mixed $value Submitted option value.
     * @return string
     */
    public function sanitizePlaintextSetting($value) {
        return sanitize_text_field(wp_unslash((string) $value));
    }

    /**
     * Sanitize the submitted storage region.
     *
     * @param mixed $value Submitted option value.
     * @return string
     */
    public function sanitizeStorageRegion($value) {
        $value = sanitize_key(wp_unslash((string) $value));

        return array_key_exists($value, self::STORAGE_REGIONS) ? $value : '';
    }

    /**
     * Sanitize a dependent storage plaintext setting while preserving hidden values.
     *
     * @param string $option_name Option key.
     * @param mixed  $value       Submitted option value.
     * @return string
     */
    public function sanitizeStoragePlaintextSetting($option_name, $value) {
        if ($this->shouldPreserveOmittedStorageOption($option_name)) {
            return (string) get_option($option_name, '');
        }

        return $this->sanitizePlaintextSetting($value);
    }

    /**
     * Sanitize a dependent Stream plaintext setting while preserving hidden values.
     *
     * @param string $option_name Option key.
     * @param mixed  $value       Submitted option value.
     * @return string
     */
    public function sanitizeStreamPlaintextSetting($option_name, $value) {
        if ($this->shouldPreserveOmittedStreamOption($option_name)) {
            return (string) get_option($option_name, '');
        }

        return $this->sanitizePlaintextSetting($value);
    }

    /**
     * Sanitize the dependent Storage region setting while preserving hidden values.
     *
     * @param mixed $value Submitted option value.
     * @return string
     */
    public function sanitizeStorageRegionSetting($value) {
        if ($this->shouldPreserveOmittedStorageOption(self::OPTION_STORAGE_REGION)) {
            return (string) get_option(self::OPTION_STORAGE_REGION, '');
        }

        return $this->sanitizeStorageRegion($value);
    }

    /**
     * Sanitize a submitted boolean settings value.
     *
     * WordPress posts unchecked checkbox settings as null through options.php,
     * so store booleans as explicit string flags for stable option reads.
     *
     * @param mixed $value Submitted option value.
     * @return string
     */
    public function sanitizeBooleanSetting($value) {
        return rest_sanitize_boolean($value) ? '1' : '0';
    }

    /**
     * Coerce uninstall cleanup opt-in to stored string "0" or "1" only.
     *
     * @param mixed $value Submitted option value.
     * @return string
     */
    public function sanitizeDeletePluginDataOnUninstallSetting($value) {
        return rest_sanitize_boolean($value) ? '1' : '0';
    }

    /**
     * Sanitize Stream library id as plaintext with legacy ciphertext migration.
     *
     * @param mixed $value Submitted option value (may be null when omitted from POST).
     * @return string
     */
    public function sanitizeStreamLibraryIdSetting($value) {
        if ($this->shouldPreserveOmittedStreamOption(self::OPTION_LIBRARY_ID)
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php + settings_fields.
            || !isset($_POST[self::OPTION_LIBRARY_ID])) {
            return BunnyConfigurationStore::normalizeStoredStreamLibraryId((string) get_option(self::OPTION_LIBRARY_ID, ''));
        }

        $trimmed = trim(wp_unslash((string) $value));

        if ('' === $trimmed) {
            return '';
        }

        if (BunnyConfigurationStore::isValidStreamLibraryId($trimmed)) {
            return $trimmed;
        }

        if (function_exists('add_settings_error')) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'indigetal_offload_stream_library_id_invalid',
                __('The Video library ID was not saved because it did not match a supported format. Use the numeric library ID from your Bunny Stream library (or a standard UUID). Values that look like encrypted blobs are not accepted.', 'indigetal-media-offload-for-bunny-net'),
                'error'
            );
        }

        return BunnyConfigurationStore::normalizeStoredStreamLibraryId((string) get_option(self::OPTION_LIBRARY_ID, ''));
    }

    /**
     * Persist Stream enabled only when API key, library id, and CDN hostname are usable.
     *
     * Runs after Stream credential options in the same options.php pass (register order).
     *
     * @param mixed $value Submitted option value.
     * @return string '0' or '1'
     */
    public function sanitizeStreamEnabledSetting($value) {
        if (!rest_sanitize_boolean($value)) {
            return '0';
        }

        if ($this->isStreamRuntimePersistableFromStoredOptions()) {
            return '1';
        }

        if (function_exists('add_settings_error')) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'indigetal_offload_stream_enable_blocked',
                __('Stream could not be enabled because the Stream API key, Video library ID, and CDN hostname must all be saved first. Fill those fields (or save again after they appear), then enable Stream.', 'indigetal-media-offload-for-bunny-net'),
                'error'
            );
        }

        return '0';
    }

    /**
     * Whether Stream can be turned on given current option values (after prior sanitizers in this request).
     *
     * @return bool
     */
    private function isStreamRuntimePersistableFromStoredOptions() {
        return '' !== BunnyConfigurationStore::getApiKey()
            && '' !== BunnyConfigurationStore::getStreamLibraryId()
            && '' !== BunnyConfigurationStore::getStreamPullZoneHostname();
    }

    /**
     * Sanitize a dependent Storage boolean while preserving hidden values.
     *
     * @param string $option_name Option key.
     * @param mixed  $value       Submitted option value.
     * @return string
     */
    public function sanitizeStorageDependentBooleanSetting($option_name, $value) {
        if ($this->shouldPreserveOmittedStorageOption($option_name)) {
            return rest_sanitize_boolean(get_option($option_name, '0')) ? '1' : '0';
        }

        return $this->sanitizeBooleanSetting($value);
    }

    /**
     * Sanitize a dependent Stream boolean while preserving hidden values.
     *
     * @param string $option_name Option key.
     * @param mixed  $value       Submitted option value.
     * @return string
     */
    public function sanitizeStreamDependentBooleanSetting($option_name, $value) {
        if ($this->shouldPreserveOmittedStreamOption($option_name)) {
            return rest_sanitize_boolean(get_option($option_name, '1')) ? '1' : '0';
        }

        return $this->sanitizeBooleanSetting($value);
    }

    /**
     * Return whether a dependent Storage control was intentionally omitted.
     *
     * @param string $option_name Option key.
     * @return bool
     */
    private function shouldPreserveOmittedStorageOption($option_name) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php + settings_fields (security-nonce-audit.md).
        if (isset($_POST[$option_name])) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php + settings_fields (security-nonce-audit.md).
        if (!isset($_POST['option_page'])) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php + settings_fields (security-nonce-audit.md).
        $option_page = sanitize_key(wp_unslash((string) $_POST['option_page']));

        if (self::SETTINGS_GROUP !== $option_page) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php + settings_fields (security-nonce-audit.md).
        if (!isset($_POST[self::OPTION_STORAGE_ENABLED])) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php + settings_fields (security-nonce-audit.md).
        return !rest_sanitize_boolean(wp_unslash($_POST[self::OPTION_STORAGE_ENABLED]));
    }

    /**
     * Return whether a dependent Stream control was intentionally omitted.
     *
     * @param string $option_name Option key.
     * @return bool
     */
    private function shouldPreserveOmittedStreamOption($option_name) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php + settings_fields (security-nonce-audit.md).
        if (isset($_POST[$option_name])) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php + settings_fields (security-nonce-audit.md).
        if (!isset($_POST['option_page'])) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php + settings_fields (security-nonce-audit.md).
        $option_page = sanitize_key(wp_unslash((string) $_POST['option_page']));

        if (self::SETTINGS_GROUP !== $option_page) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php + settings_fields (security-nonce-audit.md).
        if (!isset($_POST[self::OPTION_STREAM_ENABLED])) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php + settings_fields (security-nonce-audit.md).
        return !rest_sanitize_boolean(wp_unslash($_POST[self::OPTION_STREAM_ENABLED]));
    }

    /**
     * Preserve stored encrypted values when the submitted field is blank.
     *
     * @param string $option_name Option key.
     * @param mixed  $value       Submitted option value.
     * @return string
     */
    private function sanitizeEncryptedSetting($option_name, $value) {
        $value = trim(wp_unslash((string) $value));

        if ('' === $value) {
            return (string) get_option($option_name, '');
        }

        // WordPress may invoke the sanitizer twice on one save (plaintext, then ciphertext).
        // Re-encrypting ciphertext corrupts the option; pass through our own blob unchanged.
        if ($this->isRoundTripEncryptedApiKeyPayload($value)) {
            return $value;
        }

        return BunnyConfigurationStore::encrypt_api_key($value);
    }

    /**
     * Whether a string is ciphertext produced by encrypt_api_key (decrypt + re-encrypt round-trip).
     *
     * @param string $value Trimmed candidate value.
     * @return bool
     */
    private function isRoundTripEncryptedApiKeyPayload($value) {
        if ('' === $value) {
            return false;
        }
        $plain = BunnyConfigurationStore::decrypt_api_key($value);
        if ('' === $plain) {
            return false;
        }

        return BunnyConfigurationStore::encrypt_api_key($plain) === $value;
    }

    /**
     * Render the Stream section copy.
     *
     * @return void
     */
    public function renderStreamSectionDescription() {
        $allowed = [
            'a' => [
                'href'   => true,
                'target' => true,
                'rel'    => true,
            ],
            'strong' => [],
        ];

        echo '<p>';
        echo wp_kses(
            sprintf(
                /* translators: 1: Bunny Stream documentation URL, 2: Bunny dashboard Stream URL */
                __('Use <a href="%1$s" target="_blank" rel="noopener noreferrer">Bunny Stream</a> for adaptive video streaming and delivery. In the Bunny dashboard, navigate to the <a href="%2$s" target="_blank" rel="noopener noreferrer">Stream</a> section to create and manage your video libraries. A library stores, encodes, and streams your videos on bunny.net’s network. Once created, navigate to your library’s <strong>API tab</strong> to locate the settings needed below.', 'indigetal-media-offload-for-bunny-net'),
                esc_url('https://docs.bunny.net/stream/index#bunny-stream'),
                esc_url('https://dash.bunny.net/stream')
            ),
            $allowed
        );
        echo '</p>';
    }

    /**
     * Render the Storage section copy.
     *
     * @return void
     */
    public function renderStorageSectionDescription() {
        $allowed = [
            'a' => [
                'href'   => true,
                'target' => true,
                'rel'    => true,
            ],
            'strong' => [],
        ];

        echo '<p>';
        echo wp_kses(
            sprintf(
                /* translators: 1: Bunny Storage documentation URL, 2: Bunny dashboard Storage URL */
                __('<a href="%1$s" target="_blank" rel="noopener noreferrer">Bunny Storage</a> lets you store and manage your files on bunny.net’s global network. In the Bunny dashboard, navigate to the <a href="%2$s" target="_blank" rel="noopener noreferrer">Storage</a> section to create and manage your Storage Zone. If you did not create/connect a Pull Zone when you created your Storage Zone, click <strong>Connect Pull Zone</strong> while viewing your Storage Zone, or open the <strong>Connected pull zones</strong> tab.', 'indigetal-media-offload-for-bunny-net'),
                esc_url('https://docs.bunny.net/storage'),
                esc_url('https://dash.bunny.net/storage')
            ),
            $allowed
        );
        echo '</p>';
    }

    /**
     * Render the Stream API Key field.
     */
    public function renderAccessKeyField() {
        $value = esc_attr(BunnyConfigurationStore::decrypt_api_key(get_option(self::OPTION_ACCESS_KEY, '')));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr / static attributes; see security-escape-audit.md.
        echo "<input type='password' id='" . esc_attr(self::OPTION_ACCESS_KEY) . "' name='" . esc_attr(self::OPTION_ACCESS_KEY) . "' value='" . $value . "' class='regular-text' autocomplete='off'" . $this->getStreamDependentControlAttributes() . ' />';

        $allowed = [
            'a' => [
                'href'   => true,
                'target' => true,
                'rel'    => true,
            ],
            'strong' => [],
        ];

        echo '<p class="description">';
        echo wp_kses(
            sprintf(
                /* translators: 1: Bunny dashboard Stream URL, 2: Bunny support article URL */
                __('Copy from your library’s <strong>API</strong> tab in the Bunny dashboard (<a href="%1$s" target="_blank" rel="noopener noreferrer">Stream</a> → your library → API). See <a href="%2$s" target="_blank" rel="noopener noreferrer">How to find your Stream API key</a>.', 'indigetal-media-offload-for-bunny-net'),
                esc_url('https://dash.bunny.net/stream'),
                esc_url('https://support.bunny.net/hc/en-us/articles/13503339878684-How-to-find-your-stream-API-key')
            ),
            $allowed
        );
        echo '</p>';
    }

    /**
     * Render the Video library ID field.
     */
    public function renderLibraryIdField() {
        $library_id = esc_attr(BunnyConfigurationStore::getStreamLibraryId());
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr / static attributes; see security-escape-audit.md.
        echo "<input type='text' id='" . esc_attr(self::OPTION_LIBRARY_ID) . "' name='" . esc_attr(self::OPTION_LIBRARY_ID) . "' value='" . $library_id . "' class='regular-text'" . $this->getStreamDependentControlAttributes() . ' />';

        echo '<p class="description">';
        echo esc_html__('Must belong to the same Stream library as your Stream API Key.', 'indigetal-media-offload-for-bunny-net');
        echo '</p>';
    }   
    
    /**
     * Render the CDN hostname field.
     */
    public function renderPullZoneField() {
        $pull_zone = esc_attr(get_option(self::OPTION_PULL_ZONE, ''));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr / static attributes; see security-escape-audit.md.
        echo "<input type='text' id='" . esc_attr(self::OPTION_PULL_ZONE) . "' name='" . esc_attr(self::OPTION_PULL_ZONE) . "' value='" . $pull_zone . "' class='regular-text'" . $this->getStreamDependentControlAttributes() . ' />';

        echo '<p class="description">';
        echo wp_kses(
            __('Example: <code>your-stream-zone.b-cdn.net</code>.', 'indigetal-media-offload-for-bunny-net'),
            ['code' => []]
        );
        echo '</p>';
    }

    /**
     * Render the Stream upload master toggle.
     */
    public function renderStreamEnabledField() {
        $stream_enabled = BunnyConfigurationStore::isStreamEnabled();

        echo '<input type="hidden" name="' . esc_attr(self::OPTION_STREAM_ENABLED) . '" value="0" />';
        echo '<label class="bmo-toggle">';
        echo '<input class="bmo-toggle__input" type="checkbox" role="switch" id="' . esc_attr(self::OPTION_STREAM_ENABLED) . '" name="' . esc_attr(self::OPTION_STREAM_ENABLED) . '" value="1" autocomplete="off" aria-expanded="' . esc_attr($stream_enabled ? 'true' : 'false') . '" ' . checked($stream_enabled, true, false) . ' />';
        echo '<span class="bmo-toggle__track" aria-hidden="true"><span class="bmo-toggle__thumb"></span></span>';
        echo '<span class="bmo-toggle__text"><span class="bmo-toggle__state bmo-toggle__state--on">' . esc_html__('Enabled', 'indigetal-media-offload-for-bunny-net') . '</span><span class="bmo-toggle__state bmo-toggle__state--off">' . esc_html__('Disabled', 'indigetal-media-offload-for-bunny-net') . '</span></span>';
        echo '</label>';
        echo '<p class="description">';
        echo esc_html__('Enable to reveal Stream credentials and delivery settings for Bunny Stream. Disabling stops future offloads but keeps saved Stream settings and existing Stream-backed videos available.', 'indigetal-media-offload-for-bunny-net');
        echo '</p>';
    }

    /**
     * Return attributes for controls that belong to the conditional Stream group.
     *
     * @return string
     */
    private function getStreamDependentControlAttributes() {
        $attributes = ' data-bmo-stream-dependent-control="1"';

        if ($this->stream_dependent_controls_disabled) {
            $attributes .= ' disabled="disabled"';
        }

        return $attributes;
    }

    /**
     * Render the post-Stream-upload local video removal toggle.
     */
    public function renderRemoveLocalVideoFilesField() {
        $dependent_attributes = $this->getStreamDependentControlAttributes();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr / static attributes; see security-escape-audit.md.
        echo '<input type="hidden" name="' . esc_attr(self::OPTION_REMOVE_LOCAL_VIDEO_FILES) . '" value="0"' . $dependent_attributes . ' />';
        echo '<label class="bmo-toggle">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr / static attributes; see security-escape-audit.md.
        echo '<input class="bmo-toggle__input" type="checkbox" role="switch" id="' . esc_attr(self::OPTION_REMOVE_LOCAL_VIDEO_FILES) . '" name="' . esc_attr(self::OPTION_REMOVE_LOCAL_VIDEO_FILES) . '" value="1" autocomplete="off" ' . checked(BunnyConfigurationStore::shouldRemoveLocalVideoFiles(), true, false) . $dependent_attributes . ' />';
        echo '<span class="bmo-toggle__track" aria-hidden="true"><span class="bmo-toggle__thumb"></span></span>';
        echo '<span class="bmo-toggle__text"><span class="bmo-toggle__state bmo-toggle__state--on">' . esc_html__('Enabled', 'indigetal-media-offload-for-bunny-net') . '</span><span class="bmo-toggle__state bmo-toggle__state--off">' . esc_html__('Disabled', 'indigetal-media-offload-for-bunny-net') . '</span></span>';
        echo '</label>';
        echo '<p class="description">';
        echo esc_html__('When enabled, local video files are removed from this server after successful Bunny Stream upload, making Bunny Stream the remaining playback copy for those videos. Disable to keep local backup video files on the server while still serving offloaded video.', 'indigetal-media-offload-for-bunny-net');
        echo '</p>';
    }

    /**
     * Render the Storage offload master toggle.
     */
    public function renderStorageEnabledField() {
        $storage_enabled = BunnyConfigurationStore::isStorageOffloadEnabled();

        echo '<input type="hidden" name="' . esc_attr(self::OPTION_STORAGE_ENABLED) . '" value="0" />';
        echo '<label class="bmo-toggle">';
        echo '<input class="bmo-toggle__input" type="checkbox" role="switch" id="' . esc_attr(self::OPTION_STORAGE_ENABLED) . '" name="' . esc_attr(self::OPTION_STORAGE_ENABLED) . '" value="1" autocomplete="off" aria-expanded="' . esc_attr($storage_enabled ? 'true' : 'false') . '" ' . checked($storage_enabled, true, false) . ' />';
        echo '<span class="bmo-toggle__track" aria-hidden="true"><span class="bmo-toggle__thumb"></span></span>';
        echo '<span class="bmo-toggle__text"><span class="bmo-toggle__state bmo-toggle__state--on">' . esc_html__('Enabled', 'indigetal-media-offload-for-bunny-net') . '</span><span class="bmo-toggle__state bmo-toggle__state--off">' . esc_html__('Disabled', 'indigetal-media-offload-for-bunny-net') . '</span></span>';
        echo '</label>';
        echo '<p class="description">';
        echo esc_html__('Enable to reveal Storage credentials and delivery settings for Bunny Storage offload. Disabling stops future offloads but keeps saved Storage settings and already-offloaded files.', 'indigetal-media-offload-for-bunny-net');
        echo '</p>';
    }

    /**
     * Return attributes for controls that belong to the conditional Storage group.
     *
     * @return string
     */
    private function getStorageDependentControlAttributes() {
        $attributes = ' data-bmo-storage-dependent-control="1"';

        if ($this->storage_dependent_controls_disabled) {
            $attributes .= ' disabled="disabled"';
        }

        return $attributes;
    }

    /**
     * Render the post-offload local file removal toggle.
     */
    public function renderRemoveLocalFilesField() {
        $dependent_attributes = $this->getStorageDependentControlAttributes();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr / static attributes; see security-escape-audit.md.
        echo '<input type="hidden" name="' . esc_attr(self::OPTION_REMOVE_LOCAL_FILES) . '" value="0"' . $dependent_attributes . ' />';
        echo '<label class="bmo-toggle">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr / static attributes; see security-escape-audit.md.
        echo '<input class="bmo-toggle__input" type="checkbox" role="switch" id="' . esc_attr(self::OPTION_REMOVE_LOCAL_FILES) . '" name="' . esc_attr(self::OPTION_REMOVE_LOCAL_FILES) . '" value="1" autocomplete="off" ' . checked(BunnyConfigurationStore::shouldRemoveLocalFiles(), true, false) . $dependent_attributes . ' />';
        echo '<span class="bmo-toggle__track" aria-hidden="true"><span class="bmo-toggle__thumb"></span></span>';
        echo '<span class="bmo-toggle__text"><span class="bmo-toggle__state bmo-toggle__state--on">' . esc_html__('Enabled', 'indigetal-media-offload-for-bunny-net') . '</span><span class="bmo-toggle__state bmo-toggle__state--off">' . esc_html__('Disabled', 'indigetal-media-offload-for-bunny-net') . '</span></span>';
        echo '</label>';
        echo '<p class="description">';
        echo esc_html__('When enabled, local originals and generated files are removed from this server after successful Bunny Storage upload. Disable to keep local backup files while still serving complete offloaded media from Bunny.', 'indigetal-media-offload-for-bunny-net');
        echo '</p>';
    }

    /**
     * Render the Storage zone field.
     */
    public function renderStorageZoneField() {
        $storage_zone = esc_attr(get_option(self::OPTION_STORAGE_ZONE, ''));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr / static attributes; see security-escape-audit.md.
        echo "<input type='text' id='" . esc_attr(self::OPTION_STORAGE_ZONE) . "' name='" . esc_attr(self::OPTION_STORAGE_ZONE) . "' value='" . $storage_zone . "' class='regular-text'" . $this->getStorageDependentControlAttributes() . ' />';
        echo '<p class="description">';
        echo esc_html__('Enter the Bunny Storage Zone name exactly as it appears in the Bunny dashboard.', 'indigetal-media-offload-for-bunny-net');
        echo '</p>';
    }

    /**
     * Render the Storage region field.
     */
    public function renderStorageRegionField() {
        $current_region = get_option(self::OPTION_STORAGE_REGION, '');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr / static attributes; see security-escape-audit.md.
        echo "<select id='" . esc_attr(self::OPTION_STORAGE_REGION) . "' name='" . esc_attr(self::OPTION_STORAGE_REGION) . "'" . $this->getStorageDependentControlAttributes() . '>';
        echo "<option value=''>" . esc_html__('Select a storage region', 'indigetal-media-offload-for-bunny-net') . '</option>';

        foreach (self::STORAGE_REGIONS as $region_code => $region_label) {
            printf(
                "<option value='%s' %s>%s</option>",
                esc_attr($region_code),
                selected($current_region, $region_code, false),
                esc_html(sprintf('%s (%s)', $region_label, $this->getStorageRegionHostname($region_code)))
            );
        }

        echo '</select>';
        echo '<p class="description">';
        echo esc_html__('Select the Storage Zone\'s main storage region. This should match the zone\'s primary region and its Storage hostname family, such as ny.storage.bunnycdn.com for New York.', 'indigetal-media-offload-for-bunny-net');
        echo '</p>';
    }

    /**
     * Render the Storage password field.
     */
    public function renderStoragePasswordField() {
        $value = esc_attr(BunnyConfigurationStore::getStoragePassword());
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr / static attributes; see security-escape-audit.md.
        echo "<input type='password' id='" . esc_attr(self::OPTION_STORAGE_PASSWORD) . "' name='" . esc_attr(self::OPTION_STORAGE_PASSWORD) . "' value='" . $value . "' class='regular-text' autocomplete='off'" . $this->getStorageDependentControlAttributes() . ' />';
        echo '<p class="description">';
        echo wp_kses(
            sprintf(
                /* translators: %s: Bunny Storage dashboard URL */
                __('Use the Password from <a href="%s" target="_blank" rel="noopener noreferrer">Storage</a> → your storage zone → Access → API / HTTP. Do not use the read-only password.', 'indigetal-media-offload-for-bunny-net'),
                esc_url('https://dash.bunny.net/storage')
            ),
            [
                'a' => ['href' => true, 'target' => true, 'rel' => true],
            ]
        );
        echo '</p>';
    }

    /**
     * Render the Storage delivery Pull Zone field.
     */
    public function renderStoragePullZoneField() {
        $pull_zone = esc_attr(get_option(self::OPTION_STORAGE_PULL_ZONE, ''));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr / static attributes; see security-escape-audit.md.
        echo "<input type='text' id='" . esc_attr(self::OPTION_STORAGE_PULL_ZONE) . "' name='" . esc_attr(self::OPTION_STORAGE_PULL_ZONE) . "' value='" . $pull_zone . "' class='regular-text'" . $this->getStorageDependentControlAttributes() . ' />';
        echo '<p class="description">';
        echo wp_kses(
            sprintf(
                /* translators: %s: Bunny CDN dashboard URL */
                __('Copy the hostname of the Pull Zone connected to your Storage Zone from <a href="%s" target="_blank" rel="noopener noreferrer">CDN</a> → the connected pull zone → Linked hostname, such as <code>your-storage-zone.b-cdn.net</code>. Do not enter the raw Storage hostname like <code>ny.storage.bunnycdn.com</code> here.', 'indigetal-media-offload-for-bunny-net'),
                esc_url('https://dash.bunny.net/cdn')
            ),
            [
                'a' => ['href' => true, 'target' => true, 'rel' => true],
                'code' => [],
            ]
        );
        echo '</p>';
    }

    /**
     * Render Settings tab fields in Bunny-style sections (options.php save flow unchanged).
     *
     * @return void
     */
    private function renderSettingsForm() {
        /**
         * Fires before the Stream settings section on the Media admin screen (inside the same `options.php` form).
         *
         * Pro add-ons use this to render the Bunny.net Account (Account API key) block.
         */
        do_action('indigetal_offload_render_settings_before_stream_section');

        $stream_section_class = BunnyConfigurationStore::isStreamEnabled()
            ? 'bmo-section bmo-section--stream bmo-section--stream-enabled'
            : 'bmo-section bmo-section--stream bmo-section--stream-off';

        echo '<section class="' . esc_attr($stream_section_class) . '" data-bmo-stream-section="1">';
        echo '<header>';
        echo '<h2>' . esc_html__('Stream', 'indigetal-media-offload-for-bunny-net') . '</h2>';
        $this->renderStreamSectionDescription();
        echo '</header>';
        echo '<ul class="bmo-section__rows">';
        $this->renderBmoFieldRow(
            self::OPTION_STREAM_ENABLED,
            __('Enable Stream', 'indigetal-media-offload-for-bunny-net'),
            [$this, 'renderStreamEnabledField']
        );

        $stream_enabled = BunnyConfigurationStore::isStreamEnabled();
        $stream_dependent_row_class = 'bmo-stream-dependent-field';
        $stream_dependent_row_attributes = [
            'data-bmo-stream-dependent-field' => '1',
            'aria-hidden'                     => $stream_enabled ? 'false' : 'true',
        ];

        if (!$stream_enabled) {
            $stream_dependent_row_attributes['hidden'] = true;
        }

        $this->stream_dependent_controls_disabled = !$stream_enabled;

        $this->renderBmoFieldRow(
            self::OPTION_REMOVE_LOCAL_VIDEO_FILES,
            __('Remove local video files', 'indigetal-media-offload-for-bunny-net'),
            [$this, 'renderRemoveLocalVideoFilesField'],
            $stream_dependent_row_class,
            $stream_dependent_row_attributes
        );
        $this->renderBmoFieldRow(
            self::OPTION_ACCESS_KEY,
            __('Stream API Key', 'indigetal-media-offload-for-bunny-net'),
            [$this, 'renderAccessKeyField'],
            $stream_dependent_row_class,
            $stream_dependent_row_attributes
        );
        $this->renderBmoFieldRow(
            self::OPTION_LIBRARY_ID,
            __('Video library ID', 'indigetal-media-offload-for-bunny-net'),
            [$this, 'renderLibraryIdField'],
            $stream_dependent_row_class,
            $stream_dependent_row_attributes
        );
        $this->renderBmoFieldRow(
            self::OPTION_PULL_ZONE,
            __('CDN hostname', 'indigetal-media-offload-for-bunny-net'),
            [$this, 'renderPullZoneField'],
            $stream_dependent_row_class,
            $stream_dependent_row_attributes
        );
        $this->stream_dependent_controls_disabled = false;
        echo '</ul></section>';

        $storage_section_class = BunnyConfigurationStore::isStorageOffloadEnabled()
            ? 'bmo-section bmo-section--storage bmo-section--storage-enabled'
            : 'bmo-section bmo-section--storage bmo-section--storage-off';

        echo '<section class="' . esc_attr($storage_section_class) . '" data-bmo-storage-section="1">';
        echo '<header>';
        echo '<h2>' . esc_html__('Storage', 'indigetal-media-offload-for-bunny-net') . '</h2>';
        $this->renderStorageSectionDescription();
        echo '</header>';
        echo '<ul class="bmo-section__rows">';
        $this->renderBmoFieldRow(
            self::OPTION_STORAGE_ENABLED,
            __('Enable Storage Offload', 'indigetal-media-offload-for-bunny-net'),
            [$this, 'renderStorageEnabledField']
        );

        $storage_enabled = BunnyConfigurationStore::isStorageOffloadEnabled();
        $storage_dependent_row_class = 'bmo-storage-dependent-field';
        $storage_dependent_row_attributes = [
            'data-bmo-storage-dependent-field' => '1',
            'aria-hidden'                      => $storage_enabled ? 'false' : 'true',
        ];

        if (!$storage_enabled) {
            $storage_dependent_row_attributes['hidden'] = true;
        }

        $this->storage_dependent_controls_disabled = !$storage_enabled;

        $this->renderBmoFieldRow(
            self::OPTION_REMOVE_LOCAL_FILES,
            __('Remove Local files', 'indigetal-media-offload-for-bunny-net'),
            [$this, 'renderRemoveLocalFilesField'],
            $storage_dependent_row_class,
            $storage_dependent_row_attributes
        );
        $this->renderBmoFieldRow(
            self::OPTION_STORAGE_ZONE,
            __('Storage zone name', 'indigetal-media-offload-for-bunny-net'),
            [$this, 'renderStorageZoneField'],
            $storage_dependent_row_class,
            $storage_dependent_row_attributes
        );
        $this->renderBmoFieldRow(
            self::OPTION_STORAGE_REGION,
            __('Main storage region', 'indigetal-media-offload-for-bunny-net'),
            [$this, 'renderStorageRegionField'],
            $storage_dependent_row_class,
            $storage_dependent_row_attributes
        );
        $this->renderBmoFieldRow(
            self::OPTION_STORAGE_PASSWORD,
            __('Storage zone password', 'indigetal-media-offload-for-bunny-net'),
            [$this, 'renderStoragePasswordField'],
            $storage_dependent_row_class,
            $storage_dependent_row_attributes
        );
        $this->renderBmoFieldRow(
            self::OPTION_STORAGE_PULL_ZONE,
            __('Storage delivery Pull Zone hostname', 'indigetal-media-offload-for-bunny-net'),
            [$this, 'renderStoragePullZoneField'],
            $storage_dependent_row_class,
            $storage_dependent_row_attributes
        );
        /**
         * Fires after the Storage Pull Zone field inside the Storage `<ul>`, before dependent controls reset.
         *
         * @param bool                 $storage_enabled                    Whether Storage offload is enabled.
         * @param string               $storage_dependent_row_class        Row class for dependent fields.
         * @param array<string, mixed> $storage_dependent_row_attributes Row attributes (e.g. data-bmo-storage-dependent-field, hidden).
         */
        do_action(
            'indigetal_offload_render_settings_storage_dependent_fields',
            $storage_enabled,
            $storage_dependent_row_class,
            $storage_dependent_row_attributes
        );
        $this->storage_dependent_controls_disabled = false;
        echo '</ul></section>';

        echo '<section class="bmo-section">';
        echo '<header>';
        echo '<h2>' . esc_html__('Advanced', 'indigetal-media-offload-for-bunny-net') . '</h2>';
        echo '<p>' . esc_html__('By default, uninstalling Indigetal Media Offload for Bunny.net preserves offload-critical WordPress data: saved settings, encrypted credentials, Storage manifests, Stream attachment metadata, and URL rewriting state. You normally do not need to change anything here.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '</header>';
        echo '<div class="bmo-alert bmo-alert--warning">';
        echo '<p><strong>' . esc_html__('Warning:', 'indigetal-media-offload-for-bunny-net') . '</strong> ';
        echo esc_html__('Only enable the option below if you intend to remove plugin-owned WordPress data when the plugin is uninstalled. This does not delete local media files from your server and does not delete remote objects in Bunny Storage or Bunny Stream.', 'indigetal-media-offload-for-bunny-net');
        echo '</p>';
        echo '</div>';
        echo '<ul class="bmo-section__rows">';
        $this->renderBmoFieldRow(
            self::OPTION_DELETE_PLUGIN_DATA_ON_UNINSTALL,
            __('Remove plugin-owned WordPress data on uninstall', 'indigetal-media-offload-for-bunny-net'),
            [$this, 'renderDeletePluginDataOnUninstallField']
        );
        echo '</ul>';
        echo '</section>';
    }

    /**
     * Render the Advanced uninstall cleanup checkbox (Settings API boolean as "0"/"1").
     *
     * @return void
     */
    public function renderDeletePluginDataOnUninstallField() {
        $enabled = '1' === (string) get_option(self::OPTION_DELETE_PLUGIN_DATA_ON_UNINSTALL, '0');

        echo '<input type="hidden" name="' . esc_attr(self::OPTION_DELETE_PLUGIN_DATA_ON_UNINSTALL) . '" value="0" />';
        echo '<label class="bmo-toggle">';
        echo '<input class="bmo-toggle__input" type="checkbox" role="switch" id="' . esc_attr(self::OPTION_DELETE_PLUGIN_DATA_ON_UNINSTALL) . '" name="' . esc_attr(self::OPTION_DELETE_PLUGIN_DATA_ON_UNINSTALL) . '" value="1" autocomplete="off" ' . checked($enabled, true, false) . ' />';
        echo '<span class="bmo-toggle__track" aria-hidden="true"><span class="bmo-toggle__thumb"></span></span>';
        echo '<span class="bmo-toggle__text"><span class="bmo-toggle__state bmo-toggle__state--on">' . esc_html__('Enabled', 'indigetal-media-offload-for-bunny-net') . '</span><span class="bmo-toggle__state bmo-toggle__state--off">' . esc_html__('Disabled', 'indigetal-media-offload-for-bunny-net') . '</span></span>';
        echo '</label>';
        echo '<p class="description">';
        echo esc_html__('When enabled, uninstall removes this plugin\'s saved settings and the WordPress-stored offload data it relies on (credentials, manifests, Stream-related attachment metadata, and similar), then clears this opt-in so it does not remain after removal. When disabled, that data is kept so you can reinstall. In both cases, uninstall performs a small amount of internal cleanup so WordPress does not keep scheduled work pointing at removed plugin code. Local media files on your server and content stored at Bunny.net are never deleted by this setting.', 'indigetal-media-offload-for-bunny-net');
        echo '</p>';
    }

    /**
     * Render informational content for the About & Privacy tab (no settings form).
     * Uses the same `.bmo-settings-panel` + `.bmo-section` surface pattern as the Settings form.
     *
     * @return void
     */
    private function renderAboutPrivacyTabPanel() {
        $allowed_links = [
            'a' => [
                'href'   => true,
                'target' => true,
                'rel'    => true,
            ],
        ];

        echo '<div class="bmo-settings-panel">';

        echo '<section class="bmo-section">';
        echo '<header>';
        echo '<h2>' . esc_html__('Bunny.net external service', 'indigetal-media-offload-for-bunny-net') . '</h2>';
        echo '<p>';
        echo wp_kses(
            sprintf(
                /* translators: 1: Bunny terms URL, 2: Bunny privacy URL */
                __('Indigetal Media Offload for Bunny.net sends media and configuration-related data to <strong>Bunny.net</strong> only when you enable Stream and/or Storage features and use the workflows this plugin provides. Review Bunny.net’s <a href="%1$s" target="_blank" rel="noopener noreferrer">Terms of Service</a> and <a href="%2$s" target="_blank" rel="noopener noreferrer">privacy policy</a> before enabling offload.', 'indigetal-media-offload-for-bunny-net'),
                esc_url('https://bunny.net/tos/'),
                esc_url('https://bunny.net/privacy/')
            ),
            array_merge($allowed_links, ['strong' => []])
        );
        echo '</p>';
        echo '</header>';
        echo '</section>';

        echo '<section class="bmo-section">';
        echo '<header>';
        echo '<h2>' . esc_html__('Data sent to Bunny.net', 'indigetal-media-offload-for-bunny-net') . '</h2>';
        echo '<p>' . esc_html__('Depending on what you enable, the plugin may send the following kinds of data to Bunny.net:', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '</header>';
        echo '<div class="bmo-section__content">';
        echo '<p>' . esc_html__('Stream (when enabled): the server makes requests to Bunny Stream API hosts such as video.bunnycdn.com to create, update, inspect, and delete videos and collections. Playback-related URLs may use hostnames such as player.mediadelivery.net and the Stream Pull Zone hostname you configure in settings.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '<p>' . esc_html__('Storage (when enabled): the server makes requests to the regional Bunny Storage API host for the zone you select, and public file URLs use the Storage Pull Zone hostname you configure.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '<p>' . esc_html__('API keys, passwords, zone identifiers, and related options are stored in the WordPress database (encrypted where this plugin applies encryption) and are used only to contact Bunny services on your behalf.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '</div>';
        echo '</section>';

        echo '<section class="bmo-section">';
        echo '<header>';
        echo '<h2>' . esc_html__('Admin help links', 'indigetal-media-offload-for-bunny-net') . '</h2>';
        echo '<p>' . esc_html__('On the Settings tab, help text may link to Bunny.net documentation on docs.bunny.net, the customer dashboard on dash.bunny.net, a support article on support.bunny.net, and CDN product pages used to explain pull-zone hostnames. Opening those links is optional; they remain under Bunny.net’s terms and privacy policy linked above.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '</header>';
        echo '</section>';

        echo '<section class="bmo-section">';
        echo '<header>';
        echo '<h2>' . esc_html__('Delivery scope in Free', 'indigetal-media-offload-for-bunny-net') . '</h2>';
        echo '<p>' . esc_html__('This Free release does not generate HMAC-signed or token-authenticated CDN URLs from PHP for Storage or Stream.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '<p>' . esc_html__('This Free release does not include operator Tools tabs, bulk batch or retry queues, a block-based Stream upload experience, or resumable chunked video uploads.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '<p>' . esc_html__('Whether URLs are publicly readable or restricted is determined by your Bunny Pull Zone, Storage, and Stream configuration outside WordPress.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '</header>';
        echo '</section>';

        echo '<section class="bmo-section">';
        echo '<header>';
        echo '<h2>' . esc_html__('Local media files after offload', 'indigetal-media-offload-for-bunny-net') . '</h2>';
        echo '<p>' . esc_html__('On the Settings tab, separate toggles control whether local media files are removed from this server after successful Storage offload and after successful Stream video offload. When a toggle is enabled, WordPress may no longer have a local file behind the attachment URL even though delivery continues from Bunny.net.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '</header>';
        echo '</section>';

        echo '<section class="bmo-section">';
        echo '<header>';
        echo '<h2>' . esc_html__('Remote Bunny objects when you delete attachments', 'indigetal-media-offload-for-bunny-net') . '</h2>';
        echo '<p>' . esc_html__('Deleting a WordPress attachment for media that was offloaded to Bunny Storage or Bunny Stream may delete the corresponding remote Bunny object where that behavior is implemented, so visitors should not retain a working hotlink to the removed object.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '</header>';
        echo '</section>';

        echo '<section class="bmo-section">';
        echo '<header>';
        echo '<h2>' . esc_html__('Uninstall and data retention', 'indigetal-media-offload-for-bunny-net') . '</h2>';
        echo '<p>' . esc_html__('By default, uninstall keeps this plugin’s settings, saved credentials, offload records, and media-related metadata in WordPress so you can reinstall and keep using media already stored at Bunny.net.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '<p>' . esc_html__('Unless you turn on Advanced → Remove plugin-owned WordPress data on uninstall on the Settings tab (and only if you intend to wipe that data), nothing in that list is removed on uninstall.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '<p>' . esc_html__('Uninstall never deletes your site’s media files on disk or removes objects in your Bunny Storage zones or Stream library.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '<p>' . esc_html__('When that Advanced option is enabled, uninstall removes this plugin’s saved settings and the WordPress-stored offload data it relies on, then clears the opt-in so it does not remain after removal; local media files and Bunny.net objects are still never deleted by the plugin.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '<p>' . esc_html__('A small amount of internal cleanup always runs on uninstall so WordPress does not keep scheduled work pointing at removed plugin code.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '</header>';
        echo '</section>';

        echo '<section class="bmo-section">';
        echo '<header>';
        echo '<h2>' . esc_html__('Optional Pro companion', 'indigetal-media-offload-for-bunny-net') . '</h2>';
        echo '<p>';
        echo wp_kses(
            sprintf(
                /* translators: %s: URL to author / Pro addon commercial information. */
                __(
                    'A Pro addon is available to extend this Free base plugin with additional operator workflows and delivery features—such as token-compatible URL delivery. For availability and feature details, visit <a href="%s" target="_blank" rel="noopener noreferrer">Indigetal WebCraft</a>.',
                    'indigetal-media-offload-for-bunny-net'
                ),
                esc_url(self::PRO_COMPANION_INFO_URL)
            ),
            $allowed_links
        );
        echo '</p>';
        echo '<p>' . esc_html__('This Free plugin does not bundle that companion, surface off–WordPress.org update prompts for it in wp-admin, or require it for core Media Library Storage and Stream offload.', 'indigetal-media-offload-for-bunny-net') . '</p>';
        echo '</header>';
        echo '</section>';

        echo '<section class="bmo-section">';
        echo '<header>';
        echo '<h2>' . esc_html__('Create a Bunny.net account', 'indigetal-media-offload-for-bunny-net') . '</h2>';
        echo '<p>';
        echo wp_kses(
            sprintf(
                /* translators: 1: Affiliate signup URL (bunny.net with ref), 2: Bunny affiliate program documentation URL */
                __(
                    'If you need a Bunny.net account, you can <a href="%1$s" target="_blank" rel="noopener noreferrer sponsored">open bunny.net with this affiliate link</a>. This is an affiliate link; if you become a paying customer, we may earn a commission at no extra cost to you. Bunny publishes its affiliate rules in the <a href="%2$s" target="_blank" rel="noopener noreferrer">affiliate program documentation</a>.',
                    'indigetal-media-offload-for-bunny-net'
                ),
                esc_url(self::BUNNY_NET_AFFILIATE_URL),
                esc_url('https://docs.bunny.net/billing/affiliate-program')
            ),
            $allowed_links
        );
        echo '</p>';
        echo '</header>';
        echo '</section>';

        echo '</div>';
    }

    /**
     * One split row: visible label (for/id) + field markup from existing render callbacks.
     *
     * @param string               $control_id     Option key; must match the control element's id attribute.
     * @param string               $label_text     Translated field label.
     * @param callable             $callback       Callable that echoes input/select and helper paragraphs.
     * @param string               $row_class      Optional additional row class.
     * @param array<string, mixed> $row_attributes Optional row attributes.
     * @return void
     */
    private function renderBmoFieldRow($control_id, $label_text, callable $callback, $row_class = '', array $row_attributes = []) {
        $class_name = 'bmo-section--split';

        if ('' !== $row_class) {
            $class_name .= ' ' . sanitize_html_class($row_class);
        }

        echo '<li class="' . esc_attr($class_name) . '"';
        foreach ($row_attributes as $attribute_name => $attribute_value) {
            $attribute_name = sanitize_key((string) $attribute_name);

            if ('' === $attribute_name) {
                continue;
            }

            if (true === $attribute_value) {
                echo ' ' . esc_attr($attribute_name);
                continue;
            }

            echo ' ' . esc_attr($attribute_name) . '="' . esc_attr((string) $attribute_value) . '"';
        }
        echo '>';
        echo '<label class="bmo-section__title" for="' . esc_attr($control_id) . '">';
        echo esc_html($label_text);
        echo '</label>';
        echo '<div class="bmo-section__content">';
        call_user_func($callback);
        echo '</div>';
        echo '</li>';
    }

    /**
     * Render the settings page.
     */
    public function renderSettingsPage() {
        $tabs = self::getAdminTabs();
        $allowed_slugs = array_keys($tabs);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab routing; allowlisted slug (security-nonce-audit.md).
        $requested = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : self::TAB_SETTINGS;
        $active_tab = in_array($requested, $allowed_slugs, true)
            ? $requested
            : self::TAB_SETTINGS;

        echo '<div class="wrap"><div id="indigetal-offload-admin-wrapper">';
        echo '<header class="bmo-admin-header">';
        echo '<h1 class="bmo-admin-header__brand">';
        echo '<img src="' . esc_url(self::pluginAssetUrl('assets/indigetal-offload-logo-type.svg')) . '" alt="' . esc_attr__('Indigetal Media Offload', 'indigetal-media-offload-for-bunny-net') . '" width="308" height="58" decoding="async">';
        echo '</h1>';
        echo '</header>';
        self::renderAdminTabs($active_tab);

        /**
         * After nav tabs; use `$active_tab` to scope notices or secondary panels. Does not replace tab bodies.
         *
         * @param string $active_tab Active tab slug.
         */
        do_action('indigetal_offload_admin_panels', $active_tab);

        if (self::TAB_ABOUT_PRIVACY === $active_tab) {
            $this->renderAboutPrivacyTabPanel();
            echo '</div></div>';

            return;
        }

        if (self::TAB_SETTINGS !== $active_tab) {
            /**
             * Render a custom admin tab body registered via `indigetal_offload_admin_tabs`.
             *
             * @param string $active_tab Active tab slug.
             */
            do_action('indigetal_offload_render_admin_tab', $active_tab);
            echo '</div></div>';

            return;
        }

        echo '<div class="bmo-settings-panel">';
        $this->renderSettingsPromoBanner();
        echo "<form action='options.php' method='post'>";
        settings_fields(self::SETTINGS_GROUP);
        $this->renderSettingsForm();
        submit_button(__('Save Settings', 'indigetal-media-offload-for-bunny-net'));
        echo '</form>';
        echo '</div>';

        /**
         * After the main Settings tab form panel; still inside `#indigetal-offload-admin-wrapper`.
         *
         * @param string $active_tab Active tab slug (here always `self::TAB_SETTINGS`).
         */
        do_action('indigetal_offload_render_settings_after_settings_panel', $active_tab);

        echo '</div></div>';
    }

    /**
     * Return the documented hostname for a storage region.
     *
     * @param string $region_code Storage region code.
     * @return string
     */
    private function getStorageRegionHostname($region_code) {
        if ('de' === $region_code) {
            return 'storage.bunnycdn.com';
        }

        return $region_code . '.storage.bunnycdn.com';
    }

}
