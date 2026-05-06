# Stack2 Backup API Integration Guide

This document describes how to implement the backup functionality from the Stack2 app side.  
All endpoints follow the same HMAC-based request signing contract used for plugin commands.

---

## Overview

The backup flow is push-based and **zero-disk**: WordPress reads each site file directly in 10 MB pieces and pushes every chunk to the Stack2 API immediately — no ZIP archive is ever written to disk.  The only temp file created is a small SQL dump for the database; it is deleted right after its chunks are pushed.  Stack2 does not need to poll or fetch anything.

```
Stack2                              WordPress Plugin
  |                                      |
  |  POST /wp-json/stack2/v1/command     |
  |  {"action":"backup","backup_type":"full"}
  |------------------------------------->|
  |                                      | 1. Dump database to small temp .sql file
  |                                      |    Stream SQL file in 10 MB chunks:
  |  POST {base_url}/api/websites/backup/chunk
  |<-------------------------------------|    file_path: "database/database.sql"
  |  200 OK                              |    (repeat per chunk)
  |------------------------------------->|    Delete temp .sql file
  |                                      |
  |                                      | 2. For each file under ABSPATH:
  |  POST {base_url}/api/websites/backup/chunk
  |<-------------------------------------|    file_path: "wordpress/{relative_path}"
  |  200 OK                              |    (10 MB at a time, repeat per chunk)
  |------------------------------------->|
  |                                      |
  |  200 OK {backup_id, total_chunks ...}|
  |<-------------------------------------|
  |                                      |
  | (optional status check)              |
  |  GET /wp-json/stack2/v1/backup/{id}/status
  |------------------------------------->|
  |  200 OK {status:"pushed", ...}       |
  |<-------------------------------------|
```

Key points:
- **No ZIP file is ever created on disk.** Files are streamed directly from the WordPress file system.
- Each chunk is up to **10 MB** (base64-encoded in transit).
- Every chunk carries `file_path` and `file_chunk_index` so Stack2 can reconstruct the original file tree.
- Chunks arrive sequentially; `chunk_index` is a 0-based global counter across all chunks of the backup.
- The **last chunk** carries summary metadata (`total_chunks`, `total_bytes`, `backup_type`) so Stack2 knows when to finalise.

---

## 1. Initiate a Backup

**Endpoint:** `POST /wp-json/stack2/v1/command`

**Required headers:**

| Header | Value |
|---|---|
| `X-Stack2-Site-ID` | Your Site ID |
| `X-Stack2-Timestamp` | Current Unix timestamp (seconds) |
| `X-Stack2-Signature` | HMAC-SHA256 hex signature |
| `Content-Type` | `application/json` |

**Signing message:**
```
POST:/stack2/v1/command:{timestamp}:{sha256_hex_of_raw_json_body}
```

**Request body:**
```json
{
  "action": "backup",
  "backup_type": "full"
}
```

`backup_type` values:

| Value | Description |
|---|---|
| `full` | Full site backup: database SQL dump + entire WordPress root directory (default) |
| `database` | WordPress database SQL dump only |
| `files` | Entire WordPress root directory (no database) |

**Successful response (HTTP 200):**
```json
{
  "success": true,
  "error": null,
  "inventory": null,
  "backup_id": "bkp_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "total_chunks": 150,
  "total_bytes": 2500000000,
  "backup_type": "full"
}
```

| Field | Type | Description |
|---|---|---|
| `backup_id` | string | Unique identifier for this backup (also sent with every chunk) |
| `total_chunks` | integer | Total number of chunks that were pushed |
| `total_bytes` | integer | Total raw bytes streamed across all files |
| `backup_type` | string | The type of backup that was generated |

> **Note:** By the time this response is received, all chunks have already been pushed to Stack2 and the SQL temp file has been removed from the WordPress server.

**Error response example (HTTP 200):**
```json
{
  "success": false,
  "error": "Failed to push chunk 2.",
  "inventory": null
}
```

> The command endpoint always returns HTTP 200. Check the `success` field to determine outcome.

---

## 2. Stack2 Receiving Endpoint

WordPress pushes each chunk to:

```
POST {stack2_base_url}/api/websites/backup/chunk
```

**Headers sent by WordPress:**

| Header | Value |
|---|---|
| `X-Stack2-Site-ID` | Site ID |
| `X-Stack2-Timestamp` | Unix timestamp |
| `X-Stack2-Signature` | HMAC-SHA256 hex signature |
| `Content-Type` | `application/json` |

**Signing message (computed by WordPress):**
```
POST:stack2-backup-chunk:{timestamp}:{sha256_of_raw_json_body}
```

**Request body (intermediate chunks):**
```json
{
  "backup_id": "bkp_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "chunk_index": 0,
  "file_path": "database/database.sql",
  "file_chunk_index": 0,
  "data": "<base64-encoded chunk bytes>",
  "checksum": "<sha256 hex of the raw chunk bytes before base64 encoding>",
  "is_last": false
}
```

**Request body (final chunk — `is_last: true`):**
```json
{
  "backup_id": "bkp_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "chunk_index": 149,
  "file_path": "wordpress/wp-login.php",
  "file_chunk_index": 0,
  "data": "<base64-encoded chunk bytes>",
  "checksum": "<sha256 hex of raw chunk bytes>",
  "is_last": true,
  "total_chunks": 150,
  "total_bytes": 2500000000,
  "backup_type": "full"
}
```

| Field | Always present | Description |
|---|---|---|
| `backup_id` | ✓ | Identifies the backup session |
| `chunk_index` | ✓ | 0-based global sequential index across all chunks |
| `file_path` | ✓ | Virtual path of the source file (e.g. `database/database.sql`, `wordpress/wp-config.php`) |
| `file_chunk_index` | ✓ | 0-based index within the current file (use to write bytes at the right offset) |
| `data` | ✓ | base64-encoded raw bytes |
| `checksum` | ✓ | SHA-256 hex of raw bytes before base64 |
| `is_last` | ✓ | `true` on the very last chunk of the whole backup |
| `total_chunks` | only on `is_last` | Total number of chunks pushed |
| `total_bytes` | only on `is_last` | Total raw bytes across all files |
| `backup_type` | only on `is_last` | `full`, `database`, or `files` |

**Expected response from Stack2:** `200 OK` (body can be empty or `{"success":true}`).  
If Stack2 returns a non-2xx status, the plugin logs the error and stops pushing.

### Reassembly

Stack2 should:
1. For each incoming chunk, decode `data` from base64 and verify `hash('sha256', decoded_bytes) === checksum`.
2. Group chunks by `backup_id` + `file_path`, sorted by `file_chunk_index`.  Write the decoded bytes for each chunk at offset `file_chunk_index * 10485760` within that file.
3. A file is complete when a new `file_path` begins (or `is_last === true` for the final file).
4. When `is_last === true`, verify `chunk_index + 1 === total_chunks` to confirm no chunks were dropped, then mark the backup complete.

The virtual file tree pushed by the plugin:
```
database/
└── database.sql          # Full SQL dump (types: database, full)
wordpress/
├── wp-admin/…            # WordPress admin files
├── wp-includes/…         # WordPress core library files
├── wp-content/
│   ├── themes/…          # All theme files
│   ├── plugins/…         # All plugin files
│   ├── uploads/…         # All media files
│   └── …                 # Any other wp-content subdirectories
├── wp-config.php         # WordPress configuration
├── index.php             # WordPress front controller
├── .htaccess             # Apache rewrite rules (if present)
└── …                     # Any other files in the WordPress root
```

---

## 3. Check Backup Status (optional)

After the backup command returns, you can verify the outcome via:

**Endpoint:** `GET /wp-json/stack2/v1/backup/{backup_id}/status`

**Required headers:**

| Header | Value |
|---|---|
| `X-Stack2-Site-ID` | Your Site ID |
| `X-Stack2-Timestamp` | Current Unix timestamp (seconds) |
| `X-Stack2-Signature` | HMAC-SHA256 hex signature |

**Signing message for GET requests** (body is empty):
```
GET:/stack2/v1/backup/{backup_id}/status:{timestamp}:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
```

The constant `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` is the SHA-256 hash of an empty string.

**Successful response (HTTP 200):**
```json
{
  "success": true,
  "backup_id": "bkp_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "backup_type": "full",
  "status": "pushed",
  "total_bytes": 2500000000,
  "chunk_size": 10485760,
  "total_chunks": 150,
  "created_at": "2026-05-06T10:25:33Z"
}
```

`status` values:

| Value | Meaning |
|---|---|
| `pushed` | All chunks were pushed to Stack2 successfully |
| `failed` | The backup or push encountered an error |

---

## 4. Safety Cleanup (optional)

If a push fails mid-way, any orphaned SQL temp file on WordPress is automatically removed by an hourly cron task.  You may also trigger cleanup manually:

**Endpoint:** `POST /wp-json/stack2/v1/command`

**Request body:**
```json
{
  "action": "backup_cleanup",
  "backup_id": "bkp_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"
}
```

This is a no-op if no temp file exists and always returns `success: true`.

---

## End-to-End Example (pseudo-code)

```python
import base64, hashlib, hmac, json, os, time, requests

SITE_URL = "https://example.com"
SITE_ID  = "site_xxx"
API_KEY  = "my-secret-api-key"

def sign_post(route, body_bytes, api_key):
    ts = str(int(time.time()))
    body_hash = hashlib.sha256(body_bytes).hexdigest()
    message = f"POST:{route}:{ts}:{body_hash}"
    sig = hmac.new(api_key.encode(), message.encode(), hashlib.sha256).hexdigest()
    return ts, sig

# Step 1: Trigger backup – WordPress streams all files directly to Stack2.
body = json.dumps({"action": "backup", "backup_type": "full"}).encode()
ts, sig = sign_post("/stack2/v1/command", body, API_KEY)
resp = requests.post(
    f"{SITE_URL}/wp-json/stack2/v1/command",
    data=body,
    headers={
        "Content-Type": "application/json",
        "X-Stack2-Site-ID": SITE_ID,
        "X-Stack2-Timestamp": ts,
        "X-Stack2-Signature": sig,
    },
)
info = resp.json()
backup_id    = info["backup_id"]
total_chunks = info["total_chunks"]
total_bytes  = info["total_bytes"]

# Step 2: Stack2 side receives chunks at POST /api/websites/backup/chunk
# Group by file_path, sorted by file_chunk_index.
# When a new file_path arrives (or is_last=True), the previous file is complete.

# Step 3: (Optional) Verify status
EMPTY_HASH = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
ts = str(int(time.time()))
route = f"/stack2/v1/backup/{backup_id}/status"
message = f"GET:{route}:{ts}:{EMPTY_HASH}"
sig = hmac.new(API_KEY.encode(), message.encode(), hashlib.sha256).hexdigest()
status_resp = requests.get(
    f"{SITE_URL}/wp-json{route}",
    headers={
        "X-Stack2-Site-ID": SITE_ID,
        "X-Stack2-Timestamp": ts,
        "X-Stack2-Signature": sig,
    },
)
assert status_resp.json()["status"] == "pushed"

# Step 4: Reconstruct the file tree from received chunks.
# For each file received, concatenate chunks in file_chunk_index order
# and write to the appropriate path relative to your restore root.
# e.g.:  restore_root/wordpress/wp-config.php
#        restore_root/database/database.sql
```

---

## Backup File Tree

Files are streamed directly from WordPress without creating a ZIP on disk.

| Virtual path | Description | Included in |
|---|---|---|
| `database/database.sql` | Full SQL dump of all WordPress tables | `database`, `full` |
| `wordpress/` | Entire WordPress root directory (wp-admin, wp-includes, wp-content, wp-config.php, .htaccess, and all other files) | `files`, `full` |

**database.sql format:**
- Standard SQL with `SET FOREIGN_KEY_CHECKS=0/1` wrappers
- `DROP TABLE IF EXISTS` + `CREATE TABLE` + `INSERT` for each table
- Rows exported in batches of 1,000 for memory efficiency

**Restore procedure (simplified):**
1. For each received file, write the concatenated chunks to the correct path in your restore directory.
2. Copy the contents of `wordpress/` into your web root (or a new directory).
3. Edit `wordpress/wp-config.php` to update `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` for the new environment.
4. Create a new database and import `database/database.sql` via `mysql` CLI or phpMyAdmin.
5. Update the `siteurl` and `home` options in the database if the domain has changed.

---

## Admin Panel

Backup status is visible in the WordPress admin panel at **Settings → Stack2 Connector → Last Backup**.

Fields displayed:

- **Status** – `never` / `pushed` (green) / `failed` (red)
- **Last Run** – ISO 8601 timestamp of the most recent backup
- **Backup ID** – Identifier for the last completed backup
- **File Size** – Total bytes streamed across all files

---

## Security Notes

- All backup-related endpoints require valid HMAC signatures and a matching Site ID.
- Requests with a timestamp skew greater than 300 seconds are rejected.
- The SQL temp file is stored in `wp-content/uploads/stack2-backups/` which is protected by `.htaccess` to deny direct HTTP access.
- Backup IDs are cryptographically random (128-bit hex).
- Orphaned SQL temp files are automatically deleted by an hourly cron task.
- `wp-config.php` is included in the backup (as `wordpress/wp-config.php`). Stack2 stores and transmits it over the same HMAC-signed channel used for all other backup data.

---

## Requirements

- `wp-content/uploads/` must be writable by the web server process (used for the small SQL temp file only).
- No PHP extension beyond the standard library is required; `ZipArchive` is not needed.
- Sufficient disk space for the SQL dump only (typically much smaller than the full site).

---

## Troubleshooting

| Symptom | Resolution |
|---|---|
| Backup command returns `success: false` with a chunk error | Check that `{stack2_base_url}/api/websites/backup/chunk` exists and returns 2xx. |
| `503 Stack2 credentials are not configured` | Configure Stack2 Base URL, Site ID and API Key in WordPress **Settings → Stack2 Connector**. |
| Backup stops mid-stream on large sites | The plugin sets PHP execution time to 600 s. Contact your hosting provider if the server enforces a lower limit. |
| `Cannot create backup directory` | Ensure `wp-content/uploads/` is writable by the web server. |
| `401 Signature verification failed` on status endpoint | Remember that GET request signing uses the SHA-256 of an empty body. |

