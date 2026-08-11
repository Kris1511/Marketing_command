<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            // Simulator redirect
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
            Log::error('Twitter token exchange failed', ['body' => $response->body()]);
            throw new Exception('Twitter OAuth token exchange failed: ' . ($response->json('error_description') ?? $response->body()));
        }

        return $response->json();
    }

    /**
     * Fetch user details from Twitter API.
     */
    public function getUserDetails(string $accessToken): array
    {
        if ($this->isMock || str_starts_with($accessToken, 'mock_access_token_')) {
            return [
                'id' => 'mock_twitter_user_998877',
                'name' => 'Demo Agency',
                'username' => 'DemoAgency_X',
            ];
        }

        $response = Http::withToken($accessToken)
            ->get('https://api.twitter.com/2/users/me');

        if (!$response->successful()) {
            Log::error('Twitter fetch user details failed', ['body' => $response->body()]);
            throw new Exception('Failed to fetch Twitter user details.');
        }

        $data = $response->json('data');
        if (empty($data)) {
            throw new Exception('Invalid user data returned from Twitter.');
        }

        return [
            'id'       => $data['id'],
            'name'     => $data['name'],
            'username' => $data['username'],
        ];
    }

    /**
     * Refresh access token if expired.
     */
    public function refreshAccessTokenIfNeeded(Integration $integration): Integration
    {
        // If mock, just slide expiry window
        if ($this->isMock || str_starts_with($integration->refresh_token ?? '', 'mock_')) {
            if ($integration->access_token_expires_at && $integration->access_token_expires_at->isPast()) {
                $integration->update([
                    'access_token_expires_at' => now()->addHours(2),
                    'token_expires_at'        => now()->addHours(2),
                ]);
            }
            return $integration;
        }

        // Check if token expires in under 5 minutes
        $expiresAt = $integration->access_token_expires_at ?? $integration->token_expires_at;
        if ($expiresAt && $expiresAt->isFuture() && $expiresAt->diffInMinutes(now()) > 5) {
            return $integration;
        }

        if (empty($integration->refresh_token)) {
            throw new Exception('Twitter refresh token is missing. Please reconnect your account.');
        }

        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post('https://api.twitter.com/2/oauth2/token', [
                'client_id'     => $this->clientId,
                'grant_type'    => 'refresh_token',
                'refresh_token' => $integration->refresh_token,
            ]);

        if (!$response->successful()) {
            Log::error('Twitter refresh token failed', ['body' => $response->body()]);
            $integration->update(['connection_status' => 'expired']);
            throw new Exception('Failed to refresh Twitter connection. Please reconnect.');
        }

        $tokens = $response->json();
        $expiresIn = $tokens['expires_in'] ?? 7200;

        $updateData = [
            'refresh_token'           => $tokens['refresh_token'] ?? $integration->refresh_token,
            'access_token_expires_at' => now()->addSeconds($expiresIn),
            'token_expires_at'        => now()->addSeconds($expiresIn),
            'connection_status'       => 'connected',
        ];

        $integration->update($updateData);
        return $integration;
    }

    /**
     * Publish a text post (tweet) on X (Twitter).
     */
    public function publishTweet(Integration $integration, string $text): array
    {
        $integration = $this->refreshAccessTokenIfNeeded($integration);

        if ($this->isMock || str_starts_with($integration->refresh_token ?? '', 'mock_')) {
            Log::info('Mock Tweet published', ['text' => $text, 'workspace' => $integration->workspace_id]);
            return [
                'success' => true,
                'id'      => 'mock_tweet_id_' . rand(100000000, 999999999),
            ];
        }

        // We need the temporary access token from exchange or refresh.
        // In this implementation, since integrations table does not have an access_token column, 
        // let's retrieve the fresh access token. Since we just ran refreshAccessTokenIfNeeded,
        // the client credentials or token exchange response was returned.
        // To be safe and simple, let's perform a token refresh to obtain a valid access token directly.
        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post('https://api.twitter.com/2/oauth2/token', [
                'client_id'     => $this->clientId,
                'grant_type'    => 'refresh_token',
                'refresh_token' => $integration->refresh_token,
            ]);

        if (!$response->successful()) {
            throw new Exception('Twitter access token invalid or expired. Refresh failed.');
        }

        $accessToken = $response->json('access_token');

        // Post Tweet using Twitter API v2 POST /2/tweets
        $tweetResponse = Http::withToken($accessToken)
            ->post('https://api.twitter.com/2/tweets', [
                'text' => mb_substr($text, 0, 280), // Strictly clamp text length to 280 chars
            ]);

        if (!$tweetResponse->successful()) {
            Log::error('Twitter publishing failed', ['body' => $tweetResponse->body()]);
            throw new Exception('X (Twitter) API error: ' . ($tweetResponse->json('detail') ?? $tweetResponse->body()));
        }

        $data = $tweetResponse->json('data');

        return [
            'success' => true,
            'id'      => $data['id'] ?? null,
        ];
    }
}
