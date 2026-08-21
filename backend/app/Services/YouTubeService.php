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
        $this->client = new GoogleClient();
        
        $clientId = config('services.google.client_id', env('GOOGLE_CLIENT_ID', env('YOUTUBE_CLIENT_ID')));
        $clientSecret = config('services.google.client_secret', env('GOOGLE_CLIENT_SECRET', env('YOUTUBE_CLIENT_SECRET')));
        $redirectUri = config('services.google.redirect_uri', env('GOOGLE_REDIRECT_URI', env('YOUTUBE_REDIRECT_URI', 'http://localhost:8000/api/youtube/callback')));

        $caPath = 'C:\\PHP\\extras\\ssl\\cacert.pem';
        if (file_exists($caPath)) {
            $this->client->setHttpClient(new \GuzzleHttp\Client(['verify' => $caPath]));
        }

        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        $this->client->setRedirectUri($redirectUri);

        // Requested OAuth Scopes - including force-ssl for commentThreads & replies
        $this->client->setScopes([
            'https://www.googleapis.com/auth/youtube',
            'https://www.googleapis.com/auth/youtube.force-ssl',
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/youtube.upload',
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
     * Aggregate real-time video metrics (views, likes, comments) for the channel's videos.
     */
    public function getVideoMetrics(YouTubeConnection $connection, bool $forceRefresh = false): array
    {
        $cacheKey = "yt_video_metrics_" . $connection->channel_id;
        if ($forceRefresh) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
        }

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 120, function () use ($connection) {
            try {
                $connection = $this->refreshAccessTokenIfNeeded($connection);
                $this->client->setAccessToken($connection->access_token);
                $youtube = new GoogleYouTube($this->client);

                // 1. Get channel uploads playlist
                $channelRes = $youtube->channels->listChannels('contentDetails,statistics', ['mine' => true]);
                if (empty($channelRes->getItems())) {
                    return [
                        'views' => (int)($connection->view_count ?? 0),
                        'likes' => 0,
                        'comments' => 0,
                        'shares' => 0,
                        'subscribers' => (int)($connection->subscriber_count ?? 0),
                        'video_count' => (int)($connection->video_count ?? 0),
                    ];
                }

                $channelItem = $channelRes->getItems()[0];
                $stats = $channelItem->getStatistics();
                $subscribers = (int)($stats ? $stats->getSubscriberCount() : $connection->subscriber_count);
                $channelViewCount = (int)($stats ? $stats->getViewCount() : $connection->view_count);
                $videoCount = (int)($stats ? $stats->getVideoCount() : $connection->video_count);

                $uploadsListId = $channelItem->getContentDetails()?->getRelatedPlaylists()?->getUploads();
                if (!$uploadsListId) {
                    return [
                        'views' => $channelViewCount,
                        'likes' => 0,
                        'comments' => 0,
                        'shares' => 0,
                        'subscribers' => $subscribers,
                        'video_count' => $videoCount,
                    ];
                }

                // 2. Get playlist items (up to 50 latest videos)
                $playlistItems = $youtube->playlistItems->listPlaylistItems('contentDetails', [
                    'playlistId' => $uploadsListId,
                    'maxResults' => 50,
                ]);

                $videoIds = [];
                foreach ($playlistItems->getItems() as $item) {
                    $vId = $item->getContentDetails()?->getVideoId();
                    if ($vId) {
                        $videoIds[] = $vId;
                    }
                }

                $totalViews = 0;
                $totalLikes = 0;
                $totalComments = 0;

                if (!empty($videoIds)) {
                    $videosRes = $youtube->videos->listVideos('statistics', [
                        'id' => implode(',', $videoIds)
                    ]);

                    foreach ($videosRes->getItems() as $v) {
                        $vStats = $v->getStatistics();
                        if ($vStats) {
                            $totalViews += (int)$vStats->getViewCount();
                            $totalLikes += (int)$vStats->getLikeCount();
                            $totalComments += (int)$vStats->getCommentCount();
                        }
                    }
                }

                // Use max of aggregated video views or channel level views
                $finalViews = max($totalViews, $channelViewCount);

                // Update connection stats in DB
                $connection->update([
                    'view_count' => $finalViews,
                    'subscriber_count' => $subscribers,
                    'video_count' => max($videoCount, count($videoIds)),
                ]);

                return [
                    'views' => $finalViews,
                    'likes' => $totalLikes,
                    'comments' => $totalComments,
                    'shares' => 0,
                    'subscribers' => $subscribers,
                    'video_count' => max($videoCount, count($videoIds)),
                ];
            } catch (\Exception $e) {
                Log::warning('YouTube getVideoMetrics error', ['error' => $e->getMessage()]);
                return [
                    'views' => (int)($connection->view_count ?? 0),
                    'likes' => 0,
                    'comments' => 0,
                    'shares' => 0,
                    'subscribers' => (int)($connection->subscriber_count ?? 0),
                    'video_count' => (int)($connection->video_count ?? 0),
                ];
            }
        });
    }
}
