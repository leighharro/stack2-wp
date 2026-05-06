# Stack2 Backup API Integration Guide

This document describes how to implement the backup functionality from the Stack2 app side.  
All endpoints follow the same HMAC-based request signing contract used for plugin commands.

---

## Overview

The backup flow is push-based: WordPress generates the backup and pushes each chunk directly to the Stack2 API as it is read.  Stack2 does not need to poll or fetch chunks.

```
Stack2                              WordPress Plugin
  |                                      |
  |  POST /wp-json/stack2/v1/command     |
  |  {"action":"backup","backup_type":"full"}
  |------------------------------------->|
  |                                      | 1. Generate gzip backup (DB + file manifest)
  |                                      | 2. For each 10 MB chunk:
  |  POST {base_url}/api/websites/backup/chunk
  |<-------------------------------------|    push base64 chunk with checksum
  |  200 OK                              |
  |------------------------------------->|    (repeat for every chunk)
  |                                      | 3. Delete temp file from WordPress server
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
- Each chunk is **10 MB** (base64-encoded in transit).
- Chunks arrive sequentially with a `chunk_index` starting at `0`.
- The **last chunk** carries additional summary metadata (`total_chunks`, `file_size`, `file_checksum`, `backup_type`) so Stack2 knows when to finalise the upload.
- The temp file is **deleted from WordPress immediately** after all chunks are pushed — no disk space is held.

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
  "total_chunks": 5,
  "file_size": 52428800,
  "checksum": "abc123...",
  "backup_type": "full"
}
```

| Field | Type | Description |
|---|---|---|
| `backup_id` | string | Unique identifier for this backup (also sent with every chunk) |
| `total_chunks` | integer | Total number of chunks that were pushed |
| `file_size` | integer | Total compressed backup size in bytes |
| `checksum` | string | SHA-256 hex digest of the full compressed backup file |
| `backup_type` | string | The type of backup that was generated |

> **Note:** By the time this response is received, all chunks have already been pushed to Stack2 and the temp file has been removed from the WordPress server.

**Error response example (HTTP 400):**
```json
{
  "success": false,
  "error": "Failed to push chunk 2.",
  "inventory": null
}
```

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
  "data": "<base64-encoded chunk bytes>",
  "checksum": "<sha256 hex of the raw chunk bytes before base64 encoding>",
  "is_last": false
}
```

**Request body (final chunk — `is_last: true`):**
```json
{
  "backup_id": "bkp_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "chunk_index": 4,
  "data": "<base64-encoded chunk bytes>",
  "checksum": "<sha256 hex of raw chunk bytes>",
  "is_last": true,
  "total_chunks": 5,
  "file_size": 52428800,
  "file_checksum": "<sha256 of full reassembled file>",
  "backup_type": "full"
}
```

**Expected response from Stack2:** `200 OK` (body can be empty or `{"success":true}`).  
If Stack2 returns a non-2xx status, the plugin logs the error and stops pushing.

### Reassembly

Stack2 should:
1. On each incoming chunk, decode `data` from base64 and verify `hash('sha256', decoded_bytes) === checksum`.
2. Write the decoded bytes to a temp file at offset `chunk_index * 10485760`.
3. When `is_last === true`, verify the full file checksum against `file_checksum` and finalise the upload.

---

## 3. Check Backup Status (optional)

After the backup command returns, you can also query status via:

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
  "file_size": 52428800,
  "chunk_size": 10485760,
  "total_chunks": 5,
  "checksum": "abc123...",
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

If a push fails mid-way, any orphaned temp file on WordPress is automatically removed by an hourly cron task.  You may also send a cleanup command:

**Endpoint:** `POST /wp-json/stack2/v1/command`

**Request body:**
```json
{
  "action": "backup_cleanup",
  "backup_id": "bkp_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"
}
```

This is a no-op if the file no longer exists and always returns `success: true`.

---

## End-to-End Example (pseudo-code)

```python
import base64, hashlib, hmac, json, time, requests

SITE_URL = "https://example.com"
SITE_ID  = "site_xxx"
API_KEY  = "my-secret-api-key"

def sign_post(route, body_bytes, api_key):
    ts = str(int(time.time()))
    body_hash = hashlib.sha256(body_bytes).hexdigest()
    message = f"POST:{route}:{ts}:{body_hash}"
    sig = hmac.new(api_key.encode(), message.encode(), hashlib.sha256).hexdigest()
    return ts, sig

# Step 1: Trigger backup – WordPress generates the file and pushes all chunks
# to POST {SITE_URL}/api/websites/backup/chunk automatically.
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
backup_id     = info["backup_id"]
total_chunks  = info["total_chunks"]
full_checksum = info["checksum"]

# Step 2: Stack2 side receives chunks at POST /api/websites/backup/chunk
# (handled by Stack2 server code – see "Stack2 Receiving Endpoint" above)
# Chunks arrive with chunk_index 0..N-1, decoded and appended in order.
# The final chunk (is_last=True) triggers reassembly verification.

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
```

---

## Backup File Format

The backup file is a **gzip-compressed** text archive.

- **Database backup** — Standard SQL dump with `DROP TABLE IF EXISTS` / `CREATE TABLE` / `INSERT` statements, rows exported in batches of 1000 for memory efficiency.
- **Files manifest** — Plain-text listing of every file under `wp-content/uploads/` with size and modification time.
- **Full** — Both of the above concatenated.

---

## Admin Panel

Backup status is visible in the WordPress admin panel at **Settings → Stack2 Connector → Last Backup**.

Fields displayed:

- **Status** – `never` / `pushed` (green) / `failed` (red)
- **Last Run** – ISO 8601 timestamp of the most recent backup
- **Backup ID** – Identifier for the last completed backup
- **File Size** – Human-readable compressed file size

---

## Security Notes

- All backup-related endpoints require valid HMAC signatures and a matching Site ID.
- Requests with a timestamp skew greater than 300 seconds are rejected.
- Backup temp files are stored in `wp-content/uploads/stack2-backups/` which is protected by `.htaccess` to deny direct HTTP access.
- Backup IDs are cryptographically random (128-bit hex).
- Orphaned temp files are automatically deleted by an hourly cron task.

---

## Troubleshooting

| Symptom | Resolution |
|---|---|
| Backup command returns `success: false` with a chunk error | Check that `{stack2_base_url}/api/websites/backup/chunk` exists and returns 2xx. |
| `503 Stack2 credentials are not configured` | Configure Stack2 Base URL, Site ID and API Key in WordPress **Settings → Stack2 Connector**. |
| Backup generation fails on large databases | The plugin sets PHP execution time to 300 s. Contact your hosting provider if the server enforces a lower limit. |
| `Cannot create backup directory` | Ensure `wp-content/uploads/` is writable by the web server. |
| `401 Signature verification failed` on status endpoint | Remember that GET request signing uses the SHA-256 of an empty body. |
