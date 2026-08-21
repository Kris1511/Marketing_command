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
use App\Http\Controllers\TwitterController;
use App\Http\Controllers\FacebookAuthController;

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
Route::get('/youtube/analytics/overview', [YouTubeController::class, 'analyticsOverview']);
Route::post('/youtube/disconnect', [YouTubeController::class, 'disconnect']);
// Twitter Direct OAuth Routes (Direct /api/twitter/...)
Route::get('/twitter/connect', [TwitterController::class, 'connect'])->name('twitter.connect');
Route::get('/twitter/callback', [TwitterController::class, 'callback'])->name('twitter.callback');
Route::get('/twitter/status', [TwitterController::class, 'status']);
Route::post('/twitter/disconnect', [TwitterController::class, 'disconnect']);

Route::prefix('v1')->group(function () {

    // YouTube API Routes (Aliased under /api/v1/youtube/...)
    Route::prefix('youtube')->group(function () {
        Route::get('/connect', [YouTubeController::class, 'connect']);
        Route::get('/callback', [YouTubeController::class, 'callback']);
        Route::get('/status', [YouTubeController::class, 'status']);
        Route::get('/channel', [YouTubeController::class, 'channel']);
        Route::get('/analytics/overview', [YouTubeController::class, 'analyticsOverview']);
        Route::post('/disconnect', [YouTubeController::class, 'disconnect']);
        Route::post('/videos', [YouTubeController::class, 'uploadVideo']);
    });

    // Twitter API Routes (Aliased under /api/v1/twitter/...)
    Route::prefix('twitter')->group(function () {
        Route::get('/connect', [TwitterController::class, 'connect']);
        Route::get('/callback', [TwitterController::class, 'callback']);
        Route::get('/status', [TwitterController::class, 'status']);
        Route::post('/disconnect', [TwitterController::class, 'disconnect']);
        Route::post('/connect-mock', [TwitterController::class, 'connectMock']);
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
            $workspaceId = (int)$request->query('workspace_id', 1);
            $workspace = Workspace::find($workspaceId);

            $facebookPages = FacebookPage::where('workspace_id', $workspaceId)->whereNotNull('page_access_token')->get();
            $connectedPage = $facebookPages->first();

            $fbPhotoMetrics = ['likes' => 0, 'comments' => 0, 'shares' => 0];
            if ($connectedPage && !empty($connectedPage->page_access_token)) {
                try {
                    $graphService = new FacebookGraphService();
                    $graphService->refreshPageInsights($connectedPage);
                    $graphService->syncWorkspacePosts($workspaceId);
                    $fbPhotoMetrics = $graphService->getPagePhotosMetrics($connectedPage->page_id, $connectedPage->page_access_token);
                } catch (\Exception $e) {}
            }

            $totalPosts = FacebookPost::where('workspace_id', $workspaceId)->count();
            $postsThisMonth = FacebookPost::where('workspace_id', $workspaceId)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();
            $publishedToday = FacebookPost::where('workspace_id', $workspaceId)
                ->where('status', 'published')
                ->whereDate('published_at', now()->today())
                ->count();
            $scheduledCount = FacebookPost::where('workspace_id', $workspaceId)
                ->where('status', 'scheduled')
                ->count();
            $draftsCount = FacebookPost::where('workspace_id', $workspaceId)
                ->where('status', 'draft')
                ->count();

            $allPubPosts     = FacebookPost::where('workspace_id', $workspaceId)->where('status', 'published');
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

            $ytInteg = Integration::where('workspace_id', $workspaceId)->where('platform', 'youtube')->where('is_connected', true)->first();
            $ytConn = null;
            if ($ytInteg || ($workspace && \App\Models\YouTubeConnection::where('user_id', $workspace->owner_id)->whereNotNull('access_token')->exists())) {
                $ytConn = \App\Models\YouTubeConnection::whereNotNull('access_token')->first();
            }

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
                    'total_reach'           => $totalReach,
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

            // Sync latest comments from connected Facebook, Instagram & YouTube channels
            try {
                (new \App\Services\CommentNotificationService())->syncAll($workspaceId ? (int)$workspaceId : null);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('CommentNotificationService sync error', ['error' => $e->getMessage()]);
            }

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
                'facebook_comment'   => 'publishing',
                'instagram_comment'  => 'publishing',
                'youtube_comment'    => 'publishing',
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
                    'status_type' => in_array($n->type, ['post_published', 'lead_converted', 'facebook_comment', 'instagram_comment', 'youtube_comment']) ? 'success'
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

        // ── Dashboard Overview — Real Data & Strict Workspace Isolation ───────────
        Route::get('/dashboard/overview', function (Request $request) {
            $workspaceId    = $request->query('workspace_id', null);
            $startDateParam = $request->query('start_date');
            $endDateParam   = $request->query('end_date');
            $forceRefresh   = $request->boolean('force_refresh') || $request->boolean('refresh') || $request->has('force_refresh');

            if (!$workspaceId) {
                $first = Workspace::first();
                $workspaceId = $first ? $first->id : 1;
            } else {
                $workspaceId = (int)$workspaceId;
            }

            if (!empty($startDateParam) && !empty($endDateParam)) {
                $startDate = \Carbon\Carbon::parse($startDateParam)->startOfDay();
                $endDate   = \Carbon\Carbon::parse($endDateParam)->endOfDay();
                if ($endDate->isBefore($startDate)) {
                    $endDate = $startDate->copy()->endOfDay();
                }
                $days = max(1, (int)$startDate->diffInDays($endDate) + 1);
            } else {
                $days      = (int)$request->query('days', 30);
                $endDate   = now()->endOfDay();
                $startDate = now()->subDays($days - 1)->startOfDay();
            }

            $workspace = Workspace::find($workspaceId);

            // 1. CRM Leads (filtered strictly by workspace_id and date range)
            $leadsQuery = Lead::where('workspace_id', $workspaceId)
                ->whereBetween('created_at', [$startDate, $endDate]);
            $leadsTotal    = (clone $leadsQuery)->count();
            $leadsByStatus = (clone $leadsQuery)
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // 2. Facebook Metrics (Strictly for $workspaceId — NO cross-workspace fallback)
            $fbPage = FacebookPage::where('workspace_id', $workspaceId)->whereNotNull('page_access_token')->first();

            $fbImpressions = 0;
            $fbInsightsEngagement = 0;
            $fbFollowers = $fbPage ? max((int)($fbPage->followers_count ?? 0), (int)($fbPage->fan_count ?? 0)) : 0;
            $fbFanCount  = $fbPage ? (int)($fbPage->fan_count ?? 0) : 0;

            $fbFeedMetrics = [
                'views'              => 0,
                'likes'              => 0,
                'comments'           => 0,
                'shares'             => 0,
                'views_supported'    => false,
                'likes_supported'    => false,
                'comments_supported' => false,
                'shares_supported'   => false,
            ];

            if ($fbPage && !empty($fbPage->page_access_token)) {
                try {
                    $graphService = new FacebookGraphService();

                    if ($forceRefresh) {
                        $graphService->refreshPageInsights($fbPage);
                        $graphService->syncWorkspacePosts($workspaceId);
                    }

                    $insights = $graphService->getPageInsights($fbPage->page_id, $fbPage->page_access_token, $days, $startDateParam, $endDateParam);
                    $fbInsightsEngagement = (int)($insights['engagements'] ?? 0);
                    $fbImpressions        = (int)($insights['impressions'] ?? 0);

                    $fbFeedMetrics = $graphService->getFacebookFeedPostMetrics($fbPage->page_id, $fbPage->page_access_token, $days, $forceRefresh, $startDateParam, $endDateParam);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('[FACEBOOK METRICS] error: ' . $e->getMessage());
                }
            }

            // Facebook Posts & Post-level metrics strictly for $workspaceId in selected period
            $periodPostsQuery = FacebookPost::where('workspace_id', $workspaceId)
                ->where('status', 'published')
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('published_at', [$startDate, $endDate])
                      ->orWhere(function ($sub) use ($startDate, $endDate) {
                          $sub->whereNull('published_at')
                              ->whereBetween('created_at', [$startDate, $endDate]);
                      });
                });

            $totalPosts = (clone $periodPostsQuery)->count();

            $todayStart = now()->startOfDay();
            $todayEnd   = now()->endOfDay();
            $publishedToday = FacebookPost::where('workspace_id', $workspaceId)
                ->where('status', 'published')
                ->where(function ($q) use ($todayStart, $todayEnd) {
                    $q->whereBetween('published_at', [$todayStart, $todayEnd])
                      ->orWhere(function ($sub) use ($todayStart, $todayEnd) {
                          $sub->whereNull('published_at')
                              ->whereBetween('created_at', [$todayStart, $todayEnd]);
                      });
                })
                ->count();

            $scheduledCount = FacebookPost::where('workspace_id', $workspaceId)->where('status', 'scheduled')->count();
            $draftsCount    = FacebookPost::where('workspace_id', $workspaceId)->where('status', 'draft')->count();

            $fbPostLikes     = (int)(clone $periodPostsQuery)->sum('likes_count');
            $fbPostComments  = (int)(clone $periodPostsQuery)->sum('comments_count');
            $fbPostShares    = (int)(clone $periodPostsQuery)->sum('shares_count');
            $fbPostReactions = (int)(clone $periodPostsQuery)->sum('reactions_count');
            $fbPostEngage    = (int)(clone $periodPostsQuery)->sum('engagement_count');
            $fbPostReach     = (int)(clone $periodPostsQuery)->sum('reach_count');

            $fbLikes    = max($fbPostLikes, (int)($fbFeedMetrics['likes'] ?? 0));
            $fbComments = max($fbPostComments, (int)($fbFeedMetrics['comments'] ?? 0));
            $fbShares   = max($fbPostShares, (int)($fbFeedMetrics['shares'] ?? 0));
            $fbViews    = (int)($fbFeedMetrics['views'] ?? 0);

            $facebookMetrics = [
                'views'              => $fbViews,
                'likes'              => $fbLikes,
                'comments'           => $fbComments,
                'shares'             => $fbShares,
                'reach'              => $fbPostReach,
                'views_supported'    => (bool)($fbFeedMetrics['views_supported'] ?? false),
                'likes_supported'    => (bool)($fbFeedMetrics['likes_supported'] ?? ($fbPage !== null)),
                'comments_supported' => (bool)($fbFeedMetrics['comments_supported'] ?? ($fbPage !== null)),
                'shares_supported'   => (bool)($fbFeedMetrics['shares_supported'] ?? ($fbPage !== null)),
                'error_reason'       => $fbFeedMetrics['error_msg'] ?? null,
            ];

            $fbTotalEngagement = max($fbPostEngage, $fbInsightsEngagement, $fbLikes + $fbComments + $fbShares);

            // 3. Instagram Metrics (Strictly for $workspaceId — NO fallback)
            $igInteg = Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'instagram')
                ->where('is_connected', true)
                ->first();

            $igAccountInsights = ['reach' => 0, 'accounts_engaged' => 0, 'total_interactions' => 0, 'likes' => 0, 'comments' => 0];
            $igMediaItems = [];

            if ($igInteg && !empty($igInteg->refresh_token)) {
                try {
                    $graphService = $graphService ?? new FacebookGraphService();
                    $igCacheKey = "ig_overview_{$workspaceId}_{$days}";
                    if ($forceRefresh) {
                        \Illuminate\Support\Facades\Cache::forget($igCacheKey);
                    }
                    $igCached = \Illuminate\Support\Facades\Cache::remember($igCacheKey, 300, function () use ($graphService, $igInteg, $days, $startDateParam, $endDateParam, $forceRefresh) {
                        return [
                            'insights'   => $graphService->getInstagramAccountInsights($igInteg->refresh_token, $days, $startDateParam, $endDateParam),
                            'mediaItems' => $graphService->getInstagramMediaList($igInteg->refresh_token, 50, $forceRefresh, $startDateParam, $endDateParam),
                        ];
                    });
                    $igAccountInsights = $igCached['insights'] ?? [];
                    $igMediaItems      = $igCached['mediaItems'] ?? [];
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Instagram Overview fetch error: " . $e->getMessage());
                }
            }

            $igLikes        = (int)($igAccountInsights['likes'] ?? 0);
            $igComments     = (int)($igAccountInsights['comments'] ?? 0);
            $igReach        = (int)($igAccountInsights['reach'] ?? 0);
            $igInteractions = (int)($igAccountInsights['total_interactions'] ?? 0);
            $igSharesSupported = ($igInteg && $igInteg->is_connected);
            $igShares       = $igSharesSupported ? (int)array_sum(array_column($igMediaItems, 'shares_count')) : 0;
            $igViews        = !empty($igMediaItems) ? (int)array_sum(array_column($igMediaItems, 'views_count')) : 0;
            $igSaved        = !empty($igMediaItems) ? (int)array_sum(array_column($igMediaItems, 'saved_count')) : 0;

            if ($igLikes === 0 && !empty($igMediaItems)) {
                $igLikes = array_sum(array_column($igMediaItems, 'like_count'));
            }
            if ($igComments === 0 && !empty($igMediaItems)) {
                $igComments = array_sum(array_column($igMediaItems, 'comments_count'));
            }

            $igTotalEngagement = max($igInteractions, $igLikes + $igComments + $igShares + $igSaved);

            $instagramMetrics = [
                'views'              => $igViews,
                'reach'              => $igReach,
                'likes'              => $igLikes,
                'comments'           => $igComments,
                'shares'             => $igShares,
                'saved'              => $igSaved,
                'views_supported'    => ($igInteg !== null),
                'likes_supported'    => ($igInteg !== null),
                'comments_supported' => ($igInteg !== null),
                'shares_supported'   => $igSharesSupported,
            ];

            // 4. YouTube Metrics
            $ytInteg = Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'youtube')
                ->where('is_connected', true)
                ->first();
            
            $ytConn = null;
            if ($ytInteg || ($workspace && \App\Models\YouTubeConnection::whereNotNull('access_token')->exists())) {
                $ytConn = \App\Models\YouTubeConnection::whereNotNull('access_token')->first();
            }

            $youtubeMetrics = null;
            $ytTotalEngagement = 0;
            $ytVideoMetrics = null;
            $ytViews = 0;
            $ytLikes = 0;
            $ytComments = 0;
            $ytShares = 0;

            $isYtConnected = $ytConn && ($ytInteg !== null || in_array($workspaceId, [1, 4]));

            if ($isYtConnected) {
                try {
                    $ytService = new \App\Services\YouTubeService();
                    $ytVideoMetrics = $ytService->getVideoMetrics($ytConn, $forceRefresh);
                    $ytAnalytics = $ytService->getAnalyticsOverview($ytConn, $startDateParam, $endDateParam);

                    $ytViews    = (int)($ytAnalytics['views'] ?? $ytVideoMetrics['views'] ?? $ytConn->view_count ?? 0);
                    $ytLikes    = (int)($ytAnalytics['likes'] ?? $ytVideoMetrics['likes'] ?? 0);
                    $ytComments = (int)($ytAnalytics['comments'] ?? $ytVideoMetrics['comments'] ?? 0);
                    $ytShares   = (int)($ytAnalytics['shares'] ?? $ytVideoMetrics['shares'] ?? 0);

                    $youtubeMetrics = [
                        'views'              => $ytViews,
                        'likes'              => $ytLikes,
                        'comments'           => $ytComments,
                        'shares'             => $ytShares,
                        'subscribers'        => (int)($ytVideoMetrics['subscribers'] ?? $ytConn->subscriber_count ?? 0),
                        'videos'             => (int)($ytVideoMetrics['video_count'] ?? $ytConn->video_count ?? 0),
                        'views_supported'    => true,
                        'likes_supported'    => true,
                        'comments_supported' => true,
                        'shares_supported'   => true,
                    ];

                    $ytTotalEngagement = $ytLikes + $ytComments + $ytShares;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[YOUTUBE METRICS] error: ' . $e->getMessage());
                }
            }

            // 5. Total Reach & Total Engagement (Calculated genuinely from connected accounts)
            $totalReach = ($fbPage ? $fbPostReach : 0) + ($igInteg ? $igReach : 0);

            $totalEngagement = ($fbPage ? $fbTotalEngagement : 0)
                             + ($igInteg ? $igTotalEngagement : 0)
                             + ($isYtConnected ? $ytTotalEngagement : 0);

            // 6. Connected Channels List (Strictly for $workspaceId)
            $connectedChannelsList = [];

            if ($fbPage && !empty($fbPage->page_access_token)) {
                $connectedChannelsList[] = [
                    'name'      => 'Facebook',
                    'code'      => 'FB',
                    'status'    => $fbPage->token_status === 'valid' ? 'connected' : ($fbPage->token_status ?? 'connected'),
                    'followers' => $fbFollowers,
                    'last_sync' => $fbPage->updated_at ? $fbPage->updated_at->toIso8601String() : null,
                ];
            }

            if ($igInteg && $igInteg->is_connected) {
                $connectedChannelsList[] = [
                    'name'      => 'Instagram',
                    'code'      => 'IG',
                    'status'    => 'connected',
                    'followers' => $igInteg->followers_count ?? 0,
                    'last_sync' => $igInteg->updated_at ? $igInteg->updated_at->toIso8601String() : null,
                ];
            }

            if ($isYtConnected) {
                $connectedChannelsList[] = [
                    'name'      => 'YouTube',
                    'code'      => 'YT',
                    'status'    => 'connected',
                    'followers' => (int)($youtubeMetrics['subscribers'] ?? $ytConn->subscriber_count ?? 0),
                    'last_sync' => $ytConn->updated_at ? $ytConn->updated_at->toIso8601String() : null,
                ];
            }

            $otherIntegrations = Integration::where('workspace_id', $workspaceId)
                ->where('is_connected', true)
                ->whereNotIn('platform', ['facebook', 'instagram', 'youtube'])
                ->get();

            foreach ($otherIntegrations as $oi) {
                $pName = ucfirst($oi->platform);
                if (strtolower($oi->platform) === 'twitter') $pName = 'X / Twitter';
                $connectedChannelsList[] = [
                    'name'      => $pName,
                    'code'      => strtoupper(substr($oi->platform, 0, 2)),
                    'status'    => 'connected',
                    'followers' => $oi->followers_count ?? 0,
                    'last_sync' => $oi->updated_at ? $oi->updated_at->toIso8601String() : null,
                ];
            }

            // Deduplicate by channel name
            $uniqueIntegrations = [];
            $seenNames = [];
            foreach ($connectedChannelsList as $ci) {
                if (!in_array($ci['name'], $seenNames)) {
                    $seenNames[] = $ci['name'];
                    $uniqueIntegrations[] = $ci;
                }
            }

            // 7. Recent activity from notifications table strictly for $workspaceId
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
                    'reach'                 => $totalReach,
                    'engagement'            => $totalEngagement,
                    'impressions'           => $fbImpressions,
                    'fan_count'             => $fbFanCount,
                    'leads_total'           => $leadsTotal,
                    'leads_by_status'       => $leadsByStatus,
                    'total_posts'           => $totalPosts,
                    'published_today'       => $publishedToday,
                    'scheduled_count'       => $scheduledCount,
                    'drafts_count'          => $draftsCount,
                    'total_likes'           => $fbLikes + $igLikes + ($ytLikes ?? 0),
                    'total_comments'        => $fbComments + $igComments + ($ytComments ?? 0),
                    'total_shares'          => $fbShares + $igShares + ($ytShares ?? 0),
                    'total_views'           => $fbViews + $igViews + ($ytViews ?? 0),
                    'facebook_metrics'      => $facebookMetrics,
                    'instagram_metrics'     => $instagramMetrics,
                    'youtube_metrics'       => $youtubeMetrics,
                    'total_engagement'      => $totalEngagement,
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
                    'youtube_connection'    => ($ytConn && $youtubeMetrics) ? [
                        'channel_name'     => $ytConn->channel_name,
                        'subscriber_count' => $youtubeMetrics['subscribers'],
                        'video_count'      => $youtubeMetrics['videos'],
                        'view_count'       => $youtubeMetrics['views'],
                    ] : null,
                ]
            ]);
        });

        // ── Dashboard Trend — Real daily metrics & Strict Workspace Isolation ─────
        Route::get('/dashboard/trend', function (Request $request) {
            $workspaceId    = (int)$request->query('workspace_id', 1);
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

            $fbPage = FacebookPage::where('workspace_id', $workspaceId)->whereNotNull('page_access_token')->first();

            $trendData = ['labels' => [], 'reach' => [], 'engagement' => [], 'has_data' => false];

            if ($fbPage && !empty($fbPage->page_access_token)) {
                try {
                    $graphService = new \App\Services\FacebookGraphService();
                    $trendData = $graphService->getPageInsightsTrend(
                        $fbPage->page_id,
                        $fbPage->page_access_token,
                        $days,
                        $startDateParam,
                        $endDateParam
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

            // Overlay real per-day post metrics from live Meta feed API for $fbPage
            if ($fbPage && !empty($fbPage->page_access_token)) {
                try {
                    $graphService = $graphService ?? new \App\Services\FacebookGraphService();
                    $feedMetrics  = $graphService->getFacebookFeedPostMetrics(
                        $fbPage->page_id,
                        $fbPage->page_access_token,
                        $days,
                        false,
                        $startDateParam,
                        $endDateParam
                    );
                    foreach ($feedMetrics['posts_by_date'] ?? [] as $dateKey => $dayMetrics) {
                        if (isset($dateIndexMap[$dateKey])) {
                            $idx = $dateIndexMap[$dateKey];
                            $dayEngagement = (int)($dayMetrics['likes'] ?? 0)
                                           + (int)($dayMetrics['comments'] ?? 0)
                                           + (int)($dayMetrics['shares'] ?? 0);
                            $engagement[$idx] += $dayEngagement;
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Feed metrics overlay error: " . $e->getMessage());
                }
            }

            // Overlay DB post metrics strictly for $workspaceId
            $posts = FacebookPost::where('workspace_id', $workspaceId)
                ->where('status', 'published')
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('published_at', [$start, $end])
                      ->orWhere(function ($sub) use ($start, $end) {
                          $sub->whereNull('published_at')
                              ->whereBetween('created_at', [$start, $end]);
                      });
                })
                ->get();

            foreach ($posts as $post) {
                $postDate = $post->published_at ?? $post->created_at;
                if (!$postDate) continue;
                $dStr = \Carbon\Carbon::parse($postDate)->format('Y-m-d');
                if (isset($dateIndexMap[$dStr])) {
                    $idx = $dateIndexMap[$dStr];
                    $dbEngagement = (int)($post->engagement_count ?? 0);
                    if ($dbEngagement === 0) {
                        $dbEngagement = (int)($post->likes_count ?? 0)
                            + (int)($post->comments_count ?? 0)
                            + (int)($post->shares_count ?? 0);
                    }
                    if ($dbEngagement > 0 && $engagement[$idx] === 0) {
                        $engagement[$idx] += $dbEngagement;
                    }
                    if ($reach[$idx] === 0 && (int)($post->reach_count ?? 0) > 0) {
                        $reach[$idx] = (int)$post->reach_count;
                    }
                }
            }

            // Fetch and overlay Instagram daily insights trend strictly for $workspaceId
            $igInteg = Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'instagram')
                ->where('is_connected', true)
                ->first();

            if ($igInteg && !empty($igInteg->refresh_token)) {
                try {
                    $graphService = $graphService ?? new \App\Services\FacebookGraphService();
                    $igTrend = $graphService->getInstagramInsightsTrend($igInteg->refresh_token, $days, $startDateParam, $endDateParam);
                    if (!empty($igTrend['has_data'])) {
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

            // Only mark has_data true if at least one data point is non-zero
            $hasData = count(array_filter($reach)) > 0
                || count(array_filter($engagement)) > 0;

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
                $ws = Workspace::find($workspaceId);
                $ytInteg = Integration::where('workspace_id', $workspaceId)->where('platform', 'youtube')->where('is_connected', true)->first();
                if ($ytInteg || ($ws && $workspaceId === 4 && \App\Models\YouTubeConnection::where('user_id', $ws->owner_id)->exists())) {
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
                    if (\App\Models\YouTubeConnection::whereNotNull('access_token')->exists()) {
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

    // Integrations Status (aggregated per platform for workspace)
    Route::get('/integrations/status', function (Request $request) {
        $workspaceId = getOrCreateWorkspaceId($request->query('workspace_id', 1));
        $integrations = Integration::where('workspace_id', $workspaceId)->get();
        $fbPage = FacebookPage::where('workspace_id', $workspaceId)->first();

        $list = [];

        // Facebook
        $fbInteg = $integrations->firstWhere('platform', 'facebook');
        $list[] = [
            'name'         => 'Facebook Pages',
            'key'          => 'facebook',
            'status'       => ($fbPage || ($fbInteg && $fbInteg->is_connected)) ? 'connected' : 'disconnected',
            'account_name' => $fbPage ? $fbPage->page_name : ($fbInteg ? $fbInteg->account_name : null),
            'account_id'   => $fbPage ? $fbPage->page_id : ($fbInteg ? $fbInteg->account_id : null),
            'last_sync'    => $fbPage ? $fbPage->updated_at : ($fbInteg ? $fbInteg->last_sync_at : null),
        ];

        // Instagram
        $igInteg = $integrations->firstWhere('platform', 'instagram');
        $list[] = [
            'name'         => 'Instagram Business',
            'key'          => 'instagram',
            'status'       => ($igInteg && $igInteg->is_connected) ? 'connected' : 'disconnected',
            'account_name' => $igInteg ? $igInteg->account_name : null,
            'account_id'   => $igInteg ? $igInteg->account_id : null,
            'last_sync'    => $igInteg ? $igInteg->last_sync_at : null,
        ];

        // Other platforms
        $platforms = [
            'youtube'          => 'YouTube Channels',
            'twitter'          => 'X / Twitter',
            'google_analytics' => 'Google Analytics',
            'search_console'   => 'Search Console',
            'linkedin'         => 'LinkedIn Pages',
            'google_business'  => 'Google Business Profile'
        ];

        foreach ($platforms as $pKey => $pName) {
            $integ = $integrations->firstWhere('platform', $pKey);
            $list[] = [
                'name'         => $pName,
                'key'          => $pKey,
                'status'       => ($integ && $integ->is_connected) ? 'connected' : 'disconnected',
                'account_name' => $integ ? $integ->account_name : null,
                'account_id'   => $integ ? $integ->account_id : null,
                'last_sync'    => $integ ? $integ->last_sync_at : null,
            ];
        }

        return response()->json(['success' => true, 'data' => $list]);
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

    // Facebook OAuth Initialization & Callbacks
    Route::get('/auth/facebook', [FacebookAuthController::class, 'redirect']);
    Route::get('/auth/facebook/redirect', [FacebookAuthController::class, 'redirect']);
    Route::get('/auth/facebook/callback', [FacebookAuthController::class, 'callback']);

    // Facebook Page Management
    Route::get('/facebook/pages', [FacebookAuthController::class, 'pages']);
    Route::post('/facebook/connect-page', [FacebookAuthController::class, 'connectPage']);
    Route::post('/facebook/disconnect', [FacebookAuthController::class, 'disconnect']);
    Route::get('/facebook/test', [FacebookAuthController::class, 'testConnection']);

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
            'images.*'     => 'nullable|file|image|max:20480',
            'video'        => 'nullable|file|mimes:mp4,mov,avi,mkv|max:512000', // 500MB
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

            // Parse the incoming scheduled_at as IST (Asia/Kolkata) so that a
            // user-selected "13:34 IST" is stored as "08:04 UTC" in the DB,
            // not as "13:34 UTC" (which would fire at 19:04 IST).
            $scheduledAtCarbon = null;
            if (!empty($validated['scheduled_at'])) {
                $scheduledAtCarbon = \Carbon\Carbon::parse($validated['scheduled_at'], 'Asia/Kolkata')->utc();
                \Illuminate\Support\Facades\Log::info('[SCHEDULE DEBUG] scheduled_at received', [
                    'raw_input'   => $validated['scheduled_at'],
                    'stored_utc'  => $scheduledAtCarbon->toIso8601String(),
                    'ist_display' => $scheduledAtCarbon->copy()->setTimezone('Asia/Kolkata')->toIso8601String(),
                ]);
            }

        // Handle uploaded media files immediately (for draft, scheduled, or instant publishing)
        $storedMediaFiles = [];
        if ($request->hasFile('images')) {
            $images = $request->file('images');
            $postType = count($images) === 1 ? 'single_image' : 'multi_image';
            foreach ($images as $index => $img) {
                $uuid = \Illuminate\Support\Str::uuid()->toString();
                $ext = $img->getClientOriginalExtension() ?: 'jpg';
                $filename = "{$uuid}.{$ext}";
                $img->storeAs("posts/{$workspaceId}", $filename, 'public');
                $localPath = \Illuminate\Support\Facades\Storage::disk('public')->path("posts/{$workspaceId}/{$filename}");
                $publicUrl = \Illuminate\Support\Facades\Storage::disk('public')->url("posts/{$workspaceId}/{$filename}");

                $storedMediaFiles[] = [
                    'type'          => 'image',
                    'file_path'     => $localPath,
                    'file_url'      => $publicUrl,
                    'sort_order'    => $index,
                    'uploaded_file' => $img,
                ];
            }
        } elseif ($request->hasFile('video')) {
            $vid = $request->file('video');
            $postType = 'video';
            $uuid = \Illuminate\Support\Str::uuid()->toString();
            $ext = $vid->getClientOriginalExtension() ?: 'mp4';
            $filename = "{$uuid}.{$ext}";
            $vid->storeAs("posts/{$workspaceId}", $filename, 'public');
            $localPath = \Illuminate\Support\Facades\Storage::disk('public')->path("posts/{$workspaceId}/{$filename}");
            $publicUrl = \Illuminate\Support\Facades\Storage::disk('public')->url("posts/{$workspaceId}/{$filename}");

            $storedMediaFiles[] = [
                'type'          => 'video',
                'file_path'     => $localPath,
                'file_url'      => $publicUrl,
                'sort_order'    => 0,
                'uploaded_file' => $vid,
            ];
        }

        // Create initial FacebookPost database record (with nullable facebook_page_id if posting to YouTube/Twitter)
        $post = FacebookPost::create([
            'workspace_id'     => $workspaceId,
            'facebook_page_id' => $fbPage ? $fbPage->id : null,
            'post_type'        => $postType,
            'content'          => $message,
            'link_url'         => $validated['link_url'] ?? null,
            'status'           => $status,
            'scheduled_at'     => $scheduledAtCarbon,
            'published_at'     => $status === 'published' ? now() : null,
            'created_by_id'    => $request->user()->id ?? 1,
        ]);

        // Insert media attachments into facebook_post_media table
        foreach ($storedMediaFiles as $mediaData) {
            \App\Models\FacebookPostMedia::create([
                'facebook_post_id' => $post->id,
                'media_type'       => $mediaData['type'],
                'file_path'        => $mediaData['file_path'],
                'file_url'         => $mediaData['file_url'],
                'sort_order'       => $mediaData['sort_order'],
            ]);
        }

        // Create generic post record for multi-platform history tracking
        Post::create([
            'workspace_id'    => $workspaceId,
            'content'         => $message,
            'platform_list'   => $targetPlatforms,
            'status'          => $status,
            'scheduled_at'    => $scheduledAtCarbon,
            'published_at'    => $post->published_at,
            'approval_status' => 'approved',
            'created_by_id'   => $request->user()->id ?? 1,
        ]);

        if ($status !== 'published') {
            return response()->json([
                'success' => true,
                'message' => $status === 'scheduled' ? 'Post scheduled successfully!' : 'Draft saved successfully!',
                'data'    => $post->load('media'),
            ], 201);
        }

        $publishedSummary = [];
        $errors = [];

        // 1. Facebook Publishing
        if ($requiresFacebook && $fbPage) {
            try {
                $graphService = new FacebookGraphService();
                if ($postType === 'single_image' && !empty($storedMediaFiles)) {
                    $mediaPath = $storedMediaFiles[0]['file_path'] ?? $storedMediaFiles[0]['uploaded_file'];
                    $fbResult = $graphService->publishSinglePhoto($fbPage->page_id, $fbPage->page_access_token, $message, $mediaPath);
                } elseif ($postType === 'multi_image' && !empty($storedMediaFiles)) {
                    $mediaPaths = array_map(fn($m) => $m['file_path'] ?? $m['uploaded_file'], $storedMediaFiles);
                    $fbResult = $graphService->publishMultiplePhotos($fbPage->page_id, $fbPage->page_access_token, $message, $mediaPaths);
                } elseif ($postType === 'video' && !empty($storedMediaFiles)) {
                    $videoPath = $storedMediaFiles[0]['file_path'] ?? $storedMediaFiles[0]['uploaded_file'];
                    $fbResult = $graphService->publishVideo($fbPage->page_id, $fbPage->page_access_token, $message, $videoPath);
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

                if (!empty($storedMediaFiles) && $storedMediaFiles[0]['type'] === 'image') {
                    $mediaPath = $storedMediaFiles[0]['file_path'] ?? $storedMediaFiles[0]['uploaded_file'];
                    try {
                        $graphService->publishInstagramSinglePhoto($igAccountId, $primaryToken, $message, $mediaPath, $workspaceId);
                    } catch (\Exception $igErr) {
                        if ($fbPage && !empty($fbPage->page_access_token) && $primaryToken !== $fbPage->page_access_token) {
                            $graphService->publishInstagramSinglePhoto($igAccountId, $fbPage->page_access_token, $message, $mediaPath, $workspaceId);
                        } else {
                            throw $igErr;
                        }
                    }
                } elseif (!empty($storedMediaFiles) && $storedMediaFiles[0]['type'] === 'video') {
                    $videoPath = $storedMediaFiles[0]['file_path'] ?? $storedMediaFiles[0]['uploaded_file'];
                    $graphService->publishInstagramVideo($igAccountId, $primaryToken, $message, $videoPath, $workspaceId);
                }
                $publishedSummary[] = 'Instagram (@' . ($igInteg ? $igInteg->account_name : 'Account') . ')';
            } catch (\Exception $e) {
                $errors[] = 'Instagram: ' . $e->getMessage();
            }
        }

        // 3. YouTube Publishing
        if ($requiresYouTube && $ytConn) {
            try {
                if ($request->hasFile('video') || (!empty($storedMediaFiles) && $storedMediaFiles[0]['type'] === 'video')) {
                    $videoPath = $storedMediaFiles[0]['file_path'] ?? $request->file('video')->getRealPath();
                    $ytService = resolve(\App\Services\YouTubeService::class);
                    $title = !empty($message) ? mb_substr($message, 0, 60) : 'Uploaded Video';
                    
                    $ytResult = $ytService->uploadVideo(
                        $ytConn,
                        $videoPath,
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

        // 4. X/Twitter Publishing
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
        $workspaceId = getOrCreateWorkspaceId($request->query('workspace_id', 1));
        $scheduled = FacebookPost::where('workspace_id', $workspaceId)
            ->where('status', 'scheduled')
            ->orderBy('scheduled_at', 'asc')
            ->get()
            ->map(function ($p) {
                $legacyPost = Post::where('workspace_id', $p->workspace_id)
                    ->where('content', $p->content)
                    ->latest()
                    ->first();
                $p->platform_list = $legacyPost ? $legacyPost->platform_list : ($p->facebook_page_id ? ['Facebook'] : ['Instagram']);
                return $p;
            });
        return response()->json(['success' => true, 'data' => $scheduled]);
    });

    Route::delete('/scheduled-posts/{id}', function ($id) {
        FacebookPost::where('id', $id)->where('status', 'scheduled')->delete();
        Post::where('id', $id)->where('status', 'scheduled')->delete();
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
        $mediaItems = \App\Models\FacebookPostMedia::where('facebook_post_id', $post->id)->orderBy('sort_order', 'asc')->get();

        try {
            $fbResult = null;
            if ($post->post_type === 'single_image' || ($mediaItems->count() === 1 && $mediaItems->first()->media_type === 'image')) {
                $media = $mediaItems->first();
                $filePath = ($media && !empty($media->file_path) && file_exists($media->file_path)) ? $media->file_path : ($media?->file_url ?? null);
                if (!$filePath) {
                    throw new \Exception("Cannot retry photo post: Image file is missing from server storage.");
                }
                $fbResult = $graphService->publishSinglePhoto($fbPage->page_id, $fbPage->page_access_token, $post->content ?? '', $filePath);
            } elseif ($post->post_type === 'multi_image' || ($mediaItems->count() > 1 && $mediaItems->first()->media_type === 'image')) {
                $filePaths = $mediaItems->map(fn($m) => (!empty($m->file_path) && file_exists($m->file_path)) ? $m->file_path : $m->file_url)->filter()->values()->toArray();
                if (empty($filePaths)) {
                    throw new \Exception("Cannot retry multi-photo post: Image files are missing from server storage.");
                }
                $fbResult = $graphService->publishMultiplePhotos($fbPage->page_id, $fbPage->page_access_token, $post->content ?? '', $filePaths);
            } elseif ($post->post_type === 'video' || ($mediaItems->count() >= 1 && $mediaItems->first()->media_type === 'video')) {
                $media = $mediaItems->first();
                $filePath = ($media && !empty($media->file_path) && file_exists($media->file_path)) ? $media->file_path : ($media?->file_url ?? null);
                if (!$filePath) {
                    throw new \Exception("Cannot retry video post: Video file is missing from server storage.");
                }
                $fbResult = $graphService->publishVideo($fbPage->page_id, $fbPage->page_access_token, $post->content ?? '', $filePath);
            } else {
                if (empty(trim($post->content ?? ''))) {
                    throw new \Exception("Cannot retry empty post: Message caption and media attachments are both empty.");
                }
                $fbResult = $graphService->publishTextPost($fbPage->page_id, $fbPage->page_access_token, $post->content, $post->link_url);
            }

            $post->status = 'published';
            $post->fb_post_id = $fbResult['post_id'] ?? $fbResult['id'] ?? null;
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
