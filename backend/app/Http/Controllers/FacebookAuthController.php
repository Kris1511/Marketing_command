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
     * Check if workspace is designated as the Testing workspace.
     */
    protected function isTestingWorkspace(?int $workspaceId): bool
    {
        if (!$workspaceId) {
            return false;
        }

        $rawConfig = (string) (config('services.facebook.testing_workspace_id') ?? '8');
        $configuredTestingIds = array_filter(array_map('trim', explode(',', $rawConfig)));

        return in_array((string) $workspaceId, $configuredTestingIds, true);
    }

    /**
     * Get Meta App ID from config/env (workspace-aware).
     */
    protected function getAppId(?int $workspaceId = null): string
    {
        if ($this->isTestingWorkspace($workspaceId)) {
            $testingAppId = config('services.facebook.testing_client_id');
            if (empty($testingAppId)) {
                throw new \RuntimeException('Testing workspace (ID 8) requires FACEBOOK_TESTING_APP_ID to be configured. Fallback to default app credentials is prohibited.');
            }
            return (string) $testingAppId;
        }

        return (string) (config('services.facebook.client_id') ?? '');
    }

    /**
     * Get Meta App Secret from config/env (workspace-aware).
     */
    protected function getAppSecret(?int $workspaceId = null): string
    {
        if ($this->isTestingWorkspace($workspaceId)) {
            $testingSecret = config('services.facebook.testing_client_secret');
            if (empty($testingSecret)) {
                throw new \RuntimeException('Testing workspace (ID 8) requires FACEBOOK_TESTING_APP_SECRET to be configured. Fallback to default app credentials is prohibited.');
            }
            return (string) $testingSecret;
        }

        return (string) (config('services.facebook.client_secret') ?? '');
    }

    /**
     * Get OAuth Redirect URI from config/env (workspace-aware).
     */
    protected function getRedirectUri(?int $workspaceId = null): string
    {
        if ($this->isTestingWorkspace($workspaceId)) {
            $testingRedirect = config('services.facebook.testing_redirect');
            if (!empty($testingRedirect)) {
                return (string) $testingRedirect;
            }
        }

        return (string) (config('services.facebook.redirect') ?? 'http://localhost:8000/api/v1/auth/facebook/callback');
    }

    /**
     * Get OAuth Scopes from config/env
     */
    protected function getScopes(): string
    {
        $raw = config('services.facebook.scopes') ?? env('FACEBOOK_OAUTH_SCOPES', 'public_profile,pages_show_list,pages_read_engagement,pages_manage_posts,pages_read_user_content,pages_manage_metadata,pages_messaging,instagram_basic,instagram_content_publish,instagram_manage_insights,instagram_manage_comments,instagram_manage_messages,read_insights');
        $scopeList = array_filter(array_map('trim', explode(',', $raw)));

        // Ensure required base permissions for Page discovery, messaging & insights
        foreach (['pages_show_list', 'pages_read_engagement', 'pages_read_user_content', 'instagram_manage_messages', 'read_insights'] as $req) {
            if (!in_array($req, $scopeList, true)) {
                $scopeList[] = $req;
            }
        }

        // When Facebook publishing is enabled, ensure pages_manage_posts is explicitly requested during OAuth
        if (filter_var(config('services.facebook.publishing_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            if (!in_array('pages_manage_posts', $scopeList, true)) {
                $scopeList[] = 'pages_manage_posts';
            }
        }

        return implode(',', array_unique($scopeList));
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
     * Resolve Facebook Page for a workspace, automatically falling back
     * to any existing valid connection across workspaces if needed.
     */
    protected function resolvePage($workspaceId): ?FacebookPage
    {
        return FacebookPage::where('workspace_id', $workspaceId)
            ->whereNotNull('page_access_token')
            ->whereNotIn('token_status', ['invalid', 'disconnected', 'revoked'])
            ->latest('updated_at')
            ->first();
    }

    /**
     * GET /api/v1/auth/facebook
     * Redirect user to Meta Facebook OAuth dialog.
     */
    public function redirect(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        if (empty($workspaceId) || $workspaceId === 'all') {
            $workspaceId = \App\Models\Workspace::first()?->id;
        }
        if (!$workspaceId) {
            return response()->json([
                'success' => false,
                'message' => 'A valid workspace must be selected or exist to connect Facebook/Instagram.',
            ], 422);
        }
        $workspaceId = (int) $workspaceId;
        $force       = $request->boolean('force') || $request->boolean('reconnect');

        // 1. Detect whether an active valid Facebook connection already exists for this workspace or across workspaces
        if (!$force) {
            $existingPage = $this->resolvePage($workspaceId);

            if ($existingPage) {
                Log::info('[FACEBOOK OAUTH] Active valid connection already exists. Reusing credentials.', [
                    'workspace_id' => $workspaceId,
                    'page_id'      => $existingPage->page_id,
                    'page_name'    => $existingPage->page_name,
                ]);

                if ($request->wantsJson()) {
                    return response()->json([
                        'success'           => true,
                        'already_connected' => true,
                        'message'           => "Facebook Page '{$existingPage->page_name}' is already connected and operational.",
                        'data'              => [
                            'page_id'             => $existingPage->page_id,
                            'page_name'           => $existingPage->page_name,
                            'followers_count'     => $existingPage->followers_count,
                            'profile_picture_url' => $existingPage->profile_picture_url,
                            'connected_since'     => $existingPage->connected_since,
                        ],
                    ]);
                }

                return $this->renderAlreadyConnectedResponse(
                    'Already Connected',
                    "Facebook Page '{$existingPage->page_name}' is already connected and operational for this workspace. Stored credentials are valid.",
                    [
                        'type'         => 'FACEBOOK_ALREADY_CONNECTED',
                        'workspace_id' => $workspaceId,
                        'page_id'      => $existingPage->page_id,
                        'page_name'    => $existingPage->page_name,
                    ]
                );
            }
        }

        try {
            $appId = $this->getAppId($workspaceId);
        } catch (\Throwable $e) {
            Log::error('[FACEBOOK OAUTH REDIRECT ERROR] ' . $e->getMessage(), ['workspace_id' => $workspaceId]);
            return response()->json([
                'success' => false,
                'error'   => 'configuration_error',
                'message' => $e->getMessage(),
            ], 500);
        }

        $redirectUri = $this->getRedirectUri($workspaceId);
        $scopes      = $request->query('scope') ?: $this->getScopes();
        $version     = $this->getGraphVersion();

        // Ask Meta whether it accepts this App ID/Secret before sending the user to facebook.com, so a
        // misconfigured app shows Meta's real error here instead of a dead-end page on Facebook.
        $metaError = $this->preflightApp($workspaceId, $appId);
        if ($metaError !== null) {
            $friendly = $this->translateOAuthError($metaError['code'] ?? '', $metaError['message'] ?? '', '', $appId, $redirectUri);

            if ($request->wantsJson()) {
                return response()->json([
                    'success'    => false,
                    'error'      => 'meta_app_error',
                    'message'    => $friendly,
                    'meta_error' => $metaError,
                ], 502);
            }

            return $this->renderOAuthResponse(false, 'Meta App Configuration Error', $friendly, ['meta_error' => $metaError]);
        }

        // 1. Generate cryptographically secure one-time nonce (128-bit)
        $nonce = bin2hex(random_bytes(16));

        // 2. Store nonce in server cache with 15-minute TTL for single-use consumption
        Cache::put("fb_oauth_nonce_{$nonce}", $workspaceId, now()->addMinutes(15));

        // 3. Construct state payload and sign with HMAC-SHA256 using APP_KEY
        $statePayload = json_encode([
            'workspace_id' => $workspaceId,
            'ts'           => time(),
            'nonce'        => $nonce,
        ]);
        $stateSig = hash_hmac('sha256', $statePayload, config('app.key'));
        $state    = base64_encode(json_encode([
            'payload' => $statePayload,
            'sig'     => $stateSig,
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
            'forced'       => $force,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success'      => true,
                'url'          => $dialogUrl,
                'app_id'       => $appId,
                'redirect_uri' => $redirectUri,
                'state'        => $state,
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
        $workspaceId = null;

        // Strict HMAC verification: reject any state missing a valid HMAC signature (zero legacy fallback)
        if (empty($stateRaw)) {
            Log::warning('[FACEBOOK OAUTH CALLBACK] Missing OAuth state parameter.');
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'missing_state',
                    'message' => 'Missing OAuth state parameter. Request rejected for security.',
                ], 403);
            }
            return $this->renderErrorPopup(
                'Invalid OAuth State',
                'Missing OAuth state parameter. Authentication request rejected for security.'
            );
        }

        $container = json_decode(base64_decode($stateRaw), true);
        if (!is_array($container) || empty($container['payload']) || empty($container['sig'])) {
            Log::warning('[FACEBOOK OAUTH CALLBACK] Unsigned or malformed OAuth state envelope.');
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'invalid_state_format',
                    'message' => 'OAuth state format is invalid or unsigned. Legacy fallback is disabled.',
                ], 403);
            }
            return $this->renderErrorPopup(
                'Invalid OAuth State',
                'OAuth state signature is missing or malformed. Unsigned legacy requests are rejected.'
            );
        }

        $expectedSig = hash_hmac('sha256', $container['payload'], config('app.key'));
        if (!hash_equals($expectedSig, $container['sig'])) {
            Log::warning('[FACEBOOK OAUTH CALLBACK] HMAC signature mismatch on OAuth state.');
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'tampered_state',
                    'message' => 'OAuth state cryptographic signature verification failed. Tampering detected.',
                ], 403);
            }
            return $this->renderErrorPopup(
                'Tampered OAuth State',
                'OAuth state signature verification failed. The request has been tampered with or is invalid.'
            );
        }

        $decoded = json_decode($container['payload'], true);
        if (!is_array($decoded) || !isset($decoded['workspace_id']) || !isset($decoded['ts']) || empty($decoded['nonce'])) {
            Log::warning('[FACEBOOK OAUTH CALLBACK] Incomplete payload claims in OAuth state.');
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'invalid_payload',
                    'message' => 'OAuth state payload is malformed or missing required claims.',
                ], 403);
            }
            return $this->renderErrorPopup(
                'Invalid OAuth State',
                'OAuth state payload is malformed or missing required security claims.'
            );
        }

        // Strict timestamp validation
        $ts  = $decoded['ts'];
        $now = time();
        if (!is_int($ts)) {
            Log::warning('[FACEBOOK OAUTH CALLBACK] Non-integer timestamp in OAuth state.');
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'invalid_timestamp',
                    'message' => 'OAuth state timestamp is invalid.',
                ], 403);
            }
            return $this->renderErrorPopup(
                'Invalid OAuth Timestamp',
                'OAuth state timestamp is invalid.'
            );
        }

        if ($ts > ($now + 60)) {
            Log::warning('[FACEBOOK OAUTH CALLBACK] Future-dated timestamp in OAuth state.', ['ts' => $ts, 'now' => $now]);
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'future_timestamp',
                    'message' => 'OAuth state timestamp is future-dated. Clock skew violation.',
                ], 403);
            }
            return $this->renderErrorPopup(
                'Invalid OAuth Timestamp',
                'OAuth state timestamp is future-dated. Clock skew violation.'
            );
        }

        if (($now - $ts) > 900) {
            Log::warning('[FACEBOOK OAUTH CALLBACK] Expired OAuth state.', ['ts' => $ts, 'now' => $now]);
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'expired_timestamp',
                    'message' => 'OAuth state has expired (15-minute TTL).',
                ], 403);
            }
            return $this->renderErrorPopup(
                'OAuth Session Expired',
                'OAuth state has expired (15-minute TTL). Please initiate Facebook connection again.'
            );
        }

        $workspaceId = (int) $decoded['workspace_id'];
        $nonce       = (string) $decoded['nonce'];

        // Single-use atomic nonce validation (OAuth CSRF & Replay protection)
        $cachedWorkspaceId = Cache::pull("fb_oauth_nonce_{$nonce}");
        if ($cachedWorkspaceId === null || (int) $cachedWorkspaceId !== $workspaceId) {
            Log::warning('[FACEBOOK OAUTH CALLBACK] Nonce already consumed or invalid.', [
                'nonce'        => $nonce,
                'workspace_id' => $workspaceId,
            ]);
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'nonce_consumed_or_invalid',
                    'message' => 'OAuth state nonce is invalid or has already been used. Replay attempt blocked.',
                ], 403);
            }
            return $this->renderErrorPopup(
                'Invalid OAuth Nonce',
                'OAuth state nonce is invalid or has already been used. Replay attempt blocked.'
            );
        }

        // Concurrent replay guard across multi-process workers using atomic Cache::add
        if (!Cache::add("fb_oauth_consumed_{$nonce}", 1, now()->addMinutes(15))) {
            Log::warning('[FACEBOOK OAUTH CALLBACK] Concurrent race replay detected for nonce.', [
                'nonce'        => $nonce,
                'workspace_id' => $workspaceId,
            ]);
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'nonce_consumed_or_invalid',
                    'message' => 'OAuth state nonce has already been consumed. Concurrent replay attempt blocked.',
                ], 403);
            }
            return $this->renderErrorPopup(
                'Invalid OAuth Nonce',
                'OAuth state nonce has already been consumed. Concurrent replay attempt blocked.'
            );
        }

        try {
            $appId       = $this->getAppId($workspaceId);
            $appSecret   = $this->getAppSecret($workspaceId);
        } catch (\Throwable $e) {
            Log::error('[FACEBOOK OAUTH CALLBACK CONFIG ERROR] ' . $e->getMessage(), ['workspace_id' => $workspaceId]);
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'configuration_error',
                    'message' => $e->getMessage(),
                ], 500);
            }
            return $this->renderErrorPopup('Configuration Error', $e->getMessage());
        }

        $redirectUri = $this->getRedirectUri($workspaceId);
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
                    'body'      => $this->redactSecrets($tokenRes->body()),
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

            // 5. Inspect Token Scopes & Granular Permissions (For Meta Business Login diagnostic)
            $debugRes = $this->client()->get("https://graph.facebook.com/{$version}/debug_token", [
                'input_token'  => $longLivedToken,
                'access_token' => "{$appId}|{$appSecret}",
            ]);
            $debugData      = $debugRes->json('data') ?? [];
            $grantedScopes  = $debugData['scopes'] ?? [];
            $granularScopes = $debugData['granular_scopes'] ?? [];

            // Verify required permission chain for Facebook Page publishing
            $isPublishingEnabled = filter_var(config('services.facebook.publishing_enabled', true), FILTER_VALIDATE_BOOLEAN);
            $requiredChain = ['pages_show_list', 'pages_read_engagement'];
            if ($isPublishingEnabled) {
                $requiredChain[] = 'pages_manage_posts';
            }
            $missingScopes = array_values(array_diff($requiredChain, $grantedScopes));
            $hasPublishingScope = in_array('pages_manage_posts', $grantedScopes, true);

            Log::info('[FACEBOOK OAUTH DEBUG]', [
                'user_id'            => $userData['id'] ?? null,
                'user_name'          => $userData['name'] ?? null,
                'is_valid_token'     => $debugData['is_valid'] ?? false,
                'granted_scopes'     => $grantedScopes,
                'missing_scopes'     => $missingScopes,
                'publishing_ready'   => empty($missingScopes),
                'granular_scopes'    => array_map(function ($g) {
                    return [
                        'scope'      => $g['scope'] ?? '',
                        'target_ids' => $g['target_ids'] ?? [],
                    ];
                }, $granularScopes),
            ]);

            // 6. Multi-Tier Page Discovery (Handles Standard Pages, NPE Pages, Business Portfolio Assets & Granular Target IDs)
            $pages = [];
            $seenPageIds = [];

            // Helper to safely add unique page
            $addPage = function ($p) use (&$pages, &$seenPageIds, $version, $longLivedToken, $hasPublishingScope, $missingScopes) {
                if (empty($p['id']) || isset($seenPageIds[$p['id']])) return;
                $seenPageIds[$p['id']] = true;

                // Ensure page access token exists; if not returned, attempt direct fetch
                if (empty($p['access_token'])) {
                    try {
                        $tokenCheck = $this->client()->get("https://graph.facebook.com/{$version}/{$p['id']}", [
                            'fields'       => 'access_token',
                            'access_token' => $longLivedToken,
                        ]);
                        if ($tokenCheck->successful() && $tokenCheck->json('access_token')) {
                            $p['access_token'] = $tokenCheck->json('access_token');
                        }
                    } catch (\Throwable $e) {}
                }

                // If instagram account is missing, try fetching it
                if (empty($p['instagram_business_account']) && !empty($p['access_token'])) {
                    try {
                        $igRes = $this->client()->get("https://graph.facebook.com/{$version}/{$p['id']}", [
                            'fields'       => 'instagram_business_account{id,username,name,profile_picture_url}',
                            'access_token' => $p['access_token'],
                        ]);
                        if ($igRes->successful() && $igRes->json('instagram_business_account')) {
                            $p['instagram_business_account'] = $igRes->json('instagram_business_account');
                        }
                    } catch (\Throwable $e) {}
                }

                // Validate Page tasks for publishing rights (CREATE_CONTENT or MANAGE)
                $pageTasks = (array) ($p['tasks'] ?? []);
                $hasContentTask = empty($pageTasks) || in_array('CREATE_CONTENT', $pageTasks, true) || in_array('MANAGE', $pageTasks, true);
                $p['can_publish'] = $hasPublishingScope && $hasContentTask;
                $p['tasks'] = $pageTasks;
                $p['missing_permissions'] = $missingScopes;

                $pages[] = $p;
            };

            // Tier 1: Query /me/accounts with full supported fields
            $accountsRes = $this->client()->get("https://graph.facebook.com/{$version}/me/accounts", [
                'access_token' => $longLivedToken,
                'fields'       => 'id,name,access_token,category,picture{url},tasks,instagram_business_account{id,username,name,profile_picture_url}',
                'limit'        => 100,
            ]);

            if ($accountsRes->successful()) {
                foreach ($accountsRes->json('data') ?? [] as $p) {
                    $addPage($p);
                }
            } else {
                Log::warning('[FACEBOOK OAUTH /me/accounts Tier 1 Error]', [
                    'status' => $accountsRes->status(),
                    'error'  => $accountsRes->json('error'),
                ]);
            }

            // Tier 2: If empty, try simplified fields (bypasses any nested schema restrictions)
            if (empty($pages)) {
                $simpleRes = $this->client()->get("https://graph.facebook.com/{$version}/me/accounts", [
                    'access_token' => $longLivedToken,
                    'fields'       => 'id,name,access_token,category',
                    'limit'        => 100,
                ]);
                if ($simpleRes->successful()) {
                    foreach ($simpleRes->json('data') ?? [] as $p) {
                        $addPage($p);
                    }
                }
            }

            // Tier 3: Resolve Page IDs explicitly selected in Facebook Login for Business Granular Scopes
            if (empty($pages) && !empty($granularScopes)) {
                $targetPageIds = [];
                foreach ($granularScopes as $g) {
                    if (!empty($g['target_ids'])) {
                        foreach ($g['target_ids'] as $tId) {
                            $targetPageIds[(string)$tId] = true;
                        }
                    }
                }

                if (!empty($targetPageIds)) {
                    Log::info('[FACEBOOK OAUTH Tier 3] Fetching Pages from granular target_ids', [
                        'target_ids' => array_keys($targetPageIds),
                    ]);

                    foreach (array_keys($targetPageIds) as $tId) {
                        $pRes = $this->client()->get("https://graph.facebook.com/{$version}/{$tId}", [
                            'fields'       => 'id,name,access_token,category,picture{url},instagram_business_account{id,username,name,profile_picture_url}',
                            'access_token' => $longLivedToken,
                        ]);
                        if ($pRes->successful() && $pRes->json('id')) {
                            $addPage($pRes->json());
                        } else {
                            Log::warning("[FACEBOOK OAUTH Tier 3] Direct fetch for target Page {$tId} failed", [
                                'status' => $pRes->status(),
                                'error'  => $pRes->json('error'),
                            ]);
                        }
                    }
                }
            }

            // Tier 4: Query /me/assigned_pages (for Meta Business Portfolio / New Pages Experience)
            if (empty($pages)) {
                $assignedRes = $this->client()->get("https://graph.facebook.com/{$version}/me/assigned_pages", [
                    'access_token' => $longLivedToken,
                    'fields'       => 'id,name,access_token,category,picture{url}',
                    'limit'        => 100,
                ]);
                if ($assignedRes->successful()) {
                    foreach ($assignedRes->json('data') ?? [] as $p) {
                        $addPage($p);
                    }
                }
            }

            // Tier 5: Query Meta Business Portfolio Owned / Client Pages (/me/businesses)
            if (empty($pages)) {
                $bizRes = $this->client()->get("https://graph.facebook.com/{$version}/me/businesses", [
                    'access_token' => $longLivedToken,
                    'fields'       => 'id,name,client_pages{id,name,access_token,category,picture{url}},owned_pages{id,name,access_token,category,picture{url}}',
                    'limit'        => 50,
                ]);
                if ($bizRes->successful()) {
                    foreach ($bizRes->json('data') ?? [] as $biz) {
                        foreach ($biz['owned_pages']['data'] ?? [] as $p) {
                            $addPage($p);
                        }
                        foreach ($biz['client_pages']['data'] ?? [] as $p) {
                            $addPage($p);
                        }
                    }
                }
            }

            // Tier 6: Short-lived token fallback (in case token exchange altered scopes)
            if (empty($pages) && $shortLivedToken !== $longLivedToken) {
                $shortAccountsRes = $this->client()->get("https://graph.facebook.com/{$version}/me/accounts", [
                    'access_token' => $shortLivedToken,
                    'fields'       => 'id,name,access_token,category',
                    'limit'        => 100,
                ]);
                if ($shortAccountsRes->successful()) {
                    foreach ($shortAccountsRes->json('data') ?? [] as $p) {
                        $addPage($p);
                    }
                }
            }

            Log::info('[FACEBOOK OAUTH] Page resolution finished', [
                'user_id'      => $userData['id'] ?? 'unknown',
                'user_name'    => $userData['name'] ?? 'unknown',
                'pages_count'  => count($pages),
                'pages_found'  => array_map(fn($p) => ['id' => $p['id'] ?? '', 'name' => $p['name'] ?? ''], $pages),
                'workspace_id' => $workspaceId,
            ]);

            if (empty($pages)) {
                $noPagesMsg = "Authentication was successful for {$userData['name']}, but no managed Facebook Pages could be retrieved. Please ensure your Facebook account has Administrator or Editor access to the Page, and that you approved Page permissions during login.";
                return $this->renderOAuthResponse(false, 'No Facebook Pages Found', $noPagesMsg);
            }

            // Discovery stays in the OAuth session until a Page is selected.

            // 7. Secure server-side session cache for page access tokens (Never expose raw tokens to client JS)
            $oauthSessionId = 'fb_auth_' . bin2hex(random_bytes(16));
            Cache::put($oauthSessionId, [
                'workspace_id'    => $workspaceId,
                'user_token'      => $longLivedToken,
                'user_data'       => $userData,
                'pages'           => $pages,
                'granted_scopes'  => $grantedScopes,
                'granular_scopes' => $granularScopes,
                'missing_scopes'  => $missingScopes,
            ], now()->addMinutes(15));

            // Sanitize pages for frontend payload: keep metadata, keep access_token accessible via session ticket
            $sanitizedPages = array_map(function ($page) use ($hasPublishingScope, $missingScopes) {
                return [
                    'id'                         => $page['id'],
                    'name'                       => $page['name'],
                    'category'                   => $page['category'] ?? 'General',
                    'picture'                    => $page['picture'] ?? null,
                    'instagram_business_account' => $page['instagram_business_account'] ?? null,
                    'access_token'               => null,
                    'can_publish'                => $page['can_publish'] ?? $hasPublishingScope,
                    'tasks'                      => $page['tasks'] ?? [],
                    'missing_permissions'        => $missingScopes,
                ];
            }, $pages);

            // Auto-update credentials in DB if reconnecting for an existing workspace page or exactly 1 page was found
            $targetPageToAutoConnect = null;
            if (count($pages) === 1) {
                $targetPageToAutoConnect = $pages[0];
            } else {
                $existingPage = FacebookPage::where('workspace_id', $workspaceId)->where('token_status', 'valid')->first();
                if ($existingPage) {
                    foreach ($pages as $p) {
                        if ($p['id'] === $existingPage->page_id) {
                            $targetPageToAutoConnect = $p;
                            break;
                        }
                    }
                }
            }

            if ($targetPageToAutoConnect && !empty($targetPageToAutoConnect['access_token'])) {
                try {
                    $pId = $targetPageToAutoConnect['id'];
                    $pName = $targetPageToAutoConnect['name'];
                    $pTok = $targetPageToAutoConnect['access_token'];
                    $igId = $targetPageToAutoConnect['instagram_business_account']['id'] ?? null;
                    $igUsername = $targetPageToAutoConnect['instagram_business_account']['username'] ?? null;

                    $targetTasks = (array) ($targetPageToAutoConnect['tasks'] ?? []);
                    $hasContentTask = empty($targetTasks) || in_array('CREATE_CONTENT', $targetTasks, true) || in_array('MANAGE', $targetTasks, true);

                    $errorMessage = null;
                    if ($isPublishingEnabled && !$hasPublishingScope) {
                        $errorMessage = "Live Facebook publishing is unavailable: Meta permission 'pages_manage_posts' was not granted. Reconnect with publishing permissions, or submit 'pages_manage_posts' for Meta App Review (Advanced Access).";
                        Log::warning('[FACEBOOK OAUTH] Auto-connected Page lacks pages_manage_posts permission', [
                            'workspace_id' => $workspaceId,
                            'page_id'      => $pId,
                            'missing'      => $missingScopes,
                        ]);
                    } elseif ($isPublishingEnabled && !$hasContentTask) {
                        $errorMessage = "Live Facebook publishing is unavailable: Connected user lacks the 'CREATE_CONTENT' task on Page '{$pName}'. Must be a Page Admin or Editor.";
                        Log::warning('[FACEBOOK OAUTH] Auto-connected Page lacks CREATE_CONTENT task', [
                            'workspace_id' => $workspaceId,
                            'page_id'      => $pId,
                            'tasks'        => $targetTasks,
                        ]);
                    }

                    // Remember which Facebook user and Meta app granted this connection (used for data deletion requests).
                    $fbOwner = $this->facebookOwner($workspaceId, $userData['id'] ?? null);

                    FacebookPage::updateOrCreate(
                        [
                            'workspace_id' => $workspaceId,
                            'page_id'      => $pId,
                        ],
                        [
                            'page_name'         => $pName,
                            'page_access_token' => $pTok,
                            'user_access_token' => $longLivedToken,
                            'token_status'      => 'valid',
                            'connected_since'   => now(),
                        ] + $fbOwner
                    );

                    Integration::updateOrCreate(
                        [
                            'workspace_id' => $workspaceId,
                            'platform'     => 'facebook',
                            'account_id'   => $pId,
                        ],
                        [
                            'account_name'      => $pName,
                            'refresh_token'     => $pTok,
                            'is_connected'      => true,
                            'connection_status' => 'connected',
                            'error_message'     => $errorMessage,
                            'last_sync_at'      => now(),
                        ] + $fbOwner
                    );

                    if ($igId) {
                        Integration::updateOrCreate(
                            [
                                'workspace_id' => $workspaceId,
                                'platform'     => 'instagram',
                                'account_id'   => $igId,
                            ],
                            [
                                'account_name'      => $igUsername ? "@{$igUsername}" : $pName,
                                'refresh_token'     => $pTok,
                                'is_connected'      => true,
                                'connection_status' => 'connected',
                                'last_sync_at'      => now(),
                            ] + $fbOwner
                        );
                    }

                    // Invalidate inbox cache so fresh data is loaded
                    Cache::forget("fb_inbox_conv_{$workspaceId}");
                    Cache::forget("ig_inbox_conv_{$workspaceId}");
                    Cache::increment("fb_inbox_conv_v_{$workspaceId}");
                    Cache::increment("ig_inbox_conv_v_{$workspaceId}");

                    Log::info('[FACEBOOK OAUTH] Auto-updated Facebook and Instagram credentials in DB', [
                        'workspace_id' => $workspaceId,
                        'page_id'      => $pId,
                        'ig_id'        => $igId,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('[FACEBOOK OAUTH] Could not auto-update page in DB: ' . $e->getMessage());
                }
            }

            return $this->renderSuccessResponse($sanitizedPages, $oauthSessionId, $userData, $workspaceId, $missingScopes);

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
            'workspace_id'         => 'required|integer|exists:workspaces,id',
            'page_id'              => 'required|string',
            'page_name'            => 'required|string',
            'page_access_token'    => 'nullable|string',
            'oauth_session_id'     => 'nullable|string',
            'instagram_account_id' => 'nullable|string',
            'instagram_username'   => 'nullable|string',
        ]);

        $workspaceId = (int)$validated['workspace_id'];
        $pageId      = $validated['page_id'];
        $pageName    = $validated['page_name'];
        $token       = $validated['page_access_token'] ?? null;

        // If page_access_token was not sent from client, retrieve it from the secure server-side cache
        if (empty($token) && !empty($validated['oauth_session_id'])) {
            $cachedSession = Cache::get($validated['oauth_session_id']);
            if (!$cachedSession || (int)$cachedSession['workspace_id'] !== $workspaceId) {
                return response()->json(['success' => false, 'message' => 'The Facebook login session belongs to another workspace or has expired. Reconnect in the selected workspace.'], 422);
            }
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

        // Verify publishing permissions and tasks for the selected Page
        $isPublishingEnabled = filter_var(config('services.facebook.publishing_enabled', true), FILTER_VALIDATE_BOOLEAN);
        $cachedSession = !empty($validated['oauth_session_id']) ? Cache::get($validated['oauth_session_id']) : null;
        $grantedScopes = is_array($cachedSession) ? ($cachedSession['granted_scopes'] ?? []) : [];
        $selectedPageData = null;
        if (is_array($cachedSession) && !empty($cachedSession['pages'])) {
            foreach ($cachedSession['pages'] as $p) {
                if ($p['id'] === $pageId) {
                    $selectedPageData = $p;
                    break;
                }
            }
        }
        $pageTasks = (array) ($selectedPageData['tasks'] ?? []);
        $hasPublishScope = in_array('pages_manage_posts', $grantedScopes, true);
        $hasContentTask = empty($pageTasks) || in_array('CREATE_CONTENT', $pageTasks, true) || in_array('MANAGE', $pageTasks, true);

        // Fallback scope check if token was provided manually without session
        if (empty($grantedScopes) && $isPublishingEnabled) {
            try {
                $appId = $this->getAppId($workspaceId);
                $secret = $this->getAppSecret($workspaceId);
                if ($appId && $secret) {
                    $debugRes = $this->client()->get("https://graph.facebook.com/{$this->getGraphVersion()}/debug_token", [
                        'input_token'  => $token,
                        'access_token' => "{$appId}|{$secret}",
                    ]);
                    if ($debugRes->successful()) {
                        $scopes = $debugRes->json('data.scopes') ?? [];
                        $hasPublishScope = in_array('pages_manage_posts', $scopes, true);
                    }
                }
            } catch (\Throwable $e) {}
        }

        $isPublishingReady = !$isPublishingEnabled || ($hasPublishScope && $hasContentTask);
        $warningMessage = null;
        if ($isPublishingEnabled && !$isPublishingReady) {
            if (!$hasPublishScope) {
                $warningMessage = "Live Facebook publishing is unavailable: Meta permission 'pages_manage_posts' was not granted. Reconnect with publishing permissions, or submit 'pages_manage_posts' for Meta App Review (Advanced Access).";
            } elseif (!$hasContentTask) {
                $warningMessage = "Live Facebook publishing is unavailable: Connected user lacks the 'CREATE_CONTENT' task on Page '{$pageName}'. Must be a Page Admin or Editor.";
            }
        }

        // Which Facebook user granted this connection (from the server-side OAuth session), for data deletion requests.
        $sessionUserId = null;
        if (!empty($validated['oauth_session_id'])) {
            $ownerSession = Cache::get($validated['oauth_session_id']);
            if (is_array($ownerSession) && (int) ($ownerSession['workspace_id'] ?? 0) === $workspaceId) {
                $sessionUserId = $ownerSession['user_data']['id'] ?? null;
            }
        }
        $fbOwner = $this->facebookOwner($workspaceId, $sessionUserId);

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
        // Keep history, but only the chosen account remains active.
        FacebookPage::where('workspace_id', $workspaceId)->where('page_id', '!=', $pageId)
            ->update(['token_status' => 'disconnected']);
        Integration::where('workspace_id', $workspaceId)->where('platform', 'facebook')
            ->where('account_id', '!=', $pageId)
            ->update(['is_connected' => false, 'connection_status' => 'disconnected']);
        if (!empty($validated['instagram_account_id'])) {
            Integration::where('workspace_id', $workspaceId)->where('platform', 'instagram')
                ->where('account_id', '!=', $validated['instagram_account_id'])
                ->update(['is_connected' => false, 'connection_status' => 'disconnected']);
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
            ] + $fbOwner
        );

        // Update unified Integration table for Facebook without overwriting other pages
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
                'error_message'     => $warningMessage,
                'last_sync_at'      => now(),
            ] + $fbOwner
        );

        // If linked Instagram account ID provided, update Instagram integration record without overwriting
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
                    'account_id'   => $validated['instagram_account_id'],
                ],
                [
                    'account_name'      => $igAccountName ? (str_starts_with($igAccountName, '@') ? $igAccountName : "@{$igAccountName}") : $pageName,
                    'refresh_token'     => $token,
                    'is_connected'      => true,
                    'connection_status' => 'connected',
                    'last_sync_at'      => now(),
                ] + $fbOwner
            );
        }

        \Illuminate\Support\Facades\DB::commit();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            throw $e;
        }

        return response()->json([
            'success'             => true,
            'message'             => $warningMessage ? "Facebook Page '{$pageName}' connected with limitations: {$warningMessage}" : "Facebook Page '{$pageName}' connected successfully!",
            'warning'             => $warningMessage,
            'publishing_ready'    => $isPublishingReady,
            'missing_permissions' => $hasPublishScope ? [] : ['pages_manage_posts'],
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
        $workspaceId = $request->query('workspace_id');
        if (empty($workspaceId) || $workspaceId === 'all') {
            $workspaceId = \App\Models\Workspace::first()?->id;
        }
        if (!$workspaceId) {
            return response()->json([
                'success' => true,
                'data'    => [],
            ]);
        }
        $workspaceId = (int) $workspaceId;
        $pages       = FacebookPage::where('workspace_id', $workspaceId)->get();

        if ($pages->isEmpty()) {
            $fallback = $this->resolvePage($workspaceId);
            if ($fallback) {
                $pages = collect([$fallback]);
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $pages->map(function ($p) use ($workspaceId) {
                return [
                    'id'                  => $p->id,
                    'workspace_id'        => (int)$workspaceId,
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
        $workspaceId = $request->input('workspace_id');
        if (empty($workspaceId) || !is_numeric($workspaceId)) {
            return response()->json([
                'success' => false,
                'message' => 'A valid workspace_id is required to disconnect Facebook.',
            ], 422);
        }

        $workspaceId = (int) $workspaceId;
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
        $workspaceId = $request->query('workspace_id');
        if (empty($workspaceId) || $workspaceId === 'all') {
            $workspaceId = \App\Models\Workspace::first()?->id;
        }
        if (!$workspaceId) {
            return response()->json([
                'success' => false,
                'message' => 'No valid workspace found.',
            ], 404);
        }
        $workspaceId = (int) $workspaceId;
        $page        = $this->resolvePage($workspaceId);

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'No Facebook Page connected for this workspace.',
            ], 404);
        }

        try {
            $details = $this->graphService->getPageDetails($page->page_id, $page->page_access_token);

            $publishingReady = true;
            $missingPermissions = [];
            $warning = null;

            if (filter_var(config('services.facebook.publishing_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
                $appId = $this->getAppId($workspaceId);
                $secret = $this->getAppSecret($workspaceId);
                if ($appId && $secret) {
                    try {
                        $debugRes = $this->client()->get("https://graph.facebook.com/{$this->getGraphVersion()}/debug_token", [
                            'input_token'  => $page->page_access_token,
                            'access_token' => "{$appId}|{$secret}",
                        ]);
                        if ($debugRes->successful()) {
                            $scopes = $debugRes->json('data.scopes') ?? [];
                            if (!in_array('pages_manage_posts', $scopes, true)) {
                                $publishingReady = false;
                                $missingPermissions[] = 'pages_manage_posts';
                                $warning = "Connected for read and insights, but live publishing is unavailable: 'pages_manage_posts' permission is not granted. Meta App Review (Advanced Access) or reconnection by an App Admin is required.";
                            }
                        }
                    } catch (\Throwable $e) {}
                }
            }

            return response()->json([
                'success'             => true,
                'message'             => $warning ? "Facebook connection active with limitations: {$warning}" : "Facebook connection active for '{$details['page_name']}'!",
                'publishing_ready'    => $publishingReady,
                'missing_permissions' => $missingPermissions,
                'warning'             => $warning,
                'data'                => $details,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Facebook token verification failed: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Attributes that record which Facebook user and Meta app granted a connection. Empty when the Facebook user
     * is unknown (manual token connects), which keeps such rows out of automatic data-deletion matching.
     *
     * @return array{facebook_user_id: string, facebook_app_id: string}|array{}
     */
    protected function facebookOwner(int $workspaceId, $facebookUserId): array
    {
        if (empty($facebookUserId)) {
            return [];
        }

        try {
            return ['facebook_user_id' => (string) $facebookUserId, 'facebook_app_id' => $this->getAppId($workspaceId)];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Strip credentials from text before it is logged or shown to a user.
     */
    protected function redactSecrets(string $text): string
    {
        $text = preg_replace('/(access_token|client_secret|fb_exchange_token|input_token|code)=[^&\s"\']+/i', '$1=[redacted]', $text);
        $text = preg_replace('/\b\d{10,}\|[A-Za-z0-9_\-]{20,}\b/', '[redacted-app-token]', $text);

        return preg_replace('/\bEAA[A-Za-z0-9]{20,}\b/', '[redacted-token]', $text);
    }

    /**
     * Look the Meta App up with its own app token. The token travels in the Authorization header so it
     * never appears in a URL or log line.
     *
     * @return array{reachable: bool, status: int|null, data: array, error: array|null}
     */
    protected function lookupApp(string $appId, string $appSecret): array
    {
        try {
            $res = Http::timeout(10)->connectTimeout(5)->acceptJson()
                ->withToken($appId . '|' . $appSecret)
                ->get('https://graph.facebook.com/' . $this->getGraphVersion() . '/' . $appId, [
                    'fields' => 'name,category,link,privacy_policy_url,terms_of_service_url,app_domains,restrictions',
                ]);
        } catch (\Throwable $e) {
            Log::warning('[FACEBOOK OAUTH] Meta app lookup unreachable: ' . $this->redactSecrets($e->getMessage()));

            return ['reachable' => false, 'status' => null, 'data' => [], 'error' => null];
        }

        if ($res->successful()) {
            return ['reachable' => true, 'status' => $res->status(), 'data' => (array) $res->json(), 'error' => null];
        }

        $error = (array) ($res->json('error') ?? []);

        return [
            'reachable' => true,
            'status'    => $res->status(),
            'data'      => [],
            'error'     => [
                'code'    => $error['code'] ?? null,
                'subcode' => $error['error_subcode'] ?? null,
                'type'    => $error['type'] ?? null,
                'message' => $this->redactSecrets((string) ($error['message'] ?? 'Meta returned an error.')),
            ],
        ];
    }

    /**
     * Returns Meta's error when it explicitly rejects the app credentials (invalid App ID / signature);
     * null when the app looks usable or Meta could not be reached (a network issue never blocks login).
     */
    protected function preflightApp(int $workspaceId, string $appId): ?array
    {
        if (!config('services.facebook.preflight', true)) {
            return null;
        }

        try {
            $secret = $this->getAppSecret($workspaceId);
        } catch (\Throwable $e) {
            return ['code' => null, 'type' => 'ConfigurationError', 'message' => $e->getMessage()];
        }

        $lookup = $this->lookupApp($appId, $secret);
        if (!$lookup['reachable'] || $lookup['error'] === null) {
            return null;
        }

        return in_array((int) ($lookup['error']['code'] ?? 0), [101, 190], true) ? $lookup['error'] : null;
    }

    /**
     * GET /api/v1/auth/facebook/diagnose (administrators only)
     * Reports how Facebook Login is configured and what Meta says about the app. Never returns secrets.
     */
    public function diagnose(Request $request)
    {
        if (($request->user()?->role ?? null) !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Only administrators can run Facebook diagnostics.'], 403);
        }

        $workspaceId = $request->query('workspace_id');
        if (empty($workspaceId) || $workspaceId === 'all') {
            $workspaceId = \App\Models\Workspace::first()?->id;
        }
        $workspaceId = $workspaceId ? (int) $workspaceId : null;

        try {
            $appId  = $this->getAppId($workspaceId);
            $secret = $this->getAppSecret($workspaceId);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'configuration_error', 'message' => $e->getMessage()], 422);
        }

        $redirectUri = $this->getRedirectUri($workspaceId);
        $scopes      = array_values(array_filter(array_map('trim', explode(',', $this->getScopes()))));
        $lookup      = ($secret !== '' && $appId !== '')
            ? $this->lookupApp($appId, $secret)
            : ['reachable' => false, 'data' => [], 'error' => null];
        $app         = $lookup['data'];
        $secretOk    = (bool) preg_match('/^[a-f0-9]{32}$/i', $secret);
        $redirectHost = (string) parse_url($redirectUri, PHP_URL_HOST);

        $warnings = [];
        $warn = function (string $level, string $source, string $code, string $message) use (&$warnings) {
            $warnings[] = ['level' => $level, 'source' => $source, 'code' => $code, 'message' => $message];
        };

        if ($appId === '' || $secret === '') {
            $warn('error', 'app-config', 'credentials_missing', 'FACEBOOK_APP_ID or FACEBOOK_APP_SECRET is not set in the backend .env.');
        } elseif (!$secretOk) {
            $warn('error', 'app-config', 'secret_format', 'The configured App Secret is not 32 hexadecimal characters, so it cannot be a valid Meta App Secret. Re-copy it from Meta App Dashboard > App settings > Basic.');
        }

        if ($appId !== '' && $secret !== '') {
            if (!$lookup['reachable']) {
                $warn('warning', 'network', 'meta_unreachable', 'Meta Graph API could not be reached from this server, so the app settings could not be checked.');
            } elseif ($lookup['error'] !== null) {
                $configError = in_array((int) $lookup['error']['code'], [101, 190], true);
                $warn('error', $configError ? 'app-config' : 'meta', 'meta_rejects_app', 'Meta rejected the app lookup: [' . ($lookup['error']['code'] ?? '?') . '] ' . $lookup['error']['message']);
            } else {
                $privacyPath = trim((string) parse_url((string) ($app['privacy_policy_url'] ?? ''), PHP_URL_PATH), '/');
                if (empty($app['privacy_policy_url'])) {
                    $warn('error', 'meta', 'privacy_policy_missing', 'No Privacy Policy URL is set on the Meta App. Meta can block Facebook Login for Live apps without one.');
                } elseif ($privacyPath === '') {
                    $warn('warning', 'meta', 'privacy_policy_is_homepage', 'The Meta App Privacy Policy URL (' . $app['privacy_policy_url'] . ') is only a website homepage. Meta expects a dedicated privacy policy page; a homepage URL commonly accompanies "we are updating additional details for this app" restrictions.');
                }
                if (empty($app['app_domains'])) {
                    $warn('warning', 'meta', 'app_domains_empty', 'The Meta App has no App Domains. Add the domains of the website and the API (for example the host of the redirect URI).');
                } elseif ($redirectHost !== '') {
                    $covered = collect($app['app_domains'])->contains(fn ($d) => $redirectHost === $d || str_ends_with($redirectHost, '.' . ltrim($d, '.')));
                    if (!$covered) {
                        $warn('warning', 'meta', 'redirect_host_not_in_app_domains', "The redirect URI host '{$redirectHost}' is not covered by the Meta App Domains.");
                    }
                }
            }
        }

        if (!str_starts_with($redirectUri, 'https://')) {
            $warn('warning', 'app-config', 'redirect_not_https', 'The redirect URI is not HTTPS. Live Meta apps require HTTPS redirect URIs.');
        }
        if ($redirectHost !== '' && strcasecmp($redirectHost, $request->getHost()) !== 0) {
            $warn('info', 'app-config', 'redirect_points_to_other_server', "The redirect URI host is '{$redirectHost}' but this API is running on '" . $request->getHost() . "'. Meta returns the user to the redirect URI, so the login can only complete on that server (the signed state and nonce are stored in this server's cache).");
        }
        if (filter_var(config('services.facebook.publishing_enabled'), FILTER_VALIDATE_BOOLEAN) && !in_array('pages_manage_posts', $scopes, true)) {
            $warn('warning', 'app-config', 'publish_scope_not_requested', 'Publishing is enabled but the pages_manage_posts permission is not in the requested OAuth scopes (FACEBOOK_OAUTH_SCOPES), so publishing to Pages may be refused by Meta. Adding it also requires Advanced Access when the app is Live.');
        }

        $needsReview = array_values(array_intersect($scopes, [
            'pages_manage_posts', 'pages_messaging', 'pages_read_user_content', 'pages_read_engagement', 'pages_manage_metadata', 'read_insights',
            'instagram_manage_messages', 'instagram_manage_comments', 'instagram_manage_insights', 'instagram_content_publish',
        ]));
        if ($needsReview) {
            $warn('info', 'meta', 'advanced_access_permissions', 'These requested permissions need Advanced Access (App Review) for people who have no role on the app once the app is Live: ' . implode(', ', $needsReview) . '.');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'workspace_id'  => $workspaceId,
                'graph_version' => $this->getGraphVersion(),
                'redirect_uri'  => $redirectUri,
                'scopes'        => $scopes,
                'credentials'   => [
                    'app_id'                   => $appId !== '' ? substr($appId, 0, 4) . '...' . substr($appId, -3) : null,
                    'secret_present'           => $secret !== '',
                    'secret_format_ok'         => $secretOk,
                    'meta_accepts_credentials' => $lookup['reachable'] ? $lookup['error'] === null : null,
                    'meta_error'               => $lookup['error'],
                ],
                'meta_app' => ($lookup['reachable'] && $lookup['error'] === null && $appId !== '' && $secret !== '') ? [
                    'name'                 => $app['name'] ?? null,
                    'category'             => $app['category'] ?? null,
                    'privacy_policy_url'   => $app['privacy_policy_url'] ?? null,
                    'terms_of_service_url' => $app['terms_of_service_url'] ?? null,
                    'app_domains'          => $app['app_domains'] ?? [],
                ] : null,
                'warnings' => $warnings,
                'cannot_be_checked_from_code' => [
                    'App Mode (Development / Live)',
                    'Dashboard alerts, Data Use Checkup and Business Verification status',
                    'Facebook Login > Settings > Valid OAuth Redirect URIs (check that the redirect URI above is listed)',
                    'Whether Meta has temporarily restricted the app. The message "Facebook Login is currently unavailable for this app, since we are updating additional details" is produced by facebook.com and is resolved only in the Meta App Dashboard.',
                ],
            ],
        ]);
    }

    /**
     * Translate Meta OAuth error codes into clear, actionable instructions.
     */
    protected function translateOAuthError($code, $description, $reason, string $appId, string $redirectUri): string
    {
        $explained = $this->explainOAuthError($code, $description, $reason, $appId, $redirectUri);

        // Always show what Meta actually reported, not only our interpretation of it.
        $raw = trim($this->redactSecrets((string) $description) . ($reason ? " (reason: {$reason})" : ''));
        if ($raw !== '' && !str_contains($explained, $raw)) {
            $explained .= ' - Meta reported: ' . ($code !== '' && $code !== null ? "[{$code}] " : '') . $raw;
        }

        return $explained;
    }

    protected function explainOAuthError($code, $description, $reason, string $appId, string $redirectUri): string
    {
        $descLower = strtolower($description . ' ' . $reason);

        if (
            str_contains($descLower, 'currently unavailable for this app') ||
            str_contains($descLower, 'updating additional details') ||
            str_contains($descLower, 'login is currently unavailable')
        ) {
            return "Meta has temporarily disabled Facebook Login for this Meta App (App ID: {$appId}) while its details are being updated. "
                . 'This restriction is applied by Meta on the app itself and cannot be fixed by changing this application. '
                . 'In the Meta App Dashboard (developers.facebook.com) check: App settings > Basic (Privacy Policy URL pointing to a real privacy page, Terms, App Icon, Category, App Domains, Data Deletion URL), '
                . 'App Mode = Live, Dashboard alerts and Data Use Checkup, Business Verification, and that Facebook Login > Valid OAuth Redirect URIs contains the callback URL. '
                . 'Then wait a few minutes and retry. An administrator can run GET /api/v1/auth/facebook/diagnose for a configuration report.';
        }

        if ((string) $code === '190' || str_contains($descLower, 'invalid oauth access token signature') || str_contains($descLower, 'error validating client secret')) {
            return "Meta rejected the App ID / App Secret pair (App ID: {$appId}). The App Secret in the backend .env does not belong to this App ID. Copy the current secret from Meta App Dashboard > App settings > Basic (32 hexadecimal characters) into the matching FACEBOOK_APP_SECRET / FACEBOOK_TESTING_APP_SECRET value, with no quotes or spaces.";
        }

        if (str_contains($descLower, 'invalid scopes') || str_contains($descLower, 'invalid permission')) {
            return 'Meta rejected one or more requested permissions. Permissions beyond public_profile need Advanced Access (App Review) when the app is Live, or the logging-in user must hold a role on the app while it is in Development mode.';
        }

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
            ? 'Meta OAuth Error: ' . $this->redactSecrets((string) $description)
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
     * Render styled HTML response for popup window when connection already exists.
     */
    protected function renderAlreadyConnectedResponse(string $title, string $message, array $extra = [], $workspaceId = null)
    {
        $workspaceId  = $workspaceId ?: \App\Models\Workspace::first()?->id;
        $escapedTitle = htmlspecialchars($title);
        $escapedMsg   = htmlspecialchars($message);
        $jsonPayload  = json_encode(array_merge(['type' => 'FACEBOOK_ALREADY_CONNECTED', 'message' => $message], $extra));
        $reconnectUrl = "/api/v1/auth/facebook?workspace_id={$workspaceId}&force=true";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$escapedTitle}</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0fdf4; color: #1f2937; padding: 32px; text-align: center; margin: 0;">
    <div style="max-width: 500px; margin: 40px auto; background: #ffffff; border-radius: 16px; padding: 32px 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); border: 1px solid #dcfce7;">
        <div style="width: 56px; height: 56px; background: #dcfce7; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 28px;">
            &#x2705;
        </div>
        <h2 style="color: #16a34a; margin: 0 0 12px; font-size: 20px; font-weight: 700;">{$escapedTitle}</h2>
        <p style="color: #4b5563; font-size: 14px; line-height: 1.6; margin: 0 0 20px; text-align: left; background: #f9fafb; padding: 14px; border-radius: 8px; border-left: 4px solid #16a34a;">
            {$escapedMsg}
        </p>
        <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px; flex-wrap: wrap;">
            <button onclick="window.close();" style="background: #16a34a; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer;">
                Keep Current Connection
            </button>
            <a href="{$reconnectUrl}" style="background: #2563eb; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; display: inline-block;">
                Connect A Different Page &rarr;
            </a>
        </div>
    </div>

    <script>
        if (window.opener) {
            try {
                window.opener.postMessage({$jsonPayload}, "*");
            } catch (e) {
                console.error("Failed to postMessage to opener:", e);
            }
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
    protected function renderSuccessResponse(array $pages, string $sessionId, array $user, int $workspaceId, array $missingScopes = [])
    {
        $escapedName = htmlspecialchars($user['name'] ?? 'Facebook User');
        $pageCount   = count($pages);
        $pagesJson   = json_encode($pages);
        $userJson    = json_encode($user);
        $missingJson = json_encode($missingScopes);
        $publishingReady = !in_array('pages_manage_posts', $missingScopes, true);
        $publishingReadyJson = json_encode($publishingReady);

        $warningBannerHtml = '';
        if (!$publishingReady) {
            $warningBannerHtml = <<<HTML
        <div style="background: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; border-radius: 8px; margin: 16px 0; text-align: left; font-size: 13px; color: #92400e;">
            <strong>⚠️ Limited Publishing Permissions:</strong><br>
            Meta did not grant the <code>pages_manage_posts</code> permission. The Page can receive messages and sync analytics, but publishing will fail until <code>pages_manage_posts</code> is approved via Meta App Review (for Live apps) or granted by an App Admin (in Development mode).
        </div>
HTML;
        }

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
        {$warningBannerHtml}
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
                    workspaceId: {$workspaceId},
                    missing_permissions: {$missingJson},
                    publishing_ready: {$publishingReadyJson}
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
