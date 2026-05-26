<?php

namespace Bunny_Offload\Integration;

use Bunny_Offload\Settings\BunnyConfigurationStore;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Public Bunny Storage CDN URL rewriting for offloaded attachments (manifest + Pull Zone).
 */
class BunnyCdnUrlRewriter {
    /**
     * Request-scoped primary URL rewrites keyed by attachment ID and source URL.
     *
     * @var array<string, string>
     */
    private $primary_url_cache = [];

    /**
     * Request-scoped image candidate rewrites keyed by attachment ID and source URL.
     *
     * @var array<string, string>
     */
    private $image_candidate_url_cache = [];

    /**
     * Constructor.
     */
    public function __construct() {
        \add_filter('wp_get_attachment_url', [$this, 'filterPrimaryAttachmentUrl'], 11, 2);
        \add_filter('wp_get_attachment_image_src', [$this, 'filterAttachmentImageSrc'], 11, 4);
        \add_filter('wp_calculate_image_srcset', [$this, 'filterImageSrcset'], 11, 5);
        \add_filter('wp_prepare_attachment_for_js', [$this, 'filterAttachmentForJs'], 11, 3);
        \add_filter('wp_get_attachment_image_attributes', [$this, 'filterAttachmentImageAttributes'], 11, 3);
        \add_filter('rest_prepare_attachment', [$this, 'filterRestAttachment'], 11, 3);
    }

    /**
     * Rewrite eligible primary attachment URLs to Bunny Storage delivery URLs.
     *
     * @param string $url           Original attachment URL.
     * @param int    $attachment_id Attachment ID.
     * @return string
     */
    public function filterPrimaryAttachmentUrl($url, $attachment_id) {
        $attachment_id = \absint($attachment_id);
        $url = \is_string($url) ? $url : '';

        if ($attachment_id < 1 || '' === $url) {
            return $url;
        }

        $cache_key = $attachment_id . '|' . md5($url);

        if (isset($this->primary_url_cache[$cache_key])) {
            return $this->primary_url_cache[$cache_key];
        }

        if (!BunnyConfigurationStore::isStorageOffloadConfigured()) {
            $this->primary_url_cache[$cache_key] = $url;
            return $url;
        }

        if (!BunnyAttachmentManifest::isSupportedAttachment($attachment_id)) {
            $this->primary_url_cache[$cache_key] = $url;
            return $url;
        }

        $rewritten_url = $this->buildPrimaryAttachmentUrl($attachment_id, $url);
        $this->primary_url_cache[$cache_key] = $rewritten_url;

        return $rewritten_url;
    }

    /**
     * Rewrite eligible attachment image URLs per manifest entry.
     *
     * @param mixed $image         Attachment image data.
     * @param int   $attachment_id Attachment ID.
     * @param mixed $size          Requested size.
     * @param bool  $icon          Whether this is an icon fallback.
     * @return mixed
     */
    public function filterAttachmentImageSrc($image, $attachment_id, $size, $icon) {
        if (false === $image || !\is_array($image) || empty($image[0])) {
            return $image;
        }

        $image[0] = $this->rewriteManagedCandidateUrl($attachment_id, (string) $image[0]);

        return $image;
    }

    /**
     * Rewrite eligible srcset candidates per manifest entry.
     *
     * @param array  $sources       Srcset candidates.
     * @param array  $size_array    Requested size array.
     * @param string $image_src     Current image source.
     * @param array  $image_meta    Attachment metadata.
     * @param int    $attachment_id Attachment ID.
     * @return array
     */
    public function filterImageSrcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!\is_array($sources) || \absint($attachment_id) < 1) {
            return $sources;
        }

        foreach ($sources as $descriptor => $source) {
            if (!\is_array($source) || empty($source['url']) || !\is_string($source['url'])) {
                continue;
            }

            $sources[$descriptor]['url'] = $this->rewriteManagedCandidateUrl($attachment_id, $source['url']);
        }

        return $sources;
    }

    /**
     * Rewrite supported Media Library JS attachment payload URLs.
     *
     * @param mixed   $response   Attachment payload.
     * @param mixed   $attachment Attachment post object.
     * @param mixed   $meta       Attachment metadata.
     * @return mixed
     */
    public function filterAttachmentForJs($response, $attachment, $meta) {
        if (!\is_array($response) || !$attachment instanceof \WP_Post) {
            return $response;
        }

        $attachment_id = \absint($attachment->ID);
        if (!$this->shouldRewriteAttachment($attachment_id)) {
            return $response;
        }

        if (!empty($response['url']) && \is_string($response['url'])) {
            $response['url'] = $this->rewriteManagedCandidateUrl($attachment_id, $response['url']);
        }

        foreach (['image', 'thumb'] as $image_key) {
            if (
                isset($response[$image_key]) &&
                \is_array($response[$image_key]) &&
                !empty($response[$image_key]['src']) &&
                \is_string($response[$image_key]['src'])
            ) {
                $response[$image_key]['src'] = $this->rewriteManagedCandidateUrl($attachment_id, $response[$image_key]['src']);
            }
        }

        if (isset($response['sizes']) && \is_array($response['sizes'])) {
            foreach ($response['sizes'] as $size_name => $size_data) {
                if (!\is_array($size_data) || empty($size_data['url']) || !\is_string($size_data['url'])) {
                    continue;
                }

                $response['sizes'][$size_name]['url'] = $this->rewriteManagedCandidateUrl($attachment_id, $size_data['url']);
            }
        }

        return $response;
    }

    /**
     * Rewrite supported attachment image HTML attributes.
     *
     * @param mixed $attr       Attachment attributes.
     * @param mixed $attachment Attachment post object.
     * @param mixed $size       Requested size.
     * @return mixed
     */
    public function filterAttachmentImageAttributes($attr, $attachment, $size) {
        if (!\is_array($attr) || !$attachment instanceof \WP_Post) {
            return $attr;
        }

        $attachment_id = \absint($attachment->ID);
        if (!$this->shouldRewriteAttachment($attachment_id)) {
            return $attr;
        }

        if (!empty($attr['src']) && \is_string($attr['src'])) {
            $attr['src'] = $this->rewriteManagedCandidateUrl($attachment_id, $attr['src']);
        }

        if (!empty($attr['srcset']) && \is_string($attr['srcset'])) {
            $attr['srcset'] = $this->rewriteSrcsetString($attachment_id, $attr['srcset']);
        }

        return $attr;
    }

    /**
     * Rewrite supported attachment REST payload URLs.
     *
     * @param mixed $response REST response.
     * @param mixed $post     Attachment post object.
     * @param mixed $request  REST request.
     * @return mixed
     */
    public function filterRestAttachment($response, $post, $request) {
        if (!$response instanceof \WP_REST_Response || !$post instanceof \WP_Post) {
            return $response;
        }

        $attachment_id = \absint($post->ID);
        if (!$this->shouldRewriteAttachment($attachment_id)) {
            return $response;
        }

        $data = $response->get_data();
        if (!\is_array($data)) {
            return $response;
        }

        if (!empty($data['source_url']) && \is_string($data['source_url'])) {
            $data['source_url'] = $this->rewriteManagedCandidateUrl($attachment_id, $data['source_url']);
        }

        if (
            isset($data['media_details']) &&
            \is_array($data['media_details']) &&
            isset($data['media_details']['sizes']) &&
            \is_array($data['media_details']['sizes'])
        ) {
            foreach ($data['media_details']['sizes'] as $size_name => $size_data) {
                if (!\is_array($size_data)) {
                    continue;
                }

                if (!empty($size_data['source_url']) && \is_string($size_data['source_url'])) {
                    $data['media_details']['sizes'][$size_name]['source_url'] = $this->rewriteManagedCandidateUrl($attachment_id, $size_data['source_url']);
                }

                if ('full' === $size_name && !empty($data['source_url']) && \is_string($data['source_url'])) {
                    $path = \wp_parse_url($data['source_url'], PHP_URL_PATH);
                    $data['media_details']['sizes'][$size_name]['file'] = \is_string($path) && '' !== $path ? \basename($path) : $size_data['file'];
                }
            }
        }

        $response->set_data($data);

        return $response;
    }

    /**
     * Build the rewritten primary attachment URL when the original file is complete.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $url           Original attachment URL.
     * @return string
     */
    private function buildPrimaryAttachmentUrl($attachment_id, $url) {
        $original_file = $this->getOriginalFileSetEntry($attachment_id);

        if (empty($original_file['relative_path'])) {
            return $url;
        }

        $manifest = BunnyAttachmentManifest::getRawManifest($attachment_id);
        $relative_path = (string) $original_file['relative_path'];

        if (
            !isset($manifest[$relative_path]) ||
            BunnyAttachmentManifest::FILE_STATE_COMPLETE !== (string) ($manifest[$relative_path]['state'] ?? '')
        ) {
            return $url;
        }

        $remote_path = (string) ($manifest[$relative_path]['remote_path'] ?? '');
        $delivery_url = $this->buildStorageDeliveryUrl($url, $remote_path);

        if ('' === $delivery_url) {
            return $url;
        }

        return $this->finalizeStorageDeliveryUrl($delivery_url, $attachment_id, ['context' => 'primary']);
    }

    /**
     * Return the original-file entry from the attachment file set.
     *
     * @param int $attachment_id Attachment ID.
     * @return array<string, string>
     */
    private function getOriginalFileSetEntry($attachment_id) {
        $file_set = BunnyAttachmentManifest::buildAttachmentFileSet($attachment_id);

        foreach ($file_set as $entry) {
            if (\is_array($entry) && 'original' === (string) ($entry['role'] ?? '')) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * Rewrite a local image candidate URL when its manifest entry is complete.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $url           Candidate URL.
     * @return string
     */
    private function rewriteManagedCandidateUrl($attachment_id, $url) {
        $attachment_id = \absint($attachment_id);
        $url = \is_string($url) ? $url : '';

        if ($attachment_id < 1 || '' === $url) {
            return $url;
        }

        $cache_key = $attachment_id . '|' . md5($url);

        if (isset($this->image_candidate_url_cache[$cache_key])) {
            return $this->image_candidate_url_cache[$cache_key];
        }

        if (!BunnyConfigurationStore::isStorageOffloadConfigured() || !BunnyAttachmentManifest::isSupportedAttachment($attachment_id)) {
            $this->image_candidate_url_cache[$cache_key] = $url;
            return $url;
        }

        $relative_path = $this->getRelativeUploadPathFromLocalUrl($url);
        $local_url = '' !== $relative_path ? $this->buildLocalUploadUrl($relative_path) : '';
        $storage_host = BunnyConfigurationStore::getStoragePullZoneHostname();
        $url_host = \wp_parse_url($url, PHP_URL_HOST);
        $is_storage_candidate_url = \is_string($storage_host) &&
            '' !== $storage_host &&
            \is_string($url_host) &&
            '' !== $url_host &&
            \strtolower($storage_host) === \strtolower($url_host);
        $fallback_url = $is_storage_candidate_url && '' !== $local_url ? $local_url : $url;

        if ('' === $relative_path) {
            $this->image_candidate_url_cache[$cache_key] = $url;
            return $url;
        }

        $manifest = BunnyAttachmentManifest::getRawManifest($attachment_id);

        if (
            !isset($manifest[$relative_path]) ||
            BunnyAttachmentManifest::FILE_STATE_COMPLETE !== (string) ($manifest[$relative_path]['state'] ?? '')
        ) {
            $this->image_candidate_url_cache[$cache_key] = $fallback_url;
            return $this->image_candidate_url_cache[$cache_key];
        }

        $delivery_url = $this->buildStorageDeliveryUrl($url, (string) ($manifest[$relative_path]['remote_path'] ?? ''));

        if ('' === $delivery_url) {
            $this->image_candidate_url_cache[$cache_key] = $fallback_url;
            return $this->image_candidate_url_cache[$cache_key];
        }

        $final_url = $this->finalizeStorageDeliveryUrl($delivery_url, $attachment_id, ['context' => 'candidate']);
        $this->image_candidate_url_cache[$cache_key] = $final_url;

        return $this->image_candidate_url_cache[$cache_key];
    }

    /**
     * Filter a Storage pull-zone delivery URL before it is returned to WordPress.
     *
     * @param string               $url           Delivery URL.
     * @param int                  $attachment_id Attachment ID.
     * @param array<string, mixed> $context       Optional context (e.g. `context` => `primary`|`candidate`).
     * @return string
     */
    private function finalizeStorageDeliveryUrl($url, $attachment_id, array $context = []) {
        $url = \is_string($url) ? $url : '';
        $attachment_id = \absint($attachment_id);

        if ('' === $url || $attachment_id < 1) {
            return $url;
        }

        /**
         * Filter public Storage CDN URLs produced by Free rewriting.
         *
         * @param string               $url           URL.
         * @param int                  $attachment_id Attachment ID.
         * @param array<string, mixed> $context       Caller context.
         */
        $filtered = \apply_filters('indigetal_offload_storage_url', $url, $attachment_id, $context);

        return \is_string($filtered) && '' !== $filtered ? $filtered : $url;
    }

    /**
     * Determine whether the storage rewriter should touch an attachment.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private function shouldRewriteAttachment($attachment_id) {
        return \absint($attachment_id) > 0 &&
            BunnyConfigurationStore::isStorageOffloadConfigured() &&
            BunnyAttachmentManifest::isSupportedAttachment($attachment_id);
    }

    /**
     * Rewrite each URL candidate inside a srcset string.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $srcset        Srcset attribute value.
     * @return string
     */
    private function rewriteSrcsetString($attachment_id, $srcset) {
        $srcset = \is_string($srcset) ? $srcset : '';

        if ('' === $srcset) {
            return $srcset;
        }

        $candidates = [];
        foreach (\explode(',', $srcset) as $candidate) {
            $candidate = \trim($candidate);
            if ('' === $candidate) {
                continue;
            }

            $parts = \preg_split('/\s+/', $candidate, 2);
            $candidate_url = isset($parts[0]) ? (string) $parts[0] : '';
            $descriptor = isset($parts[1]) ? (string) $parts[1] : '';

            if ('' === $candidate_url) {
                continue;
            }

            $rewritten_url = $this->rewriteManagedCandidateUrl($attachment_id, $candidate_url);
            $candidates[] = '' !== $descriptor ? $rewritten_url . ' ' . $descriptor : $rewritten_url;
        }

        return \implode(', ', $candidates);
    }

    /**
     * Resolve a local uploads URL back to a relative uploads path.
     *
     * @param string $url Candidate URL.
     * @return string
     */
    private function getRelativeUploadPathFromLocalUrl($url) {
        $upload_dir = \wp_upload_dir();

        if (empty($upload_dir['baseurl']) || !empty($upload_dir['error'])) {
            return '';
        }

        $base_url = (string) $upload_dir['baseurl'];
        $base_host = \wp_parse_url($base_url, PHP_URL_HOST);
        $url_host = \wp_parse_url($url, PHP_URL_HOST);
        $storage_host = BunnyConfigurationStore::getStoragePullZoneHostname();

        if (
            \is_string($base_host) &&
            '' !== $base_host &&
            \is_string($url_host) &&
            '' !== $url_host &&
            \strtolower($base_host) !== \strtolower($url_host) &&
            (
                !\is_string($storage_host) ||
                '' === $storage_host ||
                \strtolower($storage_host) !== \strtolower($url_host)
            )
        ) {
            return '';
        }

        $base_path = \wp_parse_url($base_url, PHP_URL_PATH);
        $url_path = \wp_parse_url($url, PHP_URL_PATH);

        if (!\is_string($base_path) || '' === $base_path || !\is_string($url_path) || '' === $url_path) {
            return '';
        }

        $base_path = \untrailingslashit(\wp_normalize_path(\rawurldecode($base_path)));
        $url_path = \wp_normalize_path(\rawurldecode($url_path));

        if (0 !== \strpos($url_path, $base_path . '/')) {
            return '';
        }

        return \ltrim(\substr($url_path, \strlen($base_path)), '/');
    }

    /**
     * Build an unsigned Bunny Storage delivery URL for a manifest remote path.
     *
     * @param string $original_url Original attachment URL.
     * @param string $remote_path  Manifest remote path.
     * @return string
     */
    private function buildStorageDeliveryUrl($original_url, $remote_path) {
        $remote_path = \ltrim(\trim((string) $remote_path), '/');
        $host = BunnyConfigurationStore::getStoragePullZoneHostname();

        if ('' === $remote_path || '' === $host) {
            return '';
        }

        $scheme = \wp_parse_url($original_url, PHP_URL_SCHEME);
        $scheme = \is_string($scheme) && '' !== $scheme ? $scheme : 'https';

        return $scheme . '://' . $host . '/' . $remote_path;
    }

    /**
     * Build a local uploads URL from a relative uploads path.
     *
     * @param string $relative_path Relative uploads path.
     * @return string
     */
    private function buildLocalUploadUrl($relative_path) {
        $upload_dir = \wp_upload_dir();
        $base_url = isset($upload_dir['baseurl']) && \is_string($upload_dir['baseurl']) ? $upload_dir['baseurl'] : '';
        $relative_path = \ltrim(\trim((string) $relative_path), '/');

        if ('' === $base_url || '' === $relative_path || !empty($upload_dir['error'])) {
            return '';
        }

        return \trailingslashit($base_url) . $relative_path;
    }
}
