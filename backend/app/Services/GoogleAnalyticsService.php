<?php

namespace App\Services;

use Google\Client as GoogleClient;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Exception;

class GoogleAnalyticsService
{
    protected ?GoogleClient $client = null;
    protected string $credentialsPath;
    protected ?string $defaultPropertyId;
    protected GoogleOAuthService $oauthService;

    public function __construct(?GoogleOAuthService $oauthService = null)
    {
        $this->credentialsPath   = base_path(env('GOOGLE_ANALYTICS_CREDENTIALS_PATH', 'ga-credentials.json'));
        $this->defaultPropertyId = env('GOOGLE_ANALYTICS_PROPERTY_ID', null);
        $this->oauthService      = $oauthService ?: app(GoogleOAuthService::class);
    }

    /**
     * Check if GA4 integration is configured and active for a workspace.
     */
    public function isConfigured(?int $workspaceId = null): bool
    {
        if ($workspaceId) {
            $integ = Integration::where('workspace_id', $workspaceId)
                ->where('platform', 'google_analytics')
                ->where('is_connected', true)
                ->first();

            if ($integ) {
                // If the integration is marked expired, it cannot execute live queries
                if ($integ->isExpired()) {
                    return false;
                }

                $hasToken = !empty($integ->refresh_token) || Cache::has("ga4_token_{$workspaceId}") || file_exists($this->credentialsPath);
                $propId   = (!empty($integ->account_id) && $integ->account_id !== 'pending') ? $integ->account_id : $this->defaultPropertyId;

                if ($hasToken && !empty($propId)) {
                    if ($integ->account_id === 'pending') {
                        $integ->update([
                            'account_id'   => $propId,
                            'account_name' => "GA4 Property ({$propId})",
                        ]);
                    }
                    return true;
                }
            }
        }

        $hasFile = file_exists($this->credentialsPath);
        $propertyId = $this->getPropertyId($workspaceId);

        return $hasFile && !empty($propertyId);
    }

    /**
     * Get GA4 Property ID for a specific workspace or fallback to default.
     */
    public function getPropertyId(?int $workspaceId = null): ?string
    {
        if ($workspaceId) {
            try {
                $integ = Integration::where('workspace_id', $workspaceId)
                    ->where('platform', 'google_analytics')
                    ->where('is_connected', true)
                    ->first();

                if ($integ && !empty($integ->account_id) && $integ->account_id !== 'pending') {
                    return $integ->account_id;
                }
            } catch (\Throwable $e) {
                // Ignore DB errors in unit test environment
            }
        }

        return $this->defaultPropertyId;
    }

    /**
     * Generate Google OAuth 2.0 authorization URL for connecting GA4.
     */
    public function createAuthUrl(int $workspaceId): string
    {
        $clientId     = config('services.google_analytics.client_id');
        $clientSecret = config('services.google_analytics.client_secret');
        $redirectUri  = config('services.google_analytics.redirect_uri');

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception('Google Analytics OAuth Client ID or Client Secret is not configured in backend/.env');
        }

        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->addScope([
            'https://www.googleapis.com/auth/analytics.readonly',
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
     * Handle the OAuth callback: exchange code, store tokens, discover properties.
     */
    public function handleOAuthCallback(string $code, int $workspaceId): array
    {
        $clientId     = config('services.google_analytics.client_id');
        $clientSecret = config('services.google_analytics.client_secret');
        $redirectUri  = config('services.google_analytics.redirect_uri');

        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);

        $tokens = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($tokens['error'])) {
            throw new Exception('Google OAuth Token Error: ' . ($tokens['error_description'] ?? $tokens['error']));
        }

        $accessToken  = $tokens['access_token'] ?? '';
        $refreshToken = $tokens['refresh_token'] ?? null;

        if (empty($accessToken)) {
            throw new Exception('Failed to obtain Google Analytics access token.');
        }

        // Cache active access token for this workspace
        Cache::put("ga4_token_{$workspaceId}", $accessToken, 3500);

        // Fetch user email for display
        $userEmail = null;
        try {
            $userRes = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v2/userinfo');
            if ($userRes->successful()) {
                $userEmail = $userRes->json('email');
            }
        } catch (\Throwable $e) {
            Log::warning('[GA4 OAUTH] Failed fetching user info: ' . $e->getMessage());
        }

        // Discover accessible GA4 properties using Google Analytics Admin API
        $properties = $this->listAvailableProperties($accessToken);

        // If Admin API is disabled or returned 0 properties, fallback to default property from .env
        if (empty($properties) && !empty($this->defaultPropertyId)) {
            $properties[] = [
                'property_id'  => $this->defaultPropertyId,
                'name'         => "GA4 Property ({$this->defaultPropertyId})",
                'account_name' => $userEmail ?: 'Google Analytics',
            ];
        }

        // Find existing integration to preserve existing property selection if already set
        $existingInteg = Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'google_analytics')
            ->first();

        $selectedPropId = null;
        if ($existingInteg && !empty($existingInteg->account_id) && $existingInteg->account_id !== 'pending') {
            $selectedPropId = $existingInteg->account_id;
        } elseif (count($properties) === 1) {
            $selectedPropId = $properties[0]['property_id'];
        } elseif (!empty($this->defaultPropertyId)) {
            $selectedPropId = $this->defaultPropertyId;
        }

        $selectedPropName = $selectedPropId
            ? (collect($properties)->firstWhere('property_id', $selectedPropId)['name'] ?? ($userEmail ? "GA4 ({$selectedPropId}) - {$userEmail}" : "GA4 Property ({$selectedPropId})"))
            : (count($properties) === 1 ? $properties[0]['name'] : ($userEmail ? "GA4 ({$userEmail})" : "GA4 Property"));

        // Save integration
        $tokenToSave = $refreshToken ?: ($existingInteg && !$existingInteg->isExpired() ? $existingInteg->refresh_token : null);
        $expiresIn = (int)($tokens['expires_in'] ?? 3600);

        Integration::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'google_analytics',
            ],
            [
                'account_id'              => $selectedPropId ?: ($properties[0]['property_id'] ?? 'pending'),
                'account_name'            => $selectedPropName,
                'refresh_token'           => $tokenToSave,
                'access_token_expires_at' => now()->addSeconds($expiresIn),
                'is_connected'            => true,
                'connection_status'       => 'connected',
                'error_message'           => null,
                'last_sync_at'            => now(),
            ]
        );

        return [
            'workspace_id'         => $workspaceId,
            'user_email'           => $userEmail,
            'account_name'         => $selectedPropName,
            'selected_property_id' => $selectedPropId,
            'properties'           => $properties,
        ];
    }

    /**
     * List GA4 Properties accessible by the provided OAuth access token using Google Analytics Admin API.
     */
    public function listAvailableProperties(string $accessToken): array
    {
        $properties = [];
        try {
            $url = 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries';
            $response = Http::withToken($accessToken)->timeout(10)->get($url);

            if ($response->successful()) {
                $summaries = $response->json('accountSummaries') ?? [];
                foreach ($summaries as $account) {
                    $accName = $account['displayName'] ?? 'Google Analytics Account';
                    foreach ($account['propertySummaries'] ?? [] as $prop) {
                        $rawProp = $prop['property'] ?? '';
                        $propId  = str_replace('properties/', '', $rawProp);
                        if (!empty($propId)) {
                            $properties[] = [
                                'property_id'  => $propId,
                                'name'         => $prop['displayName'] ?? "GA4 ({$propId})",
                                'account_name' => $accName,
                            ];
                        }
                    }
                }
            } else {
                Log::warning('[GA4 ADMIN API] accountSummaries returned status ' . $response->status(), [
                    'body' => $response->body()
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[GA4 ADMIN API] listAvailableProperties failed: ' . $e->getMessage());
        }

        return $properties;
    }

    /**
     * Fetch a valid access token for GA4 Data API requests (using OAuth or service account fallback).
     */
    public function getAccessToken(?int $workspaceId = null, bool $forceRefresh = false): string
    {
        if ($workspaceId) {
            return $this->oauthService->getValidAccessToken(
                'google_analytics',
                $workspaceId,
                $this->credentialsPath,
                ['https://www.googleapis.com/auth/analytics.readonly'],
                $forceRefresh
            );
        }

        // 2. Fallback to Service Account if present
        if (file_exists($this->credentialsPath)) {
            $client = new GoogleClient();
            $client->setAuthConfig($this->credentialsPath);
            $client->addScope('https://www.googleapis.com/auth/analytics.readonly');

            $caPath = 'C:\\PHP\\extras\\ssl\\cacert.pem';
            if (file_exists($caPath)) {
                $client->setHttpClient(new \GuzzleHttp\Client(['verify' => $caPath]));
            }

            $token = $client->fetchAccessTokenWithAssertion();
            if (!isset($token['error']) && !empty($token['access_token'])) {
                return $token['access_token'];
            }
        }

        throw new \App\Exceptions\GoogleOAuthException(
            "No active Google Analytics credentials found for this workspace. Please connect Google Analytics in API Connections.",
            'google_analytics',
            true
        );
    }

    /**
     * Run a GA4 Data API report.
     */
    public function runReport(string $propertyId, array $requestBody, ?int $workspaceId = null): array
    {
        $accessToken = $this->getAccessToken($workspaceId);
        $cleanPropId = preg_replace('/[^0-9]/', '', $propertyId);

        if (empty($cleanPropId)) {
            throw new Exception("Invalid GA4 Property ID: {$propertyId}");
        }

        $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$cleanPropId}:runReport";

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->timeout(12)
            ->post($url, $requestBody);

        if (!$response->successful()) {
            Log::error('GA4 runReport failed', [
                'property_id' => $cleanPropId,
                'status'      => $response->status(),
                'body'        => $response->body()
            ]);
            throw new Exception("GA4 API Error: " . ($response->json('error.message') ?? $response->body()));
        }

        return $response->json();
    }

    /**
     * Normalize date range for GA4 Data API.
     * Standard GA4 reporting defaults to Last 28 completed days (28daysAgo to yesterday).
     */
    public function resolveDateRange(?string $startDate = null, ?string $endDate = null): array
    {
        if ($startDate === 'all') {
            return ['startDate' => '2020-01-01', 'endDate' => !empty($endDate) ? $endDate : 'yesterday'];
        }

        $resolvedStart = !empty($startDate) ? $startDate : '28daysAgo';
        $resolvedEnd   = !empty($endDate) ? $endDate : 'yesterday';

        return ['startDate' => $resolvedStart, 'endDate' => $resolvedEnd];
    }

    /**
     * Format seconds into human readable duration matching GA4 format (e.g. 14s, 1m 20s, 1h 5m 2s).
     */
    public function formatEngagementDuration(float $seconds): string
    {
        $sec = (int)floor($seconds);
        if ($sec <= 0) {
            return '0s';
        }
        $h = (int)floor($sec / 3600);
        $m = (int)floor(($sec % 3600) / 60);
        $s = $sec % 60;

        if ($h > 0) {
            return "{$h}h {$m}m {$s}s";
        }
        if ($m > 0) {
            return "{$m}m {$s}s";
        }
        return "{$s}s";
    }

    /**
     * Fetch overview metrics (Active Users, New Users, Sessions, Average Engagement Time, Screen Page Views).
     */
    public function getOverview(?string $startDate = null, ?string $endDate = null, ?int $workspaceId = null): array
    {
        $propId = $this->getPropertyId($workspaceId);
        if (!$propId) {
            return [
                'connected'     => false,
                'auth_required' => false,
                'message'       => 'Google Analytics Property ID not configured for this workspace.',
            ];
        }

        if ($workspaceId) {
            try {
                $integ = Integration::where('workspace_id', $workspaceId)
                    ->where('platform', 'google_analytics')
                    ->first();
                if ($integ && $integ->isExpired()) {
                    return [
                        'connected'     => false,
                        'auth_required' => true,
                        'error'         => $integ->error_message ?: 'Google Analytics session has expired. Reconnection required.',
                        'property_id'   => $propId,
                    ];
                }
            } catch (\Throwable $dbEx) {
                // Ignore DB errors in unit tests
            }
        }

        $dateRange = $this->resolveDateRange($startDate, $endDate);

        try {
            $body = [
                'dateRanges' => [
                    $dateRange
                ],
                'metrics' => [
                    ['name' => 'activeUsers'],
                    ['name' => 'newUsers'],
                    ['name' => 'sessions'],
                    ['name' => 'userEngagementDuration'],
                    ['name' => 'screenPageViews'],
                ],
            ];

            $report = $this->runReport($propId, $body, $workspaceId);
            $row = $report['rows'][0]['metricValues'] ?? [];

            $activeUsers  = (int)($row[0]['value'] ?? 0);
            $newUsers     = (int)($row[1]['value'] ?? 0);
            $sessions     = (int)($row[2]['value'] ?? 0);
            $durationSec  = (float)($row[3]['value'] ?? 0);
            $pageViews    = (int)($row[4]['value'] ?? 0);

            $avgEngagementTime = $activeUsers > 0 ? round($durationSec / $activeUsers, 2) : 0;
            $avgEngagementFormatted = $this->formatEngagementDuration($avgEngagementTime);

            return [
                'connected'                         => true,
                'auth_required'                     => false,
                'property_id'                       => $propId,
                'active_users'                      => $activeUsers,
                'new_users'                         => $newUsers,
                'sessions'                          => $sessions,
                'average_engagement_time'           => $avgEngagementTime,
                'average_engagement_time_formatted' => $avgEngagementFormatted,
                'screen_page_views'                 => $pageViews,
            ];
        } catch (\Throwable $e) {
            Log::warning('GA4 getOverview failed', ['error' => $e->getMessage()]);
            $isAuthError = ($e instanceof \App\Exceptions\GoogleOAuthException)
                || str_contains(strtolower($e->getMessage()), 'token')
                || str_contains(strtolower($e->getMessage()), 'auth')
                || str_contains(strtolower($e->getMessage()), 'credential')
                || str_contains(strtolower($e->getMessage()), 'invalid_grant');

            return [
                'connected'     => false,
                'auth_required' => $isAuthError,
                'error'         => $e->getMessage(),
                'property_id'   => $propId,
            ];
        }
    }

    /**
     * Alias for getOverview for compatibility with overview and test endpoints.
     */
    public function getOverviewMetrics(?string $startDate = null, ?string $endDate = null, ?int $workspaceId = null): array
    {
        return $this->getOverview($startDate, $endDate, $workspaceId);
    }

    /**
     * Format revenue amount according to currency code (e.g. ₹0.00, $0.00).
     */
    public function formatRevenue(float $amount, string $currencyCode = 'INR'): string
    {
        $symbol = match (strtoupper($currencyCode)) {
            'INR' => '₹',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => $currencyCode . ' ',
        };

        return $symbol . number_format($amount, 2);
    }

    /**
     * Fetch top traffic acquisition by Session Source / Medium.
     */
    public function getTrafficSources(?string $startDate = null, ?string $endDate = null, int $limit = 20, ?int $workspaceId = null): array
    {
        $propId = $this->getPropertyId($workspaceId);
        if (!$propId) {
            return [];
        }

        if ($workspaceId) {
            try {
                $integ = Integration::where('workspace_id', $workspaceId)
                    ->where('platform', 'google_analytics')
                    ->first();
                if ($integ && $integ->isExpired()) {
                    return [];
                }
            } catch (\Throwable $e) {
                // Ignore DB errors
            }
        }

        $dateRange = $this->resolveDateRange($startDate, $endDate);

        try {
            $body = [
                'dateRanges' => [
                    $dateRange
                ],
                'dimensions' => [
                    ['name' => 'sessionSourceMedium']
                ],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'keyEvents'],
                    ['name' => 'totalRevenue']
                ],
                'orderBys' => [
                    ['metric' => ['metricName' => 'sessions'], 'desc' => true]
                ],
                'limit' => $limit,
            ];

            try {
                $report = $this->runReport($propId, $body, $workspaceId);
            } catch (\Throwable $ex) {
                // If property schema expects legacy 'conversions' instead of 'keyEvents'
                if (str_contains(strtolower($ex->getMessage()), 'keyevents')) {
                    $body['metrics'][1] = ['name' => 'conversions'];
                    $report = $this->runReport($propId, $body, $workspaceId);
                } else {
                    throw $ex;
                }
            }

            $currencyCode = $report['metadata']['currencyCode'] ?? 'INR';
            $results = [];

            foreach ($report['rows'] ?? [] as $row) {
                $sourceMedium = $row['dimensionValues'][0]['value'] ?? '(not set)';
                $sessions     = (int)($row['metricValues'][0]['value'] ?? 0);
                $keyEvents    = (int)($row['metricValues'][1]['value'] ?? 0);
                $totalRevenue = (float)($row['metricValues'][2]['value'] ?? 0);

                $results[] = [
                    'source_medium'     => $sourceMedium,
                    'sessions'          => $sessions,
                    'key_events'        => $keyEvents,
                    'total_revenue'     => $totalRevenue,
                    'revenue_formatted' => $this->formatRevenue($totalRevenue, $currencyCode),
                    'currency'          => $currencyCode,
                    // Backward-compatible aliases
                    'channel'           => $sourceMedium,
                    'active_users'      => $sessions,
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            Log::warning('GA4 getTrafficSources failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Fetch daily traffic trend for GA4.
     */
    public function getDailyTrend(?string $startDate = null, ?string $endDate = null, ?int $workspaceId = null): array
    {
        $propId = $this->getPropertyId($workspaceId);
        if (!$propId) {
            return ['labels' => [], 'active_users' => [], 'sessions' => [], 'has_data' => false];
        }

        if ($workspaceId) {
            try {
                $integ = Integration::where('workspace_id', $workspaceId)
                    ->where('platform', 'google_analytics')
                    ->first();
                if ($integ && $integ->isExpired()) {
                    return ['labels' => [], 'active_users' => [], 'sessions' => [], 'has_data' => false];
                }
            } catch (\Throwable $e) {
                // Ignore DB errors
            }
        }

        $dateRange = $this->resolveDateRange($startDate, $endDate);

        try {
            $body = [
                'dateRanges' => [
                    $dateRange
                ],
                'dimensions' => [
                    ['name' => 'date']
                ],
                'metrics' => [
                    ['name' => 'activeUsers'],
                    ['name' => 'sessions']
                ],
                'orderBys' => [
                    ['dimension' => ['dimensionName' => 'date']]
                ]
            ];

            $report = $this->runReport($propId, $body, $workspaceId);
            $labels = [];
            $activeUsers = [];
            $sessions = [];

            foreach ($report['rows'] ?? [] as $row) {
                $rawDate = $row['dimensionValues'][0]['value'] ?? '';
                $formatted = strlen($rawDate) === 8
                    ? substr($rawDate, 4, 2) . '/' . substr($rawDate, 6, 2)
                    : $rawDate;

                $labels[] = $formatted;
                $activeUsers[] = (int)($row['metricValues'][0]['value'] ?? 0);
                $sessions[] = (int)($row['metricValues'][1]['value'] ?? 0);
            }

            return [
                'labels'       => $labels,
                'active_users' => $activeUsers,
                'sessions'     => $sessions,
                'has_data'     => array_sum($sessions) > 0,
            ];
        } catch (\Throwable $e) {
            Log::warning('GA4 getDailyTrend failed', ['error' => $e->getMessage()]);
            return ['labels' => [], 'active_users' => [], 'sessions' => [], 'has_data' => false];
        }
    }
}
