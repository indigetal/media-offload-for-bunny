=== Indigetal Media Offload for Bunny.net ===
Contributors: indigetal
Tags: bunny, media, offload, video, cdn
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress media to Bunny.net Storage and Stream; rewrite attachment URLs to your Pull Zone hostnames.

== Description ==

**Indigetal Media Offload for Bunny.net** wires Bunny.net **Storage** and **Stream** into the WordPress Media Library. This **Free** plugin is a complete Media Library offload path on its own: direct uploads, manifest-backed Storage offload, Stream video offload, thumbnail and dimension metadata when Stream reports a playable encode (with bounded background retries when needed), URL rewriting for attachment and REST surfaces, and remote delete where implemented.

**What Free includes**

- **Bunny Storage (non-video)** — Offload supported non-video attachments to your Storage zone and serve them from your **Storage Pull Zone** hostname using stored manifest paths.
- **Bunny Stream (video)** — Offload supported videos to your Stream library; playback URLs use your **Stream Pull Zone** hostname, with optional removal of local files after a successful upload.
- **Thumbnails and metadata** — Stream-backed videos can receive Bunny thumbnail and dimension metadata once encoding is playable; the plugin may schedule bounded retries until that metadata is available.
- **Remote delete** — Deleting a WordPress attachment can delete linked Storage objects (per manifest) and the remote Stream video when Stream metadata is present.
- **User collections** — Stream uploads can be grouped using per-user Bunny collections where that workflow applies.
- **About & Privacy** — In-plugin disclosure aligned with this readme: third-party hosts, credentials storage, delivery scope, plugin removal data policy, and how an optional **Pro** companion could relate to Free (**Pro** is not bundled with this package and is not activated from these screens).

**What Free does not include**

This Free plugin does **not** implement member-only or token-authenticated media delivery from PHP: no HMAC-signed CDN URLs, no Stream embed token signing, no privacy filters, and no Pull Zone token query parameters added by this codebase. It does **not** ship operator **Tools** tabs, bulk batch or retry queues, a block-based Stream upload experience, or resumable chunked video uploads—those patterns are out of scope here. Expect **public/basic** delivery from WordPress unless you add another layer—for example, a **Pro** companion obtained separately from this plugin, or a custom integration using the documented extension hooks.

Configure advanced Bunny.net edge rules (including token auth at the CDN) in the Bunny.net dashboard. If your Pull Zone requires signed or tokenized requests on every request, URLs produced by this Free plugin alone may not satisfy that edge policy until a compatible integration supplies those parameters.

== Installation ==

= Minimum Requirements =

* WordPress 6.5 or greater
* PHP 8.0 or greater
* A Bunny.net account
* **Stream (optional):** Stream API access key, library ID, Stream Pull Zone hostname, and any Stream upload toggles you use—entered on **Media → Indigetal Media Offload for Bunny.net → Settings**.
* **Storage (optional):** Storage zone credentials, region, Storage Pull Zone hostname, and related toggles when you enable non-video offload (same **Settings** screen).

= Installation Instructions =

1. **Install:** Upload `indigetal-media-offload-for-bunny-net.zip` via **Plugins → Add New → Upload Plugin**, or copy the plugin folder to `wp-content/plugins/indigetal-media-offload-for-bunny-net/` (folder name must match that path after extraction).
2. **Activate:** In **Plugins → Installed Plugins**, activate **Indigetal Media Offload for Bunny.net**.
3. **Configure:** Open **Media → Indigetal Media Offload for Bunny.net**, use the **Settings** tab to enable **Stream** and/or **Storage** offload as needed, then read **About & Privacy** for Bunny.net data use, delivery scope, and uninstall retention.
4. **Plan uninstall (optional):** Removing the plugin under **Plugins → Installed Plugins** **keeps** this plugin’s settings, encrypted credentials, Storage manifests, Stream-related metadata, and related offload data in WordPress **by default** so you can reinstall and keep serving media from Bunny.net. Turn on **Advanced → Remove plugin-owned WordPress data on uninstall** on the **Settings** tab only when you deliberately want that WordPress-side data removed on the next uninstall; it never deletes media files on your server or objects at Bunny.net.

== Development ==

Source code is available in public version control at https://github.com/indigetal/media-offload-for-bunny.git

To build the same style of installable archive this repository publishes (`indigetal-media-offload-for-bunny-net.zip`), run **`npm run package`** from a repository checkout (see **`package.json`**). That script uses **`.distignore`** when assembling the zip so development-only paths stay out of the distributed bundle, which keeps the package aligned with WordPress.org directory expectations.

== Frequently Asked Questions ==

= What is Bunny.net? =
Bunny.net operates global CDN, **Stream** (video), and **Storage** (object storage) services. This plugin uses those APIs and your Pull Zone hostnames so Media Library attachments can live on Bunny while WordPress keeps normal attachment records.

= Do I need a Bunny.net account? =
Yes. Create Stream libraries and Storage zones in the Bunny.net dashboard, then enter the credentials and hostnames this plugin requests on **Media → Indigetal Media Offload for Bunny.net → Settings**.

= Does this work on multisite? =
The plugin is intended to work when activated per site. If you network-enable it, treat behavior as site-specific unless you have tested your network layout; use separate Bunny libraries, zones, and Pull Zones per site when you need hard isolation.

= Do Stream and Storage use the same hostname? =
No. Stream playback URLs (including embed-style delivery such as `player.mediadelivery.net` where applicable) are built around your **Stream** Pull Zone hostname. Non-video Storage files use your **Storage** Pull Zone hostname. Do not point both at the same hostname unless you intentionally configure Bunny.net that way.

= Are URLs signed or private? =
Not from this Free plugin. URLs use your configured Pull Zone hostnames and paths (and Stream identifiers where applicable). See **About & Privacy** on the same Media settings screen.

= What happens to local files after offload? =
Separate toggles control optional removal of local files after successful Storage offload and after successful Stream upload. When enabled, WordPress may no longer keep a copy on disk while delivery continues from Bunny.net.

= What happens to remote Bunny objects when I delete a WordPress attachment? =
When delete-to-remote is implemented for that attachment, deleting it in WordPress can delete linked Storage objects (per manifest) and the remote Stream video. Treat attachment deletion as destructive for the linked Bunny objects.

= What happens when I deactivate the plugin? =
Scheduled plugin events are cleared; settings, credentials, and attachment metadata remain. Local files and remote Bunny objects are not bulk-deleted on deactivation.

= What happens when I uninstall the plugin? =
By default, uninstall **keeps** this plugin’s settings, saved credentials, offload records, and media-related metadata in WordPress so you can reinstall and keep using media already stored at Bunny.net. Unless you turn on **Advanced → Remove plugin-owned WordPress data on uninstall** on the Settings tab (and only if you intend to wipe that data), nothing in that list is removed on uninstall. Uninstall **never** deletes your site’s media files on disk or removes objects in your Bunny Storage zones or Stream library—clean those up in WordPress or the Bunny.net dashboard if you need to. See **About & Privacy** in the plugin settings for the same policy with a bit more detail.

= Can I edit images or regenerate thumbnails after a fully offloaded image has local files removed? =
WordPress flows that require a local original on disk may not work once aggressive local deletion has removed those files. Plan backups before enabling local removal.

= What developer hooks and APIs does Free expose? =
Indigetal Media Offload for Bunny.net documents an intentional extension surface for companion plugins (including optional Pro). Treat these symbols as a stable add-on contract across Free releases unless a release explicitly documents a breaking change.

**Lifecycle and admin UI**

* **indigetal_offload_loaded** (action) — Fires after Free registers settings, configuration storage, attachment metadata, Stream metadata sync, REST status routes, Storage URL rewriting, Storage offload, user integration, and Stream Media Library hooks. Use it to bootstrap add-on code that depends on Free services.
* **indigetal_offload_free_version()** (function) — Returns the active Free version string (same value as the `INDIGETAL_OFFLOAD_VERSION` constant). Pro companions call this for compatibility checks after Free is active. Pro must not ship code loaded from Free.
* **indigetal_offload_admin_tabs** (filter) — Receives the default tab map for **Media → Indigetal Media Offload for Bunny.net** (each entry: label and admin URL). Add-ons append tabs here and render tab bodies with the **indigetal_offload_render_admin_tab** action using the same slug keys.
* **indigetal_offload_render_admin_tab** (action) — Renders a custom admin tab body for a slug registered via **indigetal_offload_admin_tabs**.
* **indigetal_offload_admin_panels** (action) — Fires before tab content; receives the active tab slug.
* **indigetal_offload_render_settings_before_stream_section** (action) — Inject markup before the Stream settings section.
* **indigetal_offload_render_settings_storage_dependent_fields** (action) — Inject Storage-dependent settings rows.
* **indigetal_offload_render_settings_after_settings_panel** (action) — Inject markup after the main Settings panel.

**URL filters**

* **indigetal_offload_storage_url** (filter) — Adjust Storage CDN URLs at rewrite time.
* **indigetal_offload_stream_url** (filter) — Adjust Stream MP4 URLs at rewrite time.
* **indigetal_offload_attachment_manifest** (filter) — Adjust the normalized attachment manifest array before use.

**REST (breaking change for direct clients)**

* Namespace **`indigetal-offload/v1`** (breaking change from the pre-1.0.1 REST namespace). Example: `GET /wp-json/indigetal-offload/v1/stream/video-status` — Stream encode status polling (`upload_files` capability).

**Post and user meta (attachment / user records)**

* `_indigetal_offload_video_id`, `_indigetal_offload_iframe_url`, `_indigetal_offload_thumbnail_url`, `_indigetal_offload_video_width`, `_indigetal_offload_video_height` — Stream attachment meta (REST-exposed where registered).
* `_indigetal_offload_offloaded`, `_indigetal_offload_manifest`, `_indigetal_offload_last_error` — Storage offload state.
* `_indigetal_offload_collection_id` (user meta) — Bunny Stream collection GUID; remote collection **name** is `user_{userId}` via `BunnyCollectionHandler::collectionNameForUser()`.

**WP_Error codes**

Plugin-issued API, Storage, Stream, and settings validation errors use the `indigetal_offload_*` prefix (for example `indigetal_offload_storage_http_error`, `indigetal_offload_api_http_error`). Integrators should not rely on legacy `bunny_*` error slugs.

**Read-only attachment helpers (no remote Bunny API calls)**

These five static methods are reserved for add-on tooling; Free does not call them internally. They read WordPress post/user meta and configuration state only:

* **BunnyStorageOffloader::getAttachmentStorageManifest( $attachment_id )** — Normalized Storage manifest rows from post meta (raw manifest, not the filtered manifest from other hooks).
* **BunnyStorageOffloader::getAttachmentStorageOffloadStatus( $attachment_id )** — Storage offload summary state, last error, and whether manifest rows report errors.
* **BunnyMediaLibrary::getAttachmentStreamMetadata( $attachment_id )** — Stream-related meta snapshot (video id, embed URL, thumbnail URL, dimensions, author collection id).
* **BunnyMediaLibrary::getAttachmentStreamStatus( $attachment_id )** — Whether Stream upload runtime is configured and whether the attachment stores a Stream video id.
* **BunnyAttachmentManifest::hasAnyOffloadedAttachments()** — Whether any attachment has a non-local offload summary (`_indigetal_offloaded` in partial, complete, or error). Bounded site-wide existence check; Pro uses this for storage URL token-key warnings and Site Health.

Fully qualified class names in source: Bunny_Offload\Integration\BunnyStorageOffloader, Bunny_Offload\Integration\BunnyMediaLibrary, and Bunny_Offload\Integration\BunnyAttachmentManifest. See PHPDoc on each method for return field details.

== Privacy ==

This plugin sends media and configuration-related data to **Bunny.net** only when you enable Stream and/or Storage features and use the workflows described in this readme. Bunny.net Terms of Service and privacy policy: https://bunny.net/tos/ — https://bunny.net/privacy/

**Stream (when enabled):** The site’s server makes requests to Bunny Stream API hosts such as `video.bunnycdn.com` to create, update, inspect, and delete videos and collections. Playback-related URLs may use hostnames such as `player.mediadelivery.net` and the **Stream Pull Zone hostname** you configure in settings.

**Storage (when enabled):** The site’s server makes requests to the **regional Bunny Storage API** host for the zone you select, and public file URLs use the **Storage Pull Zone hostname** you configure.

**Credentials:** API keys, passwords, zone identifiers, and related options are stored in the WordPress database (encrypted where this plugin applies encryption) and are used only to contact Bunny services on your behalf.

**Admin help links:** On **Media → Indigetal Media Offload for Bunny.net → Settings**, help text may link to Bunny.net documentation on `docs.bunny.net`, the Bunny.net customer dashboard on `dash.bunny.net`, a Bunny.net support article on `support.bunny.net`, and CDN product pages used to explain pull-zone hostnames. Opening those links is optional; they remain under Bunny.net’s terms and privacy policy above.

**Delivery scope (Free):** This Free release does not generate HMAC-signed or token-authenticated CDN URLs from PHP for Storage or Stream. Whether URLs are publicly readable or restricted is determined by your Bunny Pull Zone, Storage, and Stream configuration outside WordPress.

**Deleting content:** Removing a WordPress attachment can remove the linked Bunny Storage objects and Stream video as described elsewhere in this readme. Uninstall data retention is summarized on the plugin’s **About & Privacy** tab and in the FAQ above.

== Changelog ==

= 1.0.2 =
* Stream collection hardening: validate stored `_indigetal_offload_collection_id` against Bunny before upload; clear stale user meta and reuse or create the canonical `user_{userId}` collection when the remote GUID is missing.
* Paginate Bunny Stream collection listing so libraries with more than 100 collections are fully visible to collection validation and name-based reuse.

= 1.0.1 =
* WordPress.org Plugins Team review response: restrict Bunny Storage downloads to paths under the WordPress uploads directory; standardize plugin-owned options, transients, hooks, REST namespace, post/user meta, admin asset handles, lock transients, `WP_Error` codes, and Stream collection remote names under `indigetal_offload_` / `indigetal-offload` / `INDIGETAL_OFFLOAD_`.
* Breaking for integrators and upgraded databases without migration: re-save settings; update hook, filter, REST, and meta integrations; Stream collections use remote name `user_{userId}` (stored collection GUID meta unchanged). Pre-1.0.1 plugin-owned option, transient, hook, REST, and meta identifiers are not read.

= 1.0.0 =
* First stable release. Security hardening for WordPress.org review. No intentional breaking change to core Media Library Storage/Stream offload behavior for sites upgrading from `1.0.0-beta.2` or `0.8.11`.

= 1.0.0-beta.2 =
* Readme and metadata aligned with this **Stable tag** and plugin header version (`1.0.0-beta.2`): Installation paths and admin labels, Changelog/Upgrade Notice ordering, and continued alignment between **About & Privacy**, suggested Privacy Policy guide content, and readme **Privacy** / uninstall FAQ.
* No intentional breaking change to core Media Library Storage/Stream offload behavior for sites upgrading from `0.8.11`; review **About & Privacy** if you rely on uninstall cleanup options.

= 0.8.11 =
* Default removal path: preserves offload-critical options, credentials, manifests, and attachment/user meta; full deletion runs only when `indigetal_offload_delete_plugin_data_on_uninstall` is exactly `1`, then that opt-in option is removed. Runtime upload/collection lock transients and the Stream thumbnail sync cron are always cleared when the plugin package is removed.
* Aggressive cleanup list: dropped targets for legacy tool session/lock options and attachment lease meta that only applied to the separate commercial tier (not shipped in this Free tree).

= 0.8.9 =
* Final Free token/private-delivery sweep: remove unused signing and Storage token-state resolver classes; drop Account API client methods that only served those paths; align readme and About copy with public/basic Media Library offload.
* Uninstall post-meta list: stop targeting legacy Stream upload-state post meta from pre-split builds removed from Free earlier in the extraction.

= 0.1.2 =
* Hardened Bunny API retry handling and stricter Stream upload success checks before writing attachment meta.

= 0.1.1 =
* Stream thumbnail metadata and Media Library display improvements for offloaded videos.

= 0.1.0 =
* Initial Stream + Media Library integration direction (pre–Free extraction readme described a broader feature set; current Free scope is described in this file from 0.8.9 onward).

== Upgrade Notice ==

= 1.0.2 =
Bugfix release: Stream uploads recover when per-user collection meta points at a deleted or invalid Bunny collection. No settings migration required.

= 1.0.1 =
Review-response release: standardized `indigetal_offload_*` identifiers. Re-save plugin settings after upgrade; update custom integrations that used pre-1.0.1 hooks, REST paths, or attachment meta keys.

= 1.0.0 =
First stable release. Sites on `1.0.0-beta.2` or `0.8.x` can upgrade; review **About & Privacy** for uninstall retention.

= 1.0.0-beta.2 =
Beta aligned with readme **Stable tag** `1.0.0-beta.2`. Skim **Installation** for admin menu paths and **About & Privacy** for uninstall retention before upgrading from older `0.8.x` builds.

= 0.8.11 =
Uninstall now preserves database offload data by default. Review **About & Privacy** and the Settings Advanced checkbox before relying on uninstall to wipe plugin metadata.

= 0.8.9 =
Free scope documentation and dead signing/token resolver code removed; behavior for direct Media Library Storage and Stream offload is unchanged.
