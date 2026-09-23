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
        return (string) (config('services.facebook.client_id') ?? env('FACEBOOK_APP_ID', ''));
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
        $raw = config('services.facebook.scopes') ?? env('FACEBOOK_OAUTH_SCOPES', 'public_profile,pages_show_list,pages_read_engagement,pages_read_user_content,pages_manage_metadata,pages_messaging,instagram_basic,instagram_content_publish,instagram_manage_insights,instagram_manage_comments,instagram_manage_messages,read_insights');
        $scopeList = array_filter(array_map('trim', explode(',', $raw)));
        if (!in_array('instagram_manage_messages', $scopeList)) {
            $scopeList[] = 'instagram_manage_messages';
        }
        // Ensure pages_read_user_content is always requested — required for /{postId}/comments
        if (!in_array('pages_read_user_content', $scopeList)) {
            $scopeList[] = 'pages_read_user_content';
        }
        // Ensure read_insights is always requested — required for post views, impressions & insights
        if (!in_array('read_insights', $scopeList)) {
            $scopeList[] = 'read_insights';
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
        $workspaceId = $request->query('workspace_id', '1');
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

        $appId       = $this->getAppId();
        $redirectUri = $this->getRedirectUri();
        $scopes      = $request->query('scope') ?: $this->getScopes();
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
            'forced'       => $force,
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

            // 5. Inspect Token Scopes & Granular Permissions (For Meta Business Login diagnostic)
            $debugRes = $this->client()->get("https://graph.facebook.com/{$version}/debug_token", [
                'input_token'  => $longLivedToken,
                'access_token' => "{$appId}|{$appSecret}",
            ]);
            $debugData      = $debugRes->json('data') ?? [];
            $grantedScopes  = $debugData['scopes'] ?? [];
            $granularScopes = $debugData['granular_scopes'] ?? [];

            Log::info('[FACEBOOK OAUTH DEBUG]', [
                'user_id'         => $userData['id'] ?? null,
                'user_name'       => $userData['name'] ?? null,
                'is_valid_token'  => $debugData['is_valid'] ?? false,
                'granted_scopes'  => $grantedScopes,
                'granular_scopes' => array_map(function ($g) {
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
            $addPage = function ($p) use (&$pages, &$seenPageIds, $version, $longLivedToken) {
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
                        ]
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
                            'last_sync_at'      => now(),
                        ]
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
                            ]
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
            ]
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
                'last_sync_at'      => now(),
            ]
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
                ]
            );
        }

        \Illuminate\Support\Facades\DB::commit();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            throw $e;
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
        $page        = $this->resolvePage($workspaceId);

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
     * Render styled HTML response for popup window when connection already exists.
     */
    protected function renderAlreadyConnectedResponse(string $title, string $message, array $extra = [], $workspaceId = 1)
    {
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
