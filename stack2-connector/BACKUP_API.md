# Stack2 Backup API Integration Guide

This document describes how to implement the backup functionality from the Stack2 app side.  
All endpoints follow the same HMAC-based request signing contract already used for plugin commands.

---

## Overview

The backup flow is:

1. **Initiate** – Stack2 sends a `backup` command. WordPress generates a compressed backup and returns a `backup_id`.
2. **Poll status** *(optional)* – Stack2 checks the backup status until it is `ready`.
3. **Fetch chunks** – Stack2 fetches chunks sequentially by index and reassembles the file locally.
4. **Cleanup** – Stack2 sends a `backup_cleanup` command so WordPress deletes the temporary backup file.

Backups auto-expire after **1 hour** and are automatically deleted by a background cron task.

---

## 1. Initiate a Backup

**Endpoint:** `POST /wp-json/stack2/v1/command`

**Required headers (signed with your API key):**

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
| `full` | Database SQL dump + uploads file manifest (default) |
| `database` | WordPress database SQL dump only |
| `files_manifest` | Plain-text list of files in `wp-content/uploads` with sizes and timestamps |

**Successful response (HTTP 200):**
```json
{
  "success": true,
  "error": null,
  "inventory": null,
  "backup_id": "bkp_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "total_chunks": 12,
  "chunk_size": 1048576,
  "file_size": 12582912,
  "checksum": "abc123...",
  "expires_at": "2026-05-06T11:25:33Z"
}
```

| Field | Type | Description |
|---|---|---|
| `backup_id` | string | Unique identifier for this backup |
| `total_chunks` | integer | Number of chunks to fetch (0-indexed) |
| `chunk_size` | integer | Bytes per chunk (1 048 576 = 1 MB) |
| `file_size` | integer | Total compressed backup size in bytes |
| `checksum` | string | SHA-256 hex digest of the full compressed backup file |
| `expires_at` | string | ISO 8601 timestamp after which the backup is automatically deleted |

**Error response example (HTTP 503):**
```json
{
  "success": false,
  "error": "Stack2 credentials are not configured.",
  "inventory": null
}
```

---

## 2. Check Backup Status (optional)

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

The constant `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` is the SHA-256 hash of an empty string and is always used for GET request signatures.

**Successful response (HTTP 200):**
```json
{
  "success": true,
  "backup_id": "bkp_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "backup_type": "full",
  "status": "ready",
  "file_size": 12582912,
  "chunk_size": 1048576,
  "total_chunks": 12,
  "checksum": "abc123...",
  "created_at": "2026-05-06T10:25:33Z",
  "expires_at": "2026-05-06T11:25:33Z"
}
```

`status` values:

| Value | Meaning |
|---|---|
| `ready` | Backup file is ready to download |
| `failed` | Backup generation encountered an error |

**Not found response (HTTP 404):**
```json
{
  "success": false,
  "error": "Backup not found or expired."
}
```

---

## 3. Fetch Backup Chunks

**Endpoint:** `GET /wp-json/stack2/v1/backup/{backup_id}/chunk/{chunk_index}`

Chunks are **0-indexed**. Fetch all chunks from `0` to `total_chunks - 1` sequentially or in parallel.

**Required headers:** Same as status endpoint.

**Signing message:**
```
GET:/stack2/v1/backup/{backup_id}/chunk/{chunk_index}:{timestamp}:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
```

**Successful response (HTTP 200):**
```json
{
  "success": true,
  "backup_id": "bkp_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "chunk_index": 0,
  "total_chunks": 12,
  "data": "<base64-encoded chunk bytes>",
  "checksum": "<sha256 hex of the raw chunk bytes before base64 encoding>",
  "is_last": false
}
```

| Field | Type | Description |
|---|---|---|
| `data` | string | Base64-encoded bytes of this chunk |
| `checksum` | string | SHA-256 hex of the **decoded** chunk bytes (use for integrity check) |
| `is_last` | boolean | `true` when this is the final chunk |

**Not found / out-of-range response (HTTP 404):**
```json
{
  "success": false,
  "error": "Chunk not found. Backup may have expired or the chunk index is out of range."
}
```

### Resumption on failure

If fetching a chunk fails (network error, non-200 response), retry the same `chunk_index`.  
Because each chunk is derived by reading a fixed byte range from an immutable backup file, retrying is safe.

---

## 4. Clean Up the Backup

After successfully downloading all chunks and verifying the reassembled file against the top-level `checksum`, instruct WordPress to delete the temporary backup.

**Endpoint:** `POST /wp-json/stack2/v1/command`

**Request body:**
```json
{
  "action": "backup_cleanup",
  "backup_id": "bkp_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"
}
```

**Successful response (HTTP 200):**
```json
{
  "success": true,
  "error": null,
  "inventory": null
}
```

If the `backup_id` is not found (already expired and auto-cleaned), the response is still `success: true`.

---

## End-to-End Example (pseudo-code)

```python
import base64, hashlib, hmac, json, time, requests

SITE_URL = "https://example.com"
SITE_ID  = "site_xxx"
API_KEY  = "my-secret-api-key"

def sign(method, route, body_bytes, api_key):
    ts = str(int(time.time()))
    body_hash = hashlib.sha256(body_bytes).hexdigest()
    message = f"{method}:{route}:{ts}:{body_hash}"
    sig = hmac.new(api_key.encode(), message.encode(), hashlib.sha256).hexdigest()
    return ts, sig

# Step 1: Initiate backup
body = json.dumps({"action": "backup", "backup_type": "full"}).encode()
ts, sig = sign("POST", "/stack2/v1/command", body, API_KEY)
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
full_checksum = info["checksum"]

# Step 2: Fetch chunks and reassemble
EMPTY_HASH = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
assembled = b""
for i in range(total_chunks):
    route = f"/stack2/v1/backup/{backup_id}/chunk/{i}"
    ts = str(int(time.time()))
    message = f"GET:{route}:{ts}:{EMPTY_HASH}"
    sig = hmac.new(API_KEY.encode(), message.encode(), hashlib.sha256).hexdigest()

    chunk_resp = requests.get(
        f"{SITE_URL}/wp-json{route}",
        headers={
            "X-Stack2-Site-ID": SITE_ID,
            "X-Stack2-Timestamp": ts,
            "X-Stack2-Signature": sig,
        },
    )
    chunk_data = base64.b64decode(chunk_resp.json()["data"])

    # Verify chunk integrity
    assert hashlib.sha256(chunk_data).hexdigest() == chunk_resp.json()["checksum"]
    assembled += chunk_data

# Step 3: Verify full file integrity
assert hashlib.sha256(assembled).hexdigest() == full_checksum

# Step 4: Save to disk
with open(f"{backup_id}.gz", "wb") as f:
    f.write(assembled)

# Step 5: Cleanup
body = json.dumps({"action": "backup_cleanup", "backup_id": backup_id}).encode()
ts, sig = sign("POST", "/stack2/v1/command", body, API_KEY)
requests.post(
    f"{SITE_URL}/wp-json/stack2/v1/command",
    data=body,
    headers={
        "Content-Type": "application/json",
        "X-Stack2-Site-ID": SITE_ID,
        "X-Stack2-Timestamp": ts,
        "X-Stack2-Signature": sig,
    },
)
```

---

## Backup File Format

The backup file is a **gzip-compressed** archive with the following structure:

```
-- Stack2 WordPress Database Backup
-- Generated: 2026-05-06T10:25:33+00:00
-- WordPress Version: 6.8
-- PHP Version: 8.3.7
-- Site URL: https://example.com/

SET FOREIGN_KEY_CHECKS=0;

-- Table: wp_options
DROP TABLE IF EXISTS `wp_options`;
CREATE TABLE `wp_options` (...);
INSERT INTO `wp_options` ...;
...

SET FOREIGN_KEY_CHECKS=1;

-- Files Manifest (wp-content/uploads)
-- Base Directory: /var/www/html/wp-content/uploads/
-- Generated: 2026-05-06T10:25:34+00:00

FILE: 2025/01/image.jpg | SIZE: 204800 | MTIME: 1735000000
FILE: 2025/02/document.pdf | SIZE: 512000 | MTIME: 1738000000
...
```

> **Note:** Backup type `files_manifest` lists file paths and metadata only.  
> For full binary file transfer, use a direct SFTP/SSH connection or extend this plugin.

---

## Admin Panel

Backup status is visible in the WordPress admin panel at:

**Settings → Stack2 Connector → Last Backup**

Fields displayed:

- **Status** – `never` / `ready` / `failed`
- **Last Run** – ISO 8601 timestamp of the most recent backup
- **Backup ID** – Identifier for the last completed backup
- **File Size** – Human-readable compressed file size
- **Error** – Error message if the last backup failed

---

## Security Notes

- All backup endpoints require valid HMAC signatures and a matching Site ID.
- Requests with a timestamp skew greater than 300 seconds are rejected.
- Backup files are stored in `wp-content/uploads/stack2-backups/` which is protected by `.htaccess` to deny direct HTTP access.
- Backup IDs are cryptographically random (128-bit hex).
- All backup files are automatically deleted 1 hour after creation.

---

## Troubleshooting

| Symptom | Resolution |
|---|---|
| `401 Signature verification failed` on status/chunk endpoints | Remember that GET request signing uses the SHA-256 of an empty body, not the request body. |
| `404 Backup not found or expired` | Backups expire after 1 hour. Re-initiate the backup. |
| `503 Stack2 credentials are not configured` | Configure Stack2 Base URL, Site ID and API Key in WordPress Settings → Stack2 Connector. |
| Backup generation fails on large databases | The plugin increases PHP execution time to 300 seconds. If the server enforces a lower limit, contact your hosting provider. |
| `Cannot create backup directory` | Ensure `wp-content/uploads/` is writable by the web server. |
