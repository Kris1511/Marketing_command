<?php

namespace App\Services;

use App\Models\FacebookPage;
use App\Models\Integration;
use App\Models\YouTubeConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use GuzzleHttp\Client as GuzzleClient;
use Google\Client as GoogleClient;
use Google\Service\YouTube as GoogleYouTube;

class CommentNotificationService
{
    protected GuzzleClient $httpClient;

    public function __construct()
    {
        $caPath = 'C:\\PHP\\extras\\ssl\\cacert.pem';
        $options = ['timeout' => 5];
        if (file_exists($caPath)) {
            $options['verify'] = $caPath;
        }
        $this->httpClient = new GuzzleClient($options);
    }

    /**
     * Sync comments from Facebook, Instagram, and YouTube into notifications.
     */
    public function syncAll(?int $workspaceId = null): void
    {
        $this->syncFacebookComments($workspaceId);
        $this->syncInstagramComments($workspaceId);
        $this->syncYouTubeComments($workspaceId);
    }

    /**
     * Sync Facebook Page post comments.
     */
    public function syncFacebookComments(?int $workspaceId = null): void
    {
        try {
            $query = FacebookPage::query();
            if ($workspaceId) {
                $query->where('workspace_id', $workspaceId);
            }
            $query->where(function ($q) {
                $q->whereNotIn('token_status', ['disconnected', 'invalid'])->orWhereNull('token_status');
            });
            $pages = $query->get();

            foreach ($pages as $page) {
                if (empty($page->page_access_token) || empty($page->page_id)) {
                    continue;
                }

                // Process each Facebook page in isolated try-catch
                try {
                    $token = $page->page_access_token;
                    $pageId = $page->page_id;

                    // Always ensure page is subscribed to feed webhook events so comment
                    // notifications are pushed to the backend in real-time.
                    $this->ensurePageSubscribedToFeedWebhook($pageId, $token);

                    // 1. Try published posts with comments
                    try {
                        $url = "https://graph.facebook.com/v23.0/{$pageId}/published_posts";
                        $res = $this->httpClient->get($url, [
                            'query' => [
                                'fields'       => 'id,message,created_time,comments{id,message,from,created_time}',
                                'limit'        => 25,
                                'access_token' => $token,
                            ]
                        ]);

                        $data = json_decode($res->getBody(), true);
                        foreach ($data['data'] ?? [] as $post) {
                            $postId = $post['id'] ?? null;
                            if (empty($post['comments']['data'])) continue;

                            foreach ($post['comments']['data'] as $c) {
                                $commentId = $c['id'] ?? null;
                                if (!$commentId) continue;

                                $relKey = "facebook_comment:{$commentId}" . ($postId ? ":{$postId}" : "");
                                $exists = DB::table('notifications')
                                    ->where('type', 'facebook_comment')
                                    ->where('related_entity', 'LIKE', "%{$commentId}%")
                                    ->exists();
                                if ($exists) continue;

                                $author = $c['from']['name'] ?? 'Someone';
                                $msg = trim($c['message'] ?? '');
                                if (empty($msg)) $msg = 'Left a comment';
                                $createdAt = !empty($c['created_time']) ? Carbon::parse($c['created_time']) : now();

                                DB::table('notifications')->insert([
                                    'user_id'        => $page->user_id ?? 1,
                                    'workspace_id'   => $page->workspace_id ?? 1,
                                    'type'           => 'facebook_comment',
                                    'title'          => 'Facebook Comment',
                                    'message'        => "{$author} commented on your Facebook post\n\n\"{$msg}\"",
                                    'related_entity' => $relKey,
                                    'is_read'        => false,
                                    'created_at'     => $createdAt,
                                    'updated_at'     => now(),
                                ]);
                            }
                        }
                    } catch (\Throwable $ppe) {
                        Log::info('Facebook published_posts comments check notice', ['page_id' => $pageId, 'note' => $ppe->getMessage()]);
                    }

                    // 2. Fetch comments from uploaded photos (accessible with standard pages_read_engagement)
                    try {
                        $photosRes = $this->httpClient->get("https://graph.facebook.com/v23.0/{$pageId}/photos", [
                            'query' => [
                                'type'         => 'uploaded',
                                'fields'       => 'id,name,created_time,picture,source,link,comments{id,message,from,created_time}',
                                'limit'        => 25,
                                'access_token' => $token,
                            ]
                        ]);
                        $photosData = json_decode($photosRes->getBody(), true);
                        foreach ($photosData['data'] ?? [] as $photo) {
                            $photoId = $photo['id'] ?? null;
                            if ($photoId) {
                                $pThumb = $photo['source'] ?? $photo['picture'] ?? null;
                                if ($pThumb) {
                                    \Illuminate\Support\Facades\Cache::put("fb_post_pic_{$photoId}", $pThumb, 86400 * 7);
                                }
                                if (!empty($photo['name'])) {
                                    \Illuminate\Support\Facades\Cache::put("fb_post_title_{$photoId}", strtok($photo['name'], "\n"), 86400 * 7);
                                }
                                if (!empty($photo['link'])) {
                                    \Illuminate\Support\Facades\Cache::put("fb_post_url_{$photoId}", $photo['link'], 86400 * 7);
                                }
                            }

                            foreach ($photo['comments']['data'] ?? [] as $c) {
                                $commentId = $c['id'] ?? null;
                                if (!$commentId) continue;

                                $relKey = "facebook_comment:{$commentId}" . ($photoId ? ":{$photoId}" : "");
                                $exists = DB::table('notifications')
                                    ->where('type', 'facebook_comment')
                                    ->where('related_entity', 'LIKE', "%{$commentId}%")
                                    ->exists();
                                if ($exists) continue;

                                $author = $c['from']['name'] ?? 'Someone';
                                $msg = trim($c['message'] ?? '');
                                if (empty($msg)) $msg = 'Left a comment';
                                $createdAt = !empty($c['created_time']) ? Carbon::parse($c['created_time']) : now();

                                DB::table('notifications')->insert([
                                    'user_id'        => $page->user_id ?? 1,
                                    'workspace_id'   => $page->workspace_id ?? 1,
                                    'type'           => 'facebook_comment',
                                    'title'          => 'Facebook Comment',
                                    'message'        => "{$author} commented on your Facebook photo\n\n\"{$msg}\"",
                                    'related_entity' => $relKey,
                                    'is_read'        => false,
                                    'created_at'     => $createdAt,
                                    'updated_at'     => now(),
                                ]);
                            }
                        }
                    } catch (\Throwable $phe) {
                        Log::info('Facebook photos comments check notice', ['page_id' => $pageId, 'note' => $phe->getMessage()]);
                    }

                    // 3. Fetch comments from uploaded videos (accessible with standard pages_read_engagement)
                    try {
                        $videosRes = $this->httpClient->get("https://graph.facebook.com/v23.0/{$pageId}/videos", [
                            'query' => [
                                'fields'       => 'id,description,created_time,picture,permalink_url,comments{id,message,from,created_time}',
                                'limit'        => 25,
                                'access_token' => $token,
                            ]
                        ]);
                        $videosData = json_decode($videosRes->getBody(), true);
                        foreach ($videosData['data'] ?? [] as $vid) {
                            $videoId = $vid['id'] ?? null;
                            if ($videoId) {
                                $vThumb = $vid['picture'] ?? null;
                                if ($vThumb) {
                                    \Illuminate\Support\Facades\Cache::put("fb_post_pic_{$videoId}", $vThumb, 86400 * 7);
                                }
                                if (!empty($vid['description'])) {
                                    \Illuminate\Support\Facades\Cache::put("fb_post_title_{$videoId}", strtok($vid['description'], "\n"), 86400 * 7);
                                }
                                if (!empty($vid['permalink_url'])) {
                                    \Illuminate\Support\Facades\Cache::put("fb_post_url_{$videoId}", "https://www.facebook.com" . $vid['permalink_url'], 86400 * 7);
                                }
                            }

                            foreach ($vid['comments']['data'] ?? [] as $c) {
                                $commentId = $c['id'] ?? null;
                                if (!$commentId) continue;

                                $relKey = "facebook_comment:{$commentId}" . ($videoId ? ":{$videoId}" : "");
                                $exists = DB::table('notifications')
                                    ->where('type', 'facebook_comment')
                                    ->where('related_entity', 'LIKE', "%{$commentId}%")
                                    ->exists();
                                if ($exists) continue;

                                $author = $c['from']['name'] ?? 'Someone';
                                $msg = trim($c['message'] ?? '');
                                if (empty($msg)) $msg = 'Left a comment';
                                $createdAt = !empty($c['created_time']) ? Carbon::parse($c['created_time']) : now();

                                DB::table('notifications')->insert([
                                    'user_id'        => $page->user_id ?? 1,
                                    'workspace_id'   => $page->workspace_id ?? 1,
                                    'type'           => 'facebook_comment',
                                    'title'          => 'Facebook Comment',
                                    'message'        => "{$author} commented on your Facebook video\n\n\"{$msg}\"",
                                    'related_entity' => $relKey,
                                    'is_read'        => false,
                                    'created_at'     => $createdAt,
                                    'updated_at'     => now(),
                                ]);
                            }
                        }
                    } catch (\Throwable $ve) {
                        Log::info('Facebook videos comments check notice', ['page_id' => $pageId, 'note' => $ve->getMessage()]);
                    }

                    // 4. Also check synced FacebookPost records in DB for this workspace if any have comments
                    try {
                        $dbPosts = \App\Models\FacebookPost::where('workspace_id', $page->workspace_id)
                            ->whereNotNull('fb_post_id')
                            ->where('comments_count', '>', 0)
                            ->orderBy('published_at', 'desc')
                            ->take(5)
                            ->get();

                        foreach ($dbPosts as $dp) {
                            $dpPostId = $dp->fb_post_id;
                            try {
                                $cRes = $this->httpClient->get("https://graph.facebook.com/v23.0/{$dpPostId}/comments", [
                                    'query' => [
                                        'fields'       => 'id,message,from,created_time',
                                        'limit'        => 25,
                                        'access_token' => $token,
                                    ]
                                ]);
                                $cData = json_decode($cRes->getBody(), true);
                                foreach ($cData['data'] ?? [] as $c) {
                                    $commentId = $c['id'] ?? null;
                                    if (!$commentId) continue;

                                    $relKey = "facebook_comment:{$commentId}:{$dpPostId}";
                                    $exists = DB::table('notifications')
                                        ->where('type', 'facebook_comment')
                                        ->where('related_entity', 'LIKE', "%{$commentId}%")
                                        ->exists();
                                    if ($exists) continue;

                                    $author = $c['from']['name'] ?? 'Someone';
                                    $msg = trim($c['message'] ?? '');
                                    if (empty($msg)) $msg = 'Left a comment';
                                    $createdAt = !empty($c['created_time']) ? Carbon::parse($c['created_time']) : now();

                                    DB::table('notifications')->insert([
                                        'user_id'        => $page->user_id ?? 1,
                                        'workspace_id'   => $page->workspace_id ?? 1,
                                        'type'           => 'facebook_comment',
                                        'title'          => 'Facebook Comment',
                                        'message'        => "{$author} commented on your Facebook post\n\n\"{$msg}\"",
                                        'related_entity' => $relKey,
                                        'is_read'        => false,
                                        'created_at'     => $createdAt,
                                        'updated_at'     => now(),
                                    ]);
                                }
                            } catch (\Throwable $de) {}
                        }
                    } catch (\Throwable $dbe) {}

                    if (!empty($page->workspace_id)) {
                        \Illuminate\Support\Facades\Cache::put("comments_stream_v_{$page->workspace_id}", time(), 86400);
                    }

                } catch (\Throwable $pageErr) {
                    Log::warning('Facebook comments sync page error', ['page_id' => $page->page_id, 'error' => $pageErr->getMessage()]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Facebook comments sync error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Sync Instagram comments.
     */
    public function syncInstagramComments(?int $workspaceId = null): void
    {
        try {
            $query = Integration::where('platform', 'instagram')->where('is_connected', true);
            if ($workspaceId) {
                $query->where('workspace_id', $workspaceId);
            }
            $integrations = $query->get();

            foreach ($integrations as $integ) {
                $token = $integ->refresh_token ?? $integ->access_token;
                if (empty($token)) continue;

                $isIgToken = str_starts_with($token, 'IGAA');
                $baseUrl = $isIgToken ? 'https://graph.instagram.com/v23.0' : 'https://graph.facebook.com/v23.0';
                $targetNode = (!empty($integ->account_id) && !$isIgToken) ? $integ->account_id : 'me';

                try {
                    // 1. Fetch Instagram media items with inline comments where supported
                    $mediaRes = $this->httpClient->get("{$baseUrl}/{$targetNode}/media", [
                        'query' => [
                            'fields'       => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,comments_count,comments{id,text,username,timestamp}',
                            'limit'        => 25,
                            'access_token' => $token,
                        ]
                    ]);

                    $mediaData = json_decode($mediaRes->getBody(), true);
                    $mediaItems = $mediaData['data'] ?? [];

                    foreach ($mediaItems as $m) {
                        $mediaId = $m['id'] ?? null;
                        if (!$mediaId) continue;

                        $mThumb = $m['thumbnail_url'] ?? $m['media_url'] ?? null;
                        \Illuminate\Support\Facades\Cache::put("ig_media_meta_{$mediaId}", [
                            'id'            => (string)$mediaId,
                            'thumbnail_url' => $mThumb,
                            'media_url'     => $m['media_url'] ?? null,
                            'permalink'     => $m['permalink'] ?? null,
                            'post_url'      => $m['permalink'] ?? null,
                            'content'       => $m['caption'] ?? '',
                            'title'         => !empty($m['caption']) ? strtok($m['caption'], "\n") : 'Instagram Post',
                        ], 86400);

                        $commentsList = $m['comments']['data'] ?? [];

                        // If not returned inline and comments_count > 0, fetch directly
                        if (empty($commentsList) && !empty($m['comments_count']) && (int)$m['comments_count'] > 0) {
                            try {
                                $cRes = $this->httpClient->get("{$baseUrl}/{$mediaId}/comments", [
                                    'query' => [
                                        'fields'       => 'id,text,username,timestamp',
                                        'limit'        => 25,
                                        'access_token' => $token,
                                    ]
                                ]);
                                $cData = json_decode($cRes->getBody(), true);
                                $commentsList = $cData['data'] ?? [];
                            } catch (\Throwable $ce) {
                                Log::info('Instagram direct comment list note', ['media_id' => $mediaId, 'error' => $ce->getMessage()]);
                            }
                        }

                        foreach ($commentsList as $c) {
                            $commentId = $c['id'] ?? null;
                            $text = trim($c['text'] ?? '');
                            if (!$commentId || empty($text)) continue;

                            $relKey = "instagram_comment:{$commentId}:{$mediaId}";
                            $exists = DB::table('notifications')
                                ->where('type', 'instagram_comment')
                                ->where('related_entity', 'LIKE', "%{$commentId}%")
                                ->exists();
                            if ($exists) continue;

                            $username = $c['username'] ?? 'someone';
                            $cTime = !empty($c['timestamp']) ? Carbon::parse($c['timestamp']) : now();

                            DB::table('notifications')->insert([
                                'user_id'        => $integ->user_id ?? 1,
                                'workspace_id'   => $integ->workspace_id ?? $workspaceId ?? 1,
                                'type'           => 'instagram_comment',
                                'title'          => 'Instagram Comment',
                                'message'        => "@{$username} commented on your Instagram post\n\n\"{$text}\"",
                                'related_entity' => $relKey,
                                'is_read'        => false,
                                'created_at'     => $cTime,
                                'updated_at'     => now(),
                            ]);
                            $targetWs = $integ->workspace_id ?? $workspaceId ?? 1;
                            if ($targetWs) {
                                \Illuminate\Support\Facades\Cache::put("comments_stream_v_{$targetWs}", time(), 86400);
                            }
                        }
                    }
                } catch (\Throwable $ie) {
                    Log::warning('Instagram media fetch error', ['account_id' => $targetNode, 'error' => $ie->getMessage()]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Instagram comments sync error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Sync YouTube video comments.
     */
    public function syncYouTubeComments(?int $workspaceId = null): void
    {
        try {
            $query = YouTubeConnection::whereNotNull('access_token');
            if ($workspaceId) {
                $query->where('workspace_id', $workspaceId);
            }
            $connections = $query->get();
            if ($connections->isEmpty()) return;

            $ytService = new YouTubeService();

            foreach ($connections as $conn) {
                try {
                    $conn = $ytService->refreshAccessTokenIfNeeded($conn);
                    $client = $ytService->getConfiguredClient();
                    $client->setAccessToken($conn->access_token);
                    $youtube = new GoogleYouTube($client);

                    // 1. Fetch channel-wide comment threads directly in a single API call
                    $threadsList = [];
                    if (!empty($conn->channel_id)) {
                        try {
                            $threadsRes = $youtube->commentThreads->listCommentThreads('snippet', [
                                'allThreadsRelatedToChannelId' => $conn->channel_id,
                                'maxResults'                   => 50,
                                'order'                        => 'time',
                            ]);
                            $threadsList = $threadsRes->getItems() ?? [];
                        } catch (\Throwable $cte) {
                            Log::info('YouTube allThreadsRelatedToChannelId fallback to per-video query', ['msg' => $cte->getMessage()]);
                        }
                    }

                    // Fallback to per-video comment threads if channel-wide query is unsupported
                    if (empty($threadsList)) {
                        $channelRes = !empty($conn->channel_id)
                            ? $youtube->channels->listChannels('contentDetails', ['id' => $conn->channel_id])
                            : null;

                        if (!$channelRes || empty($channelRes->getItems())) {
                            $channelRes = $youtube->channels->listChannels('contentDetails', ['mine' => true]);
                        }

                        $uploadsListId = !empty($channelRes->getItems()) ? $channelRes->getItems()[0]->getContentDetails()?->getRelatedPlaylists()?->getUploads() : null;

                        if ($uploadsListId) {
                            $playlistItems = $youtube->playlistItems->listPlaylistItems('contentDetails', [
                                'playlistId' => $uploadsListId,
                                'maxResults' => 10,
                            ]);

                            foreach ($playlistItems->getItems() as $item) {
                                $videoId = $item->getContentDetails()?->getVideoId();
                                if (!$videoId) continue;
                                try {
                                    $vThreads = $youtube->commentThreads->listCommentThreads('snippet', [
                                        'videoId'    => $videoId,
                                        'maxResults' => 10,
                                    ]);
                                    foreach ($vThreads->getItems() as $vt) {
                                        $threadsList[] = $vt;
                                    }
                                } catch (\Throwable $ve) {}
                            }
                        }
                    }

                    $consecutiveExisting = 0;
                    foreach ($threadsList as $thread) {
                        $top = $thread->getSnippet()?->getTopLevelComment();
                        if (!$top) continue;

                        $commentId = $top->getId();
                        if (!$commentId) continue;

                        $videoId = $thread->getSnippet()?->getVideoId() ?? 'channel';
                        $relKey = "youtube_comment:{$commentId}:{$videoId}";
                        $exists = DB::table('notifications')->where('related_entity', 'LIKE', "youtube_comment:{$commentId}%")->exists();
                        if ($exists) {
                            $consecutiveExisting++;
                            if ($consecutiveExisting >= 3) {
                                break; // Threads are ordered by time; stop as soon as we reach already-synced comments
                            }
                            continue;
                        }
                        $consecutiveExisting = 0;

                        $snippet = $top->getSnippet();
                        $author = $snippet?->getAuthorDisplayName() ?? 'Someone';
                        $text = trim($snippet?->getTextDisplay() ?? '');
                        if (empty($text)) $text = 'Left a comment';

                        $publishedAt = $snippet?->getPublishedAt();
                        $createdAt = $publishedAt ? Carbon::parse($publishedAt) : now();
                        $targetWs = $conn->workspace_id ?? $workspaceId ?? 1;

                        DB::table('notifications')->insert([
                            'user_id'        => $conn->user_id ?? 1,
                            'workspace_id'   => $targetWs,
                            'type'           => 'youtube_comment',
                            'title'          => 'YouTube Comment',
                            'message'        => "{$author} commented on your YouTube channel\n\n\"{$text}\"",
                            'related_entity' => $relKey,
                            'is_read'        => false,
                            'created_at'     => $createdAt,
                            'updated_at'     => now(),
                        ]);

                        Log::info('[YOUTUBE SYNC] Stored comment', [
                            'platform'     => 'youtube',
                            'comment_id'   => $commentId,
                            'workspace_id' => $targetWs,
                            'event_type'   => 'comment.created',
                            'video_id'     => $videoId,
                        ]);

                        if ($targetWs) {
                            \Illuminate\Support\Facades\Cache::put("comments_stream_v_{$targetWs}", time(), 86400);
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('YouTube comments sync channel error', ['channel_id' => $conn->channel_id, 'workspace_id' => $conn->workspace_id, 'error' => $e->getMessage()]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('YouTube comments sync error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Ensure a Facebook Page is subscribed to 'feed' webhook events.
     * This enables real-time comment delivery for pages that lack pages_read_user_content.
     * Uses a 24-hour cache to avoid re-subscribing on every sync cycle.
     */
    protected function ensurePageSubscribedToFeedWebhook(string $pageId, string $token): void
    {
        $cacheKey = "fb_page_feed_webhook_subscribed_{$pageId}";
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return; // Already subscribed (cached)
        }

        try {
            $res = $this->httpClient->post("https://graph.facebook.com/v23.0/{$pageId}/subscribed_apps", [
                'form_params' => [
                    'subscribed_fields' => 'messages,feed',
                    'access_token'      => $token,
                ],
            ]);
            $data = json_decode($res->getBody(), true);
            if (!empty($data['success'])) {
                // Cache for 24 hours so we don't re-subscribe every sync
                \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addHours(24));
                Log::info('[FB SYNC] Subscribed page to feed webhook', ['page_id' => $pageId]);
            } else {
                Log::warning('[FB SYNC] Failed to subscribe page to feed webhook', ['page_id' => $pageId, 'response' => $data]);
            }
        } catch (\Throwable $e) {
            Log::info('[FB SYNC] Feed webhook subscription attempt notice', ['page_id' => $pageId, 'note' => $e->getMessage()]);
        }
    }
}
