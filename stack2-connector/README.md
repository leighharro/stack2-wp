# Stack2 Connector WordPress Plugin

Stack2 Connector syncs plugin inventory from WordPress to Stack2 and executes signed remote plugin commands.

## Features

- Inventory sync to Stack2 endpoint: `POST /api/websites/plugin-inventory`
- Signed command endpoint: `POST /wp-json/stack2/v1/command`
- Backup initiation endpoint: `POST /wp-json/stack2/v1/backups/initiate` (small agent-mode envelope; no inline file list)
- Backup file scan: `GET|POST /wp-json/stack2/v1/backups/{job_id}/files/scan?cursor=&limit=`
- Backup file stats: `POST /wp-json/stack2/v1/backups/{job_id}/files/stats`
- Backup excluded-file catalog: `GET|POST /wp-json/stack2/v1/backups/{job_id}/files/excluded?cursor=&limit=`
- Force Connector update check: HMAC command `check_updates`
- Pinned WordPress core install: HMAC command `update_core`
- Backup status endpoint: deprecated in stateless mode
- Backup database table download endpoint: `GET /wp-json/stack2/v1/backups/{job_id}/database/table/{base64url_table_name}`
- Backup file download endpoint: `GET /wp-json/stack2/v1/backups/{job_id}/files/{base64url_relative_path}`
- Backup cleanup endpoint: `DELETE /wp-json/stack2/v1/backups/{job_id}`
- Backup list endpoint: deprecated in stateless mode
- Restore script place: `PUT|POST /wp-json/stack2/v1/restore-script`
- Restore script delete: `DELETE /wp-json/stack2/v1/restore-script`
- HMAC SHA256 request signing and timestamp replay protection
- Allowed commands: `install`, `update`, `update_core`, `activate`, `deactivate`, `delete`, `inventory`, `disconnect`, `check_updates`
- WP-Cron scheduled sync with retry backoff for transient failures
- Manual Sync Now button in admin settings
- Last sync status and safe error reporting
- Debug logging with `STACK2_PLUGIN` prefix

## Requirements

- WordPress 6.0+
- PHP 8.1+

## Installation

1. Copy `stack2-connector` folder into `wp-content/plugins/`.
2. Activate **Stack2 Connector** in WordPress admin.
3. Go to **Settings > Stack2 Connector**.
4. Enter values from Stack2:
   - Stack2 Base URL
   - Site ID
   - API Key
5. Save settings. Inventory sync runs immediately in that request; the admin notice reports success or failure so you can confirm credentials work.
6. Use **Sync Now** later if you want to push inventory again without changing settings.

## Settings Stored in `wp_options`

- `stack2_base_url`
- `stack2_site_id`
- `stack2_api_key`
- `stack2_auto_sync_enabled`
- `stack2_sync_interval_minutes`
- `stack2_last_sync_at`
- `stack2_last_sync_status`
- `stack2_last_sync_error`
- `stack2_debug_enabled`

## Signing Contract

### Outbound Inventory Push (WordPress to Stack2)

- Endpoint: `POST {stack2_base_url}/api/websites/plugin-inventory`
- Headers:
  - `X-Stack2-Site-ID`
  - `X-Stack2-Timestamp`
  - `X-Stack2-Signature`
- Message format for HMAC:
  - `POST:stack2-push:{timestamp}:{sha256_hex_of_raw_json_body}`

### Inbound Command Verification (Stack2 to WordPress)

- Endpoint: `POST /wp-json/stack2/v1/command`
- Required headers:
  - `X-Stack2-Site-ID`
  - `X-Stack2-Timestamp`
  - `X-Stack2-Signature`
- Message format for verification:
  - `POST:/stack2/v1/command:{timestamp}:{sha256_hex_of_raw_json_body}`
- Timestamp skew allowed: 300 seconds

### Backup Endpoint Verification (Stack2 to WordPress)

- Required headers:
  - `X-Stack2-Site-ID`
  - `X-Stack2-Timestamp`
  - `X-Stack2-Signature`
- Message format for verification:
  - `{METHOD}:{/wp-json/stack2/v1/backups/...}:{timestamp}:{sha256_hex_of_raw_json_body}`
  - For empty bodies (`GET`/`DELETE`), body hash is `sha256("")`.
- Timestamp skew allowed: 300 seconds

### Backup Initiate Request Body

`POST /wp-json/stack2/v1/backups/initiate` accepts:

- `backup_id` (string, optional)
- `job_id` (string, optional)
- `include_files` (bool, required as part of include selection)
- `include_database` (bool, required as part of include selection)
- `timestamp` (string, optional)
- `disable_exclusions` (bool, optional, default `false`): explicit only. Echoed on the initiate response. Initiate does not walk files; send the same flag on scan/stats/excluded. **Empty `exclude_patterns` is not disable.**

If `job_id` is provided and matches `[A-Za-z0-9_-]` (max 128 chars), the plugin reuses it. Otherwise it generates a new value like `backup_<id>_<unix>`.

The initiate response is a small JSON envelope. `manifest.files` is always an empty array. `manifest_mode` is `"agent"`. File inventory is Platform-driven.

`manifest.connector_version` is the Connector plugin version string (`STACK2_CONNECTOR_VERSION`, same as the plugin header) so Platform can check backup compatibility.

`manifest.source_paths` is a unique, trailing-slash-normalised list of live source PHP filesystem roots for migrate path detect/repair (not related to `/cache/` exclude). Always includes ABSPATH. Adds `WP_CONTENT_DIR` only when it is not `{ABSPATH}wp-content`. Adds `wp_upload_dir()['basedir']` and a non-empty `upload_path` option (relative values are resolved against ABSPATH). When `realpath()` differs (for example `/home` vs `/home2`), both variants are recorded. Sibling fields `upload_path` and `upload_url_path` echo the current `wp_options` values (empty string when unset) for reports. Existing `wp_content_path` / `wp_uploads_path` are unchanged.

`GET|POST /wp-json/stack2/v1/backups/{job_id}/files/scan?cursor=&limit=&include_sha256=0&include_dirs=0`

- HMAC-signed. Query string is **not** part of the signed path. Prefer a JSON body (GET or POST) so `exclude_patterns` is covered by the HMAC body hash.
- Default `limit` is 500; hard max is 2000. Successful pages always return HTTP `200` (never `202`).
- Each `entries[]` item is `{path, size, mtime}` plus optional `sha256` when `include_sha256=true`.
- `cursor` is an opaque base64url JSON DFS stack. Omit/empty starts at ABSPATH. Resume with `next_cursor` while `has_more` is true.
- Prefer leaving `include_sha256` false and hashing via stats batches.
- Optional `exclude_patterns` (array of strings): when present and non-empty, **replaces** local `EXCLUSION_PATTERNS` for that request (no merge). Absent or empty falls back to local defaults (no bare `/cache/`). Log basename exclusions (`error_log`, `php_errorlog`, `debug.log`, `*.log`) still apply unless `disable_exclusions` is true.
- Optional `disable_exclusions` (bool, default `false`): when `true`, skip path patterns **and** the log-basename hard filter. Empty `exclude_patterns` must **not** be treated as disable (that still uses local defaults).

`POST /wp-json/stack2/v1/backups/{job_id}/files/stats`

- HMAC-signed POST. Body: `{ "paths": ["..."], "include_sha256": true, "exclude_patterns": ["/wp-content/cache/"], "disable_exclusions": false }`.
- Maximum 200 paths per request. Over that limit returns HTTP `400`.
- Response: `{ success, stats: [{path, size, mtime, sha256?}], missing: [], failed: [{path, error}] }`.
- Uses the mtime-keyed `stack2_cksum_*` checksum cache.
- `exclude_patterns` and `disable_exclusions` follow the same rules as scan. Unknown JSON keys are ignored.

`GET|POST /wp-json/stack2/v1/backups/{job_id}/files/excluded?cursor=&limit=`

- HMAC-signed. Same cursor/limit contract as scan (default 500, hard max 2000). Successful pages always return HTTP `200`.
- Prefer a JSON body so `exclude_patterns` / `disable_exclusions` are covered by the HMAC body hash.
- Walks the backup scan root (`ABSPATH`) and returns **every** file that matches the effective exclusions, including files inside excluded directories. Pages together are a complete catalog (no SHA; do not OOM a huge tree — resume with `next_cursor`).
- Body (optional): `{ "cursor": "", "limit": 500, "exclude_patterns": ["/wp-content/cache/"], "disable_exclusions": false }`.
- Each `entries[]` item: `{ "path": "wp-content/cache/object/x", "matched_pattern": "/wp-content/cache/", "size": 12, "mtime": 1710000000 }`. `size` and `mtime` are omitted when metadata cannot be read. `matched_pattern` is the first matching path pattern (list order), or a log-basename rule (`error_log`, `php_errorlog`, `debug.log`, `*.log`) when no path pattern matches.
- `exclude_patterns` follows the same replace-or-fallback rules as scan/stats (Platform SoR). Non-empty list replaces local defaults; omitted/empty without `disable_exclusions` uses local defaults.
- When `disable_exclusions` is `true`, return HTTP `200` with `entries: []`, `has_more: false`, `next_cursor: null` so Platform can store an empty catalog.

Initiate advertises the same limits as `scan.default_limit` / `scan.max_limit`, `stats.default_batch` / `stats.max_batch`, and `excluded.default_limit` / `excluded.max_limit`. The initiate envelope also echoes `disable_exclusions`. Singular aliases (`/backup/...`) remain registered.

The previous paged `GET .../manifest` build (WP-Cron, NDJSON ledger, HTTP 202) has been removed.

## Restore script placement (Plugin Restore)

Connector **≥ 1.1.20** places and deletes the same restore PHP BatchPush already uploads over FTP. Platform still runs the HTTP restore steps against that script URL. The plugin does not implement a second restore protocol.

- Filename allowlist: docroot `stack2-{backupId}.php` only (`backupId` is 8–128 chars of `A-Za-z0-9_-`, matching Platform `stack2-{backupId}.php`). Paths with `/`, `..`, or any other name are rejected.
- Atomic write (temp file + rename). Failed writes leave no target and clean leftover `.tmp.*` siblings.
- Disconnected / missing site API key → HTTP `503` (`Connector is disconnected or not ready.`) before any write.
- Plugin TTL self-delete defaults to **6 hours** (`ttl_seconds` optional, clamped 60–86400). WP-Cron plus `init` both expire a stale script if Platform is unreachable.
- Disconnect also deletes a tracked restore script.
- Never log API keys, restore keys, or script body.

### Place

`PUT|POST /wp-json/stack2/v1/restore-script`

HMAC (same headers as backup endpoints):

- `{METHOD}:/wp-json/stack2/v1/restore-script:{timestamp}:{sha256_hex_of_raw_body}`

JSON body (preferred; covered by the HMAC body hash):

```json
{
  "filename": "stack2-550e8400-e29b-41d4-a716-446655440000.php",
  "content": "<?php /* same BatchPush restore PHP bytes */",
  "ttl_seconds": 21600
}
```

`content_base64` is accepted instead of `content`. Raw PHP body is also accepted; then `filename` must be a query param or `X-Stack2-Restore-Filename` (still allowlisted). Max body 2 MiB. Must start with `<?php`.

Success: HTTP 200 `{ "success": true, "filename": "stack2-….php", "bytes": 1234, "ttl_seconds": 21600 }`.

### Delete

`DELETE /wp-json/stack2/v1/restore-script`

HMAC: `DELETE:/wp-json/stack2/v1/restore-script:{timestamp}:{sha256_hex_of_raw_body}` (empty body hash is `sha256("")`).

Optional JSON `{ "filename": "stack2-….php" }`. Omit filename to delete the tracked script. **Idempotent:** HTTP 200 if the file is already gone (`already_gone: true`).

## Backup Status Values

Stateful status transitions are deprecated in stateless mode.

## Backup Storage Layout

Artifacts are created under:

- `wp-content/.stack2-backup/{job_id}/database-table-{table}.sql.gz` (on-demand per table)

File downloads are streamed directly from `wp-content` using:

- `GET /wp-json/stack2/v1/backups/{job_id}/files/{base64url_relative_path}`

Job scratch files live under `wp-content/.stack2-backup/{job_id}/` (download temp/cache only). File inventory is not stored on disk; Platform walks it with scan/stats. `manifest.tables` from initiate remains the canonical table list.

## Command Payload

```json
{
  "action": "update",
  "plugin": "seo-by-rank-math/rank-math.php",
  "slug": "seo-by-rank-math"
}
```

### Update result (1.1.21)

`update` still returns `success`, `error`, and `inventory`. When the upgrader runs, the response also includes:

- `not_applied` (bool) — `true` when WordPress did not return an error but the installed version string did not change. `success` is then `false`, `error_code` is `not_applied`, and `error` names the unchanged version.
- `error_code` (string, failures only) — WordPress/vendor code when the upgrader returned `WP_Error`. Connector-only codes: `not_applied`, `update_failed` (skin message without a WP code), `fs_credentials` (no WP or skin reason; `error` stays `Plugin update failed. Filesystem credentials may be required.`).
- `skin_messages` (string[], failures only) — `Automatic_Upgrader_Skin` feedback, tags stripped.
- `plugin_version` (string, when the installed version could be read) — target plugin version after the attempt. This is not `installed_version` from `check_updates`, which is the Connector version.

`error` is the WordPress/vendor message when one exists (for example an expired Elementor Pro license). The filesystem-credentials string is only the fallback.

### Core update (1.1.23)

`update` stays a plugin update. WordPress itself is a separate command on the same endpoint.

- Action: `update_core`
- Body: `{ "action": "update_core", "version": "6.8.5" }`
- `version` is required. It must be a numeric release, `X.Y` or `X.Y.Z`. `6.8-beta1`, `6.8-RC1`, `nightly`, and any other suffix are rejected with `error_code` `invalid_version`. A JSON number is rejected; send a string.

The connector installs that exact release with `Core_Upgrader`. It does not call version-check or `get_core_updates()`, and it does not install whatever those APIs currently call newest. The offer passed to `Core_Upgrader::upgrade()` has empty `partial`, `no_content`, `new_bundled`, and `rollback` packages, so WordPress downloads the full zip. `pre_check_md5` and `attempt_rollback` are off, so a checksum mismatch cannot switch the package and a failed copy cannot install a different rollback zip.

Package URL, HTTPS only, host `downloads.wordpress.org`:

- `en_US` (and an empty or unsafe locale): `https://downloads.wordpress.org/release/wordpress-<version>.zip`
- any other `get_locale()`: `https://downloads.wordpress.org/release/<locale>/wordpress-<version>.zip`
- locale package HTTP 404 or 410, or a download failure of that locale zip: the en_US zip of the same version

`downloads.w.org` is an allowed host for the same check. The connector does not build `w.org` URLs itself. The package URL is not written to the connector log. On failure the response `skin_messages` can still contain the upgrader's own download line.

Success means the WordPress version on disk (`wp-includes/version.php`) equals `version`. `Core_Upgrader` / `update_core()` do not update the in-request `$wp_version` global, so the connector re-reads the file and uses that value for `installed_version` and `inventory.wp_version`. An upgrader that returns a version string, or that installs a newer release than the pin, is not success.

Response fields:

- `success`, `error`, `error_message` (`error_message` is the same text as `error`; the 1.1.21 field name is unchanged)
- `not_applied` — `true` and `error_code` `not_applied` when the upgrader did not report a failure but `wp_version` did not change
- `error_code` and `skin_messages` on failure. Connector-only codes: `invalid_version`, `not_applied`, `version_mismatch` (files changed to something other than the pin), `update_failed`, `fs_credentials`, `invalid_package`, `core_upgrader_unavailable`. A `WP_Error` from `Core_Upgrader` keeps its code.
- `installed_version` — WordPress version after the attempt. This is not the Connector version returned by `check_updates`.
- `inventory` — the existing inventory payload, including `wp_version`

`.maintenance` is removed before the command returns, including when the upgrader throws or the version is rejected.

### Inventory package signals (1.1.21)

Each plugin in `inventory.plugins` keeps the existing fields and adds:

- `update_package_available` — `true` or `false` when the `update_plugins` transient has a `package` field (non-empty string means a download URL is present). `null` when WordPress has no package field for that plugin.
- `upgrade_notice` — plain-text notice from that same transient, or `null`.

Optional command body `{ "action": "inventory", "refresh": true }` deletes the `update_plugins` site transient and calls `wp_update_plugins()` before collecting. Omitted or false leaves the current transient. Scheduled inventory sync does not force a refresh.

### Requires Plugins and compatibility headers (1.1.22)

Platform G0 reads these fields when ordering a multi-plugin update. The connector reports them and does not warn, block, or reorder updates itself.

On WordPress 6.5 and newer, every plugin object includes:

- `requires_plugins` — array of slugs from `WP_Plugin_Dependencies::get_dependencies( $file )` after `WP_Plugin_Dependencies::initialize()`. Plugins with no Requires Plugins header send `[]`.
- `has_circular_dependency` — bool from `WP_Plugin_Dependencies::has_circular_dependency( $file )`.

On WordPress below 6.5 both keys are omitted. They are not sent as `[]` or `false`.

Dependency slugs and the circular flag are local. Collecting them does not call `api.wordpress.org` or any other WordPress.org host. `initialize()` can request the Plugin Information API when the current admin screen is `plugins.php`; inventory short-circuits that lookup for the duration of the call. An inventory command with `refresh: true` can still contact WordPress.org for the separate update check (`wp_update_plugins()`). That refresh is not used to fill these fields.

Optional compatibility headers are read with `get_file_data()` from the plugin file. A key is included only when the header is present and non-empty, on every supported WordPress version:

| Plugin header | JSON key |
| --- | --- |
| `WC requires at least` | `wc_requires_at_least` |
| `WC tested up to` | `wc_tested_up_to` |
| `Elementor tested up to` | `elementor_tested_up_to` |
| `Elementor Pro tested up to` | `elementor_pro_tested_up_to` |

Example plugin object (WordPress 6.5+, Stripe-style gateway):

```json
{
  "slug": "woocommerce-gateway-stripe",
  "file": "woocommerce-gateway-stripe/woocommerce-gateway-stripe.php",
  "requires_plugins": ["woocommerce"],
  "has_circular_dependency": false,
  "wc_requires_at_least": "8.6",
  "wc_tested_up_to": "9.4"
}
```

#### Verify on a WooCommerce site

Unit tests mock `WP_Plugin_Dependencies` and block outbound HTTP (`tests/Unit/PluginDependencyInventoryTest.php`). On a real site:

1. Use WordPress 6.5 or newer with WooCommerce and a gateway whose main file contains `Requires Plugins: woocommerce` (WooCommerce Stripe Gateway or WooPayments). Optional: Elementor and Elementor Pro, to see the Elementor headers.
2. Block WordPress.org for the request, for example with a must-use plugin:

```php
<?php
add_filter('pre_http_request', function ($pre, $args, $url) {
    $host = wp_parse_url((string) $url, PHP_URL_HOST);
    if (is_string($host) && preg_match('/(^|\\.)wordpress\\.org$/i', $host)) {
        return new WP_Error('blocked', 'outbound blocked');
    }
    return $pre;
}, 1000, 3);
```

3. Collect inventory without `refresh: true`. From WP-CLI, with the connector active:

```bash
wp eval 'echo wp_json_encode( ( new Stack2_Inventory_Collector() )->collect( "verify" ), JSON_PRETTY_PRINT );'
```

Or send the signed command `{ "action": "inventory" }`.

4. The gateway entry includes `requires_plugins` containing `woocommerce`, `has_circular_dependency: false`, and `wc_requires_at_least` / `wc_tested_up_to` when those headers exist. Elementor headers appear only on plugins that declare them. No request to `api.wordpress.org` is made for these fields.
5. Repeat on WordPress 6.4 (or any release below 6.5): the same plugins omit `requires_plugins` and `has_circular_dependency`. Compatibility headers are still present when declared. A normal WooCommerce + gateway pair is not circular; a true `has_circular_dependency` requires plugins whose Requires Plugins headers cycle (including a plugin that requires its own slug).

### Disconnect (Platform-initiated)

Platform should call this **while the site API key is still valid**, then tombstone the secret after a successful ack.

- Action: `disconnect` (HMAC-signed like every other `/command`)
- Body: `{ "action": "disconnect" }`
- On success: HTTP 200, `{ "success": true, "error": null, "inventory": null }`
- Deletes `stack2_base_url`, `stack2_site_id`, and `stack2_api_key`, and unschedules inventory cron
- A later command with empty local credentials returns HTTP 503 (`Stack2 credentials are not configured.`) — treat that as already disconnected

Platform / GuaranaApp should send `action: "disconnect"` (not `clear_credentials`).

### Force Connector update check

Platform can force WordPress to refresh the Connector update cache immediately after a release is tagged, instead of waiting for WP-Cron.

- Action: `check_updates` (HMAC-signed like every other `/command`)
- Body: `{ "action": "check_updates" }`
- Clears `stack2_connector_update_cache` and the `update_plugins` site transient, then calls `wp_update_plugins()`
- On success: HTTP 200, `{ "success": true, "error": null, "inventory": null, "status": "up_to_date"|"update_available", "installed_version": "1.1.16", "available_version": "1.1.16" }`
- On GitHub/update-API failure: HTTP 502, `{ "success": false, "error": "...", "inventory": null, "status": "check_failed", "installed_version": "1.1.16", "available_version": null }`
- Site must be connected (empty credentials still return HTTP 503)

## Command Response Shape

```json
{
  "success": true,
  "error": null,
  "inventory": {
    "site_id": "site_xxx",
    "site_url": "https://example.com",
    "wp_version": "6.8",
    "php_version": "8.3.7",
    "collected_at": "2026-05-06T10:11:11Z",
    "plugins": []
  }
}
```

## Troubleshooting

- `401 Signature verification failed`
  - Check API key, site ID, and header signing format.
- `401 Timestamp outside allowed skew window`
  - Ensure server clocks are in sync.
- `Plugin install/update/delete failed`
  - WordPress may require filesystem credentials. For `update`, that sentence is the fallback: if WordPress or the vendor returned a reason (license, package, copy failure), `error` and `error_code` carry that reason instead.
- `Stack2 API returned 401/403`
  - Verify Stack2 credentials in settings.
- Sync is not running on schedule
  - Confirm WP-Cron is active and auto sync is enabled.

Enable debug mode in plugin settings and inspect PHP error logs for entries prefixed with `STACK2_PLUGIN`.
