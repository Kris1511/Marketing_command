<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GoogleSearchConsoleService;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class GoogleSearchConsoleController extends Controller
{
    protected GoogleSearchConsoleService $gscService;

    public function __construct(GoogleSearchConsoleService $gscService)
    {
        $this->gscService = $gscService;
    }

    /**
     * GET /api/v1/search-console/connect
     * Initiates Google OAuth 2.0 flow for Search Console.
     */
    public function connect(Request $request)
    {
        $workspaceId = (int)$request->query('workspace_id', 1);

        try {
            $authUrl = $this->gscService->createAuthUrl($workspaceId);

            if ($request->wantsJson()) {
                return response()->json([
                    'success'  => true,
                    'auth_url' => $authUrl,
                ]);
            }

            return redirect()->away($authUrl);
        } catch (Exception $e) {
            Log::error('Search Console OAuth Connect Error: ' . $e->getMessage());

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 500);
            }

            return $this->renderOAuthResponse(false, 'Search Console Connection Error', $e->getMessage());
        }
    }

    /**
     * GET /api/v1/search-console/callback
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
            $result = $this->gscService->handleOAuthCallback($code, $workspaceId);

            $siteCount = count($result['sites'] ?? []);
            $successMsg = $siteCount > 0
                ? ($result['selected_site_url']
                    ? "Successfully connected Search Console site: {$result['selected_site_url']}"
                    : "Found {$siteCount} Search Console properties. Please choose your site in Marketing Command.")
                : "Authenticated as {$result['user_email']}, but no verified Search Console properties were found.";

            return $this->renderOAuthResponse(
                true,
                'Google Search Console Connected!',
                $successMsg,
                $result
            );
        } catch (Exception $e) {
            Log::error('Search Console OAuth Callback Failed', ['error' => $e->getMessage()]);
            return $this->renderOAuthResponse(false, 'Search Console Connection Error', $e->getMessage());
        }
    }

    /**
     * Render popup response communicating via window.postMessage with opener.
     */
    protected function renderOAuthResponse(bool $success, string $title, string $message, array $extraData = [])
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $redirectUrl = $frontendUrl . '/integrations?gsc=' . ($success ? 'success' : 'error');
        $bg         = $success ? '#f0fdf4' : '#fef2f2';
        $titleColor = $success ? '#0284c7' : '#dc2626';
        $icon       = $success ? '&#x2714;' : '&#x274C;';

        $safeTitle   = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $payloadJs = json_encode(array_merge([
            'type'    => 'SEARCH_CONSOLE_OAUTH_RESULT',
            'success' => $success,
            'message' => $message,
        ], $extraData));

        return response(<<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{$safeTitle}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: {$bg};
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            text-align: center;
            max-width: 420px;
            width: 100%;
        }
        .icon { font-size: 40px; margin-bottom: 12px; }
        h3 { margin: 0 0 8px; color: {$titleColor}; font-size: 20px; }
        p { margin: 0 0 16px; color: #475569; font-size: 14px; line-height: 1.5; }
        .redirect-note { font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">{$icon}</div>
        <h3>{$safeTitle}</h3>
        <p>{$safeMessage}</p>
        <div class="redirect-note">Returning to Marketing Command...</div>
    </div>
    <script>
        (function() {
            var payload = {$payloadJs};
            if (window.opener) {
                try {
                    window.opener.postMessage(payload, '*');
                } catch(e) {}
                setTimeout(function() { window.close(); }, 1200);
            } else {
                setTimeout(function() {
                    window.location.href = '{$redirectUrl}';
                }, 1800);
            }
        })();
    </script>
</body>
</html>
HTML
        , $success ? 200 : 400)->header('Content-Type', 'text/html');
    }

    /**
     * GET /api/v1/search-console/sites
     * List all available properties for the active workspace.
     */
    public function sites(Request $request)
    {
        $workspaceId = (int)$request->query('workspace_id', 1);

        try {
            $accessToken = $this->gscService->getAccessToken($workspaceId);
            $sites = $this->gscService->listAvailableSites($accessToken);

            return response()->json([
                'success' => true,
                'sites'   => $sites,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'sites'   => [],
            ], 400);
        }
    }

    /**
     * POST /api/v1/search-console/select-site
     */
    public function selectSite(Request $request)
    {
        $request->validate([
            'workspace_id' => 'required|integer',
            'site_url'     => 'required|string|max:255',
        ]);

        $workspaceId = (int)$request->input('workspace_id');
        $siteUrl     = trim($request->input('site_url'));

        $integ = Integration::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'search_console',
            ],
            [
                'account_id'        => $siteUrl,
                'account_name'      => $siteUrl,
                'is_connected'      => true,
                'connection_status' => 'connected',
                'last_sync_at'      => now(),
            ]
        );

        return response()->json([
            'success'     => true,
            'message'     => "Search Console site '{$siteUrl}' selected successfully.",
            'integration' => $integ,
        ]);
    }

    /**
     * GET /api/v1/search-console/status
     */
    public function status(Request $request)
    {
        $workspaceId = (int)$request->query('workspace_id', 1);
        $isConfigured = $this->gscService->isConfigured($workspaceId);
        $siteUrl = $this->gscService->getSiteUrl($workspaceId);
        $credPath = base_path(env('GOOGLE_SEARCH_CONSOLE_CREDENTIALS_PATH', env('GOOGLE_ANALYTICS_CREDENTIALS_PATH', 'ga-credentials.json')));
        $hasCredentials = file_exists($credPath);

        $integ = Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'search_console')
            ->first();

        $isTokenExpired = false;
        if (!empty($integ?->refresh_token) || Cache::has("gsc_token_{$workspaceId}")) {
            try {
                $this->gscService->getAccessToken($workspaceId);
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
            'has_oauth'        => !empty($integ?->refresh_token) || Cache::has("gsc_token_{$workspaceId}"),
            'site_url'         => $siteUrl,
            'account_name'     => $integ?->account_name,
            'last_sync'        => $integ?->last_sync_at?->toIso8601String(),
            'message'          => $isTokenExpired
                ? "Google Search Console session has expired. Reconnection required."
                : ($isConnected
                    ? "Google Search Console is connected to site {$siteUrl}."
                    : 'Google Search Console is not connected.'),
        ]);
    }

    /**
     * POST /api/v1/search-console/configure
     */
    public function configure(Request $request)
    {
        $request->validate([
            'workspace_id' => 'required|integer',
            'site_url'     => 'required|string|max:255',
        ]);

        $workspaceId = (int)$request->input('workspace_id');
        $siteUrl     = trim($request->input('site_url'));

        $integ = Integration::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'search_console',
            ],
            [
                'account_id'        => $siteUrl,
                'account_name'      => $siteUrl,
                'is_connected'      => true,
                'connection_status' => 'connected',
                'last_sync_at'      => now(),
            ]
        );

        return response()->json([
            'success'     => true,
            'message'     => "Search Console Site URL '{$siteUrl}' configured successfully.",
            'integration' => $integ,
        ]);
    }

    /**
     * GET /api/v1/search-console/metrics
     */
    public function metrics(Request $request)
    {
        $workspaceId = (int)$request->query('workspace_id', 1);
        $startDate   = $request->query('start_date');
        $endDate     = $request->query('end_date');

        $limit       = max(1, min(100, (int)$request->query('limit', 10)));

        try {
            $overview = $this->gscService->getOverview($startDate, $endDate, $workspaceId);
            $queries  = $this->gscService->getTopQueries($startDate, $endDate, $limit, $workspaceId);
            $trend    = $this->gscService->getDailyTrend($startDate, $endDate, $workspaceId);

            $authRequired = !empty($overview['auth_required']);

            return response()->json([
                'success'       => true,
                'auth_required' => $authRequired,
                'data'          => [
                    'overview'      => $overview,
                    'queries'       => $queries,
                    'trend'         => $trend,
                    'auth_required' => $authRequired,
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Search Console metrics query error', ['error' => $e->getMessage()]);
            return response()->json([
                'success'       => false,
                'auth_required' => true,
                'message'       => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/v1/search-console/test
     */
    public function test(Request $request)
    {
        $workspaceId = (int)$request->input('workspace_id', $request->query('workspace_id', 1));

        if (!$this->gscService->isConfigured($workspaceId)) {
            return response()->json([
                'success' => false,
                'message' => 'Search Console credentials or Site URL not configured for this workspace.',
            ], 400);
        }

        try {
            $siteUrl  = $this->gscService->getSiteUrl($workspaceId);
            $overview = $this->gscService->getOverview(null, null, $workspaceId);

            if (!empty($overview['auth_required']) || !empty($overview['error'])) {
                return response()->json([
                    'success'       => false,
                    'auth_required' => true,
                    'message'       => $overview['error'] ?? 'Search Console authorization expired. Please reconnect.',
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => "Google Search Console site ({$siteUrl}) connection verified!",
                'data'    => [
                    'site_url'    => $siteUrl,
                    'clicks'      => (int)($overview['clicks'] ?? 0),
                    'impressions' => (int)($overview['impressions'] ?? 0),
                    'ctr'         => $overview['ctr'] ?? 0,
                    'position'    => $overview['position'] ?? 0,
                    'status'      => 'Live & Operational',
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Search Console Test Error: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /api/v1/search-console/disconnect
     */
    public function disconnect(Request $request)
    {
        $workspaceId = (int)$request->input('workspace_id', 1);

        $integ = Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'search_console')
            ->first();

        if ($integ) {
            $integ->update([
                'is_connected'      => false,
                'connection_status' => 'disconnected',
            ]);
        }

        Cache::forget("gsc_token_{$workspaceId}");

        return response()->json([
            'success' => true,
            'message' => 'Google Search Console disconnected for this workspace.',
        ]);
    }
}
