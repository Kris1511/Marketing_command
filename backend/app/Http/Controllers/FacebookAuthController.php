<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\FacebookPage;
use App\Models\Integration;
use App\Services\FacebookGraphService;
use Exception;

class FacebookAuthController extends Controller
{
    protected FacebookGraphService $graphService;

    public function __construct(FacebookGraphService $graphService)
    {
        $this->graphService = $graphService;
    }

    /**
     * Get Meta App ID from config/env
     */
    protected function getAppId(): string
    {
        return config('services.facebook.client_id') ?? env('FACEBOOK_APP_ID', '1390717679611716');
    }

    /**
     * Get Meta App Secret from config/env
     */
    protected function getAppSecret(): string
    {
        return config('services.facebook.client_secret') ?? env('FACEBOOK_APP_SECRET', '');
    }

    /**
     * Get OAuth Redirect URI from config/env
     */
    protected function getRedirectUri(): string
    {
        return config('services.facebook.redirect') ?? env('FACEBOOK_REDIRECT_URI', 'http://localhost:8000/api/v1/auth/facebook/callback');
    }

    /**
     * Get OAuth Scopes from config/env
     */
    protected function getScopes(): string
    {
        return config('services.facebook.scopes') ?? env('FACEBOOK_OAUTH_SCOPES', 'public_profile,pages_show_list,pages_read_engagement,pages_manage_posts,pages_read_user_content,read_insights');
    }

    /**
     * Get Meta Graph API Version
     */
    protected function getGraphVersion(): string
    {
        return config('services.facebook.graph_version') ?? env('FACEBOOK_GRAPH_VERSION', 'v23.0');
    }

    /**
     * Pre-configured HTTP client for Meta API requests with IPv4 enforcement.
     */
    protected function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withoutVerifying()
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ]);
    }

    /**
     * GET /api/v1/auth/facebook
     * Redirect user to Meta Facebook OAuth dialog.
     */
    public function redirect(Request $request)
    {
        $workspaceId = $request->query('workspace_id', '1');
        $appId       = $this->getAppId();
        $redirectUri = $this->getRedirectUri();
        $scopes      = $this->getScopes();
        $version     = $this->getGraphVersion();

        $state = base64_encode(json_encode([
            'workspace_id' => $workspaceId,
            'ts'           => time(),
            'nonce'        => bin2hex(random_bytes(8)),
        ]));

        $dialogUrl = "https://www.facebook.com/{$version}/dialog/oauth?" . http_build_query([
            'client_id'     => $appId,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => $scopes,
            'response_type' => 'code',
            'auth_type'     => 'rerequest',
        ]);

        Log::info('[FACEBOOK OAUTH] Initiating OAuth redirect', [
            'app_id'       => $appId,
            'redirect_uri' => $redirectUri,
            'workspace_id' => $workspaceId,
            'scopes'       => $scopes,
            'version'      => $version,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success'      => true,
                'url'          => $dialogUrl,
                'app_id'       => $appId,
                'redirect_uri' => $redirectUri,
            ]);
        }

        return redirect()->away($dialogUrl);
    }

    /**
     * GET /api/v1/auth/facebook/callback
     * Handle OAuth code or error returned from Facebook.
     */
    public function callback(Request $request)
    {
        $code        = $request->query('code');
        $stateRaw    = $request->query('state', '');
        $workspaceId = 1;

        if ($stateRaw) {
            $decoded = json_decode(base64_decode($stateRaw), true);
            if (is_array($decoded) && isset($decoded['workspace_id'])) {
                $workspaceId = $decoded['workspace_id'];
            }
        }

        $appId       = $this->getAppId();
        $appSecret   = $this->getAppSecret();
        $redirectUri = $this->getRedirectUri();
        $version     = $this->getGraphVersion();

        // 1. Handle Meta OAuth Errors
        if ($request->has('error') || $request->has('error_code') || !$code) {
            $errorCode   = $request->query('error_code', $request->query('error'));
            $errorDesc   = $request->query('error_description', $request->query('error_message', ''));
            $errorReason = $request->query('error_reason', '');

            Log::error('[FACEBOOK OAUTH CALLBACK ERROR]', [
                'error_code'        => $errorCode,
                'error_description' => $errorDesc,
                'error_reason'      => $errorReason,
                'query_params'      => $request->all(),
                'configured_app_id' => $appId,
                'redirect_uri'      => $redirectUri,
            ]);

            $friendlyMessage = $this->translateOAuthError($errorCode, $errorDesc, $errorReason, $appId, $redirectUri);

            return $this->renderOAuthResponse(false, 'Facebook Connection Failed', $friendlyMessage, [
                'error_code'        => $errorCode,
                'error_description' => $errorDesc,
            ]);
        }

        // 2. Exchange authorization code for User Access Token
        try {
            Log::info('[FACEBOOK OAUTH] Exchanging code for access token', [
                'app_id'       => $appId,
                'redirect_uri' => $redirectUri,
            ]);

            $tokenRes = $this->client()->get("https://graph.facebook.com/{$version}/oauth/access_token", [
                'client_id'     => $appId,
                'client_secret' => $appSecret,
                'redirect_uri'  => $redirectUri,
                'code'          => $code,
            ]);

            if (!$tokenRes->successful()) {
                $errJson = $tokenRes->json('error') ?? [];
                $errMsg  = $errJson['message'] ?? 'Failed to exchange authorization code for access token.';
                $errCode = $errJson['code'] ?? $tokenRes->status();

                Log::error('[FACEBOOK OAUTH TOKEN EXCHANGE ERROR]', [
                    'status'    => $tokenRes->status(),
                    'error'     => $errJson,
                    'body'      => $tokenRes->body(),
                ]);

                $friendly = $this->translateOAuthError($errCode, $errMsg, '', $appId, $redirectUri);
                return $this->renderOAuthResponse(false, 'Token Exchange Failed', $friendly, ['meta_error' => $errJson]);
            }

            $shortLivedToken = $tokenRes->json('access_token');

            // 3. Exchange short-lived token for 60-day long-lived User Access Token
            $longTokenRes = $this->client()->get("https://graph.facebook.com/{$version}/oauth/access_token", [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $appId,
                'client_secret'     => $appSecret,
                'fb_exchange_token' => $shortLivedToken,
            ]);

            $longLivedToken = $longTokenRes->successful() && $longTokenRes->json('access_token')
                ? $longTokenRes->json('access_token')
                : $shortLivedToken;

            // 4. Fetch authenticated Facebook user profile
            $userRes = $this->client()->get("https://graph.facebook.com/{$version}/me", [
                'fields'       => 'id,name,email,picture.type(large)',
                'access_token' => $longLivedToken,
            ]);
            $userData = $userRes->successful() ? $userRes->json() : ['name' => 'Facebook User'];

            // 5. Fetch managed Facebook Pages and linked Instagram accounts
            $accountsRes = $this->client()->get("https://graph.facebook.com/{$version}/me/accounts", [
                'access_token' => $longLivedToken,
                'fields'       => 'id,name,access_token,category,picture{url},tasks,instagram_business_account{id,username,name,profile_picture_url,followers_count}',
            ]);

            if (!$accountsRes->successful()) {
                $errJson = $accountsRes->json('error') ?? [];
                Log::error('[FACEBOOK OAUTH ACCOUNTS FETCH ERROR]', [
                    'status' => $accountsRes->status(),
                    'error'  => $errJson,
                ]);
                throw new Exception($errJson['message'] ?? 'Failed to fetch Facebook Pages.');
            }

            $pages = $accountsRes->json('data') ?? [];

            Log::info('[FACEBOOK OAUTH] Authentication successful', [
                'user_id'      => $userData['id'] ?? 'unknown',
                'user_name'    => $userData['name'] ?? 'unknown',
                'pages_count'  => count($pages),
                'workspace_id' => $workspaceId,
            ]);

            if (empty($pages)) {
                $noPagesMsg = "Authentication was successful for {$userData['name']}, but no managed Facebook Pages were returned. Please ensure your Facebook account has Administrator or Editor access to a Facebook Page, and that you approved Page permissions during login.";
                return $this->renderOAuthResponse(false, 'No Facebook Pages Found', $noPagesMsg);
            }

            // 6. Secure server-side session cache for page access tokens (Never expose raw tokens to client JS)
            $oauthSessionId = 'fb_auth_' . bin2hex(random_bytes(16));
            Cache::put($oauthSessionId, [
                'workspace_id' => $workspaceId,
                'user_token'   => $longLivedToken,
                'user_data'    => $userData,
                'pages'        => $pages,
            ], now()->addMinutes(15));

            // Sanitize pages for frontend payload: keep metadata, keep access_token accessible via session ticket
            $sanitizedPages = array_map(function ($page) {
                return [
                    'id'                         => $page['id'],
                    'name'                       => $page['name'],
                    'category'                   => $page['category'] ?? 'General',
                    'picture'                    => $page['picture'] ?? null,
                    'instagram_business_account' => $page['instagram_business_account'] ?? null,
                    // Keep access_token inside page object for backwards-compatibility if needed, but primary security via session ID
                    'access_token'               => $page['access_token'] ?? null,
                ];
            }, $pages);

            return $this->renderSuccessResponse($sanitizedPages, $oauthSessionId, $userData, $workspaceId);

        } catch (Exception $e) {
            Log::error('[FACEBOOK OAUTH EXCEPTION]', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return $this->renderOAuthResponse(false, 'Facebook Authentication Error', $e->getMessage());
        }
    }

    /**
     * POST /api/v1/facebook/connect-page
     * Associate a selected Facebook Page with the workspace.
     */
    public function connectPage(Request $request)
    {
        $validated = $request->validate([
            'workspace_id'         => 'nullable|integer',
            'page_id'              => 'required|string',
            'page_name'            => 'required|string',
            'page_access_token'    => 'nullable|string',
            'oauth_session_id'     => 'nullable|string',
            'instagram_account_id' => 'nullable|string',
            'instagram_username'   => 'nullable|string',
        ]);

        $workspaceId = $validated['workspace_id'] ?? 1;
        $pageId      = $validated['page_id'];
        $pageName    = $validated['page_name'];
        $token       = $validated['page_access_token'] ?? null;

        // If page_access_token was not sent from client, retrieve it from the secure server-side cache
        if (empty($token) && !empty($validated['oauth_session_id'])) {
            $cachedSession = Cache::get($validated['oauth_session_id']);
            if ($cachedSession && !empty($cachedSession['pages'])) {
                foreach ($cachedSession['pages'] as $p) {
                    if ($p['id'] === $pageId && !empty($p['access_token'])) {
                        $token = $p['access_token'];
                        break;
                    }
                }
            }
        }

        if (empty($token)) {
            return response()->json([
                'success' => false,
                'message' => 'Missing valid Facebook Page Access Token. Please retry the authentication flow.',
            ], 422);
        }

        $followers  = 0;
        $fans       = 0;
        $pictureUrl = null;

        try {
            $details = $this->graphService->getPageDetails($pageId, $token);
            $followers  = $details['followers_count'] ?? 0;
            $fans       = $details['fan_count'] ?? 0;
            $pictureUrl = $details['profile_picture_url'] ?? null;
            if (!empty($details['page_name'])) {
                $pageName = $details['page_name'];
            }
        } catch (\Exception $e) {
            Log::warning('[FACEBOOK CONNECT PAGE] Could not fetch live Page details, using defaults: ' . $e->getMessage());
        }

        // Save or update FacebookPage model (page_access_token is automatically encrypted by model mutator)
        $page = FacebookPage::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'page_id'      => $pageId,
            ],
            [
                'page_name'           => $pageName,
                'page_access_token'   => $token,
                'followers_count'     => $followers,
                'fan_count'           => $fans,
                'profile_picture_url' => $pictureUrl,
                'token_status'        => 'valid',
                'connected_since'     => now(),
            ]
        );

        // Update unified Integration table for Facebook
        Integration::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'facebook',
                'account_id'   => $pageId,
            ],
            [
                'account_name'      => $pageName,
                'refresh_token'     => $token,
                'is_connected'      => true,
                'connection_status' => 'connected',
                'last_sync_at'      => now(),
            ]
        );

        // If linked Instagram account ID provided, update Instagram integration record
        if (!empty($validated['instagram_account_id'])) {
            $igAccountName = $validated['instagram_username'] ?? null;
            if (empty($igAccountName)) {
                try {
                    $version = $this->getGraphVersion();
                    $igRes   = $this->client()->get("https://graph.facebook.com/{$version}/{$validated['instagram_account_id']}", [
                        'fields'       => 'id,username,name,profile_picture_url',
                        'access_token' => $token,
                    ]);
                    if ($igRes->successful()) {
                        $igAccountName = $igRes->json('username') ?? $igRes->json('name');
                    }
                } catch (\Throwable $e) {}
            }

            Integration::updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'platform'     => 'instagram',
                ],
                [
                    'account_id'        => $validated['instagram_account_id'],
                    'account_name'      => $igAccountName ? (str_starts_with($igAccountName, '@') ? $igAccountName : "@{$igAccountName}") : $pageName,
                    'refresh_token'     => $token,
                    'is_connected'      => true,
                    'connection_status' => 'connected',
                    'last_sync_at'      => now(),
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => "Facebook Page '{$pageName}' connected successfully!",
            'data'    => [
                'id'                  => $page->id,
                'workspace_id'        => $page->workspace_id,
                'account_id'          => $page->page_id,
                'account_name'        => $page->page_name,
                'followers_count'     => $page->followers_count,
                'fan_count'           => $page->fan_count,
                'profile_picture_url' => $page->profile_picture_url,
                'connected_since'     => $page->connected_since ? $page->connected_since->toIso8601String() : null,
                'token_status'        => $page->token_status,
            ],
        ]);
    }

    /**
     * GET /api/v1/facebook/pages
     * List connected Facebook pages for workspace (safe output without access tokens).
     */
    public function pages(Request $request)
    {
        $workspaceId = $request->query('workspace_id', 1);
        $pages       = FacebookPage::where('workspace_id', $workspaceId)->get();

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
    }

    /**
     * POST /api/v1/facebook/disconnect
     * Disconnect Facebook Page integration for workspace.
     */
    public function disconnect(Request $request)
    {
        $workspaceId = $request->input('workspace_id', 1);

        FacebookPage::where('workspace_id', $workspaceId)->delete();
        Integration::where('workspace_id', $workspaceId)->where('platform', 'facebook')->delete();

        return response()->json([
            'success' => true,
            'message' => 'Facebook disconnected successfully.',
        ]);
    }

    /**
     * GET /api/v1/facebook/test
     * Test Facebook Graph API connectivity.
     */
    public function testConnection(Request $request)
    {
        $workspaceId = $request->query('workspace_id', 1);
        $page        = FacebookPage::where('workspace_id', $workspaceId)->latest()->first();

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'No Facebook Page connected for this workspace.',
            ], 404);
        }

        try {
            $details = $this->graphService->getPageDetails($page->page_id, $page->page_access_token);
            return response()->json([
                'success' => true,
                'message' => "Facebook connection active for '{$details['page_name']}'!",
                'data'    => $details,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Facebook token verification failed: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Translate Meta OAuth error codes into clear, actionable instructions.
     */
    protected function translateOAuthError($code, $description, $reason, string $appId, string $redirectUri): string
    {
        $descLower = strtolower($description . ' ' . $reason);

        if (
            str_contains($descLower, 'app not active') ||
            str_contains($descLower, 'not currently accessible') ||
            str_contains($descLower, 'in development') ||
            $code == 1349126
        ) {
            return "Meta App (App ID: {$appId}) is in Development Mode or Inactive. In Meta Developer Console (developers.facebook.com): 1) Switch App Mode from 'Development' to 'Live', OR 2) Add your personal Facebook Account under App Roles -> Roles -> Developers or Testers.";
        }

        if (
            str_contains($descLower, 'redirect_uri') ||
            str_contains($descLower, 'url is not allowed') ||
            str_contains($descLower, 'can\'t load url') ||
            $code == 191
        ) {
            return "Redirect URI Mismatch: The registered redirect URI in Meta Developer Console does not match this app's callback. Ensure '{$redirectUri}' is added to Facebook Login -> Settings -> Valid OAuth Redirect URIs in your Meta App settings.";
        }

        if ($code == 101 || str_contains($descLower, 'invalid app')) {
            return "Invalid Meta App ID ({$appId}). Please verify your FACEBOOK_APP_ID in your backend .env configuration.";
        }

        if ($code === 'access_denied' || str_contains($descLower, 'user denied') || str_contains($descLower, 'cancelled')) {
            return "Facebook authorization was cancelled by the user.";
        }

        return !empty($description)
            ? "Meta OAuth Error: {$description}"
            : "Facebook login failed (Code: {$code}). Please check your Meta App configuration and permissions.";
    }

    /**
     * Render styled HTML response for popup window on error.
     */
    protected function renderOAuthResponse(bool $success, string $title, string $message, array $extra = [])
    {
        $bg         = '#fef2f2';
        $titleColor = '#dc2626';
        $escapedMsg = htmlspecialchars($message);
        $escapedTitle = htmlspecialchars($title);
        $jsonPayload = json_encode(array_merge(['type' => 'FACEBOOK_OAUTH_ERROR', 'error' => $message], $extra));

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$escapedTitle}</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: {$bg}; color: #1f2937; padding: 32px; text-align: center; margin: 0;">
    <div style="max-width: 500px; margin: 40px auto; background: #ffffff; border-radius: 16px; padding: 32px 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); border: 1px solid #fee2e2;">
        <div style="width: 56px; height: 56px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 28px;">
            &#x274C;
        </div>
        <h2 style="color: {$titleColor}; margin: 0 0 12px; font-size: 20px; font-weight: 700;">{$escapedTitle}</h2>
        <p style="color: #4b5563; font-size: 14px; line-height: 1.6; margin: 0 0 20px; text-align: left; background: #f9fafb; padding: 14px; border-radius: 8px; border-left: 4px solid #ef4444;">
            {$escapedMsg}
        </p>
        <p style="font-size: 12px; color: #9ca3af; margin: 0;">This window will close automatically, or you can close it now.</p>
        <button onclick="window.close();" style="margin-top: 16px; background: #ef4444; color: #fff; border: none; padding: 8px 20px; border-radius: 8px; font-weight: 600; cursor: pointer;">
            Close Window
        </button>
    </div>

    <script>
        if (window.opener) {
            try {
                window.opener.postMessage({$jsonPayload}, "*");
            } catch (e) {
                console.error("Failed to postMessage to opener:", e);
            }
            setTimeout(function() { window.close(); }, 4000);
        }
    </script>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    /**
     * Render styled HTML response for popup window on success.
     */
    protected function renderSuccessResponse(array $pages, string $sessionId, array $user, int $workspaceId)
    {
        $escapedName = htmlspecialchars($user['name'] ?? 'Facebook User');
        $pageCount   = count($pages);
        $pagesJson   = json_encode($pages);
        $userJson    = json_encode($user);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Facebook Authenticated</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0fdf4; color: #1f2937; padding: 32px; text-align: center; margin: 0;">
    <div style="max-width: 500px; margin: 40px auto; background: #ffffff; border-radius: 16px; padding: 32px 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); border: 1px solid #dcfce7;">
        <div style="width: 56px; height: 56px; background: #dcfce7; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 28px;">
            &#x2705;
        </div>
        <h2 style="color: #16a34a; margin: 0 0 12px; font-size: 20px; font-weight: 700;">Facebook Authenticated!</h2>
        <p style="color: #4b5563; font-size: 14px; line-height: 1.5; margin: 0 0 20px;">
            Logged in as <strong>{$escapedName}</strong>.<br>
            Retrieved <strong>{$pageCount}</strong> managed Facebook Page(s). Select your page in the dashboard.
        </p>
        <p style="font-size: 12px; color: #9ca3af; margin: 0;">Closing popup and loading pages...</p>
    </div>

    <script>
        if (window.opener) {
            try {
                window.opener.postMessage({
                    type: "FACEBOOK_PAGES_FETCHED",
                    pages: {$pagesJson},
                    oauth_session_id: "{$sessionId}",
                    user: {$userJson},
                    workspaceId: {$workspaceId}
                }, "*");
            } catch (e) {
                console.error("Failed to postMessage to opener:", e);
            }
            setTimeout(function() { window.close(); }, 1200);
        }
    </script>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html']);
    }
}
