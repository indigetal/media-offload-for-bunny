=== Media Offload for Bunny.net ===
Contributors: Brandon Meyer
Tags: bunny, media, offload, video, streaming, cdn
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0-beta.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload non-video media to Bunny Storage and stream videos from Bunny Stream through the WordPress Media Library, with attachment URLs rewritten to your configured Bunny Pull Zone hostnames for public/basic delivery.

== Description ==

Media Offload for Bunny.net connects Bunny.net **Storage** and **Stream** to the WordPress Media Library. This **Free** release focuses on direct uploads from the Media Library, manifest-backed Storage offload, Stream video offload, thumbnail and metadata hydration, and URL rewriting for normal WordPress attachment and REST surfaces.

**What Free includes**

- **Bunny Storage (non-video)** – Offload supported non-video attachments from the Media Library to your Storage zone; rewrite delivery URLs to your configured **Storage Pull Zone** hostname using stored manifest data.
- **Bunny Stream (video)** – Offload supported videos from the Media Library to your Stream library; MP4 playback URLs use your configured **Stream Pull Zone** hostname; optional local file removal after successful offload.
- **Thumbnails and metadata** – Stream-backed videos can receive Bunny thumbnail and dimension metadata once encoding is playable; bounded background retries when needed.
- **Remote delete** – Deleting a WordPress attachment can delete the corresponding remote Storage objects (per manifest) and the remote Stream video when a Stream video ID is stored.
- **User collections** – Stream videos are associated with per-user Bunny collections where that workflow applies.
- **About & Privacy** – In-plugin disclosure for data sent to Bunny.net, uninstall behavior, and how Free differs from future add-on scope.

**What Free does not include**

This Free plugin does **not** implement access-controlled or tokenized media delivery from WordPress: it does not add HMAC-signed CDN URLs, Stream embed signing, privacy filters, or Pull-Zone token query parameters from PHP. It does not ship operator Tools tabs, bulk/retry queues, block-editor Stream upload stacks, or TUS uploads. Treat delivery as **public/basic** unless you add a compatible layer (for example a future Pro add-on) that integrates with the documented extension hooks.

Configure advanced Bunny.net edge rules (including token auth at the CDN) in the Bunny.net dashboard. If your Pull Zone requires signed or tokenized requests on every hit, URLs emitted by this Free plugin alone may not satisfy that edge policy.

== Installation ==

= Minimum Requirements =

* WordPress 6.5 or greater
* PHP 8.0 or greater
* A Bunny.net account
* **Stream:** Stream API access key, library ID, and Stream Pull Zone hostname (and optional Stream upload toggles) entered under Media Offload settings
* **Storage (optional):** Storage zone credentials, region, Storage Pull Zone hostname, and related toggles when you enable non-video offload

= Installation Instructions =

1. Upload the plugin folder to `/wp-content/plugins/media-offload-for-bunny/` or install the zip from the WordPress Plugins screen.
2. Activate **Media Offload for Bunny.net** through the Plugins menu.
3. Open **Media → Media Offload for Bunny.net**, complete the **Settings** tab, and read **About & Privacy** for data use and retention.
4. **Uninstall:** When you remove the plugin from the Plugins screen, WordPress **keeps** this plugin’s settings, encrypted credentials, Storage manifests, Stream metadata, and related offload data **by default** so you can reinstall and keep serving media from Bunny.net. Turn on **Advanced → Remove plugin-owned WordPress data on uninstall** on the Settings tab only if you deliberately want that WordPress-side data removed; it never deletes media files on your server or objects at Bunny.net.

== Development ==

Source code is available in public version control at https://github.com/indigetal/media-offload-for-bunny.git

To build the same style of installable archive this project publishes (`media-offload-for-bunny.zip`), run **`npm run package`** from a repository checkout (see **`package.json`**). That script uses **`.distignore`** when assembling the zip so development-only paths stay out of the distributed bundle, which keeps the package aligned with WordPress.org directory expectations.

== Frequently Asked Questions ==

= What is Bunny.net? =
Bunny.net provides global CDN, video streaming (Stream), and object storage (Storage) used by this plugin to store and deliver your media.

= Do I need a Bunny.net account? =
Yes. Create libraries and storage zones in the Bunny.net dashboard, then paste the credentials and hostnames this plugin asks for on the Settings tab.

= Does this work on multisite? =
The plugin is expected to work on multisite when network-enabled per site; use separate Bunny resources per site if you need isolation.

= Do Stream and Storage use the same hostname? =
No. Stream MP4 and iframe-style delivery use your **Stream** Pull Zone hostname. Non-video Storage files use your **Storage** Pull Zone hostname. Do not point both at the same hostname unless you intentionally configure Bunny.net that way.

= Are URLs signed or private? =
Not in this Free release. URLs are built from your configured Pull Zone hostnames and paths (plus Stream video IDs where applicable). For member-only or tokenized delivery, plan a compatible add-on or external layer; see **About & Privacy** in wp-admin.

= What happens to local files after offload? =
Separate toggles control optional removal of local files after successful Storage offload and after successful Stream upload. When enabled, WordPress may no longer keep a copy on disk while delivery continues from Bunny.net.

= What happens to remote Bunny objects when I delete a WordPress attachment? =
Where implemented, deleting the attachment can delete the related remote Storage objects (from the manifest) and the remote Stream video. Treat attachment deletion as destructive for the linked Bunny objects.

= What happens when I deactivate the plugin? =
Scheduled plugin events are cleared; settings, credentials, and attachment metadata remain. Local files and remote Bunny objects are not bulk-deleted on deactivation.

= What happens when I uninstall the plugin? =
By default, uninstall **keeps** this plugin’s settings, saved credentials, offload records, and media-related metadata in WordPress so you can reinstall and keep using media already stored at Bunny.net. Unless you turn on **Advanced → Remove plugin-owned WordPress data on uninstall** on the Settings tab (and only if you intend to wipe that data), nothing in that list is removed on uninstall. Uninstall **never** deletes your site’s media files on disk or removes objects in your Bunny Storage zones or Stream library—clean those up in WordPress or the Bunny.net dashboard if you need to. See **About & Privacy** in the plugin settings for the same policy with a bit more detail.

= Can I edit images or regenerate thumbnails after a fully offloaded image has local files removed? =
WordPress flows that require a local original on disk may not work once aggressive local deletion has removed those files. Plan backups before enabling local removal.

== Privacy ==

This plugin sends media and configuration-related data to **Bunny.net** only when you enable Stream and/or Storage features and use the workflows described in this readme. Bunny.net terms of use and privacy policy: https://bunny.net/terms/ — https://bunny.net/privacy/

**Stream (when enabled):** The site’s server makes requests to Bunny Stream API hosts such as `video.bunnycdn.com` to create, update, inspect, and delete videos and collections. Playback-related URLs may use hostnames such as `player.mediadelivery.net` and the **Stream Pull Zone hostname** you configure in settings.

**Storage (when enabled):** The site’s server makes requests to the **regional Bunny Storage API** host for the zone you select, and public file URLs use the **Storage Pull Zone hostname** you configure.

**Credentials:** API keys, passwords, zone identifiers, and related options are stored in the WordPress database (encrypted where this plugin applies encryption) and are used only to contact Bunny services on your behalf.

**Admin help links:** On **Media → Media Offload for Bunny.net → Settings**, help text may link to Bunny.net documentation on `docs.bunny.net`, the Bunny.net customer dashboard on `dash.bunny.net`, a Bunny.net support article on `support.bunny.net`, and CDN product pages used to explain pull-zone hostnames. Opening those links is optional; they remain under Bunny.net’s terms and privacy policy above.

**Delivery scope (Free):** This Free release does not generate HMAC-signed or token-authenticated CDN URLs from PHP for Storage or Stream. Whether URLs are publicly readable or restricted is determined by your Bunny Pull Zone, Storage, and Stream configuration outside WordPress.

**Deleting content:** Removing a WordPress attachment can remove the linked Bunny Storage objects and Stream video as described elsewhere in this readme. Uninstall data retention is summarized on the plugin’s **About & Privacy** tab and in the FAQ above.

== Changelog ==

= 0.8.11 =
* Uninstall: default path preserves offload-critical options, credentials, manifests, and attachment/user meta; full deletion runs only when `bunny_offload_delete_plugin_data_on_uninstall` is exactly `1`, then that opt-in option is removed. Runtime upload/collection lock transients and the Stream thumbnail sync cron are always cleared on uninstall. Removed Pro-only tool session/lock options and attachment lease meta from the aggressive cleanup list.

= 0.8.9 =
* Final Free token/private-delivery sweep: remove unused signing and Storage token-state resolver classes; drop Account API client methods that only served those paths; align readme and About copy with public/basic Media Library offload.
* Uninstall post-meta list: stop targeting legacy Stream TUS upload-state keys removed from Free earlier in the extraction.

= 0.1.2 =
* Hardened Bunny API retry handling and stricter Stream upload success checks before writing attachment meta.

= 0.1.1 =
* Stream thumbnail metadata and Media Library display improvements for offloaded videos.

= 0.1.0 =
* Initial Stream + Media Library integration direction (pre–Free extraction readme described a broader feature set; current Free scope is described in this file from 0.8.9 onward).

== Upgrade Notice ==

= 0.8.11 =
Uninstall now preserves database offload data by default. Review **About & Privacy** and the Settings Advanced checkbox before relying on uninstall to wipe plugin metadata.

= 0.8.9 =
Free scope documentation and dead signing/token resolver code removed; behavior for direct Media Library Storage and Stream offload is unchanged.
