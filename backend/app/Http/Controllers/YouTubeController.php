<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\YouTubeService;
use App\Models\YouTubeConnection;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class YouTubeController extends Controller
{
    /** OAuth state lifetime (seconds). */
    private const OAUTH_STATE_TTL = 900;

    protected YouTubeService $youtubeService;

    public function __construct(YouTubeService $youtubeService)
    {
        $this->youtubeService = $youtubeService;
    }

    /**
     * Resolve workspace_id from the request. A missing id is an error: it is never replaced by the first
     * workspace or a hard-coded one (that could expose or write another client's channel).
     */
    protected function getWorkspaceId(Request $request): int
    {
        $id = $request->input('workspace_id', $request->query('workspace_id'));
        if (!$id && $request->user()) {
            $id = $request->user()->current_workspace_id;
        }
        if (empty($id)) {
            throw new \InvalidArgumentException('A workspace_id is required. Select a client workspace first.');
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
     * GET /api/v1/youtube/connect
     * Starts the Google OAuth flow for ONE client workspace.
     *
     * Requires a signed-in user (Bearer token). The browser-popup version of this route used to accept any
     * workspace_id from the query string; a stale/deleted/foreign id then travelled through OAuth and blew up in
     * the callback as a foreign-key error. The workspace is now validated here and bound into a signed state.
     */
    public function connect(Request $request)
    {
        try {
            $user = $request->user('sanctum');
            if (! $user) {
                return $this->connectFailure($request, 401, 'Sign-in Required', 'Please sign in to Marketing Command and start the YouTube connection from the Integrations page.');
            }
            if (($user->role ?: 'viewer') === 'viewer') {
                return $this->connectFailure($request, 403, 'Not Allowed', 'Viewers have read-only access and cannot connect accounts.');
            }

            $rawWorkspace = $request->input('workspace_id', $request->query('workspace_id'));
            // Missing, "all", unknown, deleted or not-yours: rejected here - never replaced by another workspace.
            if ($denied = WorkspaceAccess::guard($request, $rawWorkspace, $user)) {
                return $denied;
            }
            $workspaceId = (int) $rawWorkspace;
            $force       = $request->boolean('force') || $request->boolean('reconnect');

            // 1. Check if a valid YouTube connection already exists for this workspace
            if (!$force) {
                $existing = $this->resolveConnection($workspaceId);
                if ($existing && (!empty($existing->access_token) || !empty($existing->refresh_token))) {
                    try {
                        $existing = $this->youtubeService->refreshAccessTokenIfNeeded($existing);
                    } catch (\Throwable $refreshErr) {
                        Log::warning('[YOUTUBE OAUTH] Proactive token refresh notice', ['workspace_id' => $workspaceId, 'reason' => $this->safeLogMessage($refreshErr)]);
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

            $state = $this->buildOAuthState((int) $user->id, $workspaceId);

            $loginHint = $request->query('login_hint', env('YOUTUBE_LOGIN_HINT', null));
            $authUrl = $this->youtubeService->getAuthUrl($state, $loginHint);

            // The signed state (and its single-use nonce, kept in this server's cache) can only be validated by the
            // server Google redirects back to. If that is a different host, say so now instead of failing after login.
            $warning = $this->callbackHostWarning($request);
            if ($warning) {
                Log::warning('[YOUTUBE OAUTH] ' . $warning, ['workspace_id' => $workspaceId]);
            }

            if ($request->wantsJson()) {
                return response()->json(array_filter([
                    'success' => true,
                    'url'     => $authUrl,
                    'warning' => $warning,
                ]));
            }

            return redirect()->away($authUrl);
        } catch (\InvalidArgumentException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->getMessage(),
            ], 400);
        } catch (Exception $e) {
            Log::error('YouTube connect error', ['reason' => $this->safeLogMessage($e)]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize Google OAuth connection. Please try again.',
            ], 500);
        }
    }

    /**
     * GET /api/youtube/callback
     * Google OAuth Callback Endpoint.
     */
    public function callback(Request $request)
    {
        // 1. Check for OAuth error / denied permission (only the short error code is shown, never raw text)
        if ($request->has('error')) {
            $code = preg_replace('/[^a-z_]/', '', strtolower((string) $request->query('error', '')));
            $message = $code === 'access_denied'
                ? 'Google permission was denied, so nothing was connected.'
                : 'Google returned an error' . ($code !== '' ? " ({$code})" : '') . '. Please try connecting again.';
            return $this->renderOAuthResponse(false, 'Google OAuth Failed', $message);
        }

        $code = $request->query('code');
        if (!$code) {
            return $this->renderOAuthResponse(false, 'Invalid Request', 'Missing authorization code from Google.');
        }

        // 2. Validate the signed, expiring, single-use state
        [$claims, $stateError] = $this->verifyOAuthState((string) $request->query('state', ''));
        if ($claims === null) {
            return $this->renderOAuthResponse(false, 'Invalid State', $stateError);
        }
        $workspaceId = (int) $claims['workspace_id'];

        // 3. The workspace must still exist and the user who started the flow must still be allowed to use it.
        //    All of this happens BEFORE the authorization code is exchanged or anything is written.
        if (! Workspace::whereKey($workspaceId)->exists()) {
            return $this->renderOAuthResponse(false, 'Workspace Not Found', 'The client workspace selected for this connection no longer exists. Select a valid client and connect YouTube again.');
        }
        $user = User::find((int) $claims['user_id']);
        if (! $user || $user->is_active === false || ($user->role ?: 'viewer') === 'viewer' || ! WorkspaceAccess::canAccess($user, $workspaceId)) {
            return $this->renderOAuthResponse(false, 'Access Denied', 'You do not have permission to connect YouTube for this client workspace.');
        }

        try {
            // 4. Exchange code for tokens
            $tokens = $this->youtubeService->exchangeCodeForTokens($code);

            // 5. Call YouTube API & Get Channel Info
            $channelData = $this->youtubeService->getChannelInfo($tokens['access_token']);

            // 6. Save the channel and the unified integration row in ONE transaction, scoped to this workspace
            $connection = $this->youtubeService->saveChannelConnection((int) $user->id, $tokens, $channelData, $workspaceId);

            // 7. Render OAuth response popup
            return $this->renderOAuthResponse(
                true,
                'YouTube Channel Connected!',
                "Successfully connected '{$connection->channel_name}'",
                $connection
            );
        } catch (\Throwable $e) {
            Log::error('YouTube OAuth Callback Failed', [
                'workspace_id' => $workspaceId,
                'exception'    => get_class($e),
                'reason'       => $this->safeLogMessage($e),
            ]);
            return $this->renderOAuthResponse(false, 'YouTube Connection Error', $this->safeUserMessage($e));
        }
    }

    /** Signed (HMAC-SHA256 with APP_KEY), expiring and single-use OAuth state bound to user + workspace. */
    protected function buildOAuthState(int $userId, int $workspaceId): string
    {
        $nonce = bin2hex(random_bytes(16));
        Cache::put("yt_oauth_nonce_{$nonce}", ['user_id' => $userId, 'workspace_id' => $workspaceId], now()->addSeconds(self::OAUTH_STATE_TTL));

        $payload = json_encode([
            'user_id'      => $userId,
            'workspace_id' => $workspaceId,
            'ts'           => now()->timestamp,
            'nonce'        => $nonce,
        ]);

        // URL-safe base64 (A-Z a-z 0-9 - _, no padding): nothing in it can be altered by query-string encoding,
        // by Google echoing it back, or by a "+" being turned into a space somewhere along the redirect.
        return rtrim(strtr(base64_encode(json_encode([
            'payload' => $payload,
            'sig'     => hash_hmac('sha256', $payload, config('app.key')),
        ])), '+/', '-_'), '=');
    }

    /** Decodes a state produced by buildOAuthState() (also tolerates the standard alphabet / a "+" turned into a space). */
    protected function decodeStateEnvelope(string $raw): ?array
    {
        $b64 = rtrim(strtr(str_replace(' ', '+', trim($raw)), '-_', '+/'), '=');
        $json = base64_decode(str_pad($b64, (int) (ceil(strlen($b64) / 4) * 4), '=', STR_PAD_RIGHT), true);
        $envelope = $json === false ? null : json_decode($json, true);

        return is_array($envelope) ? $envelope : null;
    }

    /** @return array{0: ?array, 1: string} claims (or null) and a user-safe error message */
    protected function verifyOAuthState(string $raw): array
    {
        $invalid = 'The OAuth state is missing, invalid or tampered with. Please start the YouTube connection again from Integrations.';
        if ($raw === '') {
            return [null, $invalid];
        }

        $envelope = $this->decodeStateEnvelope($raw);
        if ($envelope === null || ! isset($envelope['payload'], $envelope['sig']) || ! is_string($envelope['payload']) || ! is_string($envelope['sig'])) {
            return [null, $invalid];
        }
        if (! hash_equals(hash_hmac('sha256', $envelope['payload'], config('app.key')), $envelope['sig'])) {
            return [null, $invalid];
        }

        $claims = json_decode($envelope['payload'], true);
        if (! is_array($claims)
            || ! isset($claims['user_id'], $claims['workspace_id'], $claims['nonce'])
            || ! is_int($claims['ts'] ?? null)
            || ! is_string($claims['nonce'])) {
            return [null, $invalid];
        }

        $age = now()->timestamp - $claims['ts'];
        if ($age > self::OAUTH_STATE_TTL || $age < -60) {
            return [null, 'The YouTube connection request expired. Please start it again from Integrations.'];
        }

        // Single use: the nonce is consumed here, so a replayed callback URL is refused.
        $stored = Cache::pull("yt_oauth_nonce_{$claims['nonce']}");
        if (! is_array($stored)
            || (int) $stored['workspace_id'] !== (int) $claims['workspace_id']
            || (int) $stored['user_id'] !== (int) $claims['user_id']) {
            return [null, 'This YouTube connection request was already used or is no longer valid. Please start it again from Integrations.'];
        }

        return [$claims, ''];
    }

    /** Non-null when Google will redirect to a different host than the one serving this request. */
    protected function callbackHostWarning(Request $request): ?string
    {
        $redirect = (string) config('services.google.redirect_uri');
        $redirectHost = parse_url($redirect, PHP_URL_HOST);
        $thisHost = $request->getHost();

        if (! $redirectHost || ! $thisHost || strcasecmp($redirectHost, $thisHost) === 0) {
            return null;
        }

        return "Google will return to {$redirectHost}, but this connection was started on {$thisHost}. "
            . 'The connection only completes if that address runs this same backend version (same APP_KEY and cache). '
            . 'For local testing set GOOGLE_REDIRECT_URI to the /api/youtube/callback URL of the server you are using and register it in Google Cloud.';
    }

    protected function connectFailure(Request $request, int $status, string $title, string $message)
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        return $this->renderOAuthResponse(false, $title, $message)->setStatusCode($status);
    }

    /** Message that is safe to show: our own validation messages and one known Google message pass; everything else is generic. */
    protected function safeUserMessage(\Throwable $e): string
    {
        if ($e instanceof \InvalidArgumentException || $e instanceof \DomainException) {
            return $e->getMessage();
        }
        if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException) {
            return 'A database error occurred while saving the YouTube connection. Nothing was changed. Please try again or contact support.';
        }
        $m = $e->getMessage();
        if (str_contains($m, 'No YouTube Channel found')) {
            return 'No YouTube channel was found for that Google account. Choose an account that owns a channel.';
        }
        if (stripos($m, 'invalid_grant') !== false || stripos($m, 'expired') !== false) {
            return 'Google rejected the authorization (it may have expired or already been used). Please start the connection again.';
        }

        return 'Could not complete the YouTube connection. Please try again.';
    }

    /** Log text without SQL, bindings or anything token-shaped. */
    protected function safeLogMessage(\Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\QueryException) {
            return 'database error (state ' . ($e->errorInfo[0] ?? $e->getCode() ?: 'unknown') . ', driver code ' . ($e->errorInfo[1] ?? 'unknown') . ')';
        }

        return substr(preg_replace('/[A-Za-z0-9_\-\.\/+=]{32,}/', '[redacted]', $e->getMessage()), 0, 300);
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
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null';

        $successJs = $success ? 'true' : 'false';
        $messageJs = json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $targetOriginJs = json_encode($frontendUrl);

        $extraButtonHtml = '';
        $autoCloseDelay = $title === 'Already Connected' ? 3000 : 1500;

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
