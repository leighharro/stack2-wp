# WordPress Plugin: Manifest Changes for Incremental Backups

## Goal

Update the WordPress plugin's backup manifest so the GuaranaApp backend can perform incremental backups by reusing unchanged files from the previous backup via server-side blob copies.

## Required Manifest Changes

### Current Format

```json
{
  "backup_id": "...",
  "job_id": "...",
  "site_url": "https://example.com",
  "home_url": "https://example.com",
  "wordpress_version": "6.6",
  "php_version": "8.2",
  "generated_at": "2026-07-02T10:00:00+00:00",
  "backup_started_at": "2026-07-02T10:00:00+00:00",
  "include_files": true,
  "include_database": true,
  "wp_content_path": "/var/www/html/wp-content",
  "wp_uploads_path": "/var/www/html/wp-content/uploads",
  "database": { ... },
  "estimated_files_count": 5000,
  "estimated_database_size_mb": 150,
  "tables_count": 45,
  "files": [
    "wp-content/uploads/2024/photo.jpg",
    "wp-content/plugins/custom/plugin.php",
    "wp-content/themes/custom/style.css"
  ],
  "tables": [
    "wp_posts",
    "wp_options",
    "wp_users"
  ]
}
```

### New Format

The `files` array must contain objects instead of plain strings. Each object contains:

- `path` (string, required): the relative path from WordPress root, exactly as currently emitted.
- `sha256` (string, required): lowercase hex SHA-256 hash of the file contents.
- `size` (integer, required): file size in bytes.

```json
{
  "files": [
    {
      "path": "wp-content/uploads/2024/photo.jpg",
      "sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
      "size": 245760
    },
    {
      "path": "wp-content/plugins/custom/plugin.php",
      "sha256": "a3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
      "size": 4096
    },
    {
      "path": "wp-content/themes/custom/style.css",
      "sha256": "b3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
      "size": 2048
    }
  ]
}
```

The `tables` array remains unchanged as a list of strings.

## Field Details

### `path`

- Must be a relative path from the WordPress root directory.
- Must use forward slashes (`/`) regardless of host OS.
- Must not start with `./` or `/`.
- Must match the path used in the file download endpoint:
  `wp-json/stack2/v1/backups/{job_id}/files/{base64url(path)}`.
- Must be unique within the manifest.

### `sha256`

- Lowercase hexadecimal string of the file's SHA-256 hash.
- Must be computed from the raw file bytes on disk.
- Must not be computed from a compressed or transformed version of the file.
- If a file cannot be read due to permissions, omit the file from the manifest or fail the backup initiation with a clear error. Do not emit a null or empty hash.

### `size`

- File size in bytes as reported by the filesystem (`filesize()` or `stat()`).
- Must be a non-negative integer.
- Must match the actual number of bytes that will be served by the file download endpoint.

## Implementation Guidance

### Where to Make the Change

The manifest is built during backup initiation, where the plugin walks the file tree. Locate the code that currently populates the `files` array and change it from:

```php
$files[] = $relativePath;
```

to:

```php
$files[] = [
    'path'   => $relativePath,
    'sha256' => hash_file('sha256', $absolutePath),
    'size'   => filesize($absolutePath),
];
```

### Performance Considerations

- **Hashing adds CPU cost but no extra I/O.** The plugin already reads/walks the file tree; `hash_file()` streams the file through PHP's hash engine without loading the entire file into memory.
- For very large sites (tens of thousands of files), hashing every file during initiation can add seconds to manifest generation. This is acceptable because it replaces per-file HTTP downloads for unchanged files.
- If the plugin already caches file metadata between initiations, cache the hash and size keyed by absolute path and `mtime` to avoid re-hashing unchanged files.

### Excluded Files

The plugin should continue to exclude the same backup-plugin directory prefixes it excludes today:

- `wp-content/ai1wm-backups/`
- `wp-content/updraft/`
- `wp-content/wpvivid/`
- `wp-content/backwpup-*`
- `wp-snapshots/`

Do not include these in the manifest even if they exist on disk.

## Backwards Compatibility

The GuaranaApp backend will be updated to accept both formats during a transition period:

- **New object format**: used for incremental copy decisions.
- **Old string format**: treated as "hash unknown", forcing a full download.

However, the plugin should emit the new format once deployed. There is no need to support reading the old format on the plugin side.

## Endpoint Changes

No changes are required to the existing file download endpoint:

```
GET wp-json/stack2/v1/backups/{job_id}/files/{base64url(path)}
```

The `path` parameter continues to be the relative path string. The backend will look up the file metadata from the manifest it stored during initiation.

## New Optional Endpoint (Recommended for Future)

Consider adding a lightweight endpoint the backend can call to validate a single file's hash/size without downloading it:

```
GET wp-json/stack2/v1/backups/{job_id}/files/{base64url(path)}/metadata
```

Response:

```json
{
  "path": "wp-content/uploads/2024/photo.jpg",
  "sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
  "size": 245760
}
```

This is **not required** for the initial incremental backup implementation but is useful for manifest refresh and integrity checks.

## Validation Checklist

Before handing back to the backend team, verify:

- [ ] Manifest `files` array contains objects with `path`, `sha256`, and `size`.
- [ ] `sha256` is lowercase hex, 64 characters.
- [ ] `size` is an integer matching the file's byte size.
- [ ] `path` values are relative, use forward slashes, and are unique.
- [ ] Backup plugin directory prefixes are still excluded.
- [ ] File download endpoint still works with the same `base64url(path)` parameter.
- [ ] Large files (e.g., 500MB uploads) are hashed without memory exhaustion.
- [ ] Manifest JSON still deserializes correctly in the backend test harness.

## Example Minimal Manifest

```json
{
  "backup_id": "0190abcd-1234-7def-1234-567890abcdef",
  "job_id": "job_12345",
  "site_url": "https://example.com",
  "home_url": "https://example.com",
  "wordpress_version": "6.6",
  "php_version": "8.2",
  "generated_at": "2026-07-02T10:00:00+00:00",
  "backup_started_at": "2026-07-02T10:00:00+00:00",
  "include_files": true,
  "include_database": true,
  "wp_content_path": "/var/www/html/wp-content",
  "wp_uploads_path": "/var/www/html/wp-content/uploads",
  "database": {
    "host": "localhost",
    "port": 3306,
    "name": "wordpress",
    "charset": "utf8mb4",
    "collation": "utf8mb4_unicode_ci"
  },
  "estimated_files_count": 3,
  "estimated_database_size_mb": 150,
  "tables_count": 3,
  "files": [
    {
      "path": "wp-content/uploads/2024/photo.jpg",
      "sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
      "size": 245760
    },
    {
      "path": "wp-content/plugins/custom/plugin.php",
      "sha256": "a3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
      "size": 4096
    },
    {
      "path": "wp-content/themes/custom/style.css",
      "sha256": "b3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
      "size": 2048
    }
  ],
  "tables": [
    "wp_posts",
    "wp_options",
    "wp_users"
  ]
}
```

## Related Backend Work

This document supports the backend implementation described in:

- [docs/incremental-backups.md](docs/incremental-backups.md)
- [docs/backup-performance.md](docs/backup-performance.md)

The backend will:

1. Deserialize the new manifest format.
2. Load the previous completed backup's manifest.
3. Compare `path` + `sha256` between old and new manifests.
4. Server-side copy matching blobs instead of re-downloading from WordPress.
5. Use `size` for size-aware batch scheduling.
