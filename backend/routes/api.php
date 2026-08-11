<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use App\Models\Workspace;
use App\Models\Campaign;
use App\Models\CampaignMetric;
use App\Models\Lead;
use App\Models\Integration;
use App\Models\Post;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\FacebookPostMedia;
use App\Models\FacebookPostHistory;
use App\Services\FacebookGraphService;
use App\Http\Controllers\YouTubeController;

/*
|--------------------------------------------------------------------------
| Global Helper Functions for Workspace Resolution & Notifications
|--------------------------------------------------------------------------
*/
if (!function_exists('getOrCreateWorkspaceId')) {
    function getOrCreateWorkspaceId($workspaceId = null): int {
        if ($workspaceId && is_numeric($workspaceId)) {
            $ws = \App\Models\Workspace::find((int)$workspaceId);
            if ($ws) return $ws->id;
        }
        $first = \App\Models\Workspace::first();
        if ($first) return $first->id;
        $created = \App\Models\Workspace::create([
            'name'   => 'Default Workspace',
            'slug'   => 'default-workspace',
            'status' => 'active',
        ]);
        return $created->id;
    }
}

if (!function_exists('createNotification')) {
    function createNotification($workspaceId, string $type, string $title, string $message, ?string $channel = null): void {
        try {
            $wsId = getOrCreateWorkspaceId($workspaceId);
            \Illuminate\Support\Facades\DB::table('notifications')->insert([
                'workspace_id' => $wsId,
                'type'         => $type,
                'title'        => $title,
                'message'      => $message,
                'is_read'      => false,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[createNotification error] " . $e->getMessage());
        }
    }
}

/*
|--------------------------------------------------------------------------
| API Routes - Digital Marketing Dashboard (Connected to MySQL)
|--------------------------------------------------------------------------
*/

// YouTube Data API v3 Routes (Direct /api/youtube/...)

// YouTube Data API v3 Routes (Direct /api/youtube/...)
Route::get('/youtube/connect', [YouTubeController::class, 'connect']);
Route::get('/youtube/callback', [YouTubeController::class, 'callback']);
Route::get('/youtube/status', [YouTubeController::class, 'status']);
Route::get('/youtube/channel', [YouTubeController::class, 'channel']);
Route::post('/youtube/disconnect', [YouTubeController::class, 'disconnect']);
Route::post('/youtube/videos', [YouTubeController::class, 'uploadVideo']);

Route::prefix('v1')->group(function () {

    // YouTube API Routes (Aliased under /api/v1/youtube/...)
    Route::prefix('youtube')->group(function () {
        Route::get('/connect', [YouTubeController::class, 'connect']);
        Route::get('/callback', [YouTubeController::class, 'callback']);
        Route::get('/status', [YouTubeController::class, 'status']);
        Route::get('/channel', [YouTubeController::class, 'channel']);
        Route::post('/disconnect', [YouTubeController::class, 'disconnect']);
        Route::post('/videos', [YouTubeController::class, 'uploadVideo']);
    });

    // Twitter API Routes (Aliased under /api/v1/twitter/...)
    Route::prefix('twitter')->group(function () {
        Route::get('/connect', [TwitterController::class, 'connect']);
        Route::get('/callback', [TwitterController::class, 'callback']);
        Route::get('/status', [TwitterController::class, 'status']);
        Route::post('/disconnect', [TwitterController::class, 'disconnect']);
    });

    // Handle OPTIONS Preflight CORS Requests
    Route::options('/{any}', function () {
        return response('', 200)
            ->header('Access-Control-Allow-Origin', 'http://localhost:3000')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin')
            ->header('Access-Control-Allow-Credentials', 'true');
    })->where('any', '.*');

    // Public Healthcheck Endpoint
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'Marketing Command API',
            'database' => 'Connected (MySQL WAMP)',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    // Public Auth Routes
    Route::post('/login', function (Request $request) {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        $user = User::where('email', $credentials['email'])->firstOrFail();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'admin',
            ]
        ]);
    });

    Route::post('/register', function (Request $request) {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users',
            'password' => 'required|string|min:6',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]
        ], 201);
    });

    // Authenticated API Routes
    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/user', function (Request $request) {
            $user = $request->user();
            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role ?? 'admin',
                ]
            ]);
        });

        Route::post('/logout', function (Request $request) {
            if ($request->user() && $request->user()->currentAccessToken()) {
                $request->user()->currentAccessToken()->delete();
            }
            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully'
            ]);
        });

        // Workspaces API
        Route::get('/workspaces', function () {
            $workspaces = Workspace::with('owner')->orderBy('created_at', 'desc')->get();
            return response()->json([
                'success' => true,
                'data' => $workspaces
            ]);
        });

        Route::post('/workspaces', function (Request $request) {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'industry' => 'nullable|string|max:255',
                'primary_contact' => 'nullable|string|max:255',
                'primary_contact_email' => 'nullable|string|max:255',
                'budget' => 'nullable|numeric',
            ]);

            $workspace = Workspace::create([
                'name' => $validated['name'],
                'industry' => $validated['industry'] ?? 'Retail',
                'primary_contact' => $validated['primary_contact'] ?? 'Contact Person',
                'primary_contact_email' => $validated['primary_contact_email'] ?? (strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $validated['name'])) . '@client.com'),
                'budget' => $validated['budget'] ?? 12500,
                'status' => 'active',
                'owner_id' => $request->user()->id ?? 1,
            ]);

            return response()->json([
                'success' => true,
                'data' => $workspace
            ], 201);
        });

        // Dashboard Overview Metrics API (Real Data & Workspace Isolated)
        Route::get('/dashboard/metrics', function (Request $request) {
            $workspaceId = $request->query('workspace_id', 1);
            $workspace = Workspace::find($workspaceId);

            $facebookPages = FacebookPage::where('workspace_id', $workspaceId)->get();
            $connectedPage = $facebookPages->first() ?? FacebookPage::latest()->first();
            $fbWorkspaceId = $connectedPage ? $connectedPage->workspace_id : $workspaceId;

            $fbPhotoMetrics = ['likes' => 0, 'comments' => 0, 'shares' => 0];
            if ($connectedPage && !empty($connectedPage->page_access_token)) {
                try {
                    $graphService = new FacebookGraphService();
                    $graphService->refreshPageInsights($connectedPage);
                    $graphService->syncWorkspacePosts($fbWorkspaceId);
                    $fbPhotoMetrics = $graphService->getPagePhotosMetrics($connectedPage->page_id, $connectedPage->page_access_token);
                } catch (\Exception $e) {}
            }

            $totalPosts = FacebookPost::where('workspace_id', $fbWorkspaceId)->count();
            $postsThisMonth = FacebookPost::where('workspace_id', $fbWorkspaceId)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();
            $publishedToday = FacebookPost::where('workspace_id', $fbWorkspaceId)
                ->where('status', 'published')
                ->whereDate('published_at', now()->today())
                ->count();
            $scheduledCount = FacebookPost::where('workspace_id', $fbWorkspaceId)
                ->where('status', 'scheduled')
                ->count();
            $draftsCount = FacebookPost::where('workspace_id', $fbWorkspaceId)
                ->where('status', 'draft')
                ->count();

            $allPubPosts     = FacebookPost::where('workspace_id', $fbWorkspaceId)->where('status', 'published');
            $totalLikes      = max((int)(clone $allPubPosts)->sum('likes_count'), (int)($fbPhotoMetrics['likes'] ?? 0));
            $totalComments   = max((int)(clone $allPubPosts)->sum('comments_count'), (int)($fbPhotoMetrics['comments'] ?? 0));
            $totalShares     = max((int)(clone $allPubPosts)->sum('shares_count'), (int)($fbPhotoMetrics['shares'] ?? 0));
            $totalReactions  = (int)(clone $allPubPosts)->sum('reactions_count');
            $totalEngagement = max((int)(clone $allPubPosts)->sum('engagement_count'), $totalLikes + $totalComments + $totalShares);
            $totalReach      = (int)(clone $allPubPosts)->sum('reach_count');

            $followersCount = $facebookPages->sum('followers_count');
            $fanCount = $facebookPages->sum('fan_count');

            $recentPosts = FacebookPost::where('workspace_id', $workspaceId)
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get()
                ->map(function ($p) {
                    $legacyPost = Post::where('workspace_id', $p->workspace_id)
                        ->where('content', $p->content)
                        ->latest()
                        ->first();
                    $p->platform_list = $legacyPost ? $legacyPost->platform_list : ($p->facebook_page_id ? ['Facebook'] : ['YouTube']);
                    return $p;
                });

            $ytConn = \App\Models\YouTubeConnection::latest()->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'workspace_id'          => $workspaceId,
                    'workspace_name'        => $workspace ? $workspace->name : 'Workspace ' . $workspaceId,
                    'total_posts'           => $totalPosts,
                    'posts_this_month'      => $postsThisMonth,
                    'published_today'       => $publishedToday,
                    'scheduled_count'       => $scheduledCount,
                    'drafts_count'          => $draftsCount,
                    'total_likes'           => $totalLikes,
                    'total_comments'        => $totalComments,
                    'total_shares'          => $totalShares,
                    'total_reactions'       => $totalReactions,
                    'total_engagement'      => $totalEngagement,
                    'total_reach'           => max($followersCount, $totalReach),
                    'connected_pages_count' => $facebookPages->count(),
                    'followers_count'       => $followersCount,
                    'fan_count'             => $fanCount,
                    'connected_page'        => $connectedPage ? [
                        'page_id'             => $connectedPage->page_id,
                        'page_name'           => $connectedPage->page_name,
                        'followers_count'     => $connectedPage->followers_count,
                        'fan_count'           => $connectedPage->fan_count,
                        'profile_picture_url' => $connectedPage->profile_picture_url,
                        'connected_since'     => $connectedPage->connected_since ? $connectedPage->connected_since->toIso8601String() : null,
                        'token_status'        => $connectedPage->token_status,
                    ] : null,
                    'youtube_connection'    => $ytConn ? [
                        'channel_id'          => $ytConn->channel_id,
                        'channel_name'        => $ytConn->channel_name,
                        'channel_description' => $ytConn->channel_description,
                        'channel_thumbnail'   => $ytConn->channel_thumbnail,
                        'subscriber_count'    => $ytConn->subscriber_count,
                        'video_count'         => $ytConn->video_count,
                        'view_count'          => $ytConn->view_count,
                    ] : null,
                    'recent_posts'          => $recentPosts,
                ]
            ]);
        });

        // CRM Leads API
        Route::get('/leads', function () {
            $leads = Lead::with('workspace', 'campaign')->orderBy('created_at', 'desc')->get();
            return response()->json([
                'success' => true,
                'data' => $leads
            ]);
        });

        Route::post('/leads', function (Request $request) {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:255',
                'source' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'campaign_name' => 'nullable|string',
                'assigned_to' => 'nullable|string',
            ]);

            $sourceMap = [
                'Facebook Lead Ad' => 'facebook_lead_ad',
                'Google Ads' => 'google_lead_form',
                'Website Enquiry' => 'website_form',
                'Instagram Direct' => 'instagram',
                'Manual Entry' => 'manual_entry',
            ];

            $sourceVal = $sourceMap[$request->input('source')] ?? 'manual_entry';

            $lead = Lead::create([
                'workspace_id' => getOrCreateWorkspaceId(1),
                'name' => $validated['name'],
                'email' => $validated['email'] ?? (strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $validated['name'])) . '@example.com'),
                'phone' => $validated['phone'] ?? '+91 98765 00000',
                'status' => 'new',
                'source' => $sourceVal,
                'notes' => $validated['notes'] ?? null,
                'assigned_to_id' => $request->user()->id ?? 1,
            ]);

            return response()->json([
                'success' => true,
                'data' => $lead
            ], 201);
        });

        Route::patch('/leads/{id}', function (Request $request, $id) {
            $lead = Lead::findOrFail($id);
            if ($request->has('status')) {
                $lead->status = $request->input('status');
            }
            $lead->save();

            return response()->json([
                'success' => true,
                'data' => $lead
            ]);
        });

        // Notifications API — Real DB-backed
        Route::get('/notifications', function (Request $request) {
            $workspaceId = $request->query('workspace_id', null);

            $query = \Illuminate\Support\Facades\DB::table('notifications')
                ->leftJoin('workspaces', 'notifications.workspace_id', '=', 'workspaces.id')
                ->select(
                    'notifications.id',
                    'notifications.type',
                    'notifications.title',
                    'notifications.message',
                    'notifications.is_read',
                    'notifications.related_entity',
                    'notifications.created_at',
                    'workspaces.name as workspace_name'
                )
                ->orderBy('notifications.created_at', 'desc')
                ->limit(50);

            if ($workspaceId) {
                $query->where('notifications.workspace_id', $workspaceId);
            }

            $rows = $query->get();

            $categoryMap = [
                'post_published'     => 'publishing',
                'lead_assigned'      => 'leads',
                'lead_converted'     => 'leads',
                'integration_error'  => 'system',
                'campaign_milestone' => 'system',
                'team_invite'        => 'system',
                'report_ready'       => 'system',
            ];

            $formatted = $rows->map(function ($n) use ($categoryMap) {
                return [
                    'id'          => $n->id,
                    'type'        => $n->type,
                    'title'       => $n->title,
                    'message'     => $n->message,
                    'workspace'   => $n->workspace_name ?? 'General',
                    'category'    => $categoryMap[$n->type] ?? 'system',
                    'created_at'  => $n->created_at, // ISO datetime — frontend formats as relative
                    'is_read'     => (bool)$n->is_read,
                    'status_type' => in_array($n->type, ['post_published', 'lead_converted']) ? 'success'
                                    : (in_array($n->type, ['integration_error']) ? 'warning'
                                    : (in_array($n->type, ['lead_assigned']) ? 'primary' : 'info')),
                ];
            });

            return response()->json(['success' => true, 'data' => $formatted]);
        });

        Route::post('/notifications/mark-read', function (Request $request) {
            $workspaceId = $request->input('workspace_id', null);
            $query = \Illuminate\Support\Facades\DB::table('notifications')
                ->where('is_read', false);
            if ($workspaceId) {
                $query->where('workspace_id', $workspaceId);
            }
            $query->update(['is_read' => true, 'read_at' => now(), 'updated_at' => now()]);
            return response()->json(['success' => true, 'message' => 'All notifications marked as read']);
        });

        Route::patch('/notifications/{id}/read', function ($id) {
            \Illuminate\Support\Facades\DB::table('notifications')
                ->where('id', $id)
                ->update(['is_read' => true, 'read_at' => now(), 'updated_at' => now()]);
            return response()->json(['success' => true]);
        });

        // Team API — Real DB-backed (users table)
        Route::get('/team', function () {
            $users = User::where('is_active', true)
                ->select('id', 'name', 'email', 'role', 'last_login_at', 'created_at')
                ->orderBy('name')
                ->get()
                ->map(function ($u) {
                    $nameParts = explode(' ', trim($u->name));
                    $initials = strtoupper(
                        implode('', array_map(fn($p) => $p[0] ?? '', array_slice($nameParts, 0, 2)))
                    );
                    $roleDisplay = match($u->role) {
                        'admin'     => 'Administrator',
                        'manager'   => 'Marketing Manager',
                        'executive' => 'Executive',
                        'viewer'    => 'Viewer',
                        default     => ucfirst($u->role),
                    };
                    return [
                        'id'               => $u->id,
                        'name'             => $u->name,
                        'email'            => $u->email,
                        'role'             => $roleDisplay,
                        'role_key'         => $u->role,
                        'last_login_at'    => $u->last_login_at ? $u->last_login_at->toIso8601String() : null,
                        'status'           => 'Active',
                        'initials'         => substr($initials, 0, 2),
                    ];
                });

            return response()->json(['success' => true, 'data' => $users]);
        });

        Route::post('/team', function (Request $request) {
            $validated = $request->validate([
                'name'     => 'required|string|max:255',
                'email'    => 'required|email|max:255|unique:users,email',
                'role'     => 'required|in:admin,manager,executive,viewer',
            ]);

            $roleMap = [
                'Administrator'      => 'admin',
                'Marketing Manager'  => 'manager',
                'Executive'          => 'executive',
                'Viewer'             => 'viewer',
            ];
            $roleKey = $roleMap[$validated['role']] ?? $validated['role'];

            $user = User::create([
                'name'      => $validated['name'],
                'email'     => $validated['email'],
                'password'  => Hash::make(str()->random(12)), // Temporary random password
                'role'      => in_array($roleKey, ['admin','manager','executive','viewer']) ? $roleKey : 'viewer',
                'is_active' => true,
            ]);

            $nameParts = explode(' ', trim($user->name));
            $initials  = strtoupper(implode('', array_map(fn($p) => $p[0] ?? '', array_slice($nameParts, 0, 2))));

            return response()->json([
                'success' => true,
                'data' => [
                    'id'           => $user->id,
                    'name'         => $user->name,
                    'email'        => $user->email,
                    'role'         => ucfirst($user->role),
                    'role_key'     => $user->role,
                    'last_login_at'=> null,
                    'status'       => 'Active',
                    'initials'     => substr($initials, 0, 2),
                ]
            ], 201);
        });

        Route::delete('/team/{id}', function ($id, Request $request) {
            // Prevent self-deletion
            if ($request->user() && $request->user()->id == $id) {
                return response()->json(['success' => false, 'message' => 'Cannot delete your own account.'], 403);
            }
            $user = User::findOrFail($id);
            $user->is_active = false;
            $user->save();
            return response()->json(['success' => true, 'message' => 'Team member deactivated.']);
        });

        // ── Dashboard Overview — Real Data ────────────────────────────────────────
        Route::get('/dashboard/overview', function (Request $request) {
            $workspaceId  = $request->query('workspace_id', null);
            $days         = (int)$request->query('days', 30);
            $forceRefresh = $request->boolean('force_refresh') || $request->boolean('refresh') || $request->has('force_refresh');
            if (!$workspaceId) {
                $first = Workspace::first();
                $workspaceId = $first ? $first->id : 1;
            }

            $workspace = Workspace::find($workspaceId);

            // Leads metrics (real DB)
            $leadsTotal    = Lead::where('workspace_id', $workspaceId)->count();
            $leadsByStatus = Lead::where('workspace_id', $workspaceId)
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');

            // Facebook metrics (real DB & live Meta sync)
            // NOTE: fbPage may belong to a different workspace_id (via fallback).
            // We must use fbPage->workspace_id for all post/metrics queries to avoid getting zeros.
            $fbPage = FacebookPage::where('workspace_id', $workspaceId)->first() ?? FacebookPage::latest()->first();
            $fbWorkspaceId = $fbPage ? $fbPage->workspace_id : $workspaceId;

            \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS] Page ID', [
                'requested_workspace_id' => $workspaceId,
                'fb_page_workspace_id'   => $fbWorkspaceId,
                'page_id'                => $fbPage?->page_id,
                'page_name'              => $fbPage?->page_name,
                'token_status'           => $fbPage?->token_status,
            ]);

            $engagement = 0;
            $impressions = 0;
            $insights = [];

            if ($fbPage && !empty($fbPage->page_access_token)) {
                try {
                    $graphService = new FacebookGraphService();
                    $graphService->refreshPageInsights($fbPage);
                    $graphService->syncWorkspacePosts($fbWorkspaceId);
                    $insights = $graphService->getPageInsights($fbPage->page_id, $fbPage->page_access_token, $days);
                    $engagement  = $insights['engagements'] ?? 0;
                    $impressions = $insights['impressions'] ?? 0;

                    \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS] Page Insights result', [
                        'page_id'  => $fbPage->page_id,
                        'insights' => $insights,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('[FACEBOOK METRICS] Page sync error: ' . $e->getMessage());
                }
            }

            // Posts & Real Metrics aggregation for selected period ($days)
            // Use fbWorkspaceId so we query posts that belong to the connected page's workspace.
            $startDate      = now()->subDays($days)->startOfDay();
            $totalPosts     = FacebookPost::where('workspace_id', $fbWorkspaceId)->count();
            $publishedToday = FacebookPost::where('workspace_id', $fbWorkspaceId)->where('status', 'published')->whereDate('published_at', now()->today())->count();
            $scheduledCount = FacebookPost::where('workspace_id', $fbWorkspaceId)->where('status', 'scheduled')->count();
            $draftsCount    = FacebookPost::where('workspace_id', $fbWorkspaceId)->where('status', 'draft')->count();

            $periodPostsQuery = FacebookPost::where('workspace_id', $fbWorkspaceId)
                ->where('status', 'published')
                ->where('published_at', '>=', $startDate);

            $totalLikes      = (int)(clone $periodPostsQuery)->sum('likes_count');
            $totalComments   = (int)(clone $periodPostsQuery)->sum('comments_count');
            $totalShares     = (int)(clone $periodPostsQuery)->sum('shares_count');
            $totalReactions  = (int)(clone $periodPostsQuery)->sum('reactions_count');
            $totalPostEngage = (int)(clone $periodPostsQuery)->sum('engagement_count');
            $totalPostReach  = (int)(clone $periodPostsQuery)->sum('reach_count');

            \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS] DB post sums', [
                'fb_workspace_id' => $fbWorkspaceId,
                'days'            => $days,
                'period_likes'    => $totalLikes,
                'period_comments' => $totalComments,
                'period_shares'   => $totalShares,
                'period_engagement'=> $totalPostEngage,
            ]);

            $fbPhotoMetrics = ['likes' => 0, 'comments' => 0, 'errors' => []];
            if ($fbPage && !empty($fbPage->page_access_token)) {
                try {
                    $graphService = $graphService ?? new FacebookGraphService();
                    $fbPhotoMetrics = $graphService->getPagePhotosMetrics($fbPage->page_id, $fbPage->page_access_token);
                    $totalLikes    = max($totalLikes, (int)($fbPhotoMetrics['likes'] ?? 0));
                    $totalComments = max($totalComments, (int)($fbPhotoMetrics['comments'] ?? 0));

                    \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS] Photo metrics', [
                        'page_id'          => $fbPage->page_id,
                        'photo_likes'      => $fbPhotoMetrics['likes'] ?? 0,
                        'photo_comments'   => $fbPhotoMetrics['comments'] ?? 0,
                        'photo_errors'     => $fbPhotoMetrics['errors'] ?? [],
                        'total_likes_after'=> $totalLikes,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('[FACEBOOK METRICS] Photo metrics error: ' . $e->getMessage());
                }
            }

            // Instagram metrics (real Instagram Graph API sync)
            $igInteg = \App\Models\Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'instagram')
                ->where('is_connected', true)
                ->first()
                ?? \App\Models\Integration::where('platform', 'instagram')
                ->where('is_connected', true)
                ->first();
            
            $igAccountInsights = ['reach' => 0, 'accounts_engaged' => 0, 'total_interactions' => 0, 'likes' => 0, 'comments' => 0];
            $igMediaItems = [];

            if ($igInteg && !empty($igInteg->refresh_token)) {
                try {
                    $graphService = $graphService ?? new FacebookGraphService();
                    $igAccountInsights = $graphService->getInstagramAccountInsights($igInteg->refresh_token, $days);
                    $igMediaItems = $graphService->getInstagramMediaList($igInteg->refresh_token, 50, $forceRefresh);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Instagram Overview fetch error: " . $e->getMessage());
                }
            }

            $igLikes        = (int)($igAccountInsights['likes'] ?? 0);
            $igComments     = (int)($igAccountInsights['comments'] ?? 0);
            $igReach        = (int)($igAccountInsights['reach'] ?? 0);
            $igInteractions = (int)($igAccountInsights['total_interactions'] ?? 0);
            $igShares       = !empty($igMediaItems) ? (int)array_sum(array_column($igMediaItems, 'shares_count')) : 0;
            $igViews        = !empty($igMediaItems) ? (int)array_sum(array_column($igMediaItems, 'views_count')) : 0;
            $igSaved        = !empty($igMediaItems) ? (int)array_sum(array_column($igMediaItems, 'saved_count')) : 0;

            if ($igLikes === 0 && !empty($igMediaItems)) {
                $igLikes = array_sum(array_column($igMediaItems, 'like_count'));
            }
            if ($igComments === 0 && !empty($igMediaItems)) {
                $igComments = array_sum(array_column($igMediaItems, 'comments_count'));
            }

            $pageReach  = $fbPage ? max($fbPage->followers_count, $fbPage->fan_count) : 0;
            $reach      = max($pageReach, $totalPostReach, $impressions, $igReach, $totalLikes + $totalComments);
            $fanCount   = $fbPage ? $fbPage->fan_count : 0;
            $engagement = max($totalPostEngage, $engagement);

            $connectedChannelsList = [];
            if ($fbPage && !empty($fbPage->page_access_token)) {
                $connectedChannelsList[] = [
                    'name'      => 'Facebook',
                    'code'      => 'FB',
                    'status'    => $fbPage->token_status === 'valid' ? 'connected' : ($fbPage->token_status ?? 'connected'),
                    'followers' => max($fbPage->followers_count ?? 0, $fbPage->fan_count ?? 0),
                    'last_sync' => $fbPage->updated_at ? $fbPage->updated_at->toIso8601String() : null,
                ];
            }


            if ($igInteg && $igInteg->is_connected) {
                $connectedChannelsList[] = [
                    'name'      => 'Instagram',
                    'code'      => 'IN',
                    'status'    => 'connected',
                    'followers' => $igInteg->followers_count ?? 0,
                    'last_sync' => $igInteg->updated_at ? $igInteg->updated_at->toIso8601String() : null,
                ];
            }
            $ytConn = null;
            try {
                $ytConn = \App\Models\YoutubeConnection::where('is_connected', true)->first();
                if ($ytConn) {
                    $connectedChannelsList[] = [
                        'name'        => 'YouTube',
                        'code'        => 'YT',
                        'status'      => 'connected',
                        'followers'   => $ytConn->subscriber_count ?? 0,
                        'last_sync'   => $ytConn->updated_at ? $ytConn->updated_at->toIso8601String() : null,
                    ];
                }
            } catch (\Throwable $e) {
                $ytConn = null;
            }
            $otherIntegrations = \App\Models\Integration::where('workspace_id', $workspaceId)
                ->where('is_connected', true)
                ->get();
            foreach ($otherIntegrations as $oi) {
                $pName = ucfirst($oi->platform);
                if (strtolower($oi->platform) === 'twitter') $pName = 'X / Twitter';
                if (strtolower($oi->platform) === 'facebook') $pName = 'Facebook';
                if (strtolower($oi->platform) === 'instagram') $pName = 'Instagram';
                $connectedChannelsList[] = [
                    'name'      => $pName,
                    'code'      => strtoupper(substr($oi->platform, 0, 2)),
                    'status'    => 'connected',
                    'followers' => $oi->followers_count ?? 0,
                    'last_sync' => $oi->updated_at ? $oi->updated_at->toIso8601String() : null,
                ];
            }
            // Facebook Feed Post Metrics (direct Meta Graph API query)
            $hasConnectedFb = ($fbPage && !empty($fbPage->page_access_token));
            $fbFeedMetrics  = [
                'success'            => false,
                'views'              => null,
                'likes'              => null,
                'comments'           => null,
                'shares'             => null,
                'views_supported'    => false,
                'likes_supported'    => false,
                'comments_supported' => false,
                'shares_supported'   => false,
            ];

            if ($hasConnectedFb) {
                try {
                    $graphService = $graphService ?? new FacebookGraphService();
                    $fbFeedMetrics = $graphService->getFacebookFeedPostMetrics($fbPage->page_id, $fbPage->page_access_token, $days, $forceRefresh);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('[FACEBOOK METRICS] getFacebookFeedPostMetrics exception: ' . $e->getMessage());
                }
            }

            \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS FEED SYNC RESULT]', [
                'has_connected_fb' => $hasConnectedFb,
                'feed_metrics'     => $fbFeedMetrics,
            ]);

            $facebookMetrics = [
                'views'               => $fbFeedMetrics['views'],
                'likes'               => $fbFeedMetrics['likes'],
                'comments'            => $fbFeedMetrics['comments'],
                'shares'              => $fbFeedMetrics['shares'],
                'views_supported'     => (bool)($fbFeedMetrics['views_supported'] ?? false),
                'likes_supported'     => (bool)($fbFeedMetrics['likes_supported'] ?? false),
                'comments_supported'  => (bool)($fbFeedMetrics['comments_supported'] ?? false),
                'shares_supported'   => (bool)($fbFeedMetrics['shares_supported'] ?? false),
                'error_reason'        => $fbFeedMetrics['error_msg'] ?? null,
            ];

            $igViewsSupported = true;
            $igSharesSupported = true;

            \Illuminate\Support\Facades\Log::info("[INSTAGRAM METRICS DEV LOG]", [
                'views'  => ['val' => $igViews, 'supported' => $igViewsSupported],
                'shares' => ['val' => $igShares, 'supported' => $igSharesSupported],
            ]);

            $instagramMetrics = [
                'views'               => $igViews,
                'likes'               => $igLikes,
                'comments'            => $igComments,
                'shares'              => $igShares,
                'views_supported'     => $igViewsSupported,
                'shares_supported'    => $igSharesSupported,
            ];

            // Deduplicate connected integrations by channel name
            $uniqueIntegrations = [];
            $seenNames = [];
            foreach ($connectedChannelsList as $ci) {
                if (!in_array($ci['name'], $seenNames)) {
                    $seenNames[] = $ci['name'];
                    $uniqueIntegrations[] = $ci;
                }
            }
            // Recent activity from notifications table
            $recentActivity = \Illuminate\Support\Facades\DB::table('notifications')
                ->where('workspace_id', $workspaceId)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(fn($n) => [
                    'id'        => $n->id,
                    'type'      => $n->type,
                    'title'     => $n->title,
                    'message'   => $n->message,
                    'created_at'=> $n->created_at,
                    'is_read'   => (bool)$n->is_read,
                ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'workspace_id'          => $workspaceId,
                    'workspace_name'        => $workspace?->name ?? 'Workspace',
                    'reach'                 => $reach,
                    'engagement'            => $engagement,
                    'impressions'           => $impressions,
                    'fan_count'             => $fanCount,
                    'leads_total'           => $leadsTotal,
                    'leads_by_status'       => $leadsByStatus,
                    'total_posts'           => $totalPosts,
                    'published_today'       => $publishedToday,
                    'scheduled_count'       => $scheduledCount,
                    'drafts_count'          => $draftsCount,
                    'total_likes'           => $totalLikes,
                    'total_comments'        => $totalComments,
                    'total_shares'          => $totalShares,
                    'total_views'           => $igViews,
                    'facebook_metrics'      => $facebookMetrics,
                    'instagram_metrics'     => $instagramMetrics,
                    'total_engagement'      => $totalPostEngage,
                    'connected_channels'    => $uniqueIntegrations,
                    'recent_activity'       => $recentActivity,
                    'connected_page'        => $fbPage ? [
                        'page_id'         => $fbPage->page_id,
                        'page_name'       => $fbPage->page_name,
                        'followers_count' => $fbPage->followers_count,
                        'fan_count'       => $fbPage->fan_count,
                        'token_status'    => $fbPage->token_status,
                        'connected_since' => $fbPage->connected_since ? \Carbon\Carbon::parse($fbPage->connected_since)->toIso8601String() : null,
                    ] : null,
                    'youtube_connection'    => $ytConn ? [
                        'channel_name'     => $ytConn->channel_name,
                        'subscriber_count' => $ytConn->subscriber_count,
                        'video_count'      => $ytConn->video_count,
                        'view_count'       => $ytConn->view_count,
                    ] : null,
                ]
            ]);
        });

        // ── Dashboard Trend — Real daily metrics ──────────────────────────────────
        Route::get('/dashboard/trend', function (Request $request) {
            $workspaceId    = $request->query('workspace_id', 1);
            $startDateParam = $request->query('start_date');
            $endDateParam   = $request->query('end_date');

            if (!empty($startDateParam) && !empty($endDateParam)) {
                $start = \Carbon\Carbon::parse($startDateParam)->startOfDay();
                $end   = \Carbon\Carbon::parse($endDateParam)->endOfDay();
                if ($end->isBefore($start)) {
                    $end = $start->copy()->endOfDay();
                }
                $days = (int)$start->diffInDays($end) + 1;
            } else {
                $days  = (int)$request->query('days', 30);
                $start = now()->subDays($days - 1)->startOfDay();
                $end   = now()->endOfDay();
            }

            $fbPage = FacebookPage::where('workspace_id', $workspaceId)->first() ?? FacebookPage::latest()->first();

            $trendData = ['labels' => [], 'reach' => [], 'engagement' => [], 'has_data' => false];

            if ($fbPage && !empty($fbPage->page_access_token)) {
                try {
                    $graphService = new \App\Services\FacebookGraphService();
                    $trendData = $graphService->getPageInsightsTrend(
                        $fbPage->page_id,
                        $fbPage->page_access_token,
                        $days
                    );
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Page Insights trend fetch error: " . $e->getMessage());
                }
            }

            // Build complete daily time series for the date range
            $labels       = [];
            $reach        = [];
            $engagement   = [];
            $dateIndexMap = [];

            for ($i = 0; $i < $days; $i++) {
                $currDate = (clone $start)->addDays($i);
                $dStr = $currDate->format('Y-m-d');
                $lbl  = $currDate->format('M j');
                $labels[] = $lbl;
                $reach[]  = 0;
                $engagement[] = 0;
                $dateIndexMap[$dStr] = $i;
                $dateIndexMap[$lbl]  = $i;
            }

            if (!empty($trendData['labels'])) {
                foreach ($trendData['labels'] as $idx => $lbl) {
                    if (isset($dateIndexMap[$lbl])) {
                        $targetIdx = $dateIndexMap[$lbl];
                        $reach[$targetIdx]      += (int)($trendData['reach'][$idx] ?? 0);
                        $engagement[$targetIdx] += (int)($trendData['engagement'][$idx] ?? 0);
                    }
                }
            }

            // Overlay published post metrics onto daily trend dates
            $posts = FacebookPost::where('workspace_id', $workspaceId)
                ->where('status', 'published')
                ->whereBetween('published_at', [$start, $end])
                ->get();

            foreach ($posts as $post) {
                $lbl = \Carbon\Carbon::parse($post->published_at)->format('M j');
                if (isset($dateIndexMap[$lbl])) {
                    $idx = $dateIndexMap[$lbl];
                    $engagement[$idx] += ($post->engagement_count ?? 0);
                }
            }

            // Fetch and overlay Instagram daily insights trend
            $igInteg = \App\Models\Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'instagram')
                ->where('is_connected', true)
                ->first()
                ?? \App\Models\Integration::where('platform', 'instagram')
                ->where('is_connected', true)
                ->first();

            if ($igInteg && !empty($igInteg->refresh_token)) {
                try {
                    $graphService = $graphService ?? new \App\Services\FacebookGraphService();
                    $igTrend = $graphService->getInstagramInsightsTrend($igInteg->refresh_token, $days);
                    if (!empty($igTrend['has_data'])) {
                        $trendData['has_data'] = true;
                        foreach ($igTrend['reach'] as $idx => $val) {
                            if (isset($reach[$idx])) {
                                $reach[$idx] += $val;
                            }
                        }
                        foreach ($igTrend['engagement'] as $idx => $val) {
                            if (isset($engagement[$idx])) {
                                $engagement[$idx] += $val;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Instagram Insights trend fetch error: " . $e->getMessage());
                }
            }

            $hasData = ($trendData['has_data'] ?? false) || count(array_filter($reach)) > 0 || count(array_filter($engagement)) > 0 || $posts->count() > 0;

            return response()->json([
                'success'   => true,
                'has_data'  => $hasData,
                'data' => [
                    'labels'     => $labels,
                    'reach'      => $reach,
                    'engagement' => $engagement,
                ]
            ]);
        });

        // ── Dashboard Priorities — Scheduled posts + expiring tokens ──────────────
        Route::get('/dashboard/priorities', function (Request $request) {
            $workspaceId = $request->query('workspace_id', 1);
            $priorities = [];

            // 1. Scheduled posts due today/tomorrow
            $upcomingPosts = FacebookPost::where('workspace_id', $workspaceId)
                ->where('status', 'scheduled')
                ->whereBetween('scheduled_at', [now()->startOfDay(), now()->addDays(2)->endOfDay()])
                ->orderBy('scheduled_at')
                ->limit(5)
                ->get();

            foreach ($upcomingPosts as $post) {
                $priorities[] = [
                    'type'        => 'scheduled_post',
                    'title'       => 'Approve scheduled post',
                    'description' => \Carbon\Carbon::parse($post->scheduled_at)->format('M j \a\t g:i A'),
                    'action'      => 'Review',
                    'action_url'  => '/publishing',
                    'due_at'      => $post->scheduled_at,
                ];
            }

            // 2. Expiring integrations (tokens expiring within 7 days)
            $expiringIntegrations = \App\Models\Integration::where('workspace_id', $workspaceId)
                ->where('is_connected', true)
                ->whereNotNull('token_expires_at')
                ->where('token_expires_at', '<=', now()->addDays(7))
                ->get();

            foreach ($expiringIntegrations as $integ) {
                $priorities[] = [
                    'type'        => 'expiring_token',
                    'title'       => ucfirst($integ->platform) . ' connection expiring',
                    'description' => 'Reconnect before ' . \Carbon\Carbon::parse($integ->token_expires_at)->format('M j'),
                    'action'      => 'Fix',
                    'action_url'  => '/integrations',
                    'due_at'      => $integ->token_expires_at,
                ];
            }

            // 3. YouTube token expiry check
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('youtube_connections')) {
                    $ytConn = \App\Models\YouTubeConnection::whereNotNull('token_expires_at')
                        ->where('token_expires_at', '<=', now()->addDays(7))
                        ->latest()
                        ->first();
                    if ($ytConn) {
                        $priorities[] = [
                            'type'        => 'expiring_token',
                            'title'       => 'YouTube connection expiring',
                            'description' => 'Reconnect before ' . \Carbon\Carbon::parse($ytConn->token_expires_at)->format('M j'),
                            'action'      => 'Fix',
                            'action_url'  => '/integrations',
                            'due_at'      => $ytConn->token_expires_at,
                        ];
                    }
                }
            } catch (\Throwable $e) {}

            // 4. New leads awaiting follow-up (created > 24h ago, still 'new')
            $overdueLeads = Lead::where('workspace_id', $workspaceId)
                ->where('status', 'new')
                ->where('created_at', '<', now()->subHours(24))
                ->count();
            if ($overdueLeads > 0) {
                $priorities[] = [
                    'type'        => 'overdue_leads',
                    'title'       => 'Follow up ' . $overdueLeads . ' lead' . ($overdueLeads > 1 ? 's' : ''),
                    'description' => $overdueLeads . ' lead' . ($overdueLeads > 1 ? 's' : '') . ' awaiting contact',
                    'action'      => 'Open',
                    'action_url'  => '/leads',
                    'due_at'      => null,
                ];
            }

            return response()->json(['success' => true, 'data' => $priorities]);
        });

        // ── Per-Workspace Metrics (for Clients page cards) ────────────────────────
        Route::get('/workspace/{id}/metrics', function ($id) {
            $workspace = Workspace::findOrFail($id);

            $leadsCount  = Lead::where('workspace_id', $id)->count();
            $fbPages = FacebookPage::where('workspace_id', $id)->get();
            
            $connectedPlatforms = [];
            if ($fbPages->count() > 0) {
                $connectedPlatforms['facebook'] = true;
            }
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('youtube_connections')) {
                    if (\App\Models\YouTubeConnection::where('is_connected', true)->exists()) {
                        $connectedPlatforms['youtube'] = true;
                    }
                }
            } catch (\Throwable $e) {}

            $otherConns = \App\Models\Integration::where('workspace_id', $id)
                ->where('is_connected', true)
                ->get();
            foreach ($otherConns as $integ) {
                $p = strtolower($integ->platform);
                $connectedPlatforms[$p] = true;
            }

            if (!isset($connectedPlatforms['instagram'])) {
                $igFallback = \App\Models\Integration::where('platform', 'instagram')->where('is_connected', true)->first();
                if ($igFallback) {
                    $connectedPlatforms['instagram'] = true;
                }
            }

            $channelCount = count($connectedPlatforms);

            $reach = $fbPages->sum('followers_count');

            $lastActivity = FacebookPost::where('workspace_id', $id)
                ->orderBy('created_at', 'desc')
                ->value('created_at');

            return response()->json([
                'success' => true,
                'data' => [
                    'workspace_id'   => $id,
                    'workspace_name' => $workspace->name,
                    'status'         => $workspace->status ?? 'active',
                    'reach'          => $reach,
                    'leads'          => $leadsCount,
                    'channels'       => $channelCount,
                    'last_activity'  => $lastActivity,
                ]
            ]);
        });

        // ── Integration Status (real timestamps from DB) ───────────────────────────
        Route::get('/integrations/status', function (Request $request) {
            $workspaceId = $request->query('workspace_id', null);

            $platforms = [
                ['key' => 'facebook',        'name' => 'Facebook Pages',        'code' => 'FB',  'phase' => 1],
                ['key' => 'instagram',       'name' => 'Instagram Business',    'code' => 'IG',  'phase' => 1],
                ['key' => 'youtube',         'name' => 'YouTube Channels',      'code' => 'YT',  'phase' => 1],
                ['key' => 'google_analytics','name' => 'Google Analytics',      'code' => 'GA',  'phase' => 1],
                ['key' => 'search_console',  'name' => 'Search Console',        'code' => 'SC',  'phase' => 1],
                ['key' => 'google_business', 'name' => 'Google Business Profile','code' => 'GB', 'phase' => 1],
                ['key' => 'linkedin',        'name' => 'LinkedIn Pages',        'code' => 'in',  'phase' => 2],
                ['key' => 'twitter',         'name' => 'X / Twitter',           'code' => 'X',   'phase' => 2],
            ];

            $result = [];

            foreach ($platforms as $platform) {
                $status    = 'disconnected';
                $lastSync  = null;
                $accountName = null;
                $tokenExpiry = null;

                if ($platform['key'] === 'facebook') {
                    $fbPage = $workspaceId
                        ? FacebookPage::where('workspace_id', $workspaceId)->first()
                        : FacebookPage::latest()->first();
                    if ($fbPage) {
                        $status      = $fbPage->token_status === 'valid' ? 'connected' : 'error';
                        $lastSync    = $fbPage->updated_at ? $fbPage->updated_at->toIso8601String() : null;
                        $accountName = $fbPage->page_name;
                    }
                } elseif ($platform['key'] === 'instagram') {
                    $igInteg = Integration::where('platform', 'instagram')
                        ->when($workspaceId, fn($q) => $q->where('workspace_id', $workspaceId))
                        ->where('is_connected', true)
                        ->latest()->first();
                    if ($igInteg) {
                        $status      = $igInteg->connection_status ?? 'connected';
                        $lastSync    = $igInteg->last_sync_at ? $igInteg->last_sync_at->toIso8601String() : null;
                        $accountName = $igInteg->account_name;
                        $tokenExpiry = $igInteg->token_expires_at;
                    } else {
                        // Fallback: check if Facebook page has linked Instagram
                        $fbPage = $workspaceId
                            ? FacebookPage::where('workspace_id', $workspaceId)->first()
                            : FacebookPage::latest()->first();
                        if ($fbPage) {
                            $status      = $fbPage->token_status === 'valid' ? 'connected' : 'error';
                            $lastSync    = $fbPage->updated_at ? $fbPage->updated_at->toIso8601String() : null;
                            $accountName = $fbPage->page_name . ' (IG)';
                        }
                    }
                } elseif ($platform['key'] === 'youtube') {
                    try {
                        if (\Illuminate\Support\Facades\Schema::hasTable('youtube_connections')) {
                            $ytConn = \App\Models\YouTubeConnection::latest()->first();
                            if ($ytConn) {
                                $status      = 'connected';
                                $lastSync    = $ytConn->updated_at ? $ytConn->updated_at->toIso8601String() : null;
                                $accountName = $ytConn->channel_name;
                                $tokenExpiry = $ytConn->token_expires_at;
                            }
                        }
                    } catch (\Throwable $e) {}
                } elseif ($platform['key'] === 'google_analytics') {
                    // Check if credentials file exists AND property ID is configured
                    $credPath = base_path(env('GOOGLE_ANALYTICS_CREDENTIALS_PATH', 'ga-credentials.json'));
                    $propId   = env('GOOGLE_ANALYTICS_PROPERTY_ID', '');
                    if (file_exists($credPath) && !empty($propId)) {
                        // Get last sync from integrations table if stored
                        $integ = \App\Models\Integration::where('platform', 'google_analytics')
                            ->when($workspaceId, fn($q) => $q->where('workspace_id', $workspaceId))
                            ->latest()->first();
                        $status   = 'connected';
                        $lastSync = $integ?->last_sync_at?->toIso8601String();
                    } else {
                        $status = 'disconnected';
                    }
                } elseif ($platform['key'] === 'search_console') {
                    $credPath = base_path(env('GOOGLE_ANALYTICS_CREDENTIALS_PATH', 'ga-credentials.json'));
                    $siteUrl  = env('GOOGLE_SEARCH_CONSOLE_SITE_URL', '');
                    if (file_exists($credPath) && !empty($siteUrl)) {
                        $integ  = \App\Models\Integration::where('platform', 'search_console')
                            ->when($workspaceId, fn($q) => $q->where('workspace_id', $workspaceId))
                            ->latest()->first();
                        $status   = 'connected';
                        $lastSync = $integ?->last_sync_at?->toIso8601String();
                    } else {
                        $status = 'disconnected';
                    }
                } else {
                    // Generic: check integrations table
                    $integ = \App\Models\Integration::where('platform', $platform['key'])
                        ->when($workspaceId, fn($q) => $q->where('workspace_id', $workspaceId))
                        ->latest()->first();
                    if ($integ && $integ->is_connected) {
                        $status      = $integ->connection_status ?? 'connected';
                        $lastSync    = $integ->last_sync_at ? $integ->last_sync_at->toIso8601String() : null;
                        $accountName = $integ->account_name;
                        $tokenExpiry = $integ->token_expires_at;
                    }
                }

                $result[] = [
                    'key'         => $platform['key'],
                    'name'        => $platform['name'],
                    'code'        => $platform['code'],
                    'phase'       => $platform['phase'],
                    'status'      => $status,
                    'last_sync'   => $lastSync, // ISO datetime or null
                    'account_name'=> $accountName,
                    'token_expiry'=> $tokenExpiry,
                ];
            }

            return response()->json(['success' => true, 'data' => $result]);
        });

    });

    // Posts / Social Publishing API
    Route::get('/posts', function (Request $request) {
        $query = Post::with('workspace', 'creator')->orderBy('created_at', 'desc');
        if ($request->has('workspace_id')) {
            $query->where('workspace_id', $request->workspace_id);
        }
        $posts = $query->get();
        return response()->json([
            'success' => true,
            'data' => $posts
        ]);
    });

    Route::post('/posts', function (Request $request) {
        $validated = $request->validate([
            'workspace_id'  => 'nullable|integer',
            'title'         => 'nullable|string|max:255',
            'content'       => 'required|string',
            'platform_list' => 'required|array',
            'status'        => 'required|in:draft,scheduled,published',
            'scheduled_at'  => 'nullable|date',
            'media_urls'    => 'nullable|array',
        ]);

        $status = $validated['status'];
        $publishedAt = ($status === 'published') ? now() : null;

        $post = Post::create([
            'workspace_id'    => getOrCreateWorkspaceId($validated['workspace_id'] ?? 1),
            'content'         => $validated['content'],
            'platform_list'   => $validated['platform_list'],
            'status'          => $status,
            'scheduled_at'    => $validated['scheduled_at'] ?? null,
            'published_at'    => $publishedAt,
            'media_urls'      => $validated['media_urls'] ?? [],
            'approval_status' => 'approved',
            'created_by_id'   => $request->user()->id ?? 1,
        ]);

        return response()->json([
            'success' => true,
            'message' => $status === 'published' ? 'Post published successfully!' : ($status === 'scheduled' ? 'Post scheduled successfully!' : 'Draft saved.'),
            'data'    => $post,
        ], 201);
    });

    Route::delete('/posts/{id}', function ($id) {
        $post = Post::findOrFail($id);
        $post->delete();
        return response()->json(['success' => true, 'message' => 'Post deleted successfully.']);
    });

    // Integrations (API Connections) — list all
    Route::get('/integrations', function (Request $request) {
        $query = \App\Models\Integration::query();
        if ($request->has('workspace_id')) {
            $query->where('workspace_id', $request->workspace_id);
        }
        $integrations = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['success' => true, 'data' => $integrations]);
    });

    // Integrations — connect a new platform
    Route::post('/integrations', function (Request $request) {
        $validated = $request->validate([
            'workspace_id'   => 'nullable|integer',
            'platform'       => 'required|string|in:facebook,instagram,youtube,google_analytics,search_console,google_business,linkedin,twitter,mailchimp,slack',
            'account_name'   => 'nullable|string|max:255',
            'account_id'     => 'required|string|max:255',
            'refresh_token'  => 'nullable|string',
        ]);

        $workspaceId = getOrCreateWorkspaceId($request->input('workspace_id', 1));

        // Upsert — if same workspace+platform+account already exists, update it
        $integration = \App\Models\Integration::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => $validated['platform'],
                'account_id'   => $validated['account_id'],
            ],
            [
                'account_name'      => $validated['account_name'] ?? $validated['account_id'],
                'refresh_token'     => $validated['refresh_token'] ?? null,
                'is_connected'      => true,
                'connection_status' => 'connected',
                'last_sync_at'      => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Platform connected successfully.',
            'data'    => $integration,
        ], 201);
    });

    // Integrations — disconnect (delete)
    Route::delete('/integrations/{id}', function ($id) {
        $integration = Integration::findOrFail($id);
        $integration->delete();
        return response()->json(['success' => true, 'message' => 'Platform disconnected.']);
    });

    // ── Facebook OAuth & Graph API Endpoints ─────────────────────────────────────

    // GET /v1/auth/facebook
    Route::get('/auth/facebook', function (Request $request) {
        $workspaceId = $request->query('workspace_id', '1');
        $appId = env('FACEBOOK_APP_ID');
        $redirectUri = env('FACEBOOK_REDIRECT_URI', 'http://localhost:8000/api/v1/auth/facebook/callback');
        $state = base64_encode(json_encode(['workspace_id' => $workspaceId, 'ts' => time()]));
        $scopes = implode(',', [
            'public_profile',
            'pages_show_list',
            'pages_manage_posts',
            'pages_read_engagement',
            'pages_read_user_content',
            'read_insights',
        ]);

        $dialogUrl = "https://www.facebook.com/v23.0/dialog/oauth?" . http_build_query([
            'client_id'     => $appId,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => $scopes,
            'response_type' => 'code',
        ]);

        return redirect($dialogUrl);
    });

    Route::get('/auth/facebook/redirect', function (Request $request) {
        return redirect()->to('/api/v1/auth/facebook?' . http_build_query($request->all()));
    });

    // GET /v1/auth/facebook/callback
    Route::get('/auth/facebook/callback', function (Request $request) {
        $code = $request->query('code');
        $stateRaw = $request->query('state', '');
        $workspaceId = 1;
        if ($stateRaw) {
            $decoded = json_decode(base64_decode($stateRaw), true);
            $workspaceId = $decoded['workspace_id'] ?? 1;
        }

        if (!$code) {
            $error = $request->query('error_description', 'Authorization cancelled or denied.');
            return response()->make(
                '<html><body style="font-family:sans-serif;text-align:center;padding:40px;background:#fef2f2">'
                . '<h2 style="color:#dc2626">&#x274C; Facebook Login Failed</h2>'
                . '<p style="color:#374151">' . htmlspecialchars($error) . '</p>'
                . '<script>setTimeout(function(){ if(window.opener){ window.opener.postMessage({type:"FACEBOOK_OAUTH_ERROR",error:"' . addslashes($error) . '"}, "*"); } window.close(); }, 2500);</script>'
                . '</body></html>', 200, ['Content-Type' => 'text/html']
            );
        }

        try {
            $appId = env('FACEBOOK_APP_ID');
            $appSecret = env('FACEBOOK_APP_SECRET');
            $redirectUri = env('FACEBOOK_REDIRECT_URI', 'http://localhost:8000/api/v1/auth/facebook/callback');

            // Step 1: Exchange code for short-lived user access token
            $tokenRes = Http::withoutVerifying()->timeout(30)->connectTimeout(10)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->get('https://graph.facebook.com/v23.0/oauth/access_token', [
                'client_id'     => $appId,
                'client_secret' => $appSecret,
                'redirect_uri'  => $redirectUri,
                'code'          => $code,
            ]);

            if (!$tokenRes->successful()) {
                throw new \Exception($tokenRes->json('error.message') ?? 'Failed to exchange code for user access token');
            }

            $shortLivedToken = $tokenRes->json('access_token');

            // Step 2: Exchange short-lived token for long-lived user access token
            $longTokenRes = Http::withoutVerifying()->timeout(30)->connectTimeout(10)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->get('https://graph.facebook.com/v23.0/oauth/access_token', [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $appId,
                'client_secret'     => $appSecret,
                'fb_exchange_token' => $shortLivedToken,
            ]);

            $longLivedToken = $longTokenRes->successful() ? $longTokenRes->json('access_token') : $shortLivedToken;

            // Step 3: Fetch managed Facebook Pages & Linked Instagram Business Accounts
            $accountsRes = Http::withoutVerifying()->timeout(30)->connectTimeout(10)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->get('https://graph.facebook.com/v23.0/me/accounts', [
                'access_token' => $longLivedToken,
                'fields'       => 'id,name,access_token,category,picture,tasks,instagram_business_account{id,username,name,profile_picture_url,followers_count}',
            ]);

            if (!$accountsRes->successful()) {
                throw new \Exception($accountsRes->json('error.message') ?? 'Failed to fetch Facebook Pages');
            }

            $pages = $accountsRes->json('data') ?? [];

            return response()->make(
                '<html><body style="font-family:sans-serif;text-align:center;padding:40px;background:#f0fdf4">'
                . '<h2 style="color:#16a34a">&#x2705; Facebook Authenticated!</h2>'
                . '<p style="color:#374151">Retrieved ' . count($pages) . ' Facebook Page(s). Select a page in your dashboard.</p>'
                . '<script>'
                . 'if (window.opener) {'
                . '  window.opener.postMessage({'
                . '    type: "FACEBOOK_PAGES_FETCHED",'
                . '    pages: ' . json_encode($pages) . ','
                . '    userToken: "' . addslashes($longLivedToken) . '",'
                . '    workspaceId: ' . intval($workspaceId)
                . '  }, "*");'
                . '}'
                . 'setTimeout(function(){ window.close(); }, 1200);'
                . '</script>'
                . '</body></html>',
                200,
                ['Content-Type' => 'text/html']
            );

        } catch (\Exception $e) {
            return response()->make(
                '<html><body style="font-family:sans-serif;text-align:center;padding:40px;background:#fef2f2">'
                . '<h2 style="color:#dc2626">&#x274C; Error Authenticating Facebook</h2>'
                . '<p style="color:#374151">' . htmlspecialchars($e->getMessage()) . '</p>'
                . '<script>setTimeout(function(){ if(window.opener){ window.opener.postMessage({type:"FACEBOOK_OAUTH_ERROR",error:"' . addslashes($e->getMessage()) . '"}, "*"); } window.close(); }, 3000);</script>'
                . '</body></html>', 200, ['Content-Type' => 'text/html']
            );
        }
    });

    // GET /v1/facebook/pages
    Route::get('/facebook/pages', function (Request $request) {
        $workspaceId = $request->query('workspace_id', 1);
        $pages = FacebookPage::where('workspace_id', $workspaceId)->get();

        return response()->json([
            'success' => true,
            'data'    => $pages->map(function ($p) {
                return [
                    'id'                  => $p->id,
                    'workspace_id'        => $p->workspace_id,
                    'account_id'          => $p->page_id,
                    'account_name'        => $p->page_name,
                    'followers_count'     => $p->followers_count,
                    'fan_count'           => $p->fan_count,
                    'profile_picture_url' => $p->profile_picture_url,
                    'connected_since'     => $p->connected_since ? $p->connected_since->toIso8601String() : null,
                    'token_status'        => $p->token_status,
                ];
            }),
        ]);
    });

    // POST /v1/facebook/connect-page
    Route::post('/facebook/connect-page', function (Request $request) {
        $validated = $request->validate([
            'workspace_id'         => 'nullable|integer',
            'page_id'              => 'required|string',
            'page_name'            => 'required|string',
            'page_access_token'    => 'required|string',
            'instagram_account_id' => 'nullable|string',
        ]);

        $workspaceId = getOrCreateWorkspaceId($request->input('workspace_id', 1));

        $graphService = new FacebookGraphService();

        $followers = 0;
        $fans = 0;
        $pictureUrl = null;

        try {
            $details = $graphService->getPageDetails($validated['page_id'], $validated['page_access_token']);
            $followers = $details['followers_count'] ?? 0;
            $fans = $details['fan_count'] ?? 0;
            $pictureUrl = $details['profile_picture_url'] ?? null;
        } catch (\Exception $e) {
            // Log & continue fallback
        }

        $page = FacebookPage::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'page_id'      => $validated['page_id'],
            ],
            [
                'page_name'           => $validated['page_name'],
                'page_access_token'   => $validated['page_access_token'], // Encrypted at model layer
                'followers_count'     => $followers,
                'fan_count'           => $fans,
                'profile_picture_url' => $pictureUrl,
                'token_status'        => 'valid',
                'connected_since'     => now(),
            ]
        );

        // Sync legacy integration table
        Integration::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'facebook',
                'account_id'   => $validated['page_id'],
            ],
            [
                'account_name'      => $validated['page_name'],
                'refresh_token'     => $validated['page_access_token'],
                'is_connected'      => true,
                'connection_status' => 'connected',
                'last_sync_at'      => now(),
            ]
        );

        // If linked Instagram account ID is provided, auto-connect Instagram for the same workspace
        if (!empty($validated['instagram_account_id'])) {
            Integration::updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'platform'     => 'instagram',
                ],
                [
                    'account_id'        => $validated['instagram_account_id'],
                    'account_name'      => $validated['page_name'],
                    'refresh_token'     => $validated['page_access_token'],
                    'is_connected'      => true,
                    'connection_status' => 'connected',
                    'last_sync_at'      => now(),
                ]
            );
        }

        // Write a real notification to DB on successful page connect
        createNotification(
            $workspaceId,
            'integration_error',
            'Facebook Page Connected',
            "Facebook Page '{$validated['page_name']}' was connected to this workspace.",
            $page->page_name
        );

        return response()->json([
            'success' => true,
            'message' => "Facebook Page '{$validated['page_name']}' connected successfully!",
            'data'    => [
                'id'                  => $page->id,
                'workspace_id'        => $page->workspace_id,
                'account_id'          => $page->page_id,
                'account_name'        => $page->page_name,
                'followers_count'     => $page->followers_count,
                'profile_picture_url' => $page->profile_picture_url,
                'connected_since'     => $page->connected_since->toIso8601String(),
                'token_status'        => $page->token_status,
            ],
        ]);
    });

    // POST /v1/instagram/connect
    Route::post('/instagram/connect', function (Request $request) {
        $validated = $request->validate([
            'workspace_id'         => 'nullable|integer',
            'instagram_account_id' => 'required|string',
            'access_token'         => 'required|string',
            'account_name'         => 'nullable|string',
        ]);

        $workspaceId = getOrCreateWorkspaceId($request->input('workspace_id', 1));
        $accountId   = trim($validated['instagram_account_id']);
        $token       = trim($validated['access_token']);
        $accountName = trim($validated['account_name'] ?? '');

        // Try fetching account details from Graph API
        $followers = 0;
        try {
            $response = Http::withoutVerifying()->timeout(30)->connectTimeout(10)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->get("https://graph.facebook.com/v23.0/{$accountId}", [
                'fields'       => 'id,username,name,followers_count',
                'access_token' => $token,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $accountName = $data['username'] ?? $data['name'] ?? $accountName;
                $followers   = $data['followers_count'] ?? 0;
            } else {
                // Try Instagram Basic Display endpoint
                $basicRes = Http::withoutVerifying()->timeout(30)->connectTimeout(10)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->get("https://graph.instagram.com/me", [
                    'fields'       => 'id,username,account_type',
                    'access_token' => $token,
                ]);
                if ($basicRes->successful()) {
                    $bData = $basicRes->json();
                    $accountName = $bData['username'] ?? $accountName;
                }
            }
        } catch (\Exception $e) {
            // Network fallback
        }

        if (empty($accountName)) {
            $accountName = "Instagram Account ({$accountId})";
        }

        $integration = Integration::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'instagram',
                'account_id'   => $accountId,
            ],
            [
                'account_name'      => $accountName,
                'refresh_token'     => $token,
                'is_connected'      => true,
                'connection_status' => 'connected',
                'last_sync_at'      => now(),
            ]
        );

        createNotification(
            $workspaceId,
            'report_ready',
            'Instagram Business Account Connected',
            "Instagram Business Account '{$accountName}' was connected successfully.",
            $accountName
        );

        return response()->json([
            'success' => true,
            'message' => "Instagram Account '{$accountName}' connected successfully!",
            'data'    => [
                'id'            => $integration->id,
                'workspace_id'  => $integration->workspace_id,
                'account_id'    => $integration->account_id,
                'account_name'  => $integration->account_name,
                'followers'     => $followers,
                'last_sync_at'  => $integration->last_sync_at->toIso8601String(),
            ]
        ]);
    });

    // POST /v1/facebook/disconnect-page
    Route::post('/facebook/disconnect-page', function (Request $request) {
        $validated = $request->validate([
            'workspace_id' => 'required|integer',
            'page_id'      => 'nullable|string',
        ]);

        $query = FacebookPage::where('workspace_id', $validated['workspace_id']);
        if (!empty($validated['page_id'])) {
            $query->where('page_id', $validated['page_id']);
        }
        $query->delete();

        Integration::where('workspace_id', $validated['workspace_id'])->where('platform', 'facebook')->delete();

        return response()->json(['success' => true, 'message' => 'Facebook Page disconnected successfully.']);
    });

    // POST /v1/facebook/publish-post (Supports Text, Single Image, Multi Image, Video, Link for Facebook & YouTube)
    Route::post('/facebook/publish-post', function (Request $request) {
        $validated = $request->validate([
            'workspace_id' => 'nullable|integer',
            'post_type'    => 'nullable|in:text,single_image,multi_image,video,link',
            'message'      => 'nullable|string',
            'link_url'     => 'nullable|url',
            'page_id'      => 'nullable|string',
            'status'       => 'nullable|in:draft,scheduled,published',
            'scheduled_at' => 'nullable|date',
            'platforms'    => 'nullable',
            'images.*'     => 'nullable|file|image|max:10240',
            'video'        => 'nullable|file|mimes:mp4,mov,avi,mkv|max:51200', // 50MB
        ]);

        $workspaceId = getOrCreateWorkspaceId($validated['workspace_id'] ?? 1);
        $status = $validated['status'] ?? 'published';
        $postType = $validated['post_type'] ?? 'text';
        $message = $validated['message'] ?? '';

        $targetPlatforms = $request->input('platforms', ['Facebook']);
        if (is_string($targetPlatforms)) {
            $targetPlatforms = json_decode($targetPlatforms, true) ?? [$targetPlatforms];
        }
        if (!is_array($targetPlatforms)) {
            $targetPlatforms = ['Facebook'];
        }

        $requiresFacebook  = in_array('Facebook', $targetPlatforms);
        $requiresInstagram = in_array('Instagram', $targetPlatforms);
        $requiresYouTube   = in_array('YouTube', $targetPlatforms);
        $requiresTwitter   = in_array('Twitter', $targetPlatforms) || in_array('X / Twitter', $targetPlatforms) || in_array('X', $targetPlatforms);

        if (!$requiresFacebook && !$requiresInstagram && !$requiresYouTube && !$requiresTwitter) {
            return response()->json([
                'success' => false,
                'message' => 'Please select at least one target platform (e.g. YouTube, Facebook, Instagram, or X/Twitter).',
            ], 400);
        }

        $fbPage = null;
        if ($requiresFacebook) {
            $query = FacebookPage::where('workspace_id', $workspaceId);
            if (!empty($validated['page_id'])) {
                $query->where('page_id', $validated['page_id']);
            }
            $fbPage = $query->first() ?? FacebookPage::latest()->first();

            if (!$fbPage || empty($fbPage->page_access_token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No connected Facebook Page found for this workspace. Please connect a Facebook Page first.',
                ], 400);
            }
        }

        $igInteg = null;
        if ($requiresInstagram) {
            $igInteg = Integration::where('platform', 'instagram')
                ->where('is_connected', true)
                ->when($workspaceId, fn($q) => $q->where('workspace_id', $workspaceId))
                ->latest()->first();

            if (!$igInteg && !$fbPage) {
                return response()->json([
                    'success' => false,
                    'message' => 'No connected Instagram Business Account found for this workspace. Please connect Instagram in Integrations.',
                ], 400);
            }

            if (!$request->hasFile('images') && !$request->hasFile('video')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Instagram Graph API requires an image or video to create a post. Please attach media to publish to Instagram.',
                ], 400);
            }
        }

        $ytConn = null;
        if ($requiresYouTube) {
            $ytConn = \App\Models\YouTubeConnection::latest()->first();
            if (!$ytConn) {
                return response()->json([
                    'success' => false,
                    'message' => 'No connected YouTube Channel found for this workspace. Please connect YouTube in Integrations.',
                ], 400);
            }
        }

        $twitterConn = null;
        if ($requiresTwitter) {
            $twitterConn = \App\Models\Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'twitter')
                ->where('is_connected', true)
                ->first()
                ?? \App\Models\Integration::where('platform', 'twitter')
                ->where('is_connected', true)
                ->latest()
                ->first();
            if (!$twitterConn) {
                return response()->json([
                    'success' => false,
                    'message' => 'No connected X (Twitter) account found for this workspace. Please connect X in Integrations.',
                ], 400);
            }
        }

        // Create initial FacebookPost database record (with nullable facebook_page_id if posting to YouTube/Twitter)
        $post = FacebookPost::create([
            'workspace_id'     => $workspaceId,
            'facebook_page_id' => $fbPage ? $fbPage->id : null,
            'post_type'        => $postType,
            'content'          => $message,
            'link_url'         => $validated['link_url'] ?? null,
            'status'           => $status,
            'scheduled_at'     => !empty($validated['scheduled_at']) ? new \DateTime($validated['scheduled_at']) : null,
            'published_at'     => $status === 'published' ? now() : null,
            'created_by_id'    => $request->user()->id ?? 1,
        ]);

        // Create generic post record for multi-platform history tracking
        Post::create([
            'workspace_id'    => $workspaceId,
            'content'         => $message,
            'platform_list'   => $targetPlatforms,
            'status'          => $status,
            'scheduled_at'    => $post->scheduled_at,
            'published_at'    => $post->published_at,
            'approval_status' => 'approved',
            'created_by_id'   => $request->user()->id ?? 1,
        ]);

        if ($status !== 'published') {
            return response()->json([
                'success' => true,
                'message' => $status === 'scheduled' ? 'Post scheduled successfully!' : 'Draft saved successfully!',
                'data'    => $post,
            ], 201);
        }

        $publishedSummary = [];
        $errors = [];

        // 1. Facebook Publishing
        if ($requiresFacebook && $fbPage) {
            try {
                $graphService = new FacebookGraphService();
                if ($request->hasFile('images')) {
                    $images = $request->file('images');
                    if (count($images) === 1) {
                        $fbResult = $graphService->publishSinglePhoto($fbPage->page_id, $fbPage->page_access_token, $message, $images[0]);
                        $post->post_type = 'single_image';
                    } else {
                        $fbResult = $graphService->publishMultiplePhotos($fbPage->page_id, $fbPage->page_access_token, $message, $images);
                        $post->post_type = 'multi_image';
                    }
                } elseif ($request->hasFile('video')) {
                    $fbResult = $graphService->publishVideo($fbPage->page_id, $fbPage->page_access_token, $message, $request->file('video'));
                    $post->post_type = 'video';
                } else {
                    $fbResult = $graphService->publishTextPost($fbPage->page_id, $fbPage->page_access_token, $message, $validated['link_url'] ?? null);
                }

                $fbPostId = $fbResult['post_id'] ?? $fbResult['id'] ?? null;
                $post->fb_post_id = $fbPostId;
                $publishedSummary[] = 'Facebook Page';
            } catch (\Exception $e) {
                $errors[] = 'Facebook: ' . $e->getMessage();
            }
        }

        // 2. Instagram Publishing
        if ($requiresInstagram) {
            try {
                $graphService = new FacebookGraphService();
                $igAccountId = $igInteg ? $igInteg->account_id : ($fbPage ? $fbPage->page_id : null);
                $primaryToken = $igInteg ? $igInteg->refresh_token : ($fbPage ? $fbPage->page_access_token : null);

                if ($request->hasFile('images')) {
                    $images = $request->file('images');
                    try {
                        $graphService->publishInstagramSinglePhoto($igAccountId, $primaryToken, $message, $images[0], $workspaceId);
                    } catch (\Exception $igErr) {
                        if ($fbPage && !empty($fbPage->page_access_token) && $primaryToken !== $fbPage->page_access_token) {
                            $graphService->publishInstagramSinglePhoto($igAccountId, $fbPage->page_access_token, $message, $images[0], $workspaceId);
                        } else {
                            throw $igErr;
                        }
                    }
                } elseif ($request->hasFile('video')) {
                    $video = $request->file('video');
                    $graphService->publishInstagramVideo($igAccountId, $primaryToken, $message, $video, $workspaceId);
                }
                $publishedSummary[] = 'Instagram (@' . ($igInteg ? $igInteg->account_name : 'Account') . ')';
            } catch (\Exception $e) {
                $errors[] = 'Instagram: ' . $e->getMessage();
            }
        }

        // 3. YouTube Publishing
        if ($requiresYouTube && $ytConn) {
            try {
                if ($request->hasFile('video')) {
                    $videoFile = $request->file('video');
                    $ytService = resolve(\App\Services\YouTubeService::class);
                    $title = !empty($message) ? mb_substr($message, 0, 60) : 'Uploaded Video';
                    
                    $ytResult = $ytService->uploadVideo(
                        $ytConn,
                        $videoFile->getRealPath(),
                        $title,
                        $message,
                        'public'
                    );
                    $publishedSummary[] = "YouTube ({$ytConn->channel_name})";
                } else {
                    throw new \Exception('YouTube requires a video file to be uploaded.');
                }
            } catch (\Exception $e) {
                $errors[] = 'YouTube: ' . $e->getMessage();
            }
        }

        // 3. X/Twitter Publishing
        if ($requiresTwitter && $twitterConn) {
            try {
                $twitterService = resolve(\App\Services\TwitterService::class);
                $tweetText = $message;
                if (!empty($validated['link_url'])) {
                    $tweetText .= "\n" . $validated['link_url'];
                }
                
                $tweetResult = $twitterService->publishTweet($twitterConn, $tweetText);
                $publishedSummary[] = "X/Twitter ({$twitterConn->account_name})";
            } catch (\Exception $e) {
                $errors[] = 'X/Twitter: ' . $e->getMessage();
            }
        }

        if (count($errors) > 0 && count($publishedSummary) === 0) {
            $post->status = 'failed';
            $post->error_message = implode(' | ', $errors);
            $post->save();

            return response()->json([
                'success' => false,
                'message' => 'Publishing failed: ' . implode(' | ', $errors),
                'data'    => $post,
            ], 500);
        }

        $post->status = 'published';
        $post->published_at = now();
        $post->save();

        if ($requiresFacebook && !empty($post->fb_post_id) && $fbPage) {
            try {
                $graphService = new FacebookGraphService();
                $graphService->syncPost($post, $fbPage->page_access_token);
                $graphService->refreshPageInsights($fbPage);
            } catch (\Exception $syncErr) {
                \Illuminate\Support\Facades\Log::warning('Post sync after publish failed: ' . $syncErr->getMessage());
            }
        }

        FacebookPostHistory::create([
            'facebook_post_id' => $post->id,
            'action'           => 'published',
            'attempt_number'   => 1,
            'status_code'      => 200,
            'response_payload' => ['summary' => $publishedSummary, 'errors' => $errors],
        ]);

        // Write a real notification to DB on successful publish
        createNotification(
            $workspaceId,
            'post_published',
            'Post Published',
            'Post published to ' . implode(', ', $publishedSummary) . '.',
            implode(', ', $publishedSummary)
        );

        return response()->json([
            'success'    => true,
            'message'    => 'Post published successfully to ' . implode(', ', $publishedSummary) . '!',
            'data'       => $post,
        ]);
    });

    // POST /v1/facebook/publish-image (Backwards compatible image upload route)
    Route::post('/facebook/publish-image', function (Request $request) {
        return redirect()->to('/api/v1/facebook/publish-post');
    });

    // ── Drafts API ───────────────────────────────────────────────────────────────
    Route::get('/drafts', function (Request $request) {
        $workspaceId = $request->query('workspace_id', 1);
        $drafts = FacebookPost::where('workspace_id', $workspaceId)->where('status', 'draft')->orderBy('updated_at', 'desc')->get();
        return response()->json(['success' => true, 'data' => $drafts]);
    });

    Route::delete('/drafts/{id}', function ($id) {
        FacebookPost::where('id', $id)->where('status', 'draft')->delete();
        return response()->json(['success' => true, 'message' => 'Draft deleted']);
    });

    // ── Scheduled Posts API ──────────────────────────────────────────────────────
    Route::get('/scheduled-posts', function (Request $request) {
        $workspaceId = $request->query('workspace_id', 1);
        $scheduled = FacebookPost::where('workspace_id', $workspaceId)->where('status', 'scheduled')->orderBy('scheduled_at', 'asc')->get();
        return response()->json(['success' => true, 'data' => $scheduled]);
    });

    Route::delete('/scheduled-posts/{id}', function ($id) {
        FacebookPost::where('id', $id)->where('status', 'scheduled')->delete();
        return response()->json(['success' => true, 'message' => 'Scheduled post cancelled and removed']);
    });

    // ── Retry & Duplicate Post Actions ───────────────────────────────────────────
    Route::post('/posts/{id}/retry', function ($id) {
        $post = FacebookPost::findOrFail($id);
        $fbPage = FacebookPage::find($post->facebook_page_id) ?? FacebookPage::where('workspace_id', $post->workspace_id)->first();

        if (!$fbPage) {
            return response()->json(['success' => false, 'message' => 'No connected Facebook Page available for retry'], 400);
        }

        $graphService = new FacebookGraphService();
        try {
            $fbResult = $graphService->publishTextPost($fbPage->page_id, $fbPage->page_access_token, $post->content, $post->link_url);
            $post->status = 'published';
            $post->fb_post_id = $fbResult['id'] ?? null;
            $post->error_message = null;
            $post->published_at = now();
            $post->retry_count += 1;
            $post->save();

            FacebookPostHistory::create([
                'facebook_post_id' => $post->id,
                'action'           => 'retried',
                'attempt_number'   => $post->retry_count,
                'status_code'      => 200,
                'response_payload' => $fbResult,
            ]);

            return response()->json(['success' => true, 'message' => 'Post retried and published successfully!', 'data' => $post]);
        } catch (\Exception $e) {
            $post->retry_count += 1;
            $post->error_message = $e->getMessage();
            $post->save();

            return response()->json(['success' => false, 'message' => "Retry Failed: {$e->getMessage()}"], 500);
        }
    });

    Route::post('/posts/{id}/duplicate', function ($id) {
        $post = FacebookPost::findOrFail($id);
        $newPost = $post->replicate();
        $newPost->status = 'draft';
        $newPost->fb_post_id = null;
        $newPost->published_at = null;
        $newPost->save();

        return response()->json(['success' => true, 'message' => 'Post duplicated as a new draft!', 'data' => $newPost]);
    });

    // ── Facebook Analytics / Insights API ────────────────────────────────────────
    // ── Reports — Combined Multi-Platform Analytics ─────────────────────────
    Route::get('/reports/analytics', function (Request $request) {
        $workspaceId = $request->query('workspace_id', 1);
        $days        = (int) $request->query('period', 30);

        $graphService = new FacebookGraphService();

        // ── Facebook ──────────────────────────────────────────────────────
        $fbPage = FacebookPage::where('workspace_id', $workspaceId)->first();
        $fbConnected   = false;
        $fbFollowers   = 0;
        $fbImpressions = 0;
        $fbEngagement  = 0;
        $fbReactions   = 0;
        $fbLikes       = 0;
        $fbComments    = 0;
        $fbTrend       = ['labels' => [], 'reach' => [], 'engagement' => []];

        if ($fbPage && !empty($fbPage->page_access_token)) {
            $fbConnected = true;
            $fbFollowers = max($fbPage->followers_count ?? 0, $fbPage->fan_count ?? 0);

            try {
                $insights      = $graphService->getPageInsights($fbPage->page_id, $fbPage->page_access_token, $days);
                $fbImpressions = $insights['impressions'] ?? 0;
                $fbEngagement  = $insights['engagements'] ?? 0;
                $fbReactions   = $insights['reactions'] ?? 0;
            } catch (\Exception $e) {}

            try {
                $photoMetrics = $graphService->getPagePhotosMetrics($fbPage->page_id, $fbPage->page_access_token);
                $fbLikes    = (int)($photoMetrics['likes'] ?? 0);
                $fbComments = (int)($photoMetrics['comments'] ?? 0);
            } catch (\Exception $e) {}

            // DB fallback maximums
            $dbPosts    = FacebookPost::where('workspace_id', $workspaceId)->where('status', 'published');
            $fbLikes    = max($fbLikes, (int)(clone $dbPosts)->sum('likes_count'));
            $fbComments = max($fbComments, (int)(clone $dbPosts)->sum('comments_count'));

            try {
                $fetchedTrend = $graphService->getPageInsightsTrend($fbPage->page_id, $fbPage->page_access_token, $days);
                if (!empty($fetchedTrend['labels'])) {
                    $fbTrend = $fetchedTrend;
                }
            } catch (\Exception $e) {}
        }

        // ── Instagram ─────────────────────────────────────────────────────
        $igInteg = \App\Models\Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'instagram')
            ->where('is_connected', true)
            ->first();

        $igConnected    = false;
        $igReach        = 0;
        $igLikes        = 0;
        $igComments     = 0;
        $igInteractions = 0;
        $igViews        = 0;
        $igShares       = 0;
        $igSaved        = 0;
        $igFollowers    = 0;
        $igTrend        = ['labels' => [], 'reach' => [], 'engagement' => []];

        if ($igInteg && !empty($igInteg->refresh_token)) {
            $igConnected = true;
            $igFollowers = (int)($igInteg->meta_data['followers_count'] ?? 0);

            try {
                $igInsights     = $graphService->getInstagramAccountInsights($igInteg->refresh_token, $days);
                $igReach        = (int)($igInsights['reach'] ?? 0);
                $igLikes        = (int)($igInsights['likes'] ?? 0);
                $igComments     = (int)($igInsights['comments'] ?? 0);
                $igInteractions = (int)($igInsights['total_interactions'] ?? 0);
            } catch (\Exception $e) {}

            try {
                $igMediaItems = $graphService->getInstagramMediaList($igInteg->refresh_token, 25);
                $igViews  = (int)array_sum(array_column($igMediaItems, 'views_count'));
                $igShares = (int)array_sum(array_column($igMediaItems, 'shares_count'));
                $igSaved  = (int)array_sum(array_column($igMediaItems, 'saved_count'));
                if ($igLikes === 0) $igLikes = (int)array_sum(array_column($igMediaItems, 'like_count'));
                if ($igComments === 0) $igComments = (int)array_sum(array_column($igMediaItems, 'comments_count'));
            } catch (\Exception $e) {}

            // Instagram daily trend — try the method if it exists
            try {
                if (method_exists($graphService, 'getInstagramInsightsTrend')) {
                    $igTrendData = $graphService->getInstagramInsightsTrend($igInteg->refresh_token, $days);
                    if (!empty($igTrendData['labels'])) {
                        $igTrend = $igTrendData;
                    }
                }
            } catch (\Exception $e) {}
        }

        // ── Build combined trend labels ───────────────────────────────────
        $labels = $fbTrend['labels'];
        if (empty($labels)) {
            $labels = $igTrend['labels'] ?? [];
        }
        if (empty($labels)) {
            for ($i = $days - 1; $i >= 0; $i--) {
                $labels[] = now()->subDays($i)->format('M j');
            }
        }

        $fbReach      = $fbTrend['reach'] ?? array_fill(0, count($labels), 0);
        $fbEngage     = $fbTrend['engagement'] ?? array_fill(0, count($labels), 0);
        $igReachTrend = $igTrend['reach'] ?? array_fill(0, count($labels), 0);
        $igEngageTrend= $igTrend['engagement'] ?? array_fill(0, count($labels), 0);

        // Pad arrays to match label count
        while (count($fbReach) < count($labels)) $fbReach[] = 0;
        while (count($fbEngage) < count($labels)) $fbEngage[] = 0;
        while (count($igReachTrend) < count($labels)) $igReachTrend[] = 0;
        while (count($igEngageTrend) < count($labels)) $igEngageTrend[] = 0;

        return response()->json([
            'success' => true,
            'data'    => [
                'facebook' => [
                    'connected'   => $fbConnected,
                    'page_name'   => $fbPage->page_name ?? null,
                    'followers'   => $fbFollowers,
                    'impressions' => $fbImpressions,
                    'engagement'  => $fbEngagement,
                    'reactions'   => $fbReactions,
                    'likes'       => $fbLikes,
                    'comments'    => $fbComments,
                ],
                'instagram' => [
                    'connected'    => $igConnected,
                    'account_name' => $igInteg->account_name ?? null,
                    'followers'    => $igFollowers,
                    'reach'        => $igReach,
                    'likes'        => $igLikes,
                    'comments'     => $igComments,
                    'interactions' => $igInteractions,
                    'views'        => $igViews,
                    'shares'       => $igShares,
                    'saved'        => $igSaved,
                ],
                'trend' => [
                    'labels'         => $labels,
                    'fb_reach'       => $fbReach,
                    'fb_engagement'  => $fbEngage,
                    'ig_reach'       => $igReachTrend,
                    'ig_engagement'  => $igEngageTrend,
                ],
                'comparison' => [
                    ['metric' => 'Followers',    'facebook' => $fbFollowers,   'instagram' => $igFollowers],
                    ['metric' => 'Impressions / Reach', 'facebook' => $fbImpressions, 'instagram' => $igReach],
                    ['metric' => 'Likes',        'facebook' => $fbLikes,       'instagram' => $igLikes],
                    ['metric' => 'Comments',     'facebook' => $fbComments,    'instagram' => $igComments],
                    ['metric' => 'Engagements',  'facebook' => $fbEngagement,  'instagram' => $igInteractions],
                    ['metric' => 'Views',        'facebook' => null,           'instagram' => $igViews],
                    ['metric' => 'Shares',       'facebook' => null,           'instagram' => $igShares],
                    ['metric' => 'Saved',        'facebook' => null,           'instagram' => $igSaved],
                ],
            ],
        ]);
    });

    Route::get('/facebook/analytics', function (Request $request) {
        $workspaceId = $request->query('workspace_id', 1);
        $days        = (int) $request->query('period', 30);
        $fbPage      = FacebookPage::where('workspace_id', $workspaceId)->first();

        // No connected page — real empty state, no fake data
        if (!$fbPage) {
            return response()->json([
                'success'    => true,
                'connected'  => false,
                'data' => [
                    'followers'   => 0,
                    'impressions' => 0,
                    'engagement'  => 0,
                    'trend'       => ['labels' => [], 'reach' => [], 'engagement' => []]
                ]
            ]);
        }

        $graphService = new FacebookGraphService();

        // Fetch real insights (returns zeros on failure — no fake fallback)
        $insights = $graphService->getPageInsights($fbPage->page_id, $fbPage->page_access_token);

        // Fetch real daily trend (returns empty arrays on failure — no fake fallback)
        $trendData = ['labels' => [], 'reach' => [], 'engagement' => []];
        try {
            $fetchedTrend = $graphService->getPageInsightsTrend($fbPage->page_id, $fbPage->page_access_token, $days);
            if (!empty($fetchedTrend['labels'])) {
                $trendData = $fetchedTrend;
            }
        } catch (\Exception $e) {
            // Graph API unavailable — return real empty state
        }

        return response()->json([
            'success'   => true,
            'connected' => true,
            'data' => [
                'followers'   => $fbPage->followers_count,
                'impressions' => $insights['impressions'] ?? 0,
                'engagement'  => $insights['engagements'] ?? 0,
                'trend'       => $trendData,
            ]
        ]);
    });
});
