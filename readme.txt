=== Sarudo ===
Contributors: sarudo
Tags: ai, automation, blog, content, seo
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to your Sarudo AI employee. Sarudo can publish posts, manage media, configure SEO, and operate your site on your behalf.

== Description ==

Sarudo is an AI employee that businesses hire to take over day-to-day operations — including their WordPress site. This plugin is the connection layer: install it, paste the connection token into your Sarudo chat, and your AI can:

* Publish, edit, schedule, and delete blog posts and pages
* Upload and manage media (images, GIFs, files) — featured images set properly, not as inline figures
* Manage categories and tags (auto-create missing ones by slug)
* Configure SEO metadata via Yoast, RankMath, or AIOSEO (automatic detection)
* Add 301 redirects
* Manage menus, widgets, comments, revisions and Customizer / site-editor settings
* Install, activate, update and delete plugins and themes
* Create and manage users and their roles, and send password-reset emails
* Run WooCommerce: products, orders (including refunds), customers, coupons and sales reports
* Read and change site options, maintenance mode, cron events, caches and backups
* Run database queries, and read and write files under wp-content/uploads (or all of wp-content when asked)
* Read site health and basic configuration

Every action Sarudo takes is logged in an audit table you can review at any time under Settings → Sarudo. The connection token is a site-level secret (not tied to any user account); rotate it anytime to revoke access.

The connection token grants administrator-level control of your site: whoever holds it can do everything listed above. Treat it like an administrator password, lock the API to your Sarudo instance's IP with the allowlist, and rotate the token or deactivate the plugin to cut off access instantly.

The plugin is open source (GPLv2 or later): https://github.com/AnkushSehgal/sarudo-wordpress

= How it works =

1. Install + activate this plugin.
2. Copy the connection token shown on the plugin's settings page.
3. Paste the token into your Sarudo chat when prompted during the website setup.
4. Sarudo can now operate your site on your behalf.

= What about my data? =

Your data stays on your WordPress site. Sarudo's plugin only EXPOSES a REST API gated by your connection token; it doesn't send your content anywhere automatically. When Sarudo makes a request (e.g. "publish this post"), the request body and the result are written to a local audit log you can view in your WordPress admin.

= Security =

* Bearer token authentication on every API call (constant-time comparison)
* Optional IP allowlist (lock the API to your Sarudo instance's IP)
* Per-minute rate limit (default 60 req/min, configurable)
* Audit log of every command + arguments + result (default 90-day retention)
* Fields named password, token, secret, api_key, authorization or bearer are redacted before being written to the audit log
* Permanent deletes, refunds, backup restores, changing the site URL and destructive SQL (DROP, TRUNCATE, DELETE without WHERE) require an explicit confirm flag

== Installation ==

1. Upload the `sarudo` folder to `/wp-content/plugins/` (or install via Plugins → Add New).
2. Activate the plugin.
3. Go to Settings → Sarudo. Copy the connection token, then click "I've saved it" to clear the plaintext from the database.
4. In your Sarudo chat, complete the connection setup by pasting the token when prompted.

== Frequently Asked Questions ==

= Does Sarudo have full control of my site? =

Close to it. The token unlocks every command listed above, including installing plugins and themes, managing users and running database queries. Each command has an explicit name and is written to the audit log, so you can see exactly what Sarudo did, and destructive operations (deletes, refunds, destructive SQL) require an explicit confirmation flag.

= How do I revoke Sarudo's access? =

Either rotate the token on the plugin's settings page (instant) or deactivate / delete the plugin (also instant). Sarudo will lose connection until you onboard a new token.

= Does this slow down my site? =

No. The plugin only loads its REST handlers when a request comes in on the `/wp-json/sarudo/v1/` namespace. No front-end JS, no database queries on regular page loads.

= Can I see what Sarudo has been doing? =

Yes — Settings → Sarudo shows an audit log of every API call: command name, success, duration, source IP, and timestamp. Default retention is 90 days, configurable.

== Changelog ==

= 0.1.0 — 2026-05-19 =
* Initial release.
* Posts, pages, media, terms (categories/tags), SEO meta, redirects, site info.
* Yoast / RankMath / AIOSEO auto-detection for SEO bridge.
* Audit log, IP allowlist, rate limiting, dry-run mode.
* Improvements over publishing through the core REST API with an application password:
  * Featured images now uploaded to media library + set as `_thumbnail_id` (not inline figures)
  * Categories auto-created by slug when missing
  * SEO metadata populated via the active SEO plugin's storage location

== Upgrade Notice ==

= 0.1.0 =
First release.
