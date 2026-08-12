<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TwitterService;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class TwitterController extends Controller
{
    protected TwitterService $twitterService;

    public function __construct(TwitterService $twitterService)
    {
        $this->twitterService = $twitterService;
    }

    /**
     * GET /api/twitter/connect
     * Initiates the X (Twitter) OAuth 2.0 PKCE flow.
     */
    public function connect(Request $request)
    {
        Log::info('Twitter connect endpoint hit', $request->all());
        try {
            $workspaceId = $request->query('workspace_id', 1);
            $state = base64_encode(json_encode([
                'workspace_id' => $workspaceId,
                'time' => time(),
            ]));

            // Generate PKCE code verifier and code challenge
            $codeVerifier = bin2hex(random_bytes(32));
            $codeChallenge = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(hash('sha256', $codeVerifier, true)));

            // Cache the verifier under the state key for 15 minutes
            Cache::put('twitter_code_verifier_' . $state, $codeVerifier, now()->addMinutes(15));

            $authUrl = $this->twitterService->getAuthUrl($state, $codeChallenge);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'url' => $authUrl,
                ]);
            }

            return redirect()->away($authUrl);
        } catch (Exception $e) {
            Log::error('Twitter connect error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize Twitter OAuth connection.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/twitter/callback
     * Handles the redirect callback from X (Twitter) OAuth 2.0.
     */
    public function callback(Request $request)
    {
        Log::info('Twitter callback endpoint hit', $request->all());

        if ($request->has('error')) {
            $error = $request->query('error_description', $request->query('error', 'User denied permission.'));
            return $this->renderOAuthResponse(false, 'X (Twitter) Connection Failed', $error);
        }

        $code = $request->query('code');
        $stateRaw = $request->query('state');

        if (!$code) {
            return $this->renderOAuthResponse(false, 'Invalid Request', 'Missing authorization code from X (Twitter).');
        }

        $workspaceId = 1;
        if ($stateRaw) {
            $decoded = json_decode(base64_decode($stateRaw), true);
            if (is_array($decoded) && isset($decoded['workspace_id'])) {
                $workspaceId = $decoded['workspace_id'];
            }
        }

        try {
            // Retrieve code verifier from cache
            $codeVerifier = Cache::pull('twitter_code_verifier_' . $stateRaw) ?? '';

            // If mock mode is active or mock code provided, we don't strictly validate code verifier
            if (!$this->twitterService->isMockMode() && $code !== 'mock_oauth_code_123456' && empty($codeVerifier)) {
                throw new Exception('Invalid OAuth session or state expired. Please try again.');
            }

            // Exchange code for tokens
            $tokens = $this->twitterService->exchangeCodeForTokens($code, $codeVerifier);

            // Fetch user account details
            $userData = $this->twitterService->getUserDetails($tokens['access_token']);

            // Save or update connection in integration table (handling soft-deleted records)
            $expiresIn = $tokens['expires_in'] ?? 7200;
            $expiresAt = now()->addSeconds($expiresIn);

            $integration = Integration::withTrashed()->updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'platform'     => 'twitter',
                    'account_id'   => $userData['id'],
                ],
                [
                    'account_name'            => '@' . ltrim($userData['username'], '@'),
                    'refresh_token'           => $tokens['refresh_token'] ?? null,
                    'access_token_expires_at' => $expiresAt,
                    'token_expires_at'        => $expiresAt,
                    'is_connected'            => true,
                    'connection_status'       => 'connected',
                    'last_sync_at'            => now(),
                    'deleted_at'              => null,
                ]
            );

            if ($integration->trashed()) {
                $integration->restore();
            }

            Log::info('Twitter OAuth Connection Successfully Saved', [
                'workspace_id' => $workspaceId,
                'integration_id' => $integration->id,
                'account_name' => $integration->account_name
            ]);

            return $this->renderOAuthResponse(
                true,
                'X (Twitter) Connected!',
                "Successfully connected '{$integration->account_name}'",
                $integration
            );

        } catch (Exception $e) {
            Log::error('Twitter OAuth Callback Failed', ['error' => $e->getMessage()]);
            return $this->renderOAuthResponse(false, 'X (Twitter) Connection Error', $e->getMessage());
        }
    }

    /**
     * GET /api/twitter/status
     * Returns the active X (Twitter) connection status for the workspace.
     */
    public function status(Request $request)
    {
        $workspaceId = $request->query('workspace_id', 1);

        $integration = Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'twitter')
            ->where('is_connected', true)
            ->first()
            ?? Integration::where('platform', 'twitter')
            ->where('is_connected', true)
            ->latest()
            ->first();

        if (!$integration) {
            return response()->json([
                'success' => true,
                'connected' => false,
                'message' => 'X (Twitter) is not connected.',
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'connected' => true,
            'data' => [
                'id' => $integration->id,
                'account_id' => $integration->account_id,
                'account_name' => $integration->account_name,
                'connection_status' => $integration->connection_status,
                'last_sync_at' => $integration->last_sync_at?->toIso8601String(),
                'created_at' => $integration->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/twitter/disconnect
     * Disconnects the X (Twitter) integration for the workspace.
     */
    public function disconnect(Request $request)
    {
        $workspaceId = $request->input('workspace_id', 1);

        Integration::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('platform', 'twitter')
            ->forceDelete();

        Integration::withTrashed()
            ->where('platform', 'twitter')
            ->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'X (Twitter) disconnected successfully.',
        ]);
    }

    /**
     * POST /api/twitter/connect-mock
     * Directly connects a mock X (Twitter) account without redirects.
     */
    public function connectMock(Request $request)
    {
        Log::info('Twitter connectMock endpoint hit', $request->all());
        try {
            $workspaceId = $request->input('workspace_id', 1);

            $integration = Integration::withTrashed()->updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'platform'     => 'twitter',
                    'account_id'   => 'mock_twitter_user_998877',
                ],
                [
                    'account_name'            => '@Chandramohan_K1',
                    'refresh_token'           => 'mock_refresh_token_' . bin2hex(random_bytes(16)),
                    'access_token_expires_at' => now()->addHours(2),
                    'token_expires_at'        => now()->addHours(2),
                    'is_connected'            => true,
                    'connection_status'       => 'connected',
                    'last_sync_at'            => now(),
                    'deleted_at'              => null,
                ]
            );

            if ($integration->trashed()) {
                $integration->restore();
            }

            Log::info('Twitter Mock OAuth Connection Successfully Saved', [
                'workspace_id' => $workspaceId,
                'integration_id' => $integration->id,
                'account_name' => $integration->account_name
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Successfully connected @DemoAgency_X (mock)',
                'data' => [
                    'id' => $integration->id,
                    'account_id' => $integration->account_id,
                    'account_name' => $integration->account_name,
                    'connection_status' => $integration->connection_status,
                    'last_sync_at' => $integration->last_sync_at?->toIso8601String(),
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Twitter mock connect error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect Twitter account (mock).',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper to render HTML popup code to message the frontend.
     */
    protected function renderOAuthResponse(bool $success, string $title, string $message, ?Integration $integration = null)
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/integrations?twitter=' . ($success ? 'success' : 'error');
        $bg = $success ? '#f0fdf4' : '#fef2f2';
        $titleColor = $success ? '#16a34a' : '#dc2626';

        $dataPayload = $integration ? json_encode([
            'id' => $integration->id,
            'account_id' => $integration->account_id,
            'account_name' => $integration->account_name,
            'connection_status' => $integration->connection_status,
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
    <p style="font-size: 13px; color: #6b7280;">Closing window...</p>

    <script>
        if (window.opener) {
            window.opener.postMessage({
                type: 'TWITTER_OAUTH_RESULT',
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
