# 05-DB-SCHEMA.md - Database Schema & Architecture

## Digital Marketing Dashboard - MySQL Database

**Version:** 1.0  
**Database**: MySQL 8.0+  
**Engine**: InnoDB  
**Charset**: utf8mb4 (unicode)

---

## 1. Schema Overview

### 1.1 Table Count & Purpose
- **Core Tables**: 12
- **Pivot/Junction Tables**: 2
- **Total**: 14 tables (expandable for phases 2+)

### 1.2 Design Principles
1. **Normalization**: 3NF (Third Normal Form) with strategic denormalization
2. **Constraints**: Foreign keys, unique constraints for data integrity
3. **Soft Deletes**: Keep data for audit trails (via `deleted_at` timestamp)
4. **Tenant Isolation**: Every record includes `workspace_id` for multi-tenancy
5. **Audit Trail**: All changes logged in `audit_logs` table
6. **Timestamps**: All tables include `created_at` and `updated_at`

---

## 2. Core Tables

### 2.1 Users Table

```sql
CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  
  -- Role: admin (system), manager, executive, viewer
  role ENUM('admin', 'manager', 'executive', 'viewer') DEFAULT 'viewer',
  
  -- User status
  is_active BOOLEAN DEFAULT TRUE,
  last_login_at TIMESTAMP NULL,
  
  -- Soft delete
  deleted_at TIMESTAMP NULL,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  INDEX idx_email (email),
  INDEX idx_role (role),
  INDEX idx_created_at (created_at)
);
```

**Fields:**
- `id`: Unique user identifier
- `email`: Login identifier (case-insensitive search)
- `password`: Bcrypt hashed
- `role`: Default system role (overridden per workspace via `user_workspace_roles`)
- `last_login_at`: For activity tracking

**Relationships:**
- `hasMany`: user_workspace_roles
- `hasMany`: campaigns (created_by)
- `hasMany`: posts (created_by)
- `hasMany`: lead_activities
- `hasMany`: audit_logs

---

### 2.2 Workspaces Table

```sql
CREATE TABLE workspaces (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  name VARCHAR(255) NOT NULL,
  industry VARCHAR(100),
  primary_contact VARCHAR(255),
  primary_contact_email VARCHAR(255),
  
  budget DECIMAL(12, 2) UNSIGNED,
  status ENUM('active', 'paused', 'archived') DEFAULT 'active',
  
  logo_url VARCHAR(500),
  
  -- Admin user reference
  owner_id BIGINT UNSIGNED NOT NULL,
  
  -- Soft delete
  deleted_at TIMESTAMP NULL,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_owner_id (owner_id),
  INDEX idx_status (status),
  INDEX idx_created_at (created_at),
  UNIQUE KEY unique_name (name, deleted_at)
);
```

**Key Details:**
- `owner_id`: Primary contact (must be an admin for workspace)
- Unique name per workspace (ignoring deleted ones via expression index)
- Can be "paused" to disable all operations
- Can be "archived" for historical reference only

---

### 2.3 User Workspace Roles (Pivot Table)

```sql
CREATE TABLE user_workspace_roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  user_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  
  -- Role override per workspace
  role ENUM('admin', 'manager', 'executive', 'viewer') NOT NULL,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  
  UNIQUE KEY unique_user_workspace (user_id, workspace_id),
  INDEX idx_workspace_id (workspace_id),
  INDEX idx_role (role)
);
```

**Purpose:** Defines which users belong to which workspaces and their roles

**Relationships:**
- `belongsTo`: user
- `belongsTo`: workspace

---

### 2.4 Campaigns Table

```sql
CREATE TABLE campaigns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  workspace_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  
  -- Platform targeting
  platform ENUM(
    'meta_facebook', 'instagram', 'youtube', 
    'google_ads', 'linkedin', 'twitter',
    'google_analytics', 'search_console',
    'email_marketing', 'other'
  ) NOT NULL,
  
  status ENUM('draft', 'scheduled', 'active', 'paused', 'completed') DEFAULT 'draft',
  
  -- Budget tracking
  budget DECIMAL(12, 2) UNSIGNED,
  spent DECIMAL(12, 2) UNSIGNED DEFAULT 0,
  
  -- Campaign duration
  start_date DATE,
  end_date DATE,
  
  -- Campaign details
  description TEXT,
  
  -- Audit
  created_by_id BIGINT UNSIGNED NOT NULL,
  deleted_at TIMESTAMP NULL,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE RESTRICT,
  
  INDEX idx_workspace_id (workspace_id),
  INDEX idx_platform (platform),
  INDEX idx_status (status),
  INDEX idx_created_at (created_at),
  INDEX idx_date_range (start_date, end_date)
);
```

**Features:**
- Single platform per campaign (can create multiple for multi-channel)
- Budget and spend tracking
- Date range scoping

---

### 2.5 Campaign Metrics Table

```sql
CREATE TABLE campaign_metrics (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  campaign_id BIGINT UNSIGNED NOT NULL,
  metric_date DATE NOT NULL,
  
  -- Performance metrics
  impressions BIGINT UNSIGNED DEFAULT 0,
  clicks BIGINT UNSIGNED DEFAULT 0,
  conversions BIGINT UNSIGNED DEFAULT 0,
  shares BIGINT UNSIGNED DEFAULT 0,
  comments BIGINT UNSIGNED DEFAULT 0,
  
  -- Financial metrics
  revenue DECIMAL(12, 2) UNSIGNED DEFAULT 0,
  
  -- Calculated metrics (denormalized for performance)
  cpc DECIMAL(8, 4) UNSIGNED, -- Cost per click
  ctr DECIMAL(8, 4) UNSIGNED, -- Click-through rate (%)
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  
  UNIQUE KEY unique_campaign_date (campaign_id, metric_date),
  INDEX idx_metric_date (metric_date),
  INDEX idx_created_at (created_at)
);
```

**Denormalization:** CPC and CTR are calculated but stored for query performance

**Retention Policy:** Keep for 2+ years (for historical reports)

---

### 2.6 Leads Table

```sql
CREATE TABLE leads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  workspace_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED,
  
  -- Lead information
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(20),
  
  -- Lead tracking
  status ENUM('new', 'contacted', 'qualified', 'converted', 'lost', 'ignored') DEFAULT 'new',
  
  source ENUM(
    'facebook_lead_ad',
    'google_lead_form',
    'website_form',
    'manual_entry',
    'instagram',
    'linkedin',
    'email',
    'phone_call',
    'other'
  ) NOT NULL,
  
  -- Assignment
  assigned_to_id BIGINT UNSIGNED,
  
  -- Interaction tracking
  contacted_at TIMESTAMP NULL,
  converted_at TIMESTAMP NULL,
  
  notes TEXT,
  
  -- Soft delete
  deleted_at TIMESTAMP NULL,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to_id) REFERENCES users(id) ON DELETE SET NULL,
  
  INDEX idx_workspace_id (workspace_id),
  INDEX idx_status (status),
  INDEX idx_source (source),
  INDEX idx_assigned_to_id (assigned_to_id),
  INDEX idx_created_at (created_at),
  INDEX idx_email_workspace (email, workspace_id)
);
```

**Unique Constraint Logic:**
- Email must be unique within a workspace (prevent duplicates per client)
- Can have same email across different workspaces

---

### 2.7 Lead Activities Table

```sql
CREATE TABLE lead_activities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  lead_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  
  activity_type ENUM(
    'call', 'email', 'sms', 
    'meeting', 'note', 'status_change',
    'assignment_change'
  ) NOT NULL,
  
  activity_notes TEXT,
  activity_date DATETIME NOT NULL,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  
  INDEX idx_lead_id (lead_id),
  INDEX idx_activity_date (activity_date),
  INDEX idx_activity_type (activity_type),
  INDEX idx_created_at (created_at)
);
```

**Purpose:** Audit trail of all interactions with a lead

---

### 2.8 Posts (Publishing) Table

```sql
CREATE TABLE posts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  workspace_id BIGINT UNSIGNED NOT NULL,
  
  content LONGTEXT NOT NULL,
  
  -- Platform targeting (JSON array)
  platform_list JSON NOT NULL,
  
  -- Publishing status
  status ENUM('draft', 'scheduled', 'published', 'failed') DEFAULT 'draft',
  
  scheduled_at TIMESTAMP NULL,
  published_at TIMESTAMP NULL,
  
  -- Media (JSON array of URLs)
  media_urls JSON,
  
  -- Approval workflow
  approval_status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'draft',
  
  created_by_id BIGINT UNSIGNED NOT NULL,
  approved_by_id BIGINT UNSIGNED,
  approval_notes TEXT,
  
  deleted_at TIMESTAMP NULL,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (approved_by_id) REFERENCES users(id) ON DELETE SET NULL,
  
  INDEX idx_workspace_id (workspace_id),
  INDEX idx_status (status),
  INDEX idx_scheduled_at (scheduled_at),
  INDEX idx_published_at (published_at),
  INDEX idx_created_at (created_at)
);
```

**JSON Fields:**
- `platform_list`: `["facebook", "instagram", "linkedin"]`
- `media_urls`: `[{"url": "...", "type": "image"}, ...]`

---

### 2.9 Integrations Table

```sql
CREATE TABLE integrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  workspace_id BIGINT UNSIGNED NOT NULL,
  
  platform ENUM(
    'facebook', 'instagram', 'youtube',
    'google_analytics', 'search_console',
    'google_business', 'linkedin', 'twitter',
    'mailchimp', 'slack'
  ) NOT NULL,
  
  account_id VARCHAR(255) NOT NULL,
  account_name VARCHAR(255),
  
  -- OAuth tokens (encrypted)
  refresh_token LONGTEXT, -- Encrypted
  access_token_expires_at TIMESTAMP NULL,
  
  -- Connection status
  is_connected BOOLEAN DEFAULT TRUE,
  last_sync_at TIMESTAMP NULL,
  token_expires_at TIMESTAMP NULL,
  
  connection_status ENUM(
    'connected', 'expired', 'error', 'pending_auth'
  ) DEFAULT 'pending_auth',
  
  error_message TEXT,
  
  deleted_at TIMESTAMP NULL,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  
  UNIQUE KEY unique_integration (workspace_id, platform, account_id),
  INDEX idx_workspace_id (workspace_id),
  INDEX idx_is_connected (is_connected),
  INDEX idx_last_sync_at (last_sync_at)
);
```

**Security Notes:**
- `refresh_token` must be encrypted using Laravel's encryption
- Never expose in API responses
- Always validate before use

---

### 2.10 Audit Logs Table

```sql
CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  user_id BIGINT UNSIGNED,
  workspace_id BIGINT UNSIGNED NOT NULL,
  
  -- What was changed
  entity_type VARCHAR(50) NOT NULL, -- 'campaign', 'lead', 'post', etc.
  entity_id BIGINT UNSIGNED NOT NULL,
  
  -- Type of action
  action ENUM(
    'create', 'update', 'delete',
    'publish', 'sync', 'login', 'logout',
    'export', 'approve', 'reject'
  ) NOT NULL,
  
  -- Before/after data (JSON for flexibility)
  changes_before JSON,
  changes_after JSON,
  
  -- Request context
  ip_address VARCHAR(45),
  user_agent TEXT,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  
  INDEX idx_workspace_id (workspace_id),
  INDEX idx_entity (entity_type, entity_id),
  INDEX idx_user_id (user_id),
  INDEX idx_action (action),
  INDEX idx_created_at (created_at)
);
```

**Immutable**: Never update or delete audit logs (only insert)

**Retention**: Keep for 2+ years for compliance

---

### 2.11 Notifications Table

```sql
CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  user_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  
  type ENUM(
    'lead_assigned',
    'post_published',
    'campaign_milestone',
    'integration_error',
    'team_invite',
    'lead_converted',
    'report_ready'
  ) NOT NULL,
  
  title VARCHAR(255) NOT NULL,
  message TEXT,
  
  -- Link back to related entity
  related_entity VARCHAR(255), -- "lead:123", "post:456"
  
  is_read BOOLEAN DEFAULT FALSE,
  read_at TIMESTAMP NULL,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  
  INDEX idx_user_id (user_id),
  INDEX idx_is_read (is_read),
  INDEX idx_created_at (created_at)
);
```

---

### 2.12 Reports Table

```sql
CREATE TABLE reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  workspace_id BIGINT UNSIGNED NOT NULL,
  created_by_id BIGINT UNSIGNED NOT NULL,
  
  title VARCHAR(255) NOT NULL,
  report_type ENUM(
    'overview',
    'channel_performance',
    'lead_summary',
    'roi_analysis',
    'custom'
  ) NOT NULL,
  
  -- Report configuration (JSON)
  filters JSON, -- date_range, platforms, metrics
  
  -- Generated data (JSON)
  data JSON,
  
  -- Export
  export_format ENUM('pdf', 'csv', 'json') DEFAULT 'pdf',
  file_path VARCHAR(500),
  
  generated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE RESTRICT,
  
  INDEX idx_workspace_id (workspace_id),
  INDEX idx_generated_at (generated_at),
  INDEX idx_created_at (created_at)
);
```

---

## 3. Relationships (Entity Relationship Diagram)

### 3.1 ERD Summary

```
users
  ├─ 1:N → user_workspace_roles
  ├─ 1:N → campaigns (created_by)
  ├─ 1:N → posts (created_by)
  ├─ 1:N → lead_activities
  ├─ 1:N → audit_logs
  └─ 1:N → reports (created_by)

workspaces
  ├─ 1:N → user_workspace_roles
  ├─ 1:N → campaigns
  ├─ 1:N → leads
  ├─ 1:N → posts
  ├─ 1:N → integrations
  ├─ 1:N → audit_logs
  ├─ 1:N → notifications
  ├─ 1:N → reports
  └─ N:1 ← users (owner_id)

campaigns
  ├─ 1:N → campaign_metrics
  ├─ 1:N → leads (campaign_id)
  └─ N:1 ← workspaces
  └─ N:1 ← users (created_by)

campaign_metrics
  └─ N:1 ← campaigns

leads
  ├─ 1:N → lead_activities
  ├─ N:1 ← workspaces
  ├─ N:1 ← campaigns
  └─ N:1 ← users (assigned_to)

lead_activities
  ├─ N:1 ← leads
  └─ N:1 ← users

posts
  ├─ N:1 ← workspaces
  ├─ N:1 ← users (created_by)
  └─ N:1 ← users (approved_by)

integrations
  └─ N:1 ← workspaces

audit_logs
  ├─ N:1 ← users
  └─ N:1 ← workspaces

notifications
  ├─ N:1 ← users
  └─ N:1 ← workspaces

reports
  ├─ N:1 ← workspaces
  └─ N:1 ← users (created_by)
```

---

## 4. Indexes Strategy

### 4.1 Primary Indexes (Composite for performance)

```sql
-- Workspace-scoped queries (most common)
INDEX idx_workspace_id (workspace_id)

-- Filtering by status/state
INDEX idx_status (status)

-- Date range queries
INDEX idx_created_at (created_at)
INDEX idx_date_range (start_date, end_date)

-- User assignment lookups
INDEX idx_assigned_to_id (assigned_to_id)

-- Combined workspace + status (common filters)
INDEX idx_workspace_status (workspace_id, status)

-- Lead search
INDEX idx_email_workspace (email, workspace_id)
```

### 4.2 Query Examples

```sql
-- Find all leads in a workspace
SELECT * FROM leads WHERE workspace_id = 123 AND deleted_at IS NULL;
-- Uses: idx_workspace_id

-- Find active campaigns
SELECT * FROM campaigns 
WHERE workspace_id = 123 AND status = 'active';
-- Uses: idx_workspace_status

-- Get metrics for chart (last 30 days)
SELECT * FROM campaign_metrics 
WHERE campaign_id = 456 AND metric_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY metric_date ASC;
-- Uses: UNIQUE(campaign_id, metric_date)

-- Audit log query
SELECT * FROM audit_logs 
WHERE workspace_id = 123 AND created_at >= '2026-08-01'
ORDER BY created_at DESC LIMIT 100;
-- Uses: idx_workspace_id, idx_created_at
```

---

## 5. Data Types & Constraints

### 5.1 Integer Types

```
TINYINT     - -128 to 127 (not used, risky)
SMALLINT    - -32,768 to 32,767 (not used)
INT         - -2.1B to 2.1B (IDs under 2 billion - avoid)
BIGINT      - -9.2Q to 9.2Q (IDs, safe choice)
```

**All primary keys use BIGINT UNSIGNED** for future-proofing

### 5.2 String Types

```
VARCHAR(n)  - Variable length, max n characters (for emails, names)
ENUM(...)   - Fixed set of values (for status, platform, type)
TEXT        - Up to 65KB (for content, description)
LONGTEXT    - Up to 4GB (for large content, JSON data)
```

### 5.3 Decimal Precision

```
DECIMAL(12, 2)
├─ Total digits: 12
├─ Decimal places: 2
├─ Range: 0 to 999,999,999.99
└─ Use for: Money, percentages
```

### 5.4 JSON Usage

```sql
-- Platform list (array)
platform_list JSON
INSERT INTO posts (platform_list) VALUES ('["facebook", "instagram"]');

-- Filters (object)
filters JSON
INSERT INTO reports (filters) VALUES ('{"start_date": "2026-08-01", "platforms": ["facebook"]}');

-- Query JSON
SELECT * FROM posts WHERE JSON_CONTAINS(platform_list, '"facebook"');
SELECT JSON_EXTRACT(filters, '$.start_date') FROM reports;
```

---

## 6. Migration Strategy

### 6.1 Laravel Migration Structure

Each table gets a migration file:

```
database/migrations/
├── 2024_01_01_000001_create_users_table.php
├── 2024_01_01_000002_create_workspaces_table.php
├── 2024_01_01_000003_create_user_workspace_roles_table.php
├── 2024_01_01_000004_create_campaigns_table.php
├── 2024_01_01_000005_create_campaign_metrics_table.php
├── 2024_01_01_000006_create_leads_table.php
├── 2024_01_01_000007_create_lead_activities_table.php
├── 2024_01_01_000008_create_posts_table.php
├── 2024_01_01_000009_create_integrations_table.php
├── 2024_01_01_000010_create_audit_logs_table.php
├── 2024_01_01_000011_create_notifications_table.php
└── 2024_01_01_000012_create_reports_table.php
```

### 6.2 Migration Execution

```bash
# Create new migration
php artisan make:migration create_campaigns_table

# Run migrations
php artisan migrate

# Rollback
php artisan migrate:rollback

# Fresh (destructive - dev only)
php artisan migrate:fresh --seed
```

---

## 7. Performance Optimization

### 7.1 Query Optimization

```php
// ✗ Bad: N+1 query problem
$campaigns = Campaign::all();
foreach ($campaigns as $campaign) {
    $metrics = $campaign->metrics; // Query per campaign!
}

// ✓ Good: Eager loading
$campaigns = Campaign::with('metrics')->get();

// ✓ Better: Selective columns
$campaigns = Campaign::select(['id', 'name', 'workspace_id'])
    ->with(['metrics' => function ($query) {
        $query->select(['campaign_id', 'metric_date', 'impressions']);
    }])
    ->get();
```

### 7.2 Query Scoping

```php
// Always scope by workspace
Campaign::where('workspace_id', $workspaceId)->get();

// Use middleware for automatic scoping
class ScopeByWorkspace {
    public function handle($request, Closure $next) {
        $workspaceId = $request->header('X-Client-Workspace-ID');
        // Add to query builder via macro
    }
}
```

### 7.3 Caching Strategy

```php
// Cache expensive aggregations
$metrics = Cache::remember("workspace_{$id}_metrics_30d", 3600, function () {
    return CampaignMetric::where('workspace_id', $id)
        ->whereBetween('metric_date', [
            now()->subDays(30),
            now()
        ])
        ->selectRaw('SUM(impressions) as total_reach')
        ->first();
});

// Invalidate on data change
Cache::forget("workspace_{$id}_metrics_30d");
```

---

## 8. Backup & Recovery

### 8.1 Backup Strategy

```bash
# Daily backup (via cron)
mysqldump -u root -p database_name > /backups/db_$(date +%Y%m%d).sql

# Compressed backup
mysqldump -u root -p database_name | gzip > /backups/db_$(date +%Y%m%d).sql.gz

# Upload to S3
aws s3 cp /backups/db_$(date +%Y%m%d).sql.gz s3://my-backups/
```

### 8.2 Recovery Procedure

```bash
# Restore from backup
mysql -u root -p database_name < /backups/db_backup.sql

# Restore from gzipped backup
gunzip -c /backups/db_backup.sql.gz | mysql -u root -p database_name
```

---

## 9. Monitoring & Health Checks

### 9.1 Key Metrics

```sql
-- Table sizes
SELECT 
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.tables
WHERE table_schema = 'database_name'
ORDER BY (data_length + index_length) DESC;

-- Query performance
SHOW PROCESSLIST; -- Current queries
SHOW SLOW QUERY LOG; -- Slow queries

-- Connection count
SHOW PROCESSLIST;
SHOW STATUS LIKE 'Threads%';
```

### 9.2 Alerts

- Disk usage > 85%
- Slow query (> 3s) detected
- Replication lag > 1 minute
- Connection pool exhausted

---

## 10. Security Considerations

### 10.1 Encryption

```php
// Encrypt sensitive fields
'refresh_token' => encrypted(),

// In model
protected $casts = [
    'refresh_token' => 'encrypted',
];

// Usage
$integration->refresh_token = 'secret'; // Auto-encrypted on save
$token = $integration->refresh_token; // Auto-decrypted on retrieve
```

### 10.2 SQL Injection Prevention

```php
// ✗ Bad (vulnerable)
DB::statement("SELECT * FROM users WHERE email = '$email'");

// ✓ Good (parameterized)
User::where('email', $email)->first();
DB::select('SELECT * FROM users WHERE email = ?', [$email]);
```

### 10.3 Access Control

```php
// Policy checks
Gate::define('view-workspace', function (User $user, Workspace $workspace) {
    return $user->workspaces()->contains($workspace);
});

// In queries
$campaigns = Campaign::whereHas('workspace', function ($q) {
    $q->where('id', auth()->user()->activeWorkspaceId());
})->get();
```

---

## Sign-Off

**Database Architect:** [Name]  
**DBA:** [Name]  
**Date:** August 2026  
**Status:** Approved for Implementation

