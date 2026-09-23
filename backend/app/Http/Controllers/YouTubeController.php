<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\YouTubeService;
use App\Models\YouTubeConnection;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use Exception;

class YouTubeController extends Controller
{
    protected YouTubeService $youtubeService;

    public function __construct(YouTubeService $youtubeService)
    {
        $this->youtubeService = $youtubeService;
    }

    /**
     * Resolve and validate workspace_id from request.
     * Prevents unintentional default fallbacks to workspace 1.
     */
    protected function getWorkspaceId(Request $request): int
    {
        $id = $request->input('workspace_id', $request->query('workspace_id'));
        if (!$id && $request->user()) {
            $id = $request->user()->current_workspace_id;
        }
        if (empty($id)) {
            // Default to first available workspace or fallback to 9
            $firstWs = \App\Models\Workspace::first();
            $id = $firstWs ? $firstWs->id : 9;
        }
        return (int)$id;
    }

    /**
     * Resolve YouTube connection for a workspace, automatically falling back
     * to any existing valid connection across workspaces if needed.
     */
    protected function resolveConnection(int $workspaceId): ?YouTubeConnection
    {
        // 1. Check if workspace is explicitly disconnected
        $integ = Integration::where('workspace_id', $workspaceId)->where('platform', 'youtube')->first();
        if ($integ && ($integ->connection_status === 'disconnected' || !$integ->is_connected)) {
            return null;
        }

        // 2. Direct workspace connection
        $connection = YouTubeConnection::where('workspace_id', $workspaceId)->first();
        if ($connection && (!empty($connection->refresh_token) || !empty($connection->access_token))) {
            try {
                $connection = $this->youtubeService->refreshAccessTokenIfNeeded($connection);
                if (!empty($connection->access_token)) {
                    return $connection;
                }
            } catch (\Throwable $e) {
                Log::warning("[YOUTUBE RESOLVE] Direct connection refresh failed for ws {$workspaceId}: " . $e->getMessage());
            }
        }

        return $connection;
    }

    /**
     * GET /api/youtube/connect
     * Redirect user to Google OAuth login screen.
     */
    public function connect(Request $request)
    {
        try {
            $workspaceId = $this->getWorkspaceId($request);
            $force       = $request->boolean('force') || $request->boolean('reconnect');

            // 1. Check if a valid YouTube connection already exists for this workspace or across workspaces
            if (!$force) {
                $existing = $this->resolveConnection($workspaceId);
                if ($existing && (!empty($existing->access_token) || !empty($existing->refresh_token))) {
                    try {
                        $existing = $this->youtubeService->refreshAccessTokenIfNeeded($existing);
                    } catch (\Throwable $refreshErr) {
                        Log::warning('[YOUTUBE OAUTH] Proactive token refresh notice: ' . $refreshErr->getMessage());
                    }

                    if (!empty($existing->access_token)) {
                        Log::info('[YOUTUBE OAUTH] Valid YouTube connection already exists. Reusing credentials.', [
                            'workspace_id' => $workspaceId,
                            'channel_id'   => $existing->channel_id,
                            'channel_name' => $existing->channel_name,
                        ]);

                        if ($request->wantsJson()) {
                            return response()->json([
                                'success'           => true,
                                'already_connected' => true,
                                'message'           => "YouTube channel '{$existing->channel_name}' is already connected and operational.",
                                'data'              => [
                                    'channel_id'   => $existing->channel_id,
                                    'channel_name' => $existing->channel_name,
                                ],
                            ]);
                        }

                        return $this->renderOAuthResponse(
                            true,
                            'Already Connected',
                            "YouTube channel '{$existing->channel_name}' is already connected and operational for this workspace. Reusing stored credentials.",
                            $existing,
                            $workspaceId
                        );
                    }
                }
            }

            $state = base64_encode(json_encode([
                'user_id'      => $request->user()?->id ?? 1,
                'workspace_id' => $workspaceId,
                'time'         => time(),
            ]));

            $loginHint = $request->query('login_hint', env('YOUTUBE_LOGIN_HINT', null));
            $authUrl = $this->youtubeService->getAuthUrl($state, $loginHint);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'url'     => $authUrl,
                ]);
            }

            return redirect()->away($authUrl);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        } catch (Exception $e) {
            Log::error('YouTube connect error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize Google OAuth connection: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/youtube/callback
     * Google OAuth Callback Endpoint.
     */
    public function callback(Request $request)
    {
        // 1. Check for OAuth error / denied permission
        if ($request->has('error')) {
            $error = $request->query('error_description', $request->query('error', 'User denied permission.'));
            return $this->renderOAuthResponse(false, 'Google OAuth Failed', $error);
        }

        $code = $request->query('code');
        $stateRaw = $request->query('state');

        if (!$code) {
            return $this->renderOAuthResponse(false, 'Invalid Request', 'Missing authorization code from Google.');
        }

        // 2. Validate state
        $userId = $request->user()?->id ?? 1;
        $workspaceId = null;
        if ($stateRaw) {
            $decoded = json_decode(base64_decode($stateRaw), true);
            if (is_array($decoded)) {
                if (isset($decoded['user_id'])) $userId = (int)$decoded['user_id'];
                if (isset($decoded['workspace_id'])) $workspaceId = (int)$decoded['workspace_id'];
            }
        }

        if (empty($workspaceId)) {
            return $this->renderOAuthResponse(false, 'Invalid State', 'Missing workspace_id in OAuth state parameter.');
        }

        try {
            // 3. Exchange code for tokens
            $tokens = $this->youtubeService->exchangeCodeForTokens($code);

            // 4. Call YouTube API & Get Channel Info
            $channelData = $this->youtubeService->getChannelInfo($tokens['access_token']);

            // 5. Save Channel Information & Tokens (encrypted and workspace scoped)
            $connection = $this->youtubeService->saveChannelConnection($userId, $tokens, $channelData, $workspaceId);

            // 6. Persist to unified integrations table without wiping existing refresh_token
            $integrationData = [
                'account_id'        => $channelData['channel_id'] ?? $connection->channel_id,
                'account_name'      => $channelData['channel_name'] ?? $connection->channel_name,
                'is_connected'      => true,
                'connection_status' => 'connected',
                'followers_count'   => $connection->subscriber_count ?? 0,
                'last_sync_at'      => now(),
            ];
            if (!empty($tokens['refresh_token'])) {
                $integrationData['refresh_token'] = $tokens['refresh_token'];
            }

            Integration::updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'platform'     => 'youtube',
                    'account_id'   => $channelData['channel_id'] ?? $connection->channel_id,
                ],
                $integrationData
            );

            // 7. Render OAuth response popup
            return $this->renderOAuthResponse(
                true,
                'YouTube Channel Connected!',
                "Successfully connected '{$connection->channel_name}'",
                $connection
            );

        } catch (Exception $e) {
            Log::error('YouTube OAuth Callback Failed', ['error' => $e->getMessage()]);
            return $this->renderOAuthResponse(false, 'YouTube Connection Error', $e->getMessage());
        }
    }

    /**
     * GET /api/youtube/status
     * Get connection status for YouTube for a workspace.
     */
    public function status(Request $request)
    {
        try {
            $workspaceId = $this->getWorkspaceId($request);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        }

        $connection = $this->resolveConnection($workspaceId);

        if (!$connection) {
            return response()->json([
                'success'   => true,
                'connected' => false,
                'message'   => 'YouTube is not connected for this workspace.',
                'data'      => null,
            ]);
        }

        $hasRefreshToken = !empty($connection->refresh_token);
        $isExpiredWithoutRefresh = $connection->token_expires_at && $connection->token_expires_at->isPast() && !$hasRefreshToken;

        return response()->json([
            'success'   => true,
            'connected' => true,
            'data'      => [
                'id'                  => $connection->id,
                'workspace_id'        => $workspaceId,
                'channel_id'          => $connection->channel_id,
                'channel_name'        => $connection->channel_name,
                'channel_description' => $connection->channel_description,
                'channel_thumbnail'   => $connection->channel_thumbnail,
                'subscriber_count'    => $connection->subscriber_count,
                'video_count'         => $connection->video_count,
                'view_count'          => $connection->view_count,
                'has_refresh_token'   => $hasRefreshToken,
                'token_valid'         => !$isExpiredWithoutRefresh,
                'token_expires_at'    => $connection->token_expires_at?->toIso8601String(),
                'created_at'          => $connection->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/youtube/channel
     * Retrieve live connected YouTube Channel statistics from YouTube API.
     */
    public function channel(Request $request)
    {
        try {
            $workspaceId = $this->getWorkspaceId($request);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        }

        $connection = $this->resolveConnection($workspaceId);

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'YouTube account not connected for this workspace.',
            ], 404);
        }

        try {
            $connection = $this->youtubeService->refreshAccessTokenIfNeeded($connection);
            $channelData = $this->youtubeService->getChannelInfo($connection->access_token, $connection->channel_id);

            $connection->update([
                'channel_name'        => $channelData['channel_name'],
                'channel_description' => $channelData['channel_description'],
                'channel_thumbnail'   => $channelData['channel_thumbnail'],
                'subscriber_count'    => $channelData['subscriber_count'],
                'video_count'         => $channelData['video_count'],
                'view_count'          => $channelData['view_count'],
            ]);

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'                  => $connection->id,
                    'workspace_id'        => $workspaceId,
                    'channel_id'          => $connection->channel_id,
                    'channel_name'        => $connection->channel_name,
                    'channel_description' => $connection->channel_description,
                    'channel_thumbnail'   => $connection->channel_thumbnail,
                    'subscriber_count'    => $connection->subscriber_count,
                    'video_count'         => $connection->video_count,
                    'view_count'          => $connection->view_count,
                    'token_expires_at'    => $connection->token_expires_at?->toIso8601String(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Fetch YouTube channel failed', ['error' => $e->getMessage()]);

            $isRevoked = str_contains($e->getMessage(), 'invalid_grant') || str_contains($e->getMessage(), 'revoked');
            return response()->json([
                'success'                  => false,
                'reauthorization_required' => $isRevoked,
                'message'                  => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /api/youtube/disconnect
     * Disconnect connected YouTube channel.
     */
    public function disconnect(Request $request)
    {
        try {
            $workspaceId = $this->getWorkspaceId($request);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        }

        // Delete active workspace connection and clear credentials
        YouTubeConnection::where('workspace_id', $workspaceId)->delete();

        Integration::where('workspace_id', $workspaceId)->where('platform', 'youtube')->update([
            'is_connected'      => false,
            'connection_status' => 'disconnected',
            'refresh_token'     => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'YouTube channel disconnected successfully.',
        ]);
    }

    /**
     * POST /api/youtube/videos
     * Upload a video to YouTube with optional thumbnail, tags, category, and playlist.
     */
    public function uploadVideo(Request $request)
    {
        $request->validate([
            'video'          => 'required|file|mimes:mp4,mov,avi,mkv,webm|max:512000', // 500MB
            'title'          => 'nullable|string|max:100',
            'description'    => 'nullable|string',
            'privacy_status' => 'nullable|in:public,unlisted,private',
            'tags'           => 'nullable',
            'category_id'    => 'nullable|string',
            'thumbnail'      => 'nullable|file|image|max:5120', // 5MB
            'playlist_id'    => 'nullable|string',
        ]);

        try {
            $workspaceId = $this->getWorkspaceId($request);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        }

        $connection = $this->resolveConnection($workspaceId);
        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'No connected YouTube Channel found for this workspace. Please connect YouTube first.',
            ], 400);
        }

        try {
            $videoFile = $request->file('video');
            $title = $request->input('title') ?: 'Uploaded Video ' . date('Y-m-d H:i');
            $description = $request->input('description', '');
            $privacyStatus = $request->input('privacy_status', 'public');
            $categoryId = $request->input('category_id', '22');

            // Parse tags
            $tags = $request->input('tags');
            if (is_string($tags)) {
                $tags = array_map('trim', explode(',', $tags));
            } elseif (!is_array($tags)) {
                $tags = null;
            }

            // Thumbnail path
            $thumbnailPath = null;
            if ($request->hasFile('thumbnail')) {
                $thumbnailPath = $request->file('thumbnail')->getRealPath();
            }

            $playlistId = $request->input('playlist_id');

            $result = $this->youtubeService->uploadVideo(
                $connection,
                $videoFile->getRealPath(),
                $title,
                $description,
                $privacyStatus,
                $tags,
                $categoryId,
                $thumbnailPath,
                $playlistId
            );

            return response()->json([
                'success'  => true,
                'message'  => 'Video uploaded to YouTube successfully!',
                'data'     => $result,
                'video_id' => $result['video_id'],
                'url'      => $result['url'],
            ]);
        } catch (Exception $e) {
            Log::error('YouTube uploadVideo endpoint error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload video: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/youtube/videos/{id}
     * Retrieve full video details for a specific video ID.
     */
    public function videoDetails(Request $request, $id)
    {
        try {
            $workspaceId = $this->getWorkspaceId($request);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        }

        $connection = $this->resolveConnection($workspaceId);

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'YouTube account not connected for this workspace.',
            ], 404);
        }

        try {
            $details = $this->youtubeService->getVideoDetails($connection, $id);
            return response()->json([
                'success' => true,
                'data'    => $details,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve video details: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /api/youtube/videos/{id}/analytics
     * GET /api/youtube/video-analytics?video_id={id}
     * Retrieve video metadata, performance metrics, and audience retention curve for a specific video.
     */
    public function videoAnalytics(Request $request, ?string $id = null)
    {
        try {
            $workspaceId = $this->getWorkspaceId($request);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        }

        $videoId = $id ?: $request->input('video_id', $request->query('video_id'));
        if (empty($videoId)) {
            return response()->json([
                'success' => false,
                'message' => 'Video ID is required.',
            ], 400);
        }

        $connection = $this->resolveConnection($workspaceId);
        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'YouTube account not connected for this workspace.',
            ], 404);
        }

        try {
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');
            $forceRefresh = $request->boolean('force_refresh', false);

            $result = $this->youtubeService->getVideoAnalyticsAndRetention($connection, $videoId, $startDate, $endDate, $forceRefresh);

            return response()->json($result);
        } catch (Exception $e) {
            Log::error("YouTube videoAnalytics error for video {$videoId}: " . $e->getMessage());

            return response()->json([
                'success'  => false,
                'message'  => 'Failed to retrieve video analytics: ' . $e->getMessage(),
                'video_id' => $videoId,
            ], 500);
        }
    }

    /**
     * POST /api/youtube/videos/{id}/thumbnail
     * Upload custom thumbnail image for a video.
     */
    public function uploadThumbnail(Request $request, $id)
    {
        $request->validate([
            'thumbnail' => 'required|file|image|max:5120',
        ]);

        try {
            $workspaceId = $this->getWorkspaceId($request);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        }

        $connection = $this->resolveConnection($workspaceId);

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'YouTube account not connected for this workspace.',
            ], 404);
        }

        try {
            $thumbnailPath = $request->file('thumbnail')->getRealPath();
            $res = $this->youtubeService->uploadThumbnail($connection, $id, $thumbnailPath);

            return response()->json([
                'success' => true,
                'message' => 'Thumbnail uploaded successfully.',
                'data'    => $res,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload thumbnail: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/youtube/playlists
     * List playlists belonging to the connected channel.
     */
    public function playlists(Request $request)
    {
        try {
            $workspaceId = $this->getWorkspaceId($request);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        }

        $connection = $this->resolveConnection($workspaceId);

        if (!$connection) {
            return response()->json([
                'success'   => false,
                'connected' => false,
                'message'   => 'YouTube account not connected for this workspace.',
                'data'      => [],
            ], 404);
        }

        try {
            $maxResults = (int)$request->query('max_results', 50);
            $playlists = $this->youtubeService->getPlaylists($connection, $maxResults);

            return response()->json([
                'success' => true,
                'data'    => $playlists,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load playlists: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/youtube/playlists
     * Create a new playlist.
     */
    public function createPlaylist(Request $request)
    {
        $request->validate([
            'title'          => 'required|string|max:150',
            'description'    => 'nullable|string',
            'privacy_status' => 'nullable|in:public,unlisted,private',
        ]);

        try {
            $workspaceId = $this->getWorkspaceId($request);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        }

        $connection = $this->resolveConnection($workspaceId);

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'YouTube account not connected for this workspace.',
            ], 404);
        }

        try {
            $title = $request->input('title');
            $description = $request->input('description', '');
            $privacy = $request->input('privacy_status', 'public');

            $res = $this->youtubeService->createPlaylist($connection, $title, $description, $privacy);

            return response()->json([
                'success' => true,
                'message' => 'Playlist created successfully.',
                'data'    => $res,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create playlist: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/youtube/playlists/add-video
     * Add a video to a playlist.
     */
    public function addVideoToPlaylist(Request $request)
    {
        $request->validate([
            'playlist_id' => 'required|string',
            'video_id'    => 'required|string',
        ]);

        try {
            $workspaceId = $this->getWorkspaceId($request);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        }

        $connection = $this->resolveConnection($workspaceId);

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'YouTube account not connected for this workspace.',
            ], 404);
        }

        try {
            $playlistId = $request->input('playlist_id');
            $videoId = $request->input('video_id');

            $res = $this->youtubeService->addVideoToPlaylist($connection, $playlistId, $videoId);

            return response()->json([
                'success' => true,
                'message' => 'Video added to playlist successfully.',
                'data'    => $res,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add video to playlist: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/youtube/analytics/overview
     * Retrieve YouTube Analytics metrics (Views, Likes, Comments, Shares, Watch Time, Subscribers) for connected channel.
     */
    public function analyticsOverview(Request $request)
    {
        try {
            $workspaceId = $this->getWorkspaceId($request);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        }

        $connection = $this->resolveConnection($workspaceId);

        if (!$connection) {
            return response()->json([
                'success'                  => false,
                'connected'                => false,
                'reauthorization_required' => false,
                'message'                  => 'YouTube account not connected. Please connect YouTube channel in Integrations.',
                'views'                    => 0,
                'likes'                    => 0,
                'comments'                 => 0,
                'shares'                   => 0,
                'watch_time_hours'         => 0.0,
                'data'                     => null,
            ], 404);
        }

        try {
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');
            $forceRefresh = $request->boolean('force_refresh', false);
            $autoSync = $request->boolean('auto_sync', false) || $request->boolean('live_sync', false);
            $cacheTtl = $autoSync ? 30 : null;

            $analytics = $this->youtubeService->getAnalyticsOverview($connection, $startDate, $endDate, $forceRefresh, $cacheTtl);

            $isSuccess = $analytics['success'] ?? false;
            $views     = $isSuccess ? (int)($analytics['views'] ?? 0) : null;
            $likes     = $isSuccess ? (int)($analytics['likes'] ?? 0) : null;
            $comments  = $isSuccess ? (int)($analytics['comments'] ?? 0) : null;
            $shares    = $isSuccess ? (int)($analytics['shares'] ?? 0) : null;
            $reauth    = $analytics['reauthorization_required'] ?? false;

            return response()->json([
                'success'                  => $isSuccess,
                'connected'                => true,
                'reauthorization_required' => $reauth,
                'has_refresh_token'        => !empty($connection->refresh_token),
                'error_type'               => $analytics['error_type'] ?? null,
                'error_message'            => $analytics['error_message'] ?? null,
                'message'                  => $analytics['message'] ?? 'YouTube analytics retrieved successfully.',
                'channel'                  => [
                    'channel_id'        => $connection->channel_id,
                    'channel_name'      => $connection->channel_name,
                    'channel_thumbnail' => $connection->channel_thumbnail,
                    'subscriber_count'  => $connection->subscriber_count,
                    'video_count'       => $connection->video_count,
                    'lifetime_views'    => (int)($analytics['lifetime_views'] ?? $connection->view_count ?? 0),
                ],
                'start_date'               => $analytics['start_date'],
                'end_date'                 => $analytics['end_date'],
                'views'                    => $views,
                'likes'                    => $likes,
                'comments'                 => $comments,
                'shares'                   => $shares,
                'subscribers_gained'       => $analytics['subscribers_gained'] ?? null,
                'subscribers_lost'         => $analytics['subscribers_lost'] ?? null,
                'subscribers_net_change'   => $analytics['subscribers_net_change'] ?? null,
                'views_last_48h'           => $analytics['views_last_48h'] ?? null,
                'realtime_48h'             => $analytics['realtime_48h'] ?? null,
                'watch_time_minutes'       => (float)($analytics['watch_time_minutes'] ?? 0.0),
                'watch_time_hours'         => (float)($analytics['watch_time_hours'] ?? 0.0),
                'average_view_duration_seconds' => $analytics['average_view_duration_seconds'] ?? 0,
                'published_videos_count'   => $analytics['published_videos_count'] ?? 0,
                'daily_trend'              => $analytics['daily_trend'] ?? [],
                'data'                     => [
                    'views'                         => $views,
                    'likes'                         => $likes,
                    'comments'                      => $comments,
                    'shares'                        => $shares,
                    'subscribers_gained'            => $analytics['subscribers_gained'] ?? null,
                    'subscribers_lost'              => $analytics['subscribers_lost'] ?? null,
                    'subscribers_net_change'        => $analytics['subscribers_net_change'] ?? null,
                    'views_last_48h'                => $analytics['views_last_48h'] ?? null,
                    'realtime_48h'                  => $analytics['realtime_48h'] ?? null,
                    'watch_time_minutes'            => (float)($analytics['watch_time_minutes'] ?? 0.0),
                    'watch_time_hours'              => (float)($analytics['watch_time_hours'] ?? 0.0),
                    'average_view_duration_seconds' => $analytics['average_view_duration_seconds'] ?? 0,
                    'published_videos_count'        => $analytics['published_videos_count'] ?? 0,
                    'start_date'                    => $analytics['start_date'],
                    'end_date'                      => $analytics['end_date'],
                    'daily_trend'                   => $analytics['daily_trend'] ?? [],
                    'reauthorization_required'      => $reauth,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('YouTube analyticsOverview controller error', ['error' => $e->getMessage()]);

            return response()->json([
                'success'                  => false,
                'connected'                => true,
                'reauthorization_required' => true,
                'error_type'               => 'token_invalid',
                'message'                  => $e->getMessage(),
                'views'                    => null,
                'likes'                    => null,
                'comments'                 => null,
                'shares'                   => null,
                'watch_time_hours'         => null,
                'views_last_48h'           => null,
                'error'                    => $e->getMessage(),
            ], 200);
        }
    }

    /**
     * GET /api/youtube/content
     * Retrieve YouTube channel content items (Videos, Shorts, Live, Playlists, Posts) with real statistics.
     */
    public function content(Request $request)
    {
        try {
            $workspaceId = $this->getWorkspaceId($request);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        }

        $connection = $this->resolveConnection($workspaceId);

        if (!$connection) {
            return response()->json([
                'success'                  => false,
                'connected'                => false,
                'reauthorization_required' => false,
                'message'                  => 'YouTube account not connected for this workspace.',
                'items'                    => [],
                'counts'                   => ['all' => 0, 'videos' => 0, 'shorts' => 0, 'live' => 0, 'playlists' => 0, 'posts' => 0],
            ], 404);
        }

        try {
            $type = $request->query('type', 'all');
            $maxResults = (int)$request->query('max_results', 50);
            $forceRefresh = $request->boolean('force_refresh', false);
            $autoSync = $request->boolean('auto_sync', false) || $request->boolean('live_sync', false);
            $cacheTtl = $autoSync ? 30 : null;

            $result = $this->youtubeService->getChannelContent($connection, $type, $maxResults, $forceRefresh, $cacheTtl);

            return response()->json($result);
        } catch (Exception $e) {
            Log::error('YouTube content endpoint error', ['error' => $e->getMessage()]);

            return response()->json([
                'success'                  => false,
                'connected'                => true,
                'reauthorization_required' => str_contains($e->getMessage(), 'reconnect') || str_contains($e->getMessage(), 'invalid_grant'),
                'error_type'               => 'api_error',
                'message'                  => $e->getMessage(),
                'items'                    => [],
                'counts'                   => ['all' => 0, 'videos' => 0, 'shorts' => 0, 'live' => 0, 'playlists' => 0, 'posts' => 0],
            ], 200);
        }
    }

    /**
     * Helper to render HTML redirect/popup response for OAuth completion.
     */
    protected function renderOAuthResponse(bool $success, string $title, string $message, ?YouTubeConnection $connection = null, ?int $workspaceId = null)
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $redirectUrl = $frontendUrl . '/integrations?youtube=' . ($success ? 'success' : 'error');
        $bg = $success ? '#f0fdf4' : '#fef2f2';
        $titleColor = $success ? '#16a34a' : '#dc2626';

        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $dataPayload = $connection ? json_encode([
            'channel_id'        => $connection->channel_id,
            'channel_name'      => $connection->channel_name,
            'subscriber_count'  => $connection->subscriber_count,
            'video_count'       => $connection->video_count,
            'view_count'        => $connection->view_count,
            'channel_thumbnail' => $connection->channel_thumbnail,
        ]) : 'null';

        $successJs = $success ? 'true' : 'false';
        $messageJs = json_encode($message);
        $targetOriginJs = json_encode($frontendUrl);

        $extraButtonHtml = '';
        $autoCloseDelay = 1500;
        if ($title === 'Already Connected' && $workspaceId) {
            $extraButtonHtml = '<p style="margin-top: 15px;"><a href="/api/youtube/connect?workspace_id=' . $workspaceId . '&force=true" style="display: inline-block; padding: 8px 16px; background: #4f46e5; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500; font-size: 13px;">Connect Another Channel &rarr;</a></p>';
            $autoCloseDelay = 5000;
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$safeTitle}</title>
    <meta charset="utf-8">
</head>
<body style="font-family: system-ui, -apple-system, sans-serif; text-align: center; padding: 40px; background: {$bg}; color: #374151;">
    <h2 style="color: {$titleColor};">{$safeTitle}</h2>
    <p>{$safeMessage}</p>
    {$extraButtonHtml}
    <p style="font-size: 13px; color: #6b7280;">Redirecting back to Marketing Command...</p>

    <script>
        if (window.opener) {
            window.opener.postMessage({
                type: 'YOUTUBE_OAUTH_RESULT',
                success: {$successJs},
                message: {$messageJs},
                connection: {$dataPayload}
            }, {$targetOriginJs});
            setTimeout(function() { window.close(); }, {$autoCloseDelay});
        } else {
            setTimeout(function() { window.location.href = '{$redirectUrl}'; }, 2000);
        }
    </script>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html']);
    }
}
