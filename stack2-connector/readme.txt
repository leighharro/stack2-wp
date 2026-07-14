=== Stack2 Connector ===
Contributors: stack2
Tags: stack2, automation, plugin management, backup, inventory
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.4
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
- Automatic update checks against GitHub Releases, with checksum-verified installs
- Debug logging support

== Installation ==

1. Upload the `stack2-connector` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Settings > Stack2 Connector.
4. Enter your Stack2 Base URL, Site ID, and API Key.
5. Save settings.
6. Click Sync Now to verify connectivity.

= WP-CLI =

You can install, activate, and configure the plugin entirely from WP-CLI:

    wp plugin install https://github.com/leighharro/stack2-wp/releases/latest/download/stack2-connector.zip --activate

    wp stack2 configure \
        --server=https://api.stack2.au \
        --site-id=abc123 \
        --token=secret

`wp stack2 configure` saves the Stack2 Base URL, Site ID, and API Key and queues an inventory sync, equivalent to filling out and saving the Settings > Stack2 Connector page.

To push inventory immediately instead of waiting on the queued sync:

    wp stack2 sync

See WP-CLI.md for the full walkthrough, including headless/scripted install notes.

== Frequently Asked Questions ==

= What do I need before setup? =

You need a Stack2 account with a valid Site ID and API key for your website.

= Why do signed requests fail? =

Check API key, site ID, timestamp skew, and that signature headers are sent exactly as required.

= Does this plugin require WP-Cron? =

Automatic scheduled sync relies on WP-Cron. Manual sync is available from plugin settings.

= How does the plugin update itself? =

Stack2 Connector is not distributed on wordpress.org. It checks GitHub Releases (https://github.com/leighharro/stack2-wp/releases/latest) for new versions and integrates with WordPress's normal plugin update UI, including the built-in "Enable auto-updates" option on the Plugins page. Downloaded packages are verified against the release's published SHA256 checksum before install. Like scheduled sync, checking for updates and background auto-updates rely on WP-Cron; a manual "Check for Updates" button is available from plugin settings.

== Changelog ==

= 1.1.4 =
- Release 1.1.4.

= 1.1.3 =
- Release 1.1.3.

= 1.1.2 =
- Release 1.1.2.

= 1.1.1 =
- Release 1.1.1.

= 1.1.0 =
- Added self-update support via GitHub Releases, integrated with WordPress's native plugin update UI and auto-updates.

= 1.0.7 =
- Patch release.

= 1.0.6 =
- Patch release.

= 1.0.5 =
- Patch release.

= 1.0.4 =
- Patch release.

= 1.0.3 =
- Patch release.

= 1.0.2 =
- Patch release.

= 1.0.1 =
- Patch release.

= 1.0.0 =
- Initial public release.
- Added signed command endpoint and inventory synchronization.
- Added authenticated backup endpoints and supporting services.

== Upgrade Notice ==

= 1.1.0 =
Adds self-update support via GitHub Releases.

= 1.0.7 =
Patch release.

= 1.0.6 =
Patch release.

= 1.0.5 =
Patch release.

= 1.0.4 =
Patch release.

= 1.0.3 =
Patch release.

= 1.0.2 =
Patch release.

= 1.0.1 =
Patch release.

= 1.0.0 =
Initial release.
