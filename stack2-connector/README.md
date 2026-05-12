# Stack2 Connector WordPress Plugin

Stack2 Connector syncs plugin inventory from WordPress to Stack2 and executes signed remote plugin commands.

## Features

- Inventory sync to Stack2 endpoint: `POST /api/websites/plugin-inventory`
- Signed command endpoint: `POST /wp-json/stack2/v1/command`
- Backup initiation endpoint: `POST /wp-json/stack2/v1/backups/initiate`
- Backup status endpoint: deprecated in stateless mode
- Backup database table download endpoint: `GET /wp-json/stack2/v1/backups/{job_id}/database/table/{base64url_table_name}`
- Backup file download endpoint: `GET /wp-json/stack2/v1/backups/{job_id}/files/{base64url_relative_path}`
- Backup cleanup endpoint: `DELETE /wp-json/stack2/v1/backups/{job_id}`
- Backup list endpoint: deprecated in stateless mode
- HMAC SHA256 request signing and timestamp replay protection
- Allowed commands: `install`, `update`, `activate`, `deactivate`, `delete`, `inventory`
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
5. Save settings.
6. Click **Sync Now** to verify connectivity.

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

## Backup Status Values

Stateful status transitions are deprecated in stateless mode.

## Backup Storage Layout

Artifacts are created under:

- `wp-content/.stack2-backup/{job_id}/database-table-{table}.sql.gz` (on-demand per table)

File downloads are streamed directly from `wp-content` using:

- `GET /wp-json/stack2/v1/backups/{job_id}/files/{base64url_relative_path}`

The manifest returned during initiate is used by the control server as the canonical file/table list.

## Command Payload

```json
{
  "action": "update",
  "plugin": "seo-by-rank-math/rank-math.php",
  "slug": "seo-by-rank-math"
}
```

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
