# Stack2 WordPress Plugin Implementation Plan

## Objective
Build a production-ready WordPress plugin that:
1. Syncs plugin inventory from WordPress to Stack2.
2. Receives signed commands from Stack2 and executes allowed plugin actions.
3. Returns command results and optional refreshed inventory.
4. Uses HMAC-based request signing with timestamp replay protection.

## Setup Inputs (from Stack2 UI)
Admin enters these into plugin settings:
1. Stack2 Base URL (example: https://app.stack2.au)
2. Site ID (issued by Stack2)
3. API Key (issued once/rotated by Stack2)

Do not generate these values in WordPress. They come from Stack2.

## API Contract to Implement

### A. Inventory Push to Stack2
Endpoint:
- POST {stack2_base_url}/api/websites/plugin-inventory

Required headers:
- X-Stack2-Site-ID: {site_id}
- X-Stack2-Timestamp: unix seconds
- X-Stack2-Signature: hex hmac sha256

Signing message:
- Preferred: POST:stack2-push:{timestamp}:{sha256_hex_of_raw_json_body}
- Compatibility fallback exists server-side, but plugin should use preferred format above.

Body JSON:

```json
{
  "site_id": "site_xxx",
  "site_url": "https://example.com",
  "wp_version": "6.8",
  "php_version": "8.3.7",
  "collected_at": "2026-05-06T10:10:10Z",
  "plugins": [
    {
      "slug": "seo-by-rank-math",
      "file": "seo-by-rank-math/rank-math.php",
      "name": "Rank Math SEO",
      "version": "1.0.220",
      "author": "Rank Math",
      "plugin_uri": "https://rankmath.com/",
      "description": "SEO plugin",
      "is_active": true,
      "has_update": false,
      "latest_version": null
    }
  ]
}
```

Important:
- Do not send has_vulnerability. It is not part of the payload.

### B. Command Receiver in WordPress
Expose REST route:
- POST /wp-json/stack2/v1/command

Verify headers:
- X-Stack2-Site-ID
- X-Stack2-Timestamp
- X-Stack2-Signature

Signing message to verify:
- POST:/stack2/v1/command:{timestamp}:{sha256_hex_of_raw_json_body}

Timestamp validation:
- Reject if absolute skew > 300 seconds.

Request JSON:

```json
{
  "action": "update",
  "plugin": "seo-by-rank-math/rank-math.php",
  "slug": "seo-by-rank-math"
}
```

Allowed actions:
- install
- update
- activate
- deactivate
- delete
- inventory

Response JSON:

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

Notes:
- inventory is optional but should be returned for inventory action and after successful mutating actions when practical.
- If HTTP status is non-2xx, still return structured JSON where possible.

## WordPress Plugin Architecture

### Files
1. Main plugin bootstrap file.
2. Settings/admin page class.
3. Inventory collector service.
4. Signature/HMAC utility service.
5. REST controller for command endpoint.
6. Command executor service.
7. HTTP client service for push.
8. Logger/debug diagnostics helper.

### Settings
Store in wp_options:
1. stack2_base_url
2. stack2_site_id
3. stack2_api_key
4. stack2_auto_sync_enabled
5. stack2_sync_interval_minutes
6. stack2_last_sync_at
7. stack2_last_sync_status
8. stack2_last_sync_error

Security:
- Sanitize and validate all fields.
- Restrict settings page to manage_options.
- Mask API key in UI after save (show partial only).

### Scheduling
1. Register WP-Cron event for periodic inventory sync.
2. Trigger immediate sync on:
   - plugin activation
   - settings saved (if credentials valid)
3. Debounce to avoid duplicate concurrent sync runs.

### Inventory Collector Rules
1. Use get_plugins() to list installed plugins.
2. Use is_plugin_active() for active state.
3. Detect available update from update_plugins transient.
4. Compute:
   - slug from plugin file path first segment
   - file as plugin basename
   - latest_version if update exists
5. Include site_url, wp_version, php_version, collected_at.

### Command Execution Rules
1. install:
   - require slug
   - install via Plugin_Upgrader (with FS credentials handling)
2. update:
   - require plugin file or resolve via slug
3. activate/deactivate/delete:
   - require plugin file (or resolve slug to file)
4. inventory:
   - no mutation, return inventory
5. Always return structured success/error.
6. Wrap mutation operations with robust exception handling and rollback-safe behavior where applicable.

### Signature Implementation
1. hash = sha256(raw_body) as lowercase hex.
2. message format exactly as contract.
3. signature = lowercase hex hmac_sha256(message, api_key).
4. Use constant-time compare for signature check.
5. Reject missing headers, stale timestamp, invalid signature, wrong site id.

### Error Handling and Retries
1. Inventory push retries:
   - exponential backoff (example: 5s, 30s, 2m) for transient 5xx/network errors.
2. No retries for 401/403 until credentials changed.
3. Persist last error summary for admin UI.
4. Add debug mode toggle for detailed logs via error_log with prefix STACK2_PLUGIN.

### Compatibility
1. Support modern WordPress versions currently in active support.
2. Support PHP 8.1+.
3. Avoid fatal behavior if WP-CLI unavailable.
4. Graceful degradation when filesystem write credentials are required.

## Non-Functional Requirements
1. Idempotent inventory pushes.
2. Strict input validation for all REST payload fields.
3. Minimal blocking work in request cycle.
4. No secrets logged in plaintext.
5. Unit-testable service boundaries.

## Testing Checklist
1. Valid signed inventory push returns 200 from Stack2.
2. Invalid signature returns 401.
3. Stale timestamp (>300s) rejected.
4. Command endpoint rejects bad signature/site id.
5. Each action path:
   - install
   - update
   - activate
   - deactivate
   - delete
   - inventory
6. Response JSON shape matches contract.
7. Update detection maps correctly to has_update/latest_version.
8. No has_vulnerability field emitted.
9. Cron sync executes and records status.
10. Plugin survives missing/invalid settings without fatal errors.

## Definition of Done
1. Plugin can be configured using Stack2 Site ID + API key.
2. Manual Sync Now works.
3. Scheduled sync works.
4. Stack2 commands can mutate plugin state remotely.
5. HMAC verification passes in both directions.
6. Admin UI shows last sync status/time and safe error messages.
7. README includes setup, signing contract, and troubleshooting.

## Copy/Paste Prompt for Agent

Build a WordPress plugin named Stack2 Connector that integrates with Stack2 API. Implement:
1) settings page with Stack2 Base URL, Site ID, API key, sync toggles;
2) inventory push to POST /api/websites/plugin-inventory with headers X-Stack2-Site-ID, X-Stack2-Timestamp, X-Stack2-Signature and signing message POST:stack2-push:{timestamp}:{sha256(body)};
3) command receiver at POST /wp-json/stack2/v1/command verifying signature message POST:/stack2/v1/command:{timestamp}:{sha256(body)};
4) allowed actions install/update/activate/deactivate/delete/inventory;
5) structured response {success,error,inventory};
6) plugin inventory fields slug,file,name,version,author,plugin_uri,description,is_active,has_update,latest_version (no has_vulnerability);
7) WP-Cron periodic sync, sync-now button, retries for transient failures, secure logging and input validation;
8) robust service-oriented architecture with testable classes and a README including setup + troubleshooting.
# Stack2 WordPress Plugin Plan

## Objective
Build a production-ready WordPress plugin that:

1. Syncs plugin inventory from WordPress to Stack2.
2. Receives signed commands from Stack2 and executes allowed plugin actions.
3. Returns command results and optional refreshed inventory.
4. Uses HMAC-based request signing with timestamp replay protection.

## Setup Inputs (from Stack2 UI)
Admin enters these into plugin settings:

1. Stack2 Base URL (example: https://app.stack2.au)
2. Site ID (issued by Stack2)
3. API Key (issued once/rotated by Stack2)

Do not generate these values in WordPress. They come from Stack2.

## API Contract to Implement

### Inventory Push to Stack2
Endpoint:
- POST {stack2_base_url}/api/websites/plugin-inventory

Required headers:
- X-Stack2-Site-ID: {site_id}
- X-Stack2-Timestamp: unix seconds
- X-Stack2-Signature: hex hmac sha256

Signing message:
- Preferred: POST:stack2-push:{timestamp}:{sha256_hex_of_raw_json_body}
- Compatibility fallback exists server-side, but plugin should use preferred format above.

Body JSON:

```json
{
  "site_id": "site_xxx",
  "site_url": "https://example.com",
  "wp_version": "6.8",
  "php_version": "8.3.7",
  "collected_at": "2026-05-06T10:10:10Z",
  "plugins": [
    {
      "slug": "seo-by-rank-math",
      "file": "seo-by-rank-math/rank-math.php",
      "name": "Rank Math SEO",
      "version": "1.0.220",
      "author": "Rank Math",
      "plugin_uri": "https://rankmath.com/",
      "description": "SEO plugin",
      "is_active": true,
      "has_update": false,
      "latest_version": null
    }
  ]
}
```

Important:
- Do not send has_vulnerability. It is not part of the payload.

### Command Receiver in WordPress
Expose REST route:
- POST /wp-json/stack2/v1/command

Verify headers:
- X-Stack2-Site-ID
- X-Stack2-Timestamp
- X-Stack2-Signature

Signing message to verify:
- POST:/stack2/v1/command:{timestamp}:{sha256_hex_of_raw_json_body}

Timestamp validation:
- Reject if absolute skew greater than 300 seconds.

Request JSON:

```json
{
  "action": "update",
  "plugin": "seo-by-rank-math/rank-math.php",
  "slug": "seo-by-rank-math"
}
```

Allowed actions:
- install
- update
- activate
- deactivate
- delete
- inventory

Response JSON:

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

Notes:
- inventory is optional but should be returned for inventory action and after successful mutating actions when practical.
- If HTTP status is non-2xx, still return structured JSON where possible.

## WordPress Plugin Architecture

### Files
1. Main plugin bootstrap file.
2. Settings/admin page class.
3. Inventory collector service.
4. Signature/HMAC utility service.
5. REST controller for command endpoint.
6. Command executor service.
7. HTTP client service for push.
8. Logger/debug diagnostics helper.

### Settings
Store in wp_options:

1. stack2_base_url
2. stack2_site_id
3. stack2_api_key
4. stack2_auto_sync_enabled
5. stack2_sync_interval_minutes
6. stack2_last_sync_at
7. stack2_last_sync_status
8. stack2_last_sync_error

Security:
- Sanitize and validate all fields.
- Restrict settings page to manage_options.
- Mask API key in UI after save (show partial only).

### Scheduling
1. Register WP-Cron event for periodic inventory sync.
2. Trigger immediate sync on plugin activation.
3. Trigger immediate sync when settings are saved and credentials are valid.
4. Debounce to avoid duplicate concurrent sync runs.

### Inventory Collector Rules
1. Use get_plugins() to list installed plugins.
2. Use is_plugin_active() for active state.
3. Detect available update from update_plugins transient.
4. Compute slug from plugin file path first segment.
5. Set file as plugin basename.
6. Populate latest_version if update exists.
7. Include site_url, wp_version, php_version, collected_at.

### Command Execution Rules
1. install:
   - require slug
   - install via Plugin_Upgrader (with FS credentials handling)
2. update:
   - require plugin file or resolve via slug
3. activate/deactivate/delete:
   - require plugin file (or resolve slug to file)
4. inventory:
   - no mutation, return inventory
5. Always return structured success/error.
6. Wrap mutation operations with robust exception handling and rollback-safe behavior where applicable.

### Signature Implementation
1. hash = sha256(raw_body) as lowercase hex.
2. message format exactly as contract.
3. signature = lowercase hex hmac_sha256(message, api_key).
4. Use constant-time compare for signature check.
5. Reject missing headers, stale timestamp, invalid signature, wrong site id.

### Error Handling and Retries
1. Inventory push retries:
   - exponential backoff (for example: 5s, 30s, 2m) for transient 5xx/network errors.
2. No retries for 401/403 until credentials changed.
3. Persist last error summary for admin UI.
4. Add debug mode toggle for detailed logs via error_log with prefix STACK2_PLUGIN.

### Compatibility
1. Support modern WordPress versions currently in active support.
2. Support PHP 8.1+.
3. Avoid fatal behavior if WP-CLI unavailable.
4. Graceful degradation when filesystem write credentials are required.

## Non-Functional Requirements
1. Idempotent inventory pushes.
2. Strict input validation for all REST payload fields.
3. Minimal blocking work in request cycle.
4. No secrets logged in plaintext.
5. Unit-testable service boundaries.

## Testing Checklist
1. Valid signed inventory push returns 200 from Stack2.
2. Invalid signature returns 401.
3. Stale timestamp greater than 300s is rejected.
4. Command endpoint rejects bad signature/site id.
5. Each action path is validated:
   - install
   - update
   - activate
   - deactivate
   - delete
   - inventory
6. Response JSON shape matches contract.
7. Update detection maps correctly to has_update/latest_version.
8. No has_vulnerability field is emitted.
9. Cron sync executes and records status.
10. Plugin survives missing/invalid settings without fatal errors.

## Definition of Done
1. Plugin can be configured using Stack2 Site ID and API key.
2. Manual Sync Now works.
3. Scheduled sync works.
4. Stack2 commands can mutate plugin state remotely.
5. HMAC verification passes in both directions.
6. Admin UI shows last sync status/time and safe error messages.
7. README includes setup, signing contract, and troubleshooting.

## Copy/Paste Prompt for Agent
Build a WordPress plugin named Stack2 Connector that integrates with Stack2 API. Implement:

1. Settings page with Stack2 Base URL, Site ID, API key, and sync toggles.
2. Inventory push to POST /api/websites/plugin-inventory with headers X-Stack2-Site-ID, X-Stack2-Timestamp, X-Stack2-Signature and signing message POST:stack2-push:{timestamp}:{sha256(body)}.
3. Command receiver at POST /wp-json/stack2/v1/command verifying signature message POST:/stack2/v1/command:{timestamp}:{sha256(body)}.
4. Allowed actions install/update/activate/deactivate/delete/inventory.
5. Structured response {success,error,inventory}.
6. Plugin inventory fields slug,file,name,version,author,plugin_uri,description,is_active,has_update,latest_version (no has_vulnerability).
7. WP-Cron periodic sync, sync-now button, retries for transient failures, secure logging and input validation.
8. Robust service-oriented architecture with testable classes and a README including setup and troubleshooting.
