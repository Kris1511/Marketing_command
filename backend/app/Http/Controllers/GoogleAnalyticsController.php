<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GoogleAnalyticsService;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class GoogleAnalyticsController extends Controller
{
    protected GoogleAnalyticsService $gaService;

    public function __construct(GoogleAnalyticsService $gaService)
    {
        $this->gaService = $gaService;
    }

    /**
     * GET /api/v1/google-analytics/connect
     * Initiates Google OAuth 2.0 flow for GA4.
     */
    public function connect(Request $request)
    {
        $workspaceId = (int)$request->query('workspace_id', 1);

        try {
            $authUrl = $this->gaService->createAuthUrl($workspaceId);

            if ($request->wantsJson()) {
                return response()->json([
                    'success'  => true,
                    'auth_url' => $authUrl,
                ]);
            }

            return redirect()->away($authUrl);
        } catch (Exception $e) {
            Log::error('GA4 OAuth Connect Error: ' . $e->getMessage());

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 500);
            }

            return $this->renderOAuthResponse(false, 'Google Analytics Connection Error', $e->getMessage());
        }
    }

    /**
     * GET /api/v1/google-analytics/callback
     * Google OAuth 2.0 callback endpoint.
     */
    public function callback(Request $request)
    {
        // 1. Check for OAuth error / user cancellation
        if ($request->has('error')) {
            $error = $request->query('error_description', $request->query('error', 'User denied permission.'));
            return $this->renderOAuthResponse(false, 'Google OAuth Failed', $error);
        }

        $code     = $request->query('code');
        $stateRaw = $request->query('state');

        if (!$code) {
            return $this->renderOAuthResponse(false, 'Invalid Request', 'Missing authorization code from Google.');
        }

        // 2. Decode state to retrieve workspace_id
        $workspaceId = 1;
        if ($stateRaw) {
            $decoded = json_decode(base64_decode($stateRaw), true);
            if (is_array($decoded) && isset($decoded['workspace_id'])) {
                $workspaceId = (int)$decoded['workspace_id'];
            }
        }

        try {
            $result = $this->gaService->handleOAuthCallback($code, $workspaceId);

            $propCount = count($result['properties'] ?? []);
            $successMsg = $propCount > 0
                ? ($result['selected_property_id']
                    ? "Successfully connected GA4 property: {$result['selected_property_id']}"
                    : "Found {$propCount} GA4 properties. Please choose your property in Marketing Command.")
                : "Authenticated as {$result['user_email']}, but no GA4 properties were found.";

            return $this->renderOAuthResponse(
                true,
                'Google Analytics Connected!',
                $successMsg,
                $result
            );
        } catch (Exception $e) {
            Log::error('GA4 OAuth Callback Failed', ['error' => $e->getMessage()]);
            return $this->renderOAuthResponse(false, 'GA4 Connection Error', $e->getMessage());
        }
    }

    /**
     * Render popup response communicating via window.postMessage with opener.
     */
    protected function renderOAuthResponse(bool $success, string $title, string $message, array $extraData = [])
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $redirectUrl = $frontendUrl . '/integrations?ga=' . ($success ? 'success' : 'error');
        $bg         = $success ? '#f0fdf4' : '#fef2f2';
        $titleColor = $success ? '#16a34a' : '#dc2626';
        $icon       = $success ? '&#x2714;' : '&#x274C;';

        $safeTitle   = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $payloadJs = json_encode(array_merge([
            'type'    => 'GOOGLE_ANALYTICS_OAUTH_RESULT',
            'success' => $success,
            'message' => $message,
        ], $extraData));

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$safeTitle}</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; text-align: center; padding: 40px; background: {$bg}; color: #374151;">
    <div style="max-width: 480px; margin: 40px auto; background: #ffffff; border-radius: 16px; padding: 32px 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); border: 1px solid rgba(0,0,0,0.05);">
        <div style="width: 52px; height: 52px; border-radius: 50%; background: {$bg}; color: {$titleColor}; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 26px;">
            {$icon}
        </div>
        <h2 style="color: {$titleColor}; margin: 0 0 10px; font-size: 20px;">{$safeTitle}</h2>
        <p style="font-size: 14px; line-height: 1.5; color: #4b5563; margin-bottom: 20px;">{$safeMessage}</p>
        <p style="font-size: 12px; color: #9ca3af;">Redirecting to Marketing Command...</p>
    </div>

    <script>
        if (window.opener) {
            try {
                window.opener.postMessage({$payloadJs}, "*");
            } catch (e) {
                console.error("Failed to postMessage:", e);
            }
            setTimeout(function() { window.close(); }, 1800);
        } else {
            setTimeout(function() { window.location.href = '{$redirectUrl}'; }, 2000);
        }
    </script>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    /**
     * GET /api/v1/google-analytics/properties
     * List all GA4 properties accessible with current workspace token.
     */
    public function properties(Request $request)
    {
        $workspaceId = (int)$request->query('workspace_id', 1);

        try {
            $token = $this->gaService->getAccessToken($workspaceId);
            $properties = $this->gaService->listAvailableProperties($token);

            return response()->json([
                'success'    => true,
                'properties' => $properties,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success'    => false,
                'message'    => 'Unable to fetch properties: ' . $e->getMessage(),
                'properties' => [],
            ], 400);
        }
    }

    /**
     * POST /api/v1/google-analytics/select-property
     * Select/link a specific GA4 property for the workspace.
     */
    public function selectProperty(Request $request)
    {
        $request->validate([
            'workspace_id' => 'required|integer',
            'property_id'  => 'required|string|max:100',
            'account_name' => 'nullable|string|max:255',
        ]);

        $workspaceId = (int)$request->input('workspace_id');
        $propertyId  = preg_replace('/[^0-9]/', '', $request->input('property_id'));
        $accountName = $request->input('account_name') ?: "GA4 Property ({$propertyId})";

        $integ = Integration::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'google_analytics',
            ],
            [
                'account_id'        => $propertyId,
                'account_name'      => $accountName,
                'is_connected'      => true,
                'connection_status' => 'connected',
                'last_sync_at'      => now(),
            ]
        );

        return response()->json([
            'success'     => true,
            'message'     => "GA4 Property {$propertyId} selected successfully.",
            'integration' => $integ,
        ]);
    }

    /**
     * GET /api/v1/google-analytics/status
     */
    public function status(Request $request)
    {
        $workspaceId = (int)$request->query('workspace_id', 1);
        $isConfigured = $this->gaService->isConfigured($workspaceId);
        $propertyId = $this->gaService->getPropertyId($workspaceId);
        $credPath = base_path(env('GOOGLE_ANALYTICS_CREDENTIALS_PATH', 'ga-credentials.json'));
        $hasCredentials = file_exists($credPath);

        $integ = Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'google_analytics')
            ->first();

        $isTokenExpired = false;
        if ($integ && ($integ->connection_status === 'expired' || !empty($integ->refresh_token))) {
            try {
                $this->gaService->getAccessToken($workspaceId);
            } catch (\Throwable $te) {
                if (str_contains(strtolower($te->getMessage()), 'expired') || str_contains(strtolower($te->getMessage()), 'revoked')) {
                    $isTokenExpired = true;
                }
            }
        }

        $isConnected = $isConfigured && ($integ ? $integ->is_connected : true) && !$isTokenExpired;

        return response()->json([
            'success'          => true,
            'connected'        => $isConnected,
            'status'           => $isTokenExpired ? 'expired' : ($isConnected ? 'connected' : 'disconnected'),
            'has_credentials'  => $hasCredentials || !empty($integ?->refresh_token),
            'has_oauth'        => !empty($integ?->refresh_token) || Cache::has("ga4_token_{$workspaceId}"),
            'property_id'      => $propertyId,
            'account_name'     => $integ?->account_name,
            'last_sync'        => $integ?->last_sync_at?->toIso8601String(),
            'message'          => $isTokenExpired
                ? "Google Analytics session has expired. Reconnection required."
                : ($isConnected
                    ? "Google Analytics (GA4) is connected to property {$propertyId}."
                    : 'Google Analytics is not connected.'),
        ]);
    }

    /**
     * POST /api/v1/google-analytics/configure
     */
    public function configure(Request $request)
    {
        $request->validate([
            'workspace_id' => 'required|integer',
            'property_id'  => 'required|string|max:100',
        ]);

        $workspaceId = (int)$request->input('workspace_id');
        $propertyId  = preg_replace('/[^0-9]/', '', $request->input('property_id'));

        $integ = Integration::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'google_analytics',
            ],
            [
                'account_id'        => $propertyId,
                'account_name'      => "GA4 Property ({$propertyId})",
                'is_connected'      => true,
                'connection_status' => 'connected',
                'last_sync_at'      => now(),
            ]
        );

        return response()->json([
            'success'     => true,
            'message'     => "Google Analytics GA4 Property {$propertyId} configured successfully.",
            'integration' => $integ,
        ]);
    }

    /**
     * GET /api/v1/google-analytics/metrics
     */
    public function metrics(Request $request)
    {
        $workspaceId = (int)$request->query('workspace_id', 1);
        $startDate   = $request->query('start_date');
        $endDate     = $request->query('end_date');
        $limit       = (int)$request->query('limit', 20);

        try {
            $overview = $this->gaService->getOverview($startDate, $endDate, $workspaceId);
            $sources  = $this->gaService->getTrafficSources($startDate, $endDate, $limit, $workspaceId);
            $trend    = $this->gaService->getDailyTrend($startDate, $endDate, $workspaceId);

            $authRequired = !empty($overview['auth_required']);

            return response()->json([
                'success'       => true,
                'auth_required' => $authRequired,
                'data'          => [
                    'overview'      => $overview,
                    'sources'       => $sources,
                    'trend'         => $trend,
                    'auth_required' => $authRequired,
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Google Analytics metrics query error', ['error' => $e->getMessage()]);
            return response()->json([
                'success'       => false,
                'auth_required' => true,
                'message'       => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/v1/google-analytics/test
     */
    public function test(Request $request)
    {
        $workspaceId = (int)$request->input('workspace_id', $request->query('workspace_id', 1));

        if (!$this->gaService->isConfigured($workspaceId)) {
            return response()->json([
                'success' => false,
                'message' => 'GA4 credentials or Property ID not configured for this workspace.',
            ], 400);
        }

        try {
            $propId = $this->gaService->getPropertyId($workspaceId);
            $overview = $this->gaService->getOverview('7daysAgo', 'today', $workspaceId);

            if (!empty($overview['auth_required']) || !empty($overview['error'])) {
                return response()->json([
                    'success'       => false,
                    'auth_required' => true,
                    'message'       => $overview['error'] ?? 'GA4 authorization expired. Please reconnect Google Analytics.',
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => "Google Analytics (GA4) Property ({$propId}) connection verified!",
                'data'    => [
                    'property_id'  => $propId,
                    'active_users' => (int)($overview['active_users'] ?? 0),
                    'sessions'     => (int)($overview['sessions'] ?? 0),
                    'page_views'   => (int)($overview['screen_page_views'] ?? 0),
                    'status'       => 'Live & Operational',
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'GA4 Test Error: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /api/v1/google-analytics/disconnect
     */
    public function disconnect(Request $request)
    {
        $workspaceId = (int)$request->input('workspace_id', 1);

        $integ = Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'google_analytics')
            ->first();

        if ($integ) {
            $integ->update([
                'is_connected'      => false,
                'connection_status' => 'disconnected',
            ]);
        }

        Cache::forget("ga4_token_{$workspaceId}");

        return response()->json([
            'success' => true,
            'message' => 'Google Analytics disconnected for this workspace.',
        ]);
    }
}
