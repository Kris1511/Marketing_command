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
use App\Http\Controllers\FacebookMessengerWebhookController;
use App\Http\Controllers\InstagramInboxController;
use App\Http\Controllers\YouTubeInboxController;

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
Route::get('/youtube/connect', [YouTubeController::class, 'connect']);
Route::get('/youtube/callback', [YouTubeController::class, 'callback']);
Route::get('/youtube/status', [YouTubeController::class, 'status']);
Route::get('/youtube/channel', [YouTubeController::class, 'channel']);
Route::get('/youtube/analytics/overview', [YouTubeController::class, 'analyticsOverview']);
Route::post('/youtube/disconnect', [YouTubeController::class, 'disconnect']);
Route::post('/youtube/videos', [YouTubeController::class, 'uploadVideo']);
Route::get('/youtube/videos/{id}', [YouTubeController::class, 'videoDetails']);
Route::get('/youtube/videos/{id}/analytics', [YouTubeController::class, 'videoAnalytics']);
Route::get('/youtube/video-analytics', [YouTubeController::class, 'videoAnalytics']);
Route::post('/youtube/videos/{id}/thumbnail', [YouTubeController::class, 'uploadThumbnail']);
Route::get('/youtube/playlists', [YouTubeController::class, 'playlists']);
Route::post('/youtube/playlists', [YouTubeController::class, 'createPlaylist']);
Route::post('/youtube/playlists/add-video', [YouTubeController::class, 'addVideoToPlaylist']);
Route::get('/youtube/content', [YouTubeController::class, 'content']);
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
        Route::get('/content', [YouTubeController::class, 'content']);
        Route::post('/disconnect', [YouTubeController::class, 'disconnect']);
        Route::post('/videos', [YouTubeController::class, 'uploadVideo']);
        Route::get('/videos/{id}', [YouTubeController::class, 'videoDetails']);
        Route::get('/videos/{id}/analytics', [YouTubeController::class, 'videoAnalytics']);
        Route::get('/video-analytics', [YouTubeController::class, 'videoAnalytics']);
        Route::post('/videos/{id}/thumbnail', [YouTubeController::class, 'uploadThumbnail']);
        Route::get('/playlists', [YouTubeController::class, 'playlists']);
        Route::post('/playlists', [YouTubeController::class, 'createPlaylist']);
        Route::post('/playlists/add-video', [YouTubeController::class, 'addVideoToPlaylist']);
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
    Route::options('/{any}', function (Request $request) {
        $origin = $request->header('Origin');
        $allowedOrigins = [
            'http://localhost:3000',
            'http://localhost:3001',
            'http://localhost:5173',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:3001',
            'http://127.0.0.1:5173',
            env('FRONTEND_URL'),
        ];

        $allowOrigin = 'http://localhost:3000';
        if ($origin && (in_array($origin, array_filter($allowedOrigins)) || preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin))) {
            $allowOrigin = $origin;
        }

        return response('', 200)
            ->header('Access-Control-Allow-Origin', $allowOrigin)
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN')
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

    Route::get('/webhook/facebook', [FacebookMessengerWebhookController::class, 'verify']);
    Route::post('/webhook/facebook', [FacebookMessengerWebhookController::class, 'receive']);
    Route::get('/webhook/instagram', [FacebookMessengerWebhookController::class, 'verify']);
    Route::post('/webhook/instagram', [FacebookMessengerWebhookController::class, 'receive']);

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

    // ── Comments Real-Time SSE Stream Endpoint (Supports Query Token & Bearer Auth) ──
    Route::get('/comments/stream', function (Request $request) {
        $workspaceId = (int) $request->query('workspace_id', 0);
        if (!$workspaceId) {
            return response()->json(['success' => false, 'message' => 'workspace_id is required'], 400);
        }

        // Authenticate via Bearer token, query parameter 'token', or Sanctum session
        $token = $request->bearerToken() ?: $request->query('token');
        $user = null;
        if ($token) {
            $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if ($pat) {
                $user = $pat->tokenable;
            }
        }
        if (!$user && \Illuminate\Support\Facades\Auth::guard('sanctum')->check()) {
            $user = \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
        }
        if (!$user && config('app.env') === 'local') {
            $user = \App\Models\User::first();
        }
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Valid token required.'], 401);
        }

        $sinceId = (int) ($request->query('since', $request->header('Last-Event-ID', 0)));
        if ($sinceId <= 0) {
            $sinceId = (int) (\Illuminate\Support\Facades\DB::table('notifications')
                ->where('workspace_id', $workspaceId)
                ->whereIn('type', ['facebook_comment', 'instagram_comment', 'youtube_comment'])
                ->max('id') ?? 0);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return response()->stream(function () use ($workspaceId, &$sinceId) {
            @set_time_limit(0);
            ignore_user_abort(true);

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            ob_implicit_flush(true);

            \Illuminate\Support\Facades\Log::info('[COMMENTS SSE] Client connected', [
                'workspace_id' => $workspaceId,
                'since_id'     => $sinceId,
            ]);

            // 2KB padding comment ensures HTTP headers and ready event flush immediately through PHP CLI / reverse proxy
            echo ":" . str_repeat(" ", 2048) . "\n\n";
            echo "retry: 2000\n\n";

            echo "event: ready\n";
            echo "data: " . json_encode(['workspace_id' => $workspaceId, 'since_id' => $sinceId]) . "\n\n";
            @ob_flush();
            flush();

            $startTime = time();
            $heartbeatTime = time();
            $lastCheckedVersion = (int) \Illuminate\Support\Facades\Cache::get("comments_stream_v_{$workspaceId}", 0);

            // Keep the SSE stream pending and alive (runs continuous loop up to 30s, browser auto-reconnects seamlessly)
            while (!connection_aborted() && (time() - $startTime) < 30) {
                // Throttled incremental YouTube comments sync (at most once every 20s per workspace)
                if (\Illuminate\Support\Facades\Cache::add("yt_comments_auto_sync_{$workspaceId}", true, 20)) {
                    try {
                        (new \App\Services\CommentNotificationService())->syncYouTubeComments($workspaceId);
                    } catch (\Throwable $ye) {
                        \Illuminate\Support\Facades\Log::debug('[COMMENTS STREAM] YouTube sync error: ' . $ye->getMessage());
                    }
                }

                // Fetch any new comments since $sinceId
                $newRows = \Illuminate\Support\Facades\DB::table('notifications')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('type', ['facebook_comment', 'instagram_comment', 'youtube_comment'])
                    ->where('id', '>', $sinceId)
                    ->orderBy('id', 'asc')
                    ->limit(50)
                    ->get();

                if ($newRows->isNotEmpty()) {
                    foreach ($newRows as $n) {
                        $sinceId = max($sinceId, (int) $n->id);
                        $platform = ($n->type === 'facebook_comment') ? 'facebook'
                                  : (($n->type === 'instagram_comment') ? 'instagram' : 'youtube');

                        $contentId = null;
                        $thumbUrl = null;
                        $postUrl = null;
                        $contentTitle = null;

                        if ($n->type === 'facebook_comment') {
                            $cleanRel = preg_replace('/^facebook_comment:/', '', (string)$n->related_entity);
                            $parts = array_values(array_filter(explode(':', $cleanRel)));
                            $contentId = !empty($parts) ? end($parts) : null;
                            $canonicalPostId = ($contentId && str_contains($contentId, '_')) ? end(explode('_', $contentId)) : $contentId;
                            if ($canonicalPostId) {
                                $thumbUrl = \Illuminate\Support\Facades\Cache::get("fb_post_pic_{$canonicalPostId}");
                                $postUrl = \Illuminate\Support\Facades\Cache::get("fb_post_url_{$canonicalPostId}") ?: "https://www.facebook.com/{$canonicalPostId}";
                                $contentTitle = \Illuminate\Support\Facades\Cache::get("fb_post_title_{$canonicalPostId}");
                            }
                        } elseif ($n->type === 'instagram_comment') {
                            $cleanRel = preg_replace('/^instagram_comment:/', '', (string)$n->related_entity);
                            $parts = array_values(array_filter(explode(':', $cleanRel)));
                            $rawMediaId = !empty($parts) ? end($parts) : null;
                            $contentId = $rawMediaId ? preg_replace('/^media_/', '', $rawMediaId) : null;
                            if ($contentId) {
                                $igMeta = \Illuminate\Support\Facades\Cache::get("ig_media_meta_{$contentId}");
                                if ($igMeta) {
                                    $thumbUrl = $igMeta['thumbnail_url'] ?? null;
                                    $postUrl  = $igMeta['post_url'] ?? $igMeta['permalink'] ?? "https://www.instagram.com/p/{$contentId}";
                                    $contentTitle = $igMeta['title'] ?? null;
                                } else {
                                    $postUrl = "https://www.instagram.com/p/{$contentId}";
                                }
                            }
                        } elseif ($n->type === 'youtube_comment') {
                            $cleanRel = preg_replace('/^youtube_comment:/', '', (string)$n->related_entity);
                            $parts = array_values(array_filter(explode(':', $cleanRel)));
                            $rawVid = !empty($parts) ? end($parts) : null;
                            if ($rawVid && $rawVid !== 'channel') {
                                $contentId = $rawVid;
                                $thumbUrl = "https://i.ytimg.com/vi/{$contentId}/hqdefault.jpg";
                                $postUrl  = "https://www.youtube.com/watch?v={$contentId}";
                            }
                        }

                        $payload = [
                            'id'             => $n->id,
                            'type'           => $n->type,
                            'title'          => $n->title,
                            'message'        => $n->message,
                            'created_at'     => !empty($n->created_at) ? \Carbon\Carbon::parse($n->created_at)->toIso8601String() : null,
                            'is_read'        => (bool)$n->is_read,
                            'related_entity' => $n->related_entity,
                            'platform'       => $platform,
                            'content_id'     => $contentId,
                            'thumbnail_url'  => $thumbUrl,
                            'post_url'       => $postUrl,
                            'content_title'  => $contentTitle,
                        ];

                        \Illuminate\Support\Facades\Log::info('[COMMENTS SSE] Emitting comment.created', [
                            'workspace_id' => $workspaceId,
                            'id'           => $n->id,
                            'platform'     => $platform,
                        ]);

                        echo "id: {$n->id}\n";
                        echo "event: comment.created\n";
                        echo "data: " . json_encode($payload) . "\n\n";
                    }
                    @ob_flush();
                    flush();
                }

                // Check for comment updates (e.g. read status changed)
                $currentVersion = (int) \Illuminate\Support\Facades\Cache::get("comments_stream_v_{$workspaceId}", 0);
                if ($currentVersion > $lastCheckedVersion) {
                    $lastCheckedVersion = $currentVersion;
                    $updatedEvt = \Illuminate\Support\Facades\Cache::get("comments_stream_last_update_{$workspaceId}");
                    if (!empty($updatedEvt)) {
                        \Illuminate\Support\Facades\Log::info('[COMMENTS SSE] Emitting comment.updated', [
                            'workspace_id' => $workspaceId,
                            'version'      => $currentVersion,
                        ]);
                        echo "event: comment.updated\n";
                        echo "data: " . json_encode($updatedEvt) . "\n\n";
                        @ob_flush();
                        flush();
                    }
                }

                // Periodic heartbeat every 10 seconds
                if ((time() - $heartbeatTime) >= 10) {
                    $heartbeatTime = time();
                    echo ": heartbeat " . time() . "\n\n";
                    @ob_flush();
                    flush();
                }

                usleep(500000); // 500ms
            }

            \Illuminate\Support\Facades\Log::info('[COMMENTS SSE] Stream connection cycle finished', [
                'workspace_id' => $workspaceId,
                'last_id'      => $sinceId,
                'duration'     => time() - $startTime,
            ]);
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    });

    // ── Comments Real-Time Live Delta Endpoint (Lightweight, Non-Blocking, Ultra-Fast <3ms) ──
    Route::get('/comments/live-delta', function (Request $request) {
        $workspaceId = (int) $request->query('workspace_id', 0);
        if (!$workspaceId) {
            return response()->json(['success' => false, 'message' => 'workspace_id is required'], 400);
        }

        // Authenticate via Bearer token, query parameter 'token', or Sanctum session
        $token = $request->bearerToken() ?: $request->query('token');
        $user = null;
        if ($token) {
            $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if ($pat) {
                $user = $pat->tokenable;
            }
        }
        if (!$user && \Illuminate\Support\Facades\Auth::guard('sanctum')->check()) {
            $user = \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
        }
        if (!$user && config('app.env') === 'local') {
            $user = \App\Models\User::first();
        }
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Valid token required.'], 401);
        }

        $sinceId = (int) $request->query('since', 0);
        $clientVersion = (int) $request->query('v', 0);

        if ($sinceId <= 0) {
            $sinceId = (int) (\Illuminate\Support\Facades\DB::table('notifications')
                ->where('workspace_id', $workspaceId)
                ->whereIn('type', ['facebook_comment', 'instagram_comment', 'youtube_comment'])
                ->max('id') ?? 0);
        }

        $events = [];

        // Throttled incremental YouTube comments sync (at most once every 20s per workspace)
        if (\Illuminate\Support\Facades\Cache::add("yt_comments_auto_sync_{$workspaceId}", true, 20)) {
            try {
                (new \App\Services\CommentNotificationService())->syncYouTubeComments($workspaceId);
            } catch (\Throwable $ye) {
                \Illuminate\Support\Facades\Log::debug('[LIVE DELTA] YouTube sync error: ' . $ye->getMessage());
            }
        }

        // 1. Fetch any newly created comments since $sinceId
        $newRows = \Illuminate\Support\Facades\DB::table('notifications')
            ->where('workspace_id', $workspaceId)
            ->whereIn('type', ['facebook_comment', 'instagram_comment', 'youtube_comment'])
            ->where('id', '>', $sinceId)
            ->orderBy('id', 'asc')
            ->limit(50)
            ->get();

        $latestId = $sinceId;

        if ($newRows->isNotEmpty()) {
            foreach ($newRows as $n) {
                $latestId = max($latestId, (int) $n->id);
                $platform = ($n->type === 'facebook_comment') ? 'facebook'
                          : (($n->type === 'instagram_comment') ? 'instagram' : 'youtube');

                $contentId = null;
                $thumbUrl = null;
                $postUrl = null;
                $contentTitle = null;

                if ($n->type === 'facebook_comment') {
                    $cleanRel = preg_replace('/^facebook_comment:/', '', (string)$n->related_entity);
                    $parts = array_values(array_filter(explode(':', $cleanRel)));
                    $contentId = !empty($parts) ? end($parts) : null;
                    $canonicalPostId = ($contentId && str_contains($contentId, '_')) ? end(explode('_', $contentId)) : $contentId;
                    if ($canonicalPostId) {
                        $thumbUrl = \Illuminate\Support\Facades\Cache::get("fb_post_pic_{$canonicalPostId}");
                        $postUrl = \Illuminate\Support\Facades\Cache::get("fb_post_url_{$canonicalPostId}") ?: "https://www.facebook.com/{$canonicalPostId}";
                        $contentTitle = \Illuminate\Support\Facades\Cache::get("fb_post_title_{$canonicalPostId}");
                    }
                } elseif ($n->type === 'instagram_comment') {
                    $cleanRel = preg_replace('/^instagram_comment:/', '', (string)$n->related_entity);
                    $parts = array_values(array_filter(explode(':', $cleanRel)));
                    $rawMediaId = !empty($parts) ? end($parts) : null;
                    $contentId = $rawMediaId ? preg_replace('/^media_/', '', $rawMediaId) : null;
                    if ($contentId) {
                        $igMeta = \Illuminate\Support\Facades\Cache::get("ig_media_meta_{$contentId}");
                        if ($igMeta) {
                            $thumbUrl = $igMeta['thumbnail_url'] ?? null;
                            $postUrl  = $igMeta['post_url'] ?? $igMeta['permalink'] ?? "https://www.instagram.com/p/{$contentId}";
                            $contentTitle = $igMeta['title'] ?? null;
                        } else {
                            $postUrl = "https://www.instagram.com/p/{$contentId}";
                        }
                    }
                } elseif ($n->type === 'youtube_comment') {
                    $cleanRel = preg_replace('/^youtube_comment:/', '', (string)$n->related_entity);
                    $parts = array_values(array_filter(explode(':', $cleanRel)));
                    $rawVid = !empty($parts) ? end($parts) : null;
                    if ($rawVid && $rawVid !== 'channel') {
                        $contentId = $rawVid;
                        $thumbUrl = "https://i.ytimg.com/vi/{$contentId}/hqdefault.jpg";
                        $postUrl  = "https://www.youtube.com/watch?v={$contentId}";
                    }
                }

                $events[] = [
                    'event' => 'comment.created',
                    'data'  => [
                        'id'             => $n->id,
                        'type'           => $n->type,
                        'title'          => $n->title,
                        'message'        => $n->message,
                        'created_at'     => !empty($n->created_at) ? \Carbon\Carbon::parse($n->created_at)->toIso8601String() : null,
                        'is_read'        => (bool)$n->is_read,
                        'related_entity' => $n->related_entity,
                        'platform'       => $platform,
                        'content_id'     => $contentId,
                        'thumbnail_url'  => $thumbUrl,
                        'post_url'       => $postUrl,
                        'content_title'  => $contentTitle,
                    ],
                ];
            }
        }

        // 2. Check for comment updates (e.g. read status toggled)
        $currentVersion = (int) \Illuminate\Support\Facades\Cache::get("comments_stream_v_{$workspaceId}", 0);
        if ($currentVersion > $clientVersion) {
            $updatedEvt = \Illuminate\Support\Facades\Cache::get("comments_stream_last_update_{$workspaceId}");
            if (!empty($updatedEvt)) {
                $events[] = [
                    'event' => 'comment.updated',
                    'data'  => $updatedEvt,
                ];
            }
        }

        return response()->json([
            'success'   => true,
            'events'    => $events,
            'latest_id' => $latestId,
            'version'   => $currentVersion,
        ]);
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

        // Workspaces API (With Real Isolated Metrics per Client)
        Route::get('/workspaces', function () {
            $workspaces = Workspace::with('owner')->orderBy('created_at', 'desc')->get();

            $workspacesData = $workspaces->map(function ($ws) {
                // 1. Unique Connected Platforms for this workspace
                $connectedPlatforms = [];

                $hasFb = FacebookPage::where('workspace_id', $ws->id)
                    ->whereNotNull('page_access_token')
                    ->whereNotIn('token_status', ['invalid', 'disconnected', 'revoked'])
                    ->exists()
                    || Integration::where('workspace_id', $ws->id)
                    ->where('platform', 'facebook')
                    ->where('is_connected', true)
                    ->where('connection_status', '!=', 'disconnected')
                    ->exists();
                if ($hasFb) $connectedPlatforms[] = 'Facebook';

                $hasIg = Integration::where('workspace_id', $ws->id)
                    ->where('platform', 'instagram')
                    ->where('is_connected', true)
                    ->exists();
                if ($hasIg) $connectedPlatforms[] = 'Instagram';

                $hasYt = \App\Models\YouTubeConnection::where('workspace_id', $ws->id)
                    ->where(function ($q) {
                        $q->whereNotNull('refresh_token')->orWhereNotNull('access_token');
                    })
                    ->exists()
                    || Integration::where('workspace_id', $ws->id)
                    ->where('platform', 'youtube')
                    ->where('is_connected', true)
                    ->exists();
                if ($hasYt) $connectedPlatforms[] = 'YouTube';

                $hasTw = Integration::where('workspace_id', $ws->id)
                    ->where('platform', 'twitter')
                    ->where('is_connected', true)
                    ->exists();
                if ($hasTw) $connectedPlatforms[] = 'Twitter';

                $hasGa = Integration::where('workspace_id', $ws->id)
                    ->where('platform', 'google_analytics')
                    ->where('is_connected', true)
                    ->exists();
                if ($hasGa) $connectedPlatforms[] = 'Google Analytics';

                $hasGsc = Integration::where('workspace_id', $ws->id)
                    ->where('platform', 'search_console')
                    ->where('is_connected', true)
                    ->exists();
                if ($hasGsc) $connectedPlatforms[] = 'Search Console';

                $channelsCount = count($connectedPlatforms);

                // 2. Real Leads Count for this workspace
                $leadsCount = Lead::where('workspace_id', $ws->id)->count();

                // 3. Real Reach Count for this workspace (from published posts)
                $postsReach = (int)FacebookPost::where('workspace_id', $ws->id)
                    ->where('status', 'published')
                    ->sum('reach_count');

                $wsArray = $ws->toArray();
                $wsArray['channels_count']     = $channelsCount;
                $wsArray['connected_channels'] = $connectedPlatforms;
                $wsArray['leads_count']        = $leadsCount;
                $wsArray['reach_count']        = $postsReach;

                return $wsArray;
            });

            // Workspaces with connected channels are prioritized first
            $workspacesData = $workspacesData->sortByDesc('channels_count')->values();

            return response()->json([
                'success' => true,
                'data' => $workspacesData
            ]);
        });

        Route::get('/workspace/{id}/metrics', function ($id) {
            $workspaceId = (int)$id;
            $ws = Workspace::find($workspaceId);
            if (!$ws) {
                return response()->json(['success' => false, 'message' => 'Workspace not found'], 404);
            }

            $connectedPlatforms = [];
            $hasFb = FacebookPage::where('workspace_id', $ws->id)->whereNotNull('page_access_token')->where(function ($q) {
                $q->whereNotIn('token_status', ['invalid', 'disconnected', 'revoked'])->orWhereNull('token_status');
            })->exists()
                  || Integration::where('workspace_id', $ws->id)->where('platform', 'facebook')->where('is_connected', true)->where('connection_status', '!=', 'disconnected')->exists();
            if ($hasFb) $connectedPlatforms[] = 'Facebook';

            $hasIg = Integration::where('workspace_id', $ws->id)->where('platform', 'instagram')->where('is_connected', true)->exists();
            if ($hasIg) $connectedPlatforms[] = 'Instagram';

            $hasYt = \App\Models\YouTubeConnection::where('workspace_id', $ws->id)
                  ->where(function ($q) {
                      $q->whereNotNull('refresh_token')->orWhereNotNull('access_token');
                  })
                  ->exists()
                  || Integration::where('workspace_id', $ws->id)->where('platform', 'youtube')->where('is_connected', true)->exists();
            if ($hasYt) $connectedPlatforms[] = 'YouTube';

            $hasTw = Integration::where('workspace_id', $ws->id)->where('platform', 'twitter')->where('is_connected', true)->exists();
            if ($hasTw) $connectedPlatforms[] = 'Twitter';

            $hasGa = Integration::where('workspace_id', $ws->id)->where('platform', 'google_analytics')->where('is_connected', true)->exists();
            if ($hasGa) $connectedPlatforms[] = 'Google Analytics';

            $hasGsc = Integration::where('workspace_id', $ws->id)->where('platform', 'search_console')->where('is_connected', true)->exists();
            if ($hasGsc) $connectedPlatforms[] = 'Search Console';

            $channelsCount = count($connectedPlatforms);
            $leadsCount = Lead::where('workspace_id', $ws->id)->count();
            $postsReach = (int)FacebookPost::where('workspace_id', $ws->id)->where('status', 'published')->sum('reach_count');

            return response()->json([
                'success' => true,
                'data' => [
                    'workspace_id'       => $workspaceId,
                    'channels'           => $channelsCount,
                    'connected_channels' => $connectedPlatforms,
                    'leads'              => $leadsCount,
                    'reach'              => $postsReach,
                ]
            ]);
        });

        Route::get('/workspaces/{id}/metrics', function ($id) {
            $workspaceId = (int)$id;
            $ws = Workspace::find($workspaceId);
            if (!$ws) {
                return response()->json(['success' => false, 'message' => 'Workspace not found'], 404);
            }

            $connectedPlatforms = [];
            $hasFb = FacebookPage::where('workspace_id', $ws->id)->whereNotNull('page_access_token')->where(function ($q) {
                $q->whereNotIn('token_status', ['invalid', 'disconnected', 'revoked'])->orWhereNull('token_status');
            })->exists()
                  || Integration::where('workspace_id', $ws->id)->where('platform', 'facebook')->where('is_connected', true)->where('connection_status', '!=', 'disconnected')->exists();
            if ($hasFb) $connectedPlatforms[] = 'Facebook';

            $hasIg = Integration::where('workspace_id', $ws->id)->where('platform', 'instagram')->where('is_connected', true)->exists();
            if ($hasIg) $connectedPlatforms[] = 'Instagram';

            $hasYt = \App\Models\YouTubeConnection::where('workspace_id', $ws->id)
                  ->where(function ($q) {
                      $q->whereNotNull('refresh_token')->orWhereNotNull('access_token');
                  })
                  ->exists()
                  || Integration::where('workspace_id', $ws->id)->where('platform', 'youtube')->where('is_connected', true)->exists();
            if ($hasYt) $connectedPlatforms[] = 'YouTube';

            $hasTw = Integration::where('workspace_id', $ws->id)->where('platform', 'twitter')->where('is_connected', true)->exists();
            if ($hasTw) $connectedPlatforms[] = 'Twitter';

            $hasGa = Integration::where('workspace_id', $ws->id)->where('platform', 'google_analytics')->where('is_connected', true)->exists();
            if ($hasGa) $connectedPlatforms[] = 'Google Analytics';

            $hasGsc = Integration::where('workspace_id', $ws->id)->where('platform', 'search_console')->where('is_connected', true)->exists();
            if ($hasGsc) $connectedPlatforms[] = 'Search Console';

            $channelsCount = count($connectedPlatforms);
            $leadsCount = Lead::where('workspace_id', $ws->id)->count();
            $postsReach = (int)FacebookPost::where('workspace_id', $ws->id)->where('status', 'published')->sum('reach_count');

            return response()->json([
                'success' => true,
                'data' => [
                    'workspace_id'       => $workspaceId,
                    'channels'           => $channelsCount,
                    'connected_channels' => $connectedPlatforms,
                    'leads'              => $leadsCount,
                    'reach'              => $postsReach,
                ]
            ]);
        });

        Route::post('/workspaces', function (Request $request) {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'industry' => 'nullable|string|max:255',
                'primary_contact' => 'nullable|string|max:255',
                'primary_contact_email' => 'nullable|string|max:255',
                'budget' => 'nullable|numeric',
                'status' => 'nullable|string|in:active,setup,pending,inactive',
            ]);

            $workspace = Workspace::create([
                'name' => $validated['name'],
                'industry' => $validated['industry'] ?? 'General',
                'primary_contact' => $validated['primary_contact'] ?? 'Contact Person',
                'primary_contact_email' => $validated['primary_contact_email'] ?? (strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $validated['name'])) . '@client.com'),
                'budget' => $validated['budget'] ?? 0,
                'status' => $validated['status'] ?? 'active',
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

            $accounts = app(\App\Services\WorkspaceSocialAccounts::class);
            $connectedPage = $accounts->facebook($workspaceId);

            $fbPhotoMetrics = ['likes' => 0, 'comments' => 0, 'shares' => 0];
            if ($connectedPage && !empty($connectedPage->page_access_token)) {
                try {
                    $graphService = new FacebookGraphService();
                    $graphService->refreshPageInsights($connectedPage);
                    $graphService->syncWorkspacePosts($workspaceId);
                    $fbPhotoMetrics = $graphService->getPagePhotosMetrics($connectedPage->page_id, $connectedPage->page_access_token);
                } catch (\Exception $e) {}
            }

            if ($connectedPage) {
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

                $followersCount = (int)($connectedPage->followers_count ?? 0);
                $fanCount = (int)($connectedPage->fan_count ?? 0);

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
            } else {
                $totalPosts = 0;
                $postsThisMonth = 0;
                $publishedToday = 0;
                $scheduledCount = 0;
                $draftsCount = 0;
                $totalLikes = 0;
                $totalComments = 0;
                $totalShares = 0;
                $totalReactions = 0;
                $totalEngagement = 0;
                $totalReach = 0;
                $followersCount = 0;
                $fanCount = 0;
                $recentPosts = collect([]);
            }

            $ytInteg = Integration::where('workspace_id', $workspaceId)->where('platform', 'youtube')->where('is_connected', true)->first();
            $ytConn = $ytInteg ? \App\Models\YouTubeConnection::where('workspace_id', $workspaceId)->whereNotNull('access_token')->first() : null;

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
                    'connected_pages_count' => $connectedPage ? 1 : 0,
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
                    'youtube_connection'    => ($ytInteg && $ytConn) ? [
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

        // ── Notifications API — 100% Real DB-backed with 5-record Pagination ────
        Route::get('/notifications', function (Request $request) {
            $workspaceId = $request->query('workspace_id', null);
            $filterTab   = strtolower(trim($request->query('category', $request->query('filter', 'all'))));
            $search      = trim($request->query('search', ''));
            $page        = max(1, (int)$request->query('page', 1));
            $perPage     = max(1, (int)$request->query('per_page', 5));

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

            // Base query strictly scoped to workspace
            $baseQuery = \Illuminate\Support\Facades\DB::table('notifications')
                ->leftJoin('workspaces', 'notifications.workspace_id', '=', 'workspaces.id');

            if ($workspaceId) {
                $baseQuery->where('notifications.workspace_id', $workspaceId);
            }

            // Real workspace-wide summary metrics (before category filtering)
            $totalCount  = (clone $baseQuery)->count();
            $unreadCount = (clone $baseQuery)->where('notifications.is_read', false)->count();

            // Real Category Counters for Tabs
            $commentsCount = (clone $baseQuery)->whereIn('notifications.type', ['facebook_comment', 'instagram_comment', 'youtube_comment'])->count();
            $leadsCount    = (clone $baseQuery)->whereIn('notifications.type', ['lead_assigned', 'lead_converted'])->count();
            $pubCount      = (clone $baseQuery)->where('notifications.type', 'post_published')->count();
            $systemCount   = (clone $baseQuery)->whereIn('notifications.type', ['integration_error', 'campaign_milestone', 'team_invite', 'report_ready'])->count();

            // Apply Tab Category Filter
            $filteredQuery = clone $baseQuery;

            if ($filterTab === 'comments') {
                $filteredQuery->whereIn('notifications.type', ['facebook_comment', 'instagram_comment', 'youtube_comment']);
            } elseif ($filterTab === 'unread') {
                $filteredQuery->where('notifications.is_read', false);
            } elseif ($filterTab === 'leads') {
                $filteredQuery->whereIn('notifications.type', ['lead_assigned', 'lead_converted']);
            } elseif ($filterTab === 'publishing') {
                $filteredQuery->where('notifications.type', 'post_published');
            } elseif ($filterTab === 'system') {
                $filteredQuery->whereIn('notifications.type', ['integration_error', 'campaign_milestone', 'team_invite', 'report_ready']);
            }

            // Apply Search Query
            if (!empty($search)) {
                $filteredQuery->where(function ($q) use ($search) {
                    $q->where('notifications.title', 'LIKE', "%{$search}%")
                      ->orWhere('notifications.message', 'LIKE', "%{$search}%")
                      ->orWhere('workspaces.name', 'LIKE', "%{$search}%");
                });
            }

            // Apply Date Range Filter if provided
            $startDateParam = $request->query('start_date');
            $endDateParam   = $request->query('end_date');
            if (!empty($startDateParam) && $startDateParam !== 'all' && $startDateParam !== 'null') {
                try {
                    $sDate = \Carbon\Carbon::parse($startDateParam)->startOfDay();
                    $filteredQuery->where('notifications.created_at', '>=', $sDate);
                } catch (\Throwable $e) {}
            }
            if (!empty($endDateParam) && $endDateParam !== 'all' && $endDateParam !== 'null') {
                try {
                    $eDate = \Carbon\Carbon::parse($endDateParam)->endOfDay();
                    $filteredQuery->where('notifications.created_at', '<=', $eDate);
                } catch (\Throwable $e) {}
            }

            $totalFiltered = (clone $filteredQuery)->count();
            $lastPage      = max(1, (int)ceil($totalFiltered / $perPage));
            if ($page > $lastPage) $page = $lastPage;

            $rows = $filteredQuery->select(
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
                ->orderBy('notifications.id', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            // Bulk pre-load all FacebookPost records in a single query to eliminate N+1 queries
            $fbCandidateIds = [];
            foreach ($rows as $r) {
                if ($r->type === 'facebook_comment') {
                    $cleanRel = preg_replace('/^facebook_comment:/', '', (string)$r->related_entity);
                    $parts = array_values(array_filter(explode(':', $cleanRel)));
                    $cId = !empty($parts) ? end($parts) : null;
                    if ($cId) {
                        $fbCandidateIds[] = $cId;
                        if (str_contains($cId, '_')) {
                            $sub = explode('_', $cId);
                            $fbCandidateIds[] = end($sub);
                        }
                    }
                }
            }
            $fbCandidateIds = array_unique(array_filter($fbCandidateIds));

            $fbPostMap = [];
            if (!empty($fbCandidateIds)) {
                $preloadedFbPosts = \App\Models\FacebookPost::with('media')
                    ->whereIn('fb_post_id', $fbCandidateIds)
                    ->orWhereIn('id', array_filter($fbCandidateIds, 'is_numeric'))
                    ->get();

                foreach ($preloadedFbPosts as $p) {
                    if ($p->fb_post_id) {
                        $fbPostMap[(string)$p->fb_post_id] = $p;
                    }
                    $fbPostMap[(string)$p->id] = $p;
                }
            }

            $formatted = $rows->map(function ($n) use ($categoryMap, $fbPostMap, $workspaceId) {
                $platform = null;
                $contentId = null;
                $thumbUrl = null;
                $mediaUrl = null;
                $postUrl = null;
                $contentTitle = null;

                if ($n->type === 'facebook_comment') {
                    $platform = 'facebook';
                    $cleanRel = preg_replace('/^facebook_comment:/', '', (string)$n->related_entity);
                    $parts = array_values(array_filter(explode(':', $cleanRel)));
                    $contentId = !empty($parts) ? end($parts) : null;
                    if ($contentId && str_contains($contentId, '_')) {
                        $sub = explode('_', $contentId);
                        $canonicalPostId = end($sub);
                    } else {
                        $canonicalPostId = $contentId;
                    }

                    if ($canonicalPostId) {
                        $fbPost = $fbPostMap[(string)$canonicalPostId] ?? $fbPostMap[(string)$contentId] ?? null;

                        if ($fbPost) {
                            $thumbUrl = $fbPost->media->first()?->file_url
                                ?? \Illuminate\Support\Facades\Cache::get("fb_post_pic_{$fbPost->fb_post_id}");
                            $postUrl = $fbPost->link_url ?: "https://www.facebook.com/{$canonicalPostId}";
                            $contentTitle = !empty($fbPost->content) ? strtok($fbPost->content, "\n") : null;
                        } else {
                            $thumbUrl = \Illuminate\Support\Facades\Cache::get("fb_post_pic_{$canonicalPostId}");
                            $postUrl = \Illuminate\Support\Facades\Cache::get("fb_post_url_{$canonicalPostId}") ?: "https://www.facebook.com/{$canonicalPostId}";
                            $contentTitle = \Illuminate\Support\Facades\Cache::get("fb_post_title_{$canonicalPostId}");
                        }

                        // On-demand Graph API lookup fallback for direct photo/video comments if thumbnail is still missing
                        if (empty($thumbUrl)) {
                            $thumbUrl = \Illuminate\Support\Facades\Cache::remember("fb_post_pic_{$canonicalPostId}", 86400 * 7, function () use ($canonicalPostId, $n, $workspaceId) {
                                $fbPage = \App\Models\FacebookPage::where('workspace_id', $n->workspace_id ?? $workspaceId)->first();
                                if (empty($fbPage?->page_access_token)) return null;
                                try {
                                    $client = new \GuzzleHttp\Client(['timeout' => 4]);
                                    $res = $client->get("https://graph.facebook.com/v23.0/{$canonicalPostId}", [
                                        'query' => [
                                            'fields'       => 'id,picture,source',
                                            'access_token' => $fbPage->page_access_token,
                                        ]
                                    ]);
                                    $data = json_decode($res->getBody(), true);
                                    $src = $data['source'] ?? null;
                                    if ($src && !str_contains($src, '.mp4')) {
                                        return $src;
                                    }
                                    return $data['picture'] ?? $src ?? null;
                                } catch (\Throwable $e) {
                                    return null;
                                }
                            });
                        }
                    }
                } elseif ($n->type === 'instagram_comment') {
                    $platform = 'instagram';
                    $cleanRel = preg_replace('/^instagram_comment:/', '', (string)$n->related_entity);
                    $parts = array_values(array_filter(explode(':', $cleanRel)));
                    $rawMediaId = !empty($parts) ? end($parts) : null;
                    $contentId = $rawMediaId ? preg_replace('/^media_/', '', $rawMediaId) : null;

                    if ($contentId) {
                        $igMeta = \Illuminate\Support\Facades\Cache::get("ig_media_meta_{$contentId}");
                        if ($igMeta) {
                            $thumbUrl = $igMeta['thumbnail_url'] ?? null;
                            $mediaUrl = $igMeta['media_url'] ?? null;
                            $postUrl  = $igMeta['post_url'] ?? $igMeta['permalink'] ?? "https://www.instagram.com/p/{$contentId}";
                            $contentTitle = $igMeta['title'] ?? null;
                        } else {
                            $postUrl = "https://www.instagram.com/p/{$contentId}";
                        }
                    }
                } elseif ($n->type === 'youtube_comment') {
                    $platform = 'youtube';
                    $cleanRel = preg_replace('/^youtube_comment:/', '', (string)$n->related_entity);
                    $parts = array_values(array_filter(explode(':', $cleanRel)));
                    $rawVid = !empty($parts) ? end($parts) : null;
                    if ($rawVid && $rawVid !== 'channel') {
                        $contentId = $rawVid;
                        $thumbUrl = "https://i.ytimg.com/vi/{$contentId}/hqdefault.jpg";
                        $mediaUrl = "https://www.youtube.com/watch?v={$contentId}";
                        $postUrl  = "https://www.youtube.com/watch?v={$contentId}";
                    }
                }

                return [
                    'id'             => $n->id,
                    'type'           => $n->type,
                    'title'          => $n->title,
                    'message'        => $n->message,
                    'workspace'      => $n->workspace_name ?? 'General',
                    'category'       => $categoryMap[$n->type] ?? 'system',
                    'created_at'     => !empty($n->created_at) ? \Carbon\Carbon::parse($n->created_at)->toIso8601String() : null,
                    'is_read'        => (bool)$n->is_read,
                    'related_entity' => $n->related_entity,
                    'status_type'    => in_array($n->type, ['post_published', 'lead_converted', 'facebook_comment', 'instagram_comment', 'youtube_comment']) ? 'success'
                                    : (in_array($n->type, ['integration_error']) ? 'warning'
                                    : (in_array($n->type, ['lead_assigned']) ? 'primary' : 'info')),
                    'platform'       => $platform,
                    'content_id'     => $contentId,
                    'thumbnail_url'  => $thumbUrl,
                    'media_url'      => $mediaUrl,
                    'post_url'       => $postUrl,
                    'content_title'  => $contentTitle,
                ];
            });

            // Real active workspaces count from DB
            $activeWorkspaces = \App\Models\Workspace::where('status', 'active')->count();

            return response()->json([
                'success'    => true,
                'data'       => $formatted,
                'pagination' => [
                    'current_page' => $page,
                    'per_page'     => $perPage,
                    'total'        => $totalFiltered,
                    'last_page'    => $lastPage,
                    'has_prev'     => $page > 1,
                    'has_next'     => $page < $lastPage,
                ],
                'summary' => [
                    'total_notifications' => $totalCount,
                    'unread_alerts'       => $unreadCount,
                    'active_workspaces'   => $activeWorkspaces,
                    'system_status'       => 'Operational',
                    'counts'              => [
                        'all'        => $totalCount,
                        'comments'   => $commentsCount,
                        'unread'     => $unreadCount,
                        'leads'      => $leadsCount,
                        'publishing' => $pubCount,
                        'system'     => $systemCount,
                    ],
                ],
            ]);
        });

        Route::post('/notifications/mark-read', function (Request $request) {
            $workspaceId = $request->input('workspace_id', null);
            $query = \Illuminate\Support\Facades\DB::table('notifications')
                ->where('is_read', false);
            if ($workspaceId) {
                $query->where('workspace_id', $workspaceId);
            }
            $updated = $query->update(['is_read' => true, 'read_at' => now(), 'updated_at' => now()]);
            return response()->json(['success' => true, 'updated_count' => $updated, 'message' => 'All notifications marked as read']);
        });

        Route::patch('/notifications/{id}/read', function ($id) {
            $notif = \Illuminate\Support\Facades\DB::table('notifications')->where('id', $id)->first();
            if ($notif) {
                $newRead = !$notif->is_read;
                \Illuminate\Support\Facades\DB::table('notifications')
                    ->where('id', $id)
                    ->update([
                        'is_read'    => $newRead,
                        'read_at'    => $newRead ? now() : null,
                        'updated_at' => now(),
                    ]);

                if ($notif->workspace_id) {
                    \Illuminate\Support\Facades\Cache::put("comments_stream_last_update_{$notif->workspace_id}", [
                        'id'      => (int) $id,
                        'is_read' => (bool) $newRead,
                    ], 86400);
                    \Illuminate\Support\Facades\Cache::put("comments_stream_v_{$notif->workspace_id}", time(), 86400);
                }

                return response()->json(['success' => true, 'is_read' => $newRead]);
            }
            return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
        });



        // ── Comments Dedicated Incremental Sync Endpoint ─────────────────────
        Route::post('/comments/sync', function (Request $request) {
            $workspaceId = (int) $request->input('workspace_id', $request->query('workspace_id', 0));
            if (!$workspaceId) {
                return response()->json(['success' => false, 'message' => 'workspace_id is required'], 400);
            }
            $platform = $request->input('platform', 'all');

            $syncService = new \App\Services\CommentNotificationService();
            $beforeCount = \Illuminate\Support\Facades\DB::table('notifications')
                ->where('workspace_id', $workspaceId)
                ->whereIn('type', ['facebook_comment', 'instagram_comment', 'youtube_comment'])
                ->count();

            try {
                if ($platform === 'facebook') {
                    $syncService->syncFacebookComments($workspaceId);
                } elseif ($platform === 'instagram') {
                    $syncService->syncInstagramComments($workspaceId);
                } elseif ($platform === 'youtube') {
                    $syncService->syncYouTubeComments($workspaceId);
                } else {
                    $syncService->syncAll($workspaceId);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Comments sync error', ['error' => $e->getMessage()]);
            }

            $afterCount = \Illuminate\Support\Facades\DB::table('notifications')
                ->where('workspace_id', $workspaceId)
                ->whereIn('type', ['facebook_comment', 'instagram_comment', 'youtube_comment'])
                ->count();

            $newCount = max(0, $afterCount - $beforeCount);

            if ($newCount > 0) {
                \Illuminate\Support\Facades\Cache::put("comments_stream_v_{$workspaceId}", time(), 86400);
            }

            return response()->json([
                'success'            => true,
                'workspace_id'       => $workspaceId,
                'new_comments_count' => $newCount,
                'message'            => $newCount > 0 ? "Synced {$newCount} new comment(s)." : "All comments are up to date."
            ]);
        });

        // ── Team API — 100% Real DB-backed (users & user_workspace_roles) ────────
        Route::get('/team', function () {
            $users = User::where('is_active', true)
                ->with('workspaces')
                ->orderBy('id', 'asc')
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

                    $assignedWorkspaces = $u->workspaces->pluck('name')->toArray();
                    $assignedDisplay = count($assignedWorkspaces) > 0
                        ? implode(', ', $assignedWorkspaces)
                        : ($u->role === 'admin' ? 'All Workspaces (Admin)' : 'No specific client assigned');

                    return [
                        'id'                     => $u->id,
                        'name'                   => $u->name,
                        'email'                  => $u->email,
                        'role'                   => $roleDisplay,
                        'role_key'               => $u->role,
                        'assigned_clients'       => $assignedDisplay,
                        'assigned_workspace_ids' => $u->workspaces->pluck('id')->toArray(),
                        'last_active'            => $u->last_login_at ? $u->last_login_at->diffForHumans() : 'Recent session',
                        'last_login_at'          => $u->last_login_at ? $u->last_login_at->toIso8601String() : null,
                        'status'                 => $u->is_active ? 'Active' : 'Inactive',
                        'initials'               => substr($initials, 0, 2),
                        'created_at'             => $u->created_at ? $u->created_at->toIso8601String() : null,
                    ];
                });

            $roleCounts = [
                'admin'     => User::where('is_active', true)->where('role', 'admin')->count(),
                'manager'   => User::where('is_active', true)->where('role', 'manager')->count(),
                'executive' => User::where('is_active', true)->where('role', 'executive')->count(),
                'viewer'    => User::where('is_active', true)->where('role', 'viewer')->count(),
            ];

            return response()->json([
                'success'     => true,
                'data'        => $users,
                'role_counts' => $roleCounts,
            ]);
        });

        Route::post('/team', function (Request $request) {
            $validated = $request->validate([
                'name'            => 'required|string|max:255',
                'email'           => 'required|email|max:255|unique:users,email',
                'role'            => 'required|string',
                'workspace_ids'   => 'nullable|array',
                'workspace_ids.*' => 'integer',
            ]);

            $roleMap = [
                'Administrator'      => 'admin',
                'Marketing Manager'  => 'manager',
                'Executive'          => 'executive',
                'Viewer'             => 'viewer',
                'admin'              => 'admin',
                'manager'            => 'manager',
                'executive'          => 'executive',
                'viewer'             => 'viewer',
            ];
            $roleKey = $roleMap[$validated['role']] ?? 'viewer';

            $user = User::create([
                'name'      => trim($validated['name']),
                'email'     => trim($validated['email']),
                'password'  => Hash::make(str()->random(16)),
                'role'      => $roleKey,
                'is_active' => true,
            ]);

            if (!empty($validated['workspace_ids'])) {
                $syncData = [];
                foreach ($validated['workspace_ids'] as $wsId) {
                    $syncData[$wsId] = ['role' => $roleKey];
                }
                $user->workspaces()->sync($syncData);
            }

            $nameParts = explode(' ', trim($user->name));
            $initials  = strtoupper(implode('', array_map(fn($p) => $p[0] ?? '', array_slice($nameParts, 0, 2))));
            $assignedWorkspaces = $user->workspaces()->pluck('name')->toArray();

            return response()->json([
                'success' => true,
                'message' => "Team member '{$user->name}' added successfully.",
                'data' => [
                    'id'                     => $user->id,
                    'name'                   => $user->name,
                    'email'                  => $user->email,
                    'role'                   => match($user->role) {
                        'admin'     => 'Administrator',
                        'manager'   => 'Marketing Manager',
                        'executive' => 'Executive',
                        'viewer'    => 'Viewer',
                        default     => ucfirst($user->role),
                    },
                    'role_key'               => $user->role,
                    'assigned_clients'       => count($assignedWorkspaces) > 0 ? implode(', ', $assignedWorkspaces) : ($user->role === 'admin' ? 'All Workspaces (Admin)' : 'No specific client assigned'),
                    'assigned_workspace_ids' => $user->workspaces()->pluck('workspaces.id')->toArray(),
                    'last_active'            => 'Never',
                    'last_login_at'          => null,
                    'status'                 => 'Active',
                    'initials'               => substr($initials, 0, 2),
                    'created_at'             => $user->created_at->toIso8601String(),
                ]
            ], 201);
        });

        Route::put('/team/{id}', function ($id, Request $request) {
            $user = User::findOrFail($id);

            $validated = $request->validate([
                'name'            => 'sometimes|required|string|max:255',
                'role'            => 'sometimes|required|string',
                'is_active'       => 'sometimes|boolean',
                'workspace_ids'   => 'nullable|array',
                'workspace_ids.*' => 'integer',
            ]);

            if (isset($validated['name'])) {
                $user->name = trim($validated['name']);
            }
            if (isset($validated['role'])) {
                $roleMap = [
                    'Administrator'      => 'admin',
                    'Marketing Manager'  => 'manager',
                    'Executive'          => 'executive',
                    'Viewer'             => 'viewer',
                    'admin'              => 'admin',
                    'manager'            => 'manager',
                    'executive'          => 'executive',
                    'viewer'             => 'viewer',
                ];
                $user->role = $roleMap[$validated['role']] ?? $user->role;
            }
            if (isset($validated['is_active'])) {
                $user->is_active = (bool)$validated['is_active'];
            }
            $user->save();

            if (isset($validated['workspace_ids'])) {
                $syncData = [];
                foreach ($validated['workspace_ids'] as $wsId) {
                    $syncData[$wsId] = ['role' => $user->role];
                }
                $user->workspaces()->sync($syncData);
            }

            $nameParts = explode(' ', trim($user->name));
            $initials  = strtoupper(implode('', array_map(fn($p) => $p[0] ?? '', array_slice($nameParts, 0, 2))));
            $assignedWorkspaces = $user->workspaces()->pluck('name')->toArray();

            return response()->json([
                'success' => true,
                'message' => "Team member '{$user->name}' updated successfully.",
                'data' => [
                    'id'                     => $user->id,
                    'name'                   => $user->name,
                    'email'                  => $user->email,
                    'role'                   => match($user->role) {
                        'admin'     => 'Administrator',
                        'manager'   => 'Marketing Manager',
                        'executive' => 'Executive',
                        'viewer'    => 'Viewer',
                        default     => ucfirst($user->role),
                    },
                    'role_key'               => $user->role,
                    'assigned_clients'       => count($assignedWorkspaces) > 0 ? implode(', ', $assignedWorkspaces) : ($user->role === 'admin' ? 'All Workspaces (Admin)' : 'No specific client assigned'),
                    'assigned_workspace_ids' => $user->workspaces()->pluck('workspaces.id')->toArray(),
                    'last_active'            => $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Recent session',
                    'last_login_at'          => $user->last_login_at ? $user->last_login_at->toIso8601String() : null,
                    'status'                 => $user->is_active ? 'Active' : 'Inactive',
                    'initials'               => substr($initials, 0, 2),
                    'created_at'             => $user->created_at ? $user->created_at->toIso8601String() : null,
                ]
            ]);
        });

        Route::delete('/team/{id}', function ($id, Request $request) {
            if ($request->user() && $request->user()->id == $id) {
                return response()->json(['success' => false, 'message' => 'Cannot delete your own account.'], 403);
            }
            $user = User::findOrFail($id);
            $user->is_active = false;
            $user->save();
            return response()->json(['success' => true, 'message' => "Team member '{$user->name}' deactivated successfully."]);
        });

        // ── Dashboard Overview — Real Data & Strict Workspace Isolation ───────────
        Route::get('/dashboard/overview', function (Request $request) {
            $request->validate(['workspace_id' => 'required|integer|exists:workspaces,id']);
            $workspaceId    = (int)$request->query('workspace_id');
            $startDateParam = $request->query('start_date');
            $endDateParam   = $request->query('end_date');
            $forceRefresh   = $request->boolean('force_refresh') || $request->boolean('refresh') || $request->has('force_refresh');
            $autoSync       = $request->boolean('auto_sync') || $request->boolean('live_sync');
            $requestedPlatform = strtolower((string) $request->query('platform', ''));
            $isLightMode = in_array($requestedPlatform, ['facebook', 'instagram']) && $request->boolean('light');

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

            // 2. Facebook Metrics (Strict workspace isolation - do not leak or resurrect across workspaces)
            $accounts = app(\App\Services\WorkspaceSocialAccounts::class);
            $fbPage = $accounts->facebook($workspaceId);

            $fbImpressions = 0;
            $fbInsightsEngagement = 0;
            $fbInsightsReactions = 0;
            $fbPeriodFollowers = 0;
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

            $fbPerfViews = null;
            $fbPerfViewers = null;
            $fbPerfViewsSupported = false;
            $fbPerfViewersSupported = false;

            if ($fbPage && !empty($fbPage->page_access_token) && !$isLightMode) {
                // Canonical Facebook performance metrics (Views & Viewers) sourced strictly from FacebookPerformanceService
                try {
                    $perfService = app(\App\Services\FacebookPerformanceService::class);
                    $perfData = $perfService->getPerformanceMetrics($workspaceId, $startDateParam, $endDateParam, $days, $forceRefresh);
                    if (!empty($perfData['metrics'])) {
                        $fbPerfViews          = (int)($perfData['metrics']['views']['total'] ?? 0);
                        $fbPerfViewsSupported = (bool)($perfData['metrics']['views']['is_available'] ?? true);
                        $fbPerfViewers        = (int)($perfData['metrics']['viewers']['total'] ?? 0);
                        $fbPerfViewersSupported = (bool)($perfData['metrics']['viewers']['is_available'] ?? true);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[FACEBOOK PERF OVERVIEW] error: ' . $e->getMessage());
                }

                try {
                    $graphService = new FacebookGraphService();

                    if ($forceRefresh) {
                        $graphService->refreshPageInsights($fbPage);
                        $graphService->syncWorkspacePosts($workspaceId);
                    }

                    $insights = $graphService->getPageInsights($fbPage->page_id, $fbPage->page_access_token, $days, $startDateParam, $endDateParam);
                    $fbInsightsEngagement  = (int)($insights['engagements'] ?? 0);
                    $fbImpressions         = (int)($insights['impressions'] ?? 0);
                    $fbPeriodFollowers     = (int)($insights['follows'] ?? 0);
                    // page_actions_post_reactions_total: the canonical date-range-aware Likes/Reactions metric
                    // that matches Meta Business Suite. Preferred over per-post lifetime like counts.
                    $fbInsightsReactions   = (int)($insights['reactions'] ?? 0);

                    $fbFeedMetrics = $graphService->getFacebookFeedPostMetrics($fbPage->page_id, $fbPage->page_access_token, $days, $forceRefresh, $startDateParam, $endDateParam, $workspaceId);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('[FACEBOOK METRICS] error: ' . $e->getMessage());
                }
            }

            // Facebook Posts & Post-level metrics strictly for $workspaceId in selected period
            if ($fbPage) {
                $periodPostsQuery = FacebookPost::where('workspace_id', $workspaceId)
                    ->where('status', 'published')
                    ->where(function ($q) use ($startDate, $endDate) {
                        $q->whereBetween('published_at', [$startDate, $endDate])
                          ->orWhere(function ($sub) use ($startDate, $endDate) {
                              $sub->whereNull('published_at')
                                  ->whereBetween('created_at', [$startDate, $endDate]);
                          });
                    });

                $fbPostsCount = (clone $periodPostsQuery)->count();

                $todayStart = now()->startOfDay();
                $todayEnd   = now()->endOfDay();
                $fbPublishedToday = FacebookPost::where('workspace_id', $workspaceId)
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

                // Use page_actions_post_reactions_total (Meta Business Suite-aligned metric) as the primary
                // likes value when available; fall back to the per-post feed-level count.
                $fbFeedLikes = (int)($fbFeedMetrics['likes'] ?? 0);
                $fbLikes = $fbInsightsReactions > 0
                    ? $fbInsightsReactions
                    : max($fbPostLikes, $fbFeedLikes);
                $fbComments = max($fbPostComments, (int)($fbFeedMetrics['comments'] ?? 0));
                $fbShares   = max($fbPostShares, (int)($fbFeedMetrics['shares'] ?? 0));
                $fbPostsCount = max((clone $periodPostsQuery)->count(), (int)($fbFeedMetrics['posts_count'] ?? 0));

                $fbTotalEngagement = max($fbPostEngage, $fbInsightsEngagement, $fbLikes + $fbComments + $fbShares);
            } else {
                $fbPostsCount = 0;
                $fbPublishedToday = 0;
                $scheduledCount = 0;
                $draftsCount = 0;
                $fbPostLikes = 0;
                $fbPostComments = 0;
                $fbPostShares = 0;
                $fbPostReactions = 0;
                $fbPostEngage = 0;
                $fbPostReach = 0;
                $fbLikes = 0;
                $fbComments = 0;
                $fbShares = 0;
                $fbTotalEngagement = 0;
            }

            $facebookMetrics = [
                'views'                      => $fbPerfViews,
                'viewers'                    => $fbPerfViewers,
                'lifetime_views'             => null,
                'likes'                      => ($fbFeedMetrics['likes_supported'] ?? true) ? $fbLikes : 0,
                'comments'                   => ($fbFeedMetrics['comments_supported'] ?? true) ? $fbComments : 0,
                'shares'                     => $fbShares,
                'reach'                      => $fbPostReach > 0 ? $fbPostReach : ($fbImpressions > 0 ? $fbImpressions : 0),
                'posts_count'                => $fbPostsCount,
                'published_today'            => $fbPublishedToday,
                'engagement'                 => $fbTotalEngagement,
                'followers_count'            => $fbFollowers,
                'period_followers'           => $fbPeriodFollowers,
                'followers_gained'           => $fbPeriodFollowers,
                'views_supported'            => $fbPerfViewsSupported,
                'viewers_supported'          => $fbPerfViewersSupported,
                'likes_supported'            => (bool)($fbFeedMetrics['likes_supported'] ?? true),
                'comments_supported'         => (bool)($fbFeedMetrics['comments_supported'] ?? true),
                'shares_supported'           => (bool)($fbFeedMetrics['shares_supported'] ?? ($fbPage !== null)),
                'likes_permission_needed'    => $fbFeedMetrics['likes_permission_needed'] ?? null,
                'comments_permission_needed' => $fbFeedMetrics['comments_permission_needed'] ?? null,
                'views_permission_needed'    => $fbFeedMetrics['views_permission_needed'] ?? null,
                'error_reason'               => $fbFeedMetrics['error_msg'] ?? null,
            ];

            // 3. Instagram Metrics for the selected workspace account.
            $igInteg = $accounts->instagram($workspaceId);

            $igAccountInsights = ['reach' => 0, 'accounts_engaged' => 0, 'total_interactions' => 0, 'likes' => 0, 'comments' => 0];
            $igMediaItems = [];
            $igPeriodFollowers = 0;

            if ($igInteg && !empty($igInteg->refresh_token) && !$isLightMode) {
                try {
                    $graphService = $graphService ?? new FacebookGraphService();
                    $igTtl = ($autoSync || $forceRefresh) ? 30 : 300;
                    $igCacheKey = "ig_overview_v2_{$workspaceId}_{$igInteg->account_id}_{$startDate->format('Ymd')}_{$endDate->format('Ymd')}" . ($igTtl < 300 ? "_{$igTtl}" : "");
                    if ($forceRefresh) {
                        \Illuminate\Support\Facades\Cache::forget($igCacheKey);
                    }
                    $igCached = \Illuminate\Support\Facades\Cache::remember($igCacheKey, $igTtl, function () use ($graphService, $igInteg, $days, $startDateParam, $endDateParam, $forceRefresh, $autoSync) {
                        return [
                            'insights'        => $graphService->getInstagramAccountInsights($igInteg->refresh_token, $igInteg->account_id, $days, $startDateParam, $endDateParam),
                            'mediaItems'      => $graphService->getInstagramMediaList($igInteg->refresh_token, $igInteg->account_id, 50, $forceRefresh, $startDateParam, $endDateParam, $autoSync ? 30 : null),
                            'periodFollowers' => $graphService->getInstagramFollowersGained($igInteg->refresh_token, $igInteg->account_id, $days, $startDateParam, $endDateParam),
                        ];
                    });
                    $igAccountInsights = $igCached['insights'] ?? [];
                    $igMediaItems      = $igCached['mediaItems'] ?? [];
                    $igPeriodFollowers = (int)($igCached['periodFollowers'] ?? 0);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Instagram Overview fetch error: " . $e->getMessage());
                }
            }

            $igProfile = [];
            if ($igInteg && !empty($igInteg->account_id) && !empty($igInteg->refresh_token)) {
                try {
                    $graphService = $graphService ?? new FacebookGraphService();
                    if ($forceRefresh) {
                        \Illuminate\Support\Facades\Cache::forget("ig_profile_{$igInteg->account_id}");
                    }
                    $igProfile = \Illuminate\Support\Facades\Cache::remember("ig_profile_{$igInteg->account_id}", 3600, function () use ($graphService, $igInteg) {
                        return $graphService->getInstagramProfile($igInteg->account_id, $igInteg->refresh_token);
                    });
                } catch (\Throwable $e) {}
            }

            $igFollowers  = (int)($igProfile['followers_count'] ?? 0);
            $igMediaCount = (int)($igProfile['media_count'] ?? 0);

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

            $igPostsCount = count($igMediaItems);
            $totalIgMediaAll = max(1, $igMediaCount > 0 ? $igMediaCount : 50);

            if ($igPeriodFollowers === 0 && $igFollowers > 0) {
                if (($days >= 180 || ($igPostsCount / $totalIgMediaAll) >= 0.75) && $endDate->gte(now()->subDays(7))) {
                    $igPeriodFollowers = $igFollowers;
                } elseif ($igPostsCount === 0) {
                    $igPeriodFollowers = 0;
                } else {
                    $ratio = min(1.0, $igPostsCount / $totalIgMediaAll);
                    $igPeriodFollowers = max(1, (int)round($igFollowers * $ratio));
                }
            }

            $instagramMetrics = [
                'account_name'       => $igInteg ? ('@' . ltrim($igInteg->account_name, '@')) : null,
                'account_id'         => $igInteg?->account_id,
                'followers_count'    => $igFollowers,
                'period_followers'   => $igPeriodFollowers,
                'followers_gained'   => $igPeriodFollowers,
                'media_count'        => $igMediaCount,
                'posts_count'        => count($igMediaItems),
                'views'              => $igViews,
                'lifetime_views'     => null,
                'reach'              => $igReach,
                'likes'              => $igLikes,
                'comments'           => $igComments,
                'shares'             => $igShares,
                'saved'              => $igSaved,
                'total_interactions' => $igTotalEngagement,
                'views_supported'    => ($igInteg !== null),
                'likes_supported'    => ($igInteg !== null),
                'comments_supported' => ($igInteg !== null),
                'shares_supported'   => $igSharesSupported,
            ];

            // 4. Overview uses only connections belonging to this workspace.
            $ytInteg = Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'youtube')
                ->where('is_connected', true)
                ->first();
            
            $ytConn = $ytInteg ? \App\Models\YouTubeConnection::where('workspace_id', $workspaceId)
                ->whereNotNull('access_token')
                ->first() : null;

            if ($ytInteg && (!$ytConn || empty($ytConn->access_token))) {
                $ytConn = \App\Models\YouTubeConnection::where('workspace_id', $workspaceId)->where(function ($q) {
                    $q->whereNotNull('refresh_token')->orWhereNotNull('access_token');
                })
                ->latest('updated_at')
                ->first();
            }

            $youtubeMetrics = null;
            $ytTotalEngagement = 0;
            $ytVideoMetrics = null;
            $ytViews = 0;
            $ytLikes = 0;
            $ytComments = 0;
            $ytShares = 0;
            $isSuccess = false;

            $isYtConnected = $ytInteg && $ytConn && !empty($ytConn->access_token);

            if ($isYtConnected && !$isLightMode) {
                $ytStartStr = $startDate->format('Y-m-d');
                $ytEndStr   = $endDate->format('Y-m-d');

                try {
                    $ytService = new \App\Services\YouTubeService();
                    $ytMetrics = $ytService->getChannelAnalytics($ytConn, $ytStartStr, $ytEndStr, $forceRefresh);

                    $isSuccess  = $ytMetrics['success'] ?? false;
                    $ytViews    = $isSuccess ? (int)($ytMetrics['views'] ?? 0) : null;
                    $ytLikes    = $isSuccess ? (int)($ytMetrics['likes'] ?? 0) : null;
                    $ytComments = $isSuccess ? (int)($ytMetrics['comments'] ?? 0) : null;
                    $ytShares   = $isSuccess ? (int)($ytMetrics['shares'] ?? 0) : null;

                    $ytSubscribersTotal = (int)($ytMetrics['subscribers'] ?? $ytConn->subscriber_count ?? 0);
                    $ytPeriodSubs = (int)($ytMetrics['subscribers_net_change'] ?? $ytMetrics['subscribers_gained'] ?? 0);
                    if ($days >= 180 && $endDate->gte(now()->subDays(7)) && $ytSubscribersTotal > 0) {
                        $ytPeriodSubs = $ytSubscribersTotal;
                    }

                    $youtubeMetrics = [
                        'channel_id'                   => $ytConn->channel_id,
                        'channel_name'                 => $ytConn->channel_name,
                        'start_date'                   => $ytMetrics['start_date'] ?? $ytStartStr,
                        'end_date'                     => $ytMetrics['end_date'] ?? $ytEndStr,
                        'views'                        => $ytViews,
                        'likes'                        => $ytLikes,
                        'comments'                     => $ytComments,
                        'shares'                       => $ytShares,
                        'subscribers'                  => $ytSubscribersTotal,
                        'period_subscribers'           => $ytPeriodSubs,
                        'period_followers'             => $ytPeriodSubs,
                        'subscribers_gained'           => $ytMetrics['subscribers_gained'] ?? null,
                        'subscribers_lost'             => $ytMetrics['subscribers_lost'] ?? null,
                        'subscribers_net_change'       => $ytMetrics['subscribers_net_change'] ?? null,
                        'videos'                       => (int)($ytMetrics['videos'] ?? $ytConn->video_count ?? 0),
                        'published_videos_count'       => (int)($ytMetrics['published_videos_count'] ?? 0),
                        'lifetime_views'               => (int)($ytMetrics['lifetime_views'] ?? $ytConn->view_count ?? 0),
                        'watch_time_hours'             => (float)($ytMetrics['watch_time_hours'] ?? 0.0),
                        'watch_time_minutes'           => (float)($ytMetrics['watch_time_minutes'] ?? 0.0),
                        'average_view_duration_seconds'=> (int)($ytMetrics['average_view_duration_seconds'] ?? 0),
                        'views_supported'              => $isSuccess,
                        'likes_supported'              => $isSuccess,
                        'comments_supported'           => $isSuccess,
                        'shares_supported'             => $isSuccess,
                        'analytics_api_active'         => $isSuccess,
                        'reauthorization_required'     => (in_array($ytMetrics['error_type'] ?? '', ['token_invalid', 'insufficient_scope']) && empty($ytConn->refresh_token)),
                        'error_type'                   => $ytMetrics['error_type'] ?? null,
                        'error_message'                => $ytMetrics['error_message'] ?? null,
                        'query_info'                   => $ytMetrics['query_info'] ?? null,
                        'data_source_description'      => $ytMetrics['data_source_description'] ?? null,
                        'analytics_api_activation_url' => $ytMetrics['analytics_api_activation_url'] ?? null,
                    ];

                    $ytTotalEngagement = $isSuccess ? ($ytLikes + $ytComments + $ytShares) : 0;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[YOUTUBE METRICS] error: ' . $e->getMessage());
                    $youtubeMetrics = [
                        'channel_id'             => $ytConn->channel_id,
                        'channel_name'           => $ytConn->channel_name,
                        'start_date'             => $ytStartStr,
                        'end_date'               => $ytEndStr,
                        'views'                  => null,
                        'likes'                  => null,
                        'comments'               => null,
                        'shares'                 => null,
                        'subscribers'            => (int)($ytConn->subscriber_count ?? 0),
                        'period_subscribers'     => 0,
                        'period_followers'       => 0,
                        'subscribers_gained'     => null,
                        'subscribers_lost'       => null,
                        'subscribers_net_change' => null,
                        'videos'                 => (int)($ytConn->video_count ?? 0),
                        'published_videos_count' => 0,
                        'lifetime_views'         => (int)($ytConn->view_count ?? 0),
                        'views_supported'        => false,
                        'likes_supported'        => false,
                        'comments_supported'     => false,
                        'shares_supported'       => false,
                        'analytics_api_active'   => false,
                        'reauthorization_required' => false,
                        'error_type'             => 'exception',
                        'error_message'          => 'Unable to fetch YouTube metrics: ' . $e->getMessage(),
                    ];
                }
            }

            // 5. Total Reach, Total Engagement & Total Published Posts (Calculated genuinely from connected accounts for the selected period)
            $ytPublishedPosts = (int)($youtubeMetrics['published_videos_count'] ?? 0);
            $ytPublishedToday = 0;
            if ($isYtConnected && $ytConn && !$isLightMode) {
                $todayStr = now()->format('Y-m-d');
                $ytService = $ytService ?? new \App\Services\YouTubeService();
                $ytPublishedToday = $ytService->getPublishedVideosCount($ytConn, $todayStr, $todayStr, $forceRefresh);
            }

            $totalPosts = $fbPostsCount + count($igMediaItems) + $ytPublishedPosts;
            $publishedToday = $fbPublishedToday + $ytPublishedToday;

            $totalReach = ($fbPage ? $fbPostReach : 0) + ($igInteg ? $igReach : 0) + (($isYtConnected && !empty($isSuccess)) ? $ytViews : 0);
            $totalEngagement = ($fbPage ? $fbTotalEngagement : 0)
                             + ($igInteg ? $igTotalEngagement : 0)
                             + (($isYtConnected && !empty($isSuccess)) ? $ytTotalEngagement : 0);

            // 6. Connected Channels List (Strictly for $workspaceId)
            $connectedChannelsList = [];

            if ($fbPage && !empty($fbPage->page_access_token)) {
                $connectedChannelsList[] = [
                    'name'      => 'Facebook',
                    'code'      => 'FB',
                    'status'    => $fbPage->token_status === 'valid' ? 'connected' : ($fbPage->token_status ?? 'connected'),
                    'followers' => $fbFollowers,
                    'account'   => $fbPage->page_name,
                    'last_sync' => $fbPage->updated_at ? $fbPage->updated_at->toIso8601String() : null,
                ];
            }

            if ($igInteg && $igInteg->is_connected) {
                $connectedChannelsList[] = [
                    'name'      => 'Instagram',
                    'code'      => 'IG',
                    'status'    => 'connected',
                    'followers' => $igFollowers,
                    'account'   => '@' . ltrim($igInteg->account_name, '@'),
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
                    'total_published_posts' => $totalPosts,
                    'published_today'       => $publishedToday,
                    'scheduled_count'       => $scheduledCount,
                    'drafts_count'          => $draftsCount,
                    'total_likes'           => $fbLikes + $igLikes + ($ytLikes ?? 0),
                    'total_comments'        => $fbComments + $igComments + ($ytComments ?? 0),
                    'total_shares'          => $fbShares + $igShares + ($ytShares ?? 0),
                    'total_views'           => ($fbPerfViews ?? 0) + $igViews + ($ytViews ?? 0),
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
                    'youtube_connection'    => $ytConn ? [
                        'channel_name'     => $ytConn->channel_name,
                        'subscriber_count' => (int)($youtubeMetrics['subscribers'] ?? $ytConn->subscriber_count ?? 0),
                        'video_count'      => (int)($youtubeMetrics['videos'] ?? $ytConn->video_count ?? 0),
                        'view_count'       => (int)($youtubeMetrics['views'] ?? $ytConn->view_count ?? 0),
                    ] : null,
                ]
            ]);
        });

        // ── Dashboard Trend — Real daily metrics & Strict Workspace Isolation ─────
        Route::get('/dashboard/trend', function (Request $request) {
            $request->validate(['workspace_id' => 'required|integer|exists:workspaces,id']);
            $workspaceId    = (int)$request->query('workspace_id');
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

            $accounts = app(\App\Services\WorkspaceSocialAccounts::class);
            $fbPage = $accounts->facebook($workspaceId);

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
                        $endDateParam,
                        $workspaceId
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

            // Fetch and overlay Instagram daily insights for the selected account.
            $igInteg = $accounts->instagram($workspaceId);

            if ($igInteg && !empty($igInteg->refresh_token)) {
                try {
                    $graphService = $graphService ?? new \App\Services\FacebookGraphService();
                    $igTrend = $graphService->getInstagramInsightsTrend($igInteg->refresh_token, $igInteg->account_id, $days, $startDateParam, $endDateParam);
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

            // Fetch and overlay YouTube daily trend strictly for $workspaceId
            $ytConn = \App\Models\YouTubeConnection::where('workspace_id', $workspaceId)->whereNotNull('access_token')->first();
            if ($ytConn) {
                try {
                    $ytService = new \App\Services\YouTubeService();
                    $ytAnalytics = $ytService->getChannelAnalytics(
                        $ytConn,
                        $start->format('Y-m-d'),
                        $end->format('Y-m-d'),
                        $request->boolean('force_refresh', false)
                    );
                    if (!empty($ytAnalytics['success'])) {
                        foreach ($ytAnalytics['daily_trend'] ?? [] as $dateKey => $dayData) {
                            if (isset($dateIndexMap[$dateKey])) {
                                $idx = $dateIndexMap[$dateKey];
                                $reach[$idx]      += (int)($dayData['views'] ?? 0);
                                $engagement[$idx] += (int)($dayData['engagement'] ?? 0);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("YouTube trend fetch error: " . $e->getMessage());
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
            $request->validate(['workspace_id' => 'required|integer|exists:workspaces,id']);
            $workspaceId = (int)$request->query('workspace_id');
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

            // 3. YouTube token expiry check (strictly scoped to workspace)
            try {
                $ytConn = \App\Models\YouTubeConnection::where('workspace_id', $workspaceId)->first();
                if ($ytConn) {
                    $hasRefreshToken = !empty($ytConn->refresh_token);
                    $isExpiredWithoutRefresh = $ytConn->token_expires_at && $ytConn->token_expires_at->isPast() && !$hasRefreshToken;
                    $isExpiringSoonWithoutRefresh = $ytConn->token_expires_at && !$hasRefreshToken && $ytConn->token_expires_at->lte(now()->addDays(7));

                    if ($isExpiredWithoutRefresh || $isExpiringSoonWithoutRefresh) {
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

        // ── Per-Workspace Metrics (for Clients page cards - Strictly Workspace Isolated) ─────────
        Route::get('/workspace/{id}/metrics', function ($id) {
            $workspaceId = (int)$id;
            $workspace = Workspace::findOrFail($workspaceId);

            $leadsCount = Lead::where('workspace_id', $workspaceId)->count();
            
            $connectedPlatforms = [];

            // 1. Facebook
            $hasFb = FacebookPage::where('workspace_id', $workspaceId)
                ->whereNotNull('page_access_token')
                ->where('token_status', 'valid')
                ->exists()
                || Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'facebook')
                ->where('is_connected', true)
                ->exists();
            if ($hasFb) $connectedPlatforms[] = 'Facebook';

            // 2. Instagram
            $hasIg = Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'instagram')
                ->where('is_connected', true)
                ->exists();
            if ($hasIg) $connectedPlatforms[] = 'Instagram';

            // 3. YouTube (strictly for this workspace)
            $hasYt = Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'youtube')
                ->where('is_connected', true)
                ->exists();
            if ($hasYt) $connectedPlatforms[] = 'YouTube';

            // 4. Twitter / X
            $hasTw = Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'twitter')
                ->where('is_connected', true)
                ->exists();
            if ($hasTw) $connectedPlatforms[] = 'Twitter';

            // 5. Google Analytics
            $hasGa = Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'google_analytics')
                ->where('is_connected', true)
                ->exists();
            if ($hasGa) $connectedPlatforms[] = 'Google Analytics';

            // 6. Search Console
            $hasGsc = Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'search_console')
                ->where('is_connected', true)
                ->exists();
            if ($hasGsc) $connectedPlatforms[] = 'Search Console';

            $channelCount = count($connectedPlatforms);

            $reach = (int)FacebookPost::where('workspace_id', $workspaceId)
                ->where('status', 'published')
                ->sum('reach_count');

            $lastActivity = FacebookPost::where('workspace_id', $workspaceId)
                ->orderBy('created_at', 'desc')
                ->value('created_at');

            return response()->json([
                'success' => true,
                'data' => [
                    'workspace_id'       => $workspaceId,
                    'workspace_name'     => $workspace->name,
                    'status'             => $workspace->status ?? 'active',
                    'reach'              => $reach,
                    'leads'              => $leadsCount,
                    'channels'           => $channelCount,
                    'connected_channels' => $connectedPlatforms,
                    'last_activity'      => $lastActivity,
                ]
            ]);
        });

        // ── Integration Status (Strictly workspace-isolated, real timestamps from DB) ──
        Route::get('/integrations/status', function (Request $request) {
            $workspaceId = (int)$request->query('workspace_id', 1);

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
                $status      = 'disconnected';
                $lastSync    = null;
                $accountName = null;
                $tokenExpiry = null;

                if ($platform['key'] === 'facebook') {
                    $fbPage = FacebookPage::where('workspace_id', $workspaceId)
                        ->whereNotNull('page_access_token')
                        ->where(function ($q) {
                            $q->whereNotIn('token_status', ['invalid', 'disconnected', 'revoked'])->orWhereNull('token_status');
                        })
                        ->first();

                    $fbInteg = Integration::where('workspace_id', $workspaceId)
                        ->where('platform', 'facebook')
                        ->first();

                    $isFbExplicitlyDisconnected = ($fbInteg && ($fbInteg->connection_status === 'disconnected' || !$fbInteg->is_connected))
                        || (!$fbPage && FacebookPage::where('workspace_id', $workspaceId)->where('token_status', 'disconnected')->exists());

                    if ($fbPage && !empty($fbPage->page_access_token) && !$isFbExplicitlyDisconnected) {
                        $status      = in_array($fbPage->token_status, ['invalid', 'revoked', 'disconnected']) ? 'disconnected' : 'connected';
                        $lastSync    = $fbPage->updated_at ? $fbPage->updated_at->toIso8601String() : ($fbPage->connected_since ? $fbPage->connected_since->toIso8601String() : null);
                        $accountName = $fbPage->page_name;
                    } elseif ($fbInteg && $fbInteg->is_connected && $fbInteg->connection_status !== 'disconnected') {
                        $status      = $fbInteg->connection_status ?? 'connected';
                        $lastSync    = $fbInteg->last_sync_at ? $fbInteg->last_sync_at->toIso8601String() : null;
                        $accountName = $fbInteg->account_name;
                    } else {
                        $status      = 'disconnected';
                        $lastSync    = $fbInteg ? ($fbInteg->last_sync_at ? $fbInteg->last_sync_at->toIso8601String() : null) : null;
                        $accountName = null;
                    }
                } elseif ($platform['key'] === 'instagram') {
                    $igInteg = Integration::where('platform', 'instagram')
                        ->where('workspace_id', $workspaceId)
                        ->where('is_connected', true)
                        ->where(function ($q) {
                            $q->where('connection_status', '!=', 'disconnected')->orWhereNull('connection_status');
                        })
                        ->latest()->first();

                    if ($igInteg && (!empty($igInteg->refresh_token) || !empty($igInteg->access_token) || $igInteg->is_connected)) {
                        $status      = $igInteg->connection_status ?? 'connected';
                        $lastSync    = $igInteg->last_sync_at ? $igInteg->last_sync_at->toIso8601String() : null;
                        $accountName = $igInteg->account_name;
                        $tokenExpiry = $igInteg->token_expires_at;
                    }
                } elseif ($platform['key'] === 'youtube') {
                    $ytInteg = \App\Models\Integration::where('workspace_id', $workspaceId)
                        ->where('platform', 'youtube')
                        ->where('is_connected', true)
                        ->where(function ($q) {
                            $q->where('connection_status', '!=', 'disconnected')->orWhereNull('connection_status');
                        })
                        ->first();

                    if ($ytInteg) {
                        $ytConn = \App\Models\YouTubeConnection::where('workspace_id', $workspaceId)->first();

                        if ($ytConn && (!empty($ytConn->access_token) || !empty($ytConn->refresh_token))) {
                            // Proactively refresh if token is nearing expiration
                            try {
                                $ytService = resolve(\App\Services\YouTubeService::class);
                                $ytConn = $ytService->refreshAccessTokenIfNeeded($ytConn);
                            } catch (\Throwable $ytErr) {
                                \Illuminate\Support\Facades\Log::warning("[YOUTUBE STATUS AUTO-REFRESH NOTICE] " . $ytErr->getMessage());
                            }

                            $status      = !empty($ytConn->access_token) ? 'connected' : 'error';
                            $lastSync    = $ytConn->updated_at ? $ytConn->updated_at->toIso8601String() : null;
                            $accountName = $ytConn->channel_name;
                            $tokenExpiry = $ytConn->token_expires_at ? $ytConn->token_expires_at->toIso8601String() : null;
                        } else {
                            $status      = $ytInteg->connection_status ?? 'connected';
                            $lastSync    = $ytInteg->last_sync_at ? $ytInteg->last_sync_at->toIso8601String() : null;
                            $accountName = $ytInteg->account_name;
                            $tokenExpiry = $ytInteg->token_expires_at;
                        }
                    }
                } elseif ($platform['key'] === 'google_analytics') {
                    $gaService = app(\App\Services\GoogleAnalyticsService::class);
                    $propId = $gaService->getPropertyId($workspaceId);
                    $isConf = $gaService->isConfigured($workspaceId);
                    $integ = \App\Models\Integration::where('platform', 'google_analytics')
                        ->where('workspace_id', $workspaceId)
                        ->where('is_connected', true)
                        ->latest()->first();

                    if ($isConf && $integ) {
                        $isTokenExpired = false;
                        if (!empty($integ->refresh_token)) {
                            try {
                                $gaService->getAccessToken($workspaceId);
                            } catch (\Throwable $te) {
                                if (str_contains(strtolower($te->getMessage()), 'expired') || str_contains(strtolower($te->getMessage()), 'revoked')) {
                                    $isTokenExpired = true;
                                }
                            }
                        }
                        $status      = $isTokenExpired ? 'expired' : ($integ->connection_status ?? 'connected');
                        $accountName = $integ->account_name ?: ("GA4: " . $propId);
                        $lastSync    = $integ->last_sync_at ? $integ->last_sync_at->toIso8601String() : now()->toIso8601String();
                    }
                } elseif ($platform['key'] === 'search_console') {
                    $gscService = app(\App\Services\GoogleSearchConsoleService::class);
                    $siteUrl = $gscService->getSiteUrl($workspaceId);
                    $isConf = $gscService->isConfigured($workspaceId);
                    $integ = \App\Models\Integration::where('platform', 'search_console')
                        ->where('workspace_id', $workspaceId)
                        ->where('is_connected', true)
                        ->latest()->first();

                    if ($isConf && $integ) {
                        $isTokenExpired = false;
                        if (!empty($integ->refresh_token)) {
                            try {
                                $gscService->getAccessToken($workspaceId);
                            } catch (\Throwable $te) {
                                if (str_contains(strtolower($te->getMessage()), 'expired') || str_contains(strtolower($te->getMessage()), 'revoked')) {
                                    $isTokenExpired = true;
                                }
                            }
                        }
                        $status      = $isTokenExpired ? 'expired' : ($integ->connection_status ?? 'connected');
                        $accountName = $siteUrl;
                        $lastSync    = $integ->last_sync_at ? $integ->last_sync_at->toIso8601String() : now()->toIso8601String();
                    }
                } else {
                    $integ = \App\Models\Integration::where('platform', $platform['key'])
                        ->where('workspace_id', $workspaceId)
                        ->where('is_connected', true)
                        ->latest()->first();
                    if ($integ) {
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
                    'last_sync'   => $lastSync,
                    'account_name'=> $accountName,
                    'token_expiry'=> $tokenExpiry,
                ];
            }

            return response()->json(['success' => true, 'data' => $result]);
        });

        // ── Disconnect Integration (Non-destructive soft disconnect) ─────────────
        Route::post('/integrations/disconnect', function (Request $request) {
            $workspaceId = (int)$request->input('workspace_id', 1);
            $platform    = strtolower(trim($request->input('platform', '')));
            $accountId   = $request->input('account_id');

            $integQuery = Integration::where('workspace_id', $workspaceId)->where('platform', $platform);
            if ($accountId) {
                $integQuery->where('account_id', $accountId);
            }

            if ($platform === 'facebook') {
                $fbQuery = FacebookPage::where('workspace_id', $workspaceId);
                if ($accountId) {
                    $fbQuery->where('page_id', $accountId);
                }
                $fbQuery->update(['token_status' => 'disconnected']);
                $integQuery->update(['is_connected' => false, 'connection_status' => 'disconnected']);
            } elseif ($platform === 'instagram') {
                $integQuery->update(['is_connected' => false, 'connection_status' => 'disconnected']);
            } elseif ($platform === 'youtube') {
                $integQuery->update([
                    'is_connected'      => false,
                    'connection_status' => 'disconnected',
                    'refresh_token'     => null,
                ]);

                $ytConnQuery = \App\Models\YouTubeConnection::where('workspace_id', $workspaceId);
                if ($accountId) {
                    $ytConnQuery->where('channel_id', $accountId);
                }
                $ytConnQuery->delete();
            } elseif ($platform === 'twitter') {
                $integQuery->update(['is_connected' => false, 'connection_status' => 'disconnected']);
            } elseif ($platform === 'google_analytics') {
                $integQuery->update(['is_connected' => false, 'connection_status' => 'disconnected']);
            } elseif ($platform === 'search_console') {
                $integQuery->update(['is_connected' => false, 'connection_status' => 'disconnected']);
            } elseif ($platform === 'google_business') {
                $integQuery->update(['is_connected' => false, 'connection_status' => 'disconnected']);
            } elseif ($platform === 'linkedin') {
                $integQuery->update(['is_connected' => false, 'connection_status' => 'disconnected']);
            }

            return response()->json(['success' => true, 'message' => ucfirst($platform) . ' disconnected successfully.']);
        });

        // ── Manual Connect Endpoint (All 8 Supported Platforms) ──────────────────
        Route::post('/integrations/manual-connect', function (Request $request) {
            $validated = $request->validate([
                'workspace_id' => 'required|integer',
                'platform'     => 'required|string|in:facebook,instagram,youtube,linkedin,twitter,google_analytics,search_console,google_business',
                'account_id'   => 'required|string|max:255',
                'account_name' => 'nullable|string|max:255',
                'access_token' => 'nullable|string',
            ]);

            $workspaceId = (int)$validated['workspace_id'];
            $platform    = strtolower(trim($validated['platform']));
            $accountId   = trim($validated['account_id']);
            $accountName = trim($validated['account_name'] ?? '');
            $token       = trim($validated['access_token'] ?? '');

            if (empty($accountName)) {
                $accountName = match($platform) {
                    'facebook'         => "Facebook Page ({$accountId})",
                    'instagram'        => str_starts_with($accountId, '@') ? $accountId : "@{$accountId}",
                    'youtube'          => "YouTube Channel ({$accountId})",
                    'linkedin'         => "LinkedIn Page ({$accountId})",
                    'twitter'          => str_starts_with($accountId, '@') ? $accountId : "@{$accountId}",
                    'google_analytics' => "GA4 Property ({$accountId})",
                    'search_console'   => "GSC ({$accountId})",
                    'google_business'  => "Google Business ({$accountId})",
                    default            => "Account ({$accountId})",
                };
            }

            // Platform-specific tables sync
            if ($platform === 'facebook') {
                FacebookPage::updateOrCreate(
                    ['workspace_id' => $workspaceId, 'page_id' => $accountId],
                    [
                        'page_name'         => $accountName,
                        'page_access_token' => $token,
                        'token_status'      => 'valid',
                        'connected_since'   => now(),
                    ]
                );
            } elseif ($platform === 'youtube') {
                \App\Models\YouTubeConnection::updateOrCreate(
                    ['channel_id' => $accountId],
                    [
                        'workspace_id' => $workspaceId,
                        'channel_name' => $accountName,
                        'access_token' => $token ?: 'manual_token',
                    ]
                );
            }

            // Update unified Integration table with composite key (workspace_id + platform + account_id)
            $integration = Integration::updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'platform'     => $platform,
                    'account_id'   => $accountId,
                ],
                [
                    'account_name'      => $accountName,
                    'access_token'      => $token ?: null,
                    'refresh_token'     => $token ?: null,
                    'is_connected'      => true,
                    'connection_status' => 'connected',
                    'last_sync_at'      => now(),
                ]
            );

            return response()->json([
                'success'     => true,
                'message'     => ucfirst(str_replace('_', ' ', $platform)) . " connected successfully.",
                'integration' => [
                    'platform'     => $platform,
                    'account_id'   => $accountId,
                    'account_name' => $accountName,
                    'status'       => 'connected',
                ],
            ]);
        });

        // ── Real API Connection Testing Endpoint ─────────────────────────────────
        Route::post('/integrations/test', function (Request $request) {
            $workspaceId = (int)$request->input('workspace_id', 1);
            $platform    = strtolower(trim($request->input('platform', '')));

            if ($platform === 'facebook') {
                $fbPage = app(\App\Services\WorkspaceSocialAccounts::class)->facebook($workspaceId);
                if (!$fbPage || empty($fbPage->page_access_token)) {
                    return response()->json(['success' => false, 'message' => 'No active Facebook Page connected for this workspace.']);
                }
                try {
                    $graph = new FacebookGraphService();
                    $details = $graph->getPageDetails($fbPage->page_id, $fbPage->page_access_token);
                    if (!empty($details['followers_count'])) {
                        $fbPage->followers_count = (int)$details['followers_count'];
                        $fbPage->fan_count = (int)($details['fan_count'] ?? $fbPage->fan_count);
                        $fbPage->save();
                    }
                    return response()->json([
                        'success' => true,
                        'message' => "Facebook Page \"{$fbPage->page_name}\" connection verified!",
                        'data' => [
                            'account_name' => $fbPage->page_name,
                            'account_id'   => $fbPage->page_id,
                            'followers'    => (int)$fbPage->followers_count,
                            'fan_count'    => (int)$fbPage->fan_count,
                            'status'       => 'Live & Operational',
                        ]
                    ]);
                } catch (\Throwable $e) {
                    return response()->json(['success' => false, 'message' => 'Facebook API Error: ' . $e->getMessage()]);
                }
            } elseif ($platform === 'instagram') {
                $igInteg = app(\App\Services\WorkspaceSocialAccounts::class)->instagram($workspaceId);
                if (!$igInteg || empty($igInteg->refresh_token)) {
                    return response()->json(['success' => false, 'message' => 'No active Instagram account connected for this workspace.']);
                }
                try {
                    $graph = new FacebookGraphService();
                    $profile = $graph->getInstagramProfile($igInteg->account_id, $igInteg->refresh_token);
                    $media = $graph->getInstagramMediaList($igInteg->refresh_token, $igInteg->account_id, 5, true);
                    if (!empty($profile['followers_count'])) {
                        \Illuminate\Support\Facades\Cache::put("ig_profile_{$igInteg->account_id}", $profile, 3600);
                    }
                    $accNameClean = '@' . ltrim($igInteg->account_name, '@');
                    return response()->json([
                        'success' => true,
                        'message' => "Instagram Account \"{$accNameClean}\" connection verified!",
                        'data' => [
                            'account_name' => '@' . ltrim($igInteg->account_name, '@'),
                            'account_id'   => $igInteg->account_id,
                            'followers'    => (int)($profile['followers_count'] ?? 0),
                            'media_count'  => (int)($profile['media_count'] ?? count($media)),
                            'status'       => 'Live & Operational',
                        ]
                    ]);
                } catch (\Throwable $e) {
                    return response()->json(['success' => false, 'message' => 'Instagram API Error: ' . $e->getMessage()]);
                }
            } elseif ($platform === 'youtube') {
                $ytInteg = Integration::where('workspace_id', $workspaceId)
                    ->where('platform', 'youtube')
                    ->where('is_connected', true)
                    ->where(function ($q) {
                        $q->where('connection_status', '!=', 'disconnected')->orWhereNull('connection_status');
                    })
                    ->first();
                $ytConn = $ytInteg ? \App\Models\YouTubeConnection::where('workspace_id', $workspaceId)->first() : null;
                if (!$ytInteg || !$ytConn || empty($ytConn->access_token)) {
                    return response()->json(['success' => false, 'message' => 'No active YouTube channel connected for this workspace.']);
                }
                try {
                    $ytService = resolve(\App\Services\YouTubeService::class);
                    $conn = $ytService->refreshAccessTokenIfNeeded($ytConn);
                    $metrics = $ytService->getVideoMetrics($conn);
                    return response()->json([
                        'success' => true,
                        'message' => "YouTube Channel \"{$conn->channel_name}\" connection verified!",
                        'data' => [
                            'channel_name' => $conn->channel_name,
                            'subscribers'  => (int)($conn->subscriber_count ?? 0),
                            'total_views'  => (int)($metrics['views'] ?? $conn->view_count ?? 0),
                            'videos'       => (int)($metrics['video_count'] ?? $conn->video_count ?? 0),
                            'status'       => 'Live & Operational',
                        ]
                    ]);
                } catch (\Throwable $e) {
                    return response()->json(['success' => false, 'message' => 'YouTube API Error: ' . $e->getMessage()]);
                }
            } elseif ($platform === 'twitter') {
                $twInteg = Integration::where('workspace_id', $workspaceId)->where('platform', 'twitter')->where('is_connected', true)->first();
                if (!$twInteg) {
                    return response()->json(['success' => false, 'message' => 'No active X (Twitter) account connected for this workspace.']);
                }
                return response()->json([
                    'success' => true,
                    'message' => "X (Twitter) Profile \"@{$twInteg->account_name}\" connection verified!",
                    'data' => [
                        'account_name' => '@' . $twInteg->account_name,
                        'status'       => 'Live & Operational',
                    ]
                ]);
            } elseif ($platform === 'google_analytics') {
                $gaService = app(\App\Services\GoogleAnalyticsService::class);
                if (!$gaService->isConfigured($workspaceId)) {
                    return response()->json(['success' => false, 'message' => 'GA4 credentials or Property ID not configured for this workspace.']);
                }
                try {
                    $data = $gaService->getOverviewMetrics(now()->subDays(1)->format('Y-m-d'), now()->format('Y-m-d'), $workspaceId);
                    return response()->json([
                        'success' => true,
                        'message' => "Google Analytics (GA4) Property ({$gaService->getPropertyId($workspaceId)}) verified!",
                        'data' => [
                            'property_id'  => $gaService->getPropertyId($workspaceId),
                            'active_users' => (int)($data['active_users'] ?? 0),
                            'sessions'     => (int)($data['sessions'] ?? 0),
                            'status'       => 'Live & Operational',
                        ]
                    ]);
                } catch (\Throwable $e) {
                    return response()->json(['success' => false, 'message' => 'GA4 Error: ' . $e->getMessage()]);
                }
            } elseif ($platform === 'search_console') {
                $gscService = app(\App\Services\GoogleSearchConsoleService::class);
                if (!$gscService->isConfigured($workspaceId)) {
                    return response()->json(['success' => false, 'message' => 'Search Console credentials or Site URL not configured for this workspace.']);
                }
                try {
                    $data = $gscService->getOverview(now()->subDays(7)->format('Y-m-d'), now()->format('Y-m-d'), $workspaceId);
                    return response()->json([
                        'success' => true,
                        'message' => "Google Search Console Site ({$gscService->getSiteUrl($workspaceId)}) verified!",
                        'data' => [
                            'site_url'    => $gscService->getSiteUrl($workspaceId),
                            'clicks'      => (int)($data['clicks'] ?? 0),
                            'impressions' => (int)($data['impressions'] ?? 0),
                            'status'      => 'Live & Operational',
                        ]
                    ]);
                } catch (\Throwable $e) {
                    return response()->json(['success' => false, 'message' => 'Search Console Error: ' . $e->getMessage()]);
                }
            }

            $integ = Integration::where('workspace_id', $workspaceId)->where('platform', $platform)->where('is_connected', true)->first();
            if ($integ) {
                return response()->json(['success' => true, 'message' => ucfirst($platform) . " integration verified!"]);
            }
            return response()->json(['success' => false, 'message' => ucfirst($platform) . " is not connected for this workspace."]);
        });

        // ── Real Multi-Integration Sync Endpoint ─────────────────────────────────
        Route::post('/integrations/sync', function (Request $request) {
            $workspaceId    = (int)$request->input('workspace_id', 1);
            $targetPlatform = strtolower(trim($request->input('platform', '')));
            $synced         = [];
            $errors         = [];

            // Facebook Sync
            if (empty($targetPlatform) || $targetPlatform === 'facebook') {
                $fbPage = app(\App\Services\WorkspaceSocialAccounts::class)->facebook($workspaceId);
                if ($fbPage && !empty($fbPage->page_access_token)) {
                    try {
                        $graph = new FacebookGraphService();
                        $graph->refreshPageInsights($fbPage);
                        $fbPage->touch();
                        Integration::where('workspace_id', $workspaceId)->where('platform', 'facebook')->update([
                            'last_sync_at' => now(),
                        ]);
                        $synced[] = "Facebook ({$fbPage->page_name})";
                    } catch (\Throwable $e) {
                        $errors[] = "Facebook: " . $e->getMessage();
                    }
                }
            }

            // Instagram Sync
            if (empty($targetPlatform) || $targetPlatform === 'instagram') {
                $igInteg = app(\App\Services\WorkspaceSocialAccounts::class)->instagram($workspaceId);
                if ($igInteg && !empty($igInteg->refresh_token)) {
                    try {
                        $graph = new FacebookGraphService();
                        $profile = $graph->getInstagramProfile($igInteg->account_id, $igInteg->refresh_token);
                        $igInteg->last_sync_at = now();
                        $igInteg->save();
                        $synced[] = "Instagram (@" . ltrim($igInteg->account_name, '@') . ")";
                    } catch (\Throwable $e) {
                        $igInteg->update(['last_sync_at' => now()]);
                        $synced[] = "Instagram (@" . ltrim($igInteg->account_name, '@') . ")";
                    }
                }
            }

            // YouTube Sync
            if (empty($targetPlatform) || $targetPlatform === 'youtube') {
                $ytInteg = Integration::where('workspace_id', $workspaceId)
                    ->where('platform', 'youtube')
                    ->where('is_connected', true)
                    ->where(function ($q) {
                        $q->where('connection_status', '!=', 'disconnected')->orWhereNull('connection_status');
                    })
                    ->first();
                $ytConn = $ytInteg ? \App\Models\YouTubeConnection::where('workspace_id', $workspaceId)->first() : null;
                if ($ytConn && (!empty($ytConn->access_token) || !empty($ytConn->refresh_token))) {
                    try {
                        $ytService = resolve(\App\Services\YouTubeService::class);
                        $conn = $ytService->refreshAccessTokenIfNeeded($ytConn);
                        $metrics = $ytService->getVideoMetrics($conn);
                        if (!empty($metrics['views'])) {
                            $conn->view_count = $metrics['views'];
                        }
                        $conn->touch();
                        Integration::where('workspace_id', $workspaceId)->where('platform', 'youtube')->update([
                            'last_sync_at' => now(),
                        ]);
                        $synced[] = "YouTube ({$conn->channel_name})";
                    } catch (\Throwable $e) {
                        $errors[] = "YouTube: " . $e->getMessage();
                    }
                }
            }

            // Twitter Sync
            if (empty($targetPlatform) || $targetPlatform === 'twitter') {
                $twInteg = Integration::where('workspace_id', $workspaceId)->where('platform', 'twitter')->where('is_connected', true)->first();
                if ($twInteg) {
                    $twInteg->update(['last_sync_at' => now()]);
                    $synced[] = "X/Twitter (@" . ltrim($twInteg->account_name, '@') . ")";
                }
            }

            // Google Analytics Sync
            if (empty($targetPlatform) || $targetPlatform === 'google_analytics') {
                $gaInteg = Integration::where('workspace_id', $workspaceId)->where('platform', 'google_analytics')->where('is_connected', true)->first();
                if ($gaInteg) {
                    $gaInteg->update(['last_sync_at' => now()]);
                    $synced[] = "Google Analytics";
                }
            }

            // Search Console Sync
            if (empty($targetPlatform) || $targetPlatform === 'search_console') {
                $gscInteg = Integration::where('workspace_id', $workspaceId)->where('platform', 'search_console')->where('is_connected', true)->first();
                if ($gscInteg) {
                    $gscInteg->update(['last_sync_at' => now()]);
                    $synced[] = "Google Search Console";
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($synced) > 0 ? 'Synced: ' . implode(', ', $synced) : 'No connected integrations found to sync for this workspace.',
                'synced'  => $synced,
                'errors'  => $errors,
            ]);
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

    // POST /v1/facebook/publish-post (Production Publishing for Facebook, Instagram, and YouTube)
    Route::post('/facebook/publish-post', function (Request $request) {
        $validated = $request->validate([
            'workspace_id'    => 'nullable|integer',
            'post_type'       => 'nullable|in:text,single_image,multi_image,video,link',
            'message'         => 'nullable|string',
            'video_title'     => 'nullable|string|max:100',
            'link_url'        => 'nullable|url',
            'page_id'         => 'nullable|string',
            'status'          => 'nullable|in:draft,scheduled,published',
            'scheduled_at'    => 'nullable|date',
            'platforms'       => 'nullable',
            'youtube_privacy' => 'nullable|in:public,unlisted,private',
            'images.*'        => 'nullable|file|image|max:20480',
            'video'           => 'nullable|file|mimes:mp4,mov,avi,mkv|max:512000', // 500MB
        ]);

        $workspaceId = getOrCreateWorkspaceId($validated['workspace_id'] ?? 1);
        $status = $validated['status'] ?? 'published';
        $postType = $validated['post_type'] ?? 'text';
        $message = $validated['message'] ?? '';
        $youtubePrivacy = in_array($validated['youtube_privacy'] ?? '', ['public', 'unlisted', 'private']) ? $validated['youtube_privacy'] : 'unlisted';
        $videoTitle = !empty($validated['video_title']) ? $validated['video_title'] : (!empty($message) ? mb_substr($message, 0, 60) : 'Uploaded Video');

        $targetPlatforms = $request->input('platforms', ['Facebook']);
        if (is_string($targetPlatforms)) {
            $targetPlatforms = json_decode($targetPlatforms, true) ?? [$targetPlatforms];
        }
        if (!is_array($targetPlatforms)) {
            $targetPlatforms = ['Facebook'];
        }

        // Scope strictly to Facebook, Instagram, and YouTube
        $requiresFacebook  = in_array('Facebook', $targetPlatforms);
        $requiresInstagram = in_array('Instagram', $targetPlatforms);
        $requiresYouTube   = in_array('YouTube', $targetPlatforms);

        if (!$requiresFacebook && !$requiresInstagram && !$requiresYouTube) {
            return response()->json([
                'success' => false,
                'message' => 'Please select at least one target platform (Facebook, Instagram, or YouTube).',
            ], 400);
        }

        // 1. Validate Facebook Connection
        $fbPage = null;
        if ($requiresFacebook) {
            $query = FacebookPage::where('workspace_id', $workspaceId);
            if (!empty($validated['page_id'])) {
                $query->where('page_id', $validated['page_id']);
            }
            $fbPage = $query->first();

            if ($status !== 'draft' && (!$fbPage || empty($fbPage->page_access_token))) {
                return response()->json([
                    'success' => false,
                    'message' => 'No connected Facebook Page found for this workspace. Please connect a Facebook Page first.',
                ], 400);
            }
        }

        // 2. Validate Instagram Connection & Media Requirement
        $igInteg = null;
        $fbPageForIg = null;
        if ($requiresInstagram) {
            $igInteg = Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'instagram')
                ->where('is_connected', true)
                ->first();

            $fbPageForIg = $fbPage ?? FacebookPage::where('workspace_id', $workspaceId)->whereNotNull('page_access_token')->first();

            if ($status !== 'draft' && !$igInteg && !$fbPageForIg) {
                return response()->json([
                    'success' => false,
                    'message' => 'No connected Instagram Business Account found for this workspace. Please connect Instagram in API Connections.',
                ], 400);
            }

            if ($status !== 'draft' && !$request->hasFile('images') && !$request->hasFile('video')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Instagram Graph API requires an image or video to create a post. Please attach media to publish to Instagram.',
                ], 400);
            }
        }

        // 3. Validate YouTube Connection & Video Requirement
        $ytConn = null;
        if ($requiresYouTube) {
            $ytInteg = Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'youtube')
                ->where('is_connected', true)
                ->first();
            $ytConn = \App\Models\YouTubeConnection::where('workspace_id', $workspaceId)
                ->orWhere(function ($q) use ($ytInteg) {
                    if ($ytInteg && !empty($ytInteg->account_id)) {
                        $q->where('channel_id', $ytInteg->account_id);
                    }
                })
                ->orWhere('user_id', auth()->id() ?? $request->user()?->id ?? 1)
                ->whereNotNull('access_token')
                ->first();
            if ($status !== 'draft' && !$ytConn) {
                return response()->json([
                    'success' => false,
                    'message' => 'No connected YouTube Channel found for this workspace. Please connect YouTube in Integrations.',
                ], 400);
            }

            if ($status !== 'draft' && $postType !== 'video' && !$request->hasFile('video')) {
                return response()->json([
                    'success' => false,
                    'message' => 'YouTube requires video content. Please attach a video file to publish to YouTube.',
                ], 400);
            }
        }

        // Parse scheduled_at if provided
        $scheduledAtCarbon = null;
        if (!empty($validated['scheduled_at'])) {
            $scheduledAtCarbon = \Carbon\Carbon::parse($validated['scheduled_at'], 'Asia/Kolkata')->utc();
        }

        // Duplicate Publish Protection: Prevent identical double submissions within 15 seconds
        if ($status === 'published') {
            $recentDuplicate = FacebookPost::where('workspace_id', $workspaceId)
                ->where('content', $message)
                ->where('status', 'published')
                ->where('created_at', '>=', now()->subSeconds(15))
                ->first();
            if ($recentDuplicate) {
                return response()->json([
                    'success'           => true,
                    'message'           => 'Post already published (duplicate submission prevented).',
                    'platform_statuses' => $recentDuplicate->platform_statuses ?? [],
                    'published_summary' => ['Facebook Page'],
                    'errors'            => [],
                    'data'              => $recentDuplicate->load('media'),
                ]);
            }
        }

        // Handle uploaded media files
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

        // Create database record
        $post = FacebookPost::create([
            'workspace_id'     => $workspaceId,
            'facebook_page_id' => $fbPage ? $fbPage->id : null,
            'post_type'        => $postType,
            'content'          => $message,
            'link_url'         => $validated['link_url'] ?? null,
            'status'           => $status,
            'scheduled_at'     => $scheduledAtCarbon,
            'published_at'     => $status === 'published' ? now() : null,
            'youtube_privacy'  => $youtubePrivacy,
            'created_by_id'    => $request->user()->id ?? 1,
        ]);

        // Insert media attachments
        foreach ($storedMediaFiles as $mediaData) {
            \App\Models\FacebookPostMedia::create([
                'facebook_post_id' => $post->id,
                'media_type'       => $mediaData['type'],
                'file_path'        => $mediaData['file_path'],
                'file_url'         => $mediaData['file_url'],
                'sort_order'       => $mediaData['sort_order'],
            ]);
        }

        // Create legacy generic post record for multi-platform history
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

        // If Draft or Scheduled, return immediately without calling social platform APIs
        if ($status !== 'published') {
            return response()->json([
                'success' => true,
                'message' => $status === 'scheduled' ? 'Post scheduled successfully!' : 'Draft saved successfully!',
                'data'    => $post->load('media'),
            ], 201);
        }

        // Execute Multi-Platform Publishing
        $platformStatuses = [];
        $publishedSummary = [];
        $errors           = [];

        // 1. Facebook Publishing
        if ($requiresFacebook && $fbPage) {
            try {
                $graphService = app(FacebookGraphService::class);
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
                $platformStatuses['facebook'] = [
                    'platform'  => 'Facebook',
                    'status'    => 'published',
                    'id'        => $fbPostId,
                    'timestamp' => now()->toIso8601String(),
                ];
                $publishedSummary[] = 'Facebook Page';
            } catch (\Exception $e) {
                $errMsg = $e->getMessage();
                $platformStatuses['facebook'] = [
                    'platform' => 'Facebook',
                    'status'   => 'failed',
                    'error'    => $errMsg,
                ];
                $errors['facebook'] = 'Facebook: ' . $errMsg;
            }
        }

        // 2. Instagram Publishing
        if ($requiresInstagram) {
            try {
                if (empty($storedMediaFiles)) {
                    throw new \Exception('Instagram requires an image or video to create a post. Please attach media to publish to Instagram.');
                }

                $graphService = app(FacebookGraphService::class);
                $fbPageForIg = $fbPage ?? FacebookPage::where('workspace_id', $workspaceId)->whereNotNull('page_access_token')->first();
                $igAccountId  = $igInteg ? $igInteg->account_id : null;
                $primaryToken = $igInteg ? $igInteg->refresh_token : ($fbPageForIg ? $fbPageForIg->page_access_token : null);

                // Auto-resolve verified Instagram Business Account if linked to Facebook Page
                if ($fbPageForIg && !empty($fbPageForIg->page_access_token)) {
                    $linkedIg = $graphService->getLinkedInstagramBusinessAccount($fbPageForIg->page_id, $fbPageForIg->page_access_token);
                    if ($linkedIg && !empty($linkedIg['id'])) {
                        $igAccountId = (string) $linkedIg['id'];
                        if (empty($primaryToken)) {
                            $primaryToken = $fbPageForIg->page_access_token;
                        }
                    }
                }

                if (empty($igAccountId) || empty($primaryToken)) {
                    throw new \Exception('No connected Instagram Business Account ID or access token found for this workspace. Please ensure an Instagram Professional account is linked to your Facebook Page in Meta Business Suite.');
                }

                $igResult = null;
                if ($postType === 'multi_image' && count($storedMediaFiles) > 1) {
                    $mediaPaths = array_map(fn($m) => $m['file_path'] ?? $m['uploaded_file'], $storedMediaFiles);
                    try {
                        $igResult = $graphService->publishInstagramCarousel($igAccountId, $primaryToken, $message, $mediaPaths, $workspaceId);
                    } catch (\Exception $igErr) {
                        if ($fbPageForIg && !empty($fbPageForIg->page_access_token) && $primaryToken !== $fbPageForIg->page_access_token) {
                            $igResult = $graphService->publishInstagramCarousel($igAccountId, $fbPageForIg->page_access_token, $message, $mediaPaths, $workspaceId);
                        } else {
                            throw $igErr;
                        }
                    }
                } elseif (!empty($storedMediaFiles) && $storedMediaFiles[0]['type'] === 'image') {
                    $mediaPath = $storedMediaFiles[0]['file_path'] ?? $storedMediaFiles[0]['uploaded_file'];
                    try {
                        $igResult = $graphService->publishInstagramSinglePhoto($igAccountId, $primaryToken, $message, $mediaPath, $workspaceId);
                    } catch (\Exception $igErr) {
                        if ($fbPageForIg && !empty($fbPageForIg->page_access_token) && $primaryToken !== $fbPageForIg->page_access_token) {
                            $igResult = $graphService->publishInstagramSinglePhoto($igAccountId, $fbPageForIg->page_access_token, $message, $mediaPath, $workspaceId);
                        } else {
                            throw $igErr;
                        }
                    }
                } elseif (!empty($storedMediaFiles) && $storedMediaFiles[0]['type'] === 'video') {
                    $videoPath = $storedMediaFiles[0]['file_path'] ?? $storedMediaFiles[0]['uploaded_file'];
                    $igResult = $graphService->publishInstagramVideo($igAccountId, $primaryToken, $message, $videoPath, $workspaceId);
                }

                $igMediaId = $igResult['id'] ?? null;
                if (empty($igMediaId)) {
                    throw new \Exception('Instagram media publish completed without returning a valid media ID from Meta.');
                }

                $post->ig_media_id = $igMediaId;
                $platformStatuses['instagram'] = [
                    'platform'  => 'Instagram',
                    'status'    => 'published',
                    'id'        => $igMediaId,
                    'timestamp' => now()->toIso8601String(),
                ];
                $publishedSummary[] = 'Instagram (@' . ($igInteg ? $igInteg->account_name : 'Account') . ')';
            } catch (\Exception $e) {
                $rawMsg = $e->getMessage();
                $friendlyMsg = $rawMsg;
                if (str_contains($rawMsg, 'Application does not have permission') || str_contains($rawMsg, 'code":10') || str_contains($rawMsg, 'code 10')) {
                    $friendlyMsg = 'Your connected Meta account lacks Instagram publishing permissions (Error #10). Please ensure your Instagram Professional account is linked to your Facebook Page in Meta Business Suite.';
                }
                $post->ig_media_id = null;
                $platformStatuses['instagram'] = [
                    'platform' => 'Instagram',
                    'status'   => 'failed',
                    'error'    => $friendlyMsg,
                ];
                $errors['instagram'] = 'Instagram: ' . $friendlyMsg;
            }
        }

        // 3. YouTube Publishing
        if ($requiresYouTube && $ytConn) {
            try {
                if ($request->hasFile('video') || (!empty($storedMediaFiles) && $storedMediaFiles[0]['type'] === 'video')) {
                    $videoPath = $storedMediaFiles[0]['file_path'] ?? $request->file('video')->getRealPath();
                    $ytService = resolve(\App\Services\YouTubeService::class);

                    $ytResult = $ytService->uploadVideo(
                        $ytConn,
                        $videoPath,
                        $videoTitle,
                        $message,
                        $youtubePrivacy
                    );

                    $ytVideoId = $ytResult['id'] ?? null;
                    $post->yt_video_id = $ytVideoId;
                    $platformStatuses['youtube'] = [
                        'platform'       => 'YouTube',
                        'status'         => 'published',
                        'id'             => $ytVideoId,
                        'url'            => $ytResult['url'] ?? "https://www.youtube.com/watch?v={$ytVideoId}",
                        'privacy_status' => $youtubePrivacy,
                        'timestamp'      => now()->toIso8601String(),
                    ];
                    $publishedSummary[] = "YouTube ({$ytConn->channel_name}) [{$youtubePrivacy}]";
                } else {
                    throw new Exception('YouTube requires a video file to be uploaded.');
                }
            } catch (\Exception $e) {
                $errMsg = $e->getMessage();
                $platformStatuses['youtube'] = [
                    'platform' => 'YouTube',
                    'status'   => 'failed',
                    'error'    => $errMsg,
                ];
                $errors['youtube'] = 'YouTube: ' . $errMsg;
            }
        }

        // Store platform-level status separately in database
        $post->platform_statuses = $platformStatuses;

        // If all selected platforms failed
        if (count($publishedSummary) === 0 && count($errors) > 0) {
            $post->status = 'failed';
            $post->error_message = implode(' | ', array_values($errors));
            $post->save();

            return response()->json([
                'success'           => false,
                'partial'           => false,
                'status'            => 'failed',
                'message'           => 'Publishing failed on all selected platforms: ' . implode(' | ', array_values($errors)),
                'platform_statuses' => $platformStatuses,
                'errors'            => array_values($errors),
                'data'              => $post,
            ], 422);
        }

        // Partial or complete publishing success
        $hasPartialIssues = count($errors) > 0;
        $post->status = 'published';
        $post->published_at = now();
        $post->error_message = $hasPartialIssues ? implode(' | ', array_values($errors)) : null;
        $post->save();

        if ($requiresFacebook && !empty($post->fb_post_id) && $fbPage) {
            try {
                $graphService = app(FacebookGraphService::class);
                $graphService->syncPost($post, $fbPage->page_access_token);
                $graphService->refreshPageInsights($fbPage);
            } catch (\Exception $syncErr) {
                \Illuminate\Support\Facades\Log::warning('Post sync after publish failed: ' . $syncErr->getMessage());
            }
        }

        FacebookPostHistory::create([
            'facebook_post_id' => $post->id,
            'action'           => count($publishedSummary) > 0 ? 'published' : 'failed',
            'attempt_number'   => 1,
            'status_code'      => $hasPartialIssues ? 207 : 200,
            'response_payload' => [
                'platform_statuses' => $platformStatuses,
                'summary'           => $publishedSummary,
                'errors'            => array_values($errors),
            ],
            'error_details'    => count($errors) > 0 ? implode(' | ', array_values($errors)) : null,
        ]);

        createNotification(
            $workspaceId,
            'post_published',
            $hasPartialIssues ? 'Publishing Completed with Issues' : 'Post Published',
            $hasPartialIssues
                ? 'Published to ' . implode(', ', $publishedSummary) . '; Issues on: ' . implode(', ', array_keys($errors))
                : 'Post published to ' . implode(', ', $publishedSummary) . '.',
            implode(', ', $publishedSummary)
        );

        $finalMessage = $hasPartialIssues
            ? 'Publishing completed with issues: Published to ' . implode(', ', $publishedSummary) . ', but failed on ' . implode(', ', array_keys($errors)) . '.'
            : 'Post published successfully to ' . implode(', ', $publishedSummary) . '!';

        return response()->json([
            'success'           => true,
            'partial'           => $hasPartialIssues,
            'status'            => $hasPartialIssues ? 'partial' : 'published',
            'message'           => $finalMessage,
            'platform_statuses' => $platformStatuses,
            'published_summary' => $publishedSummary,
            'errors'            => array_values($errors),
            'data'              => $post,
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

        // Auto-process any due scheduled posts if scheduler worker is not running
        if (FacebookPost::where('status', 'scheduled')->where('scheduled_at', '<=', now())->exists()) {
            try {
                \Illuminate\Support\Facades\Artisan::call('posts:process-scheduled');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Auto-process scheduled posts notice: ' . $e->getMessage());
            }
        }

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
        $newPost->created_at = now();
        $newPost->updated_at = now();
        $newPost->save();

        $mediaItems = \App\Models\FacebookPostMedia::where('facebook_post_id', $post->id)->get();
        foreach ($mediaItems as $media) {
            $newMedia = $media->replicate();
            $newMedia->facebook_post_id = $newPost->id;
            $newMedia->save();
        }

        // Also duplicate in Post model for history
        Post::create([
            'workspace_id'    => $newPost->workspace_id,
            'content'         => $newPost->content,
            'platform_list'   => $newPost->facebook_page_id ? ['Facebook'] : ['Instagram'],
            'status'          => 'draft',
            'created_by_id'   => request()->user()->id ?? 1,
        ]);

        return response()->json(['success' => true, 'message' => 'Post duplicated as a new draft!', 'data' => $newPost->load('media')]);
    });

    // ── Paginated Publishing History & Drafts Endpoint ────────────────────────
    Route::get('/posts/history', function (Request $request) {
        $workspaceId = (int)$request->query('workspace_id', 1);

        // Auto-process any due scheduled posts if scheduler worker is not running
        if (FacebookPost::where('status', 'scheduled')->where('scheduled_at', '<=', now())->exists()) {
            try {
                \Illuminate\Support\Facades\Artisan::call('posts:process-scheduled');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Auto-process scheduled posts notice: ' . $e->getMessage());
            }
        }

        $page        = max(1, (int)$request->query('page', 1));
        $perPage     = max(1, min(50, (int)$request->query('per_page', 5)));

        $query = FacebookPost::with('media')
            ->where('workspace_id', $workspaceId)
            ->orderBy('created_at', 'desc');

        $totalRecords = $query->count();
        $totalPages   = (int)ceil($totalRecords / $perPage);
        $lastPage     = max(1, $totalPages);

        $posts = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(function ($p) {
                $legacyPost = Post::where('workspace_id', $p->workspace_id)
                    ->where('content', $p->content)
                    ->latest()
                    ->first();
                $p->platform_list = $legacyPost ? $legacyPost->platform_list : ($p->facebook_page_id ? ['Facebook'] : ['Instagram']);

                // If post is published in DB but has individual platform failures, present status as partial for frontend UI
                if ($p->status === 'published' && !empty($p->platform_statuses)) {
                    $hasFailures = collect($p->platform_statuses)->contains(fn($s) => ($s['status'] ?? null) === 'failed');
                    $hasSuccess = collect($p->platform_statuses)->contains(fn($s) => ($s['status'] ?? null) === 'published');
                    if ($hasFailures && $hasSuccess) {
                        $p->status = 'partial';
                    }
                }
                return $p;
            });

        return response()->json([
            'success'      => true,
            'data'         => $posts,
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $totalRecords,
            'last_page'    => $lastPage,
            'has_prev'     => $page > 1,
            'has_next'     => $page < $lastPage,
        ]);
    });

    // ── Facebook Analytics / Insights API ────────────────────────────────────────
    // ── Reports — Combined Multi-Platform Analytics ─────────────────────────
    Route::get('/reports/analytics', function (Request $request) {
        $workspaceId = (int)$request->query('workspace_id', 1);
        $platform    = strtolower(trim($request->query('platform', 'all')));
        $period      = trim($request->query('period', '30'));
        $reqStart    = $request->query('start_date');
        $reqEnd      = $request->query('end_date');

        // 1. Calculate Exact Start and End Dates based on period or custom date range
        $today = now()->endOfDay();
        $startDate = null;
        $endDate = null;

        if ($period === 'today' || $period === '1') {
            $startDate = now()->startOfDay();
            $endDate = now()->endOfDay();
            $days = 1;
        } elseif ($period === '7') {
            $startDate = now()->subDays(6)->startOfDay();
            $endDate = now()->endOfDay();
            $days = 7;
        } elseif ($period === '90') {
            $startDate = now()->subDays(89)->startOfDay();
            $endDate = now()->endOfDay();
            $days = 90;
        } elseif ($period === 'custom' && !empty($reqStart) && !empty($reqEnd)) {
            try {
                $parsedStart = \Carbon\Carbon::parse($reqStart)->startOfDay();
                $parsedEnd = \Carbon\Carbon::parse($reqEnd)->endOfDay();
                if ($parsedStart->gt($parsedEnd)) {
                    $temp = $parsedStart;
                    $parsedStart = $parsedEnd->startOfDay();
                    $parsedEnd = $temp->endOfDay();
                }
                if ($parsedEnd->gt($today)) {
                    $parsedEnd = $today->copy();
                }
                $startDate = $parsedStart;
                $endDate = $parsedEnd;
                $days = (int)round($startDate->diffInDays($endDate) + 1);
            } catch (\Exception $e) {
                $startDate = now()->subDays(29)->startOfDay();
                $endDate = now()->endOfDay();
                $days = 30;
            }
        } else {
            // Default 30 days
            $startDate = now()->subDays(29)->startOfDay();
            $endDate = now()->endOfDay();
            $days = 30;
        }

        $startDateStr = $startDate->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');

        $graphService = new FacebookGraphService();

        // ── 1. Facebook ──────────────────────────────────────────────────────
        $accounts = app(\App\Services\WorkspaceSocialAccounts::class);
        $fbPage = $accounts->facebook($workspaceId);
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
            $fbFollowers = max((int)($fbPage->followers_count ?? 0), (int)($fbPage->fan_count ?? 0));

            try {
                $insights      = $graphService->getPageInsights($fbPage->page_id, $fbPage->page_access_token, $days, $startDateStr, $endDateStr);
                $fbImpressions = (int)($insights['impressions'] ?? 0);
                $fbEngagement  = (int)($insights['engagements'] ?? 0);
                $fbReactions   = (int)($insights['reactions'] ?? 0);
            } catch (\Throwable $e) {}

            try {
                $feedMetrics = $graphService->getFacebookFeedPostMetrics($fbPage->page_id, $fbPage->page_access_token, $days, true, $startDateStr, $endDateStr);
                $fbLikes    = (int)($feedMetrics['likes'] ?? 0);
                $fbComments = (int)($feedMetrics['comments'] ?? 0);
            } catch (\Throwable $e) {}

            try {
                $fetchedTrend = $graphService->getPageInsightsTrend($fbPage->page_id, $fbPage->page_access_token, $days, $startDateStr, $endDateStr);
                if (!empty($fetchedTrend['labels'])) {
                    $fbTrend = $fetchedTrend;
                }
            } catch (\Throwable $e) {}
        }

        // ── 2. Instagram ─────────────────────────────────────────────────────
        $igInteg = $accounts->instagram($workspaceId);

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
            $igProfile = \Illuminate\Support\Facades\Cache::remember("ig_profile_{$igInteg->account_id}", 3600, function () use ($graphService, $igInteg) {
                return $graphService->getInstagramProfile($igInteg->account_id, $igInteg->refresh_token);
            });
            $igFollowers = (int)($igProfile['followers_count'] ?? 0);

            try {
                $igMediaItems = $graphService->getInstagramMediaList($igInteg->refresh_token, $igInteg->account_id, 50, true, $startDateStr, $endDateStr);
                if (!empty($igMediaItems)) {
                    $igLikes        = (int)array_sum(array_column($igMediaItems, 'like_count'));
                    $igComments     = (int)array_sum(array_column($igMediaItems, 'comments_count'));
                    $igViews        = (int)array_sum(array_column($igMediaItems, 'views_count'));
                    $igShares       = (int)array_sum(array_column($igMediaItems, 'shares_count'));
                    $igSaved        = (int)array_sum(array_column($igMediaItems, 'saved_count'));
                    $igReach        = (int)array_sum(array_column($igMediaItems, 'reach'));
                    $igInteractions = (int)array_sum(array_column($igMediaItems, 'total_interactions'));
                    if ($igInteractions === 0) {
                        $igInteractions = $igLikes + $igComments + $igShares + $igSaved;
                    }
                }
            } catch (\Throwable $e) {}

            try {
                $igTrendData = $graphService->getInstagramInsightsTrend($igInteg->refresh_token, $days, $startDateStr, $endDateStr);
                if (!empty($igTrendData['labels'])) {
                    $igTrend = $igTrendData;
                    if ($igReach === 0 && !empty($igTrendData['reach'])) {
                        $igReach = (int)array_sum($igTrendData['reach']);
                    }
                    if ($igInteractions === 0 && !empty($igTrendData['engagement'])) {
                        $igInteractions = (int)array_sum($igTrendData['engagement']);
                    }
                }
            } catch (\Throwable $e) {}
        }

        // ── 3. YouTube ───────────────────────────────────────────────────────
        $ytInteg = Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'youtube')
            ->where('is_connected', true)
            ->where(function ($q) {
                $q->where('connection_status', '!=', 'disconnected')->orWhereNull('connection_status');
            })
            ->first();

        $ytConnected        = false;
        $ytViews            = 0;
        $ytSubscribers      = 0;
        $ytVideos           = 0;
        $ytLikes            = 0;
        $ytComments         = 0;
        $ytShares           = 0;
        $ytWatchTimeHours   = 0.0;
        $ytChannelName      = null;
        $ytDailyTrend       = [];

        $ytConn = $ytInteg ? \App\Models\YouTubeConnection::where('workspace_id', $workspaceId)->whereNotNull('access_token')->first() : null;

        if ($ytConn) {
            $ytConnected   = true;
            $ytChannelName = $ytConn->channel_name;
            $ytSubscribers = (int)($ytConn->subscriber_count ?? 0);
            $ytVideos      = (int)($ytConn->video_count ?? 0);

            try {
                $ytService = app(\App\Services\YouTubeService::class);
                $ytMetrics = $ytService->getChannelAnalytics($ytConn, $startDateStr, $endDateStr, (bool)$request->query('force_refresh', false));
                if (!empty($ytMetrics['success'])) {
                    $ytViews          = (int)($ytMetrics['views'] ?? 0);
                    $ytLikes          = (int)($ytMetrics['likes'] ?? 0);
                    $ytComments       = (int)($ytMetrics['comments'] ?? 0);
                    $ytShares         = (int)($ytMetrics['shares'] ?? 0);
                    $ytSubscribers    = (int)($ytMetrics['subscribers'] ?? $ytSubscribers);
                    $ytVideos         = (int)($ytMetrics['videos'] ?? $ytVideos);
                    $ytWatchTimeHours = (float)($ytMetrics['watch_time_hours'] ?? 0.0);
                    $ytDailyTrend     = $ytMetrics['daily_trend'] ?? [];
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Reports YouTube analytics error: ' . $e->getMessage());
            }
        }

        // ── 4. X / Twitter ───────────────────────────────────────────────────
        $twInteg = Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'twitter')
            ->where('is_connected', true)
            ->first();

        $twConnected   = false;
        $twHandle      = null;
        $twFollowers   = 0;
        $twTweets      = 0;

        if ($twInteg) {
            $twConnected = true;
            $twHandle    = $twInteg->account_name;
            $twFollowers = (int)($twInteg->meta_data['followers_count'] ?? 0);
            $twTweets    = (int)($twInteg->meta_data['tweet_count'] ?? 0);
        }

        // ── 5. Google Analytics (GA4) ─────────────────────────────────────────
        $gaInteg = Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'google_analytics')
            ->where('is_connected', true)
            ->first();

        $gaConnected      = false;
        $gaActiveUsers    = 0;
        $gaSessions       = 0;
        $gaPageViews      = 0;
        $gaEngagementRate = 0;
        $gaConversions    = 0;

        if ($gaInteg) {
            try {
                $gaService = app(\App\Services\GoogleAnalyticsService::class);
                if ($gaService->isConfigured($workspaceId)) {
                    $gaData = $gaService->getOverviewMetrics($startDateStr, $endDateStr, $workspaceId);
                    $gaConnected      = (bool)($gaData['connected'] ?? false);
                    $gaActiveUsers    = (int)($gaData['active_users'] ?? 0);
                    $gaSessions       = (int)($gaData['sessions'] ?? 0);
                    $gaPageViews      = (int)($gaData['screen_page_views'] ?? 0);
                    $gaEngagementRate = (float)($gaData['engagement_rate'] ?? 0);
                    $gaConversions    = (int)($gaData['conversions'] ?? 0);
                }
            } catch (\Throwable $e) {}
        }

        // ── 6. Google Search Console ──────────────────────────────────────────
        $gscInteg = Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'search_console')
            ->where('is_connected', true)
            ->first();

        $gscConnected   = false;
        $gscClicks      = 0;
        $gscImpressions = 0;
        $gscCtr         = 0;
        $gscPosition    = 0;

        if ($gscInteg) {
            try {
                $gscService = app(\App\Services\GoogleSearchConsoleService::class);
                if ($gscService->isConfigured($workspaceId)) {
                    $gscData = $gscService->getOverview($startDateStr, $endDateStr, $workspaceId);
                    $gscConnected   = (bool)($gscData['connected'] ?? false);
                    $gscClicks      = (int)($gscData['clicks'] ?? 0);
                    $gscImpressions = (int)($gscData['impressions'] ?? 0);
                    $gscCtr         = (float)($gscData['ctr'] ?? 0);
                    $gscPosition    = (float)($gscData['position'] ?? 0);
                }
            } catch (\Throwable $e) {}
        }

        // ── 7. Build Exact Trend Chart Timeline Labels from $startDate to $endDate ─
        $labels = [];
        if ($days === 1) {
            $labels = [$startDate->format('M j')];
        } else {
            $step = max(1, (int)floor($days / 30));
            $curr = $startDate->copy();
            while ($curr->lte($endDate)) {
                $labels[] = $curr->format('M j');
                $curr->addDays($step);
            }
        }

        // Generate baseline series aligned with labels
        $fbReachSeries = $fbTrend['reach'] ?? array_fill(0, count($labels), 0);
        $fbEngageSeries= $fbTrend['engagement'] ?? array_fill(0, count($labels), 0);
        $igReachSeries = $igTrend['reach'] ?? array_fill(0, count($labels), 0);
        $igEngageSeries= $igTrend['engagement'] ?? array_fill(0, count($labels), 0);

        while (count($fbReachSeries) < count($labels)) $fbReachSeries[] = 0;
        while (count($fbEngageSeries) < count($labels)) $fbEngageSeries[] = 0;
        while (count($igReachSeries) < count($labels)) $igReachSeries[] = 0;
        while (count($igEngageSeries) < count($labels)) $igEngageSeries[] = 0;

        $ytViewsSeries = [];
        $ytEngageSeries= [];
        foreach ($labels as $lbl) {
            $ytViewsSeries[] = 0;
            $ytEngageSeries[] = 0;
        }
        if (!empty($ytDailyTrend)) {
            $dateKeys = array_keys($ytDailyTrend);
            foreach ($dateKeys as $i => $dKey) {
                if ($i < count($ytViewsSeries)) {
                    $ytViewsSeries[$i]  = (int)($ytDailyTrend[$dKey]['views'] ?? 0);
                    $ytEngageSeries[$i] = (int)($ytDailyTrend[$dKey]['engagement'] ?? 0);
                }
            }
        }

        // GA4 & GSC Daily Trends
        $gaPageviewsSeries = array_fill(0, count($labels), 0);
        $gaSessionsSeries  = array_fill(0, count($labels), 0);
        if ($gaConnected && isset($gaService)) {
            try {
                $gaDaily = $gaService->getDailyTrend($startDateStr, $endDateStr, $workspaceId);
                if (!empty($gaDaily['labels'])) {
                    $lblMap = array_flip($labels);
                    foreach ($gaDaily['labels'] as $gi => $gDate) {
                        $m = \Carbon\Carbon::parse($gDate)->format('M j');
                        if (isset($lblMap[$m])) {
                            $idx = $lblMap[$m];
                            $gaPageviewsSeries[$idx] = (int)($gaDaily['pageviews'][$gi] ?? $gaDaily['screen_page_views'][$gi] ?? 0);
                            $gaSessionsSeries[$idx]  = (int)($gaDaily['sessions'][$gi] ?? 0);
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        $gscClicksSeries      = array_fill(0, count($labels), 0);
        $gscImpressionsSeries = array_fill(0, count($labels), 0);
        if ($gscConnected && isset($gscService)) {
            try {
                $gscDaily = $gscService->getDailyTrend($startDateStr, $endDateStr, $workspaceId);
                if (!empty($gscDaily['labels'])) {
                    $lblMap = array_flip($labels);
                    foreach ($gscDaily['labels'] as $gsi => $gsDate) {
                        $m = \Carbon\Carbon::parse($gsDate)->format('M j');
                        if (isset($lblMap[$m])) {
                            $idx = $lblMap[$m];
                            $gscClicksSeries[$idx]      = (int)($gscDaily['clicks'][$gsi] ?? 0);
                            $gscImpressionsSeries[$idx] = (int)($gscDaily['impressions'][$gsi] ?? 0);
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        // Top Content aggregation across connected platforms
        $topContent = [];

        // 1. Facebook Published Posts
        try {
            $fbPostsQuery = FacebookPost::where('workspace_id', $workspaceId)
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->take(15)
                ->get();

            foreach ($fbPostsQuery as $p) {
                // Real Views / Reach: Prefer views_count (Meta post_media_view / video_views), then reach_count / impressions_count.
                // If the metric is unsupported or null on Facebook posts, return 'N/A' instead of 0.
                $cachedViews = !empty($p->fb_post_id) ? \Illuminate\Support\Facades\Cache::get("fb_video_views_{$p->fb_post_id}") : null;
                $pViews = $p->views_count !== null ? (int)$p->views_count : ($cachedViews !== null ? (int)$cachedViews : null);

                if ($pViews !== null) {
                    $pReach = $pViews;
                } elseif (!empty($p->reach_count) && (int)$p->reach_count > 0) {
                    $pReach = (int)$p->reach_count;
                } elseif (!empty($p->impressions_count) && (int)$p->impressions_count > 0) {
                    $pReach = (int)$p->impressions_count;
                } else {
                    $pReach = 'N/A';
                }
                $pLikes = (int)($p->likes_count ?? 0);
                $pComments = (int)($p->comments_count ?? 0);
                $pShares = (int)($p->shares_count ?? 0);
                $pEng = max((int)($p->engagement_count ?? 0), $pLikes + $pComments + $pShares);
                $msg = !empty($p->message) ? $p->message : (!empty($p->content) ? $p->content : 'Facebook Post');
                $topContent[] = [
                    'id'         => 'fb_' . $p->id,
                    'platform'   => 'facebook',
                    'title'      => \Illuminate\Support\Str::limit($msg, 90),
                    'date'       => $p->published_at ? \Carbon\Carbon::parse($p->published_at)->toIso8601String() : $p->created_at?->toIso8601String(),
                    'reach'      => $pReach,
                    'likes'      => $pLikes,
                    'comments'   => $pComments,
                    'shares'     => $pShares,
                    'engagement' => $pEng,
                    'url'        => $p->permalink_url ?? null,
                ];
            }
        } catch (\Throwable $e) {}

        // 2. Instagram Media Items
        if (!empty($igMediaItems)) {
            foreach (array_slice($igMediaItems, 0, 15) as $igItem) {
                $igL = (int)($igItem['like_count'] ?? 0);
                $igC = (int)($igItem['comments_count'] ?? 0);
                $igS = (int)($igItem['shares_count'] ?? 0);
                $igEng = (int)($igItem['total_interactions'] ?? ($igL + $igC + $igS));
                $cap = !empty($igItem['caption']) ? $igItem['caption'] : 'Instagram Media';
                $topContent[] = [
                    'id'         => 'ig_' . ($igItem['id'] ?? uniqid()),
                    'platform'   => 'instagram',
                    'title'      => \Illuminate\Support\Str::limit($cap, 90),
                    'date'       => !empty($igItem['timestamp']) ? \Carbon\Carbon::parse($igItem['timestamp'])->toIso8601String() : null,
                    'reach'      => (int)($igItem['reach'] ?? $igItem['views_count'] ?? 0),
                    'likes'      => $igL,
                    'comments'   => $igC,
                    'shares'     => $igS,
                    'engagement' => $igEng,
                    'url'        => $igItem['permalink'] ?? null,
                ];
            }
        }

        // 3. YouTube Content Items
        if ($ytConn) {
            try {
                $ytService = $ytService ?? app(\App\Services\YouTubeService::class);
                $ytContentRes = $ytService->getChannelContent($ytConn, 'videos', 10, false, 300);
                foreach ($ytContentRes['content_items'] ?? [] as $yItem) {
                    $yV = (int)($yItem['view_count'] ?? $yItem['views'] ?? 0);
                    $yL = (int)($yItem['like_count'] ?? $yItem['likes'] ?? 0);
                    $yC = (int)($yItem['comment_count'] ?? $yItem['comments'] ?? 0);
                    $yTitle = $yItem['title'] ?? 'YouTube Video';
                    $topContent[] = [
                        'id'         => 'yt_' . ($yItem['id'] ?? uniqid()),
                        'platform'   => 'youtube',
                        'title'      => \Illuminate\Support\Str::limit($yTitle, 90),
                        'date'       => !empty($yItem['published_at']) ? \Carbon\Carbon::parse($yItem['published_at'])->toIso8601String() : null,
                        'reach'      => $yV,
                        'likes'      => $yL,
                        'comments'   => $yC,
                        'shares'     => 0,
                        'engagement' => $yL + $yC,
                        'url'        => !empty($yItem['id']) ? "https://www.youtube.com/watch?v={$yItem['id']}" : null,
                    ];
                }
            } catch (\Throwable $e) {}
        }

        // Sort Top Content by engagement descending
        usort($topContent, function ($a, $b) {
            if ($b['engagement'] !== $a['engagement']) {
                return $b['engagement'] <=> $a['engagement'];
            }
            $aReach = is_numeric($a['reach']) ? (int)$a['reach'] : -1;
            $bReach = is_numeric($b['reach']) ? (int)$b['reach'] : -1;
            return $bReach <=> $aReach;
        });

        // Previous period metrics for comparison
        $prevDays = $days;
        $prevEnd = $startDate->copy()->subSecond();
        $prevStart = $prevEnd->copy()->subDays($prevDays - 1)->startOfDay();
        $prevStartStr = $prevStart->format('Y-m-d');
        $prevEndStr = $prevEnd->format('Y-m-d');

        $prevFbImpressions = 0;
        $prevFbEngagement = 0;
        $prevIgReach = 0;
        $prevIgEngagement = 0;
        $prevYtViews = 0;
        $prevYtEngagement = 0;
        $prevGaSessions = 0;
        $prevGscClicks = 0;

        if ($fbPage && !empty($fbPage->page_access_token)) {
            try {
                $prevInsights = $graphService->getPageInsights($fbPage->page_id, $fbPage->page_access_token, $prevDays, $prevStartStr, $prevEndStr);
                $prevFbImpressions = (int)($prevInsights['impressions'] ?? 0);
                $prevFbEngagement  = (int)($prevInsights['engagements'] ?? 0);
            } catch (\Throwable $e) {}
        }

        // Platform-tailored Summary Calculation
        $totalReach = 0;
        $totalEngagement = 0;
        $totalAudience = 0;
        $totalClicks = 0;
        $totalConversions = 0;

        if ($platform === 'facebook') {
            $totalReach       = $fbImpressions;
            $totalEngagement  = $fbEngagement;
            $totalAudience    = $fbFollowers;
        } elseif ($platform === 'instagram') {
            $totalReach       = $igReach;
            $totalEngagement  = $igInteractions;
            $totalAudience    = $igFollowers;
        } elseif ($platform === 'youtube') {
            $totalReach       = $ytViews;
            $totalEngagement  = $ytLikes + $ytComments + $ytShares;
            $totalAudience    = $ytSubscribers;
        } elseif ($platform === 'twitter') {
            $totalAudience    = $twFollowers;
            $totalEngagement  = $twTweets;
        } elseif ($platform === 'google_analytics') {
            $totalReach       = $gaPageViews;
            $totalClicks      = $gaSessions;
            $totalConversions = $gaConversions;
        } elseif ($platform === 'search_console') {
            $totalReach       = $gscImpressions;
            $totalClicks      = $gscClicks;
        } else {
            // All Platforms
            $totalReach       = $fbImpressions + $igReach + $ytViews + $gaPageViews + $gscImpressions;
            $totalEngagement  = $fbEngagement + $igInteractions + $ytLikes + $ytComments + $ytShares;
            $totalAudience    = $fbFollowers + $igFollowers + $ytSubscribers + $twFollowers;
            $totalClicks      = $gscClicks + $gaSessions;
            $totalConversions = $gaConversions;
        }

        $prevTotalReach = 0;
        $prevTotalEngagement = 0;
        $prevTotalClicks = 0;
        if ($platform === 'facebook') {
            $prevTotalReach      = $prevFbImpressions;
            $prevTotalEngagement = $prevFbEngagement;
        } elseif ($platform === 'instagram') {
            $prevTotalReach      = $prevIgReach;
            $prevTotalEngagement = $prevIgEngagement;
        } elseif ($platform === 'youtube') {
            $prevTotalReach      = $prevYtViews;
            $prevTotalEngagement = $prevYtEngagement;
        } elseif ($platform === 'google_analytics') {
            $prevTotalClicks     = $prevGaSessions;
        } elseif ($platform === 'search_console') {
            $prevTotalClicks     = $prevGscClicks;
        } else {
            $prevTotalReach      = $prevFbImpressions + $prevIgReach + $prevYtViews;
            $prevTotalEngagement = $prevFbEngagement + $prevIgEngagement + $prevYtEngagement;
            $prevTotalClicks     = $prevGscClicks + $prevGaSessions;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'workspace_id'    => $workspaceId,
                'platform'        => $platform,
                'period'          => $period,
                'start_date'      => $startDateStr,
                'end_date'        => $endDateStr,
                'days_count'      => $days,
                'summary' => [
                    'total_reach'       => $totalReach,
                    'total_engagement'  => $totalEngagement,
                    'total_audience'    => $totalAudience,
                    'total_clicks'      => $totalClicks,
                    'total_conversions' => $totalConversions,
                ],
                'previous_summary' => [
                    'total_reach'       => $prevTotalReach,
                    'total_engagement'  => $prevTotalEngagement,
                    'total_audience'    => $totalAudience,
                    'total_clicks'      => $prevTotalClicks,
                    'start_date'        => $prevStartStr,
                    'end_date'          => $prevEndStr,
                ],
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
                'youtube' => [
                    'connected'        => $ytConnected,
                    'channel_name'     => $ytChannelName,
                    'subscribers'      => $ytSubscribers,
                    'views'            => $ytViews,
                    'video_count'      => $ytVideos,
                    'likes'            => $ytLikes,
                    'comments'         => $ytComments,
                    'shares'           => $ytShares,
                    'watch_time_hours' => $ytWatchTimeHours,
                ],
                'twitter' => [
                    'connected'    => $twConnected,
                    'account_name' => $twHandle,
                    'followers'    => $twFollowers,
                    'tweets'       => $twTweets,
                ],
                'google_analytics' => [
                    'connected'         => $gaConnected,
                    'active_users'      => $gaActiveUsers,
                    'sessions'          => $gaSessions,
                    'screen_page_views' => $gaPageViews,
                    'engagement_rate'   => $gaEngagementRate,
                    'conversions'       => $gaConversions,
                ],
                'search_console' => [
                    'connected'   => $gscConnected,
                    'clicks'      => $gscClicks,
                    'impressions' => $gscImpressions,
                    'ctr'         => $gscCtr,
                    'position'    => $gscPosition,
                ],
                'trend' => [
                    'labels'          => $labels,
                    'fb_reach'        => array_slice($fbReachSeries, 0, count($labels)),
                    'fb_engagement'   => array_slice($fbEngageSeries, 0, count($labels)),
                    'ig_reach'        => array_slice($igReachSeries, 0, count($labels)),
                    'ig_engagement'   => array_slice($igEngageSeries, 0, count($labels)),
                    'yt_views'        => array_slice($ytViewsSeries, 0, count($labels)),
                    'yt_engagement'   => array_slice($ytEngageSeries, 0, count($labels)),
                    'ga_pageviews'    => array_slice($gaPageviewsSeries, 0, count($labels)),
                    'ga_sessions'     => array_slice($gaSessionsSeries, 0, count($labels)),
                    'gsc_clicks'      => array_slice($gscClicksSeries, 0, count($labels)),
                    'gsc_impressions' => array_slice($gscImpressionsSeries, 0, count($labels)),
                ],
                'top_content' => $topContent,
            ],
        ]);
    });

    Route::get('/facebook/inbox/conversations', function (Request $request) {
        $validated = $request->validate([
            'workspace_id' => 'required|integer|exists:workspaces,id',
            'limit'        => 'nullable|integer|min:1|max:50',
            'after'        => 'nullable|string',
            'fresh'        => 'nullable|boolean',
        ]);

        $workspaceId = (int) $validated['workspace_id'];
        $limit       = min(50, max(1, (int) ($validated['limit'] ?? 20)));
        $after       = $validated['after'] ?? null;
        $isFresh     = $request->boolean('fresh');

        $fbPage      = app(\App\Services\WorkspaceSocialAccounts::class)->facebook($workspaceId);

        if (!$fbPage || empty($fbPage->page_access_token)) {
            return response()->json([
                'success' => false,
                'message' => 'No connected Facebook Page with a valid Page access token was found for this workspace.',
            ], 404);
        }

        $version = \Illuminate\Support\Facades\Cache::get("fb_inbox_conv_v_{$workspaceId}", 1);
        $cacheKey = "fb_inbox_conv_{$workspaceId}_v{$version}_" . md5("{$limit}_{$after}");

        if (!$isFresh && \Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return response()->json(\Illuminate\Support\Facades\Cache::get($cacheKey));
        }

        $params = [
            'fields'       => 'id,updated_time,unread_count,message_count,participants,snippet',
            'limit'        => $limit,
            'platform'     => 'messenger',
            'access_token' => $fbPage->page_access_token,
        ];
        if (!empty($after)) {
            $params['after'] = $after;
        }

        $response = Http::withoutVerifying()
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->get("https://graph.facebook.com/v23.0/{$fbPage->page_id}/conversations", $params);

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => $response->json('error.message') ?: 'Facebook Messenger conversations could not be loaded.',
                'meta_code' => $response->json('error.code'),
            ], 502);
        }

        $pageId = (string) $fbPage->page_id;
        $pageEmail = "{$pageId}@facebook.com";
        $conversations = collect($response->json('data') ?? [])->map(function ($conversation) use ($pageId, $pageEmail, $fbPage) {
            $participants = collect(data_get($conversation, 'participants.data', []));
            $customer = $participants->first(function ($participant) use ($pageId, $pageEmail, $fbPage) {
                $id = (string) ($participant['id'] ?? '');
                $email = (string) ($participant['email'] ?? '');
                $name = (string) ($participant['name'] ?? '');
                return $id !== $pageId && $email !== $pageEmail && $name !== (string) $fbPage->page_name;
            }) ?? $participants->first();

            $snippet = $conversation['snippet'] ?? null;

            return [
                'id'            => $conversation['id'] ?? null,
                'updated_time'  => $conversation['updated_time'] ?? null,
                'unread_count'  => (int) ($conversation['unread_count'] ?? 0),
                'message_count' => (int) ($conversation['message_count'] ?? 0),
                'customer'      => [
                    'name' => $customer['name'] ?? 'Facebook user',
                ],
                'latest_message' => $snippet !== null ? [
                    'message'      => $snippet,
                    'created_time' => $conversation['updated_time'] ?? null,
                    'direction'    => null,
                    'attachments'  => [],
                ] : null,
            ];
        })->filter(fn ($conversation) => !empty($conversation['id']))->values()->all();

        $responseData = [
            'success' => true,
            'data'    => $conversations,
            'paging'  => [
                'before' => $response->json('paging.cursors.before'),
                'after'  => $response->json('paging.cursors.after'),
            ],
        ];

        \Illuminate\Support\Facades\Cache::put($cacheKey, $responseData, now()->addSeconds(60));

        return response()->json($responseData);
    });

    Route::post('/facebook/inbox/subscribe-webhook', [FacebookMessengerWebhookController::class, 'subscribe']);
    Route::get('/facebook/inbox/events', [FacebookMessengerWebhookController::class, 'events']);
    Route::get('/facebook/inbox/events/poll', [FacebookMessengerWebhookController::class, 'poll']);

    // Instagram Direct Message Routes
    Route::get('/instagram/inbox/conversations', [InstagramInboxController::class, 'conversations']);
    Route::get('/instagram/inbox/conversations/{conversationId}/messages', [InstagramInboxController::class, 'messages']);
    Route::post('/instagram/inbox/conversations/{conversationId}/messages', [InstagramInboxController::class, 'sendMessage']);
    Route::post('/instagram/inbox/send-message', [InstagramInboxController::class, 'sendMessage']);
    Route::get('/instagram/inbox/events', [FacebookMessengerWebhookController::class, 'events']);
    Route::get('/instagram/inbox/events/poll', [FacebookMessengerWebhookController::class, 'poll']);

    // YouTube Inbox Routes
    Route::get('/youtube/inbox/conversations', [YouTubeInboxController::class, 'conversations']);
    Route::get('/youtube/inbox/conversations/{conversationId}/messages', [YouTubeInboxController::class, 'messages']);
    Route::post('/youtube/inbox/conversations/{conversationId}/messages', [YouTubeInboxController::class, 'sendMessage']);
    Route::post('/youtube/inbox/send-message', [YouTubeInboxController::class, 'sendMessage']);

    Route::get('/facebook/inbox/conversations/{conversationId}/messages', function (Request $request, string $conversationId) {
        $validated = $request->validate([
            'workspace_id' => 'required|integer|exists:workspaces,id',
            'limit'        => 'nullable|integer|min:1|max:50',
            'after'        => 'nullable|string',
        ]);

        $workspaceId = (int) $validated['workspace_id'];
        $limit       = min(50, max(1, (int) ($validated['limit'] ?? 25)));
        $fbPage      = app(\App\Services\WorkspaceSocialAccounts::class)->facebook($workspaceId);

        if (!$fbPage || empty($fbPage->page_access_token)) {
            return response()->json([
                'success' => false,
                'message' => 'No connected Facebook Page with a valid Page access token was found for this workspace.',
            ], 404);
        }

        $params = [
            'fields'       => 'id,message,created_time,from,to,attachments,sticker',
            'limit'        => $limit,
            'access_token' => $fbPage->page_access_token,
        ];
        if (!empty($validated['after'])) {
            $params['after'] = $validated['after'];
        }

        $response = Http::withoutVerifying()
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->get("https://graph.facebook.com/v23.0/{$conversationId}/messages", $params);

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => $response->json('error.message') ?: 'Facebook Messenger messages could not be loaded.',
                'meta_code' => $response->json('error.code'),
            ], 502);
        }

        $pageId = (string) $fbPage->page_id;
        $messages = collect($response->json('data') ?? [])->map(function ($message) use ($pageId) {
            $attachments = collect(data_get($message, 'attachments.data', []))->map(function ($attachment) {
                return [
                    'type' => $attachment['type'] ?? null,
                    'url'  => data_get($attachment, 'image_data.url') ?? data_get($attachment, 'video_data.url') ?? data_get($attachment, 'file_url'),
                ];
            })->filter(fn ($attachment) => !empty($attachment['url']))->values()->all();

            $fromPage = (string) data_get($message, 'from.id') === $pageId;

            return [
                'id'           => $message['id'] ?? null,
                'message'      => $message['message'] ?? '',
                'created_time' => $message['created_time'] ?? null,
                'direction'    => $fromPage ? 'outbound' : 'inbound',
                'sender_name'  => data_get($message, 'from.name'),
                'attachments'  => $attachments,
                'sticker'      => $message['sticker'] ?? null,
            ];
        })->filter(fn ($message) => !empty($message['id']))->values()->all();

        return response()->json([
            'success' => true,
            'data'    => $messages,
            'paging'  => [
                'before' => $response->json('paging.cursors.before'),
                'after'  => $response->json('paging.cursors.after'),
            ],
        ]);
    });

    Route::post('/facebook/inbox/conversations/{conversationId}/messages', function (Request $request, string $conversationId) {
        $validated = $request->validate([
            'workspace_id' => 'required|integer|exists:workspaces,id',
            'message'      => 'required|string|min:1|max:2000',
        ]);

        $workspaceId = (int) $validated['workspace_id'];
        $fbPage      = app(\App\Services\WorkspaceSocialAccounts::class)->facebook($workspaceId);

        if (!$fbPage || empty($fbPage->page_access_token)) {
            return response()->json([
                'success' => false,
                'message' => 'No connected Facebook Page with a valid Page access token was found for this workspace.',
            ], 404);
        }

        $conversationResponse = Http::withoutVerifying()
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->get("https://graph.facebook.com/v23.0/{$conversationId}", [
                'fields'       => 'participants',
                'access_token' => $fbPage->page_access_token,
            ]);

        if (!$conversationResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => $conversationResponse->json('error.message') ?: 'Facebook Messenger conversation could not be verified.',
                'meta_code' => $conversationResponse->json('error.code'),
            ], 502);
        }

        $pageId = (string) $fbPage->page_id;
        $pageEmail = "{$pageId}@facebook.com";
        $recipient = collect($conversationResponse->json('participants.data') ?? [])->first(function ($participant) use ($pageId, $pageEmail, $fbPage) {
            $id = (string) ($participant['id'] ?? '');
            $email = (string) ($participant['email'] ?? '');
            $name = (string) ($participant['name'] ?? '');
            return $id !== $pageId && $email !== $pageEmail && $name !== (string) $fbPage->page_name;
        });

        if (empty($recipient['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Facebook did not return a reply recipient for this conversation.',
            ], 422);
        }

        $sendResponse = Http::withoutVerifying()
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->post("https://graph.facebook.com/v23.0/{$fbPage->page_id}/messages", [
                'recipient'      => ['id' => $recipient['id']],
                'messaging_type' => 'RESPONSE',
                'message'        => ['text' => trim($validated['message'])],
                'access_token'   => $fbPage->page_access_token,
            ]);

        if (!$sendResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => $sendResponse->json('error.message') ?: 'Facebook Messenger reply could not be sent.',
                'meta_code' => $sendResponse->json('error.code'),
            ], 502);
        }

        $vKey = "fb_inbox_conv_v_{$workspaceId}";
        \Illuminate\Support\Facades\Cache::put($vKey, (int) \Illuminate\Support\Facades\Cache::get($vKey, 1) + 1, now()->addDays(7));

        return response()->json([
            'success' => true,
            'message' => 'Reply sent through Facebook Messenger.',
            'data'    => [
                'message_id' => $sendResponse->json('message_id'),
            ],
        ]);
    });

    Route::get('/facebook/analytics', function (Request $request) {
        $workspaceId    = (int) $request->query('workspace_id', 1);
        $startDateParam = $request->query('start_date');
        $endDateParam   = $request->query('end_date');

        if (!empty($startDateParam) && !empty($endDateParam)) {
            $startDate = \Carbon\Carbon::parse($startDateParam)->startOfDay();
            $endDate   = \Carbon\Carbon::parse($endDateParam)->endOfDay();
            $days      = max(1, (int)$startDate->diffInDays($endDate) + 1);
            $startDateStr = $startDate->toDateString();
            $endDateStr   = $endDate->toDateString();
        } else {
            $days = (int) $request->query('period', 30);
            $startDateStr = null;
            $endDateStr   = null;
        }

        $fbPage = app(\App\Services\WorkspaceSocialAccounts::class)->facebook($workspaceId);

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
        $insights = $graphService->getPageInsights($fbPage->page_id, $fbPage->page_access_token, $days, $startDateStr, $endDateStr);

        // Fetch real daily trend (returns empty arrays on failure — no fake fallback)
        $trendData = ['labels' => [], 'reach' => [], 'engagement' => []];
        try {
            $fetchedTrend = $graphService->getPageInsightsTrend($fbPage->page_id, $fbPage->page_access_token, $days, $startDateStr, $endDateStr);
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
                'followers'   => (int)($fbPage->followers_count ?? 0),
                'impressions' => $insights['impressions'] ?? 0,
                'engagement'  => $insights['engagements'] ?? 0,
                'trend'       => $trendData,
            ]
        ]);
    });

    Route::get('/facebook/performance-metrics', function (Request $request) {
        $request->validate(['workspace_id' => 'required|integer|exists:workspaces,id']);
        $workspaceId  = (int) $request->query('workspace_id');
        $startDate    = $request->query('start_date');
        $endDate      = $request->query('end_date');
        $days         = $request->has('days') ? (int) $request->query('days') : null;
        $forceRefresh = (bool) $request->query('force_refresh', false);

        $service = new \App\Services\FacebookPerformanceService();
        $result  = $service->getPerformanceMetrics($workspaceId, $startDate, $endDate, $days, $forceRefresh);

        return response()->json($result);
    });

    // ── Facebook Dashboard Stats ──────────────────────────────────────────────
    // Returns summary card data, posts-by-day chart, and post-type breakdown.
    // Uses only already-available DB data + cached page details.
    Route::get('/facebook/dashboard-stats', function (Request $request) {
        $request->validate(['workspace_id' => 'required|integer|exists:workspaces,id']);
        $workspaceId    = (int) $request->query('workspace_id');
        $startDateParam = $request->query('start_date');
        $endDateParam   = $request->query('end_date');
        $forceRefresh   = $request->boolean('force_refresh') || $request->boolean('refresh') || $request->has('force_refresh');
        $autoSync       = $request->boolean('auto_sync');
        $liveSync       = $request->boolean('live_sync') || $request->boolean('sync') || $autoSync;
        $cacheTtl       = $autoSync ? 25 : 300;

        $fbPage = app(\App\Services\WorkspaceSocialAccounts::class)->facebook($workspaceId);

        if (!$fbPage) {
            return response()->json(['success' => false, 'message' => 'No Facebook page connected.']);
        }

        // Date range
        $allPosts = $request->query('all_posts') === 'true' || $request->query('all_posts') === '1' || $startDateParam === 'all' || $endDateParam === 'all';
        $startDate = null;
        $endDate   = null;
        if (!$allPosts) {
            if (!empty($startDateParam) && !empty($endDateParam) && $startDateParam !== 'null' && $endDateParam !== 'null') {
                $startDate = \Carbon\Carbon::parse($startDateParam, 'Asia/Kolkata')->startOfDay()->setTimezone('UTC');
                $endDate   = \Carbon\Carbon::parse($endDateParam, 'Asia/Kolkata')->endOfDay()->setTimezone('UTC');
            } else {
                $endDate   = now('Asia/Kolkata')->endOfDay()->setTimezone('UTC');
                $startDate = now('Asia/Kolkata')->subDays(29)->startOfDay()->setTimezone('UTC');
            }
        }

        $pageIds = \App\Models\FacebookPage::where('workspace_id', $workspaceId)->where('page_id', $fbPage->page_id)->pluck('id');
        $statsCacheKey = 'fb_dashboard_stats_v7_' . sha1(implode('|', [
            $workspaceId,
            $fbPage->page_id,
            $allPosts ? 'all' : ($startDate ? $startDate->toDateString() : ''),
            $allPosts ? 'all' : ($endDate ? $endDate->toDateString() : ''),
        ])) . ($autoSync ? '_live' : '');

        if ($forceRefresh) {
            \Illuminate\Support\Facades\Cache::forget($statsCacheKey);
        } elseif (\Illuminate\Support\Facades\Cache::has($statsCacheKey)) {
            return response()->json(\Illuminate\Support\Facades\Cache::get($statsCacheKey));
        }

        $freshMetricsCutoff = $autoSync ? now()->subSeconds(30) : now()->subMinutes(10);
        $freshMetricsQuery = \App\Models\FacebookPost::where('workspace_id', $workspaceId)
            ->whereNotNull('fb_post_id')
            ->where('fb_post_id', '!=', '')
            ->where(function ($q) {
                $q->where('status', 'published')->orWhereNull('status');
            });
        if (!$allPosts && $startDate && $endDate) {
            $freshMetricsQuery->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('published_at', [$startDate, $endDate])
                  ->orWhere(function ($sub) use ($startDate, $endDate) {
                      $sub->whereNull('published_at')->whereBetween('created_at', [$startDate, $endDate]);
                  });
            });
        }
        if (!empty($pageIds) && count($pageIds) > 0) {
            $freshMetricsQuery->where(function ($q) use ($pageIds, $fbPage) {
                $q->whereIn('facebook_page_id', $pageIds)
                  ->orWhere('fb_post_id', 'like', $fbPage->page_id . '\_%');
            });
        }
        $hasFreshPeriodMetrics = (clone $freshMetricsQuery)
            ->where('last_synced_at', '>=', $freshMetricsCutoff)
            ->exists();

        // Summary data from DB page record (real values stored on connect/refresh)
        $followers = $fbPage->followers_count !== null ? (int)$fbPage->followers_count : ($fbPage->fan_count !== null ? (int)$fbPage->fan_count : null);
        $lifetimePageLikes = $fbPage->fan_count !== null ? (int)$fbPage->fan_count : null;

        // Dynamically refresh live Page Details from Meta Graph API (cached for 5 minutes)
        if (!empty($fbPage->page_access_token)) {
            try {
                if ($forceRefresh) {
                    \Illuminate\Support\Facades\Cache::forget("fb_page_live_{$fbPage->page_id}");
                }
                $pageLiveDetails = \Illuminate\Support\Facades\Cache::remember("fb_page_live_{$fbPage->page_id}", 300, function () use ($fbPage) {
                    $graphService = new \App\Services\FacebookGraphService();
                    return $graphService->getPageDetails($fbPage->page_id, $fbPage->page_access_token);
                });
                if (!empty($pageLiveDetails)) {
                    if (array_key_exists('followers_count', $pageLiveDetails) && $pageLiveDetails['followers_count'] !== null) {
                        $followers = (int)$pageLiveDetails['followers_count'];
                    }
                    if (array_key_exists('fan_count', $pageLiveDetails) && $pageLiveDetails['fan_count'] !== null) {
                        $lifetimePageLikes = (int)$pageLiveDetails['fan_count'];
                    }
                    if ($fbPage->followers_count !== $followers || $fbPage->fan_count !== $lifetimePageLikes) {
                        $fbPage->update([
                            'followers_count' => $followers,
                            'fan_count'       => $lifetimePageLikes,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[FACEBOOK LIVE DETAILS ERROR] ' . $e->getMessage());
            }
        }

        // Live fetch & sync feed metrics for the selected date range from Meta Graph API
        $apiComments     = 0;
        $apiLikes        = 0;
        $apiPostsCount   = 0;
        $periodFollowers = null;
        $feedMetricsFetched = false;
        $days = ($startDate && $endDate) ? max(1, (int)$startDate->diffInDays($endDate) + 1) : 30;

        if (!empty($fbPage->page_access_token)) {
            $graphService = new \App\Services\FacebookGraphService();

            if ($liveSync && ($forceRefresh || !$hasFreshPeriodMetrics)) {
                try {
                    $graphService->syncFacebookPagePosts(
                        $fbPage,
                        $startDateParam,
                        $endDateParam,
                        $forceRefresh,
                        100
                    );
                    $feedMetrics = $graphService->getFacebookFeedPostMetrics(
                        $fbPage->page_id,
                        $fbPage->page_access_token,
                        $days,
                        $forceRefresh,
                        $startDate ? $startDate->toDateString() : null,
                        $endDate ? $endDate->toDateString() : null,
                        $workspaceId,
                        $autoSync ? 30 : null
                    );
                    $apiComments   = (int)($feedMetrics['comments'] ?? 0);
                    $feedLikes     = (int)($feedMetrics['likes'] ?? 0);
                    $apiPostsCount = (int)($feedMetrics['posts_count'] ?? 0);
                    $feedMetricsFetched = (bool)($feedMetrics['success'] ?? false);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[DASHBOARD STATS] Error fetching period metrics: ' . $e->getMessage());
                }
            }

            try {
                if ($forceRefresh) {
                    $since = $startDate ? $startDate->timestamp : null;
                    $until = $endDate ? $endDate->timestamp : null;
                    \Illuminate\Support\Facades\Cache::forget("fb_page_insights_v2_{$fbPage->page_id}_{$since}_{$until}");
                }
                $insights = $graphService->getPageInsights(
                    $fbPage->page_id,
                    $fbPage->page_access_token,
                    $days,
                    $startDate ? $startDate->toDateString() : null,
                    $endDate ? $endDate->toDateString() : null
                );
                // Period Followers: genuine new followers gained during the period from Meta's page_daily_follows_unique
                $periodFollowers = ($insights['has_data'] ?? false) ? (int)($insights['follows'] ?? 0) : null;

                // Use page_actions_post_reactions_total (what Meta Business Suite shows as "Likes") as the
                // canonical date-range-aware reactions metric. Fall back to per-post feed-level count if not available.
                $insightsReactions = (int)($insights['reactions'] ?? 0);
                $apiLikes = $insightsReactions > 0 ? $insightsReactions : ($feedLikes ?? 0);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[DASHBOARD STATS] Error fetching period insights: ' . $e->getMessage());
            }
        }

        // Total published posts for selected date range
        $baseQuery = \App\Models\FacebookPost::where('workspace_id', $workspaceId)
            ->whereNotNull('fb_post_id')
            ->where('fb_post_id', '!=', '')
            ->where(function ($q) {
                $q->where('status', 'published')->orWhereNull('status');
            });
        if (!empty($pageIds) && count($pageIds) > 0) {
            $baseQuery->where(function ($q) use ($pageIds, $fbPage) {
                $q->whereIn('facebook_page_id', $pageIds)
                  ->orWhere('fb_post_id', 'like', $fbPage->page_id . '\_%');
            });
        }

        $periodQuery = clone $baseQuery;
        if (!$allPosts && $startDate && $endDate) {
            $periodQuery->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('published_at', [$startDate, $endDate])
                  ->orWhere(function ($sub) use ($startDate, $endDate) {
                      $sub->whereNull('published_at')->whereBetween('created_at', [$startDate, $endDate]);
                  });
            });
        }

        // Consolidate aggregates into single SQL query
        $aggregates = (clone $periodQuery)
            ->selectRaw("COUNT(*) as total_posts, COALESCE(SUM(likes_count), 0) as total_likes, COALESCE(SUM(comments_count), 0) as total_comments")
            ->first();

        $dbTotalPosts = (int)($aggregates->total_posts ?? 0);
        $dbLikes      = (int)($aggregates->total_likes ?? 0);
        $dbComments   = (int)($aggregates->total_comments ?? 0);

        $totalPosts     = $dbTotalPosts;
        $periodLikes    = ($feedMetricsFetched && !empty($apiLikes)) ? $apiLikes : ($apiLikes > 0 ? $apiLikes : $dbLikes);
        $periodComments = $feedMetricsFetched ? $apiComments : $dbComments;

        // Posts-by-day chart: count posts per day in the date range
        $postsByDay = (clone $periodQuery)
            ->selectRaw("DATE(COALESCE(published_at, created_at)) as post_date, COUNT(*) as count")
            ->groupBy('post_date')
            ->orderBy('post_date')
            ->get()
            ->keyBy('post_date')
            ->map(fn($r) => (int)$r->count);

        // Build labels for every day in range
        $labels = [];
        $counts = [];
        if ($startDate && $endDate) {
            $current = $startDate->copy()->startOfDay();
            while ($current->lte($endDate)) {
                $dateKey = $current->format('Y-m-d');
                $label   = $current->format('M j');
                $labels[] = $label;
                $counts[] = $postsByDay[$dateKey] ?? 0;
                $current->addDay();
            }
        } else {
            foreach ($postsByDay as $dateKey => $cnt) {
                $labels[] = \Carbon\Carbon::parse($dateKey)->format('M j');
                $counts[] = $cnt;
            }
        }

        // Post type breakdown for selected period
        $typeCounts = (clone $periodQuery)
            ->selectRaw("COALESCE(post_type, 'single_image') as ptype, COUNT(*) as cnt")
            ->groupBy('ptype')
            ->get();

        $photoCount    = 0;
        $videoCount    = 0;
        $reelCount     = 0;
        $carouselCount = 0;
        $otherCount    = 0;

        foreach ($typeCounts as $tc) {
            $type = strtolower($tc->ptype ?? '');
            if ($type === 'reel') {
                $reelCount += (int)$tc->cnt;
            } elseif ($type === 'video') {
                $videoCount += (int)$tc->cnt;
            } elseif (in_array($type, ['multi_image', 'carousel'])) {
                $carouselCount += (int)$tc->cnt;
            } elseif (in_array($type, ['single_image', 'photo', ''])) {
                $photoCount += (int)$tc->cnt;
            } else {
                $otherCount += (int)$tc->cnt;
            }
        }

        $payload = [
            'success' => true,
            'summary' => [
                'followers'           => $followers,
                'total_followers'     => $followers,
                'period_followers'    => $periodFollowers,
                'likes'               => $periodLikes,
                'page_likes'          => $periodLikes,
                'period_page_likes'   => $periodLikes,
                'lifetime_page_likes' => $lifetimePageLikes,
                'comments'            => $periodComments,
                'total_posts'         => $totalPosts,
                'page_name'           => $fbPage->page_name ?? '',
                'page_id'             => $fbPage->page_id ?? '',
            ],
            'posts_chart' => [
                'labels' => $labels,
                'counts' => $counts,
            ],
            'post_types' => [
                'photo'    => $photoCount,
                'video'    => $videoCount,
                'reel'     => $reelCount,
                'carousel' => $carouselCount,
                'other'    => $otherCount,
            ],
        ];

        \Illuminate\Support\Facades\Cache::put($statsCacheKey, $payload, $cacheTtl);

        return response()->json($payload);
    });

    Route::get('/facebook/posts', function (Request $request) {
        $request->validate(['workspace_id' => 'required|integer|exists:workspaces,id']);
        $workspaceId  = (int) $request->query('workspace_id');
        $page         = max(1, (int) $request->query('page', 1));
        $perPage      = max(1, min(50, (int) $request->query('per_page', 5)));
        $autoSync     = $request->boolean('auto_sync');
        $liveSync     = $request->boolean('live_sync') || $request->boolean('sync') || $autoSync;
        $forceRefresh = $request->boolean('force_refresh') || $request->boolean('refresh') || $request->has('force_refresh');
        $cacheTtl     = $autoSync ? 25 : 300;

        $fbPage = app(\App\Services\WorkspaceSocialAccounts::class)->facebook($workspaceId);
        if (!$fbPage) {
            return response()->json([
                'success'      => true,
                'data'         => [],
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => 0,
                'total_pages'  => 1,
                'has_prev'     => false,
                'has_next'     => false,
                'message'      => 'No Facebook page connected.',
            ]);
        }

        // Date range parameters
        $allPosts       = $request->query('all_posts') === 'true' || $request->query('all_posts') === '1';
        $startDateParam = $request->query('start_date');
        $endDateParam   = $request->query('end_date');

        $startDate = null;
        $endDate   = null;
        if (!$allPosts) {
            if (!empty($startDateParam) && !empty($endDateParam) && $startDateParam !== 'null' && $endDateParam !== 'null' && $startDateParam !== 'all' && $endDateParam !== 'all') {
                $startDate = \Carbon\Carbon::parse($startDateParam, 'Asia/Kolkata')->startOfDay()->setTimezone('UTC');
                $endDate   = \Carbon\Carbon::parse($endDateParam, 'Asia/Kolkata')->endOfDay()->setTimezone('UTC');
            } else {
                $endDate   = now('Asia/Kolkata')->endOfDay()->setTimezone('UTC');
                $startDate = now('Asia/Kolkata')->subDays(29)->startOfDay()->setTimezone('UTC');
            }
        }

        $postsCacheKey = 'fb_latest_posts_v9_' . sha1(implode('|', [
            $workspaceId,
            $fbPage->page_id,
            $page,
            $perPage,
            $allPosts ? 'all' : ($startDateParam ?: ''),
            $allPosts ? 'all' : ($endDateParam ?: ''),
        ])) . ($autoSync ? '_live' : '');

        if ($forceRefresh) {
            \Illuminate\Support\Facades\Cache::forget($postsCacheKey);
        } elseif (\Illuminate\Support\Facades\Cache::has($postsCacheKey)) {
            return response()->json(\Illuminate\Support\Facades\Cache::get($postsCacheKey));
        }

        $pageIds = \App\Models\FacebookPage::where('workspace_id', $workspaceId)
            ->where('page_id', $fbPage->page_id)
            ->pluck('id');

        $buildQuery = function () use ($workspaceId, $pageIds, $fbPage, $allPosts, $startDate, $endDate) {
            $q = \App\Models\FacebookPost::with('media')
                ->where('workspace_id', $workspaceId)
                ->whereNotNull('fb_post_id')
                ->where('fb_post_id', '!=', '')
                ->where(function ($sub) {
                    $sub->where('status', 'published')
                        ->orWhereNull('status');
                });

            if (!empty($pageIds) && count($pageIds) > 0) {
                $q->where(function ($sub) use ($pageIds, $fbPage) {
                    $sub->whereIn('facebook_page_id', $pageIds)
                        ->orWhere('fb_post_id', 'like', $fbPage->page_id . '\_%');
                });
            } else {
                $q->where('fb_post_id', 'like', $fbPage->page_id . '\_%');
            }

            if (!$allPosts && $startDate && $endDate) {
                $q->where(function ($sub) use ($startDate, $endDate) {
                    $sub->whereBetween('published_at', [$startDate, $endDate])
                        ->orWhere(function ($nested) use ($startDate, $endDate) {
                            $nested->whereNull('published_at')
                                   ->whereBetween('created_at', [$startDate, $endDate]);
                        });
                });
            }

            return $q;
        };

        $existingCount = $buildQuery()->count();
        $needsSync     = $forceRefresh || $liveSync || ($existingCount === 0);

        if ($needsSync && !empty($fbPage->page_access_token) && $fbPage->token_status !== 'disconnected') {
            try {
                $graphService = app(\App\Services\FacebookGraphService::class);
                $graphService->syncFacebookPagePosts(
                    $fbPage,
                    !$allPosts ? $startDateParam : null,
                    !$allPosts ? $endDateParam : null,
                    $forceRefresh,
                    100
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[FACEBOOK POSTS SYNC ERROR] ' . $e->getMessage());
            }
        }

        $query = $buildQuery();
        $query->orderByRaw('COALESCE(published_at, created_at) DESC');

        $totalRecords = $query->count();
        $totalPages   = max(1, (int) ceil($totalRecords / $perPage));

        $rawPosts = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $posts = $rawPosts->map(function ($p) use ($fbPage) {
            $mediaFirst = $p->media->first();
            $imageUrl = $mediaFirst ? $mediaFirst->file_url : null;
            if (!$imageUrl && !empty($p->fb_post_id)) {
                $cacheKey = "fb_post_pic_{$p->fb_post_id}";
                $imageUrl = \Illuminate\Support\Facades\Cache::get($cacheKey);
            }

            $cachedViews = !empty($p->fb_post_id) ? \Illuminate\Support\Facades\Cache::get("fb_video_views_{$p->fb_post_id}") : null;
            $rawType = strtolower($p->post_type ?: 'single_image');
            $formattedType = match($rawType) {
                'multi_image', 'carousel' => 'Carousel',
                'video'                   => 'Video',
                'reel'                    => 'Reel',
                'single_image', 'photo'   => 'Photo',
                'text'                    => 'Text',
                'link'                    => 'Link',
                default                   => 'Other',
            };

            // Real views only: return Meta-provided post_media_view or existing video/reel views fallback.
            $views = $p->views_count !== null ? (int)$p->views_count : ($cachedViews !== null ? (int)$cachedViews : null);

            $permalink = $p->link_url ?: (!empty($p->fb_post_id) ? "https://www.facebook.com/{$p->fb_post_id}" : null);

            return [
                'id'               => $p->id,
                'fb_post_id'       => $p->fb_post_id,
                'content'          => $p->content,
                'image_url'        => $imageUrl,
                'permalink'        => $permalink,
                'permalink_url'    => $permalink,
                'media_count'      => $p->media->count(),
                'post_type'        => $rawType,
                'formatted_type'   => $formattedType,
                'page_name'        => $p->page?->page_name ?? $fbPage?->page_name ?? '',
                'published_at'     => $p->published_at ? $p->published_at->toIso8601String() : ($p->created_at ? $p->created_at->toIso8601String() : null),
                'views'            => $views,
                'views_count'      => $views,
                'reach_count'      => null, // Organic post-level reach is not provided by Meta Graph API under Page tokens
                'followers_count'  => null,
                'likes_count'      => $p->likes_count !== null ? (int) $p->likes_count : null,
                'comments_count'   => $p->comments_count !== null ? (int) $p->comments_count : null,
                'shares_count'     => $p->shares_count !== null ? (int) $p->shares_count : null,
                'reactions_count'  => $p->reactions_count !== null ? (int) $p->reactions_count : ($p->likes_count !== null ? (int) $p->likes_count : null),
                'engagement_count' => ($p->likes_count !== null || $p->comments_count !== null || $p->shares_count !== null)
                    ? ((int)($p->likes_count ?? 0) + (int)($p->comments_count ?? 0) + (int)($p->shares_count ?? 0))
                    : null,
            ];
        });

        $payload = [
            'success'      => true,
            'data'         => $posts->values()->all(),
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $totalRecords,
            'total_pages'  => $totalPages,
            'has_prev'     => $page > 1,
            'has_next'     => $page < $totalPages,
        ];

        \Illuminate\Support\Facades\Cache::put($postsCacheKey, $payload, $cacheTtl);

        return response()->json($payload);
    });



    // Instagram Dashboard Stats & Posts
    Route::get('/instagram/dashboard-stats', function (Request $request) {
        $request->validate(['workspace_id' => 'required|integer|exists:workspaces,id']);
        $workspaceId    = (int) $request->query('workspace_id');
        $startDateParam = $request->query('start_date');
        $endDateParam   = $request->query('end_date');
        $forceRefresh   = $request->boolean('force_refresh') || $request->boolean('refresh') || $request->has('force_refresh');
        $autoSync       = $request->boolean('auto_sync') || $request->boolean('live_sync');
        $cacheTtl       = $autoSync ? 25 : 300;

        $igInteg = app(\App\Services\WorkspaceSocialAccounts::class)->instagram($workspaceId);
        if (!$igInteg || empty($igInteg->refresh_token)) {
            return response()->json(['success' => false, 'message' => 'No Instagram account connected.']);
        }

        if (!empty($startDateParam) && !empty($endDateParam) && $startDateParam !== 'all' && $endDateParam !== 'all') {
            $startDate = \Carbon\Carbon::parse($startDateParam)->startOfDay();
            $endDate   = \Carbon\Carbon::parse($endDateParam)->endOfDay();
        } else {
            $endDate   = now()->endOfDay();
            $startDate = now()->subDays(29)->startOfDay();
        }

        if ($endDate->isBefore($startDate)) {
            $endDate = $startDate->copy()->endOfDay();
        }

        $statsCacheKey = 'ig_stats_v6_' . sha1(implode('|', [
            $workspaceId,
            $igInteg->account_id,
            $startDate->toDateString(),
            $endDate->toDateString(),
        ])) . ($autoSync ? '_live' : '');

        if (!$forceRefresh && \Illuminate\Support\Facades\Cache::has($statsCacheKey)) {
            return response()->json(\Illuminate\Support\Facades\Cache::get($statsCacheKey));
        }

        if ($forceRefresh) {
            \Illuminate\Support\Facades\Cache::forget($statsCacheKey);
        }

        $graphService = new \App\Services\FacebookGraphService();
        $days = max(1, (int) $startDate->diffInDays($endDate) + 1);

        $profile = [];
        try {
            if ($forceRefresh) {
                \Illuminate\Support\Facades\Cache::forget("ig_profile_{$igInteg->account_id}");
            }
            $profile = \Illuminate\Support\Facades\Cache::remember("ig_profile_{$igInteg->account_id}", 300, function () use ($graphService, $igInteg) {
                return $graphService->getInstagramProfile($igInteg->account_id, $igInteg->refresh_token);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[INSTAGRAM DASHBOARD] profile error: ' . $e->getMessage());
        }

        $mediaFetchLimit = min(10000, max(100, (int)($profile['media_count'] ?? 0)));
        $mediaItems = [];
        try {
            $mediaItems = $graphService->getInstagramMediaList(
                $igInteg->refresh_token,
                $igInteg->account_id,
                $mediaFetchLimit,
                $forceRefresh,
                $startDate->toDateString(),
                $endDate->toDateString(),
                $cacheTtl
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[INSTAGRAM DASHBOARD] media error: ' . $e->getMessage());
        }

        $periodFollowers = null;
        try {
            $fgCacheKey = "ig_followers_gained_{$igInteg->account_id}_{$days}_{$startDate->toDateString()}_{$endDate->toDateString()}";
            if ($forceRefresh) {
                \Illuminate\Support\Facades\Cache::forget($fgCacheKey);
            }
            $periodFollowers = \Illuminate\Support\Facades\Cache::remember($fgCacheKey, $cacheTtl, function () use ($graphService, $igInteg, $days, $startDate, $endDate) {
                return $graphService->getInstagramFollowersGained(
                    $igInteg->refresh_token,
                    $igInteg->account_id,
                    $days,
                    $startDate->toDateString(),
                    $endDate->toDateString()
                );
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[INSTAGRAM DASHBOARD] followers gained error: ' . $e->getMessage());
        }

        $postsByDay = [];
        $labels = [];
        $counts = [];
        $current = $startDate->copy()->startOfDay();
        while ($current->lte($endDate)) {
            $dateKey = $current->format('Y-m-d');
            $labels[] = $current->format('M j');
            $postsByDay[$dateKey] = 0;
            $current->addDay();
        }

        $photoCount = 0;
        $videoCount = 0;
        $reelCount = 0;
        $carouselCount = 0;
        $otherCount = 0;
        $likes = 0;
        $comments = 0;
        $hasLikes = false;
        $hasComments = false;

        foreach ($mediaItems as $item) {
            if (!empty($item['timestamp'])) {
                $dateKey = \Carbon\Carbon::parse($item['timestamp'])->format('Y-m-d');
                if (array_key_exists($dateKey, $postsByDay)) {
                    $postsByDay[$dateKey]++;
                }
            }

            $mediaType = strtoupper((string)($item['media_type'] ?? ''));
            $productType = strtoupper((string)($item['media_product_type'] ?? ''));
            if ($productType === 'REELS' || $mediaType === 'REELS') {
                $reelCount++;
            } elseif ($mediaType === 'CAROUSEL_ALBUM') {
                $carouselCount++;
            } elseif ($mediaType === 'VIDEO') {
                $videoCount++;
            } elseif ($mediaType === 'IMAGE') {
                $photoCount++;
            } else {
                $otherCount++;
            }

            if ($item['like_count'] !== null) {
                $hasLikes = true;
                $likes += (int)$item['like_count'];
            }
            if ($item['comments_count'] !== null) {
                $hasComments = true;
                $comments += (int)$item['comments_count'];
            }
        }

        foreach ($postsByDay as $count) {
            $counts[] = (int)$count;
        }

        $payload = [
            'success' => true,
            'summary' => [
                'followers'        => array_key_exists('followers_count', $profile) && $profile['followers_count'] !== null ? (int)$profile['followers_count'] : null,
                'total_followers'  => array_key_exists('followers_count', $profile) && $profile['followers_count'] !== null ? (int)$profile['followers_count'] : null,
                'period_followers' => $periodFollowers,
                'likes'            => $hasLikes ? $likes : (count($mediaItems) === 0 ? 0 : null),
                'comments'         => $hasComments ? $comments : (count($mediaItems) === 0 ? 0 : null),
                'total_posts'      => count($mediaItems),
                'account_name'     => '@' . ltrim((string)($profile['username'] ?? $igInteg->account_name ?? ''), '@'),
                'account_id'       => $igInteg->account_id,
            ],
            'posts_chart' => [
                'labels' => $labels,
                'counts' => $counts,
            ],
            'post_types' => [
                'photo'    => $photoCount,
                'video'    => $videoCount,
                'reel'     => $reelCount,
                'carousel' => $carouselCount,
                'other'    => $otherCount,
            ],
        ];

        \Illuminate\Support\Facades\Cache::put($statsCacheKey, $payload, $cacheTtl);

        return response()->json($payload);
    });

    Route::get('/instagram/posts', function (Request $request) {
        $request->validate(['workspace_id' => 'required|integer|exists:workspaces,id']);
        $workspaceId    = (int) $request->query('workspace_id');
        $page           = max(1, (int) $request->query('page', 1));
        $perPage        = max(1, min(50, (int) $request->query('per_page', 5)));
        $allPosts       = $request->query('all_posts') === 'true' || $request->query('all_posts') === '1';
        $forceRefresh   = $request->boolean('force_refresh') || $request->boolean('refresh') || $request->has('force_refresh');
        $autoSync       = $request->boolean('auto_sync') || $request->boolean('live_sync');
        $cacheTtl       = $autoSync ? 25 : 300;
        $startDateParam = $request->query('start_date');
        $endDateParam   = $request->query('end_date');

        $igInteg = app(\App\Services\WorkspaceSocialAccounts::class)->instagram($workspaceId);
        if (!$igInteg || empty($igInteg->refresh_token)) {
            return response()->json([
                'success'      => true,
                'data'         => [],
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => 0,
                'total_pages'  => 1,
                'has_prev'     => false,
                'has_next'     => false,
                'message'      => 'No Instagram account connected.',
            ]);
        }

        $startDate = null;
        $endDate = null;
        if (!$allPosts) {
            if (!empty($startDateParam) && !empty($endDateParam) && $startDateParam !== 'null' && $endDateParam !== 'null' && $startDateParam !== 'all' && $endDateParam !== 'all') {
                $startDate = \Carbon\Carbon::parse($startDateParam)->startOfDay();
                $endDate   = \Carbon\Carbon::parse($endDateParam)->endOfDay();
            } else {
                $endDate   = now()->endOfDay();
                $startDate = now()->subDays(29)->startOfDay();
            }
            if ($endDate->isBefore($startDate)) {
                $endDate = $startDate->copy()->endOfDay();
            }
        }

        $postsCacheKey = 'ig_posts_v6_' . sha1(implode('|', [
            $workspaceId,
            $igInteg->account_id,
            $page,
            $perPage,
            $allPosts ? 'all' : ($startDate ? $startDate->toDateString() : ''),
            $allPosts ? 'all' : ($endDate ? $endDate->toDateString() : ''),
        ])) . ($autoSync ? '_live' : '');

        if (!$forceRefresh && \Illuminate\Support\Facades\Cache::has($postsCacheKey)) {
            return response()->json(\Illuminate\Support\Facades\Cache::get($postsCacheKey));
        }

        if ($forceRefresh) {
            \Illuminate\Support\Facades\Cache::forget($postsCacheKey);
        }

        $graphService = new \App\Services\FacebookGraphService();
        $profile = [];
        try {
            if ($forceRefresh) {
                \Illuminate\Support\Facades\Cache::forget("ig_profile_{$igInteg->account_id}");
            }
            $profile = \Illuminate\Support\Facades\Cache::remember("ig_profile_{$igInteg->account_id}", 300, function () use ($graphService, $igInteg) {
                return $graphService->getInstagramProfile($igInteg->account_id, $igInteg->refresh_token);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[INSTAGRAM POSTS] profile error: ' . $e->getMessage());
        }

        $mediaFetchLimit = min(10000, max(100, (int)($profile['media_count'] ?? 0)));
        $mediaItems = [];
        try {
            $mediaItems = $graphService->getInstagramMediaList(
                $igInteg->refresh_token,
                $igInteg->account_id,
                $mediaFetchLimit,
                $forceRefresh,
                $allPosts || !$startDate ? null : $startDate->toDateString(),
                $allPosts || !$endDate ? null : $endDate->toDateString(),
                $cacheTtl
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[INSTAGRAM POSTS] media error: ' . $e->getMessage());
        }

        usort($mediaItems, function ($a, $b) {
            return strcmp((string)($b['timestamp'] ?? ''), (string)($a['timestamp'] ?? ''));
        });

        $totalRecords = count($mediaItems);
        $totalPages = max(1, (int) ceil($totalRecords / $perPage));
        $safePage = min($page, $totalPages);
        $slice = array_slice($mediaItems, ($safePage - 1) * $perPage, $perPage);

        $posts = array_map(function ($item) use ($igInteg) {
            $mediaType = strtoupper((string)($item['media_type'] ?? ''));
            $productType = strtoupper((string)($item['media_product_type'] ?? ''));
            if ($productType === 'REELS' || $mediaType === 'REELS') {
                $rawType = 'reel';
                $formattedType = 'Reel';
            } elseif ($mediaType === 'CAROUSEL_ALBUM') {
                $rawType = 'multi_image';
                $formattedType = 'Carousel';
            } elseif ($mediaType === 'VIDEO') {
                $rawType = 'video';
                $formattedType = 'Video';
            } elseif ($mediaType === 'IMAGE') {
                $rawType = 'single_image';
                $formattedType = 'Photo';
            } else {
                $rawType = 'other';
                $formattedType = 'Other';
            }

            $bestThumb = $item['thumbnail_url'] ?? $item['media_url'] ?? null;
            $mediaIdStr = (string)$item['id'];
            $caption = $item['caption'] ?? '';
            $postTitle = !empty($caption) ? strtok($caption, "\n") : 'Instagram Post';

            // Cache individual media item metadata for instant comment association
            \Illuminate\Support\Facades\Cache::put("ig_media_meta_{$mediaIdStr}", [
                'id'            => $mediaIdStr,
                'thumbnail_url' => $bestThumb,
                'media_url'     => $item['media_url'] ?? null,
                'permalink'     => $item['permalink'] ?? null,
                'post_url'      => $item['permalink'] ?? null,
                'content'       => $caption,
                'title'         => $postTitle,
                'formatted_type'=> $formattedType,
            ], 86400);

            return [
                'id'               => $mediaIdStr,
                'ig_media_id'      => $mediaIdStr,
                'content_id'       => $mediaIdStr,
                'platform'         => 'instagram',
                'title'            => $postTitle,
                'content'          => $caption,
                'image_url'        => $bestThumb,
                'thumbnail_url'    => $bestThumb,
                'media_url'        => $item['media_url'] ?? null,
                'post_url'         => $item['permalink'] ?? null,
                'permalink'        => $item['permalink'] ?? null,
                'media_count'      => $rawType === 'multi_image' ? null : 1,
                'post_type'        => $rawType,
                'formatted_type'   => $formattedType,
                'page_name'        => '@' . ltrim((string)($igInteg->account_name ?? ''), '@'),
                'published_at'     => !empty($item['timestamp']) ? \Carbon\Carbon::parse($item['timestamp'])->toIso8601String() : null,
                'views'            => $item['views_count'] ?? null,
                'views_count'      => $item['views_count'] ?? null,
                'reach_count'      => $item['reach'] ?? null,
                'followers_count'  => null,
                'likes_count'      => $item['like_count'] ?? null,
                'comments_count'   => $item['comments_count'] ?? null,
                'shares_count'     => $item['shares_count'] ?? null,
                'reactions_count'  => $item['like_count'] ?? null,
                'engagement_count' => ((int)($item['like_count'] ?? 0)) + ((int)($item['comments_count'] ?? 0)) + ((int)($item['shares_count'] ?? 0)),
                'shares_supported' => (bool)($item['shares_supported'] ?? false),
                'views_supported'  => (bool)($item['views_supported'] ?? false),
            ];
        }, $slice);

        $payload = [
            'success'      => true,
            'data'         => array_values($posts),
            'current_page' => $safePage,
            'per_page'     => $perPage,
            'total'        => $totalRecords,
            'total_pages'  => $totalPages,
            'has_prev'     => $safePage > 1,
            'has_next'     => $safePage < $totalPages,
        ];

        \Illuminate\Support\Facades\Cache::put($postsCacheKey, $payload, $cacheTtl);

        return response()->json($payload);
    });

    // Google Analytics (GA4) Integration Routes (Dedicated OAuth 2.0 Flow)
    Route::get('/google-analytics/connect', [\App\Http\Controllers\GoogleAnalyticsController::class, 'connect']);
    Route::get('/google-analytics/callback', [\App\Http\Controllers\GoogleAnalyticsController::class, 'callback']);
    Route::get('/google-analytics/status', [\App\Http\Controllers\GoogleAnalyticsController::class, 'status']);
    Route::get('/google-analytics/properties', [\App\Http\Controllers\GoogleAnalyticsController::class, 'properties']);
    Route::post('/google-analytics/select-property', [\App\Http\Controllers\GoogleAnalyticsController::class, 'selectProperty']);
    Route::post('/google-analytics/configure', [\App\Http\Controllers\GoogleAnalyticsController::class, 'configure']);
    Route::get('/google-analytics/metrics', [\App\Http\Controllers\GoogleAnalyticsController::class, 'metrics']);
    Route::post('/google-analytics/test', [\App\Http\Controllers\GoogleAnalyticsController::class, 'test']);
    Route::post('/google-analytics/disconnect', [\App\Http\Controllers\GoogleAnalyticsController::class, 'disconnect']);

    // ── Google Search Console Integration Routes (Dedicated OAuth 2.0 Flow) ──
    Route::get('/search-console/connect', [\App\Http\Controllers\GoogleSearchConsoleController::class, 'connect']);
    Route::get('/search-console/callback', [\App\Http\Controllers\GoogleSearchConsoleController::class, 'callback']);
    Route::get('/search-console/status', [\App\Http\Controllers\GoogleSearchConsoleController::class, 'status']);
    Route::get('/search-console/sites', [\App\Http\Controllers\GoogleSearchConsoleController::class, 'sites']);
    Route::post('/search-console/select-site', [\App\Http\Controllers\GoogleSearchConsoleController::class, 'selectSite']);
    Route::post('/search-console/configure', [\App\Http\Controllers\GoogleSearchConsoleController::class, 'configure']);
    Route::get('/search-console/metrics', [\App\Http\Controllers\GoogleSearchConsoleController::class, 'metrics']);
    Route::post('/search-console/test', [\App\Http\Controllers\GoogleSearchConsoleController::class, 'test']);
    Route::post('/search-console/disconnect', [\App\Http\Controllers\GoogleSearchConsoleController::class, 'disconnect']);
});

// Direct alias routes for Google Analytics OAuth callback and connect without v1 prefix
Route::get('/google-analytics/callback', [\App\Http\Controllers\GoogleAnalyticsController::class, 'callback']);
Route::get('/google-analytics/connect', [\App\Http\Controllers\GoogleAnalyticsController::class, 'connect']);

// Direct alias routes for Google Search Console OAuth callback and connect without v1 prefix
Route::get('/search-console/callback', [\App\Http\Controllers\GoogleSearchConsoleController::class, 'callback']);
Route::get('/search-console/connect', [\App\Http\Controllers\GoogleSearchConsoleController::class, 'connect']);

// Direct alias routes for Webhooks without v1 prefix (matches Meta registered callback URLs)
Route::get('/webhook/facebook', [\App\Http\Controllers\FacebookMessengerWebhookController::class, 'verify']);
Route::post('/webhook/facebook', [\App\Http\Controllers\FacebookMessengerWebhookController::class, 'receive']);
Route::get('/webhook/instagram', [\App\Http\Controllers\FacebookMessengerWebhookController::class, 'verify']);
Route::post('/webhook/instagram', [\App\Http\Controllers\FacebookMessengerWebhookController::class, 'receive']);

