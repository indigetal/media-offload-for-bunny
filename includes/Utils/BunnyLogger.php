<?php
namespace Bunny_Offload\Utils;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyLogger {
    public static function log($message, $type = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $type = self::normalizeType($type);
            $message = self::normalizeMessage($message);
            $context = self::getRuntimeContext();

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only when WP_DEBUG.
            error_log(sprintf('[BunnyOffload] [%s] [%s] %s', strtoupper($type), $context, $message));
        }
    }

    /**
     * Normalize the requested log level to a safe string.
     *
     * @param mixed $type Log level.
     * @return string
     */
    private static function normalizeType($type) {
        $type = sanitize_key((string) $type);

        return '' !== $type ? $type : 'info';
    }

    /**
     * Normalize log payloads into a human-readable string.
     *
     * @param mixed $message Log message.
     * @return string
     */
    private static function normalizeMessage($message) {
        if (is_scalar($message) || null === $message) {
            return (string) $message;
        }

        $encoded = wp_json_encode($message);

        return false !== $encoded ? $encoded : 'Unable to encode log payload.';
    }

    /**
     * Add lightweight request context so operators can distinguish CLI vs web logs.
     *
     * @return string
     */
    private static function getRuntimeContext() {
        if (defined('WP_CLI') && WP_CLI) {
            return 'cli';
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        if ('' !== $method && '' !== $uri) {
            return $method . ' ' . $uri;
        }

        return 'web';
    }
}
