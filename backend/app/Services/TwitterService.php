<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class TwitterService
{
    protected ?string $clientId;
    protected ?string $clientSecret;
    protected string $redirectUri;
    protected bool $isMock;

    public function __construct()
    {
        $this->clientId = config('services.twitter.client_id') ?? env('TWITTER_CLIENT_ID');
        $this->clientSecret = config('services.twitter.client_secret') ?? env('TWITTER_CLIENT_SECRET');
        $this->redirectUri = config('services.twitter.redirect') ?? env('TWITTER_REDIRECT_URI', 'http://localhost:8000/api/twitter/callback');
        
        // Mock mode is active if credentials are not configured or set to 'mock'
        $this->isMock = empty($this->clientId) || empty($this->clientSecret) || $this->clientId === 'mock' || $this->clientSecret === 'mock';
    }

    /**
     * Check if the service is currently running in mock/simulator mode.
     */
    public function isMockMode(): bool
    {
        return $this->isMock;
    }

    /**
     * Generate X (Twitter) OAuth 2.0 PKCE authorization URL.
     */
    public function getAuthUrl(string $state, string $codeChallenge): string
    {
        if ($this->isMock) {
            return route('twitter.callback', [
                'code' => 'mock_oauth_code_123456',
                'state' => $state
            ]);
        }

        $queries = [
            'response_type'         => 'code',
            'client_id'             => $this->clientId,
            'redirect_uri'          => $this->redirectUri,
            'scope'                 => 'tweet.read tweet.write users.read offline.access',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return 'https://twitter.com/i/oauth2/authorize?' . http_build_query($queries);
    }

    /**
     * Exchange auth code for access & refresh tokens.
     */
    public function exchangeCodeForTokens(string $code, string $codeVerifier): array
    {
        if ($this->isMock || $code === 'mock_oauth_code_123456') {
            return [
                'access_token'  => 'mock_access_token_' . bin2hex(random_bytes(16)),
                'refresh_token' => 'mock_refresh_token_' . bin2hex(random_bytes(16)),
                'expires_in'    => 7200,
            ];
        }

        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post('https://api.twitter.com/2/oauth2/token', [
                'client_id'     => $this->clientId,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->redirectUri,
                'code_verifier' => $codeVerifier,
            ]);

        if (!$response->successful()) {
            $status = $response->status();
            $body = $response->body();
            Log::error('Twitter token exchange failed', ['status' => $status, 'body' => $body]);
            throw new Exception("X (Twitter) token exchange failed [HTTP {$status}]: " . ($response->json('error_description') ?? $body));
        }

        return $response->json();
    }

    /**
     * Fetch user details from Twitter API v2.
     */
    public function getUserDetails(string $accessToken): array
    {
        if ($this->isMock || str_starts_with($accessToken, 'mock_access_token_')) {
            return [
                'id'       => 'mock_twitter_user_998877',
                'name'     => 'Demo Agency',
                'username' => 'DemoAgency_X',
            ];
        }

        $response = Http::withToken($accessToken)
            ->get('https://api.twitter.com/2/users/me', [
                'user.fields' => 'id,name,username,profile_image_url',
            ]);

        if (!$response->successful()) {
            $status = $response->status();
            $body = $response->body();
            Log::error('Twitter fetch user details failed', ['status' => $status, 'body' => $body]);
            throw new Exception("Failed to fetch X (Twitter) user details [HTTP {$status}]: {$body}");
        }

        $data = $response->json('data');
        if (empty($data)) {
            throw new Exception('Invalid user data returned from X (Twitter) API.');
        }

        return [
            'id'                => $data['id'],
            'name'              => $data['name'],
            'username'          => $data['username'],
            'profile_image_url' => $data['profile_image_url'] ?? null,
        ];
    }

    /**
     * Get a valid access token for the integration, automatically refreshing if expired.
     */
    public function getValidAccessToken(Integration $integration): string
    {
        if ($this->isMock || str_starts_with($integration->refresh_token ?? '', 'mock_')) {
            return 'mock_access_token_sample';
        }

        $cacheKey = 'twitter_access_token_' . $integration->id;
        $cachedToken = Cache::get($cacheKey);

        // If cached token exists and token expiration is in the future (> 5 mins), return cached
        $expiresAt = $integration->access_token_expires_at ?? $integration->token_expires_at;
        if (!empty($cachedToken) && $expiresAt && $expiresAt->isFuture() && $expiresAt->diffInMinutes(now()) > 5) {
            return $cachedToken;
        }

        if (empty($integration->refresh_token)) {
            throw new Exception('X (Twitter) refresh token is missing. Please reconnect your account.');
        }

        // Perform token refresh
        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post('https://api.twitter.com/2/oauth2/token', [
                'client_id'     => $this->clientId,
                'grant_type'    => 'refresh_token',
                'refresh_token' => $integration->refresh_token,
            ]);

        if (!$response->successful()) {
            $status = $response->status();
            $body = $response->body();
            Log::error('Twitter refresh token failed', ['status' => $status, 'body' => $body]);
            $integration->update(['connection_status' => 'expired']);
            throw new Exception("Failed to refresh X (Twitter) connection [HTTP {$status}]: {$body}");
        }

        $tokens = $response->json();
        $newAccessToken = $tokens['access_token'] ?? null;
        $newRefreshToken = $tokens['refresh_token'] ?? $integration->refresh_token;
        $expiresIn = (int)($tokens['expires_in'] ?? 7200);

        if (empty($newAccessToken)) {
            throw new Exception('Invalid token response returned from X (Twitter) refresh endpoint.');
        }

        // Update database record with new rotated refresh token & expiry
        $integration->update([
            'refresh_token'           => $newRefreshToken,
            'access_token_expires_at' => now()->addSeconds($expiresIn),
            'token_expires_at'        => now()->addSeconds($expiresIn),
            'connection_status'       => 'connected',
            'last_sync_at'            => now(),
        ]);

        // Cache the new access token
        $cacheTtl = max(60, $expiresIn - 300);
        Cache::put($cacheKey, $newAccessToken, now()->addSeconds($cacheTtl));

        return $newAccessToken;
    }

    /**
     * Publish a text post (tweet) on X (Twitter).
     */
    public function publishTweet(Integration $integration, string $text): array
    {
        if ($this->isMock || str_starts_with($integration->refresh_token ?? '', 'mock_')) {
            Log::info('Mock Tweet published', ['text' => $text, 'workspace' => $integration->workspace_id]);
            return [
                'success' => true,
                'id'      => 'mock_tweet_id_' . rand(100000000, 999999999),
            ];
        }

        $accessToken = $this->getValidAccessToken($integration);

        // Strictly clamp text length to 280 characters
        $tweetText = mb_substr(trim($text), 0, 280);

        // Call X API v2 POST /2/tweets
        $response = Http::withToken($accessToken)
            ->post('https://api.twitter.com/2/tweets', [
                'text' => $tweetText,
            ]);

        $status = $response->status();
        $body = $response->body();
        $json = $response->json();

        if (!$response->successful()) {
            Log::error('Twitter publishTweet failed', [
                'status'     => $status,
                'body'       => $body,
                'account_id' => $integration->account_id,
            ]);

            $detail = $json['detail'] ?? ($json['title'] ?? ($json['message'] ?? $body));
            throw new Exception("X (Twitter) API error [HTTP {$status}]: {$detail}");
        }

        $data = $json['data'] ?? [];

        return [
            'success' => true,
            'id'      => $data['id'] ?? null,
            'text'    => $data['text'] ?? $tweetText,
        ];
    }
}
