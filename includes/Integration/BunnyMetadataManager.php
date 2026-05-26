<?php
namespace Bunny_Offload\Integration;

use Bunny_Offload\Utils\BunnyLogger;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyMetadataManager {
    public const VIDEO_ID_META_KEY = '_indigetal_offload_video_id';
    public const IFRAME_URL_META_KEY = '_indigetal_offload_iframe_url';
    public const THUMBNAIL_URL_META_KEY = '_indigetal_offload_thumbnail_url';
    public const VIDEO_WIDTH_META_KEY = '_indigetal_offload_video_width';
    public const VIDEO_HEIGHT_META_KEY = '_indigetal_offload_video_height';
    public const COLLECTION_ID_META_KEY = '_indigetal_offload_collection_id';

    private static function buildAttachmentMetaArgs($description, $type = 'string', $show_in_rest = true, $extra_args = []) {
        if (is_array($show_in_rest)) {
            $extra_args   = $show_in_rest;
            $show_in_rest = true;
        }

        return array_merge([
            'type'          => $type,
            'description'   => $description,
            'single'        => true,
            'show_in_rest'  => $show_in_rest,
            'auth_callback' => function () {
                return current_user_can( 'upload_files' );
            },
        ], $extra_args);
    }

    /**
     * Build attachment meta args for Bunny-backed video metadata sync state only.
     *
     * @param string $description Base field description.
     * @param string $type        Registered meta type.
     * @param mixed  $show_in_rest REST exposure flag or extra args shortcut.
     * @param array  $extra_args   Additional meta registration args.
     * @return array<string, mixed>
     */
    private static function buildVideoSyncMetaArgs($description, $type = 'string', $show_in_rest = true, $extra_args = []) {
        return self::buildAttachmentMetaArgs(
            $description . ' For Bunny-backed video attachments only.',
            $type,
            $show_in_rest,
            $extra_args
        );
    }

    /**
     * Register all Bunny attachment meta used by the plugin.
     *
     * @return void
     */
    public static function registerAttachmentMeta() {
        $iframe_meta_args = self::buildAttachmentMetaArgs( 'Bunny Stream Embed URL' );

        register_post_meta( 'attachment', self::VIDEO_ID_META_KEY, self::buildAttachmentMetaArgs( 'Bunny Stream video GUID' ) );
        register_post_meta( 'attachment', self::IFRAME_URL_META_KEY, $iframe_meta_args );
        register_post_meta( 'post', self::IFRAME_URL_META_KEY, $iframe_meta_args );
        register_post_meta( 'page', self::IFRAME_URL_META_KEY, $iframe_meta_args );
        register_post_meta( 'attachment', self::THUMBNAIL_URL_META_KEY, self::buildAttachmentMetaArgs( 'Bunny Stream thumbnail URL' ) );
        register_post_meta( 'attachment', self::VIDEO_WIDTH_META_KEY, self::buildAttachmentMetaArgs( 'Bunny Stream video width in pixels', 'integer' ) );
        register_post_meta( 'attachment', self::VIDEO_HEIGHT_META_KEY, self::buildAttachmentMetaArgs( 'Bunny Stream video height in pixels', 'integer' ) );
        register_post_meta(
            'attachment',
            BunnyVideoMetadataSync::LAST_REMOTE_REFRESH_ATTEMPT_AT_META_KEY,
            self::buildVideoSyncMetaArgs(
                'Unix timestamp of the most recent Bunny video metadata refresh attempt.',
                'integer',
                [
                    'default' => 0,
                ]
            )
        );
        register_post_meta(
            'attachment',
            BunnyVideoMetadataSync::LAST_SUCCESSFUL_REMOTE_REFRESH_AT_META_KEY,
            self::buildVideoSyncMetaArgs(
                'Unix timestamp of the most recent successful Bunny video metadata refresh.',
                'integer',
                [
                    'default' => 0,
                ]
            )
        );
        register_post_meta(
            'attachment',
            BunnyVideoMetadataSync::TITLE_DIRTY_META_KEY,
            self::buildVideoSyncMetaArgs(
                'Whether the local WordPress title is newer than Bunny title.',
                'boolean',
                [
                    'default' => false,
                ]
            )
        );
        register_post_meta(
            'attachment',
            BunnyVideoMetadataSync::DESCRIPTION_DIRTY_META_KEY,
            self::buildVideoSyncMetaArgs(
                'Whether the local WordPress description is newer than Bunny description.',
                'boolean',
                [
                    'default' => false,
                ]
            )
        );
        register_post_meta(
            'attachment',
            BunnyVideoMetadataSync::LAST_SYNCED_TITLE_META_KEY,
            self::buildVideoSyncMetaArgs(
                'Last Bunny-aligned title baseline stored for this attachment.',
                'string',
                [
                    'default' => '',
                ]
            )
        );
        register_post_meta(
            'attachment',
            BunnyVideoMetadataSync::LAST_SYNCED_DESCRIPTION_META_KEY,
            self::buildVideoSyncMetaArgs(
                'Last Bunny-aligned description baseline stored for this attachment.',
                'string',
                [
                    'default' => '',
                ]
            )
        );
        register_post_meta(
            'attachment',
            BunnyVideoMetadataSync::TITLE_SYNC_ERROR_META_KEY,
            self::buildVideoSyncMetaArgs(
                'Last Bunny title sync error for this attachment.',
                'string',
                [
                    'default' => '',
                ]
            )
        );
        register_post_meta(
            'attachment',
            BunnyVideoMetadataSync::DESCRIPTION_SYNC_ERROR_META_KEY,
            self::buildVideoSyncMetaArgs(
                'Last Bunny description sync error for this attachment.',
                'string',
                [
                    'default' => '',
                ]
            )
        );
        register_post_meta(
            'attachment',
            BunnyAttachmentManifest::SUMMARY_META_KEY,
            self::buildAttachmentMetaArgs( 'Bunny Storage offload summary state for this attachment.' )
        );
        register_post_meta(
            'attachment',
            BunnyAttachmentManifest::MANIFEST_META_KEY,
            self::buildAttachmentMetaArgs(
                'Bunny Storage offload manifest keyed by relative upload path.',
                'object',
                [
                    'schema' => [
                        'type'                 => 'object',
                        'additionalProperties' => [
                            'type'       => 'object',
                            'properties' => [
                                'relative_path' => [
                                    'type' => 'string',
                                ],
                                'state' => [
                                    'type' => 'string',
                                ],
                                'remote_path' => [
                                    'type' => 'string',
                                ],
                                'last_error' => [
                                    'type' => 'string',
                                ],
                            ],
                        ],
                    ],
                ]
            )
        );
    }
}

// Register meta on WordPress init
add_action('init', ['Bunny_Offload\Integration\BunnyMetadataManager', 'registerAttachmentMeta']);

