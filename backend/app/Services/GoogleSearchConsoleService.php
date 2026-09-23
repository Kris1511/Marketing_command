<?php

namespace App\Services;

use Google\Client as GoogleClient;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Exception;

class GoogleSearchConsoleService
{
    protected ?GoogleClient $client = null;
    protected string $credentialsPath;
    protected ?string $defaultSiteUrl;

    public function __construct()
    {
        $this->credentialsPath = base_path(env('GOOGLE_SEARCH_CONSOLE_CREDENTIALS_PATH', env('GOOGLE_ANALYTICS_CREDENTIALS_PATH', 'ga-credentials.json')));
        $this->defaultSiteUrl = env('GOOGLE_SEARCH_CONSOLE_SITE_URL', null);
    }

    /**
     * Check if Search Console integration is configured for a workspace.
     */
    public function isConfigured(?int $workspaceId = null): bool
    {
        $hasOAuth = $workspaceId ? Cache::has("gsc_token_{$workspaceId}") : false;

        if ($workspaceId) {
            try {
                $integ = Integration::where('workspace_id', $workspaceId)
                    ->where('platform', 'search_console')
                    ->where('is_connected', true)
                    ->first();

                if ($integ) {
                    $hasToken = !empty($integ->refresh_token) || $hasOAuth || file_exists($this->credentialsPath);
                    $siteUrl  = (!empty($integ->account_id) && $integ->account_id !== 'pending') ? $integ->account_id : $this->defaultSiteUrl;

                    if ($hasToken && !empty($siteUrl)) {
                        if ($integ->account_id === 'pending') {
                            $integ->update([
                                'account_id'   => $siteUrl,
                                'account_name' => $siteUrl,
                            ]);
                        }
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore DB errors in test environment
            }
        }

        $hasFile = file_exists($this->credentialsPath);
        $siteUrl = $this->getSiteUrl($workspaceId);

        return ($hasOAuth || $hasFile) && !empty($siteUrl);
    }

    /**
     * Get Site URL for a specific workspace or fallback to default.
     */
    public function getSiteUrl(?int $workspaceId = null): ?string
    {
        if ($workspaceId) {
            try {
                $integ = Integration::where('workspace_id', $workspaceId)
                    ->where('platform', 'search_console')
                    ->where('is_connected', true)
                    ->first();

                if ($integ && !empty($integ->account_id) && $integ->account_id !== 'pending') {
                    return $integ->account_id;
                }
            } catch (\Throwable $e) {
                // Ignore DB errors in unit test environment
            }
        }

        return $this->defaultSiteUrl;
    }

    /**
     * Generate Google OAuth 2.0 authorization URL for connecting Search Console.
     */
    public function createAuthUrl(int $workspaceId): string
    {
        $clientId     = config('services.google_search_console.client_id');
        $clientSecret = config('services.google_search_console.client_secret');
        $redirectUri  = config('services.google_search_console.redirect_uri');

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception('Google Search Console OAuth Client ID or Client Secret is not configured in backend/.env');
        }

        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->addScope([
            'https://www.googleapis.com/auth/webmasters.readonly',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $state = base64_encode(json_encode([
            'workspace_id' => $workspaceId,
            'ts'           => time(),
        ]));
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * Handle the OAuth callback: exchange code, store tokens, discover sites.
     */
    public function handleOAuthCallback(string $code, int $workspaceId): array
    {
        $clientId     = config('services.google_search_console.client_id');
        $clientSecret = config('services.google_search_console.client_secret');
        $redirectUri  = config('services.google_search_console.redirect_uri');

        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);

        $tokens = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($tokens['error'])) {
            throw new Exception('Google Search Console OAuth Token Error: ' . ($tokens['error_description'] ?? $tokens['error']));
        }

        $accessToken  = $tokens['access_token'] ?? '';
        $refreshToken = $tokens['refresh_token'] ?? null;

        if (empty($accessToken)) {
            throw new Exception('Failed to obtain Google Search Console access token.');
        }

        // Cache active access token for this workspace
        Cache::put("gsc_token_{$workspaceId}", $accessToken, 3500);

        // Fetch user email for display
        $userEmail = null;
        try {
            $userRes = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v2/userinfo');
            if ($userRes->successful()) {
                $userEmail = $userRes->json('email');
            }
        } catch (\Throwable $e) {
            Log::warning('[GSC OAUTH] Failed fetching user info: ' . $e->getMessage());
        }

        // Discover accessible Search Console sites using Search Console API
        $sites = $this->listAvailableSites($accessToken);

        // Fallback to default site if list was empty
        if (empty($sites) && !empty($this->defaultSiteUrl)) {
            $sites[] = [
                'site_url'         => $this->defaultSiteUrl,
                'permission_level' => 'siteOwner',
            ];
        }

        // Find existing integration to preserve active site if already selected
        $existingInteg = Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'search_console')
            ->first();

        $selectedSiteUrl = null;
        if ($existingInteg && !empty($existingInteg->account_id) && $existingInteg->account_id !== 'pending') {
            $selectedSiteUrl = $existingInteg->account_id;
        } elseif (count($sites) === 1) {
            $selectedSiteUrl = $sites[0]['site_url'];
        } elseif (!empty($this->defaultSiteUrl)) {
            $selectedSiteUrl = $this->defaultSiteUrl;
        } elseif (count($sites) > 0) {
            $selectedSiteUrl = $sites[0]['site_url'];
        }

        $accountName = $selectedSiteUrl ?: ($userEmail ? "Search Console ({$userEmail})" : "Google Search Console");

        // Save integration
        Integration::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'search_console',
            ],
            [
                'account_id'        => $selectedSiteUrl ?: 'pending',
                'account_name'      => $accountName,
                'refresh_token'     => $refreshToken ?: ($existingInteg?->refresh_token),
                'is_connected'      => true,
                'connection_status' => 'connected',
                'last_sync_at'      => now(),
            ]
        );

        return [
            'workspace_id'      => $workspaceId,
            'user_email'        => $userEmail,
            'account_name'      => $accountName,
            'selected_site_url' => $selectedSiteUrl,
            'sites'             => $sites,
        ];
    }

    /**
     * List Search Console sites accessible by the provided OAuth access token.
     */
    public function listAvailableSites(string $accessToken): array
    {
        $sites = [];
        try {
            $url = 'https://www.googleapis.com/webmasters/v3/sites';
            $response = Http::withToken($accessToken)->timeout(10)->get($url);

            if ($response->successful()) {
                $entries = $response->json('siteEntry') ?? [];
                foreach ($entries as $site) {
                    $siteUrl = $site['siteUrl'] ?? '';
                    if (!empty($siteUrl)) {
                        $sites[] = [
                            'site_url'         => $siteUrl,
                            'permission_level' => $site['permissionLevel'] ?? 'siteFullUser',
                        ];
                    }
                }
            } else {
                Log::warning('[GSC SITES] sites.list returned status ' . $response->status(), [
                    'body' => $response->body()
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[GSC SITES] listAvailableSites failed: ' . $e->getMessage());
        }

        return $sites;
    }

    /**
     * Fetch a valid access token for Search Console API requests.
     */
    public function getAccessToken(?int $workspaceId = null): string
    {
        // 1. Try OAuth token for workspace
        if ($workspaceId) {
            $cached = Cache::get("gsc_token_{$workspaceId}");
            if (!empty($cached)) {
                return $cached;
            }

            try {
                $integ = Integration::where('workspace_id', $workspaceId)
                    ->where('platform', 'search_console')
                    ->where('is_connected', true)
                    ->first();

                if ($integ && !empty($integ->refresh_token)) {
                    $clientId     = config('services.google_search_console.client_id');
                    $clientSecret = config('services.google_search_console.client_secret');

                    $res = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                        'client_id'     => $clientId,
                        'client_secret' => $clientSecret,
                        'refresh_token' => $integ->refresh_token,
                        'grant_type'    => 'refresh_token',
                    ]);

                    if ($res->successful() && !empty($res->json('access_token'))) {
                        $newToken = $res->json('access_token');
                        $expiresIn = (int)($res->json('expires_in') ?? 3600);
                        Cache::put("gsc_token_{$workspaceId}", $newToken, max(60, $expiresIn - 120));
                        return $newToken;
                    }

                    if ($res->json('error') === 'invalid_grant' || str_contains($res->json('error_description') ?? '', 'expired') || str_contains($res->json('error_description') ?? '', 'revoked')) {
                        $integ->update(['connection_status' => 'expired']);
                        throw new Exception("Google Search Console token has expired or was revoked. Please reconnect Search Console.");
                    }

                    Log::warning('[GSC OAUTH REFRESH] Token refresh failed for workspace ' . $workspaceId, [
                        'response' => $res->body()
                    ]);
                }
            } catch (Exception $e) {
                if (str_contains($e->getMessage(), 'expired') || str_contains($e->getMessage(), 'revoked')) {
                    throw $e;
                }
            } catch (\Throwable $e) {
                // Ignore DB errors in test environment
            }
        }

        // 2. Service account fallback if credentials file exists
        if (file_exists($this->credentialsPath)) {
            $client = new GoogleClient();
            $client->setAuthConfig($this->credentialsPath);
            $client->addScope('https://www.googleapis.com/auth/webmasters.readonly');

            $caPath = 'C:\\PHP\\extras\\ssl\\cacert.pem';
            if (file_exists($caPath)) {
                $client->setHttpClient(new \GuzzleHttp\Client(['verify' => $caPath]));
            }

            $token = $client->fetchAccessTokenWithAssertion();
            if (isset($token['error'])) {
                throw new Exception("Google Search Console Auth Error: " . ($token['error_description'] ?? $token['error']));
            }

            return $token['access_token'];
        }

        throw new Exception("No valid Google Search Console OAuth token or credentials file found for workspace {$workspaceId}.");
    }

    /**
     * Resolve and clamp date range according to Google Search Console 2-3 day reporting lag.
     */
    public function resolveDateRange(?string $startDate = null, ?string $endDate = null): array
    {
        $maxAvailableEnd = now()->subDays(2)->format('Y-m-d');

        if (empty($endDate) || $endDate > $maxAvailableEnd) {
            $resolvedEnd = $maxAvailableEnd;
        } else {
            $resolvedEnd = $endDate;
        }

        if (empty($startDate) || $startDate === 'all') {
            $resolvedStart = \Carbon\Carbon::parse($resolvedEnd)->subDays(28)->format('Y-m-d');
        } elseif ($startDate > $resolvedEnd) {
            $resolvedStart = \Carbon\Carbon::parse($resolvedEnd)->subDays(28)->format('Y-m-d');
        } else {
            $resolvedStart = $startDate;
        }

        return [
            'startDate' => $resolvedStart,
            'endDate'   => $resolvedEnd,
        ];
    }

    /**
     * Query Search Console Search Analytics API.
     */
    public function querySearchAnalytics(string $siteUrl, array $requestBody, ?int $workspaceId = null): array
    {
        $accessToken = $this->getAccessToken($workspaceId);
        $encodedSiteUrl = urlencode($siteUrl);

        $url = "https://searchconsole.googleapis.com/webmasters/v3/sites/{$encodedSiteUrl}/searchAnalytics/query";

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->timeout(15)
            ->post($url, $requestBody);

        if (!$response->successful()) {
            Log::error('Search Console query failed', [
                'status' => $response->status(),
                'body'   => $response->body()
            ]);
            throw new Exception("Search Console API Error: " . ($response->json('error.message') ?? $response->body()));
        }

        return $response->json();
    }

    /**
     * Fetch overview metrics (Total Clicks, Total Impressions, Average CTR, Average Position).
     */
    public function getOverview(?string $startDate = null, ?string $endDate = null, ?int $workspaceId = null): array
    {
        $siteUrl = $this->getSiteUrl($workspaceId);
        if (!$siteUrl || !$this->isConfigured($workspaceId)) {
            return [
                'connected'     => false,
                'auth_required' => true,
                'site_url'      => $siteUrl,
                'clicks'        => 0,
                'impressions'   => 0,
                'ctr'           => 0,
                'position'      => 0,
                'message'       => 'Search Console credentials or Site URL not configured.',
            ];
        }

        $dates = $this->resolveDateRange($startDate, $endDate);

        try {
            $body = [
                'startDate'  => $dates['startDate'],
                'endDate'    => $dates['endDate'],
                'searchType' => 'web',
            ];

            $report = $this->querySearchAnalytics($siteUrl, $body, $workspaceId);
            $rows = $report['rows'] ?? [];

            $totalClicks = 0;
            $totalImpressions = 0;
            $sumCtr = 0;
            $sumPos = 0;
            $count = count($rows);

            foreach ($rows as $row) {
                $totalClicks += (int)($row['clicks'] ?? 0);
                $totalImpressions += (int)($row['impressions'] ?? 0);
                $sumCtr += (float)($row['ctr'] ?? 0);
                $sumPos += (float)($row['position'] ?? 0);
            }

            $avgCtr = $count > 0 ? round(($sumCtr / $count) * 100, 2) : ($totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0);
            $avgPos = $count > 0 ? round($sumPos / $count, 1) : 0;

            return [
                'connected'     => true,
                'auth_required' => false,
                'site_url'      => $siteUrl,
                'clicks'        => $totalClicks,
                'impressions'   => $totalImpressions,
                'ctr'           => $avgCtr,
                'position'      => $avgPos,
                'start_date'    => $dates['startDate'],
                'end_date'      => $dates['endDate'],
            ];
        } catch (\Throwable $e) {
            Log::warning('Search Console getOverview failed', ['error' => $e->getMessage()]);
            $isAuthError = str_contains(strtolower($e->getMessage()), 'token')
                || str_contains(strtolower($e->getMessage()), 'auth')
                || str_contains(strtolower($e->getMessage()), 'credential')
                || str_contains(strtolower($e->getMessage()), 'invalid_grant');
            return [
                'connected'     => !$isAuthError,
                'auth_required' => $isAuthError,
                'error'         => $e->getMessage(),
                'site_url'      => $siteUrl,
                'clicks'        => 0,
                'impressions'   => 0,
                'ctr'           => 0,
                'position'      => 0,
                'start_date'    => $dates['startDate'],
                'end_date'      => $dates['endDate'],
            ];
        }
    }

    /**
     * Fetch top search queries.
     */
    public function getTopQueries(?string $startDate = null, ?string $endDate = null, int $limit = 10, ?int $workspaceId = null): array
    {
        $siteUrl = $this->getSiteUrl($workspaceId);
        if (!$siteUrl || !$this->isConfigured($workspaceId)) {
            return [];
        }

        $dates = $this->resolveDateRange($startDate, $endDate);

        try {
            $body = [
                'startDate'  => $dates['startDate'],
                'endDate'    => $dates['endDate'],
                'dimensions' => ['query'],
                'searchType' => 'web',
                'rowLimit'   => 1000,
            ];

            $report = $this->querySearchAnalytics($siteUrl, $body, $workspaceId);
            $rows = $report['rows'] ?? [];

            // Sort matching Google Search Console UI ordering:
            // 1. Clicks descending (primary)
            // 2. Impressions descending (secondary for tied clicks)
            // 3. Position ascending (lower average position is better)
            // 4. Query string ascending (consistent alphabetical tie-breaker)
            usort($rows, function ($a, $b) {
                $c1 = (int)($a['clicks'] ?? 0);
                $c2 = (int)($b['clicks'] ?? 0);
                if ($c1 !== $c2) {
                    return $c2 <=> $c1;
                }

                $i1 = (int)($a['impressions'] ?? 0);
                $i2 = (int)($b['impressions'] ?? 0);
                if ($i1 !== $i2) {
                    return $i2 <=> $i1;
                }

                $p1 = (float)($a['position'] ?? 0);
                $p2 = (float)($b['position'] ?? 0);
                if ($p1 != $p2) {
                    return $p1 <=> $p2;
                }

                return strcmp($a['keys'][0] ?? '', $b['keys'][0] ?? '');
            });

            $results = [];
            foreach (array_slice($rows, 0, $limit) as $row) {
                $results[] = [
                    'query'       => $row['keys'][0] ?? 'Unknown',
                    'clicks'      => (int)($row['clicks'] ?? 0),
                    'impressions' => (int)($row['impressions'] ?? 0),
                    'ctr'         => round(((float)($row['ctr'] ?? 0)) * 100, 2),
                    'position'    => round((float)($row['position'] ?? 0), 1),
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            Log::warning('Search Console getTopQueries failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Fetch daily search trend (clicks and impressions by date).
     */
    public function getDailyTrend(?string $startDate = null, ?string $endDate = null, ?int $workspaceId = null): array
    {
        $siteUrl = $this->getSiteUrl($workspaceId);
        if (!$siteUrl || !$this->isConfigured($workspaceId)) {
            return ['labels' => [], 'clicks' => [], 'impressions' => [], 'has_data' => false];
        }

        $dates = $this->resolveDateRange($startDate, $endDate);

        try {
            $body = [
                'startDate'  => $dates['startDate'],
                'endDate'    => $dates['endDate'],
                'dimensions' => ['date'],
                'searchType' => 'web',
            ];

            $report = $this->querySearchAnalytics($siteUrl, $body, $workspaceId);
            $labels = [];
            $clicks = [];
            $impressions = [];

            foreach ($report['rows'] ?? [] as $row) {
                $rawDate = $row['keys'][0] ?? '';
                $formatted = !empty($rawDate) ? \Carbon\Carbon::parse($rawDate)->format('m/d') : '';

                $labels[]      = $formatted;
                $clicks[]      = (int)($row['clicks'] ?? 0);
                $impressions[] = (int)($row['impressions'] ?? 0);
            }

            return [
                'labels'      => $labels,
                'clicks'      => $clicks,
                'impressions' => $impressions,
                'has_data'    => array_sum($impressions) > 0,
            ];
        } catch (\Throwable $e) {
            Log::warning('Search Console getDailyTrend failed', ['error' => $e->getMessage()]);
            return ['labels' => [], 'clicks' => [], 'impressions' => [], 'has_data' => false];
        }
    }
}
