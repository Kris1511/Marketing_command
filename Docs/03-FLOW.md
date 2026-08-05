# 03-FLOW.md - User Flows & Process Diagrams

## Digital Marketing Dashboard - Laravel Migration

**Version:** 1.0  
**Last Updated:** August 2026

---

## 1. Authentication Flow

### 1.1 Login Flow (Session-Based)

```
┌────────────────────────┐
│   User Visits App      │
│   (/dashboard)         │
└────────┬───────────────┘
         │
         ▼
┌────────────────────────────┐
│ Middleware Check:          │
│ Is user authenticated?     │
└────────┬──────────┬────────┘
         │          │
      YES│          │NO
         │          │
    ┌────▼──────┐   │
    │ Dashboard │   │
    └────────────┘   │
                     ▼
            ┌─────────────────┐
            │ Login Page      │
            │ (email, pwd)    │
            └────────┬────────┘
                     │
                     ▼
            ┌─────────────────────┐
            │ POST /auth/login    │
            │ (validate + hash)   │
            └────────┬────────────┘
                     │
             ┌───────┴────────┐
             │                │
        VALID│               │INVALID
             │                │
        ┌────▼───┐      ┌─────▼──────┐
        │Create  │      │Return error│
        │Session │      │(flash msg) │
        └────┬───┘      └─────┬──────┘
             │                │
             └────────┬───────┘
                      │
                      ▼
            ┌─────────────────────┐
            │ Set Cookie:         │
            │ XSRF-TOKEN,         │
            │ laravel_session     │
            └────────┬────────────┘
                     │
                     ▼
            ┌──────────────────┐
            │ Redirect to       │
            │ /dashboard       │
            │ (session intact) │
            └──────────────────┘
```

### 1.2 Logout Flow

```
User clicks "Logout"
         │
         ▼
POST /auth/logout
  - Invalidate session
  - Clear cookies
  - Delete session from storage
         │
         ▼
Redirect to /login
```

### 1.3 Session Lifecycle
- **Created**: After successful login
- **Duration**: 8 hours of inactivity (configurable in `config/session.php`)
- **Extended**: On every successful request (sliding window)
- **Destroyed**: On logout or timeout
- **Storage**: Redis (production) or files (development)

---

## 2. Workspace (Client) Selection Flow

```
┌─────────────────────┐
│ User Logs In        │
│ (Multiple Workspaces)
└──────────┬──────────┘
           │
           ▼
┌──────────────────────────────┐
│ GET /workspaces (in header)  │
│ - Fetch all user workspaces  │
│ - Load into dropdown         │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Populate Topbar Selector     │
│ (Current client workspace)   │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ User Selects Workspace       │
│ (onClick in dropdown)        │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Set Header:                  │
│ X-Client-Workspace-ID: 123   │
│ (via SessionStorage)         │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Load Workspace Dashboard     │
│ (all endpoints filtered by   │
│  X-Client-Workspace-ID)      │
└──────────────────────────────┘
```

**Query Middleware (Backend)**:
Every model query is scoped by workspace:

```php
// In middleware or model
if ($workspaceId) {
    $query->where('workspace_id', $workspaceId);
}
```

---

## 3. Dashboard (Overview) Data Load Flow

```
┌────────────────────────────┐
│ User Navigates to           │
│ /dashboard or Overview      │
└──────────┬─────────────────┘
           │
           ▼
┌────────────────────────────────────┐
│ GET /api/v1/workspaces/{id}/       │
│       dashboard/summary            │
└──────────┬───────────────────────────┘
           │ (with workspace scope)
           ▼
┌───────────────────────────────────────┐
│ DashboardController::summary()        │
│ - Query metrics (last 30 days)        │
│ - Count total reach, engagement      │
│ - Count new leads (today)            │
│ - Count active campaigns             │
│ - Calculate monthly revenue          │
│ - Get scheduled posts               │
└──────────┬────────────────────────────┘
           │
           ▼
┌──────────────────────────────────┐
│ Return JSON Response:            │
│ {                                │
│   total_reach: 50000,           │
│   total_engagement: 2500,       │
│   engagement_rate: 5%,          │
│   new_leads: 15,               │
│   active_campaigns: 3,          │
│   scheduled_posts: 5,           │
│   monthly_revenue: $12500       │
│ }                              │
└──────────┬───────────────────────┘
           │
           ▼
┌──────────────────────────────────┐
│ Render Blade Template:           │
│ resources/views/dashboard.blade │
│ - Pass data to view             │
│ - Render metric cards           │
│ - Render charts (Chart.js)      │
│ - Render activity feed          │
└──────────────────────────────────┘
```

---

## 4. Lead Creation & Assignment Flow

### 4.1 Manual Lead Entry

```
┌──────────────────────┐
│ User Clicks:         │
│ "+ Add Lead"        │
└──────────┬───────────┘
           │
           ▼
┌─────────────────────────────────┐
│ Open Lead Modal                 │
│ (resources/views/modals/lead)  │
└──────────┬──────────────────────┘
           │
           ▼
┌─────────────────────────────────┐
│ Fill Form Fields:               │
│ - Name (required)               │
│ - Email (required, validated)   │
│ - Phone (optional)              │
│ - Source (dropdown)             │
│ - Assigned to (user selector)   │
│ - Notes (textarea)              │
└──────────┬──────────────────────┘
           │
           ▼
┌─────────────────────────────────┐
│ POST /api/v1/workspaces/{wid}/  │
│       leads                      │
│ (with CSRF token)               │
└──────────┬──────────────────────┘
           │
           ▼
┌──────────────────────────────────┐
│ Backend Validation:              │
│ - Email unique in workspace     │
│ - Phone format validation       │
│ - Source exists in enum        │
│ - Assigned user belongs to team │
└──────────┬───────────────────────┘
           │
     ┌─────┴─────┐
     │           │
   PASS          FAIL
     │           │
     │      ┌────▼──────────┐
     │      │ Return 422    │
     │      │ With errors   │
     │      │ (toast msg)   │
     │      └──────┬────────┘
     │             │
     │        ┌────▼────┐
     │        │  Modal  │
     │        │  stays  │
     │        │  open   │
     │        └─────────┘
     │
     ▼
┌─────────────────────────────┐
│ Create Lead Record:         │
│ - Generate ID              │
│ - Set status: "new"        │
│ - Set created_at timestamp │
│ - Log audit event          │
└──────────┬──────────────────┘
     │
     ▼
┌─────────────────────────────┐
│ If assigned_to_id set:      │
│ - Create notification for   │
│   assigned user            │
│ - Send email notification  │
└──────────┬──────────────────┘
     │
     ▼
┌─────────────────────────────┐
│ Return 201 Created +        │
│ Lead JSON                   │
└──────────┬──────────────────┘
     │
     ▼
┌─────────────────────────────┐
│ Frontend:                   │
│ - Close modal              │
│ - Refresh leads list       │
│ - Show success toast:      │
│   "Lead added successfully"│
└─────────────────────────────┘
```

### 4.2 Lead Status Update (CRM Pipeline)

```
User views Lead in CRM → Clicks "Change Status"
           │
           ▼
Dropdown opens: New → Contacted → Qualified → Converted/Lost
           │
           ▼
User selects status (e.g., "Contacted")
           │
           ▼
PATCH /api/v1/workspaces/{wid}/leads/{lid}
{
  "status": "contacted",
  "contacted_at": "2026-08-05T10:30:00Z",
  "notes": "Follow-up scheduled for Friday"
}
           │
           ▼
Backend validation + update
           │
           ▼
Create audit_log entry:
{
  entity_type: "lead",
  action: "update",
  changes: { status: ["new", "contacted"] }
}
           │
           ▼
Return 200 + updated lead data
           │
           ▼
Frontend updates row in table
```

---

## 5. Content Publishing Workflow

### 5.1 Create & Schedule Post

```
┌───────────────────────┐
│ User: Publishing Page │
└──────────┬────────────┘
           │
           ▼
┌───────────────────────────────┐
│ Click: "+ Create Post"        │
└──────────┬────────────────────┘
           │
           ▼
┌──────────────────────────────────┐
│ Open Post Editor Modal           │
│ - Text input (multi-platform)   │
│ - Platform selector (checkboxes)│
│ - Media upload                 │
│ - Schedule datetime picker      │
│ - Preview                      │
└──────────┬─────────────────────┘
           │
           ▼
┌──────────────────────────────────┐
│ Fill Content:                   │
│ - Text (max 280 for Twitter,   │
│         2200 for Facebook)     │
│ - Select platforms            │
│ - Upload image/video          │
│ - Set schedule date/time      │
└──────────┬─────────────────────┘
           │
           ▼
┌──────────────────────────────────┐
│ POST /api/v1/workspaces/{wid}/  │
│       posts                      │
│ {                              │
│   "content": "...",           │
│   "platforms": ["facebook", "instagram"],
│   "scheduled_at": "2026-08-10T09:00:00Z",
│   "status": "scheduled",       │
│   "media_urls": ["cdn/img.jpg"]│
│ }                              │
└──────────┬─────────────────────┘
           │
           ▼
┌──────────────────────────────────┐
│ Backend Processing:             │
│ - Validate content length       │
│ - Validate platforms            │
│ - Process media (resize, optimize) │
│ - Create post record           │
│ - Status: "scheduled"          │
└──────────┬─────────────────────┘
           │
           ▼
┌──────────────────────────────────┐
│ Queue Background Job:           │
│ (Laravel Queue)                │
│ - At scheduled_at time         │
│ - Publish to each platform API │
│ - Handle platform-specific rules
└──────────┬─────────────────────┘
           │
           ▼
┌──────────────────────────────────┐
│ Return 201 + Post JSON          │
│ (scheduled time, status)        │
└──────────┬─────────────────────┘
           │
           ▼
┌──────────────────────────────────┐
│ Frontend:                       │
│ - Close modal                  │
│ - Refresh posts calendar      │
│ - Show success: "Post scheduled"
└──────────────────────────────────┘
```

### 5.2 Scheduled Publication (Backend Job)

```
Laravel Scheduler (cron) runs every minute
         │
         ▼
Find posts where scheduled_at <= now()
  AND status = "scheduled"
         │
         ▼
For each post:
  - Get connected integrations
  - Call Platform API
    ├─ Facebook Page API
    ├─ Instagram Business API
    ├─ LinkedIn Page API
    └─ Twitter API
         │
         ▼
Update post.status:
  ├─ "published" if all succeeded
  └─ "failed" if any platform failed
         │
         ▼
Log audit_log entry
         │
         ▼
Send notification to creator:
  "Your post 'X' published successfully"
```

---

## 6. Integration (OAuth) Connection Flow

### 6.1 Connect Platform Account

```
┌──────────────────────────┐
│ User: API Connections    │
│ Page                     │
└──────────┬───────────────┘
           │
           ▼
┌──────────────────────────────────┐
│ Click "Connect Facebook"         │
└──────────┬───────────────────────┘
           │
           ▼
┌────────────────────────────────────┐
│ POST /api/v1/integrations/         │
│  facebook/authorize               │
│ Returns: {                        │
│   auth_url: "https://www.      │
│   facebook.com/v18.0/dialog/    │
│   oauth?client_id=...&         │
│   redirect_uri=..."            │
│ }                               │
└────────────┬──────────────────────┘
             │
             ▼
┌──────────────────────────────────────┐
│ Redirect to Facebook OAuth URL       │
│ (in new tab/window)                 │
└────────────┬───────────────────────┘
             │
             ▼
┌──────────────────────────────────────┐
│ User authorizes app in Facebook     │
│ - Selects business account/page    │
│ - Grants permission               │
└────────────┬───────────────────────┘
             │
             ▼
┌──────────────────────────────────────┐
│ Facebook redirects to callback:      │
│ /api/oauth/facebook/callback        │
│ ?code=XXXX&state=YYYY              │
└────────────┬───────────────────────┘
             │
             ▼
┌──────────────────────────────────────┐
│ Backend Validation:                  │
│ - Verify state token (CSRF)         │
│ - Exchange code for access token    │
│ (OAuth server-to-server)           │
└────────────┬───────────────────────┘
             │
             ▼
┌──────────────────────────────────────┐
│ Store Token Encrypted:               │
│ integrations.refresh_token (backend) │
│ NO token in browser                 │
└────────────┬───────────────────────┘
             │
             ▼
┌──────────────────────────────────────┐
│ Create integration record:           │
│ {                                   │
│   workspace_id: ...,               │
│   platform: "facebook",            │
│   account_id: "page_123",          │
│   is_connected: true,              │
│   last_sync_at: now()              │
│ }                                  │
└────────────┬───────────────────────┘
             │
             ▼
┌──────────────────────────────────────┐
│ Queue background job:                │
│ - Sync historical data             │
│ - Fetch page insights              │
│ - Store in campaign_metrics        │
└────────────┬───────────────────────┘
             │
             ▼
┌──────────────────────────────────────┐
│ Close OAuth window                   │
│ Show success in dashboard:           │
│ "Facebook connected successfully"    │
└──────────────────────────────────────┘
```

### 6.2 Test Connection

```
User clicks "Test Connection"
         │
         ▼
POST /api/v1/integrations/{id}/test
         │
         ▼
Backend:
  - Get stored refresh token
  - Request new access token
  - Try API call (e.g., GET /me)
  - Record result + timestamp
         │
         ▼
Return 200 if successful
Return 400 if token expired or invalid
         │
         ▼
Frontend shows toast:
  ✓ "Connection successful" or
  ✗ "Connection failed - re-authenticate"
```

---

## 7. Report Generation Flow

### 7.1 Custom Report Builder

```
User navigates to Reports
         │
         ▼
Click "+ Generate Report"
         │
         ▼
Open form:
  - Report type (select)
  - Date range (date picker)
  - Platforms (checkboxes)
  - Metrics (checkboxes)
  - Format (PDF or CSV)
         │
         ▼
Submit form
         │
         ▼
POST /api/v1/workspaces/{wid}/reports
{
  "title": "August Campaign Performance",
  "report_type": "channel_performance",
  "filters": {
    "start_date": "2026-08-01",
    "end_date": "2026-08-31",
    "platforms": ["facebook", "instagram"],
    "metrics": ["reach", "engagement", "ctr", "conversions"]
  },
  "format": "pdf"
}
         │
         ▼
Backend:
  - Validate inputs
  - Queue async job (ReportGenerationJob)
  - Return 202 Accepted + job_id
         │
         ▼
Frontend shows loading state
         │
         ▼
Background job:
  - Aggregate data from campaign_metrics
  - Calculate derived metrics (CTR, CPC)
  - Query lead conversions
  - Format data by platform
  - Generate PDF/CSV
  - Upload to S3 (if configured)
  - Store file path in report record
  - Send notification: "Report ready"
         │
         ▼
Frontend polls status or receives notification
         │
         ▼
Download link becomes active
         │
         ▼
User clicks "Download" → File served
```

---

## 8. Audit Logging Flow (All Actions)

```
User takes any action:
  - Create lead
  - Update campaign status
  - Connect integration
  - Publish post
  - Change user role
  - View sensitive data
         │
         ▼
Event dispatched:
  UserActionEvent::dispatch($user, $action, $entity)
         │
         ▼
Listener: AuditLogger
  - Extract changes (before/after)
  - Get IP address
  - Get user agent
  - Get workspace context
         │
         ▼
Create audit_log record:
{
  user_id: $user->id,
  workspace_id: $workspace->id,
  entity_type: "lead",
  entity_id: 123,
  action: "create",
  changes: null,
  ip_address: "192.168.1.1",
  user_agent: "Chrome/...",
  created_at: now()
}
         │
         ▼
Persist to audit_logs table
         │
         ▼
Data retained for 2+ years (compliance)
Admin can query: /admin/audit-logs
```

---

## 9. Notification Flow

### 9.1 Real-Time Notification Trigger

```
Event occurs:
  - New lead assigned to user
  - Post published
  - Integration error
  - Workspace member invited
         │
         ▼
Event dispatcher fires
         │
         ▼
NotificationListener processes event
         │
         ▼
Create notification record:
{
  user_id: $recipient,
  workspace_id: $workspace,
  type: "lead_assigned",
  title: "New Lead: John Doe",
  message: "Lead assigned to you",
  related_entity: "lead:123",
  is_read: false
}
         │
         ▼
Queue email job (async)
  - Send email to user
  - Include action link
         │
         ▼
Frontend (real-time):
  - WebSocket push (Phase 2) or
  - Poll /users/notifications
  - Update notification badge count
  - Show toast/banner
```

---

## 10. Error Handling Flow

```
Request fails somewhere in stack
         │
         ▼
Exception caught:
  - Handler::render() catches it
  - Check exception type
         │
         ▼
  ├─ ValidationException
  │   └─ Return 422 + field errors
  ├─ AuthorizationException
  │   └─ Return 403 + message
  ├─ ModelNotFoundException
  │   └─ Return 404 + message
  ├─ ThrottleRequestsException
  │   └─ Return 429 (rate limited)
  ├─ General Exception
  │   └─ Log to Sentry
  │       Return 500 + generic message
  └─ HttpException
      └─ Return appropriate status + message
         │
         ▼
Frontend catches error:
  - 2xx: Success (maybe toast)
  - 3xx: Redirect
  - 4xx: Validation/Auth error (show in form/toast)
  - 5xx: Server error (show generic message)
         │
         ▼
User sees helpful message
Developers see error in logs/Sentry
```

---

## 11. Performance Data Load Strategy

### 11.1 Chart Data Fetching

```
Dashboard loads → Chart.js canvas appears empty
         │
         ▼
GET /api/v1/workspaces/{wid}/metrics?
  period=30&platform=all
         │
         ▼
Backend:
  - Query campaign_metrics table
  - Group by date
  - Sum by platform (optional)
  - Return last 30 days
  - Response size: ~5KB
         │
         ▼
Frontend:
  - Parse JSON
  - Format for Chart.js
  - ctx = canvas.getContext('2d')
  - new Chart(ctx, config) → render
         │
         ▼
Chart appears with animation (300ms)
```

### 11.2 List Pagination

```
GET /workspaces/{wid}/leads?page=1&per_page=50
         │
         ▼
Backend:
  - Query leads with pagination
  - Return records + pagination metadata
  {
    data: [...],
    pagination: {
      total: 1250,
      per_page: 50,
      current_page: 1,
      last_page: 25
    }
  }
         │
         ▼
Frontend:
  - Display 50 leads
  - Show pagination controls
  - On click "Next" → fetch page=2
  - Replace list (smooth transition)
```

---

## Sign-Off

**Process Designer:** [Name]  
**Date:** August 2026  
**Status:** Approved

