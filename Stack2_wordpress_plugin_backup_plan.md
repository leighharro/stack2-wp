# WordPress Plugin Backup Implementation Plan

## Overview
Update the existing Stack2 WordPress plugin to support backup creation, management, and restoration. The plugin will handle backup execution on WordPress while the app coordinates scheduling, storage, and user interface.

## Architecture

### Request Flow
```
App UI → Backup Tab
  ↓
App API: POST /api/websites/{websiteId}/backups
  ↓
Hangfire Job: WebsiteBackupJob
  ↓
Plugin Endpoint: POST /wp-json/stack2/v1/backups/initiate
  ↓
WordPress: Execute backup (files + database)
  ↓
Plugin Response: Backup metadata + manifest
  ↓
App: Store manifest to blob storage, update status
  ↓
App UI: Show backup in list with progress
```

### Key Principles
- **Plugin responsibility**: Execute backups, manage temporary files, return structured manifest
- **App responsibility**: Schedule, store manifests, display status, handle retention
- **Security**: HMAC-signed requests, timestamp validation (300s tolerance), API key rotation
- **Idempotency**: Can safely resend backup initiation (plugin detects in-progress jobs)
- **Progress tracking**: Plugin reports file count, database size, transfer status
- **Failure resilience**: Plugin cleans up partial backups on error

## API Contract

### 1. Backup Initiation Endpoint

**Endpoint**: `POST /wp-json/stack2/v1/backups/initiate`

**Headers** (HMAC-signed as per existing plugin):
```
X-Stack2-Site-ID: {site_id}
X-Stack2-Timestamp: {unix_seconds}
X-Stack2-Signature: {hex_hmac_sha256}
```

**Signing message format**:
```
POST:/wp-json/stack2/v1/backups/initiate:{timestamp}:{sha256_hex_of_raw_json_body}
```

**Request body**:
```json
{
  "backup_id": "550e8400-e29b-41d4-a716-446655440000",
  "include_files": true,
  "include_database": true,
  "timestamp": "2026-05-11T14:30:00Z"
}
```

**Response** (on success):
```json
{
  "success": true,
  "error": null,
  "backup_id": "550e8400-e29b-41d4-a716-446655440000",
  "job_id": "backup_550e8400_1715425800",
  "status": "initiated",
  "manifest": {
    "wordpress_version": "6.8",
    "php_version": "8.3.7",
    "site_url": "https://example.com",
    "home_url": "https://example.com",
    "backup_started_at": "2026-05-11T14:30:00Z",
    "include_files": true,
    "include_database": true,
    "wp_content_path": "/var/www/html/wp-content",
    "wp_uploads_path": "/var/www/html/wp-content/uploads",
    "database": {
      "host": "localhost",
      "port": 3306,
      "name": "wordpress_db",
      "charset": "utf8mb4",
      "collation": "utf8mb4_unicode_ci"
    },
    "estimated_files_count": 1250,
    "estimated_database_size_mb": 125,
    "tables_count": 42
  }
}
```

**Response** (on error):
```json
{
  "success": false,
  "error": "Another backup is already in progress",
  "backup_id": "550e8400-e29b-41d4-a716-446655440000",
  "job_id": null,
  "status": "failed"
}
```

**Status codes**:
- `200`: Backup initiated successfully
- `400`: Invalid request (missing fields, bad format)
- `401`: Invalid HMAC signature or expired timestamp
- `409`: Backup already in progress or conflict
- `500`: Server error

**Error scenarios**:
- "Another backup is already in progress" → HTTP 409
- "Invalid HMAC signature" → HTTP 401
- "Timestamp out of tolerance (> 300s)" → HTTP 401
- "Database credentials invalid" → HTTP 500
- "Insufficient disk space" → HTTP 500
- "Missing include_files and include_database both false" → HTTP 400

### 2. Backup Status Endpoint

**Endpoint**: `GET /wp-json/stack2/v1/backups/{job_id}/status`

**Headers** (HMAC-signed):
```
X-Stack2-Site-ID: {site_id}
X-Stack2-Timestamp: {unix_seconds}
X-Stack2-Signature: {hex_hmac_sha256}
```

**Signing message format**:
```
GET:/wp-json/stack2/v1/backups/{job_id}/status:{timestamp}:{empty_sha256}
```

**Response**:
```json
{
  "success": true,
  "job_id": "backup_550e8400_1715425800",
  "backup_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "transfer_pending",
  "progress": {
    "phase": "compressing_files",
    "files_processed": 450,
    "files_total": 1250,
    "database_rows_processed": 5000,
    "database_rows_total": 15000,
    "bytes_processed": 52428800,
    "bytes_total": 314572800,
    "percent": 16,
    "current_file": "wp-content/plugins/yoast-seo/readme.txt"
  },
  "stage": "file_compression",
  "started_at": "2026-05-11T14:30:00Z",
  "updated_at": "2026-05-11T14:32:15Z",
  "temp_directory": "/var/www/html/.stack2-backup/backup_550e8400_1715425800",
  "file_manifest": null,
  "database_dump_file": null
}
```

**Status values**:
- `queued` → backup waiting to start
- `initiating` → preparing backup
- `file_compression` → compressing WordPress files
- `database_dump` → dumping database
- `transfer_pending` → ready to download
- `completed` → backup completed and files cleaned up locally
- `failed` → backup failed, temporary files may still exist
- `cancelled` → backup was cancelled by user

### 3. Backup Download Endpoint

**Endpoint**: `GET /wp-json/stack2/v1/backups/{job_id}/download/{component}`

Where `{component}` is: `files`, `database`, or `both` (creates combined archive)

**Headers** (HMAC-signed):
```
X-Stack2-Site-ID: {site_id}
X-Stack2-Timestamp: {unix_seconds}
X-Stack2-Signature: {hex_hmac_sha256}
```

**Response**: Binary file stream with headers:
```
Content-Type: application/zip (or application/gzip)
Content-Disposition: attachment; filename=backup-550e8400-files-20260511.zip
Content-Length: {file_size}
X-Backup-Checksum-SHA256: {sha256_hex}
```

**Note**: App should download individual components (files + database) to separate blob storage containers or blobs for management flexibility.

### 4. Backup Cleanup Endpoint

**Endpoint**: `DELETE /wp-json/stack2/v1/backups/{job_id}`

**Headers** (HMAC-signed):
```
X-Stack2-Site-ID: {site_id}
X-Stack2-Timestamp: {unix_seconds}
X-Stack2-Signature: {hex_hmac_sha256}
```

**Response**:
```json
{
  "success": true,
  "job_id": "backup_550e8400_1715425800",
  "message": "Temporary backup files deleted",
  "temp_directory": "/var/www/html/.stack2-backup/backup_550e8400_1715425800",
  "freed_space_mb": 410
}
```

**Purpose**: Clean up temporary backup files on plugin side after download completes. App should call this after storing backup in blob storage.

### 5. List Backups Endpoint

**Endpoint**: `GET /wp-json/stack2/v1/backups?status={status}`

**Headers** (HMAC-signed):
```
X-Stack2-Site-ID: {site_id}
X-Stack2-Timestamp: {unix_seconds}
X-Stack2-Signature: {hex_hmac_sha256}
```

**Query parameters** (optional):
- `status`: Filter by status (queued, transfer_pending, completed, failed)
- `limit`: Max results (default 50)

**Response**:
```json
{
  "success": true,
  "backups": [
    {
      "job_id": "backup_550e8400_1715425800",
      "backup_id": "550e8400-e29b-41d4-a716-446655440000",
      "status": "transfer_pending",
      "started_at": "2026-05-11T14:30:00Z",
      "completed_at": null,
      "include_files": true,
      "include_database": true,
      "files_size_mb": 245,
      "database_size_mb": 125
    }
  ]
}
```

## Implementation Details

### Plugin File Structure
```
stack2-backup/
├── stack2-backup.php                    # Main plugin file
├── includes/
│   ├── class-stack2-backup-manager.php  # Backup orchestration
│   ├── class-stack2-backup-compressor.php  # File compression
│   ├── class-stack2-backup-database.php    # Database export
│   ├── class-stack2-database-dumper.php    # MySQL dump wrapper
│   ├── class-stack2-backup-manifest.php    # Manifest generation
│   └── class-stack2-backup-cleaner.php     # Temporary file cleanup
├── api/
│   ├── class-stack2-backup-api.php         # REST endpoint registration
│   └── class-stack2-backup-authentication.php # HMAC verification
├── admin/
│   └── class-stack2-backup-settings.php    # Plugin settings UI
└── assets/
    └── .gitkeep
```

### Key Implementation Classes

#### 1. Stack2_Backup_Manager
**Responsibility**: Orchestrate backup process

```php
class Stack2_Backup_Manager {
    // Queue backup from app request
    public function initiate_backup( $backup_id, $include_files, $include_database ) {}
    
    // Get current backup status
    public function get_backup_status( $job_id ) {}
    
    // Get backup file for download
    public function get_backup_component( $job_id, $component ) {}
    
    // Clean up temporary files
    public function cleanup_backup( $job_id ) {}
    
    // List active/completed backups
    public function list_backups( $status = null, $limit = 50 ) {}
    
    // Check if backup already in progress
    private function is_backup_in_progress() {}
}
```

#### 2. Stack2_Backup_Compressor
**Responsibility**: Create compressed archives of WordPress files

```php
class Stack2_Backup_Compressor {
    // Compress wp-content directory
    public function compress_files( $include_paths, $temp_dir, $progress_callback ) {}
    
    // Exclude common large/unnecessary directories
    private function get_exclusion_patterns() {}
    
    // Track progress and return manifest
    public function get_file_manifest() {}
}
```

#### 3. Stack2_Backup_Database
**Responsibility**: Export database using wp-cli or mysqldump

```php
class Stack2_Backup_Database {
    // Dump database to file
    public function dump_database( $temp_dir, $progress_callback ) {}
    
    // Get database metadata for manifest
    public function get_database_info() {}
    
    // Verify dump integrity
    private function verify_dump( $dump_file ) {}
}
```

#### 4. Stack2_Backup_Manifest
**Responsibility**: Build structured backup manifest for app storage

```php
class Stack2_Backup_Manifest {
    // Create manifest from backup components
    public function build_manifest( $backup_id, $job_id, $files_info, $database_info ) {}
    
    // Save manifest as JSON
    public function save_manifest( $manifest, $temp_dir ) {}
    
    // Return manifest for API response
    public function get_manifest_json() {}
}
```

#### 5. Stack2_Backup_Cleaner
**Responsibility**: Clean up temporary files with retry logic

```php
class Stack2_Backup_Cleaner {
    // Schedule cleanup (hourly cron removes old temp dirs)
    public function schedule_cleanup() {}
    
    // Force immediate cleanup of specific backup
    public function cleanup_backup( $job_id ) {}
    
    // Clean abandoned backups older than X days
    public function cleanup_abandoned( $days = 1 ) {}
}
```

### Backup Storage Location
```
WordPress Root/
└── .stack2-backup/
    ├── backup_550e8400_1715425800/
    │   ├── wp-content-files.zip
    │   ├── wordpress-database.sql.gz
    │   ├── manifest.json
    │   └── .backup-lock              # Lock file while in progress
    └── cleanup/                       # Scheduled cleanup targets
```

### Temporary File Cleanup Strategy
1. **Lock file**: Create `.backup-lock` during active backup to prevent concurrent backups
2. **Cron cleanup**: WordPress cron runs hourly to clean:
   - Backups older than 24 hours (regardless of status)
   - Failed backups older than 6 hours
   - Lock files older than 2 hours (indicates crashed backup)
3. **On-demand cleanup**: App calls DELETE endpoint to trigger immediate cleanup
4. **Fallback**: Add manual cleanup command: `wp stack2-backup cleanup --force`

### Progress Reporting
Plugin stores progress state in WordPress options (cache) or transients:

```php
// Transient key format: stack2_backup_progress_{job_id}
$progress = [
    'phase' => 'file_compression',
    'files_processed' => 450,
    'files_total' => 1250,
    'bytes_processed' => 52428800,
    'bytes_total' => 314572800,
    'percent' => 16,
    'current_file' => 'path/to/current/file',
    'started_at' => time(),
    'updated_at' => time()
];

set_transient( 'stack2_backup_progress_' . $job_id, $progress, 24 * HOUR_IN_SECONDS );
```

### Database Export Strategy
1. **Use wp-cli if available**: `wp db export`
   - Faster, handles WordPress-specific configuration
   - Fallback: Use mysqldump command
2. **Single dump file**: Export all tables to single SQL file
3. **Compression**: Compress SQL to `.sql.gz` for transfer
4. **Verification**: Verify file size > 0 and contains SQL statements

### Exclusion Patterns
Exclude from backup to reduce size:
```
- node_modules/
- .git/
- cache directories
- temporary upload sessions
- .stack2-backup/
- wp-content/upgrade/
- older versions of plugins/themes
- large static CDN caches
```

## App Integration Points

### 1. Backup Initiation (WebsiteBackupService.cs)

Update `ExecuteAsync` method in WebsiteBackupJob:

```csharp
// Current: Call plugin endpoint and store manifest
// Need to add: Poll for backup completion, download components

private async Task DownloadBackupComponentsAsync(
    string jobId,
    WebsiteBackup backup,
    CancellationToken cancellationToken)
{
    // 1. Check backup status until completed
    while (backup.Status != WebsiteBackupStatus.Completed)
    {
        var status = await GetBackupStatusAsync(jobId, cancellationToken);
        backup.Status = MapStatusFromPlugin(status.Status);
        backup.Progress = status.Progress;
        
        await dbContext.SaveChangesAsync(cancellationToken);
        
        if (backup.Status != WebsiteBackupStatus.Completed)
        {
            await Task.Delay(TimeSpan.FromSeconds(5), cancellationToken);
        }
    }
    
    // 2. Download file component if included
    if (backup.IncludeFiles)
    {
        await backupBlobStorageService.UploadAsync(
            backup.BlobPrefix + "/files.zip",
            await DownloadComponentAsync(jobId, "files", cancellationToken),
            cancellationToken);
    }
    
    // 3. Download database component if included
    if (backup.IncludeDatabase)
    {
        await backupBlobStorageService.UploadAsync(
            backup.BlobPrefix + "/database.sql.gz",
            await DownloadComponentAsync(jobId, "database", cancellationToken),
            cancellationToken);
    }
    
    // 4. Cleanup plugin temporary files
    await CleanupPluginBackupAsync(jobId, cancellationToken);
}
```

### 2. Status Updates (New Field Requirements)

Add to WebsiteBackup entity:
```csharp
public string? PluginJobId { get; set; }  // Track plugin job ID for polling
public BackupProgress? Progress { get; set; }  // Current progress
public string? BackupPhase { get; set; }  // file_compression, database_dump, etc.
```

Create BackupProgress class:
```csharp
public class BackupProgress
{
    public string Phase { get; set; }
    public int FilesProcessed { get; set; }
    public int FilesTotal { get; set; }
    public long BytesProcessed { get; set; }
    public long BytesTotal { get; set; }
    public int Percent { get; set; }
}
```

### 3. Error Handling

**Retry strategy** for plugin communication:
- Retry failed initiation up to 3 times (exponential backoff)
- Timeout status checks after 24 hours
- Log all plugin errors for debugging

**Plugin errors to handle**:
- "Another backup is already in progress" → wait and retry
- "Insufficient disk space" → fail backup, notify user
- "Database credentials invalid" → fail backup, log credentials issue
- Network timeout → retry with backoff

## Testing Strategy

### Unit Tests (Plugin)
- HMAC signature verification with valid/invalid keys
- Timestamp validation within/outside 300s window
- Backup initiation with valid/invalid parameters
- Status tracking through phases
- File compression with exclusion patterns
- Database export with verification

### Integration Tests (Plugin)
- Full backup cycle (initiate → complete → download → cleanup)
- Concurrent backup prevention
- Large file handling (> 1GB)
- Partial failure recovery
- Cleanup of abandoned backups

### Integration Tests (App)
- Backup job execution end-to-end
- Plugin communication retry logic
- Blob storage upload verification
- Progress tracking and UI updates
- Error scenarios and recovery

### Manual Testing Checklist
- [ ] Queue backup from UI, verify job starts
- [ ] Monitor progress in Backups tab
- [ ] Download backup files and verify integrity
- [ ] Restore from backup and verify data
- [ ] Trigger concurrent backup, verify prevention
- [ ] Fill disk space, verify error handling
- [ ] Manually cleanup plugin temp files
- [ ] Test HMAC signature validation
- [ ] Test timestamp replay protection

## Rollout Plan

### Phase 1: Plugin Development
1. Implement backup manager classes
2. Implement REST API endpoints with HMAC auth
3. Add plugin admin settings UI
4. Unit tests for all components
5. Internal testing with test WordPress site

### Phase 2: App Integration
1. Update WebsiteBackupService polling logic
2. Add progress tracking fields to WebsiteBackup
3. Implement blob storage download/upload
4. Integration tests
5. Deploy to staging

### Phase 3: User Validation
1. Testing with actual customer websites
2. Performance testing (large sites)
3. Failure scenario testing
4. Documentation and help articles

### Phase 4: Production Release
1. Deploy updated plugin to customers
2. Deploy updated app endpoints
3. Monitor logs and performance
4. Customer communication and support

## Monitoring & Observability

### Logging Requirements
Plugin should log:
- Backup initiation (with backup_id and job_id)
- Status transitions (phase changes)
- Progress milestones (25%, 50%, 75%)
- Errors and exceptions with stack traces
- Cleanup operations and freed space
- HMAC verification failures

Example log format:
```
[2026-05-11T14:30:00Z] [INFO] Backup initiated: backup_id=550e8400, job_id=backup_550e8400_1715425800
[2026-05-11T14:30:15Z] [INFO] Phase: file_compression started
[2026-05-11T14:31:30Z] [INFO] File compression: 450/1250 files (36%), 150MB processed
[2026-05-11T14:33:00Z] [INFO] Phase: database_dump started
[2026-05-11T14:34:15Z] [INFO] Backup completed: total size 370MB
[2026-05-11T14:34:20Z] [INFO] Cleanup triggered: freed 370MB
```

### App Logging Requirements
Log in WebsiteBackupService:
- Backup job start/end
- Plugin communication (request/response)
- Status poll iterations
- Blob storage upload progress
- Errors and retries

## Security Considerations

1. **HMAC Signature**: 
   - Uses same shared secret as plugin inventory
   - Prevents unauthorized backup requests
   - Protects against man-in-the-middle

2. **Timestamp Validation**:
   - 300s tolerance prevents replay attacks
   - Clock skew on WordPress host would break this

3. **API Key Rotation**:
   - Existing `POST /api/websites/{websiteId}/plugin-api-key/rotate` endpoint
   - Admin can rotate if key is compromised
   - Old key invalidated immediately

4. **Access Control**:
   - App endpoints require authentication
   - Plugin endpoints require HMAC signature
   - No backup data exposed without authentication

5. **File Permissions**:
   - Temporary backup files readable only by WordPress user
   - Database dump file not accessible directly via web server
   - Cleanup removes all temporary files

## Configuration Reference

### App Configuration (appsettings.json)
```json
{
  "Backup": {
    "MaxConcurrentBackups": 1,
    "StatusPollIntervalSeconds": 5,
    "StatusPollTimeoutHours": 24,
    "RetryMaxAttempts": 3,
    "RetryBackoffMs": 1000
  }
}
```

### Plugin Configuration (WordPress Admin)
```
Stack2 Settings → Backups
├── Max backup size (MB): 5000
├── Exclude directories: (textarea)
├── Database export method: wp-cli / mysqldump
├── Keep temporary files for (hours): 24
├── Enable compression: ✓
├── Cleanup abandoned backups after (hours): 6
```

## Success Criteria

✅ Backup can be initiated from app UI  
✅ Plugin creates files + database backup  
✅ Progress is trackable through status endpoint  
✅ Backup components downloadable to blob storage  
✅ Temporary files cleaned up automatically  
✅ Concurrent backups prevented  
✅ All errors handled gracefully with user-friendly messages  
✅ HMAC signature verification prevents unauthorized access  
✅ Performance: backup completion within 30 minutes for typical WordPress site  
✅ Monitoring: All operations logged for debugging  

## Future Enhancements

1. **Incremental backups**: Only backup changed files since last backup
2. **Backup versioning**: Keep multiple versions with retention policy
3. **Restore functionality**: App UI to restore from backup
4. **External storage**: Direct plugin upload to S3/Azure instead of app-mediated download
5. **Scheduled backups**: Plugin-side scheduling for daily/weekly backups
6. **Backup verification**: Automated integrity checks on stored backups
7. **Differential sync**: Only sync changed parts of backups to cloud storage
