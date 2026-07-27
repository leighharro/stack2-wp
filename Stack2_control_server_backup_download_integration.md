# Stack2 Control Server Integration: Stateless Backup Download API

This document is the implementation guide for the control server (or AI agent generating control server code) to integrate with the current WordPress backup API.

It focuses on:

- Download workflow (primary)
- Current endpoint contract
- Stateless state model changes and migration

## Summary

The WordPress plugin backup flow is now stateless.

- `initiate` returns a manifest and a `job_id` token.
- The control server downloads backup data directly per manifest item:
  - Files: one request per file
  - Database: one request per table
- `status`, `cancel`, `list`, and archive component download endpoints are deprecated and return `410 Gone`.
- No backup progression is handled by WP-Cron.

## Base URL

- REST base: `https://<site-domain>/wp-json/stack2/v1`

Use plural routes as canonical (`/backups/...`).

## Authentication and Signing

All backup endpoints require HMAC headers.

Required headers:

- `x-stack2-site-id`
- `x-stack2-timestamp` (unix epoch seconds, UTC)
- `x-stack2-signature` (lowercase hex HMAC SHA-256)

Timestamp tolerance:

- Requests outside +/-300 seconds are rejected.

Canonical signature message:

`{METHOD}:{PATH}:{TIMESTAMP}:{SHA256_HEX_OF_RAW_BODY}`

Rules:

- `METHOD` must be uppercase.
- `PATH` should be the exact request path used by HTTP client.
- For `GET` and `DELETE`, body is empty string and body hash is SHA-256 of empty string.
- Hash raw bytes exactly as sent.

Empty body hash constant:

- `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`

## Current Endpoint Contract

### 1) Initiate backup

- Method: `POST`
- Path: `/wp-json/stack2/v1/backups/initiate`
- Body:

```json
{
  "backup_id": "<uuid-or-external-id>",
  "job_id": "backup_xxxxxxxx_1715425800",
  "include_files": true,
  "include_database": true,
  "timestamp": "2026-05-11T14:30:00Z"
}
```

Success response:

```json
{
  "success": true,
  "error": null,
  "backup_id": "...",
  "job_id": "backup_xxxxxxxx_1715425800",
  "status": "initiated",
  "manifest": {
    "backup_id": "...",
    "job_id": "...",
    "include_files": true,
    "include_database": true,
    "files": [
      { "path": "wp-content/uploads/2026/05/photo.jpg", "sha256": "<64-char lowercase hex>", "size": 123456 }
    ],
    "tables": ["wp_options", "wp_posts"],
    "...": "other metadata"
  }
}
```

Notes:

- `manifest.files` is the canonical list for file downloads. Each entry is an object with `path`, `sha256`, and `size` — **not** just a path string.
- `manifest.tables` is the canonical list for DB table downloads.
- `job_id` is used for subsequent download and cleanup endpoints.
- `job_id` in request body is optional. If provided and valid (`[A-Za-z0-9_-]`, max 128 chars), the plugin reuses it instead of generating a new one.
- `manifest.files[].sha256` is computed by the plugin synchronously during `initiate` (SHA-256 of the file's current on-disk contents, so `initiate` can take noticeably longer on sites with large media libraries — this is expected, not a bug). It is the **content-addressed key the control server uses to deduplicate against blob storage** — see "Content-addressed deduplication" below. It is a separate value from the `X-Backup-Checksum-SHA256` header returned on the actual file download (both are SHA-256 of the same file and should match; the header is a transfer-integrity check, the manifest value is the dedup key).
- **The response is streamed, not buffered.** The plugin starts writing the JSON response (and flushes periodically) before it has finished hashing every file, so that reverse proxies see continuous bytes and don't apply an idle/connection timeout while a large site is walked — this is the same technique used by the database table download endpoint. Practical implications for the client:
  - Do not assume a fixed response time budget; do assume bytes may arrive in bursts over a long-lived connection.
  - **Parse the response as a stream and validate that it is complete, well-formed JSON before trusting it.** If the connection drops or a proxy times out mid-stream, the client receives truncated JSON (e.g. an unterminated `files` array). Treat any parse failure or premature connection close as a failed `initiate` — not a partial success — and retry the whole call.
  - Retries are cheap: per-file checksums are cached by the plugin (keyed by file mtime), so a second `initiate` call re-walks the filesystem but skips re-hashing any file that hasn't changed since the first attempt.

### 2) Download one file from manifest

- Method: `GET`
- Path: `/wp-json/stack2/v1/backups/{job_id}/files/{base64url_relative_path}`

Where `base64url_relative_path` encodes manifest entry such as `wp-content/plugins/foo/bar.php`.

Response:

- Binary stream
- Headers include:
  - `Content-Type`
  - `Content-Disposition`
  - `Content-Length`
  - `X-Backup-Checksum-SHA256`
  - `X-Backup-Relative-Path`

### 3) Download one database table

- Method: `GET`
- Path: `/wp-json/stack2/v1/backups/{job_id}/database/table/{base64url_table_name}`

Where `base64url_table_name` encodes e.g. `wp_options`.

**200 response** (first request — dump streamed inline):

- Binary stream (`.sql.gz`) written directly to the HTTP response as rows are fetched from the database.
- The proxy never times out because bytes flow continuously from the first row.
- Headers include:
  - `Content-Type`
  - `Content-Disposition`
  - `X-Backup-Database-Table`
- `Content-Length` and `X-Backup-Checksum-SHA256` are **absent** on the first response because the total size and hash are only known after the stream completes.
- The dump is saved to a server-side cache file while it streams. Subsequent requests for the same `job_id` + table are served from cache and include `Content-Length` and `X-Backup-Checksum-SHA256`.

**200 response** (cached — full headers):

- Binary stream (`.sql.gz`) served from the server-side cache.
- Headers include:
  - `Content-Type`
  - `Content-Disposition`
  - `Content-Length`
  - `X-Backup-Checksum-SHA256`
  - `X-Backup-Database-Table`

Notes:

- On the first download, skip checksum verification (header absent). The file is complete if HTTP 200 is received and the response body is non-empty and valid gzip.
- To force checksum verification, download the same table a second time — the cached response will include `X-Backup-Checksum-SHA256`.
- The response is always synchronous (no 202). There is no need to poll or retry after a wait.

### 4) Cleanup generated artifacts

- Method: `DELETE`
- Path: `/wp-json/stack2/v1/backups/{job_id}`

Use after all intended file/table downloads complete.

## Deprecated Endpoints

These are intentionally deprecated in stateless mode and return `410 Gone`:

- `GET /wp-json/stack2/v1/backups/{job_id}/status`
- `POST /wp-json/stack2/v1/backups/{job_id}/cancel`
- `GET /wp-json/stack2/v1/backups`
- `GET /wp-json/stack2/v1/backups/{job_id}/download/{component}`

Control server should treat `410` from these as expected behavior, not an incident.

## Stateless Model: Control Server Behavior

There is no server-side backup progression to poll.

Implement flow as:

1. Call `initiate` once.
2. Persist returned `manifest` and `job_id` in your own control-server backup record.
3. Build a download work queue from:
   - `manifest.files` (file tasks)
   - `manifest.tables` (table tasks)
4. For each file task, check blob storage for an existing object keyed by `sha256` before downloading — see "Content-addressed deduplication" below. Only issue the `GET` download request on a miss.
5. Download each remaining item using signed `GET` requests.
6. Verify each downloaded payload using response checksum header (`X-Backup-Checksum-SHA256`), and confirm it matches the `sha256` recorded for that file in `manifest.files`.
7. Mark backup complete when all required tasks succeed (whether satisfied by dedup or by download).
8. Call cleanup endpoint once.

### Content-addressed deduplication

`manifest.files[].sha256` is a content hash, not just an integrity check — it is the plugin's authoritative statement of "this is what this file's bytes hash to right now." Use it to avoid re-transferring file content that the control server already holds:

1. Before enqueuing a file download, look up `sha256` in blob storage (a content-addressed store keyed by hash, independent of site/backup/path).
2. **Hit**: reference/copy the existing blob into this backup's file set (e.g. `files/<manifest-relative-path>` → existing blob by hash). Do not call the file download endpoint. Mark the item `succeeded` immediately.
3. **Miss**: download via `GET /backups/{job_id}/files/{base64url_relative_path}` as normal, verify `X-Backup-Checksum-SHA256` matches the manifest `sha256`, store the blob keyed by hash, then link it into this backup's file set.
4. This is why `initiate` computes `sha256` for every file synchronously before responding — the dedup decision has to be made before any download traffic is generated, and the plugin has no separate "list files without hashing" endpoint. Expect `initiate` latency to scale with the number and size of files on the site (mitigated on the plugin side by an mtime-keyed transient cache, so unchanged files are cheap on repeat backups).

Cancellation semantics:

- There is no plugin-side cancel state.
- If user cancels, stop enqueuing or executing further downloads on control server and optionally call cleanup immediately.

## Encoding Rules

Route parameters for file path and table name use base64url.

Algorithm:

1. Input UTF-8 bytes.
2. Base64 encode.
3. Replace `+` with `-` and `/` with `_`.
4. Remove trailing `=` padding.

Pseudo-code:

```text
base64url(s):
  b64 = base64(s)
  return b64.replace('+','-').replace('/','_').rstrip('=')
```

## Recommended Download Implementation

### Idempotency and resume

- Persist per-item states (`pending`, `downloading`, `deduplicated`, `succeeded`, `failed`).
- On retries/restarts, skip already succeeded/deduplicated items.
- Blob storage itself is keyed by content hash (`sha256`), so a blob is only ever written once regardless of how many sites/backups reference it.
- Use deterministic storage key naming for the per-backup manifest → blob mapping:
  - files: `files/<manifest-relative-path>` → blob `sha256`
  - tables: `database/tables/<table>.sql.gz`

### Concurrency

- Start conservative (2-6 concurrent downloads per website).
- Use separate pools for files and tables if needed.

### Retry policy

- Retry network errors and `5xx` with exponential backoff + jitter.
- Retry `429` if encountered.
- Do not retry `400/401/403/404` blindly.
- On `401`, regenerate timestamp and signature, retry once.
- `initiate` specifically: because its response is streamed (see notes above), a proxy timeout partway through shows up as a connection reset or unexpected EOF on an already-`200`-status response, not as a clean `5xx`. Treat "200 but body failed to parse as complete JSON" the same as a `5xx` for retry purposes.

### Integrity checks

For each response body:

1. Compute SHA-256 while streaming.
2. Compare with `X-Backup-Checksum-SHA256`.
3. Only mark item success if checksums match.

### Completion policy

- If required set is all files + all tables, completion means all manifest items downloaded and verified.
- If partial mode is supported, persist selected scope explicitly and evaluate completion against selected scope.

## Error Handling Matrix

- `200`: success
- `400`: malformed request or invalid encoded file/table input
- `401`: auth/signature/timestamp issue
- `404`: requested file/table unavailable
- `410`: deprecated endpoint (expected for status/cancel/list/component archive)
- `503`: plugin credentials not configured in WP settings

## Migration Guide (from old stateful integration)

If your control server currently:

- polls status,
- calls cancel,
- lists backups,
- or downloads `files|database|both` archives,

replace with:

1. `initiate`
2. consume manifest
3. per-item downloads (`files/...`, `database/table/...`)
4. cleanup

Suggested internal status model in control server:

- `initiated`
- `downloading`
- `download_partial_failed`
- `downloaded`
- `cleanup_done`
- `cancelled_by_user` (local-only)

Do not map plugin `410` to failed backup state for deprecated endpoints; map it to contract-deprecated behavior.

## cURL Examples

### Sign and call initiate

```bash
API_KEY="..."
SITE_ID="..."
HOST="example.com"
PATH="/wp-json/stack2/v1/backups/initiate"
TS="$(date +%s)"
BODY='{"backup_id":"550e8400-e29b-41d4-a716-446655440000","include_files":true,"include_database":true,"timestamp":"2026-05-11T14:30:00Z"}'
BODY_HASH="$(printf "%s" "$BODY" | openssl dgst -sha256 -binary | xxd -p -c 256)"
MSG="POST:$PATH:$TS:$BODY_HASH"
SIG="$(printf "%s" "$MSG" | openssl dgst -sha256 -hmac "$API_KEY" -binary | xxd -p -c 256)"

curl -sS "https://$HOST$PATH" \
  -X POST \
  -H "content-type: application/json" \
  -H "x-stack2-site-id: $SITE_ID" \
  -H "x-stack2-timestamp: $TS" \
  -H "x-stack2-signature: $SIG" \
  --data "$BODY"
```

### Download one manifest file

```bash
ENCODED_PATH="<base64url_of_wp-content_plugins_foo_bar.php>"
JOB_ID="backup_xxxxxxxx_1715425800"
PATH="/wp-json/stack2/v1/backups/$JOB_ID/files/$ENCODED_PATH"
TS="$(date +%s)"
EMPTY_HASH="e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
MSG="GET:$PATH:$TS:$EMPTY_HASH"
SIG="$(printf "%s" "$MSG" | openssl dgst -sha256 -hmac "$API_KEY" -binary | xxd -p -c 256)"

curl -fSL "https://$HOST$PATH" \
  -H "x-stack2-site-id: $SITE_ID" \
  -H "x-stack2-timestamp: $TS" \
  -H "x-stack2-signature: $SIG" \
  -o "./downloaded-file.bin"
```

### Cleanup

```bash
JOB_ID="backup_xxxxxxxx_1715425800"
PATH="/wp-json/stack2/v1/backups/$JOB_ID"
TS="$(date +%s)"
EMPTY_HASH="e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
MSG="DELETE:$PATH:$TS:$EMPTY_HASH"
SIG="$(printf "%s" "$MSG" | openssl dgst -sha256 -hmac "$API_KEY" -binary | xxd -p -c 256)"

curl -sS -X DELETE "https://$HOST$PATH" \
  -H "x-stack2-site-id: $SITE_ID" \
  -H "x-stack2-timestamp: $TS" \
  -H "x-stack2-signature: $SIG"
```

## AI Implementation Prompt Seed

Use the below as a seed prompt for code generation agents:

"Implement control-server backup downloader for Stack2 stateless WordPress API.

Requirements:

- Call POST /wp-json/stack2/v1/backups/initiate with signed HMAC headers.
- Parse and persist manifest.files (objects with path/sha256/size), manifest.tables, and job_id.
- Before downloading each file, look up its sha256 in content-addressed blob storage; on a hit, link the existing blob into this backup instead of downloading.
- Download files from GET /backups/{job_id}/files/{base64url_relative_path} only on a dedup miss.
- Download database tables from GET /backups/{job_id}/database/table/{base64url_table_name}.
- Compute SHA-256 while streaming and validate against X-Backup-Checksum-SHA256 and the manifest's sha256 for that file.
- Implement retry for network/5xx/429 with backoff and jitter.
- On 401, regenerate timestamp/signature and retry once.
- Treat status/cancel/list/component endpoints as deprecated (410 expected).
- Mark completion from per-item success tracking only.
- Call DELETE /backups/{job_id} cleanup once done or cancelled.
- Write structured logs and metrics for each item and final backup result."
