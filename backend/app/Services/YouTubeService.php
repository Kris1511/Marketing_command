<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\YouTube as GoogleYouTube;
use Google\Service\YouTubeAnalytics as GoogleYouTubeAnalytics;
use App\Models\YouTubeConnection;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
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

        // Requested OAuth Scopes - base profile + YouTube full permissions
        $this->client->setScopes([
            'openid',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
            'https://www.googleapis.com/auth/youtube',
            'https://www.googleapis.com/auth/youtube.force-ssl',
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/yt-analytics.readonly',
        ]);

        $this->client->setAccessType('offline');
        $this->client->setPrompt('select_account consent');
    }

    /**
     * Get Google OAuth redirect URL.
     */
    public function getAuthUrl(string $state, ?string $loginHint = null): string
    {
        $this->client->setState($state);
        if (!empty($loginHint)) {
            $this->client->setLoginHint($loginHint);
        }
        return $this->client->createAuthUrl();
    }

    /**
     * Get a clone of the configured Google Client for reuse by other services.
     */
    public function getConfiguredClient(): GoogleClient
    {
        return clone $this->client;
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
            // If access token is still valid, continue using it
            if (!empty($connection->access_token) && ($connection->token_expires_at === null || $connection->token_expires_at->isFuture())) {
                return $connection;
            }
            throw new Exception('YouTube refresh token is missing or revoked. Please reconnect your YouTube account.');
        }

        try {
            $newToken = $this->client->fetchAccessTokenWithRefreshToken($connection->refresh_token);

            if (isset($newToken['error'])) {
                $errDesc = $newToken['error_description'] ?? $newToken['error'];
                throw new Exception("Failed to refresh token: " . $errDesc);
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

            // Proactively sync fresh access token to unified integrations table
            try {
                $syncData = [
                    'access_token'      => $newToken['access_token'],
                    'is_connected'      => true,
                    'connection_status' => 'connected',
                    'last_sync_at'      => now(),
                ];
                if (!empty($newToken['refresh_token'])) {
                    $syncData['refresh_token'] = $newToken['refresh_token'];
                }
                Integration::where('workspace_id', $connection->workspace_id)
                    ->where('platform', 'youtube')
                    ->where('account_id', $connection->channel_id)
                    ->update($syncData);
            } catch (\Throwable $t) {
                Log::warning('YouTubeService: failed syncing refreshed token to integrations table: ' . $t->getMessage());
            }

            return $connection;
        } catch (Exception $e) {
            Log::error('YouTube token refresh failed', ['error' => $e->getMessage(), 'channel_id' => $connection->channel_id]);
            // If current access_token hasn't expired yet, continue using it rather than breaking immediately
            if (!empty($connection->access_token) && $connection->token_expires_at && $connection->token_expires_at->isFuture()) {
                Log::warning('Using existing YouTube access token despite refresh error: ' . $e->getMessage());
                return $connection;
            }
            throw new Exception('YouTube connection lost. Reauthorization required: ' . $e->getMessage());
        }
    }

    /**
     * Get YouTube Channel information using YouTube Data API v3.
     * Prioritizes the exact $channelId if provided.
     */
    public function getChannelInfo(string $accessToken, ?string $channelId = null): array
    {
        $this->client->setAccessToken($accessToken);
        $youtube = new GoogleYouTube($this->client);

        $response = null;
        if (!empty($channelId)) {
            try {
                $response = $youtube->channels->listChannels('snippet,statistics', ['id' => $channelId]);
            } catch (\Throwable $e) {
                Log::warning('YouTube getChannelInfo by id warning: ' . $e->getMessage());
            }
        }

        if (!$response || empty($response->getItems())) {
            $response = $youtube->channels->listChannels('snippet,statistics', ['mine' => true]);
        }

        if (empty($response->getItems())) {
            throw new Exception('No YouTube Channel found for this Google account.');
        }

        $channel = $response->getItems()[0];
        $snippet = $channel->getSnippet();
        $statistics = $channel->getStatistics();
        $thumbnails = $snippet->getThumbnails();
        $thumbnailUrl = $thumbnails?->getHigh() ? $thumbnails->getHigh()->getUrl() : ($thumbnails?->getDefault() ? $thumbnails->getDefault()->getUrl() : null);

        return [
            'channel_id'          => $channel->getId(),
            'channel_name'        => $snippet->getTitle(),
            'channel_description' => $snippet->getDescription(),
            'channel_thumbnail'   => $thumbnailUrl,
            'subscriber_count'    => (int) ($statistics?->getSubscriberCount() ?? 0),
            'video_count'         => (int) ($statistics?->getVideoCount() ?? 0),
            'view_count'          => (int) ($statistics?->getViewCount() ?? 0),
        ];
    }

    /**
     * Get list of all YouTube channels accessible by the authenticated Google account.
     */
    public function getChannelsList(string $accessToken): array
    {
        $this->client->setAccessToken($accessToken);
        $youtube = new GoogleYouTube($this->client);

        $response = $youtube->channels->listChannels('snippet,statistics', ['mine' => true]);
        $channels = [];

        foreach ($response->getItems() as $channel) {
            $snippet = $channel->getSnippet();
            $statistics = $channel->getStatistics();
            $thumbnails = $snippet->getThumbnails();
            $thumbnailUrl = $thumbnails?->getHigh() ? $thumbnails->getHigh()->getUrl() : ($thumbnails?->getDefault() ? $thumbnails->getDefault()->getUrl() : null);

            $channels[] = [
                'channel_id'          => $channel->getId(),
                'channel_name'        => $snippet->getTitle(),
                'channel_description' => $snippet->getDescription(),
                'channel_thumbnail'   => $thumbnailUrl,
                'subscriber_count'    => (int) ($statistics?->getSubscriberCount() ?? 0),
                'video_count'         => (int) ($statistics?->getVideoCount() ?? 0),
                'view_count'          => (int) ($statistics?->getViewCount() ?? 0),
            ];
        }

        return $channels;
    }

    /**
     * Save or update YouTube Channel in database.
     * Preserves existing records without deleting channels.
     */
    public function saveChannelConnection(?int $userId, array $tokens, array $channelData, int $workspaceId): YouTubeConnection
    {
        if (empty($workspaceId)) {
            throw new \InvalidArgumentException('A valid workspace_id is required to save a YouTube connection.');
        }

        $expiresIn = $tokens['expires_in'] ?? 3600;
        $expiresAt = now()->addSeconds($expiresIn);

        $updateData = [
            'user_id'             => $userId,
            'workspace_id'        => $workspaceId,
            'channel_name'        => $channelData['channel_name'],
            'channel_description' => $channelData['channel_description'],
            'channel_thumbnail'   => $channelData['channel_thumbnail'],
            'subscriber_count'    => $channelData['subscriber_count'],
            'video_count'         => $channelData['video_count'],
            'view_count'          => $channelData['view_count'],
            'access_token'        => $tokens['access_token'],
            'token_expires_at'    => $expiresAt,
        ];

        if (!empty($tokens['refresh_token'])) {
            $updateData['refresh_token'] = $tokens['refresh_token'];
        } else {
            // Retain existing refresh token if Google omitted it in this callback
            $existingRt = YouTubeConnection::where('channel_id', $channelData['channel_id'])
                ->whereNotNull('refresh_token')
                ->value('refresh_token');
            if ($existingRt) {
                $updateData['refresh_token'] = $existingRt;
            }
        }

        $connection = YouTubeConnection::updateOrCreate(
            ['channel_id' => $channelData['channel_id']],
            $updateData
        );

        // Permanently persist to unified integrations table with composite key
        try {
            $integData = [
                'account_name'      => $channelData['channel_name'],
                'access_token'      => $tokens['access_token'] ?? null,
                'is_connected'      => true,
                'connection_status' => 'connected',
                'followers_count'   => (int)($channelData['subscriber_count'] ?? 0),
                'last_sync_at'      => now(),
            ];
            if (!empty($connection->refresh_token)) {
                $integData['refresh_token'] = $connection->refresh_token;
            }
            Integration::updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'platform'     => 'youtube',
                    'account_id'   => $channelData['channel_id'],
                ],
                $integData
            );
        } catch (\Throwable $t) {
            Log::warning('YouTubeService: failed persisting YouTube to integrations table: ' . $t->getMessage());
        }

        return $connection;
    }

    /**
     * Upload a video to YouTube using chunked/resumable media upload.
     * Supports tags, category, custom thumbnail, and playlist assignment.
     */
    public function uploadVideo(
        YouTubeConnection $connection,
        string $videoPath,
        string $title,
        string $description,
        string $privacyStatus = 'public',
        ?array $tags = null,
        ?string $categoryId = '22',
        ?string $thumbnailPath = null,
        ?string $playlistId = null
    ): array {
        $connection = $this->refreshAccessTokenIfNeeded($connection);
        $this->client->setAccessToken($connection->access_token);

        $youtube = new GoogleYouTube($this->client);

        $video = new \Google\Service\YouTube\Video();

        $snippet = new \Google\Service\YouTube\VideoSnippet();
        $snippet->setTitle($title);
        $snippet->setDescription($description);
        if (!empty($tags)) {
            $snippet->setTags($tags);
        }
        if (!empty($categoryId)) {
            $snippet->setCategoryId($categoryId);
        }
        $video->setSnippet($snippet);

        $status = new \Google\Service\YouTube\VideoStatus();
        $status->setPrivacyStatus(in_array($privacyStatus, ['public', 'unlisted', 'private']) ? $privacyStatus : 'public');
        $video->setStatus($status);

        $this->client->setDefer(true);
        $statusResult = false;

        try {
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

            $handle = fopen($videoPath, 'rb');
            if ($handle === false) {
                throw new Exception("Unable to read video file at: {$videoPath}");
            }

            try {
                while (!$statusResult && !feof($handle)) {
                    $chunk = fread($handle, $chunkSizeBytes);
                    $statusResult = $media->nextChunk($chunk);
                }
            } finally {
                fclose($handle);
            }
        } finally {
            $this->client->setDefer(false);
        }

        if ($statusResult instanceof \Google\Service\YouTube\Video) {
            $uploadedVideoId = $statusResult->getId();

            // Upload custom thumbnail if provided
            $thumbnailUploaded = false;
            if (!empty($thumbnailPath) && file_exists($thumbnailPath)) {
                try {
                    $this->uploadThumbnail($connection, $uploadedVideoId, $thumbnailPath);
                    $thumbnailUploaded = true;
                } catch (\Throwable $te) {
                    Log::warning('YouTube custom thumbnail upload warning: ' . $te->getMessage());
                }
            }

            // Add to playlist if provided
            $playlistAdded = false;
            if (!empty($playlistId)) {
                try {
                    $this->addVideoToPlaylist($connection, $playlistId, $uploadedVideoId);
                    $playlistAdded = true;
                } catch (\Throwable $pe) {
                    Log::warning('YouTube add to playlist warning: ' . $pe->getMessage());
                }
            }

            return [
                'success'            => true,
                'id'                 => $uploadedVideoId,
                'video_id'           => $uploadedVideoId,
                'url'                => "https://www.youtube.com/watch?v={$uploadedVideoId}",
                'title'              => $title,
                'privacy_status'     => $privacyStatus,
                'thumbnail_uploaded' => $thumbnailUploaded,
                'playlist_added'     => $playlistAdded,
            ];
        }

        throw new Exception("YouTube upload failed or did not return video info.");
    }

    /**
     * Upload custom thumbnail image for a video.
     */
    public function uploadThumbnail(YouTubeConnection $connection, string $videoId, string $thumbnailPath): array
    {
        if (!file_exists($thumbnailPath)) {
            throw new Exception("Thumbnail file not found: {$thumbnailPath}");
        }

        $connection = $this->refreshAccessTokenIfNeeded($connection);
        $this->client->setAccessToken($connection->access_token);
        $youtube = new GoogleYouTube($this->client);

        $mimeType = mime_content_type($thumbnailPath) ?: 'image/jpeg';
        $fileSize = filesize($thumbnailPath);

        $this->client->setDefer(true);
        try {
            $setRequest = $youtube->thumbnails->set($videoId);
            $media = new \Google\Http\MediaFileUpload(
                $this->client,
                $setRequest,
                $mimeType,
                null,
                true,
                1 * 1024 * 1024
            );
            $media->setFileSize($fileSize);

            $statusResult = false;
            $handle = fopen($thumbnailPath, 'rb');
            try {
                while (!$statusResult && !feof($handle)) {
                    $chunk = fread($handle, 1 * 1024 * 1024);
                    $statusResult = $media->nextChunk($chunk);
                }
            } finally {
                fclose($handle);
            }
        } finally {
            $this->client->setDefer(false);
        }

        return [
            'success'  => true,
            'video_id' => $videoId,
            'message'  => 'Custom thumbnail uploaded successfully.',
        ];
    }

    /**
     * Retrieve full video details for a specific video ID.
     */
    public function getVideoDetails(YouTubeConnection $connection, string $videoId): array
    {
        $connection = $this->refreshAccessTokenIfNeeded($connection);
        $this->client->setAccessToken($connection->access_token);
        $youtube = new GoogleYouTube($this->client);

        $response = $youtube->videos->listVideos('snippet,statistics,contentDetails,status,player', ['id' => $videoId]);
        if (empty($response->getItems())) {
            throw new Exception("Video not found: {$videoId}");
        }

        $item = $response->getItems()[0];
        $snippet = $item->getSnippet();
        $stats = $item->getStatistics();
        $status = $item->getStatus();
        $content = $item->getContentDetails();
        $player = $item->getPlayer();

        return [
            'id'             => $item->getId(),
            'title'          => $snippet?->getTitle(),
            'description'    => $snippet?->getDescription(),
            'published_at'   => $snippet?->getPublishedAt(),
            'channel_id'     => $snippet?->getChannelId(),
            'channel_title'  => $snippet?->getChannelTitle(),
            'tags'           => $snippet?->getTags() ?? [],
            'category_id'    => $snippet?->getCategoryId(),
            'thumbnails'     => [
                'default'  => $snippet?->getThumbnails()?->getDefault()?->getUrl(),
                'medium'   => $snippet?->getThumbnails()?->getMedium()?->getUrl(),
                'high'     => $snippet?->getThumbnails()?->getHigh()?->getUrl(),
                'standard' => $snippet?->getThumbnails()?->getStandard()?->getUrl(),
                'maxres'   => $snippet?->getThumbnails()?->getMaxres()?->getUrl(),
            ],
            'views'          => (int)($stats?->getViewCount() ?? 0),
            'likes'          => (int)($stats?->getLikeCount() ?? 0),
            'comments'       => (int)($stats?->getCommentCount() ?? 0),
            'duration'       => $content?->getDuration(),
            'privacy_status' => $status?->getPrivacyStatus(),
            'upload_status'  => $status?->getUploadStatus(),
            'embed_html'     => $player?->getEmbedHtml(),
            'url'            => "https://www.youtube.com/watch?v={$item->getId()}",
        ];
    }

    /**
     * Get playlists belonging to the connected YouTube channel.
     */
    public function getPlaylists(YouTubeConnection $connection, int $maxResults = 50): array
    {
        $connection = $this->refreshAccessTokenIfNeeded($connection);
        $this->client->setAccessToken($connection->access_token);
        $youtube = new GoogleYouTube($this->client);

        $response = $youtube->playlists->listPlaylists('snippet,contentDetails,status', [
            'mine'       => true,
            'maxResults' => min($maxResults, 50),
        ]);

        $playlists = [];
        foreach ($response->getItems() as $pl) {
            $snippet = $pl->getSnippet();
            $content = $pl->getContentDetails();
            $status = $pl->getStatus();

            $playlists[] = [
                'id'             => $pl->getId(),
                'title'          => $snippet?->getTitle(),
                'description'    => $snippet?->getDescription(),
                'published_at'   => $snippet?->getPublishedAt(),
                'item_count'     => (int)($content?->getItemCount() ?? 0),
                'privacy_status' => $status?->getPrivacyStatus(),
                'thumbnail'      => $snippet?->getThumbnails()?->getHigh()?->getUrl() ?? $snippet?->getThumbnails()?->getDefault()?->getUrl(),
            ];
        }

        return $playlists;
    }

    /**
     * Create a new playlist on the connected YouTube channel.
     */
    public function createPlaylist(YouTubeConnection $connection, string $title, string $description = '', string $privacyStatus = 'public'): array
    {
        $connection = $this->refreshAccessTokenIfNeeded($connection);
        $this->client->setAccessToken($connection->access_token);
        $youtube = new GoogleYouTube($this->client);

        $playlist = new \Google\Service\YouTube\Playlist();

        $snippet = new \Google\Service\YouTube\PlaylistSnippet();
        $snippet->setTitle($title);
        $snippet->setDescription($description);
        $playlist->setSnippet($snippet);

        $status = new \Google\Service\YouTube\PlaylistStatus();
        $status->setPrivacyStatus(in_array($privacyStatus, ['public', 'unlisted', 'private']) ? $privacyStatus : 'public');
        $playlist->setStatus($status);

        $result = $youtube->playlists->insert('snippet,status', $playlist);

        return [
            'success'     => true,
            'id'          => $result->getId(),
            'title'       => $result->getSnippet()?->getTitle(),
            'description' => $result->getSnippet()?->getDescription(),
        ];
    }

    /**
     * Add a video to a playlist.
     */
    public function addVideoToPlaylist(YouTubeConnection $connection, string $playlistId, string $videoId): array
    {
        $connection = $this->refreshAccessTokenIfNeeded($connection);
        $this->client->setAccessToken($connection->access_token);
        $youtube = new GoogleYouTube($this->client);

        $playlistItem = new \Google\Service\YouTube\PlaylistItem();

        $snippet = new \Google\Service\YouTube\PlaylistItemSnippet();
        $snippet->setPlaylistId($playlistId);

        $resourceId = new \Google\Service\YouTube\ResourceId();
        $resourceId->setKind('youtube#video');
        $resourceId->setVideoId($videoId);
        $snippet->setResourceId($resourceId);

        $playlistItem->setSnippet($snippet);

        $result = $youtube->playlistItems->insert('snippet', $playlistItem);

        return [
            'success'          => true,
            'playlist_item_id' => $result->getId(),
            'playlist_id'      => $playlistId,
            'video_id'         => $videoId,
        ];
    }

    /**
     * Aggregate real-time video metrics (views, likes, comments) for the channel's videos.
     */
    public function getVideoMetrics(YouTubeConnection $connection, bool $forceRefresh = false): array
    {
        $cacheKey = "yt_video_metrics_" . $connection->channel_id;
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 120, function () use ($connection) {
            try {
                $connection = $this->refreshAccessTokenIfNeeded($connection);
                $this->client->setAccessToken($connection->access_token);
                $youtube = new GoogleYouTube($this->client);

                // 1. Get channel uploads playlist - verify exact connected channel ID
                $channelRes = !empty($connection->channel_id) 
                    ? $youtube->channels->listChannels('contentDetails,statistics', ['id' => $connection->channel_id])
                    : null;

                if (!$channelRes || empty($channelRes->getItems())) {
                    $channelRes = $youtube->channels->listChannels('contentDetails,statistics', ['mine' => true]);
                }

                if (empty($channelRes->getItems())) {
                    throw new Exception("Connected YouTube Channel '{$connection->channel_name}' ({$connection->channel_id}) not found on YouTube API.");
                }

                $channelItem = $channelRes->getItems()[0];
                $stats = $channelItem->getStatistics();
                $subscribers = (int)($stats ? $stats->getSubscriberCount() : $connection->subscriber_count);
                $channelViewCount = (int)($stats ? $stats->getViewCount() : $connection->view_count);
                $videoCount = (int)($stats ? $stats->getVideoCount() : $connection->video_count);

                $uploadsListId = $channelItem->getContentDetails()?->getRelatedPlaylists()?->getUploads();
                if (!$uploadsListId) {
                    return [
                        'views'       => $channelViewCount,
                        'likes'       => 0,
                        'comments'    => 0,
                        'shares'      => 0,
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

                // Also check channel comment threads for real-time comments across videos and community posts
                if (!empty($connection->channel_id)) {
                    try {
                        $threadParams = [
                            'allThreadsRelatedToChannelId' => $connection->channel_id,
                            'maxResults'                   => 100,
                        ];
                        $threadsRes = $youtube->commentThreads->listCommentThreads('snippet', $threadParams);
                        $threadCommentsCount = 0;
                        if (!empty($threadsRes->getItems())) {
                            foreach ($threadsRes->getItems() as $th) {
                                $threadCommentsCount += 1 + (int)($th->getSnippet()?->getTotalReplyCount() ?? 0);
                            }
                            $totalComments = max($totalComments, $threadCommentsCount);
                        }
                    } catch (\Throwable $cte) {
                        Log::info('getVideoMetrics channel commentThreads notice: ' . $cte->getMessage());
                    }
                }

                $finalViews = max($totalViews, $channelViewCount);

                $connection->update([
                    'view_count'       => $finalViews,
                    'subscriber_count' => $subscribers,
                    'video_count'      => max($videoCount, count($videoIds)),
                ]);

                return [
                    'views'       => $finalViews,
                    'likes'       => $totalLikes,
                    'comments'    => $totalComments,
                    'shares'      => 0,
                    'subscribers' => $subscribers,
                    'video_count' => max($videoCount, count($videoIds)),
                ];
            } catch (\Exception $e) {
                Log::warning('YouTube getVideoMetrics error', ['error' => $e->getMessage(), 'channel_id' => $connection->channel_id]);
                throw $e;
            }
        });
    }

    /**
     * Retrieve and cache YouTube channel statistics and upload playlist ID.
     * Caches for 30 minutes to prevent redundant channels->listChannels round trips.
     */
    public function getCachedChannelMeta(GoogleYouTube $youtube, YouTubeConnection $connection, bool $forceRefresh = false): array
    {
        $cacheKey = "yt_channel_meta_{$connection->channel_id}";
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 1800, function () use ($youtube, $connection) {
            try {
                $channelRes = !empty($connection->channel_id)
                    ? $youtube->channels->listChannels('contentDetails,statistics,snippet', ['id' => $connection->channel_id])
                    : null;

                if (!$channelRes || empty($channelRes->getItems())) {
                    $channelRes = $youtube->channels->listChannels('contentDetails,statistics,snippet', ['mine' => true]);
                }

                if (!empty($channelRes?->getItems())) {
                    $item = $channelRes->getItems()[0];
                    $stats = $item->getStatistics();
                    $snippet = $item->getSnippet();
                    $uploadsId = $item->getContentDetails()?->getRelatedPlaylists()?->getUploads();
                    if (!$uploadsId && !empty($connection->channel_id) && str_starts_with($connection->channel_id, 'UC')) {
                        $uploadsId = 'UU' . substr($connection->channel_id, 2);
                    }

                    return [
                        'channel_id'        => $item->getId() ?? $connection->channel_id,
                        'channel_name'      => $snippet?->getTitle() ?? $connection->channel_name,
                        'channel_thumbnail' => $snippet?->getThumbnails()?->getDefault()?->getUrl() ?? $connection->channel_thumbnail,
                        'subscriber_count'  => (int)($stats ? $stats->getSubscriberCount() : $connection->subscriber_count),
                        'video_count'       => (int)($stats ? $stats->getVideoCount() : $connection->video_count),
                        'view_count'        => (int)($stats ? $stats->getViewCount() : $connection->view_count),
                        'uploads_list_id'   => $uploadsId,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('YouTube getCachedChannelMeta warning: ' . $e->getMessage());
            }

            $uploadsId = (!empty($connection->channel_id) && str_starts_with($connection->channel_id, 'UC'))
                ? 'UU' . substr($connection->channel_id, 2)
                : null;

            return [
                'channel_id'        => $connection->channel_id,
                'channel_name'      => $connection->channel_name,
                'channel_thumbnail' => $connection->channel_thumbnail,
                'subscriber_count'  => (int)($connection->subscriber_count ?? 0),
                'video_count'       => (int)($connection->video_count ?? 0),
                'view_count'        => (int)($connection->view_count ?? 0),
                'uploads_list_id'   => $uploadsId,
            ];
        });
    }

    /**
     * Retrieve YouTube channel metrics filtered by date range.
     */
    public function getChannelAnalytics(
        YouTubeConnection $connection,
        ?string $startDate = null,
        ?string $endDate = null,
        bool $forceRefresh = false
    ): array {
        $startCarbon = !empty($startDate) ? \Carbon\Carbon::parse($startDate) : now()->subDays(29);
        $endCarbon   = !empty($endDate) ? \Carbon\Carbon::parse($endDate) : now();

        if ($startCarbon->gt($endCarbon)) {
            [$startCarbon, $endCarbon] = [$endCarbon, $startCarbon];
        }

        // Clamp future end date to today to prevent Google Analytics API 400 Bad Request
        if ($endCarbon->isFuture()) {
            $endCarbon = now();
        }

        $startStr = $startCarbon->format('Y-m-d');
        $endStr   = $endCarbon->format('Y-m-d');

        $cacheKey = "yt_analytics_ws_{$connection->workspace_id}_{$connection->channel_id}_{$startStr}_{$endStr}";
        if ($forceRefresh) {
            Cache::forget($cacheKey);
            Cache::forget("yt_video_metrics_" . $connection->channel_id);
            Cache::forget("yt_pub_posts_ws_{$connection->workspace_id}_{$connection->channel_id}_{$startStr}_{$endStr}");
            Cache::forget("yt_overview_ws_{$connection->workspace_id}_{$connection->channel_id}_{$startStr}_{$endStr}");
            Cache::forget("yt_content_raw_items_{$connection->workspace_id}_{$connection->channel_id}");
            Cache::forget("yt_channel_meta_{$connection->channel_id}");
            Cache::forget("yt_playlists_ch_{$connection->channel_id}");
            Cache::forget("yt_comments_fallback_{$connection->channel_id}_{$startStr}_{$endStr}");
        }

        return Cache::remember($cacheKey, 600, function () use ($connection, $startStr, $endStr, $forceRefresh) {
            // Build daily trend base structure
            $period = new \DatePeriod(
                new \DateTime($startStr),
                new \DateInterval('P1D'),
                (new \DateTime($endStr))->modify('+1 day')
            );
            $dailyTrend = [];
            foreach ($period as $dt) {
                $dStr = $dt->format('Y-m-d');
                $dailyTrend[$dStr] = [
                    'date'                   => $dStr,
                    'views'                  => 0,
                    'likes'                  => 0,
                    'comments'               => 0,
                    'shares'                 => 0,
                    'subscribers_gained'     => 0,
                    'subscribers_lost'       => 0,
                    'subscribers_net_change' => 0,
                    'watch_time_minutes'     => 0.0,
                    'watch_time_hours'       => 0.0,
                    'engagement'             => 0,
                ];
            }

            try {
                $connection = $this->refreshAccessTokenIfNeeded($connection);
            } catch (\Throwable $refreshErr) {
                Log::warning('YouTube getChannelAnalytics token refresh notice: ' . $refreshErr->getMessage());
                $errMsg = $refreshErr->getMessage();
                $isRevoked = str_contains($errMsg, 'invalid_grant') || str_contains($errMsg, 'revoked');
                
                // If token is not revoked and we still have an access token, proceed with existing token
                if (!$isRevoked && !empty($connection->access_token)) {
                    Log::info('Proceeding with existing access token despite transient refresh notice');
                } else {
                    return [
                        'success'                      => false,
                        'workspace_id'                 => $connection->workspace_id,
                        'channel_id'                   => $connection->channel_id,
                        'channel_name'                 => $connection->channel_name,
                        'start_date'                   => $startStr,
                        'end_date'                     => $endStr,
                        'views'                        => null,
                        'likes'                        => null,
                        'comments'                     => null,
                        'shares'                       => null,
                        'subscribers'                  => (int)($connection->subscriber_count ?? 0),
                        'subscribers_gained'           => null,
                        'subscribers_lost'             => null,
                        'subscribers_net_change'       => null,
                        'watch_time_minutes'           => 0.0,
                        'watch_time_hours'             => 0.0,
                        'average_view_duration_seconds'=> 0,
                        'videos'                       => (int)($connection->video_count ?? 0),
                        'published_videos_count'       => 0,
                        'lifetime_views'               => (int)($connection->view_count ?? 0),
                        'views_supported'              => false,
                        'likes_supported'              => false,
                        'comments_supported'           => false,
                        'shares_supported'             => false,
                        'daily_trend'                  => $dailyTrend,
                        'analytics_api_active'         => false,
                        'reauthorization_required'     => $isRevoked,
                        'error_type'                   => $isRevoked ? 'token_invalid' : 'network_error',
                        'error_message'                => $isRevoked ? 'YouTube token expired or revoked. Please reconnect your YouTube channel.' : 'Temporary network issue contacting YouTube. Please try again.',
                        'query_info'                   => null,
                        'data_source_description'      => 'YouTube Analytics API & YouTube Data API v3',
                        'analytics_api_activation_url' => null,
                    ];
                }
            }

            $this->client->setAccessToken($connection->access_token);
            $youtube = new GoogleYouTube($this->client);

            // 1. Fetch CURRENT channel statistics (cached 30m to eliminate redundant round trips)
            $channelMeta = $this->getCachedChannelMeta($youtube, $connection, $forceRefresh);
            $currentSubscribers   = (int)($channelMeta['subscriber_count'] ?? $connection->subscriber_count ?? 0);
            $channelLifetimeViews = (int)($channelMeta['view_count'] ?? $connection->view_count ?? 0);
            $videoCount           = (int)($channelMeta['video_count'] ?? $connection->video_count ?? 0);

            $views = 0;
            $likes = 0;
            $comments = 0;
            $shares = 0;
            $subscribersGained = 0;
            $subscribersLost = 0;
            $subscribersNetChange = 0;
            $watchTimeMinutes = 0.0;
            $watchTimeHours = 0.0;
            $averageViewDurationSeconds = 0;
            $analyticsApiActive = false;
            $errorType = null;
            $errorMessage = null;

            // 2. Query YouTube Analytics API for date-range metrics (including watch time)
            try {
                $analytics = new GoogleYouTubeAnalytics($this->client);
                $channelSelector = !empty($connection->channel_id) ? 'channel==' . $connection->channel_id : 'channel==MINE';
                $metricsString = 'views,likes,comments,shares,subscribersGained,subscribersLost,estimatedMinutesWatched,averageViewDuration';

                try {
                    $rep = $analytics->reports->query([
                        'ids'       => $channelSelector,
                        'startDate' => $startStr,
                        'endDate'   => $endStr,
                        'metrics'   => $metricsString,
                    ]);
                } catch (\Throwable $cqe) {
                    // Fallback without averageViewDuration if unsupported
                    $metricsString = 'views,likes,comments,shares,subscribersGained,subscribersLost,estimatedMinutesWatched';
                    try {
                        $rep = $analytics->reports->query([
                            'ids'       => $channelSelector,
                            'startDate' => $startStr,
                            'endDate'   => $endStr,
                            'metrics'   => $metricsString,
                        ]);
                    } catch (\Throwable $cqe2) {
                        if ($channelSelector !== 'channel==MINE') {
                            $channelSelector = 'channel==MINE';
                            $rep = $analytics->reports->query([
                                'ids'       => 'channel==MINE',
                                'startDate' => $startStr,
                                'endDate'   => $endStr,
                                'metrics'   => 'views,likes,comments,shares,subscribersGained,subscribersLost',
                            ]);
                        } else {
                            throw $cqe2;
                        }
                    }
                }

                if (!empty($rep->getRows())) {
                    $row = $rep->getRows()[0];
                    $views                = (int)($row[0] ?? 0);
                    $likes                = (int)($row[1] ?? 0);
                    $comments             = (int)($row[2] ?? 0);
                    $shares               = (int)($row[3] ?? 0);
                    $subscribersGained    = (int)($row[4] ?? 0);
                    $subscribersLost      = (int)($row[5] ?? 0);
                    $subscribersNetChange = $subscribersGained - $subscribersLost;
                    $watchTimeMinutes     = isset($row[6]) ? (float)$row[6] : 0.0;
                    $watchTimeHours       = round($watchTimeMinutes / 60, 2);
                    $averageViewDurationSeconds = isset($row[7]) ? (int)$row[7] : 0;
                }
                $analyticsApiActive = true;

                // Query daily trend
                try {
                    $trendRep = $analytics->reports->query([
                        'ids'        => $channelSelector,
                        'startDate'  => $startStr,
                        'endDate'    => $endStr,
                        'metrics'    => 'views,likes,comments,shares,subscribersGained,subscribersLost,estimatedMinutesWatched',
                        'dimensions' => 'day',
                        'sort'       => 'day',
                    ]);
                    if (!empty($trendRep->getRows())) {
                        foreach ($trendRep->getRows() as $tRow) {
                            $dayDate    = $tRow[0];
                            $tViews     = (int)($tRow[1] ?? 0);
                            $tLikes     = (int)($tRow[2] ?? 0);
                            $tComm      = (int)($tRow[3] ?? 0);
                            $tShares    = (int)($tRow[4] ?? 0);
                            $tSubGained = (int)($tRow[5] ?? 0);
                            $tSubLost   = (int)($tRow[6] ?? 0);
                            $tWatchMin  = isset($tRow[7]) ? (float)$tRow[7] : 0.0;
                            $tWatchHr   = round($tWatchMin / 60, 2);

                            $dailyTrend[$dayDate] = [
                                'date'                   => $dayDate,
                                'views'                  => $tViews,
                                'likes'                  => $tLikes,
                                'comments'               => $tComm,
                                'shares'                 => $tShares,
                                'subscribers_gained'     => $tSubGained,
                                'subscribers_lost'       => $tSubLost,
                                'subscribers_net_change' => ($tSubGained - $tSubLost),
                                'watch_time_minutes'     => $tWatchMin,
                                'watch_time_hours'       => $tWatchHr,
                                'engagement'             => ($tLikes + $tComm + $tShares),
                            ];
                        }
                    }
                } catch (\Throwable $te) {
                    Log::warning('YouTube Analytics daily trend query warning', ['error' => $te->getMessage(), 'channel_id' => $connection->channel_id]);
                }
            } catch (\Throwable $ae) {
                $errStr  = $ae->getMessage();
                $errCode = $ae->getCode();
                Log::error('YouTube Analytics API query error', ['error' => $errStr, 'code' => $errCode, 'channel_id' => $connection->channel_id]);

                if (str_contains($errStr, 'SERVICE_DISABLED') || str_contains($errStr, 'accessNotConfigured')) {
                    $errorType = 'analytics_api_disabled';
                    $errorMessage = 'YouTube Analytics API is not enabled in your Google Cloud Project. Please enable it in Google Cloud Console.';
                } elseif (str_contains($errStr, 'invalid_grant') || $errCode === 401 || str_contains($errStr, '401')) {
                    $errorType = 'token_invalid';
                    $errorMessage = 'OAuth access token has expired or is invalid. Please re-authenticate the YouTube connection.';
                } elseif (str_contains($errStr, 'insufficientPermissions') || str_contains($errStr, 'ACCESS_TOKEN_SCOPE_INSUFFICIENT') || ($errCode === 403 && str_contains($errStr, 'scope'))) {
                    $errorType = 'insufficient_scope';
                    $errorMessage = 'OAuth credentials lack the yt-analytics.readonly scope. Please reconnect the YouTube channel.';
                } elseif ($errCode === 400 || str_contains($errStr, 'badRequest')) {
                    $errorType = 'query_error';
                    $errorMessage = 'YouTube Analytics API query error: ' . $errStr;
                } else {
                    $errorType = 'api_error';
                    $errorMessage = 'YouTube Analytics API request failed: ' . $errStr;
                }

                $views                = null;
                $likes                = null;
                $comments             = null;
                $shares               = null;
                $subscribersGained    = null;
                $subscribersLost      = null;
                $subscribersNetChange = null;
                $watchTimeMinutes     = 0.0;
                $watchTimeHours       = 0.0;
            }

            // Update connection stats in DB with current channel live stats
            $connection->update([
                'subscriber_count' => $currentSubscribers,
                'view_count'       => $channelLifetimeViews,
                'video_count'      => $videoCount,
            ]);

            // 3. Real-Time Comments Aggregation (YouTube Data API v3)
            // PERFORMANCE OPTIMIZATION: Skip heavy commentThreads scan if Analytics API already provided valid comment metrics
            $channelCommentsCount = 0;
            $channelCommentsMap = [];
            $channelCommentsSupported = false;

            // Only query commentThreads if Analytics API failed or returned 0/null and channel_id exists
            if (!$analyticsApiActive && !empty($connection->channel_id)) {
                $commentsCacheKey = "yt_comments_fallback_{$connection->channel_id}_{$startStr}_{$endStr}";
                $cachedFallback = Cache::remember($commentsCacheKey, 900, function () use ($youtube, $connection, $startStr, $endStr) {
                    try {
                        $threadParams = [
                            'allThreadsRelatedToChannelId' => $connection->channel_id,
                            'maxResults'                   => 50,
                            'order'                        => 'time',
                        ];
                        $threadsRes = $youtube->commentThreads->listCommentThreads('snippet', $threadParams);
                        $threads = $threadsRes->getItems();
                        $count = 0;
                        $map = [];
                        $appTz = config('app.timezone', 'UTC');

                        if (!empty($threads)) {
                            foreach ($threads as $thread) {
                                $top = $thread->getSnippet()?->getTopLevelComment();
                                if (!$top) continue;
                                $publishedAt = $top->getSnippet()?->getPublishedAt();
                                if (!$publishedAt) continue;

                                $cCarbon = \Carbon\Carbon::parse($publishedAt)->setTimezone($appTz);
                                $cDate = $cCarbon->format('Y-m-d');
                                $replyCount = (int)($thread->getSnippet()?->getTotalReplyCount() ?? 0);
                                $threadTotal = 1 + $replyCount;

                                if ($cDate >= $startStr && $cDate <= $endStr) {
                                    $count += $threadTotal;
                                    $map[$cDate] = ($map[$cDate] ?? 0) + $threadTotal;
                                }
                            }
                        }
                        return ['count' => $count, 'map' => $map, 'supported' => true];
                    } catch (\Throwable $cte) {
                        Log::info('YouTube commentThreads fallback notice: ' . $cte->getMessage());
                        return ['count' => 0, 'map' => [], 'supported' => false];
                    }
                });

                $channelCommentsCount = $cachedFallback['count'] ?? 0;
                $channelCommentsMap = $cachedFallback['map'] ?? [];
                $channelCommentsSupported = $cachedFallback['supported'] ?? false;
            }

            // 4. Period Metrics & Live Totals Resolution
            // Period Comments: prioritize exact Data API commentThreads count if Analytics API is delayed or 0
            if ($channelCommentsSupported && ($comments === null || $comments === 0) && $channelCommentsCount > 0) {
                $comments = $channelCommentsCount;
                $commentsSupported = true;
                foreach ($dailyTrend as $dayDate => &$trendItem) {
                    $cOverride = $channelCommentsMap[$dayDate] ?? 0;
                    if ($cOverride > $trendItem['comments']) {
                        $trendItem['comments'] = $cOverride;
                        $trendItem['engagement'] = $trendItem['likes'] + $cOverride + $trendItem['shares'];
                    }
                }
            } else {
                $commentsSupported = $analyticsApiActive || $channelCommentsSupported;
            }

            // Recalculate daily trend total engagement
            foreach ($dailyTrend as $dayDate => &$trendItem) {
                $trendItem['engagement'] = (int)($trendItem['likes'] ?? 0) + (int)($trendItem['comments'] ?? 0) + (int)($trendItem['shares'] ?? 0);
            }

            $publishedVideosCount = $this->getPublishedVideosCount($connection, $startStr, $endStr, $forceRefresh);

            return [
                'success'                      => $analyticsApiActive || $channelCommentsSupported,
                'workspace_id'                 => $connection->workspace_id,
                'channel_id'                   => $connection->channel_id,
                'channel_name'                 => $connection->channel_name,
                'start_date'                   => $startStr,
                'end_date'                     => $endStr,
                // Period Metrics (Activity during selected date range)
                'views'                        => $views !== null ? (int)$views : 0,
                'likes'                        => $likes !== null ? (int)$likes : 0,
                'comments'                     => $comments !== null ? (int)$comments : 0,
                'shares'                       => $shares !== null ? (int)$shares : 0,
                'subscribers_gained'           => $subscribersGained !== null ? (int)$subscribersGained : 0,
                'subscribers_lost'             => $subscribersLost !== null ? (int)$subscribersLost : 0,
                'subscribers_net_change'       => $subscribersNetChange !== null ? (int)$subscribersNetChange : 0,
                'watch_time_minutes'           => $watchTimeMinutes,
                'watch_time_hours'             => $watchTimeHours,
                'average_view_duration_seconds'=> $averageViewDurationSeconds,
                'published_videos_count'       => (int)$publishedVideosCount,
                // Current Totals (Live Channel Status)
                'subscribers'                  => $currentSubscribers,
                'lifetime_views'               => $channelLifetimeViews,
                'videos'                       => $videoCount,
                // Capability flags
                'views_supported'              => $analyticsApiActive,
                'likes_supported'              => $analyticsApiActive,
                'comments_supported'           => $commentsSupported,
                'shares_supported'             => $analyticsApiActive,
                'daily_trend'                  => $dailyTrend,
                'analytics_api_active'         => $analyticsApiActive,
                'reauthorization_required'     => (in_array($errorType, ['token_invalid', 'insufficient_scope']) && empty($connection->refresh_token)),
                'error_type'                   => $errorType,
                'error_message'                => $errorMessage,
                'query_info'                   => [
                    'ids'        => $channelSelector,
                    'startDate'  => $startStr,
                    'endDate'    => $endStr,
                    'metrics'    => 'views,likes,comments,shares,subscribersGained,subscribersLost,estimatedMinutesWatched',
                    'channel_id' => $connection->channel_id,
                ],
                'data_source_description'      => 'YouTube Analytics API (period metrics & watch time) & YouTube Data API v3 (real-time channel totals & comments)',
                'analytics_api_activation_url' => 'https://console.developers.google.com/apis/api/youtubeanalytics.googleapis.com/overview',
            ];
        });
    }

    /**
     * Retrieve the count of videos/Shorts published by the connected YouTube channel within [startDate, endDate].
     */
    public function getPublishedVideosCount(YouTubeConnection $connection, string $startDate, string $endDate, bool $forceRefresh = false): int
    {
        $cacheKey = "yt_pub_posts_ws_{$connection->workspace_id}_{$connection->channel_id}_{$startDate}_{$endDate}";
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return (int)Cache::remember($cacheKey, 300, function () use ($connection, $startDate, $endDate) {
            // FAST PATH: If raw content items are already cached, count in memory (0ms)
            $rawCacheKey = "yt_content_raw_items_{$connection->workspace_id}_{$connection->channel_id}";
            $cachedRaw = Cache::get($rawCacheKey);
            if ($cachedRaw && !empty($cachedRaw['content_items'])) {
                $count = 0;
                foreach ($cachedRaw['content_items'] as $item) {
                    $pub = $item['published_at'] ?? null;
                    if ($pub) {
                        $vDate = substr($pub, 0, 10);
                        if ($vDate >= $startDate && $vDate <= $endDate) {
                            $count++;
                        }
                    }
                }
                return $count;
            }

            try {
                $this->client->setAccessToken($connection->access_token);
                $youtube = new GoogleYouTube($this->client);

                $channelMeta = $this->getCachedChannelMeta($youtube, $connection);
                $uploadsListId = $channelMeta['uploads_list_id'] ?? null;
                if (!$uploadsListId) {
                    return 0;
                }

                $publishedCount = 0;
                $nextPageToken = null;

                do {
                    $playlistItems = $youtube->playlistItems->listPlaylistItems('snippet', [
                        'playlistId' => $uploadsListId,
                        'maxResults' => 50,
                        'pageToken'  => $nextPageToken,
                    ]);

                    foreach ($playlistItems->getItems() as $item) {
                        $publishedAt = $item->getSnippet()?->getPublishedAt();
                        if ($publishedAt) {
                            $vDate = substr($publishedAt, 0, 10);
                            if ($vDate >= $startDate && $vDate <= $endDate) {
                                $publishedCount++;
                            } elseif ($vDate < $startDate) {
                                break 2;
                            }
                        }
                    }

                    $nextPageToken = $playlistItems->getNextPageToken();
                } while ($nextPageToken);

                return $publishedCount;
            } catch (\Throwable $e) {
                Log::warning('YouTube getPublishedVideosCount error', ['error' => $e->getMessage(), 'channel_id' => $connection->channel_id]);
                return 0;
            }
        });
    }

    /**
     * Parse ISO 8601 duration string (e.g. PT1M25S) to total seconds.
     */
    public function parseIso8601Duration(?string $iso): int
    {
        if (empty($iso)) return 0;
        try {
            $interval = new \DateInterval($iso);
            return ($interval->d * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
        } catch (\Throwable $e) {
            if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $matches)) {
                $hours = isset($matches[1]) ? (int)$matches[1] : 0;
                $minutes = isset($matches[2]) ? (int)$matches[2] : 0;
                $seconds = isset($matches[3]) ? (int)$matches[3] : 0;
                return ($hours * 3600) + ($minutes * 60) + $seconds;
            }
            return 0;
        }
    }

    /**
     * Retrieve YouTube channel content items (Videos, Shorts, Live, Playlists, Posts)
     * with real YouTube Data API v3 statistics.
     */
    public function getChannelContent(
        YouTubeConnection $connection,
        string $type = 'all',
        int $maxResults = 50,
        bool $forceRefresh = false,
        ?int $cacheTtl = null
    ): array {
        $ttl = ($cacheTtl !== null && $cacheTtl > 0) ? $cacheTtl : 600;
        $rawCacheKey = "yt_content_raw_items_{$connection->workspace_id}_{$connection->channel_id}";
        if ($forceRefresh) {
            Cache::forget($rawCacheKey);
            Cache::forget("yt_content_ws_{$connection->workspace_id}_{$connection->channel_id}_{$type}_{$maxResults}");
            Cache::forget("yt_overview_ws_{$connection->workspace_id}_{$connection->channel_id}_all_all");
            Cache::forget("yt_channel_meta_{$connection->channel_id}");
            Cache::forget("yt_playlists_ch_{$connection->channel_id}");
        }

        $rawResult = Cache::remember($rawCacheKey, $ttl, function () use ($connection, $forceRefresh, $type) {
            try {
                $connection = $this->refreshAccessTokenIfNeeded($connection);
            } catch (\Throwable $refreshErr) {
                Log::warning('YouTube getChannelContent token refresh notice: ' . $refreshErr->getMessage());
                return [
                    'success'                  => false,
                    'reauthorization_required' => true,
                    'error_type'               => 'token_invalid',
                    'message'                  => 'YouTube token expired or revoked. Please reconnect your YouTube channel.',
                    'channel'                  => [
                        'channel_id'        => $connection->channel_id,
                        'channel_name'      => $connection->channel_name,
                        'channel_thumbnail' => $connection->channel_thumbnail,
                    ],
                    'content_items'            => [],
                    'playlist_items'           => [],
                    'posts_items'              => [],
                    'counts'                   => [
                        'all'       => 0,
                        'videos'    => 0,
                        'shorts'    => 0,
                        'live'      => 0,
                        'playlists' => 0,
                        'posts'     => 0,
                    ],
                ];
            }

            $this->client->setAccessToken($connection->access_token);
            $youtube = new GoogleYouTube($this->client);

            // 1. Get channel details & uploads playlist (cached 30m to avoid redundant round trip)
            $channelMeta = $this->getCachedChannelMeta($youtube, $connection, $forceRefresh);
            $uploadsListId = $channelMeta['uploads_list_id'] ?? null;

            $contentItems = [];
            $videoCount = 0;
            $shortsCount = 0;
            $liveCount = 0;

            // 2. Fetch Videos & Shorts from Uploads Playlist
            if ($uploadsListId) {
                try {
                    $videoIds = [];
                    $nextPageToken = null;
                    $targetLimit = 100;

                    // Paginate through uploads playlist until target limit reached or all items fetched
                    do {
                        $params = [
                            'playlistId' => $uploadsListId,
                            'maxResults' => min(50, $targetLimit - count($videoIds)),
                        ];
                        if ($nextPageToken) {
                            $params['pageToken'] = $nextPageToken;
                        }
                        $playlistItemsRes = $youtube->playlistItems->listPlaylistItems('snippet,contentDetails', $params);
                        foreach ($playlistItemsRes->getItems() as $item) {
                            $vId = $item->getContentDetails()?->getVideoId();
                            if ($vId && !in_array($vId, $videoIds, true)) {
                                $videoIds[] = $vId;
                            }
                        }
                        $nextPageToken = $playlistItemsRes->getNextPageToken();
                    } while ($nextPageToken && count($videoIds) < $targetLimit);

                    if (!empty($videoIds)) {
                        $chunks = array_chunk($videoIds, 50);
                        foreach ($chunks as $chunk) {
                            $videosRes = $youtube->videos->listVideos('snippet,contentDetails,statistics,status,liveStreamingDetails', [
                                'id' => implode(',', $chunk),
                            ]);

                            foreach ($videosRes->getItems() as $v) {
                                $vSnip = $v->getSnippet();
                                $vStats = $v->getStatistics();
                                $vContent = $v->getContentDetails();
                                $vStatus = $v->getStatus();

                                $durIso = $vContent?->getDuration() ?? 'PT0S';
                                $durSec = $this->parseIso8601Duration($durIso);

                                $liveBroadcastContent = $vSnip?->getLiveBroadcastContent() ?? 'none';
                                $isCurrentlyLive = ($liveBroadcastContent === 'live' || $liveBroadcastContent === 'upcoming');

                                // Check for #short / #shorts tags in title or description
                                $titleLower = strtolower($vSnip?->getTitle() ?? '');
                                $descLower = strtolower($vSnip?->getDescription() ?? '');
                                $hasShortsTag = str_contains($titleLower, '#short') || str_contains($descLower, '#short');

                                $isShort = !$isCurrentlyLive && (($durSec > 0 && $durSec <= 60) || ($durSec > 0 && $durSec <= 180 && $hasShortsTag));
                                $isPastLongStream = ($v->getLiveStreamingDetails() !== null) && !$isShort;
                                $isLive = $isCurrentlyLive || $isPastLongStream;

                                $itemType = $isCurrentlyLive ? 'live' : ($isShort ? 'short' : ($isLive ? 'live' : 'video'));

                                if ($itemType === 'video') $videoCount++;
                                elseif ($itemType === 'short') $shortsCount++;
                                elseif ($itemType === 'live') $liveCount++;

                                $thumbs = $vSnip?->getThumbnails();
                                $thumbUrl = $thumbs?->getMedium()?->getUrl()
                                    ?? $thumbs?->getStandard()?->getUrl()
                                    ?? $thumbs?->getDefault()?->getUrl()
                                    ?? '';

                                $contentItems[] = [
                                    'id'           => $v->getId(),
                                    'title'        => $vSnip?->getTitle() ?? 'Untitled',
                                    'description'  => $vSnip?->getDescription() ?? '',
                                    'thumbnail'    => $thumbUrl,
                                    'published_at' => $vSnip?->getPublishedAt(),
                                    'visibility'   => $vStatus?->getPrivacyStatus() ?? 'public',
                                    'views'        => (int)($vStats?->getViewCount() ?? 0),
                                    'likes'        => (int)($vStats?->getLikeCount() ?? 0),
                                    'comments'     => (int)($vStats?->getCommentCount() ?? 0),
                                    'duration'     => $durIso,
                                    'duration_sec' => $durSec,
                                    'type'         => $itemType,
                                    'url'          => "https://www.youtube.com/watch?v={$v->getId()}",
                                ];
                            }
                        }
                    }
                } catch (\Throwable $ve) {
                    Log::warning('YouTube getChannelContent videos list warning: ' . $ve->getMessage());
                }
            }

            // 3. Fetch Playlists (cached 30m, only when requested)
            $rawPlaylistsList = [];
            $playlistsCount = 0;
            if (in_array($type, ['all', 'playlists'], true)) {
                try {
                    $playlistCacheKey = "yt_playlists_ch_{$connection->channel_id}";
                    $rawPlaylists = Cache::remember($playlistCacheKey, 1800, function () use ($connection) {
                        return $this->getPlaylists($connection, 50);
                    });
                    $playlistsCount = count($rawPlaylists);
                    foreach ($rawPlaylists as $pl) {
                        $rawPlaylistsList[] = [
                            'id'           => $pl['id'],
                            'title'        => $pl['title'] ?? 'Untitled Playlist',
                            'description'  => $pl['description'] ?? '',
                            'thumbnail'    => $pl['thumbnail'] ?? '',
                            'published_at' => $pl['published_at'] ?? null,
                            'visibility'   => $pl['privacy_status'] ?? 'public',
                            'views'        => null,
                            'likes'        => null,
                            'comments'     => null,
                            'duration'     => null,
                            'duration_sec' => 0,
                            'type'         => 'playlist',
                            'item_count'   => $pl['item_count'] ?? 0,
                            'url'          => "https://www.youtube.com/playlist?list={$pl['id']}",
                        ];
                    }
                } catch (\Throwable $pe) {
                    Log::warning('YouTube getChannelContent playlists warning: ' . $pe->getMessage());
                }
            }

            // 4. Fetch Community Posts (only when requested)
            $communityPosts = [];
            if (in_array($type, ['all', 'posts'], true)) {
                try {
                    $communityPosts = $this->getChannelCommunityPosts($connection, false);
                } catch (\Throwable $postErr) {
                    Log::info('YouTube community posts notice: ' . $postErr->getMessage());
                }
            }
            $postsCount = count($communityPosts);

            return [
                'success'                  => true,
                'reauthorization_required' => false,
                'error_type'               => null,
                'message'                  => 'Channel content retrieved successfully.',
                'channel'                  => $channelMeta,
                'content_items'            => $contentItems,
                'playlist_items'           => $rawPlaylistsList,
                'posts_items'              => $communityPosts,
                'counts'                   => [
                    'all'       => count($contentItems) + $playlistsCount + $postsCount,
                    'videos'    => $videoCount,
                    'shorts'    => $shortsCount,
                    'live'      => $liveCount,
                    'playlists' => $playlistsCount,
                    'posts'     => $postsCount,
                ],
            ];
        });

        if (!$rawResult || !($rawResult['success'] ?? false)) {
            return $rawResult ?: [
                'success'                  => false,
                'reauthorization_required' => false,
                'error_type'               => 'error',
                'message'                  => 'Failed to retrieve channel content.',
                'items'                    => [],
                'counts'                   => ['all' => 0, 'videos' => 0, 'shorts' => 0, 'live' => 0, 'playlists' => 0, 'posts' => 0],
            ];
        }

        $contentItems = $rawResult['content_items'] ?? [];
        $playlistItems = $rawResult['playlist_items'] ?? [];
        $communityPosts = $rawResult['posts_items'] ?? [];

        // Filter items based on selected tab/type in memory (0ms)
        $filteredItems = match ($type) {
            'videos'    => array_values(array_filter($contentItems, fn($it) => $it['type'] === 'video')),
            'shorts'    => array_values(array_filter($contentItems, fn($it) => $it['type'] === 'short')),
            'live'      => array_values(array_filter($contentItems, fn($it) => $it['type'] === 'live')),
            'playlists' => $playlistItems,
            'posts'     => $communityPosts,
            default     => array_merge($contentItems, $playlistItems, $communityPosts),
        };

        // Sort items by published_at DESC (newest first)
        usort($filteredItems, function ($a, $b) {
            $ta = !empty($a['published_at']) ? strtotime($a['published_at']) : 0;
            $tb = !empty($b['published_at']) ? strtotime($b['published_at']) : 0;
            return $tb <=> $ta;
        });

        if ($maxResults > 0 && count($filteredItems) > $maxResults) {
            $filteredItems = array_slice($filteredItems, 0, $maxResults);
        }

        return [
            'success'                  => true,
            'reauthorization_required' => false,
            'error_type'               => null,
            'message'                  => 'Channel content retrieved successfully.',
            'channel'                  => $rawResult['channel'] ?? [],
            'items'                    => $filteredItems,
            'counts'                   => $rawResult['counts'] ?? [
                'all'       => count($contentItems) + count($playlistItems) + count($communityPosts),
                'videos'    => count(array_filter($contentItems, fn($it) => $it['type'] === 'video')),
                'shorts'    => count(array_filter($contentItems, fn($it) => $it['type'] === 'short')),
                'live'      => count(array_filter($contentItems, fn($it) => $it['type'] === 'live')),
                'playlists' => count($playlistItems),
                'posts'     => count($communityPosts),
            ],
        ];
    }

    /**
     * Retrieve YouTube Analytics/Overview metrics for a connection.
     */
    public function getAnalyticsOverview(YouTubeConnection $connection, ?string $startDate = null, ?string $endDate = null, bool $forceRefresh = false, ?int $cacheTtl = null): array
    {
        $ttl = ($cacheTtl !== null && $cacheTtl > 0) ? $cacheTtl : 300;
        $cacheKey = "yt_overview_ws_{$connection->workspace_id}_{$connection->channel_id}_" . ($startDate ?: 'all') . "_" . ($endDate ?: 'all') . ($ttl < 300 ? "_{$ttl}" : "");
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, $ttl, function () use ($connection, $startDate, $endDate, $forceRefresh) {
            $metrics = $this->getChannelAnalytics($connection, $startDate, $endDate, $forceRefresh);

            // 1. Determine views in the last 48 hours from actual channel content & Analytics daily trend
            $recentVideos48h = [];
            $recentVideosViews = 0;
            try {
                // PERFORMANCE OPTIMIZATION: Reuse cached raw content items without triggering duplicate uncached crawl
                $rawCacheKey = "yt_content_raw_items_{$connection->workspace_id}_{$connection->channel_id}";
                $cachedRaw = Cache::get($rawCacheKey);
                $items = [];
                if ($cachedRaw && !empty($cachedRaw['content_items'])) {
                    $items = array_slice($cachedRaw['content_items'], 0, 20);
                } else {
                    $contentData = $this->getChannelContent($connection, 'videos', 20, false, $ttl);
                    $items = $contentData['items'] ?? [];
                }

                $cutoff48h = now()->subHours(48);
                foreach ($items as $item) {
                    if (empty($item['published_at'])) continue;
                    $pubDate = \Carbon\Carbon::parse($item['published_at']);
                    $vViews = (int)($item['views'] ?? 0);
                    // If published within the last 48 hours (or recent within 3 days with views)
                    if ($pubDate->gte($cutoff48h) || ($pubDate->gte(now()->subDays(3)) && $vViews > 0)) {
                        $recentVideosViews += $vViews;
                        $recentVideos48h[] = [
                            'id'           => $item['id'] ?? '',
                            'video_id'     => $item['video_id'] ?? $item['id'] ?? '',
                            'title'        => $item['title'] ?? 'Untitled Video',
                            'thumbnail'    => $item['thumbnail'] ?? '',
                            'views'        => $vViews,
                            'published_at' => $item['published_at'],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::info('YouTube 48h content lookup notice: ' . $e->getMessage());
            }

            // B) Check views from Analytics API daily_trend for the latest 2 days
            $analyticsViews48h = 0;
            if (!empty($metrics['daily_trend']) && is_array($metrics['daily_trend'])) {
                $trendList = array_values($metrics['daily_trend']);
                $count = count($trendList);
                if ($count >= 2) {
                    $analyticsViews48h = (int)($trendList[$count - 1]['views'] ?? 0) + (int)($trendList[$count - 2]['views'] ?? 0);
                } elseif ($count === 1) {
                    $analyticsViews48h = (int)($trendList[0]['views'] ?? 0);
                }
            }

            // Take max of analytics views and recent video views (never showing fake 0 when views occurred!)
            $total48hViews = max($analyticsViews48h, $recentVideosViews);
            if ($total48hViews === 0 && !empty($recentVideos48h)) {
                $total48hViews = array_sum(array_column($recentVideos48h, 'views'));
            }

            // C) Generate 48 hourly buckets from 48 hours ago to current hour
            $hourlyBuckets = [];
            $now = now();
            for ($i = 47; $i >= 0; $i--) {
                $hourTime = (clone $now)->subHours($i);
                $hourKey = $hourTime->format('Y-m-d H:00');
                $label = $hourTime->format('M j, g A');
                $hourlyBuckets[$hourKey] = [
                    'hour_offset' => $i,
                    'datetime'    => $hourTime->toIso8601String(),
                    'hour_key'    => $hourKey,
                    'label'       => $label,
                    'views'       => 0,
                ];
            }

            // Distribute views into the hourly buckets based on recent video publish hour and activity
            if ($total48hViews > 0) {
                $remaining = $total48hViews;
                $activeHourKeys = array_keys($hourlyBuckets);
                $indicesToPopulate = [];
                if (!empty($recentVideos48h)) {
                    foreach ($recentVideos48h as $rv) {
                        $rvPub = \Carbon\Carbon::parse($rv['published_at']);
                        $diffHours = (int)$now->diffInHours($rvPub);
                        if ($diffHours >= 0 && $diffHours < 48) {
                            $targetIndex = 47 - $diffHours;
                            $indicesToPopulate[] = max(0, min(47, $targetIndex));
                            if ($targetIndex + 1 < 48) $indicesToPopulate[] = $targetIndex + 1;
                        }
                    }
                }

                if (empty($indicesToPopulate)) {
                    $indicesToPopulate = [47, 46, 45, 44, 43, 42, 41, 40, 39, 38];
                }

                $indicesToPopulate = array_values(array_unique($indicesToPopulate));
                $countPop = count($indicesToPopulate);
                $basePerSlot = (int)floor($total48hViews / $countPop);
                $rem = $total48hViews % $countPop;

                foreach ($indicesToPopulate as $idx => $slotIdx) {
                    $k = $activeHourKeys[$slotIdx] ?? null;
                    if ($k && isset($hourlyBuckets[$k])) {
                        $hourlyBuckets[$k]['views'] = $basePerSlot + ($idx < $rem ? 1 : 0);
                    }
                }
            }

            $hourlyViewsList = array_values($hourlyBuckets);

            // Sort top videos in last 48h by views DESC
            usort($recentVideos48h, fn($a, $b) => ($b['views'] ?? 0) <=> ($a['views'] ?? 0));
            $topVideos48h = array_slice($recentVideos48h, 0, 5);

            $currentSubscribers = (int)($metrics['subscribers'] ?? $connection->subscriber_count ?? 0);

            $realtime48h = [
                'total_views'       => $total48hViews,
                'subscribers'       => $currentSubscribers,
                'hourly_views'      => $hourlyViewsList,
                'top_videos'        => $topVideos48h,
                'recent_videos_count' => count($recentVideos48h),
                'window_hours'      => 48,
                'start_time'        => $now->copy()->subHours(48)->toIso8601String(),
                'end_time'          => $now->toIso8601String(),
            ];

            return [
                'success'                       => $metrics['success'],
                'workspace_id'                  => $connection->workspace_id,
                'channel_id'                    => $connection->channel_id,
                'channel_name'                  => $connection->channel_name,
                'channel_thumbnail'             => $connection->channel_thumbnail,
                'subscriber_count'              => $currentSubscribers,
                'video_count'                   => $metrics['videos'],
                'views'                         => $metrics['views'],
                'likes'                         => $metrics['likes'],
                'comments'                      => $metrics['comments'],
                'shares'                        => $metrics['shares'],
                'subscribers_gained'            => $metrics['subscribers_gained'],
                'subscribers_lost'              => $metrics['subscribers_lost'],
                'subscribers_net_change'        => $metrics['subscribers_net_change'],
                'watch_time_minutes'            => $metrics['watch_time_minutes'],
                'watch_time_hours'              => $metrics['watch_time_hours'],
                'average_view_duration_seconds' => $metrics['average_view_duration_seconds'],
                'published_videos_count'        => $metrics['published_videos_count'] ?? 0,
                'lifetime_views'                => $metrics['lifetime_views'],
                'views_last_48h'                => $total48hViews,
                'realtime_48h'                  => $realtime48h,
                'start_date'                    => $metrics['start_date'],
                'end_date'                      => $metrics['end_date'],
                'daily_trend'                   => $metrics['daily_trend'] ?? [],
                'error_type'                    => $metrics['error_type'],
                'error_message'                 => $metrics['error_message'],
                'reauthorization_required'      => (in_array($metrics['error_type'], ['token_invalid', 'insufficient_scope']) && empty($connection->refresh_token)),
                'message'                       => $metrics['success'] ? 'YouTube metrics retrieved successfully from YouTube Analytics API.' : ($metrics['error_message'] ?? 'Failed to retrieve metrics.'),
            ];
        });
    }

    /**
     * Retrieve YouTube Channel Community Posts.
     */
    public function getChannelCommunityPosts(YouTubeConnection $connection, bool $forceRefresh = false): array
    {
        $channelId = $connection->channel_id;
        if (empty($channelId)) {
            return [];
        }

        $cacheKey = "yt_posts_ch_{$channelId}";
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 600, function () use ($channelId) {
            try {
                $url = "https://www.youtube.com/channel/{$channelId}/posts";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 6);
                curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36");
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Accept-Language: en-US,en;q=0.9",
                    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"
                ]);
                $html = curl_exec($ch);

                if (!$html || !preg_match('/ytInitialData\s*=\s*(\{.+?\});\s*<\/script>/s', $html, $m)) {
                    return [];
                }

                $data = json_decode($m[1], true);
                if (!is_array($data)) {
                    return [];
                }

                $posts = [];
                $findRenderers = function ($arr) use (&$findRenderers, &$posts) {
                    if (!is_array($arr)) return;
                    foreach ($arr as $k => $v) {
                        if ($k === 'backstagePostRenderer' || $k === 'sharedPostRenderer') {
                            $renderer = $v;
                            if ($k === 'sharedPostRenderer' && isset($v['originalPost']['backstagePostRenderer'])) {
                                $renderer = $v['originalPost']['backstagePostRenderer'];
                            }

                            $postId = $renderer['postId'] ?? '';
                            if (!$postId) continue;

                            $text = '';
                            if (isset($renderer['contentText']['runs'])) {
                                foreach ($renderer['contentText']['runs'] as $run) {
                                    $text .= $run['text'] ?? '';
                                }
                            }
                            $published = $renderer['publishedTimeText']['runs'][0]['text'] ?? '';
                            $likesText = $renderer['voteCount']['simpleText'] ?? $renderer['voteCount']['runs'][0]['text'] ?? '0';
                            $likes = is_numeric($likesText) ? (int)$likesText : 0;

                            $commentsText = $renderer['actionButtons']['commentActionButtonsRenderer']['replyButton']['buttonRenderer']['text']['simpleText'] ?? '0';
                            $comments = is_numeric($commentsText) ? (int)$commentsText : 0;

                            $thumbnail = '';
                            if (isset($renderer['backstageAttachment']['backstageImageRenderer']['image']['thumbnails'])) {
                                $thumbs = $renderer['backstageAttachment']['backstageImageRenderer']['image']['thumbnails'];
                                $thumbnail = end($thumbs)['url'] ?? '';
                            }

                            $posts[] = [
                                'id'           => $postId,
                                'title'        => !empty($text) ? $text : 'YouTube Community Post',
                                'description'  => $text,
                                'thumbnail'    => $thumbnail,
                                'published_at' => $published,
                                'visibility'   => 'public',
                                'views'        => null,
                                'likes'        => $likes,
                                'comments'     => $comments,
                                'duration'     => null,
                                'duration_sec' => 0,
                                'type'         => 'post',
                                'url'          => "https://www.youtube.com/post/{$postId}",
                            ];
                        } elseif (is_array($v)) {
                            $findRenderers($v);
                        }
                    }
                };

                $findRenderers($data);
                return $posts;
            } catch (\Throwable $e) {
                Log::warning('YouTube getChannelCommunityPosts notice: ' . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Retrieve video details, performance analytics, and audience retention curve
     * for a specific YouTube video ID.
     */
    public function getVideoAnalyticsAndRetention(
        YouTubeConnection $connection,
        string $videoId,
        ?string $startDate = null,
        ?string $endDate = null,
        bool $forceRefresh = false
    ): array {
        $cacheKey = "yt_video_retention_{$connection->workspace_id}_{$videoId}_" . ($startDate ?: 'all') . "_" . ($endDate ?: 'all');
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 600, function () use ($connection, $videoId, $startDate, $endDate, $forceRefresh) {
            $connection = $this->refreshAccessTokenIfNeeded($connection);
            $this->client->setAccessToken($connection->access_token);

            $youtube = new GoogleYouTube($this->client);
            $analytics = new GoogleYouTubeAnalytics($this->client);

            // Fast path: check if video metadata is already in raw items cache from getChannelContent
            $rawCacheKey = "yt_content_raw_items_{$connection->workspace_id}_{$connection->channel_id}";
            $cachedRaw = Cache::get($rawCacheKey);
            $existingItem = null;
            if ($cachedRaw && !empty($cachedRaw['content_items'])) {
                foreach ($cachedRaw['content_items'] as $it) {
                    if (($it['id'] ?? '') === $videoId || ($it['video_id'] ?? '') === $videoId) {
                        $existingItem = $it;
                        break;
                    }
                }
            }

            if ($existingItem && !$forceRefresh) {
                $durSec = (int)($existingItem['duration_sec'] ?? 0);
                $durFormatted = $this->formatDurationSeconds($durSec);
                $durIso = $existingItem['duration'] ?? 'PT0S';
                $isShort = ($existingItem['type'] ?? '') === 'short';

                $meta = [
                    'video_id'           => $videoId,
                    'title'              => $existingItem['title'] ?? 'Untitled',
                    'description'        => $existingItem['description'] ?? '',
                    'thumbnail'          => $existingItem['thumbnail'] ?? '',
                    'published_at'       => $existingItem['published_at'] ?? null,
                    'duration_seconds'   => $durSec,
                    'duration_formatted' => $durFormatted,
                    'duration_iso'       => $durIso,
                    'is_short'           => $isShort,
                    'type'               => $existingItem['type'] ?? ($isShort ? 'short' : 'video'),
                    'views'              => (int)($existingItem['views'] ?? 0),
                    'likes'              => (int)($existingItem['likes'] ?? 0),
                    'comments'           => (int)($existingItem['comments'] ?? 0),
                    'url'                => $existingItem['url'] ?? "https://www.youtube.com/watch?v={$videoId}",
                ];
            } else {
                // 1. Fetch Video Metadata from YouTube Data API v3 (with auto-refresh retry on 401)
                try {
                    $videoRes = $youtube->videos->listVideos('snippet,contentDetails,statistics,status', [
                        'id' => $videoId,
                    ]);
                } catch (\Google\Service\Exception $e) {
                    if ($e->getCode() == 401 && !empty($connection->refresh_token)) {
                        $newToken = $this->client->fetchAccessTokenWithRefreshToken($connection->refresh_token);
                        if (!isset($newToken['error']) && !empty($newToken['access_token'])) {
                            $connection->update([
                                'access_token'     => $newToken['access_token'],
                                'token_expires_at' => now()->addSeconds($newToken['expires_in'] ?? 3600),
                            ]);
                            $this->client->setAccessToken($newToken['access_token']);
                            $youtube = new GoogleYouTube($this->client);
                            $analytics = new GoogleYouTubeAnalytics($this->client);
                            $videoRes = $youtube->videos->listVideos('snippet,contentDetails,statistics,status', [
                                'id' => $videoId,
                            ]);
                        } else {
                            throw $e;
                        }
                    } else {
                        throw $e;
                    }
                }

                if (empty($videoRes->getItems())) {
                    throw new Exception("Video with ID '{$videoId}' not found on YouTube.");
                }

                $v = $videoRes->getItems()[0];
                $snip = $v->getSnippet();
                $stats = $v->getStatistics();
                $content = $v->getContentDetails();
                $status = $v->getStatus();

                $durIso = $content?->getDuration() ?? 'PT0S';
                $durSec = $this->parseIso8601Duration($durIso);
                $durFormatted = $this->formatDurationSeconds($durSec);

                $thumbs = $snip?->getThumbnails();
                $thumbUrl = $thumbs?->getMaxres()?->getUrl()
                    ?? $thumbs?->getHigh()?->getUrl()
                    ?? $thumbs?->getMedium()?->getUrl()
                    ?? $thumbs?->getDefault()?->getUrl()
                    ?? '';

                $titleLower = strtolower($snip?->getTitle() ?? '');
                $descLower = strtolower($snip?->getDescription() ?? '');
                $hasShortsTag = str_contains($titleLower, '#short') || str_contains($descLower, '#short');
                $isShort = ($durSec > 0 && $durSec <= 60) || ($durSec > 0 && $durSec <= 180 && $hasShortsTag);

                $meta = [
                    'video_id'           => $videoId,
                    'title'              => $snip?->getTitle() ?? 'Untitled',
                    'description'        => $snip?->getDescription() ?? '',
                    'thumbnail'          => $thumbUrl,
                    'published_at'       => $snip?->getPublishedAt(),
                    'duration_seconds'   => $durSec,
                    'duration_formatted' => $durFormatted,
                    'duration_iso'       => $durIso,
                    'is_short'           => $isShort,
                    'type'               => $isShort ? 'short' : 'video',
                    'views'              => (int)($stats?->getViewCount() ?? 0),
                    'likes'              => (int)($stats?->getLikeCount() ?? 0),
                    'comments'           => (int)($stats?->getCommentCount() ?? 0),
                    'url'                => "https://www.youtube.com/watch?v={$videoId}",
                ];
            }

            // 2. Fetch Video Analytics from YouTube Analytics API
            $startStr = $startDate ?: '2020-01-01';
            $endStr = $endDate ?: date('Y-m-d');

            $analyticsViews = null;
            $watchTimeMinutes = null;
            $averageViewDuration = null;
            $averageViewPercentage = null;
            $subscribersGained = null;
            $likes = null;
            $comments = null;
            $shares = null;
            $engagedViews = null;

            try {
                $rep = $analytics->reports->query([
                    'ids'        => 'channel==MINE',
                    'startDate'  => $startStr,
                    'endDate'    => $endStr,
                    'metrics'    => 'views,estimatedMinutesWatched,averageViewDuration,averageViewPercentage,subscribersGained,likes,comments,shares,engagedViews',
                    'filters'    => "video=={$videoId}",
                ]);

                $rows = $rep->getRows();
                if (!empty($rows) && !empty($rows[0])) {
                    $row = $rows[0];
                    $analyticsViews        = (int)($row[0] ?? 0);
                    $watchTimeMinutes      = (float)($row[1] ?? 0.0);
                    $averageViewDuration   = (int)($row[2] ?? 0);
                    $averageViewPercentage = (float)($row[3] ?? 0.0);
                    $subscribersGained     = (int)($row[4] ?? 0);
                    $likes                 = (int)($row[5] ?? 0);
                    $comments              = (int)($row[6] ?? 0);
                    $shares                = (int)($row[7] ?? 0);
                    $engagedViews          = isset($row[8]) ? (int)$row[8] : null;
                }
            } catch (\Throwable $e) {
                Log::warning("YouTube Analytics query warning for video {$videoId}: " . $e->getMessage());
            }

            // 3. Fetch Audience Retention Curve from YouTube Analytics API
            $retentionCurve = [];
            $hasRetentionData = false;
            $stayedToWatchPercentage = null;
            $stayedToWatchLabel = 'at 0:30';

            try {
                $retRep = $analytics->reports->query([
                    'ids'        => 'channel==MINE',
                    'startDate'  => $startStr,
                    'endDate'    => $endStr,
                    'dimensions' => 'elapsedVideoTimeRatio',
                    'metrics'    => 'audienceWatchRatio',
                    'filters'    => "video=={$videoId}",
                ]);

                $retRows = $retRep->getRows() ?: [];
                if (!empty($retRows)) {
                    $hasRetentionData = true;
                    foreach ($retRows as $r) {
                        $ratio = (float)$r[0];
                        $watchRatio = (float)$r[1];
                        $timeSec = (int)round($ratio * $durSec);
                        $retentionCurve[] = [
                            'ratio'                => $ratio,
                            'timestamp_seconds'    => $timeSec,
                            'timestamp_formatted'  => $this->formatDurationSeconds($timeSec),
                            'watch_ratio'          => $watchRatio,
                            'retention_percentage' => round($watchRatio * 100, 1),
                        ];
                    }

                    if (!$isShort && $durSec >= 30) {
                        $targetRatio = 30.0 / $durSec;
                        $stayedToWatchLabel = 'at 0:30';

                        $closestRow = null;
                        $minDiff = 999;
                        foreach ($retentionCurve as $pt) {
                            $diff = abs($pt['ratio'] - $targetRatio);
                            if ($diff < $minDiff) {
                                $minDiff = $diff;
                                $closestRow = $pt;
                            }
                        }

                        if ($closestRow) {
                            $stayedToWatchPercentage = $closestRow['retention_percentage'];
                        }
                    } else {
                        // Official YouTube Analytics API does not expose Shorts feed swipe/stayed-to-watch metric
                        $stayedToWatchPercentage = null;
                        $stayedToWatchLabel = 'Studio-only metric (Not in API)';
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("YouTube Retention query warning for video {$videoId}: " . $e->getMessage());
            }

            // 4. Fetch Traffic Sources & YouTube Search Terms from YouTube Analytics API
            $searchTermsData = [
                'has_data'          => false,
                'total_views'       => 0,
                'search_views'      => 0,
                'search_proportion' => 0.0,
                'terms'             => [],
            ];

            try {
                // A. Query traffic sources to find search views vs total traffic views
                $trafficRep = $analytics->reports->query([
                    'ids'        => 'channel==MINE',
                    'startDate'  => $startStr,
                    'endDate'    => $endStr,
                    'metrics'    => 'views',
                    'dimensions' => 'insightTrafficSourceType',
                    'filters'    => "video=={$videoId}",
                ]);

                $trafficRows = $trafficRep->getRows() ?: [];
                $totViews = 0;
                $sViews = 0;
                foreach ($trafficRows as $tr) {
                    $cnt = (int)($tr[1] ?? 0);
                    $totViews += $cnt;
                    if (($tr[0] ?? '') === 'YT_SEARCH') {
                        $sViews = $cnt;
                    }
                }

                $searchProportion = $totViews > 0 ? round(($sViews / $totViews) * 100, 1) : 0.0;

                // B. Query top search terms for this video
                $searchRep = $analytics->reports->query([
                    'ids'        => 'channel==MINE',
                    'startDate'  => $startStr,
                    'endDate'    => $endStr,
                    'metrics'    => 'views',
                    'dimensions' => 'insightTrafficSourceDetail',
                    'filters'    => "video=={$videoId};insightTrafficSourceType==YT_SEARCH",
                    'maxResults' => 20,
                    'sort'       => '-views',
                ]);

                $termsRows = $searchRep->getRows() ?: [];
                $termsList = [];

                $sumTermViews = 0;
                foreach ($termsRows as $row) {
                    $sumTermViews += (int)($row[1] ?? 0);
                }
                $baseSearchViews = $sViews > 0 ? $sViews : ($sumTermViews > 0 ? $sumTermViews : 1);

                foreach ($termsRows as $row) {
                    $termText = (string)($row[0] ?? '');
                    $termViews = (int)($row[1] ?? 0);
                    $termPct = round(($termViews / $baseSearchViews) * 100, 1);
                    $termsList[] = [
                        'term'       => $termText,
                        'views'      => $termViews,
                        'percentage' => $termPct,
                    ];
                }

                $searchTermsData = [
                    'has_data'          => count($termsList) > 0 || $sViews > 0,
                    'total_views'       => $totViews,
                    'search_views'      => $sViews,
                    'search_proportion' => $searchProportion,
                    'terms'             => $termsList,
                ];
            } catch (\Throwable $te) {
                Log::warning("YouTube Search Terms query warning for video {$videoId}: " . $te->getMessage());
            }

            $effectiveViews = $analyticsViews !== null && $analyticsViews > 0 ? $analyticsViews : $meta['views'];
            $engagedPercentage = ($engagedViews !== null && $effectiveViews > 0)
                ? round(($engagedViews / $effectiveViews) * 100, 1)
                : null;

            $avgDurationFormatted = $this->formatDurationSeconds($averageViewDuration ?: 0);

            return [
                'success'                    => true,
                'video'                      => $meta,
                'analytics'                  => [
                    'views'                          => $effectiveViews,
                    'watch_time_minutes'             => $watchTimeMinutes ?: round((($meta['views'] * ($averageViewDuration ?: 10)) / 60), 1),
                    'watch_time_hours'               => round(($watchTimeMinutes ?: (($meta['views'] * ($averageViewDuration ?: 10)) / 60)) / 60, 2),
                    'average_view_duration_seconds'  => $averageViewDuration,
                    'average_view_duration_formatted'=> $avgDurationFormatted,
                    'average_view_percentage'        => $averageViewPercentage,
                    'subscribers_gained'             => $subscribersGained ?: 0,
                    'likes'                          => $likes !== null && $likes > 0 ? $likes : $meta['likes'],
                    'comments'                       => $comments !== null && $comments > 0 ? $comments : $meta['comments'],
                    'shares'                         => $shares ?: 0,
                    'engaged_views'                  => $engagedViews,
                    'engaged_views_percentage'       => $engagedPercentage,
                ],
                'retention'                  => [
                    'has_retention_data'         => $hasRetentionData,
                    'stayed_to_watch_percentage' => $stayedToWatchPercentage,
                    'stayed_to_watch_label'      => $stayedToWatchLabel,
                    'engaged_views'              => $engagedViews,
                    'engaged_views_percentage'   => $engagedPercentage,
                    'curve'                      => $retentionCurve,
                ],
                'search_terms'               => $searchTermsData,
            ];
        });
    }

    /**
     * Helper to format seconds into M:SS or H:MM:SS
     */
    public function formatDurationSeconds(int $seconds): string
    {
        if ($seconds <= 0) return '0:00';
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }
        return sprintf('%d:%02d', $m, $s);
    }
}
