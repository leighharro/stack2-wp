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
    "files": ["wp-content/..."],
    "tables": ["wp_options", "wp_posts"],
    "...": "other metadata"
  }
}
```

Notes:

- `manifest.files` is the canonical list for file downloads.
- `manifest.tables` is the canonical list for DB table downloads.
- `job_id` is used for subsequent download and cleanup endpoints.
- `job_id` in request body is optional. If provided and valid (`[A-Za-z0-9_-]`, max 128 chars), the plugin reuses it instead of generating a new one.

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

Response:

- Binary stream (`.sql.gz`)
- Headers include:
  - `Content-Type`
  - `Content-Disposition`
  - `Content-Length`
  - `X-Backup-Checksum-SHA256`
  - `X-Backup-Database-Table`

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
4. Download each item using signed `GET` requests.
5. Verify each payload using response checksum header (`X-Backup-Checksum-SHA256`).
6. Mark backup complete when all required tasks succeed.
7. Call cleanup endpoint once.

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

- Persist per-item states (`pending`, `downloading`, `succeeded`, `failed`).
- On retries/restarts, skip already succeeded items.
- Use deterministic storage key naming:
  - files: `files/<manifest-relative-path>`
  - tables: `database/tables/<table>.sql.gz`

### Concurrency

- Start conservative (2-6 concurrent downloads per website).
- Use separate pools for files and tables if needed.

### Retry policy

- Retry network errors and `5xx` with exponential backoff + jitter.
- Retry `429` if encountered.
- Do not retry `400/401/403/404` blindly.
- On `401`, regenerate timestamp and signature, retry once.

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
- Parse and persist manifest.files, manifest.tables, and job_id.
- Download files from GET /backups/{job_id}/files/{base64url_relative_path}.
- Download database tables from GET /backups/{job_id}/database/table/{base64url_table_name}.
- Compute SHA-256 while streaming and validate X-Backup-Checksum-SHA256.
- Implement retry for network/5xx/429 with backoff and jitter.
- On 401, regenerate timestamp/signature and retry once.
- Treat status/cancel/list/component endpoints as deprecated (410 expected).
- Mark completion from per-item success tracking only.
- Call DELETE /backups/{job_id} cleanup once done or cancelled.
- Write structured logs and metrics for each item and final backup result."
