<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\YouTubeService;
use App\Models\YouTubeConnection;
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
     * GET /api/youtube/connect
     * Redirect user to Google OAuth login screen.
     */
    public function connect(Request $request)
    {
        try {
            $state = base64_encode(json_encode([
                'user_id' => $request->user()?->id ?? 1,
                'time' => time(),
            ]));

            $authUrl = $this->youtubeService->getAuthUrl($state);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'url' => $authUrl,
                ]);
            }

            return redirect()->away($authUrl);
        } catch (Exception $e) {
            Log::error('YouTube connect error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize Google OAuth connection.',
                'error' => $e->getMessage(),
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
        $userId = 1;
        if ($stateRaw) {
            $decoded = json_decode(base64_decode($stateRaw), true);
            if (is_array($decoded) && isset($decoded['user_id'])) {
                $userId = $decoded['user_id'];
            }
        }

        try {
            // 3 & 4. Exchange code for tokens
            $tokens = $this->youtubeService->exchangeCodeForTokens($code);

            // 5 & 6. Call YouTube API & Get Channel Info
            $channelData = $this->youtubeService->getChannelInfo($tokens['access_token']);

            // 7. Save Channel Information & Tokens (encrypted)
            $connection = $this->youtubeService->saveChannelConnection($userId, $tokens, $channelData);

            // 8. Redirect user to Marketing Command dashboard
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
     * Get connection status for YouTube.
     */
    public function status(Request $request)
    {
        $connection = YouTubeConnection::latest()->first();

        if (!$connection) {
            return response()->json([
                'success' => true,
                'connected' => false,
                'message' => 'YouTube is not connected.',
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'connected' => true,
            'data' => [
                'id' => $connection->id,
                'channel_id' => $connection->channel_id,
                'channel_name' => $connection->channel_name,
                'channel_description' => $connection->channel_description,
                'channel_thumbnail' => $connection->channel_thumbnail,
                'subscriber_count' => $connection->subscriber_count,
                'video_count' => $connection->video_count,
                'view_count' => $connection->view_count,
                'token_expires_at' => $connection->token_expires_at?->toIso8601String(),
                'created_at' => $connection->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/youtube/channel
     * Retrieve connected YouTube Channel statistics from YouTube API.
     */
    public function channel(Request $request)
    {
        $connection = YouTubeConnection::latest()->first();

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'YouTube account not connected.',
            ], 404);
        }

        try {
            // Automatically refresh access token if expired
            $connection = $this->youtubeService->refreshAccessTokenIfNeeded($connection);

            // Fetch live details from YouTube Data API v3
            $channelData = $this->youtubeService->getChannelInfo($connection->access_token);

            // Update stored stats
            $connection->update([
                'channel_name' => $channelData['channel_name'],
                'channel_description' => $channelData['channel_description'],
                'channel_thumbnail' => $channelData['channel_thumbnail'],
                'subscriber_count' => $channelData['subscriber_count'],
                'video_count' => $channelData['video_count'],
                'view_count' => $channelData['view_count'],
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $connection->id,
                    'channel_id' => $connection->channel_id,
                    'channel_name' => $connection->channel_name,
                    'channel_description' => $connection->channel_description,
                    'channel_thumbnail' => $connection->channel_thumbnail,
                    'subscriber_count' => $connection->subscriber_count,
                    'video_count' => $connection->video_count,
                    'view_count' => $connection->view_count,
                    'token_expires_at' => $connection->token_expires_at?->toIso8601String(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Fetch YouTube channel failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /api/youtube/disconnect
     * Disconnect connected YouTube channel.
     */
    public function disconnect(Request $request)
    {
        YouTubeConnection::truncate();

        return response()->json([
            'success' => true,
            'message' => 'YouTube channel disconnected successfully.',
        ]);
    }

    /**
     * POST /api/youtube/videos
     * Prepared future endpoint for video uploading.
     */
    public function uploadVideo(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimes:mp4,mov,avi,mkv|max:51200',
            'title' => 'nullable|string|max:100',
            'description' => 'nullable|string',
        ]);

        $connection = YouTubeConnection::latest()->first();
        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'No connected YouTube Channel found. Please connect first.',
            ], 400);
        }

        try {
            $videoFile = $request->file('video');
            $title = $request->input('title', 'Uploaded Video');
            $description = $request->input('description', '');

            $result = $this->youtubeService->uploadVideo(
                $connection,
                $videoFile->getRealPath(),
                $title,
                $description,
                'public'
            );

            return response()->json([
                'success' => true,
                'message' => 'Video uploaded to YouTube successfully!',
                'video_id' => $result['id'],
            ]);
        } catch (Exception $e) {
            Log::error('YouTube uploadVideo endpoint error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper to render HTML redirect/popup response for OAuth completion.
     */
    protected function renderOAuthResponse(bool $success, string $title, string $message, ?YouTubeConnection $connection = null)
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/integrations?youtube=' . ($success ? 'success' : 'error');
        $bg = $success ? '#f0fdf4' : '#fef2f2';
        $titleColor = $success ? '#16a34a' : '#dc2626';

        $dataPayload = $connection ? json_encode([
            'channel_id' => $connection->channel_id,
            'channel_name' => $connection->channel_name,
            'subscriber_count' => $connection->subscriber_count,
            'video_count' => $connection->video_count,
            'view_count' => $connection->view_count,
            'channel_thumbnail' => $connection->channel_thumbnail,
        ]) : 'null';

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
    <meta charset="utf-8">
</head>
<body style="font-family: system-ui, -apple-system, sans-serif; text-align: center; padding: 40px; background: {$bg}; color: #374151;">
    <h2 style="color: {$titleColor};">{$title}</h2>
    <p>{$message}</p>
    <p style="font-size: 13px; color: #6b7280;">Redirecting back to Marketing Command...</p>

    <script>
        if (window.opener) {
            window.opener.postMessage({
                type: 'YOUTUBE_OAUTH_RESULT',
                success: {$success},
                message: '{$message}',
                connection: {$dataPayload}
            }, '*');
            setTimeout(function() { window.close(); }, 1500);
        } else {
            setTimeout(function() { window.location.href = '{$frontendUrl}'; }, 2000);
        }
    </script>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html']);
    }
}
