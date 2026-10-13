<?php

namespace App\Services;

use App\Models\Integration;
use App\Exceptions\GoogleOAuthException;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class GoogleOAuthService
{
    /**
     * Cache TTL safety margin in seconds before actual expiry.
     */
    protected const CACHE_SAFETY_MARGIN_SECONDS = 120;

    /**
     * Google token endpoint.
     */
    protected const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /**
     * Resolve Google OAuth Client ID and Secret for a given service.
     */
    public function resolveCredentials(string $platform): array
    {
        $configKey = match ($platform) {
            'google_analytics', 'ga4' => 'google_analytics',
            'search_console', 'gsc', 'google_search_console' => 'google_search_console',
            default => 'google',
        };

        $clientId     = config("services.{$configKey}.client_id") ?: config('services.google.client_id');
        $clientSecret = config("services.{$configKey}.client_secret") ?: config('services.google.client_secret');
        $redirectUri  = config("services.{$configKey}.redirect_uri") ?: config('services.google.redirect_uri');

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception("Google OAuth Client ID or Client Secret is not configured for platform [{$platform}].");
        }

        return [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
        ];
    }

    /**
     * Get a valid access token for a given workspace and platform.
     * Automatically uses cached token, or refreshes via refresh_token, or falls back to service account.
     */
    public function getValidAccessToken(
        string $platform,
        int $workspaceId,
        ?string $credentialsPath = null,
        ?array $serviceAccountScopes = null,
        bool $forceRefresh = false
    ): string {
        $cacheKey = $this->getCacheKey($platform, $workspaceId);

        // 1. Return cached access token if valid and not forcing refresh
        if (!$forceRefresh) {
            $cachedToken = Cache::get($cacheKey);
            if (!empty($cachedToken)) {
                return $cachedToken;
            }
        }

        // 2. Fetch integration record from database
        $integ = Integration::where('workspace_id', $workspaceId)
            ->where('platform', $platform)
            ->first();

        if ($integ && !empty($integ->refresh_token)) {
            // If already marked expired and not forcing recheck, throw immediately to prevent spamming Google API
            if ($integ->isExpired() && !$forceRefresh) {
                $humanName = $this->getPlatformDisplayName($platform);
                throw new GoogleOAuthException(
                    "{$humanName} session expired. Reconnection required.",
                    $platform,
                    true
                );
            }

            return $this->refreshOAuthToken($integ, $platform, $cacheKey);
        }

        // 3. Fallback to Service Account if JSON key exists
        if (!empty($credentialsPath) && file_exists($credentialsPath)) {
            return $this->getServiceAccountToken($credentialsPath, $serviceAccountScopes ?? []);
        }

        $humanName = $this->getPlatformDisplayName($platform);
        throw new GoogleOAuthException(
            "{$humanName} is not connected for this workspace. Please connect in Marketing Command.",
            $platform,
            true
        );
    }

    /**
     * Refresh OAuth access token using refresh_token grant against Google OAuth servers.
     */
    public function refreshOAuthToken(Integration $integration, string $platform, ?string $cacheKey = null): string
    {
        $cacheKey = $cacheKey ?: $this->getCacheKey($platform, $integration->workspace_id);
        $creds = $this->resolveCredentials($platform);

        $refreshToken = $integration->refresh_token;
        if (empty($refreshToken)) {
            $integration->markAsExpired("No refresh token available.");
            Cache::forget($cacheKey);
            throw new GoogleOAuthException(
                "Missing refresh token for {$this->getPlatformDisplayName($platform)}. Please reconnect your account.",
                $platform,
                true
            );
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_ENDPOINT, [
                'client_id'     => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ]);
        } catch (\Throwable $netEx) {
            Log::error("[Google OAuth Refresh] Network request failed for {$platform}", [
                'workspace_id' => $integration->workspace_id,
                'error'        => $netEx->getMessage(),
            ]);
            throw new Exception("Unable to connect to Google OAuth servers: " . $netEx->getMessage(), 0, $netEx);
        }

        if ($response->successful() && !empty($response->json('access_token'))) {
            $accessToken = $response->json('access_token');
            $expiresIn   = (int)($response->json('expires_in') ?? 3600);
            $cacheTtl    = max(60, $expiresIn - self::CACHE_SAFETY_MARGIN_SECONDS);

            // Cache new access token
            Cache::put($cacheKey, $accessToken, $cacheTtl);

            // Prepare integration updates
            $updates = [
                'access_token_expires_at' => now()->addSeconds($expiresIn),
                'connection_status'       => 'connected',
                'error_message'           => null,
                'is_connected'            => true,
                'last_sync_at'            => now(),
            ];

            // If Google returned a rotated refresh token, securely persist it
            $newRefreshToken = $response->json('refresh_token');
            if (!empty($newRefreshToken)) {
                $updates['refresh_token'] = $newRefreshToken;
                Log::info("[Google OAuth Refresh] Rotated refresh token persisted for workspace {$integration->workspace_id} ({$platform})");
            }

            $integration->update($updates);

            return $accessToken;
        }

        $errorData = $response->json();
        $errorCode = $errorData['error'] ?? 'unknown_error';
        $errorDesc = $errorData['error_description'] ?? $response->body();

        Log::warning("[Google OAuth Refresh Failed] Workspace {$integration->workspace_id} ({$platform})", [
            'status' => $response->status(),
            'error'  => $errorData,
        ]);

        // Check if token was expired, revoked, or rejected by Google
        $isGrantRevoked = ($errorCode === 'invalid_grant')
            || str_contains(strtolower($errorDesc), 'expired')
            || str_contains(strtolower($errorDesc), 'revoked')
            || str_contains(strtolower($errorDesc), 'bad request');

        if ($isGrantRevoked) {
            $integration->markAsExpired($errorDesc);
            Cache::forget($cacheKey);

            $humanName = $this->getPlatformDisplayName($platform);
            throw new GoogleOAuthException(
                "{$humanName} authorization has expired or was revoked (Google returned '{$errorCode}'). Please reconnect your Google account.",
                $platform,
                true
            );
        }

        throw new Exception("Google token refresh error [{$errorCode}]: {$errorDesc}");
    }

    /**
     * Obtain access token using service account credentials file.
     */
    protected function getServiceAccountToken(string $credentialsPath, array $scopes): string
    {
        $client = new GoogleClient();
        $client->setAuthConfig($credentialsPath);
        foreach ($scopes as $scope) {
            $client->addScope($scope);
        }

        $caPath = 'C:\\PHP\\extras\\ssl\\cacert.pem';
        if (file_exists($caPath)) {
            $client->setHttpClient(new \GuzzleHttp\Client(['verify' => $caPath]));
        }

        $token = $client->fetchAccessTokenWithAssertion();
        if (isset($token['error']) || empty($token['access_token'])) {
            throw new Exception("Service Account Assertion failed: " . ($token['error_description'] ?? $token['error'] ?? 'Unknown error'));
        }

        return $token['access_token'];
    }

    /**
     * Generate cache key for active workspace access token.
     */
    public function getCacheKey(string $platform, int $workspaceId): string
    {
        $prefix = match ($platform) {
            'google_analytics' => 'ga4_token_',
            'search_console'   => 'gsc_token_',
            default            => "google_{$platform}_token_",
        };
        return "{$prefix}{$workspaceId}";
    }

    /**
     * Human readable display name for platforms.
     */
    public function getPlatformDisplayName(string $platform): string
    {
        return match ($platform) {
            'google_analytics' => 'Google Analytics',
            'search_console'   => 'Google Search Console',
            'youtube'          => 'YouTube',
            default            => 'Google',
        };
    }
}
