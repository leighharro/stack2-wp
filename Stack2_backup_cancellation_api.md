# Stack2 Backup Cancellation API Integration (Deprecated)

This document is deprecated.

Use [Stack2_control_server_backup_download_integration.md](Stack2_control_server_backup_download_integration.md) as the source of truth for the current stateless backup API contract.

## Why Deprecated

The plugin no longer uses the old stateful backup model.

- Status polling endpoints are deprecated.
- Cancel endpoint is deprecated.
- Backup list endpoint is deprecated.
- Archive component download endpoint is deprecated.

Current flow is:

1. Initiate backup.
2. Read manifest from initiate response.
3. Download each file and table via per-item endpoints.
4. Call cleanup.

## Historical Reference Only

The remainder of this file describes an older stateful cancellation workflow and should not be used for new implementations.

## Base URL

- WordPress REST base: `https://<site-domain>/wp-json/stack2/v1`

## Authentication and Signing

All backup API requests are signed with HMAC-SHA256 using the site API key.

Required headers:

- `x-stack2-site-id`: exact site ID configured on plugin settings
- `x-stack2-timestamp`: unix epoch seconds (UTC)
- `x-stack2-signature`: lowercase hex HMAC SHA-256 signature

Timestamp tolerance:

- Requests are rejected if timestamp skew is greater than 300 seconds.

Canonical message format:

`{METHOD}:{PATH}:{TIMESTAMP}:{SHA256_HEX_OF_RAW_BODY}`

Rules:

- `METHOD` is uppercase (`GET`, `POST`, `DELETE`)
- `PATH` should include `/wp-json`, for example `/wp-json/stack2/v1/backups/<job_id>/cancel`
- Hash body exactly as sent (empty string body for GET/DELETE and cancel POST)
- Signature = `hex(hmac_sha256(message, api_key))`

Body hash examples:

- Empty body: SHA-256 of `""`
- JSON body: hash exact raw bytes (no reformatting after signing)

## Endpoints

The plugin supports plural and singular aliases. Prefer plural forms.

### 1) List backups

- Method: `GET`
- Path: `/wp-json/stack2/v1/backups`
- Optional query:
	- `status=<status>`
	- `limit=<n>` (default 50)

Response fields include:

- `job_id`
- `backup_id`
- `status`
- `cancel_requested`
- `progress_phase`
- `progress_percent`
- `started_at`
- `completed_at`

### 2) Get backup status

- Method: `GET`
- Path: `/wp-json/stack2/v1/backups/{job_id}/status`

Response fields include:

- `job_id`, `backup_id`
- `status`
- `cancel_requested`
- `progress` object
- `stage`
- `started_at`, `updated_at`
- `error`

### 3) Cancel backup

- Method: `POST`
- Path: `/wp-json/stack2/v1/backups/{job_id}/cancel`
- Body: empty

Response fields:

- `success` (true when cancellation requested or completed)
- `job_id`
- `status` (`cancelling`, `canceled`, or unchanged non-cancellable state)
- `previous_status`
- `cancel_requested` (true while cancellation is in progress)
- `cancelled` (true when final canceled state is already applied)
- `message`

### 4) Cleanup backup artifacts

- Method: `DELETE`
- Path: `/wp-json/stack2/v1/backups/{job_id}`

Use for transfer-complete cleanup, not for cancellation flow.

## Backup State Model (for app logic)

Active states:

- `queued`
- `initiating`
- `file_compression`
- `database_dump`
- `cancelling`

Terminal states:

- `transfer_pending`
- `completed`
- `failed`
- `canceled`

Cancellation behavior:

- If job is `queued`: plugin immediately sets `canceled`.
- If job is running: plugin sets `cancelling`, then background process transitions to `canceled` shortly.
- If job is already terminal (`transfer_pending`, `completed`, `failed`, `canceled`): cancel call returns 200 with message indicating not cancellable.

## Recommended App Flow

1. Start backup via initiate endpoint.
2. Poll status every 2-5 seconds while in active states.
3. On user cancel action:
	 - Call cancel endpoint once.
	 - If response `status = cancelling`, continue polling status.
	 - Stop polling when status is terminal (`canceled`, `failed`, `transfer_pending`, `completed`).
4. Treat cancel as successful when terminal status is `canceled`.

## Error Handling

Common HTTP statuses:

- `200`: request accepted/processed
- `400`: invalid payload or unsupported input
- `401`: bad/missing signature headers or invalid HMAC
- `404`: unknown job ID
- `409`: initiation conflict (another backup in progress)
- `503`: plugin credentials not configured

Retry guidance:

- Do not retry 4xx except `409` (retry initiate after current job is terminal).
- Retry network/5xx with exponential backoff.
- On signature failures, regenerate timestamp/signature and retry once.

## cURL Example: Cancel Backup

Inputs:

- `API_KEY`, `SITE_ID`, `JOB_ID`, `HOST`
- `TS=$(date +%s)`
- `PATH=/wp-json/stack2/v1/backups/$JOB_ID/cancel`
- Empty body hash:
	- `BODY_HASH=e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`
- Message:
	- `MSG=POST:$PATH:$TS:$BODY_HASH`

Signature:

- `SIG=$(printf "%s" "$MSG" | openssl dgst -sha256 -hmac "$API_KEY" -binary | xxd -p -c 256)`

Request:

```bash
curl -X POST "https://$HOST$PATH" \
	-H "x-stack2-site-id: $SITE_ID" \
	-H "x-stack2-timestamp: $TS" \
	-H "x-stack2-signature: $SIG"
```

## Notes

- Always sign the exact route path used in the HTTP request.
- Plugin includes fallback path normalization for backward compatibility, but clients should use canonical `/wp-json/stack2/v1/backups/...` paths.
- Keep clock synchronized (NTP) to avoid timestamp rejection.
