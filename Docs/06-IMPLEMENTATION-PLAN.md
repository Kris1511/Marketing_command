# 06-IMPLEMENTATION-PLAN.md - Implementation & Migration Plan

## Digital Marketing Dashboard - Laravel Migration

**Version:** 2.0 (Improved from Original Plan)  
**Last Updated:** August 2026  
**Target Timeline:** 90 days to MVP

---

## 1. Executive Summary - Key Improvements

### What Changed from Original Plan

| Aspect | Original Plan | Improved Plan | Reason |
|--------|---------------|---------------|--------|
| **Database** | MySQL only | MySQL 8+ with Redis cache | Query performance, session management |
| **Frontend** | Direct assets copy | Vite + Blade integration | Better asset pipeline, dev experience |
| **Auth** | Basic sessions | Laravel Breeze + Sanctum | Production-ready, token support |
| **Testing** | Not mentioned | 80%+ test coverage required | Reliability, refactoring safety |
| **Migration Path** | Abrupt cutover | Gradual feature migration | Zero downtime, risk mitigation |
| **Documentation** | Schema only | Full PRD, TRD, flows, design | Knowledge base for team |

---

## 2. Phase Overview

### Timeline: 12 Weeks (3 Sprints)

```
Week 1-4: Phase 1 - Foundation (Sprint 1)
Week 5-8: Phase 2 - Core Features (Sprint 2)
Week 9-12: Phase 3 - Polish & Deploy (Sprint 3)
```

---

## 3. Phase 1: Foundation & Setup (Weeks 1-4)

### 3.1 Week 1: Environment & Project Setup

#### Deliverables
- [ ] Laravel 11 project initialized
- [ ] MySQL database created and configured
- [ ] Redis cache configured
- [ ] Development environment (Docker Compose)
- [ ] Git repository with main/develop branches
- [ ] CI/CD pipeline skeleton

#### Tasks

**1.1.1 Initialize Laravel Project**
```bash
composer create-project laravel/laravel digital-marketing-dashboard
cd digital-marketing-dashboard
php artisan key:generate
```

**1.1.2 Install Core Dependencies**
```bash
composer require laravel/breeze laravel/sanctum laravel/passport
composer require guzzlehttp/guzzle
composer require spatie/laravel-backup spatie/laravel-audit-log
php artisan breeze:install
```

**1.1.3 Configure .env**
```env
APP_NAME="Marketing Command"
APP_ENV=local
APP_DEBUG=true

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=digital_marketing_db
DB_USERNAME=root
DB_PASSWORD=

CACHE_DRIVER=redis
SESSION_DRIVER=cookie (or redis in production)
QUEUE_CONNECTION=redis

MAIL_MAILER=smtp
MAIL_HOST=mailhog (or real SMTP in production)
```

**1.1.4 Docker Compose Setup**
```yaml
# docker-compose.yml
version: '3.8'
services:
  app:
    build:
      context: ./
      dockerfile: Dockerfile
    ports:
      - "8000:8000"
    environment:
      - DB_HOST=mysql
      - REDIS_HOST=redis
    depends_on:
      - mysql
      - redis

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: digital_marketing_db
      MYSQL_ROOT_PASSWORD: root
    ports:
      - "3306:3306"
    volumes:
      - mysql_data:/var/lib/mysql

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

volumes:
  mysql_data:
```

**1.1.5 GitHub Actions CI/CD Skeleton**
```yaml
# .github/workflows/test.yml
name: Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3
    steps:
      - uses: actions/checkout@v3
      - uses: php-actions/setup-php@v2
        with:
          php-version: 8.2
      - run: composer install
      - run: php artisan migrate:fresh
      - run: php artisan test
```

**Status Check:**
- [ ] `php artisan serve` works (localhost:8000)
- [ ] `php artisan tinker` connects to database
- [ ] `docker-compose up -d` starts full stack

---

### 3.2 Week 2: Database Schema & Seeders

#### Deliverables
- [ ] All 12 tables migrated to Laravel
- [ ] Model classes with relationships
- [ ] Factories for testing data
- [ ] Database seeders with realistic sample data
- [ ] Migration rollback verification

#### Tasks

**2.2.1 Create Migrations**
```bash
# Run these (use exact numbers from 05-DB-SCHEMA.md)
php artisan make:migration create_users_table
php artisan make:migration create_workspaces_table
php artisan make:migration create_user_workspace_roles_table
# ... continue for all 12 tables
```

**2.2.2 Create Models with Relationships**
```bash
php artisan make:model User
php artisan make:model Workspace
php artisan make:model Campaign
# ... etc
```

**Example Model (User.php):**
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use SoftDeletes;
    
    protected $fillable = ['name', 'email', 'password', 'role', 'is_active'];
    protected $hidden = ['password'];
    
    public function workspaces()
    {
        return $this->belongsToMany(
            Workspace::class,
            'user_workspace_roles',
            'user_id',
            'workspace_id'
        )->withPivot('role');
    }
    
    public function campaigns()
    {
        return $this->hasMany(Campaign::class, 'created_by_id');
    }
    
    public function leadActivities()
    {
        return $this->hasMany(LeadActivity::class);
    }
}
```

**2.2.3 Create Factories for Testing**
```bash
php artisan make:factory UserFactory
php artisan make:factory WorkspaceFactory
# ... continue for all models
```

**2.2.4 Create Database Seeders**
```bash
php artisan make:seeder DatabaseSeeder
php artisan make:seeder UserSeeder
php artisan make:seeder WorkspaceSeeder
# ... continue
```

**2.2.5 Verify Migrations**
```bash
php artisan migrate:fresh --seed
# Check: Tables exist, relationships work
php artisan tinker
>>> User::first()->workspaces;
>>> Workspace::first()->campaigns;
```

**Status Check:**
- [ ] `php artisan migrate` completes without errors
- [ ] All tables present in MySQL
- [ ] Foreign keys created
- [ ] Seeders generate 100+ test records
- [ ] `php artisan migrate:rollback` works cleanly

---

### 3.3 Week 3: Authentication & Authorization

#### Deliverables
- [ ] User login/logout working
- [ ] Session management with 8-hour timeout
- [ ] Laravel Breeze views customized
- [ ] Role-based access control (RBAC) middleware
- [ ] Workspace context switching
- [ ] Basic authentication tests (50+)

#### Tasks

**3.3.1 Configure Laravel Breeze**
```bash
php artisan breeze:install blade

# This generates:
# - resources/views/auth/login.blade.php
# - resources/views/auth/register.blade.php
# - app/Http/Controllers/Auth/
# - routes/auth.php
```

**3.3.2 Customize Breeze Templates (Brand them)**

Replace default views with dashboard styling (use colors/typography from 04-UI-UX-DESIGN.md)

**3.3.3 Create RBAC Middleware**
```php
// app/Http/Middleware/CheckRole.php
<?php
namespace App\Http\Middleware;

use Closure;

class CheckRole
{
    public function handle($request, Closure $next, ...$roles)
    {
        $userRole = auth()->user()->role;
        
        if (!in_array($userRole, $roles)) {
            abort(403, 'Unauthorized');
        }
        
        return $next($request);
    }
}
```

**3.3.4 Create Workspace Scoping Middleware**
```php
// app/Http/Middleware/ScopeByWorkspace.php
public function handle($request, Closure $next)
{
    $workspaceId = $request->header('X-Client-Workspace-ID') 
                  ?? session('active_workspace_id');
    
    // Verify user has access
    if (!auth()->user()->workspaces()->contains($workspaceId)) {
        abort(403);
    }
    
    // Automatically scope all queries
    \Illuminate\Database\Eloquent\Builder::macro('inWorkspace', function () use ($workspaceId) {
        return $this->where('workspace_id', $workspaceId);
    });
    
    return $next($request);
}
```

**3.3.5 Create Authorization Policies**
```bash
php artisan make:policy CampaignPolicy

# In CampaignPolicy.php:
public function view(User $user, Campaign $campaign)
{
    return $user->workspaces()->contains($campaign->workspace_id);
}
```

**3.3.6 Write Tests**
```bash
php artisan make:test AuthenticationTest --unit
php artisan make:test AuthorizationTest --feature
```

**Example Test:**
```php
public function test_user_can_login()
{
    $user = User::factory()->create();
    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password'
    ]);
    $this->assertAuthenticated();
}

public function test_user_cannot_access_other_workspace()
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    
    $this->actingAs($user);
    $response = $this->get("/api/v1/workspaces/{$workspace->id}");
    
    $response->assertStatus(403);
}
```

**Status Check:**
- [ ] Login/logout flow works
- [ ] Session persists across requests
- [ ] Logout clears session
- [ ] RBAC middleware blocks unauthorized access
- [ ] Tests pass (at least 10 authentication tests)

---

### 3.4 Week 4: API Structure & Core Endpoints

#### Deliverables
- [ ] RESTful API routes (v1)
- [ ] Resource classes for JSON responses
- [ ] FormRequest validation classes
- [ ] Base controller with common logic
- [ ] Error handling middleware
- [ ] 30+ API endpoints stubbed
- [ ] API tests (at least 20)

#### Tasks

**4.4.1 Configure API Routes**
```php
// routes/api.php
Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        // Workspaces
        Route::get('/workspaces', [WorkspaceController::class, 'index']);
        Route::post('/workspaces', [WorkspaceController::class, 'store']);
        
        // Campaigns
        Route::get('/workspaces/{workspace}/campaigns', 
                   [CampaignController::class, 'index']);
        Route::post('/workspaces/{workspace}/campaigns',
                    [CampaignController::class, 'store']);
        
        // Leads
        Route::get('/workspaces/{workspace}/leads',
                   [LeadController::class, 'index']);
        Route::post('/workspaces/{workspace}/leads',
                    [LeadController::class, 'store']);
        
        // ... continue for all resources
    });
    
    // Public routes
    Route::post('/auth/login', [AuthController::class, 'login']);
});
```

**4.4.2 Create Resource Classes**
```bash
php artisan make:resource CampaignResource
php artisan make:resource CampaignCollection
```

```php
// app/Http/Resources/CampaignResource.php
class CampaignResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'platform' => $this->platform,
            'status' => $this->status,
            'budget' => $this->budget,
            'spent' => $this->spent,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'metrics' => CampaignMetricResource::collection($this->metrics),
        ];
    }
}
```

**4.4.3 Create FormRequest Validation**
```bash
php artisan make:request StoreCampaignRequest
```

```php
class StoreCampaignRequest extends FormRequest
{
    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'platform' => 'required|in:meta_facebook,instagram,youtube,...',
            'budget' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ];
    }
}
```

**4.4.4 Create Base Controller**
```php
// app/Http/Controllers/Controller.php
abstract class Controller
{
    protected function success($data, $message = null, $status = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
    
    protected function error($message, $status = 400, $errors = null)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
```

**4.4.5 Error Handling**
```php
// app/Exceptions/Handler.php
public function render($request, Throwable $exception)
{
    if ($request->wantsJson()) {
        if ($exception instanceof ValidationException) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $exception->errors(),
            ], 422);
        }
        
        if ($exception instanceof AuthorizationException) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }
    }
    
    return parent::render($request, $exception);
}
```

**4.4.6 Write API Tests**
```php
public function test_get_campaigns()
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->workspaces()->attach($workspace);
    Campaign::factory(3)->create(['workspace_id' => $workspace->id]);
    
    $response = $this->actingAs($user)->get(
        "/api/v1/workspaces/{$workspace->id}/campaigns"
    );
    
    $response->assertStatus(200);
    $response->assertJsonCount(3, 'data');
}
```

**Status Check:**
- [ ] `php artisan route:list` shows 30+ API routes
- [ ] API test suite passes (20+ tests)
- [ ] Request validation works
- [ ] Error responses are JSON formatted
- [ ] Resource transformations apply

---

## 4. Phase 2: Core Features (Weeks 5-8)

### 4.1 Week 5: Dashboard & Metrics

#### Deliverables
- [ ] Dashboard overview page (Blade template)
- [ ] Metrics aggregation controller
- [ ] Chart.js integration with dynamic data
- [ ] Performance trend calculation
- [ ] Real-time metrics update (via AJAX)

#### Tasks

**5.1.1 Create Dashboard Controller**
```bash
php artisan make:controller DashboardController --resource
```

```php
// app/Http/Controllers/DashboardController.php
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $workspace = Workspace::find($request->header('X-Client-Workspace-ID'));
        
        $metrics = [
            'total_reach' => $this->getTotalReach($workspace),
            'engagement_rate' => $this->getEngagementRate($workspace),
            'new_leads' => $this->getNewLeads($workspace),
            'active_campaigns' => $this->getActiveCampaigns($workspace),
            'monthly_revenue' => $this->getMonthlyRevenue($workspace),
        ];
        
        return view('dashboard.overview', compact('metrics', 'workspace'));
    }
    
    private function getTotalReach(Workspace $workspace)
    {
        return CampaignMetric::whereHas('campaign', function ($q) {
            $q->where('workspace_id', $workspace->id);
        })->sum('impressions');
    }
    
    // ... continue for other metrics
}
```

**5.1.2 Create Blade Template**
```blade
<!-- resources/views/dashboard/overview.blade.php -->
@extends('layouts.app')

@section('content')
<div class="metric-grid">
    <x-metric-card 
        label="Total Reach"
        :value="number_format($metrics['total_reach'])" />
    <x-metric-card 
        label="Engagement Rate" 
        :value="$metrics['engagement_rate'] . '%'" />
    <x-metric-card 
        label="New Leads"
        :value="$metrics['new_leads']" />
</div>

<div class="grid-2 mt-20">
    <section class="panel">
        <div class="panel-header">
            <div class="panel-title">
                <h3>Performance trend</h3>
            </div>
        </div>
        <div class="chart-wrap">
            <canvas id="performanceChart"></canvas>
        </div>
    </section>
</div>

<script>
    // Fetch metrics data via AJAX
    fetch('/api/v1/workspaces/{{ $workspace->id }}/metrics')
        .then(r => r.json())
        .then(data => {
            // Render Chart.js chart with data
            const ctx = document.getElementById('performanceChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: data,
            });
        });
</script>
@endsection
```

**5.1.3 Create Metrics API Endpoint**
```php
Route::get('/workspaces/{workspace}/metrics', [DashboardController::class, 'metrics']);

public function metrics(Workspace $workspace)
{
    $data = CampaignMetric::whereHas('campaign')
        ->where('campaign.workspace_id', $workspace->id)
        ->selectRaw('metric_date, SUM(impressions) as reach, SUM(clicks) as engagement')
        ->groupBy('metric_date')
        ->orderBy('metric_date', 'desc')
        ->limit(30)
        ->get();
    
    return response()->json([
        'labels' => $data->pluck('metric_date'),
        'reach' => $data->pluck('reach'),
        'engagement' => $data->pluck('engagement'),
    ]);
}
```

**Status Check:**
- [ ] Dashboard loads in < 2 seconds
- [ ] Metrics calculate correctly
- [ ] Chart renders with data
- [ ] API endpoint returns proper format

---

### 4.2 Week 6: Leads Management (CRM)

#### Deliverables
- [ ] Lead CRUD operations
- [ ] Lead filtering and search
- [ ] Lead status pipeline
- [ ] Activity tracking
- [ ] Lead assignment to team members
- [ ] Full CRUD tests

#### Tasks

**6.2.1 Create Lead Controller & Model**
```bash
php artisan make:model Lead -c -f
php artisan make:request StoreLeadRequest
```

**6.2.2 Implement Lead Operations**
```php
// app/Http/Controllers/LeadController.php
class LeadController extends Controller
{
    public function index(Workspace $workspace, Request $request)
    {
        $leads = Lead::where('workspace_id', $workspace->id)
            ->when($request->status, function ($q) {
                return $q->where('status', $request->status);
            })
            ->when($request->source, function ($q) {
                return $q->where('source', $request->source);
            })
            ->when($request->search, function ($q) use ($request) {
                return $q->where('name', 'like', "%{$request->search}%")
                          ->orWhere('email', 'like', "%{$request->search}%");
            })
            ->with('assignedTo', 'activities')
            ->paginate(50);
        
        return LeadResource::collection($leads);
    }
    
    public function store(StoreLeadRequest $request, Workspace $workspace)
    {
        $lead = $workspace->leads()->create($request->validated());
        
        event(new LeadCreated($lead));
        
        return new LeadResource($lead);
    }
    
    public function update(UpdateLeadRequest $request, Lead $lead)
    {
        $original = $lead->toArray();
        $lead->update($request->validated());
        
        // Log activity
        $lead->activities()->create([
            'user_id' => auth()->id(),
            'activity_type' => 'status_change',
            'activity_notes' => "Status changed to {$lead->status}",
            'activity_date' => now(),
        ]);
        
        // Audit log
        event(new AuditLogged(
            auth()->user(),
            'lead',
            $lead->id,
            'update',
            $original,
            $lead->toArray()
        ));
        
        return new LeadResource($lead->fresh());
    }
}
```

**6.2.3 Create Lead Blade Views**
```blade
<!-- resources/views/leads/index.blade.php -->
<div class="section-head">
    <h2>CRM & Leads</h2>
</div>

<div class="filters mb-4">
    <select name="status" onchange="filterLeads()">
        <option>All Status</option>
        <option value="new">New</option>
        <option value="contacted">Contacted</option>
    </select>
    
    <input type="search" placeholder="Search by name or email..." 
           onkeyup="searchLeads()" />
</div>

<div class="table-wrap">
    <table id="leadsTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Source</th>
                <th>Assigned To</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="leadsBody">
            <!-- Populated by AJAX -->
        </tbody>
    </table>
</div>

<script>
    function filterLeads() {
        const status = document.querySelector('[name="status"]').value;
        fetch(`/api/v1/workspaces/{{ $workspace->id }}/leads?status=${status}`)
            .then(r => r.json())
            .then(data => renderLeads(data.data));
    }
    
    function renderLeads(leads) {
        const tbody = document.getElementById('leadsBody');
        tbody.innerHTML = leads.map(lead => `
            <tr>
                <td>${lead.name}</td>
                <td>${lead.email}</td>
                <td><span class="pill">${lead.status}</span></td>
                <td>${lead.source}</td>
                <td>${lead.assigned_to?.name || 'Unassigned'}</td>
                <td>
                    <button onclick="editLead(${lead.id})">Edit</button>
                </td>
            </tr>
        `).join('');
    }
</script>
```

**Status Check:**
- [ ] Create lead via form (modal)
- [ ] Update lead status
- [ ] Filter leads by status/source
- [ ] Search leads by name/email
- [ ] Assign lead to team member
- [ ] View lead activity log

---

### 4.3 Week 7: Content Publishing

#### Deliverables
- [ ] Post creation form
- [ ] Multi-platform selection
- [ ] Schedule post functionality
- [ ] Publishing calendar view
- [ ] Queue job for scheduled publication
- [ ] Post history and status tracking

#### Tasks

**7.3.1 Create Post Model & Controller**
```bash
php artisan make:model Post -c -f
php artisan make:request StorePostRequest
php artisan make:job PublishScheduledPosts
```

**7.3.2 Implement Publishing Logic**
```php
// app/Http/Controllers/PostController.php
public function store(StorePostRequest $request, Workspace $workspace)
{
    $post = $workspace->posts()->create([
        'content' => $request->content,
        'platform_list' => $request->platforms,
        'media_urls' => $request->media_urls ?? [],
        'scheduled_at' => $request->scheduled_at,
        'status' => $request->scheduled_at ? 'scheduled' : 'draft',
        'created_by_id' => auth()->id(),
    ]);
    
    // Queue job for scheduled publishing
    if ($post->scheduled_at) {
        dispatch(new PublishScheduledPosts())->delay($post->scheduled_at);
    }
    
    return new PostResource($post);
}
```

**7.3.3 Create Background Job**
```php
// app/Jobs/PublishScheduledPosts.php
class PublishScheduledPosts implements ShouldQueue
{
    public function handle()
    {
        $posts = Post::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();
        
        foreach ($posts as $post) {
            try {
                foreach ($post->platform_list as $platform) {
                    $this->publishToPlatform($post, $platform);
                }
                
                $post->update([
                    'status' => 'published',
                    'published_at' => now(),
                ]);
            } catch (Exception $e) {
                $post->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }
    }
    
    private function publishToPlat form($post, $platform)
    {
        // Call platform API via ApiService
        $response = ApiService::publish($platform, $post->content);
        return $response;
    }
}
```

**7.3.4 Create Publishing Calendar View**
```blade
<!-- resources/views/publishing/calendar.blade.php -->
<div id="calendar" style="height: 600px;"></div>

<script>
    let calendar = new Calendar(document.getElementById('calendar'), {
        events: async (info, successCallback) => {
            const response = await fetch(
                `/api/v1/workspaces/{{ $workspace->id }}/posts`
            );
            const posts = await response.json();
            
            successCallback(posts.data.map(post => ({
                title: post.content.substring(0, 20) + '...',
                start: post.scheduled_at,
                end: post.scheduled_at,
                backgroundColor: '#2457e6',
            })));
        }
    });
</script>
```

**Status Check:**
- [ ] Create post with text, media, platforms
- [ ] Schedule post for future date/time
- [ ] View posts in calendar
- [ ] Scheduled job runs at correct time
- [ ] Published posts show status = 'published'

---

### 4.4 Week 8: Integrations & Reports

#### Deliverables
- [ ] OAuth connection flow (Facebook, Google, LinkedIn)
- [ ] Integration status display
- [ ] Sync metrics from connected platforms
- [ ] Report generation & export (PDF, CSV)
- [ ] Report templates
- [ ] Scheduled report delivery

#### Tasks

**8.4.1 Create Integration Controller**
```bash
php artisan make:model Integration -c
php artisan make:request ConnectIntegrationRequest
php artisan make:job SyncIntegrationMetrics
```

**8.4.2 Implement OAuth Flow**
```php
// app/Http/Controllers/IntegrationController.php
public function connect(ConnectIntegrationRequest $request, Workspace $workspace)
{
    $platform = $request->platform; // 'facebook', 'google', etc.
    
    // Generate OAuth URL
    $oauthUrl = OAuth::getAuthorizationUrl($platform, [
        'client_id' => config("platforms.{$platform}.client_id"),
        'redirect_uri' => route('integrations.callback'),
        'scope' => config("platforms.{$platform}.scopes"),
    ]);
    
    // Store state token in session for CSRF protection
    session()->put("oauth_state_{$platform}", bin2hex(random_bytes(16)));
    
    return response()->json([
        'auth_url' => $oauthUrl,
        'platform' => $platform,
    ]);
}

public function callback(Request $request)
{
    $platform = $request->platform;
    $code = $request->code;
    $state = $request->state;
    
    // Verify CSRF
    if ($state !== session()->get("oauth_state_{$platform}")) {
        abort(403, 'Invalid state token');
    }
    
    // Exchange code for token
    $token = OAuth::exchange($platform, $code);
    
    // Store encrypted token
    Integration::create([
        'workspace_id' => session('active_workspace_id'),
        'platform' => $platform,
        'account_id' => $token['account_id'],
        'refresh_token' => encrypt($token['refresh_token']),
        'is_connected' => true,
    ]);
    
    // Queue sync job
    dispatch(new SyncIntegrationMetrics($workspace, $platform));
    
    return redirect('/integrations?success=1');
}
```

**8.4.3 Create Report Generator**
```bash
php artisan make:command GenerateReport
php artisan make:job GenerateReportJob
```

```php
// app/Jobs/GenerateReportJob.php
class GenerateReportJob implements ShouldQueue
{
    public function handle()
    {
        $report = $this->report;
        
        // Aggregate data based on filters
        $data = $this->aggregateMetrics($report->filters);
        
        // Generate PDF or CSV
        if ($report->export_format === 'pdf') {
            $pdf = PDF::loadView('reports.template', ['data' => $data]);
            $path = storage_path("reports/{$report->id}.pdf");
            $pdf->save($path);
        } elseif ($report->export_format === 'csv') {
            $csv = $this->generateCSV($data);
            $path = storage_path("reports/{$report->id}.csv");
            file_put_contents($path, $csv);
        }
        
        // Update report
        $report->update([
            'file_path' => $path,
            'generated_at' => now(),
        ]);
        
        // Notify user
        Notification::send($this->report->creator, new ReportReady($report));
    }
}
```

**Status Check:**
- [ ] OAuth connection to at least one platform
- [ ] Integration status shows "connected"
- [ ] Manual sync works
- [ ] Report generation completes
- [ ] PDF/CSV export downloads
- [ ] Sync job runs automatically

---

## 5. Phase 3: Polish & Deployment (Weeks 9-12)

### 5.1 Week 9: Testing & QA

#### Deliverables
- [ ] 80%+ test coverage
- [ ] End-to-end tests for critical flows
- [ ] Performance testing (load test)
- [ ] Security audit
- [ ] Accessibility (WCAG 2.1 AA) audit
- [ ] All bugs fixed

#### Tasks

**9.1.1 Expand Test Suite**
```bash
php artisan test --coverage

# Target: 80%+ coverage
# Breakdown:
#   - Models: 90% coverage
#   - Controllers: 85% coverage
#   - Services: 90% coverage
#   - Policies: 80% coverage
```

**9.1.2 Write End-to-End Tests**
```php
// tests/Feature/LeadWorkflowTest.php
public function test_complete_lead_workflow()
{
    // 1. Login as manager
    $user = User::factory()->create(['role' => 'manager']);
    $this->actingAs($user);
    
    // 2. Create lead manually
    $response = $this->post('/api/v1/workspaces/1/leads', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'source' => 'manual_entry',
    ]);
    $leadId = $response->json('data.id');
    
    // 3. Assign to user
    $this->put("/api/v1/workspaces/1/leads/{$leadId}", [
        'assigned_to_id' => $user->id,
    ])->assertOk();
    
    // 4. Add activity (call)
    $this->post("/api/v1/workspaces/1/leads/{$leadId}/activities", [
        'activity_type' => 'call',
        'activity_notes' => 'Discussed pricing',
    ])->assertCreated();
    
    // 5. Update status to "Qualified"
    $this->put("/api/v1/workspaces/1/leads/{$leadId}", [
        'status' => 'qualified',
    ])->assertOk();
    
    // Verify
    $lead = Lead::find($leadId);
    $this->assertEquals('qualified', $lead->status);
    $this->assertCount(1, $lead->activities);
}
```

**9.1.3 Performance Testing**
```bash
# Use Apache Bench or k6
ab -n 1000 -c 100 http://localhost:8000/api/v1/workspaces/1/metrics

# Expected: < 3 seconds p95, < 1% error rate
```

**9.1.4 Security Audit**
- [ ] No hardcoded secrets
- [ ] All inputs validated
- [ ] SQL injection tests pass
- [ ] XSS protection enabled (Blade escaping)
- [ ] CSRF tokens on all forms
- [ ] Password hashing verified (bcrypt)
- [ ] OAuth tokens encrypted

**9.1.5 Accessibility Audit**
```bash
# Run axe DevTools in browser on all pages
# Checklist:
# - All form inputs have labels
# - All images have alt text (if applicable)
# - Color contrast >= 4.5:1
# - Keyboard navigation works
# - ARIA labels on buttons/icons
```

---

### 5.2 Week 10: Documentation & Knowledge Transfer

#### Deliverables
- [ ] API documentation (OpenAPI/Swagger)
- [ ] Deployment runbook
- [ ] Architecture diagram
- [ ] Database ER diagram
- [ ] Developer setup guide
- [ ] Admin user guide
- [ ] Video walkthrough (optional)

#### Tasks

**10.2.1 Generate API Documentation**
```bash
composer require darkaonline/l5-swagger

php artisan l5-swagger:generate

# Visit: http://localhost:8000/api/documentation
```

**10.2.2 Create Deployment Runbook**
```markdown
# Deployment Checklist

## Pre-deployment
- [ ] All tests pass (`php artisan test`)
- [ ] Database migrations reviewed
- [ ] Environment variables configured
- [ ] Secrets stored in secure vault

## Deploy to Staging
```bash
git checkout main
git pull origin main
composer install --no-dev
php artisan migrate:fresh (staging only)
npm run build
# Health checks
curl https://staging.example.com/health
```

## Deploy to Production
- [ ] Backup database
- [ ] Deploy code
- [ ] Run migrations: `php artisan migrate`
- [ ] Clear caches: `php artisan cache:clear`
- [ ] Verify deployment
```

**10.2.3 Create Admin User Guide (PDF)**
- How to add clients
- How to manage team members
- How to connect integrations
- How to view reports
- Troubleshooting common issues

---

### 5.3 Week 11: Pre-Launch Setup & Configuration

#### Deliverables
- [ ] Production environment configured
- [ ] SSL/TLS certificates (Let's Encrypt)
- [ ] Email service configured
- [ ] Backup system automated
- [ ] Monitoring alerts set up
- [ ] CDN configured (for assets)
- [ ] Domain DNS configured

#### Tasks

**11.3.1 Production Environment**
```bash
# .env production
APP_ENV=production
APP_DEBUG=false
CACHE_DRIVER=redis
SESSION_DRIVER=cookie
QUEUE_CONNECTION=redis
MAIL_MAILER=smtp
MAIL_HOST=email-provider.com
```

**11.3.2 SSL Certificates**
```bash
# Let's Encrypt (via certbot)
sudo certbot certonly --webroot -w /var/www/html \
  -d example.com -d www.example.com

# Renew automatically
sudo certbot renew --quiet --pre-hook "systemctl stop nginx" \
  --post-hook "systemctl start nginx"
```

**11.3.3 Backup Automation**
```bash
# crontab -e
# Daily backup at 2 AM
0 2 * * * cd /var/www/laravel && php artisan backup:run >> /var/log/backup.log 2>&1

# Upload to S3
# In config/backup.php
'disks' => ['s3'],
```

**11.3.4 Monitoring Setup**
```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'sentry'],
    ],
    'sentry' => [
        'driver' => 'sentry',
        'level' => env('LOG_LEVEL', 'debug'),
    ],
],

// In .env
SENTRY_LARAVEL_DSN=https://xxx@sentry.io/12345
```

**11.3.5 Performance Optimization**
```php
// Production deployment checklist
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
php artisan migrate --force
```

---

### 5.4 Week 12: Soft Launch & Monitoring

#### Deliverables
- [ ] Limited beta with 10-50 users
- [ ] Monitoring dashboard live
- [ ] Support ticket system ready
- [ ] Documentation deployed
- [ ] Performance baselines established
- [ ] Incident response plan tested

#### Tasks

**12.4.1 Beta Launch**
- Invite select users (internal team + 5-10 early customers)
- Daily standup on feedback
- Fix critical bugs same-day
- Monitor performance metrics

**12.4.2 Monitoring Dashboard (New Relic / DataDog)**
- [ ] Response time (p50, p95, p99)
- [ ] Error rate
- [ ] Database query time
- [ ] Redis hit rate
- [ ] Disk usage
- [ ] Memory usage

**12.4.3 Alert Thresholds**
- CPU > 80% for 5 mins → Alert
- Disk > 85% → Alert
- Error rate > 1% → Alert
- Response time p95 > 3s → Alert
- Database connections > 400/500 → Alert

**12.4.4 Run Incident Response Drill**
- Simulate database failure
- Practice failover to replica
- Test backup restoration
- Document lessons learned

---

## 6. Rollout Strategy (Post-MVP)

### Phase 1: Beta (Weeks 9-12)
- 10-50 users
- Daily monitoring
- Same-day bug fixes

### Phase 2: Soft Launch (Week 13)
- 100-200 users
- Public announcement
- Documentation live

### Phase 3: Full Launch (Week 14+)
- All users migrated
- Legacy system decommissioned
- Celebrate! 🚀

---

## 7. Risk Mitigation

### High-Risk Areas

| Risk | Mitigation |
|------|-----------|
| **Data Loss** | Daily automated backups to S3, test restore monthly |
| **Performance Issues** | Load testing, caching strategy, database optimization |
| **Security Breach** | Encryption, RBAC, audit logging, penetration testing |
| **Feature Delays** | Agile sprints, daily standups, clear prioritization |
| **Integration Failures** | Robust error handling, monitoring, manual fallback |

---

## 8. Success Criteria

### Must-Have (MVP)
- [ ] All 12 database tables working
- [ ] Authentication & authorization working
- [ ] Dashboard with real metrics
- [ ] Lead CRUD operations
- [ ] Content publishing to 1+ platform
- [ ] Report generation (PDF/CSV)
- [ ] 80%+ test coverage
- [ ] < 2 second load time

### Nice-to-Have (Post-MVP)
- [ ] Real-time notifications (WebSocket)
- [ ] AI-powered content suggestions
- [ ] Mobile app
- [ ] Marketplace integrations
- [ ] Sentiment analysis

---

## 9. Improvement Suggestions to Original Plan

### What We Added

1. **Phased Implementation** (vs. all-at-once)
   - Reduces risk
   - Enables early feedback
   - Allows for course correction

2. **Testing First Approach**
   - 80%+ coverage requirement
   - Catch bugs early
   - Safe refactoring

3. **Documentation Bundle**
   - PRD, TRD, Flows, Design, Schema, Implementation
   - Onboard new team members quickly
   - Reduce knowledge loss

4. **Redis Caching**
   - Improve performance
   - Better session management
   - Support horizontal scaling

5. **Comprehensive API**
   - REST endpoints for all resources
   - Enables future mobile apps
   - Third-party integrations possible

6. **Production-Ready from Day 1**
   - Security, monitoring, backups
   - Deploy with confidence
   - Scale without rework

7. **Soft Launch Strategy**
   - Beta with early users
   - Fix issues before full launch
   - Build confidence

---

## 10. Required Resources

### Team Composition
- **1 Backend Lead** (Laravel expert)
- **1 Backend Developer** (junior/mid-level)
- **1 Frontend Developer** (Blade + vanilla JS)
- **1 QA Engineer** (testing, automation)
- **1 DevOps** (part-time, infrastructure)

### Infrastructure Costs (Monthly)
- **Cloud Server** (AWS EC2 t3.large): $100
- **Database** (AWS RDS MySQL): $150
- **Redis Cache** (AWS ElastiCache): $50
- **Monitoring** (New Relic / DataDog): $100
- **Storage/Backups** (S3): $30
- **CDN** (CloudFront): $30
- **Domain/SSL**: $15
- **Total**: ~$475/month (scales with users)

### Development Tools (One-time)
- GitHub Pro: $21/month
- JetBrains PhpStorm: $99/year
- Postman Pro: $100/year
- Total: ~$200 setup

---

## 11. Sign-Off

**Project Manager:** [Name]  
**Technical Lead:** [Name]  
**Product Owner:** [Name]  
**Date:** August 2026  
**Status:** Ready to Begin Implementation

---

## Appendix A: Command Reference

```bash
# Start development
cd laravel-project
php artisan serve

# Run tests
php artisan test

# Generate migrations
php artisan make:migration create_xxx_table

# Create models
php artisan make:model Model

# Seed database
php artisan migrate:fresh --seed

# Clear caches
php artisan cache:clear
php artisan config:cache
php artisan route:cache

# Tinker (REPL)
php artisan tinker

# Deploy
git checkout main && git pull
composer install --no-dev
npm run build
php artisan migrate
php artisan cache:clear
```

---

## Appendix B: File Structure After Migration

```
laravel-project/
├── app/
│   ├── Http/Controllers/        # 20+ controllers
│   ├── Models/                  # 12 models with relationships
│   ├── Services/                # Business logic
│   ├── Jobs/                    # Background jobs
│   ├── Policies/                # Authorization
│   └── Exceptions/              # Custom exceptions
├── database/
│   ├── migrations/              # 12 migration files
│   ├── seeders/                 # Sample data
│   └── factories/               # Test data factories
├── resources/
│   ├── views/                   # Blade templates (organized by feature)
│   ├── css/                     # Compiled styles (from assets/styles.css)
│   └── js/                      # JavaScript (vanilla + Chart.js)
├── routes/
│   ├── api.php                  # 30+ API routes
│   ├── web.php                  # Web routes (dashboard)
│   └── auth.php                 # Authentication routes
├── tests/
│   ├── Feature/                 # Integration tests (50+)
│   ├── Unit/                    # Unit tests (30+)
│   └── TestCase.php
├── config/                      # Configuration files
├── storage/                     # File uploads, logs
└── docker-compose.yml           # Development environment
```

---

**END OF IMPLEMENTATION PLAN**

