<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;
use App\Models\FacebookPage;
use App\Models\FacebookPost;

class FacebookGraphService
{
    protected string $apiVersion = 'v23.0';
    protected string $baseUrl = 'https://graph.facebook.com';

    /**
     * Pre-configured HTTP client for Meta Graph API calls.
     * Enforces IPv4 resolution (CURLOPT_IPRESOLVE_V4) to prevent cURL error 28 (10s DNS resolution timeout on Windows/local dev).
     */
    protected function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withoutVerifying()
            ->timeout(60)
            ->connectTimeout(15)
            ->retry(2, 500, function (\Exception $exception) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException
                    || str_contains($exception->getMessage(), 'timed out')
                    || str_contains($exception->getMessage(), 'cURL error 28');
            })
            ->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ]);
    }

    /**
     * Get Facebook Page Details (Followers, Fan Count, Profile Picture)
     */
    public function getPageDetails(string $pageId, string $accessToken): array
    {
        $url = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}";
        $response = $this->client()->get($url, [
            'fields'       => 'id,name,followers_count,fan_count,picture.type(large)',
            'access_token' => $accessToken,
        ]);

        if (!$response->successful()) {
            throw new Exception($response->json('error.message') ?? 'Failed to fetch Page details from Meta Graph API');
        }

        $data = $response->json();
        return [
            'page_id'             => $data['id'] ?? $pageId,
            'page_name'           => $data['name'] ?? '',
            'followers_count'     => $data['followers_count'] ?? 0,
            'fan_count'           => $data['fan_count'] ?? 0,
            'profile_picture_url' => $data['picture']['data']['url'] ?? null,
        ];
    }

    /**
     * Get Facebook Page Insights & Analytics Metrics for a given period.
     * Queries valid Meta Graph API v23.0 metrics (page_views_total, page_post_engagements, page_daily_follows_unique).
     */
    public function getPageInsights(string $pageId, string $accessToken, int $days = 30): array
    {
        $since = now()->subDays($days)->startOfDay()->timestamp;
        $until = now()->endOfDay()->timestamp;

        $url = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/insights";

        \Illuminate\Support\Facades\Log::info("[Meta API Request] getPageInsights", [
            'page_id' => $pageId,
            'days'    => $days,
            'since'   => $since,
            'until'   => $until,
        ]);

        $response = $this->client()->get($url, [
            'metric'       => 'page_views_total,page_post_engagements,page_daily_follows_unique,page_actions_post_reactions_total',
            'period'       => 'day',
            'since'        => $since,
            'until'        => $until,
            'access_token' => $accessToken,
        ]);

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::warning("[Meta API Error] getPageInsights", [
                'page_id' => $pageId,
                'status'  => $response->status(),
                'error'   => $response->json('error') ?? $response->body(),
            ]);
            return [
                'impressions' => 0,
                'engagements' => 0,
                'views'       => 0,
                'error'       => $response->json('error.message') ?? 'Meta API insights unavailable',
            ];
        }

        $metrics = [];
        foreach ($response->json('data') ?? [] as $item) {
            $name   = $item['name'] ?? '';
            $values = $item['values'] ?? [];
            $sum = 0;
            foreach ($values as $entry) {
                $val = is_array($entry['value'] ?? null) ? array_sum($entry['value']) : (int)($entry['value'] ?? 0);
                $sum += $val;
            }
            $metrics[$name] = $sum;
        }

        \Illuminate\Support\Facades\Log::info("[Meta API Response] getPageInsights", [
            'page_id' => $pageId,
            'metrics' => $metrics,
        ]);

        return [
            'views'       => $metrics['page_views_total'] ?? 0,
            'engagements' => $metrics['page_post_engagements'] ?? 0,
            'follows'     => $metrics['page_daily_follows_unique'] ?? 0,
            'reactions'   => $metrics['page_actions_post_reactions_total'] ?? 0,
            'impressions' => $metrics['page_views_total'] ?? 0,
        ];
    }

    /**
     * Get day-by-day Page Insights trend for the last N days.
     * Returns a complete date-mapped series for every day in the period using real Meta API data.
     */
    public function getPageInsightsTrend(string $pageId, string $accessToken, int $days = 30): array
    {
        $since = now()->subDays($days)->startOfDay()->timestamp;
        $until = now()->endOfDay()->timestamp;

        $url = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/insights";

        \Illuminate\Support\Facades\Log::info("[Meta API Request] getPageInsightsTrend", [
            'page_id' => $pageId,
            'days'    => $days,
            'since'   => $since,
            'until'   => $until,
        ]);

        $response = $this->client()->get($url, [
            'metric'       => 'page_views_total,page_post_engagements,page_actions_post_reactions_total',
            'period'       => 'day',
            'since'        => $since,
            'until'        => $until,
            'access_token' => $accessToken,
        ]);

        // Pre-build date map for ALL days in the selected period
        $reachByDate      = [];
        $engagementByDate = [];
        $labels           = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $dateStr = now()->subDays($i)->format('Y-m-d');
            $label   = now()->subDays($i)->format('M j');
            $labels[] = $label;
            $reachByDate[$dateStr]      = 0;
            $engagementByDate[$dateStr] = 0;
        }

        $hasMetaData = false;

        if ($response->successful()) {
            $data = $response->json('data') ?? [];
            if (!empty($data)) {
                $hasMetaData = true;
                foreach ($data as $metric) {
                    $name   = $metric['name'] ?? '';
                    $values = $metric['values'] ?? [];
                    foreach ($values as $entry) {
                        $date  = substr($entry['end_time'] ?? '', 0, 10);
                        $value = is_array($entry['value'] ?? null) ? array_sum($entry['value']) : (int)($entry['value'] ?? 0);
                        if (isset($reachByDate[$date])) {
                            if ($name === 'page_views_total') {
                                $reachByDate[$date] = ($reachByDate[$date] ?? 0) + $value;
                            }
                        }
                        if (isset($engagementByDate[$date])) {
                            if ($name === 'page_post_engagements' || $name === 'page_actions_post_reactions_total') {
                                $engagementByDate[$date] = ($engagementByDate[$date] ?? 0) + $value;
                            }
                        }
                    }
                }
            }
        } else {
            \Illuminate\Support\Facades\Log::warning("[Meta API Error] getPageInsightsTrend", [
                'page_id' => $pageId,
                'status'  => $response->status(),
                'error'   => $response->json('error') ?? $response->body(),
            ]);
        }

        $reachSeries      = array_values($reachByDate);
        $engagementSeries = array_values($engagementByDate);

        return [
            'labels'     => $labels,
            'reach'      => $reachSeries,
            'engagement' => $engagementSeries,
            'has_data'   => $hasMetaData,
        ];
    }

    /**
     * Publish Text or Link Post to Page Feed
     */
    public function publishTextPost(string $pageId, string $accessToken, string $message, ?string $linkUrl = null): array
    {
        $url = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/feed";
        $params = [
            'message'      => $message,
            'access_token' => $accessToken,
        ];

        if (!empty($linkUrl)) {
            $params['link'] = $linkUrl;
        }

        $response = $this->client()->post($url, $params);

        if (!$response->successful()) {
            throw new Exception($response->json('error.message') ?? 'Failed to publish post to Facebook Page');
        }

        return $response->json();
    }

    /**
     * Publish Single Photo Post
     */
    public function publishSinglePhoto(string $pageId, string $accessToken, string $caption, $fileOrUrl): array
    {
        $url = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/photos";

        if (is_object($fileOrUrl) && method_exists($fileOrUrl, 'getRealPath')) {
            $response = $this->client()->attach(
                'source',
                file_get_contents($fileOrUrl->getRealPath()),
                $fileOrUrl->getClientOriginalName()
            )->post($url, [
                'caption'      => $caption,
                'access_token' => $accessToken,
            ]);
        } else {
            $response = $this->client()->post($url, [
                'url'          => (string) $fileOrUrl,
                'caption'      => $caption,
                'access_token' => $accessToken,
            ]);
        }

        if (!$response->successful()) {
            throw new Exception($response->json('error.message') ?? 'Failed to publish photo to Facebook Page');
        }

        return $response->json();
    }

    /**
     * Publish Multiple Photos as a Carousel / Multi-photo post
     */
    public function publishMultiplePhotos(string $pageId, string $accessToken, string $caption, array $files): array
    {
        $attachedMedia = [];

        foreach ($files as $file) {
            $photoUrl = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/photos";
            $response = $this->client()->attach(
                'source',
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName()
            )->post($photoUrl, [
                'published'    => 'false', // Upload without publishing to feed yet
                'access_token' => $accessToken,
            ]);

            if ($response->successful() && $response->json('id')) {
                $attachedMedia[] = ['media_fbid' => $response->json('id')];
            }
        }

        if (empty($attachedMedia)) {
            throw new Exception('Failed to upload images for multi-photo post');
        }

        // Post all attached media together in feed
        $feedUrl = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/feed";
        $response = $this->client()->post($feedUrl, [
            'message'        => $caption,
            'attached_media' => $attachedMedia,
            'access_token'   => $accessToken,
        ]);

        if (!$response->successful()) {
            throw new Exception($response->json('error.message') ?? 'Failed to publish multi-photo post');
        }

        return $response->json();
    }

    /**
     * Publish Video Post
     */
    public function publishVideo(string $pageId, string $accessToken, string $description, $fileOrUrl): array
    {
        $url = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/videos";

        if (is_object($fileOrUrl) && method_exists($fileOrUrl, 'getRealPath')) {
            $response = $this->client()->attach(
                'source',
                file_get_contents($fileOrUrl->getRealPath()),
                $fileOrUrl->getClientOriginalName()
            )->post($url, [
                'description'  => $description,
                'access_token' => $accessToken,
            ]);
        } else {
            $response = $this->client()->post($url, [
                'file_url'     => (string) $fileOrUrl,
                'description'  => $description,
                'access_token' => $accessToken,
            ]);
        }

        if (!$response->successful()) {
            throw new Exception($response->json('error.message') ?? 'Failed to publish video to Facebook Page');
        }

        return $response->json();
    }

    /**
     * Publish Single Photo Container & Post to Instagram Business Account
     */
    public function publishInstagramSinglePhoto(string $igAccountId, string $accessToken, string $caption, $fileOrUrl, int $workspaceId = 1): array
    {
        $originalName = 'image.jpg';
        $storedPath   = null;
        $mediaUrl     = null;

        if (is_object($fileOrUrl) && method_exists($fileOrUrl, 'getRealPath')) {
            $originalName = method_exists($fileOrUrl, 'getClientOriginalName') ? $fileOrUrl->getClientOriginalName() : 'image.jpg';
            $ext          = method_exists($fileOrUrl, 'getClientOriginalExtension') ? ($fileOrUrl->getClientOriginalExtension() ?: 'jpg') : 'jpg';

            // Unique filename per upload under workspace-isolated directory
            $uuid       = \Illuminate\Support\Str::uuid()->toString();
            $filename   = "{$uuid}.{$ext}";
            $path       = $fileOrUrl->storeAs("public/posts/{$workspaceId}", $filename);
            $storedPath = "posts/{$workspaceId}/{$filename}";
            $localAsset = asset("storage/posts/{$workspaceId}/{$filename}");

            // Generate valid public HTTPS URL for Meta
            $publicDomain = env('PUBLIC_MEDIA_URL');
            if (!empty($publicDomain)) {
                $mediaUrl = rtrim($publicDomain, '/') . "/storage/posts/{$workspaceId}/{$filename}";
            } elseif (str_starts_with(env('APP_URL'), 'https://')) {
                $mediaUrl = asset("storage/posts/{$workspaceId}/{$filename}");
            } else {
                // In local dev environment (localhost / http), upload actual file binary to Cloudinary CDN
                $cdnRes = $this->client()->attach(
                    'file',
                    file_get_contents($fileOrUrl->getRealPath()),
                    $filename
                )->post('https://api.cloudinary.com/v1_1/demo/image/upload', [
                    'upload_preset' => 'unsigned'
                ]);
                $mediaUrl = $cdnRes->json('secure_url') ?? $localAsset;
            }
        } else {
            $mediaUrl = (string) $fileOrUrl;
        }

        // Determine API endpoint base: graph.instagram.com for IGAA... tokens, graph.facebook.com for EAA... tokens
        $isIgToken = str_starts_with($accessToken, 'IGAA');
        $apiBase   = $isIgToken ? 'https://graph.instagram.com/v23.0' : "{$this->baseUrl}/{$this->apiVersion}";

        // Step 1: Create Media Container
        $containerUrl = "{$apiBase}/{$igAccountId}/media";
        $response = $this->client()->post($containerUrl, [
            'media_type'   => 'IMAGE',
            'image_url'    => $mediaUrl,
            'caption'      => $caption,
            'access_token' => $accessToken,
        ]);

        if (!$response->successful() || !($containerId = $response->json('id'))) {
            throw new Exception($response->json('error.message') ?? 'Failed to create Instagram photo container');
        }

        // Wait for Meta container processing (up to 5 attempts, 2 seconds each)
        for ($i = 0; $i < 5; $i++) {
            sleep(2);
            $statusRes = $this->client()->get("{$apiBase}/{$containerId}", [
                'fields'       => 'status_code',
                'access_token' => $accessToken,
            ]);
            $statusCode = $statusRes->json('status_code');
            if ($statusCode === 'FINISHED' || $statusCode === 'FINISHED_SUCCESS' || empty($statusCode)) {
                break;
            }
            if ($statusCode === 'ERROR') {
                throw new Exception('Instagram media container processing failed on Meta servers.');
            }
        }

        // Step 2: Publish Container
        $publishUrl = "{$apiBase}/{$igAccountId}/media_publish";
        $pubResponse = $this->client()->post($publishUrl, [
            'creation_id'  => $containerId,
            'access_token' => $accessToken,
        ]);

        if (!$pubResponse->successful()) {
            throw new Exception($pubResponse->json('error.message') ?? 'Failed to publish container to Instagram');
        }

        $publishedId = $pubResponse->json('id');

        // Detailed logging without exposing access token
        \Illuminate\Support\Facades\Log::info('[Instagram Publishing Flow]', [
            'workspace_id'         => $workspaceId,
            'selected_file_name'   => $originalName,
            'uploaded_storage_path'=> $storedPath,
            'public_media_url'     => $mediaUrl,
            'instagram_account_id' => $igAccountId,
            'container_response'   => $response->json(),
            'container_id'         => $containerId,
            'publish_response'     => $pubResponse->json(),
            'published_media_id'   => $publishedId,
        ]);

        return $pubResponse->json();
    }

    /**
     * Publish Reel / Video Container & Post to Instagram Business Account
     */
    public function publishInstagramVideo(string $igAccountId, string $accessToken, string $caption, $fileOrUrl, int $workspaceId = 1): array
    {
        $originalName = 'video.mp4';
        $storedPath   = null;
        $videoUrl     = null;

        if (is_object($fileOrUrl) && method_exists($fileOrUrl, 'getRealPath')) {
            $originalName = method_exists($fileOrUrl, 'getClientOriginalName') ? $fileOrUrl->getClientOriginalName() : 'video.mp4';
            $ext          = method_exists($fileOrUrl, 'getClientOriginalExtension') ? ($fileOrUrl->getClientOriginalExtension() ?: 'mp4') : 'mp4';

            // Unique filename per upload under workspace-isolated directory
            $uuid       = \Illuminate\Support\Str::uuid()->toString();
            $filename   = "{$uuid}.{$ext}";
            $path       = $fileOrUrl->storeAs("public/posts/{$workspaceId}", $filename);
            $storedPath = "posts/{$workspaceId}/{$filename}";
            $localAsset = asset("storage/posts/{$workspaceId}/{$filename}");

            $publicDomain = env('PUBLIC_MEDIA_URL');
            if (!empty($publicDomain)) {
                $videoUrl = rtrim($publicDomain, '/') . "/storage/posts/{$workspaceId}/{$filename}";
            } elseif (str_starts_with(env('APP_URL'), 'https://')) {
                $videoUrl = asset("storage/posts/{$workspaceId}/{$filename}");
            } else {
                $videoUrl = $localAsset;
            }
        } else {
            $videoUrl = (string) $fileOrUrl;
        }

        $isIgToken = str_starts_with($accessToken, 'IGAA');
        $apiBase   = $isIgToken ? 'https://graph.instagram.com/v23.0' : "{$this->baseUrl}/{$this->apiVersion}";

        $containerUrl = "{$apiBase}/{$igAccountId}/media";
        $response = $this->client()->post($containerUrl, [
            'media_type'   => 'REELS',
            'video_url'    => $videoUrl,
            'caption'      => $caption,
            'access_token' => $accessToken,
        ]);

        if (!$response->successful() || !($containerId = $response->json('id'))) {
            throw new Exception($response->json('error.message') ?? 'Failed to create Instagram video container');
        }

        // Wait for Meta container processing (up to 8 attempts for video, 3 seconds each)
        for ($i = 0; $i < 8; $i++) {
            sleep(3);
            $statusRes = $this->client()->get("{$apiBase}/{$containerId}", [
                'fields'       => 'status_code',
                'access_token' => $accessToken,
            ]);
            $statusCode = $statusRes->json('status_code');
            if ($statusCode === 'FINISHED' || $statusCode === 'FINISHED_SUCCESS' || empty($statusCode)) {
                break;
            }
            if ($statusCode === 'ERROR') {
                throw new Exception('Instagram video container processing failed on Meta servers.');
            }
        }

        $publishUrl = "{$apiBase}/{$igAccountId}/media_publish";
        $pubResponse = $this->client()->post($publishUrl, [
            'creation_id'  => $containerId,
            'access_token' => $accessToken,
        ]);

        if (!$pubResponse->successful()) {
            throw new Exception($pubResponse->json('error.message') ?? 'Failed to publish video to Instagram');
        }

        $publishedId = $pubResponse->json('id');

        \Illuminate\Support\Facades\Log::info('[Instagram Video Publishing Flow]', [
            'workspace_id'         => $workspaceId,
            'selected_file_name'   => $originalName,
            'uploaded_storage_path'=> $storedPath,
            'public_media_url'     => $videoUrl,
            'instagram_account_id' => $igAccountId,
            'container_response'   => $response->json(),
            'container_id'         => $containerId,
            'publish_response'     => $pubResponse->json(),
            'published_media_id'   => $publishedId,
        ]);

        return $pubResponse->json();
    }

    /**
     * Fetch Instagram Business / Professional Account Insights via Instagram Graph API.
     */
    public function getInstagramAccountInsights(string $accessToken, int $days = 30): array
    {
        $since = now()->subDays($days)->startOfDay()->timestamp;
        $until = now()->endOfDay()->timestamp;

        $isIgToken = str_starts_with($accessToken, 'IGAA');
        $baseUrl   = $isIgToken ? 'https://graph.instagram.com/v23.0' : "{$this->baseUrl}/{$this->apiVersion}";
        $url       = "{$baseUrl}/me/insights";

        \Illuminate\Support\Facades\Log::info("[Instagram API Request] getInstagramAccountInsights", [
            'days'  => $days,
            'since' => $since,
            'until' => $until,
        ]);

        $response = $this->client()->get($url, [
            'metric'       => 'reach,accounts_engaged,total_interactions,likes,comments',
            'period'       => 'day',
            'since'        => $since,
            'until'        => $until,
            'access_token' => $accessToken,
        ]);

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::warning("[Instagram API Error] getInstagramAccountInsights", [
                'status' => $response->status(),
                'error'  => $response->json('error') ?? $response->body(),
            ]);
            return [
                'reach'              => 0,
                'accounts_engaged'   => 0,
                'total_interactions' => 0,
                'likes'              => 0,
                'comments'           => 0,
                'error'              => $response->json('error.message') ?? 'Instagram insights unavailable',
            ];
        }

        $metrics = [];
        foreach ($response->json('data') ?? [] as $item) {
            $name   = $item['name'] ?? '';
            $values = $item['values'] ?? [];
            $sum = 0;
            foreach ($values as $entry) {
                $val = is_array($entry['value'] ?? null) ? array_sum($entry['value']) : (int)($entry['value'] ?? 0);
                $sum += $val;
            }
            $metrics[$name] = $sum;
        }

        \Illuminate\Support\Facades\Log::info("[Instagram API Response] getInstagramAccountInsights", [
            'metrics' => $metrics,
        ]);

        return [
            'reach'              => $metrics['reach'] ?? 0,
            'accounts_engaged'   => $metrics['accounts_engaged'] ?? 0,
            'total_interactions' => $metrics['total_interactions'] ?? 0,
            'likes'              => $metrics['likes'] ?? 0,
            'comments'           => $metrics['comments'] ?? 0,
        ];
    }

    /**
     * Fetch Facebook Page Views via page_views_total metric.
     */
    public function getFacebookPostViewsMetrics(string $pageId, string $accessToken): array
    {
        $videoViews = 0;
        $hasVideos  = false;
        $endpointUsed = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/videos";
        $metricUsed   = 'views';

        // 1. Check Page Video Views (Supported with Page access token)
        try {
            $vidResponse = $this->client()->timeout(5)->get($endpointUsed, [
                'fields'       => 'id,views',
                'limit'        => 50,
                'access_token' => $accessToken,
            ]);

            if ($vidResponse->successful()) {
                $vData = $vidResponse->json('data') ?? [];

                // Only report "supported" when the page actually has videos.
                // An empty array means the page has no videos — fall through so
                // the dashboard shows N/A (Req. Perm) instead of a misleading 0.
                if (!empty($vData)) {
                    foreach ($vData as $vid) {
                        $videoViews += (int)($vid['views'] ?? 0);
                    }

                    \Illuminate\Support\Facades\Log::info("[FACEBOOK VIEWS] Videos found", [
                        'Page ID'             => $pageId,
                        'Video count'         => count($vData),
                        'Endpoint'            => $endpointUsed,
                        'Metric'              => $metricUsed,
                        'Meta response'       => $vidResponse->json(),
                        'Final backend value' => $videoViews,
                        'Supported'           => true,
                    ]);

                    return [
                        'views'     => $videoViews,
                        'supported' => true,
                        'reason'    => 'video_views_aggregated',
                    ];
                }

                // No videos on page — log and fall through to permission check
                \Illuminate\Support\Facades\Log::info("[FACEBOOK VIEWS] No videos on page — falling through to post-level check", [
                    'Page ID'  => $pageId,
                    'Endpoint' => $endpointUsed,
                    'API data' => $vData,
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("[FACEBOOK VIEWS] Video fetch exception", [
                'Page ID' => $pageId,
                'Error'   => $e->getMessage(),
            ]);
        }

        // 2. Test Post-level views/impressions via feed post objects
        $postEndpoint = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/posts";
        $postError    = null;
        $supported    = false;

        try {
            $postsResponse = $this->client()->timeout(5)->get($postEndpoint, [
                'fields'       => 'id,message,created_time',
                'limit'        => 5,
                'access_token' => $accessToken,
            ]);

            if ($postsResponse->successful()) {
                $posts = $postsResponse->json('data') ?? [];
                if (!empty($posts)) {
                    $firstPostId = $posts[0]['id'];
                    $insightRes = $this->client()->timeout(5)->get("{$this->baseUrl}/{$this->apiVersion}/{$firstPostId}/insights", [
                        'metric'       => 'post_impressions',
                        'access_token' => $accessToken,
                    ]);

                    $postError = $insightRes->json('error.message') ?? $insightRes->body();
                    $errorCode = $insightRes->json('error.code');

                    \Illuminate\Support\Facades\Log::info("[FACEBOOK VIEWS]", [
                        'Page ID'             => $pageId,
                        'Post/Media ID'       => $firstPostId,
                        'Media type'          => 'post',
                        'Endpoint'            => "{$this->baseUrl}/{$this->apiVersion}/{$firstPostId}/insights",
                        'Metric'              => 'post_impressions',
                        'Meta response'       => $insightRes->json(),
                        'Error'               => $postError,
                        'Final backend value' => null,
                        'Supported'           => false,
                    ]);
                }
            } else {
                $postError = $postsResponse->json('error.message') ?? $postsResponse->body();
            }
        } catch (\Exception $e) {
            $postError = $e->getMessage();
        }

        \Illuminate\Support\Facades\Log::info("[FACEBOOK VIEWS] Final Status", [
            'Page ID'             => $pageId,
            'Endpoint'            => $postEndpoint,
            'Metric'              => 'post_impressions / post_views',
            'Meta error'          => $postError ?? "Requires pages_read_engagement or Page Public Content Access (PPCA) App Review in Live mode",
            'Final backend value' => null,
            'Supported'           => false,
        ]);

        return [
            'views'     => null,
            'supported' => false,
            'reason'    => 'requires_pages_read_engagement_app_review',
        ];
    }

    public function getPageViews(string $pageId, string $accessToken): int
    {
        try {
            $response = $this->client()->timeout(3)->get("{$this->baseUrl}/{$this->apiVersion}/{$pageId}/insights", [
                'metric'       => 'page_views_total',
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                $views = 0;
                foreach ($response->json('data.0.values') ?? [] as $v) {
                    $views += (int)($v['value'] ?? 0);
                }
                return $views;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to fetch Facebook Page Views: " . $e->getMessage());
        }

        return 0;
    }

    /**
     * Fetch Live Facebook Feed Post Metrics (Views, Likes, Comments, Shares) directly from Meta Graph API.
     * Logs the RAW Meta API response before any processing.
     * Does NOT swallow or convert permission/API errors into 0.
     */
    public function getFacebookFeedPostMetrics(string $pageId, string $accessToken): array
    {
        $publishedPostsEndpoint = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/published_posts";

        \Illuminate\Support\Facades\Log::info("[FACEBOOK API CALL] getFacebookFeedPostMetrics request", [
            'page_id'     => $pageId,
            'endpoint'    => $publishedPostsEndpoint,
            'api_version' => $this->apiVersion,
        ]);

        $response = $this->client()->timeout(10)->get($publishedPostsEndpoint, [
            'fields'       => 'id,message,created_time,shares,likes.summary(true),comments.summary(true)',
            'limit'        => 25,
            'access_token' => $accessToken,
        ]);

        // LOG RAW META RESPONSE BEFORE ANY PROCESSING
        \Illuminate\Support\Facades\Log::info("[FACEBOOK API RAW RESPONSE] getFacebookFeedPostMetrics", [
            'page_id'      => $pageId,
            'status'       => $response->status(),
            'raw_response' => $response->json() ?? $response->body(),
        ]);

        if (!$response->successful()) {
            $errorObj = $response->json('error') ?? [];
            $code     = $errorObj['code'] ?? $response->status();
            $message  = $errorObj['message'] ?? 'Meta API error';

            \Illuminate\Support\Facades\Log::warning("[FACEBOOK API ERROR] getFacebookFeedPostMetrics failed", [
                'page_id'    => $pageId,
                'error_code' => $code,
                'message'    => $message,
            ]);

            return [
                'success'            => false,
                'error_code'         => $code,
                'error_msg'          => $message,
                'views'              => null,
                'likes'              => null,
                'comments'           => null,
                'shares'             => null,
                'views_supported'    => false,
                'likes_supported'    => false,
                'comments_supported' => false,
                'shares_supported'   => false,
            ];
        }

        $postsData = $response->json('data') ?? [];
        $totalLikes    = 0;
        $totalComments = 0;
        $totalShares   = 0;
        $totalViews    = 0;
        $viewsSupported = false;

        foreach ($postsData as $p) {
            $totalLikes    += (int)($p['likes']['summary']['total_count'] ?? 0);
            $totalComments += (int)($p['comments']['summary']['total_count'] ?? 0);
            $totalShares   += (int)($p['shares']['count'] ?? 0);
        }

        try {
            $pageViewsRes = $this->client()->timeout(5)->get("{$this->baseUrl}/{$this->apiVersion}/{$pageId}/insights", [
                'metric'       => 'page_posts_impressions_organic',
                'period'       => 'day',
                'access_token' => $accessToken,
            ]);
            if ($pageViewsRes->successful() && !empty($pageViewsRes->json('data'))) {
                $viewsSupported = true;
                $totalViews = 0;
                foreach ($pageViewsRes->json('data.0.values') ?? [] as $v) {
                    $totalViews += (int)($v['value'] ?? 0);
                }
            } else {
                $pvRes = $this->client()->timeout(5)->get("{$this->baseUrl}/{$this->apiVersion}/{$pageId}/insights", [
                    'metric'       => 'page_views_total',
                    'period'       => 'day',
                    'access_token' => $accessToken,
                ]);
                if ($pvRes->successful()) {
                    $viewsSupported = true;
                    foreach ($pvRes->json('data.0.values') ?? [] as $v) {
                        $totalViews += (int)($v['value'] ?? 0);
                    }
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("[FACEBOOK VIEWS API ERROR] " . $e->getMessage());
        }

        return [
            'success'            => true,
            'views'              => $viewsSupported ? $totalViews : null,
            'likes'              => $totalLikes,
            'comments'           => $totalComments,
            'shares'             => $totalShares,
            'views_supported'    => $viewsSupported,
            'likes_supported'    => true,
            'comments_supported' => true,
            'shares_supported'   => true,
        ];
    }

    /**
     * Fetch Instagram Media List with real likes, comments, and media insights.
     */
    public function getInstagramMediaList(string $accessToken, int $limit = 25): array
    {
        $isIgToken = str_starts_with($accessToken, 'IGAA');
        $baseUrl   = $isIgToken ? 'https://graph.instagram.com/v23.0' : "{$this->baseUrl}/{$this->apiVersion}";
        $url       = "{$baseUrl}/me/media";

        $response = $this->client()->get($url, [
            'fields'       => 'id,caption,media_type,media_url,permalink,timestamp,like_count,comments_count',
            'limit'        => $limit,
            'access_token' => $accessToken,
        ]);

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::warning("[Instagram API Error] getInstagramMediaList", [
                'status' => $response->status(),
                'error'  => $response->json('error') ?? $response->body(),
            ]);
            return [];
        }

        $mediaItems = [];
        foreach ($response->json('data') ?? [] as $item) {
            $mId   = $item['id'];
            $likes = (int)($item['like_count'] ?? 0);
            $comments = (int)($item['comments_count'] ?? 0);
            $views = 0;
            $reach = 0;
            $shares = 0;
            $saved  = 0;
            $interactions = $likes + $comments;

            // Fetch Media Insights for shares, views, saved, reach & interactions
            try {
                $insightsRes = $this->client()->timeout(3)->get("{$baseUrl}/{$mId}/insights", [
                    'metric'       => 'shares,views,saved,reach,total_interactions',
                    'access_token' => $accessToken,
                ]);
                if ($insightsRes->successful()) {
                    foreach ($insightsRes->json('data') ?? [] as $metric) {
                        $n = $metric['name'] ?? '';
                        $v = (int)($metric['values'][0]['value'] ?? 0);
                        if ($n === 'reach') $reach = $v;
                        if ($n === 'shares') $shares = $v;
                        if ($n === 'views') $views = $v;
                        if ($n === 'saved') $saved = $v;
                        if ($n === 'total_interactions') $interactions = max($interactions, $v);
                    }
                }
            } catch (\Exception $e) {}

            \Illuminate\Support\Facades\Log::info("[Instagram Trace] Media ID {$mId}", [
                'endpoint'      => "{$baseUrl}/{$mId}/insights",
                'like_count'    => $likes,
                'comments_count'=> $comments,
                'shares'        => $shares,
                'views'         => $views,
                'saved'         => $saved,
                'reach'         => $reach,
                'interactions'  => $interactions,
            ]);

            $mediaItems[] = [
                'id'                 => $mId,
                'caption'            => $item['caption'] ?? '',
                'media_type'         => $item['media_type'] ?? 'IMAGE',
                'media_url'          => $item['media_url'] ?? '',
                'permalink'          => $item['permalink'] ?? '',
                'timestamp'          => $item['timestamp'] ?? null,
                'like_count'         => $likes,
                'comments_count'     => $comments,
                'shares_count'       => $shares,
                'views_count'        => $views,
                'saved_count'        => $saved,
                'reach'              => $reach,
                'total_interactions' => $interactions,
            ];
        }

        return $mediaItems;
    }

    /**
     * Fetch daily Instagram reach and interaction time-series trend over $days.
     */
    public function getInstagramInsightsTrend(string $accessToken, int $days = 30): array
    {
        $since = now()->subDays($days)->startOfDay()->timestamp;
        $until = now()->endOfDay()->timestamp;

        $isIgToken = str_starts_with($accessToken, 'IGAA');
        $baseUrl   = $isIgToken ? 'https://graph.instagram.com/v23.0' : "{$this->baseUrl}/{$this->apiVersion}";
        $url       = "{$baseUrl}/me/insights";

        $response = $this->client()->get($url, [
            'metric'       => 'reach,total_interactions',
            'period'       => 'day',
            'since'        => $since,
            'until'        => $until,
            'access_token' => $accessToken,
        ]);

        $reachByDate      = [];
        $engagementByDate = [];
        $labels           = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $dateStr = now()->subDays($i)->format('Y-m-d');
            $label   = now()->subDays($i)->format('M j');
            $labels[] = $label;
            $reachByDate[$dateStr]      = 0;
            $engagementByDate[$dateStr] = 0;
        }

        $hasData = false;

        if ($response->successful()) {
            $data = $response->json('data') ?? [];
            if (!empty($data)) {
                $hasData = true;
                foreach ($data as $metric) {
                    $name   = $metric['name'] ?? '';
                    $values = $metric['values'] ?? [];
                    foreach ($values as $entry) {
                        $date  = substr($entry['end_time'] ?? '', 0, 10);
                        $value = is_array($entry['value'] ?? null) ? array_sum($entry['value']) : (int)($entry['value'] ?? 0);
                        if (isset($reachByDate[$date])) {
                            if ($name === 'reach') {
                                $reachByDate[$date] = ($reachByDate[$date] ?? 0) + $value;
                            }
                        }
                        if (isset($engagementByDate[$date])) {
                            if ($name === 'total_interactions') {
                                $engagementByDate[$date] = ($engagementByDate[$date] ?? 0) + $value;
                            }
                        }
                    }
                }
            }
        }

        return [
            'labels'     => $labels,
            'reach'      => array_values($reachByDate),
            'engagement' => array_values($engagementByDate),
            'has_data'   => $hasData,
        ];
    }

    /**
     * Fetch real post metrics (likes, comments, shares, reactions) from Meta Graph API.
     * Uses a resilient 3-stage fallback query to support text, link, photo, and video post IDs.
     */
    public function getPostMetrics(string $fbPostId, string $accessToken, ?string $pageId = null): array
    {
        $url = "{$this->baseUrl}/{$this->apiVersion}/{$fbPostId}";

        \Illuminate\Support\Facades\Log::info("[Meta API Request] getPostMetrics Stage 1", [
            'fb_post_id' => $fbPostId,
        ]);

        // Stage 1: Attempt full fields query
        $response = $this->client()->get($url, [
            'fields'       => 'id,created_time,likes.summary(true),comments.summary(true),shares,reactions.summary(true)',
            'access_token' => $accessToken,
        ]);

        // Stage 2: If Stage 1 fails (e.g. invalid 'shares' or 'reactions' field on photo objects), retry without shares
        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::info("[Meta API Request] getPostMetrics Stage 2 (without shares)", [
                'fb_post_id' => $fbPostId,
            ]);
            $response = $this->client()->get($url, [
                'fields'       => 'id,created_time,likes.summary(true),comments.summary(true)',
                'access_token' => $accessToken,
            ]);
        }

        // Stage 3: If Stage 2 fails and $fbPostId is un-scoped and $pageId is provided, retry with page prefix {$pageId}_{fbPostId}
        if (!$response->successful() && !str_contains($fbPostId, '_') && !empty($pageId)) {
            $scopedId = "{$pageId}_{$fbPostId}";
            $scopedUrl = "{$this->baseUrl}/{$this->apiVersion}/{$scopedId}";
            \Illuminate\Support\Facades\Log::info("[Meta API Request] getPostMetrics Stage 3 (scoped ID)", [
                'scoped_id' => $scopedId,
            ]);
            $response = $this->client()->get($scopedUrl, [
                'fields'       => 'id,created_time,likes.summary(true),comments.summary(true)',
                'access_token' => $accessToken,
            ]);
        }

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::warning("[Meta API Error] getPostMetrics", [
                'fb_post_id' => $fbPostId,
                'status'     => $response->status(),
                'error'      => $response->json('error') ?? $response->body(),
            ]);

            return [
                'likes_count'      => 0,
                'comments_count'   => 0,
                'shares_count'     => 0,
                'reactions_count'  => 0,
                'engagement_count' => 0,
                'error'            => $response->json('error.message') ?? 'Post metrics unavailable',
            ];
        }

        $data      = $response->json();
        $likes     = (int)($data['likes']['summary']['total_count'] ?? 0);
        $comments  = (int)($data['comments']['summary']['total_count'] ?? 0);
        $shares    = isset($data['shares']['count']) ? (int)$data['shares']['count'] : 0;
        $reactions = isset($data['reactions']['summary']['total_count']) ? (int)$data['reactions']['summary']['total_count'] : $likes;
        $engagement = $likes + $comments + $shares;

        // Stage 4: Direct comments query fallback if comments count returned 0
        if ($comments === 0) {
            $directId = str_contains($fbPostId, '_') ? last(explode('_', $fbPostId)) : $fbPostId;
            $commUrl  = "{$this->baseUrl}/{$this->apiVersion}/{$directId}/comments";
            $commRes  = $this->client()->get($commUrl, [
                'summary'      => 'true',
                'access_token' => $accessToken,
            ]);
            if ($commRes->successful()) {
                $cCount = (int)($commRes->json('summary.total_count') ?? 0);
                if ($cCount > 0) {
                    $comments   = $cCount;
                    $engagement = $likes + $comments + $shares;
                }
            }
        }

        \Illuminate\Support\Facades\Log::info("[Facebook Trace] Post ID {$fbPostId}", [
            'endpoint'         => $url,
            'comments_from_meta'=> $comments,
            'likes_from_meta'   => $likes,
            'shares_from_meta'  => $shares,
            'reactions_from_meta' => $reactions,
            'parsed_engagement'=> $engagement,
        ]);

        return [
            'likes_count'      => $likes,
            'comments_count'   => $comments,
            'shares_count'     => $shares,
            'reactions_count'  => $reactions,
            'engagement_count' => $engagement,
        ];
    }

    /**
     * Sync single FacebookPost model with latest Meta API metrics and save.
     */
    public function syncPost(FacebookPost $post, ?string $accessToken = null): FacebookPost
    {
        if (empty($post->fb_post_id)) {
            return $post;
        }

        $page = $post->page ?? FacebookPage::find($post->facebook_page_id) ?? FacebookPage::latest()->first();

        if (empty($accessToken)) {
            $accessToken = $page?->page_access_token;
        }

        if (empty($accessToken)) {
            return $post;
        }

        try {
            $metrics = $this->getPostMetrics($post->fb_post_id, $accessToken, $page?->page_id);
            $post->likes_count      = $metrics['likes_count'];
            $post->comments_count   = $metrics['comments_count'];
            $post->shares_count     = $metrics['shares_count'];
            $post->reactions_count  = $metrics['reactions_count'];
            $post->engagement_count = $metrics['engagement_count'];
            $post->reach_count      = max($metrics['engagement_count'], $post->reach_count ?? 0);
            $post->last_synced_at   = now();
            $post->save();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to sync metrics for post {$post->id}: " . $e->getMessage());
        }

        return $post;
    }

    /**
     * Batch sync all published posts for a workspace with Meta API.
     */
    public function syncWorkspacePosts(int $workspaceId): void
    {
        $fbPage = FacebookPage::where('workspace_id', $workspaceId)->first() ?? FacebookPage::latest()->first();
        if (!$fbPage || empty($fbPage->page_access_token)) {
            return;
        }

        $token  = $fbPage->page_access_token;
        $pageId = $fbPage->page_id;

        \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS] syncWorkspacePosts start', [
            'workspace_id' => $workspaceId,
            'page_id'      => $pageId,
        ]);

        // 1. Sync metrics for each album's photos via /{albumId}/photos
        // NOTE: /photos?type=uploaded only returns the profile picture (1 result).
        //       The correct approach is: list albums → per-album /{albumId}/photos?limit=100.
        try {
            $albUrl = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/albums";
            $albRes = $this->client()->get($albUrl, [
                'fields'       => 'id,name',
                'limit'        => 100,
                'access_token' => $token,
            ]);

            if ($albRes->successful()) {
                foreach ($albRes->json('data') ?? [] as $album) {
                    $albumId = $album['id'];
                    $photoRes = $this->client()->timeout(10)->get("{$this->baseUrl}/{$this->apiVersion}/{$albumId}/photos", [
                        'fields'       => 'id,created_time,likes.summary(true),comments.summary(true)',
                        'limit'        => 100,
                        'access_token' => $token,
                    ]);

                    if (!$photoRes->successful()) {
                        continue;
                    }

                    foreach ($photoRes->json('data') ?? [] as $ph) {
                        $phId     = $ph['id'];
                        $likes    = (int)($ph['likes']['summary']['total_count'] ?? 0);
                        $comments = (int)($ph['comments']['summary']['total_count'] ?? 0);

                        // Match DB post by exact photo ID or compound page_id_photo_id
                        $matchedPost = FacebookPost::where('workspace_id', $workspaceId)
                            ->where(function ($q) use ($phId, $pageId) {
                                $q->where('fb_post_id', $phId)
                                  ->orWhere('fb_post_id', 'like', "%{$phId}%")
                                  ->orWhere('fb_post_id', "{$pageId}_{$phId}");
                            })->first();

                        if ($matchedPost) {
                            $matchedPost->likes_count      = $likes;
                            $matchedPost->comments_count   = $comments;
                            $matchedPost->reactions_count  = $likes;
                            $matchedPost->engagement_count = $likes + $comments + ($matchedPost->shares_count ?? 0);
                            $matchedPost->last_synced_at   = now();
                            $matchedPost->save();

                            \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS] syncWorkspacePosts: updated post', [
                                'photo_id'    => $phId,
                                'post_db_id'  => $matchedPost->id,
                                'likes'       => $likes,
                                'comments'    => $comments,
                            ]);
                        }
                    }
                }
            } else {
                \Illuminate\Support\Facades\Log::warning('[FACEBOOK METRICS] syncWorkspacePosts: albums fetch failed', [
                    'status' => $albRes->status(),
                    'error'  => $albRes->json('error'),
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('[FACEBOOK METRICS] syncWorkspacePosts album sync error: ' . $e->getMessage());
        }

        // 2. For published posts whose ID is a feed-post compound ID (e.g. pageId_postId),
        //    try fetching likes/comments via the /{photoId}/likes endpoint.
        //    NOTE: /{postId}/likes returns error #12 for deprecated photo status IDs,
        //    and error #10 for feed posts in Dev mode. Log but do not crash.
        $publishedPosts = FacebookPost::where('workspace_id', $workspaceId)
            ->where('status', 'published')
            ->whereNotNull('fb_post_id')
            ->where('fb_post_id', '!=', '')
            ->get();

        foreach ($publishedPosts as $post) {
            try {
                $fbId     = $post->fb_post_id;
                $directId = str_contains($fbId, '_') ? last(explode('_', $fbId)) : $fbId;

                $resLikes = $this->client()->timeout(5)->get("{$this->baseUrl}/{$this->apiVersion}/{$directId}/likes", [
                    'summary'      => 'true',
                    'access_token' => $token,
                ]);

                $resComments = $this->client()->timeout(5)->get("{$this->baseUrl}/{$this->apiVersion}/{$directId}/comments", [
                    'summary'      => 'true',
                    'access_token' => $token,
                ]);

                \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS] syncWorkspacePosts: post-level fetch', [
                    'fb_post_id'     => $fbId,
                    'direct_id'      => $directId,
                    'likes_status'   => $resLikes->status(),
                    'likes_error'    => $resLikes->json('error'),
                    'comments_status'=> $resComments->status(),
                    'comments_error' => $resComments->json('error'),
                ]);

                $updated = false;
                if ($resLikes->successful()) {
                    $newLikes = (int)($resLikes->json('summary.total_count') ?? 0);
                    if ($newLikes > 0 || $post->likes_count === 0) {
                        $post->likes_count = $newLikes;
                        $updated = true;
                    }
                }
                if ($resComments->successful()) {
                    $newComments = (int)($resComments->json('summary.total_count') ?? 0);
                    if ($newComments > 0 || $post->comments_count === 0) {
                        $post->comments_count = $newComments;
                        $updated = true;
                    }
                }
                if ($updated) {
                    $post->engagement_count = $post->likes_count + $post->comments_count + ($post->shares_count ?? 0);
                    $post->last_synced_at   = now();
                    $post->save();
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('[FACEBOOK METRICS] syncWorkspacePosts post-level exception: ' . $e->getMessage());
            }
        }
    }

    /**
     * Refresh page followers and fan count directly from Meta API.
     */
    public function refreshPageInsights(FacebookPage $page): void
    {
        if (empty($page->page_id) || empty($page->page_access_token)) {
            return;
        }

        try {
            $details = $this->getPageDetails($page->page_id, $page->page_access_token);
            if (!empty($details['followers_count'])) {
                $page->followers_count = (int)$details['followers_count'];
            }
            if (!empty($details['fan_count'])) {
                $page->fan_count = (int)$details['fan_count'];
            }
            if (!empty($details['profile_picture_url'])) {
                $page->profile_picture_url = $details['profile_picture_url'];
            }
            $page->token_status = 'valid';
            $page->save();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to refresh Page details for page {$page->id}: " . $e->getMessage());
        }
    }

    /**
     * Fetch live Facebook Page photo likes and comments by iterating each album.
     *
     * Strategy (verified against Meta Graph API v23.0):
     * - /photos?type=uploaded returns only the profile picture (1 result) — NOT the posted photos.
     * - /albums?fields=photos{...} returns only ~8 photos per album due to nested field pagination AND
     *   often returns 400 when sub-fields include summary().
     * - The ONLY reliable approach is: list albums (fields=id,name) → then /{albumId}/photos?limit=100
     *   for each album individually.
     *
     * Permissions: pages_show_list + pages_manage_posts (already granted).
     * pages_read_engagement is NOT required for /{albumId}/photos.
     */
    public function getPagePhotosMetrics(string $pageId, string $accessToken): array
    {
        $likes      = 0;
        $comments   = 0;
        $shares     = 0;
        $seenPhotos = [];
        $albumsProcessed = 0;
        $photosProcessed = 0;
        $errors     = [];

        \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS] getPagePhotosMetrics start', [
            'page_id'   => $pageId,
            'api'       => "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/albums",
            'strategy'  => 'per-album /{albumId}/photos?limit=100',
        ]);

        // Step 1: Get all album IDs (no nested photos — avoid the 400/pagination issue)
        try {
            $albUrl = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/albums";
            $albResponse = $this->client()->get($albUrl, [
                'fields'       => 'id,name',
                'limit'        => 100,
                'access_token' => $accessToken,
            ]);

            \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS] Albums list', [
                'page_id'       => $pageId,
                'endpoint'      => $albUrl,
                'status'        => $albResponse->status(),
                'album_count'   => count($albResponse->json('data') ?? []),
                'error'         => $albResponse->json('error'),
            ]);

            if ($albResponse->successful()) {
                // Step 2: Per album — fetch all photos with limit=100
                foreach ($albResponse->json('data') ?? [] as $album) {
                    $albumId = $album['id'];
                    $albumName = $album['name'] ?? 'Unknown';
                    $albumsProcessed++;

                    try {
                        $photoUrl = "{$this->baseUrl}/{$this->apiVersion}/{$albumId}/photos";
                        $photoResponse = $this->client()->get($photoUrl, [
                            'fields'       => 'id,created_time,likes.summary(true),comments.summary(true),shares',
                            'limit'        => 100,
                            'access_token' => $accessToken,
                        ]);

                        \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS] Album photos fetch', [
                            'page_id'    => $pageId,
                            'album_id'   => $albumId,
                            'album_name' => $albumName,
                            'endpoint'   => $photoUrl,
                            'status'     => $photoResponse->status(),
                            'photo_count'=> count($photoResponse->json('data') ?? []),
                            'error'      => $photoResponse->json('error'),
                        ]);

                        if ($photoResponse->successful()) {
                            foreach ($photoResponse->json('data') ?? [] as $photo) {
                                $phId = $photo['id'] ?? null;
                                if ($phId && !isset($seenPhotos[$phId])) {
                                    $seenPhotos[$phId] = true;
                                    $photoLikes    = (int)($photo['likes']['summary']['total_count'] ?? 0);
                                    $photoComments = (int)($photo['comments']['summary']['total_count'] ?? 0);
                                    $photoShares   = (int)($photo['shares']['count'] ?? 0);
                                    $likes    += $photoLikes;
                                    $comments += $photoComments;
                                    $shares   += $photoShares;
                                    $photosProcessed++;

                                    \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS] Photo metric', [
                                        'photo_id'       => $phId,
                                        'album_id'       => $albumId,
                                        'likes'          => $photoLikes,
                                        'comments'       => $photoComments,
                                        'shares'         => $photoShares,
                                        'created_time'   => $photo['created_time'] ?? null,
                                    ]);
                                }
                            }
                        } else {
                            $errors[] = "album_{$albumId}: " . ($photoResponse->json('error.message') ?? $photoResponse->status());
                        }
                    } catch (\Exception $e) {
                        $errors[] = "album_{$albumId}_exception: " . $e->getMessage();
                    }
                }
            } else {
                $errors[] = 'albums_fetch: ' . ($albResponse->json('error.message') ?? $albResponse->status());
            }
        } catch (\Exception $e) {
            $errors[] = 'albums_exception: ' . $e->getMessage();
        }

        \Illuminate\Support\Facades\Log::info('[FACEBOOK METRICS] getPagePhotosMetrics result', [
            'page_id'         => $pageId,
            'albums_processed'=> $albumsProcessed,
            'photos_processed'=> $photosProcessed,
            'total_likes'     => $likes,
            'total_comments'  => $comments,
            'total_shares'    => $shares,
            'errors'          => $errors,
        ]);

        return [
            'likes'    => $likes,
            'comments' => $comments,
            'shares'   => $shares,
            'errors'   => $errors,
        ];
    }
}
