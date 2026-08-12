<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\YouTube as GoogleYouTube;
use App\Models\YouTubeConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
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

        // Requested OAuth Scope - include yt-analytics.readonly for YouTube Analytics API
        $this->client->setScopes([
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/yt-analytics.readonly',
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube',
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

    /**
     * Upload a video to YouTube using chunked/resumable media upload.
     */
    public function uploadVideo(YouTubeConnection $connection, string $videoPath, string $title, string $description, string $privacyStatus = 'public'): array
    {
        $connection = $this->refreshAccessTokenIfNeeded($connection);
        $this->client->setAccessToken($connection->access_token);

        $youtube = new GoogleYouTube($this->client);

        $video = new \Google\Service\YouTube\Video();

        $snippet = new \Google\Service\YouTube\VideoSnippet();
        $snippet->setTitle($title);
        $snippet->setDescription($description);
        $video->setSnippet($snippet);

        $status = new \Google\Service\YouTube\VideoStatus();
        $status->setPrivacyStatus($privacyStatus);
        $video->setStatus($status);

        // Defer execution of query to execute resumable upload manually
        $this->client->setDefer(true);
        $insertRequest = $youtube->videos->insert('status,snippet', $video);

        $chunkSizeBytes = 1 * 1024 * 1024; // 1MB chunks
        $media = new \Google\Http\MediaFileUpload(
            $this->client,
            $insertRequest,
            'video/*',
            null,
            true,
            $chunkSizeBytes
        );
        $media->setFileSize(filesize($videoPath));

        $statusResult = false;
        $handle = fopen($videoPath, 'rb');
        while (!$statusResult && !feof($handle)) {
            $chunk = fread($handle, $chunkSizeBytes);
            $statusResult = $media->nextChunk($chunk);
        }
        fclose($handle);

        // Reset defer status
        $this->client->setDefer(false);

        if ($statusResult instanceof \Google\Service\YouTube\Video) {
            return [
                'success' => true,
                'id' => $statusResult->getId(),
            ];
        }

        throw new Exception("YouTube upload failed or did not return video info.");
    }

    /**
     * Get YouTube metrics using YouTube Analytics API with YouTube Data API v3 video statistics fallback.
     */
    public function getAnalyticsOverview(YouTubeConnection $connection, ?string $startDate = null, ?string $endDate = null): array
    {
        $connection = $this->refreshAccessTokenIfNeeded($connection);

        $startDate = !empty($startDate) ? $startDate : now()->subDays(28)->format('Y-m-d');
        $endDate = !empty($endDate) ? $endDate : now()->format('Y-m-d');

        // Sanity check date ordering
        if (strtotime($startDate) > strtotime($endDate)) {
            $temp = $startDate;
            $startDate = $endDate;
            $endDate = $temp;
        }

        // 1. Fetch channel video statistics from YouTube Data API v3 as base metrics
        $dataApiStats = $this->getChannelVideoStatsFromDataApi($connection->access_token);

        // 2. Query YouTube Analytics API for date-ranged metrics (including shares)
        $analyticsSuccess = false;
        $reauthRequired = false;
        $analyticsMessage = null;

        $analyticsMetrics = [
            'views'    => 0,
            'likes'    => 0,
            'comments' => 0,
            'shares'   => 0,
        ];

        try {
            $response = Http::withoutVerifying()
                ->withToken($connection->access_token)
                ->get('https://youtubeanalytics.googleapis.com/v2/reports', [
                    'ids'       => 'channel==MINE',
                    'startDate' => $startDate,
                    'endDate'   => $endDate,
                    'metrics'   => 'views,likes,comments,shares',
                ]);

            if ($response->failed()) {
                $status = $response->status();
                $json = $response->json();
                $errorMessage = $json['error']['message'] ?? 'YouTube Analytics API request failed.';
                $errorReason = $json['error']['errors'][0]['reason'] ?? '';

                if ($errorReason === 'accessNotConfigured' || str_contains(strtolower($errorMessage), 'disabled') || str_contains(strtolower($errorMessage), 'not been used')) {
                    $reauthRequired = false;
                    $analyticsMessage = 'YouTube Analytics API is disabled in Google Cloud Console for project 80913470656. Enable "YouTube Analytics API" in Google Cloud Console to sync live Views (14 views) and Analytics.';
                    Log::warning('YouTube Analytics API is disabled in Google Cloud Console project 80913470656.');
                } elseif ($status === 403 || str_contains(strtolower($errorMessage), 'permission') || str_contains(strtolower($errorReason), 'forbidden') || $errorReason === 'insufficientPermissions') {
                    $reauthRequired = true;
                    $analyticsMessage = 'YouTube Analytics permission (yt-analytics.readonly scope) is missing or revoked. Please reconnect your YouTube channel to enable date-range Analytics. Displaying statistics from YouTube Data API v3.';
                } else {
                    $analyticsMessage = "YouTube Analytics Notice ({$status}): {$errorMessage}";
                }
            } else {
                $data = $response->json();
                $headers = $data['columnHeaders'] ?? [];
                $rows = $data['rows'] ?? [];

                if (!empty($rows) && !empty($headers)) {
                    $firstRow = $rows[0];
                    foreach ($headers as $index => $headerInfo) {
                        $metricName = strtolower($headerInfo['name'] ?? '');
                        if (array_key_exists($metricName, $analyticsMetrics)) {
                            $analyticsMetrics[$metricName] = (int) ($firstRow[$index] ?? 0);
                        }
                    }
                }
                $analyticsSuccess = true;
            }
        } catch (Exception $e) {
            Log::warning('YouTube Analytics API Exception, falling back to Data API v3', [
                'channel_id' => $connection->channel_id,
                'error'      => $e->getMessage(),
            ]);
            $analyticsMessage = $e->getMessage();
        }

        // Combine metrics intelligently using max() to prevent metrics from dropping/fluctuating
        // between YouTube Analytics API batch data and YouTube Data API v3 live statistics
        $finalViews = max((int)($analyticsMetrics['views'] ?? 0), (int)($dataApiStats['views'] ?? 0), (int)($connection->view_count ?? 0));
        $finalLikes = max((int)($analyticsMetrics['likes'] ?? 0), (int)($dataApiStats['likes'] ?? 0));
        $finalComments = max((int)($analyticsMetrics['comments'] ?? 0), (int)($dataApiStats['comments'] ?? 0));
        $finalShares = (int)($analyticsMetrics['shares'] ?? 0);

        return [
            'success'                  => true,
            'analytics_api_working'    => $analyticsSuccess,
            'reauthorization_required' => $reauthRequired,
            'message'                  => $analyticsMessage ?? 'YouTube analytics retrieved successfully.',
            'start_date'               => $startDate,
            'end_date'                 => $endDate,
            'views'                    => $finalViews,
            'likes'                    => $finalLikes,
            'comments'                 => $finalComments,
            'shares'                   => $finalShares,
            'metrics'                  => [
                'views'    => $finalViews,
                'likes'    => $finalLikes,
                'comments' => $finalComments,
                'shares'   => $finalShares,
            ],
            'data_api_stats'           => $dataApiStats,
            'analytics_api_stats'      => $analyticsMetrics,
        ];
    }

    /**
     * Helper to fetch total video statistics (views, likes, comments) from YouTube Data API v3.
     */
    public function getChannelVideoStatsFromDataApi(string $accessToken): array
    {
        try {
            $this->client->setAccessToken($accessToken);
            $youtube = new GoogleYouTube($this->client);

            $channelsRes = $youtube->channels->listChannels('contentDetails,statistics', ['mine' => true]);
            if (empty($channelsRes->getItems())) {
                return ['views' => 0, 'likes' => 0, 'comments' => 0];
            }

            $channel = $channelsRes->getItems()[0];
            $channelStats = $channel->getStatistics();
            $uploadsPlaylistId = $channel->getContentDetails()?->getRelatedPlaylists()?->getUploads();

            $totalViews = (int) ($channelStats->getViewCount() ?? 0);
            $totalLikes = 0;
            $totalComments = 0;

            if ($uploadsPlaylistId) {
                $playlistItemsRes = $youtube->playlistItems->listPlaylistItems('contentDetails', [
                    'playlistId' => $uploadsPlaylistId,
                    'maxResults' => 50,
                ]);

                $videoIds = [];
                foreach ($playlistItemsRes->getItems() as $item) {
                    $videoIds[] = $item->getContentDetails()->getVideoId();
                }

                if (!empty($videoIds)) {
                    $videosRes = $youtube->videos->listVideos('statistics', [
                        'id' => implode(',', $videoIds)
                    ]);

                    foreach ($videosRes->getItems() as $video) {
                        $stats = $video->getStatistics();
                        $totalLikes += (int) ($stats->getLikeCount() ?? 0);
                        $totalComments += (int) ($stats->getCommentCount() ?? 0);
                        if ($totalViews === 0) {
                            $totalViews += (int) ($stats->getViewCount() ?? 0);
                        }
                    }
                }
            }

            return [
                'views'    => $totalViews,
                'likes'    => $totalLikes,
                'comments' => $totalComments,
            ];
        } catch (Exception $e) {
            Log::warning('Data API v3 video stats fetch error', ['error' => $e->getMessage()]);
            return ['views' => 0, 'likes' => 0, 'comments' => 0];
        }
    }
}
