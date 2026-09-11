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
- Backup status endpoint: deprecated in stateless mode
- Backup database table download endpoint: `GET /wp-json/stack2/v1/backups/{job_id}/database/table/{base64url_table_name}`
- Backup file download endpoint: `GET /wp-json/stack2/v1/backups/{job_id}/files/{base64url_relative_path}`
- Backup cleanup endpoint: `DELETE /wp-json/stack2/v1/backups/{job_id}`
- Backup list endpoint: deprecated in stateless mode
- HMAC SHA256 request signing and timestamp replay protection
- Allowed commands: `install`, `update`, `activate`, `deactivate`, `delete`, `inventory`, `disconnect`, `check_updates`
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
  - WordPress may require filesystem credentials.
- `Stack2 API returned 401/403`
  - Verify Stack2 credentials in settings.
- Sync is not running on schedule
  - Confirm WP-Cron is active and auto sync is enabled.

Enable debug mode in plugin settings and inspect PHP error logs for entries prefixed with `STACK2_PLUGIN`.
