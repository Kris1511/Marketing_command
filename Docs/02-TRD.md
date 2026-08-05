# 02-TRD.md - Technical Requirements Document

## Digital Marketing Dashboard - Laravel Migration

**Version:** 1.0  
**Last Updated:** August 2026  
**Target Environment:** Production (Linux/Docker, MySQL 8.x, PHP 8.2+)

---

## 1. Technology Stack

### Backend
- **Framework**: Laravel 11 (PHP 8.2+)
- **Database**: MySQL 8.0.x (InnoDB engine)
- **Cache**: Redis 7.x (sessions, query caching, job queue)
- **API**: RESTful JSON API (Laravel Resource classes)
- **Authentication**: Laravel Breeze or Fortify (session-based + API tokens)
- **Task Queue**: Redis + Laravel Queue (for async jobs)
- **File Storage**: Local filesystem or S3-compatible storage

### Frontend
- **View Engine**: Blade (server-side templates)
- **HTML/CSS/JavaScript**: Vanilla (existing design)
- **Build Tool**: Vite (asset bundling, HMR)
- **Styling**: Tailwind CSS (optional enhancement, not required)
- **Chart Library**: Chart.js 4.x (already in use)

### DevOps & Deployment
- **Version Control**: Git (GitHub/GitLab)
- **CI/CD**: GitHub Actions or GitLab CI
- **Containerization**: Docker + Docker Compose (development, optional production)
- **Web Server**: Nginx 1.24+ or Apache 2.4+ (production)
- **SSL/TLS**: Let's Encrypt certificates
- **Logging**: ELK Stack or Cloudwatch (production)
- **Monitoring**: New Relic or Datadog (optional)

---

## 2. Architecture Overview

### High-Level System Design

```
┌─────────────────────────────────────────────────────────────────┐
│                       CLIENT BROWSERS                            │
│              (Desktop, Tablet, Mobile - Chrome/FF/Safari)       │
└────────────────────────┬────────────────────────────────────────┘
                         │ HTTPS
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│                    LOAD BALANCER (Nginx)                        │
│                   (sticky sessions enabled)                     │
└────────────────┬─────────────────────────┬─────────────────────┘
                 │                         │
      ┌──────────▼──────────┐  ┌──────────▼──────────┐
      │   Laravel App 1     │  │   Laravel App N     │
      │  (Stateless - PHP)  │  │  (Stateless - PHP)  │
      └──────────┬──────────┘  └──────────┬──────────┘
                 │                         │
                 └────────────┬────────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        │                     │                     │
        ▼                     ▼                     ▼
    ┌────────┐          ┌────────┐          ┌──────────┐
    │ MySQL  │          │ Redis  │          │ S3/CDN   │
    │Database│          │ Cache/ │          │ (Media)  │
    │(Master/│          │ Sessions          │          │
    │Replica)│          │ Queue  │          │          │
    └────────┘          └────────┘          └──────────┘
        │                     
        ▼                     
    ┌─────────────────┐      
    │Backups (AWS S3) │      
    └─────────────────┘      
```

### Key Design Patterns

1. **MVC Architecture**: Models (Eloquent), Views (Blade), Controllers (Request handlers)
2. **Repository Pattern**: Abstract database queries into repository classes (optional but recommended)
3. **Service Layer**: Business logic isolated in service classes
4. **Single Responsibility**: Each class has one reason to change
5. **Dependency Injection**: Use Laravel container for loose coupling
6. **Event-Driven**: Publish events for audit logging, notifications, webhooks

---

## 3. Database Architecture

### 3.1 MySQL Configuration
- **Engine**: InnoDB (ACID compliance, foreign key constraints)
- **Charset**: utf8mb4 (emoji, international characters support)
- **Collation**: utf8mb4_unicode_ci
- **Max Connections**: 500 (scale based on load)
- **Connection Pool**: MySQL connection pooling via ProxySQL (production)

### 3.2 Core Tables

#### `users`
```sql
bigint id PK
string name
string email UNIQUE
string password (hashed)
enum role (admin, manager, executive, viewer)
datetime last_login
boolean active (soft delete via `deleted_at`)
timestamps
```
**Indexes**: email, role, created_at

#### `workspaces` / `clients`
```sql
bigint id PK
string name UNIQUE
string industry
string primary_contact
string primary_contact_email
decimal budget (optional)
enum status (active, paused, archived)
text logo_url
bigint owner_id FK (users)
timestamps
```
**Indexes**: owner_id, status, created_at

#### `user_workspace_roles`
```sql
bigint id PK
bigint user_id FK
bigint workspace_id FK
enum role (admin, manager, executive, viewer)
timestamps
UNIQUE(user_id, workspace_id)
```
**Indexes**: user_id, workspace_id

#### `campaigns`
```sql
bigint id PK
bigint workspace_id FK
string name
string platform (meta_facebook, instagram, youtube, google_ads, linkedin, twitter)
enum status (draft, scheduled, active, paused, completed)
decimal budget
decimal spent
date start_date
date end_date
text description
bigint created_by_id FK (users)
timestamps
```
**Indexes**: workspace_id, platform, status, created_at

#### `campaign_metrics`
```sql
bigint id PK
bigint campaign_id FK
date metric_date
bigint impressions
bigint clicks
bigint conversions
bigint shares
bigint comments
decimal revenue
decimal cpc (cost per click)
decimal ctr (click-through rate)
timestamps
UNIQUE(campaign_id, metric_date)
```
**Indexes**: campaign_id, metric_date

#### `leads`
```sql
bigint id PK
bigint workspace_id FK
bigint campaign_id FK (nullable)
string name
string email UNIQUE_PER_WORKSPACE
string phone
enum status (new, contacted, qualified, converted, lost, ignored)
enum source (facebook_lead_ad, google_lead_form, manual, website_form, instagram, linkedin, email, other)
bigint assigned_to_id FK (users, nullable)
datetime contacted_at (nullable)
datetime converted_at (nullable)
text notes
timestamps
```
**Indexes**: workspace_id, status, source, assigned_to_id, created_at

#### `lead_activities`
```sql
bigint id PK
bigint lead_id FK
bigint user_id FK
enum activity_type (call, email, sms, meeting, note, status_change)
text activity_notes
datetime activity_date
timestamps
```
**Indexes**: lead_id, activity_date

#### `posts` / `publishing`
```sql
bigint id PK
bigint workspace_id FK
bigint created_by_id FK (users)
text content
string platform_list (json: ["facebook", "instagram", "linkedin"])
enum status (draft, scheduled, published, failed)
datetime scheduled_at
datetime published_at (nullable)
text media_urls (json array)
enum approval_status (draft, submitted, approved, rejected)
bigint approved_by_id FK (users, nullable)
timestamps
```
**Indexes**: workspace_id, status, scheduled_at, published_at

#### `integrations`
```sql
bigint id PK
bigint workspace_id FK
enum platform (facebook, instagram, youtube, google_analytics, search_console, google_business, linkedin, twitter)
string account_id (platform's business account ID)
string account_name
boolean is_connected (true if tokens are fresh)
datetime last_sync_at
datetime token_expires_at
string connection_status (connected, expired, error, pending_auth)
string error_message (nullable)
timestamps
UNIQUE(workspace_id, platform, account_id)
```
**Indexes**: workspace_id, platform, is_connected, last_sync_at

#### `audit_logs`
```sql
bigint id PK
bigint user_id FK (nullable, for system actions)
bigint workspace_id FK
string entity_type (campaign, lead, post, integration, user, workspace)
bigint entity_id
enum action (create, update, delete, publish, sync, login, logout)
json changes_before (nullable)
json changes_after (nullable)
string ip_address
string user_agent
timestamps
```
**Indexes**: workspace_id, user_id, entity_type, created_at

#### `notifications`
```sql
bigint id PK
bigint user_id FK
bigint workspace_id FK
enum type (lead_assigned, post_published, campaign_milestone, integration_error, team_invite)
string title
text message
string related_entity (lead:123, campaign:456)
boolean is_read
datetime read_at (nullable)
timestamps
```
**Indexes**: user_id, is_read, created_at

#### `reports`
```sql
bigint id PK
bigint workspace_id FK
bigint created_by_id FK (users)
string title
enum report_type (overview, channel_performance, lead_summary, roi_analysis)
json filters (date_range, platforms, metrics_included)
json data (aggregated metrics)
string export_format (pdf, csv, json)
text file_path
datetime generated_at
timestamps
```
**Indexes**: workspace_id, created_at

---

### 3.3 Database Relationships (ERD)

```
users
  ├─ hasMany: user_workspace_roles
  ├─ hasMany: campaigns (created_by)
  ├─ hasMany: posts (created_by)
  ├─ hasMany: lead_activities
  └─ hasMany: audit_logs

workspaces
  ├─ hasMany: user_workspace_roles
  ├─ belongsTo: owner (users)
  ├─ hasMany: campaigns
  ├─ hasMany: leads
  ├─ hasMany: posts
  ├─ hasMany: integrations
  ├─ hasMany: audit_logs
  └─ hasMany: notifications

campaigns
  ├─ belongsTo: workspace
  ├─ belongsTo: created_by (users)
  └─ hasMany: campaign_metrics

campaign_metrics
  └─ belongsTo: campaign

leads
  ├─ belongsTo: workspace
  ├─ belongsTo: campaign (nullable)
  ├─ belongsTo: assigned_to (users)
  └─ hasMany: lead_activities

lead_activities
  ├─ belongsTo: lead
  └─ belongsTo: user

posts
  ├─ belongsTo: workspace
  ├─ belongsTo: created_by (users)
  └─ belongsTo: approved_by (users, nullable)

integrations
  └─ belongsTo: workspace

audit_logs
  ├─ belongsTo: user (nullable)
  └─ belongsTo: workspace

notifications
  ├─ belongsTo: user
  └─ belongsTo: workspace
```

---

## 4. API Endpoint Structure

### Base URL
```
https://api.example.com/v1
```

### Authentication
- **Session-based**: Standard Laravel sessions (cookies)
- **Token-based**: Laravel Sanctum tokens (for mobile apps, SPAs)
- **Header**: `Authorization: Bearer <token>` or `X-Client-Workspace-ID: <workspace_id>`

### Core Endpoints

#### Authentication
```
POST   /auth/login                    - Email + password login
POST   /auth/logout                   - Destroy session
POST   /auth/register                 - Signup (if allowed)
POST   /auth/forgot-password          - Send reset email
POST   /auth/reset-password           - Confirm password reset
GET    /auth/me                       - Current user details
POST   /auth/refresh-token            - Refresh API token
```

#### Workspaces / Clients
```
GET    /workspaces                    - List user's workspaces
POST   /workspaces                    - Create new workspace
GET    /workspaces/{id}               - Get workspace details
PUT    /workspaces/{id}               - Update workspace
DELETE /workspaces/{id}               - Delete workspace
GET    /workspaces/{id}/members       - List team members
POST   /workspaces/{id}/members       - Add team member
```

#### Campaigns
```
GET    /workspaces/{wid}/campaigns    - List campaigns
POST   /workspaces/{wid}/campaigns    - Create campaign
GET    /workspaces/{wid}/campaigns/{id}
PUT    /workspaces/{wid}/campaigns/{id}
DELETE /workspaces/{wid}/campaigns/{id}
GET    /workspaces/{wid}/campaigns/{id}/metrics
```

#### Dashboard / Metrics
```
GET    /workspaces/{wid}/dashboard/summary - Overview stats
GET    /workspaces/{wid}/dashboard/metrics  - Detailed metrics
GET    /workspaces/{wid}/dashboard/channels - Connected channels status
```

#### Leads
```
GET    /workspaces/{wid}/leads       - List leads (with filters)
POST   /workspaces/{wid}/leads       - Create lead
GET    /workspaces/{wid}/leads/{id}  - Get lead details
PUT    /workspaces/{wid}/leads/{id}  - Update lead
DELETE /workspaces/{wid}/leads/{id}  - Delete lead
GET    /workspaces/{wid}/leads/{id}/activities - Lead history
POST   /workspaces/{wid}/leads/{id}/activities - Add activity
```

#### Publishing / Posts
```
GET    /workspaces/{wid}/posts       - List posts
POST   /workspaces/{wid}/posts       - Create post
GET    /workspaces/{wid}/posts/{id}  - Get post
PUT    /workspaces/{wid}/posts/{id}  - Update post
POST   /workspaces/{wid}/posts/{id}/publish - Publish immediately
POST   /workspaces/{wid}/posts/{id}/approve  - Approve for publication
```

#### Integrations
```
GET    /workspaces/{wid}/integrations         - List connections
GET    /workspaces/{wid}/integrations/status  - Health check
POST   /workspaces/{wid}/integrations/{platform}/connect  - Start OAuth
GET    /workspaces/{wid}/integrations/callback - OAuth redirect
POST   /workspaces/{wid}/integrations/{id}/test - Test connection
DELETE /workspaces/{wid}/integrations/{id}    - Disconnect
POST   /workspaces/{wid}/integrations/sync    - Manual sync
```

#### Reports
```
GET    /workspaces/{wid}/reports              - List saved reports
POST   /workspaces/{wid}/reports              - Generate report
GET    /workspaces/{wid}/reports/{id}         - Get report data
GET    /workspaces/{wid}/reports/{id}/export  - Export (pdf/csv)
DELETE /workspaces/{wid}/reports/{id}         - Delete report
```

#### Team & Users
```
GET    /workspaces/{wid}/team                - List team members
POST   /workspaces/{wid}/team                - Invite team member
PUT    /workspaces/{wid}/team/{uid}          - Update member role
DELETE /workspaces/{wid}/team/{uid}          - Remove member
GET    /admin/users                          - Admin: list all users
GET    /admin/audit-logs                     - Admin: audit log
```

#### Notifications
```
GET    /users/notifications                  - List user's notifications
PUT    /users/notifications/{id}             - Mark as read
DELETE /users/notifications/{id}             - Delete notification
PUT    /users/notification-preferences       - Update preferences
```

---

## 5. Security & Compliance

### 5.1 Authentication & Authorization
- **OTP Login**: One-time password sent via email (recommended for production)
- **Password Requirements**: Minimum 12 characters, complexity rules
- **Session Timeout**: 8 hours of inactivity, auto-logout
- **RBAC**: Role-based access control (Admin, Manager, Executive, Viewer)
- **Workspace Isolation**: Query scope middleware ensures users only access assigned workspaces

### 5.2 Data Protection
- **At Rest**: AES-256 encryption for sensitive fields (API tokens, passwords)
- **In Transit**: TLS 1.3+ for all communications
- **Database**: Row-level security (Laravel policies), column-level encryption for PII
- **Backups**: Automated daily backups with encryption, 30-day retention

### 5.3 API Security
- **CORS**: Allow only trusted domains (production)
- **Rate Limiting**: 1000 requests/minute per user, 10000/minute per IP
- **Input Validation**: All inputs validated server-side (Laravel Validation rules)
- **Output Escaping**: Blade auto-escapes HTML by default
- **CSRF Protection**: CSRF tokens on all POST/PUT/DELETE requests
- **SQL Injection**: Prevented via Eloquent parameterized queries
- **XSS Protection**: Blade escaping, Content Security Policy (CSP) headers

### 5.4 Audit & Compliance
- **Audit Logging**: Every create/update/delete logged with user, timestamp, IP, changes
- **GDPR**: Support for data export, deletion (DSAR compliance)
- **CCPA**: Right to deletion, data portability
- **SOC 2**: Preparation (logging, access controls, encryption)

### 5.5 Third-Party Integrations
- **OAuth 2.0**: Use authorization code flow for Facebook, Google, LinkedIn, Twitter
- **API Tokens**: Refresh tokens stored encrypted on backend only
- **Webhook Validation**: Verify webhook signatures to prevent spoofing
- **Rate Limits**: Respect platform rate limits, implement exponential backoff

---

## 6. Performance Requirements

### 6.1 Load Times
- **Home/Dashboard Load**: < 2 seconds (first paint), < 3 seconds (fully loaded)
- **Chart Rendering**: < 500ms (canvas draw time)
- **Modal Open**: < 200ms
- **Search/Filter**: < 1 second

### 6.2 Scalability Targets
- **Concurrent Users**: 1000+ without performance degradation
- **Database Throughput**: 10,000 queries/second (read), 1000 queries/second (write)
- **API Throughput**: 10,000 requests/second
- **Storage**: Initial 100GB capacity, scale to 1TB+

### 6.3 Optimization Strategies
- **Caching**:
  - Redis for session storage (vs file)
  - Query caching (campaigns, integrations, metrics)
  - HTTP caching headers (Cache-Control, ETag)
  - Blade view caching
  
- **Database Optimization**:
  - Eager loading (Eloquent with/load)
  - Query result pagination (50 items/page)
  - Composite indexes (workspace_id, created_at)
  - Partitioning metrics table by date
  
- **Frontend Optimization**:
  - Minified CSS/JS (Vite)
  - Lazy loading for charts (render on scroll)
  - Image optimization (WebP conversion)
  - Code splitting (per page/module)

### 6.4 Monitoring
- **APM**: New Relic or DataDog for backend performance
- **RUM**: Real User Monitoring for frontend
- **Uptime**: Uptime Robot or similar (5-minute checks)
- **Error Tracking**: Sentry or Bugsnag
- **Logs**: Centralized logging (ELK, Cloudwatch)

---

## 7. Development Guidelines

### 7.1 Code Structure
```
laravel-project/
├── app/
│   ├── Models/              # Eloquent models
│   ├── Http/
│   │   ├── Controllers/     # Route handlers
│   │   ├── Requests/        # Form request validation
│   │   └── Resources/       # API responses
│   ├── Services/            # Business logic
│   ├── Repositories/        # Database abstraction (optional)
│   ├── Events/              # Event classes
│   ├── Listeners/           # Event listeners
│   ├── Jobs/                # Queue jobs
│   ├── Mail/                # Email classes
│   ├── Policies/            # Authorization policies
│   └── Exceptions/          # Custom exceptions
├── routes/
│   ├── api.php              # API routes (prefix: /api/v1)
│   └── web.php              # Web routes (server-rendered)
├── resources/
│   ├── views/               # Blade templates
│   │   ├── layouts/         # app.blade.php
│   │   ├── dashboard/
│   │   ├── campaigns/
│   │   ├── leads/
│   │   ├── reports/
│   │   ├── integrations/
│   │   ├── team/
│   │   ├── components/      # Reusable Blade components
│   │   └── modals/
│   ├── css/
│   │   └── app.css          # Compiled styles (from assets/styles.css)
│   └── js/
│       └── app.js           # Entry point (Vite)
├── database/
│   ├── migrations/          # Schema changes
│   ├── seeders/             # Data seeders
│   └── factories/           # Model factories (testing)
├── tests/
│   ├── Unit/                # Unit tests
│   ├── Feature/             # Integration tests
│   └── TestCase.php         # Base test class
├── storage/                 # File uploads, logs
├── bootstrap/               # Framework bootstrap
├── config/                  # Configuration files
├── .env.example             # Environment template
├── docker-compose.yml       # Docker compose (dev)
├── Dockerfile               # Docker image
└── composer.json            # PHP dependencies
```

### 7.2 Coding Standards
- **PSR-12**: Follow PHP standard recommendations
- **Laravel Convention**: Use Laravel conventions (naming, structure)
- **Type Hints**: Use strict type declarations
- **Comments**: Document complex logic, not obvious code
- **Testing**: Aim for 80%+ code coverage (unit + feature tests)

### 7.3 Database Migrations
- Each migration has one logical purpose
- Use `change()` carefully (not all databases support modification)
- Always add rollback logic (`down()` method)
- Name files descriptively: `create_campaigns_table`, `add_is_active_to_leads`

---

## 8. Deployment & DevOps

### 8.1 Environments
- **Local**: Docker Compose (PHP 8.2, MySQL 8, Redis)
- **Staging**: Kubernetes or traditional VPS (mirrors production)
- **Production**: Auto-scaled infrastructure (AWS, GCP, or DigitalOcean)

### 8.2 CI/CD Pipeline
1. Push to GitHub
2. GitHub Actions triggers (or GitLab CI)
3. Run tests (PHPUnit, Laravel)
4. Build Docker image
5. Push to registry (Docker Hub, ECR)
6. Deploy to staging
7. Run smoke tests
8. Deploy to production (manual approval)
9. Health checks and monitoring

### 8.3 Database Migrations
- Run `php artisan migrate --force` before app startup
- Use seeders for initial data (test data, static lookups)
- Version all migrations in Git

---

## 9. Monitoring & Alerting

### 9.1 Key Metrics
- **CPU Usage**: Alert if > 80% for 5 minutes
- **Memory**: Alert if > 85%
- **Disk**: Alert if > 90%
- **Database Connections**: Alert if > 400/500
- **API Response Time**: Alert if p95 > 3 seconds
- **Error Rate**: Alert if > 1%
- **Redis Memory**: Alert if > 80%

### 9.2 Incident Response
- PagerDuty for on-call escalation
- Slack integration for alerts
- Runbook documentation for each alert
- Post-incident reviews (blameless)

---

## 10. Testing Strategy

### 10.1 Test Types
- **Unit Tests**: Model methods, service methods (PHPUnit)
- **Feature Tests**: API endpoints, controller logic (Laravel Test)
- **Integration Tests**: Database interactions, third-party APIs
- **UI Tests**: Critical user workflows (optional, Selenium/Cypress)

### 10.2 Coverage Goals
- Overall: 80%+
- Controllers: 90%+
- Models: 85%+
- Services: 90%+

### 10.3 Test Data
- Use factories for realistic data generation
- Use seeders for consistent state
- Avoid fixtures (prefer factories for maintainability)

---

## 11. Third-Party Dependencies

### Critical Dependencies
- **laravel/framework** (11.x) - Core framework
- **laravel/sanctum** - API token authentication
- **laravel/telescope** - Development debugging
- **guzzlehttp/guzzle** - HTTP client (OAuth, webhooks)
- **barryvdh/laravel-dompdf** - PDF generation
- **spatie/laravel-backup** - Automated backups
- **spatie/laravel-audit-log** - Audit logging

### Optional Dependencies
- **laravel/excel** - Excel export/import
- **laravel/socialite** - OAuth integrations (alternative to manual implementation)
- **sentry/sentry-laravel** - Error tracking
- **newrelic/newrelic-php-agent** - Performance monitoring

---

## 12. Known Constraints & Trade-offs

### Preserved Design
- Existing visual design (colors, typography, spacing) maintained
- CSS class structure preserved for minimal refactoring
- Chart.js library integration unchanged
- Modal and component patterns reused

### Limitations
- No real-time websockets initially (Phase 2)
- No AI/ML features in MVP (Phase 2)
- Batch report generation only (no real-time exports > 100K records)
- Single-region deployment (Phase 2: multi-region)

---

## Sign-Off

**Tech Lead:** [Name]  
**Architect:** [Name]  
**Date:** August 2026  
**Status:** Ready for Implementation

