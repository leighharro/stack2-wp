=== Stack2 Connector ===
Contributors: stack2
Tags: stack2, automation, plugin management, backup, inventory
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WordPress plugin inventory to Stack2 and execute signed remote plugin commands, with authenticated backup endpoints.

== Description ==

Stack2 Connector links your WordPress site to Stack2 for secure remote plugin operations and inventory synchronization.

Features include:

- Signed plugin command execution via REST API
- Plugin inventory sync to Stack2
- HMAC SHA256 signature verification and replay protection
- Backup initiation and authenticated backup artifact download endpoints
- Admin settings page with manual sync controls
- Debug logging support

== Installation ==

1. Upload the `stack2-connector` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Settings > Stack2 Connector.
4. Enter your Stack2 Base URL, Site ID, and API Key.
5. Save settings.
6. Click Sync Now to verify connectivity.

== Frequently Asked Questions ==

= What do I need before setup? =

You need a Stack2 account with a valid Site ID and API key for your website.

= Why do signed requests fail? =

Check API key, site ID, timestamp skew, and that signature headers are sent exactly as required.

= Does this plugin require WP-Cron? =

Automatic scheduled sync relies on WP-Cron. Manual sync is available from plugin settings.

== Changelog ==

= 1.0.0 =
- Initial public release.
- Added signed command endpoint and inventory synchronization.
- Added authenticated backup endpoints and supporting services.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
