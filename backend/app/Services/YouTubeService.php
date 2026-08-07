<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\YouTube as GoogleYouTube;
use App\Models\YouTubeConnection;
use Illuminate\Support\Facades\Log;
use Exception;

class YouTubeService
{
    protected GoogleClient $client;

    public function __construct()
    {
        if (!class_exists(\Google\Client::class)) {
            spl_autoload_register(function ($class) {
                $prefixes = [
                    'Google\\Service\\' => base_path('vendor/google/apiclient-services/src/'),
                    'Google\\Auth\\' => base_path('vendor/google/auth/src/'),
                    'Google\\' => base_path('vendor/google/apiclient/src/'),
                    'Firebase\\JWT\\' => base_path('vendor/firebase/php-jwt/src/'),
                    'Psr\\Cache\\' => base_path('vendor/psr/cache/src/'),
                ];
                foreach ($prefixes as $prefix => $baseDir) {
                    $len = strlen($prefix);
                    if (strncmp($prefix, $class, $len) === 0) {
                        $relativeClass = substr($class, $len);
                        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
                        if (file_exists($file)) {
                            require_once $file;
                            return;
                        }
                    }
                }
            });
        }

        $this->client = new GoogleClient();
        
        $clientId = config('services.google.client_id', env('GOOGLE_CLIENT_ID'));
        $clientSecret = config('services.google.client_secret', env('GOOGLE_CLIENT_SECRET'));
        $redirectUri = config('services.google.redirect_uri', env('GOOGLE_REDIRECT_URI', 'http://localhost:8000/api/youtube/callback'));

        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        $this->client->setRedirectUri($redirectUri);

        // Requested OAuth Scope - simplified to youtube.readonly only for initial verification
        $this->client->setScopes([
            'https://www.googleapis.com/auth/youtube.readonly',
        ]);

        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
    }

    /**
     * Get Google OAuth redirect URL.
     */
    public function getAuthUrl(string $state): string
    {
        $this->client->setState($state);
        return $this->client->createAuthUrl();
    }

    /**
     * Exchange auth code for access & refresh tokens.
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $accessToken = $this->client->fetchAccessTokenWithAuthCode($code);
        
        if (isset($accessToken['error'])) {
            throw new Exception("Google OAuth Error: " . ($accessToken['error_description'] ?? $accessToken['error']));
        }

        // Determine granted scopes safely
        $grantedScopes = $accessToken['scope'] ?? $this->client->getAccessToken()['scope'] ?? 'unknown';

        Log::info('Google OAuth token exchange successful', [
            'has_access_token'  => !empty($accessToken['access_token']),
            'has_refresh_token' => !empty($accessToken['refresh_token']),
            'granted_scopes'    => $grantedScopes,
            'expires_in'        => $accessToken['expires_in'] ?? null,
        ]);

        return $accessToken;
    }

    /**
     * Refresh access token if expired or close to expiry.
     */
    public function refreshAccessTokenIfNeeded(YouTubeConnection $connection): YouTubeConnection
    {
        // Check if token expires within 2 minutes
        if ($connection->token_expires_at && $connection->token_expires_at->isFuture() && $connection->token_expires_at->diffInMinutes(now()) > 2) {
            return $connection;
        }

        if (empty($connection->refresh_token)) {
            throw new Exception('Refresh token missing or revoked. Please reconnect your YouTube account.');
        }

        try {
            $newToken = $this->client->fetchAccessTokenWithRefreshToken($connection->refresh_token);

            if (isset($newToken['error'])) {
                throw new Exception("Failed to refresh token: " . ($newToken['error_description'] ?? $newToken['error']));
            }

            $grantedScopes = $newToken['scope'] ?? $this->client->getAccessToken()['scope'] ?? 'unknown';

            Log::info('YouTube access token refreshed successfully', [
                'has_access_token'  => !empty($newToken['access_token']),
                'has_refresh_token' => !empty($newToken['refresh_token']),
                'granted_scopes'    => $grantedScopes,
                'expires_in'        => $newToken['expires_in'] ?? null,
            ]);

            $expiresIn = $newToken['expires_in'] ?? 3600;

            $updateFields = [
                'access_token'     => $newToken['access_token'],
                'token_expires_at' => now()->addSeconds($expiresIn),
            ];

            if (!empty($newToken['refresh_token'])) {
                $updateFields['refresh_token'] = $newToken['refresh_token'];
            }

            $connection->update($updateFields);

            return $connection;
        } catch (Exception $e) {
            Log::error('YouTube token refresh failed', ['error' => $e->getMessage(), 'channel_id' => $connection->channel_id]);
            throw new Exception('YouTube connection lost. Please reconnect your channel.');
        }
    }

    /**
     * Get YouTube Channel information using YouTube Data API v3.
     */
    public function getChannelInfo(string $accessToken): array
    {
        $this->client->setAccessToken($accessToken);
        $youtube = new GoogleYouTube($this->client);

        $response = $youtube->channels->listChannels('snippet,statistics', ['mine' => true]);

        if (empty($response->getItems())) {
            throw new Exception('No YouTube Channel found for this Google account.');
        }

        $channel = $response->getItems()[0];
        $snippet = $channel->getSnippet();
        $statistics = $channel->getStatistics();
        $thumbnails = $snippet->getThumbnails();
        $thumbnailUrl = $thumbnails->getHigh() ? $thumbnails->getHigh()->getUrl() : ($thumbnails->getDefault() ? $thumbnails->getDefault()->getUrl() : null);

        return [
            'channel_id' => $channel->getId(),
            'channel_name' => $snippet->getTitle(),
            'channel_description' => $snippet->getDescription(),
            'channel_thumbnail' => $thumbnailUrl,
            'subscriber_count' => (int) $statistics->getSubscriberCount(),
            'video_count' => (int) $statistics->getVideoCount(),
            'view_count' => (int) $statistics->getViewCount(),
        ];
    }

    /**
     * Save or update YouTube Channel in database.
     */
    public function saveChannelConnection(?int $userId, array $tokens, array $channelData): YouTubeConnection
    {
        $expiresIn = $tokens['expires_in'] ?? 3600;
        $expiresAt = now()->addSeconds($expiresIn);

        $updateData = [
            'user_id' => $userId,
            'channel_name' => $channelData['channel_name'],
            'channel_description' => $channelData['channel_description'],
            'channel_thumbnail' => $channelData['channel_thumbnail'],
            'subscriber_count' => $channelData['subscriber_count'],
            'video_count' => $channelData['video_count'],
            'view_count' => $channelData['view_count'],
            'access_token' => $tokens['access_token'],
            'token_expires_at' => $expiresAt,
        ];

        // Store refresh_token if provided (Google only sends it on initial consent)
        if (!empty($tokens['refresh_token'])) {
            $updateData['refresh_token'] = $tokens['refresh_token'];
        }

        return YouTubeConnection::updateOrCreate(
            ['channel_id' => $channelData['channel_id']],
            $updateData
        );
    }
}
