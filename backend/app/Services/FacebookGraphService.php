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
    public function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withoutVerifying()
            ->timeout(60)
            ->connectTimeout(15)
            ->retry(2, 500, function (\Exception $exception) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException
                    || str_contains($exception->getMessage(), 'timed out')
                    || str_contains($exception->getMessage(), 'cURL error 28');
            }, false)
            ->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ]);
    }

    /**
     * Get Instagram Business Account Profile Details (Followers, Follows, Media Count, Username, Name, Profile Picture)
     */
    public function getInstagramProfile(string $igAccountId, string $accessToken): array
    {
        try {
            $url = "{$this->baseUrl}/{$this->apiVersion}/{$igAccountId}";
            $response = $this->client()->get($url, [
                'fields'       => 'id,username,name,followers_count,follows_count,media_count,profile_picture_url',
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[Instagram Profile Error] " . $e->getMessage());
        }

        return [];
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
            'followers_count'     => array_key_exists('followers_count', $data) && $data['followers_count'] !== null ? (int)$data['followers_count'] : null,
            'fan_count'           => array_key_exists('fan_count', $data) && $data['fan_count'] !== null ? (int)$data['fan_count'] : null,
            'profile_picture_url' => $data['picture']['data']['url'] ?? null,
        ];
    }

    /**
     * Get Facebook Page Insights & Analytics Metrics for a given period.
     * Queries valid Meta Graph API v23.0 metrics (page_views_total, page_post_engagements, page_daily_follows_unique).
     */
    public function getPageInsights(string $pageId, string $accessToken, int $days = 30, $startDate = null, $endDate = null): array
    {
        if ($startDate && $endDate) {
            $since = \Carbon\Carbon::parse($startDate)->startOfDay()->timestamp;
            $until = \Carbon\Carbon::parse($endDate)->endOfDay()->timestamp;
        } else {
            $since = now()->subDays($days - 1)->startOfDay()->timestamp;
            $until = now()->endOfDay()->timestamp;
        }

        // Meta Graph API /insights enforces a strict max window of 90 days
        if ($until - $since > 89 * 86400) {
            $since = $until - 89 * 86400;
        }

        if (!$this->tokenCanReadInsights($accessToken)) {
            return [
                'views'       => 0,
                'visits'      => 0,
                'engagements' => 0,
                'follows'     => 0,
                'fan_adds'    => 0,
                'reactions'   => 0,
                'impressions' => 0,
                'has_data'    => false,
            ];
        }

        $cacheKey = "fb_page_insights_v2_{$pageId}_{$since}_{$until}";
        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($pageId, $accessToken, $days, $since, $until) {
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
                    'has_data'    => false,
                    'error'       => $response->json('error.message') ?? 'Meta API insights unavailable',
                ];
            }

            $metrics = [];
            $items = $response->json('data') ?? [];
            foreach ($items as $item) {
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
                'views'       => 0, // Profile visits (page_views_total) must not be conflated with media/content views (page_media_view)
                'visits'      => $metrics['page_views_total'] ?? 0,
                'engagements' => $metrics['page_post_engagements'] ?? 0,
                'follows'     => $metrics['page_daily_follows_unique'] ?? 0,
                'fan_adds'    => $metrics['page_fan_adds_unique'] ?? $metrics['page_fan_adds'] ?? 0,
                'reactions'   => $metrics['page_actions_post_reactions_total'] ?? 0,
                'impressions' => $metrics['page_views_total'] ?? 0,
                'has_data'    => !empty($items),
            ];
        });
    }

    /**
     * Get day-by-day Page Insights trend for the last N days.
     * Returns a complete date-mapped series for every day in the period using real Meta API data.
     */
    public function getPageInsightsTrend(string $pageId, string $accessToken, int $days = 30, $startDate = null, $endDate = null): array
    {
        if ($startDate && $endDate) {
            $start = \Carbon\Carbon::parse($startDate)->startOfDay();
            $end   = \Carbon\Carbon::parse($endDate)->endOfDay();
            $days  = max(1, (int)$start->diffInDays($end) + 1);
        } else {
            $end   = now()->endOfDay();
            $start = now()->subDays($days - 1)->startOfDay();
        }

        $since = $start->timestamp;
        $until = $end->timestamp;

        // Meta Graph API /insights enforces a strict max window of 90 days
        $apiSince = $since;
        if ($until - $apiSince > 89 * 86400) {
            $apiSince = $until - 89 * 86400;
        }

        $url = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/insights";

        \Illuminate\Support\Facades\Log::info("[Meta API Request] getPageInsightsTrend", [
            'page_id' => $pageId,
            'days'    => $days,
            'since'   => $apiSince,
            'until'   => $until,
        ]);

        $response = $this->client()->get($url, [
            'metric'       => 'page_views_total,page_post_engagements,page_actions_post_reactions_total',
            'period'       => 'day',
            'since'        => $apiSince,
            'until'        => $until,
            'access_token' => $accessToken,
        ]);

        // Pre-build date map for ALL days in the selected period
        $reachByDate      = [];
        $engagementByDate = [];
        $labels           = [];

        for ($i = 0; $i < $days; $i++) {
            $currDate = (clone $start)->addDays($i);
            $dateStr = $currDate->format('Y-m-d');
            $label   = $currDate->format('M j');
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
                        // Meta's end_time is 1 day AHEAD of the actual data date (e.g. Aug 13 end_time = data for Aug 12)
                        // Subtract 1 day to get the real data date
                        $endTimeFull = $entry['end_time'] ?? '';
                        $date = $endTimeFull
                            ? \Carbon\Carbon::parse($endTimeFull)->subDay()->format('Y-m-d')
                            : '';
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
     * Fetch Meta's current post-level Facebook Views metric for feed posts.
     */
    public function getPostMediaViews(array $postIds, string $accessToken, $startDate = null, $endDate = null, bool $forceRefresh = false): array
    {
        $postIds = array_values(array_unique(array_filter(array_map(
            fn ($id) => is_scalar($id) ? trim((string) $id) : '',
            $postIds
        ))));

        if (empty($postIds) || empty($accessToken)) {
            return [];
        }

        if (!$this->tokenCanReadInsights($accessToken)) {
            return [];
        }

        $views = [];
        $uncachedPostIds = [];

        foreach ($postIds as $postId) {
            $cacheKey = $this->postMediaViewsCacheKey($postId, $startDate, $endDate);
            if ($forceRefresh) {
                \Illuminate\Support\Facades\Cache::forget($cacheKey);
            }
            if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                $cachedValue = \Illuminate\Support\Facades\Cache::get($cacheKey);
                if ($cachedValue !== '__none__') {
                    $views[$postId] = (int) $cachedValue;
                }
                continue;
            }

            $uncachedPostIds[] = $postId;
        }

        if (empty($uncachedPostIds)) {
            return $views;
        }

        try {
            $responses = \Illuminate\Support\Facades\Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($uncachedPostIds, $accessToken) {
                $calls = [];
                foreach ($uncachedPostIds as $postId) {
                    $calls[$postId] = $pool->as($postId)
                        ->withoutVerifying()
                        ->timeout(6)
                        ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                        ->get("{$this->baseUrl}/{$this->apiVersion}/{$postId}/insights", [
                            'metric'       => 'post_media_view',
                            'period'       => 'lifetime',
                            'access_token' => $accessToken,
                        ]);
                }

                return $calls;
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[FACEBOOK POSTS VIEWS] post_media_view pool exception', [
                'error' => $e->getMessage(),
            ]);
            return $views;
        }

        foreach ($uncachedPostIds as $postId) {
            try {
                $response = $responses[$postId] ?? null;
                $cacheKey = $this->postMediaViewsCacheKey($postId, $startDate, $endDate);

                if ($response instanceof \Throwable) {
                    \Illuminate\Support\Facades\Log::warning('[FACEBOOK POSTS VIEWS] post_media_view request failed', [
                        'post_id' => $postId,
                        'error'   => $response->getMessage(),
                    ]);
                    \Illuminate\Support\Facades\Cache::put($cacheKey, '__none__', 300);
                    continue;
                }

                if (!$response instanceof \Illuminate\Http\Client\Response || !$response->successful()) {
                    \Illuminate\Support\Facades\Log::warning('[FACEBOOK POSTS VIEWS] post_media_view unavailable', [
                        'post_id' => $postId,
                        'status'  => $response instanceof \Illuminate\Http\Client\Response ? $response->status() : null,
                        'error'   => $response instanceof \Illuminate\Http\Client\Response ? ($response->json('error') ?? $response->body()) : 'No response',
                    ]);
                    \Illuminate\Support\Facades\Cache::put($cacheKey, '__none__', 300);
                    continue;
                }

                $value = $this->extractInsightMetricTotal($response->json('data') ?? [], 'post_media_view');
                if ($value !== null) {
                    $views[$postId] = $value;
                }
                \Illuminate\Support\Facades\Cache::put($cacheKey, $value ?? '__none__', 300);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[FACEBOOK POSTS VIEWS] post_media_view exception', [
                    'post_id' => $postId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $views;
    }

    public function getCachedPostMediaViews(array $postIds, $startDate = null, $endDate = null): array
    {
        $views = [];
        $postIds = array_values(array_unique(array_filter(array_map(
            fn ($id) => is_scalar($id) ? trim((string) $id) : '',
            $postIds
        ))));

        foreach ($postIds as $postId) {
            $cacheKey = $this->postMediaViewsCacheKey($postId, $startDate, $endDate);
            if (!\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                continue;
            }

            $cachedValue = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if ($cachedValue !== '__none__') {
                $views[$postId] = (int) $cachedValue;
            }
        }

        return $views;
    }

    private function postMediaViewsCacheKey(string $postId, $startDate = null, $endDate = null): string
    {
        $range = ($startDate ?: 'all') . '_' . ($endDate ?: 'all');
        return 'fb_post_media_view_v2_' . sha1($postId . '|' . $range);
    }

    private function tokenCanReadInsights(string $accessToken): bool
    {
        if (str_starts_with($accessToken, 'fake_')) {
            return true;
        }

        $appId = (string) config('services.facebook.client_id');
        $appSecret = (string) config('services.facebook.client_secret');
        if ($appId === '' || $appSecret === '') {
            return true;
        }

        return (bool) \Illuminate\Support\Facades\Cache::remember(
            'fb_token_can_read_insights_' . sha1($accessToken),
            300,
            function () use ($accessToken, $appId, $appSecret) {
                try {
                    $response = $this->client()->timeout(6)->get("{$this->baseUrl}/{$this->apiVersion}/debug_token", [
                        'input_token'  => $accessToken,
                        'access_token' => "{$appId}|{$appSecret}",
                    ]);

                    if (!$response->successful()) {
                        return true;
                    }

                    $scopes = $response->json('data.scopes') ?? [];
                    return in_array('read_insights', $scopes, true)
                        || in_array('pages_read_engagement', $scopes, true);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[FACEBOOK POSTS VIEWS] read_insights scope check failed', [
                        'error' => $e->getMessage(),
                    ]);
                    return true;
                }
            }
        );
    }

    private function extractInsightMetricTotal(array $items, string $metricName): ?int
    {
        foreach ($items as $item) {
            if (($item['name'] ?? null) !== $metricName) {
                continue;
            }

            $total = 0;
            $found = false;

            foreach (($item['values'] ?? []) as $entry) {
                $sum = $this->sumNumericInsightValue($entry['value'] ?? null);
                if ($sum !== null) {
                    $total += $sum;
                    $found = true;
                }
            }

            return $found ? $total : null;
        }

        return null;
    }

    private function sumNumericInsightValue($value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        if (!is_array($value)) {
            return null;
        }

        $total = 0;
        $found = false;

        foreach ($value as $nestedValue) {
            $sum = $this->sumNumericInsightValue($nestedValue);
            if ($sum !== null) {
                $total += $sum;
                $found = true;
            }
        }

        return $found ? $total : null;
    }

    /**
     * Helper to log comprehensive Meta API error diagnostics and throw formatted Exception
     */
    protected function logAndThrowMetaError(string $action, string $url, string $pageOrAccountId, $response): void
    {
        $error = $response->json('error') ?? [];
        $message = $error['message'] ?? $response->json('error.message') ?? $response->body();
        $code = $error['code'] ?? $response->status();
        $type = $error['type'] ?? 'OAuthException';
        $subcode = $error['error_subcode'] ?? null;
        $userMsg = $error['error_user_msg'] ?? null;

        \Illuminate\Support\Facades\Log::error("[FACEBOOK PUBLISH FAILED]", [
            'action'             => $action,
            'endpoint'           => $url,
            'page_or_account'    => $pageOrAccountId,
            'http_status'        => $response->status(),
            'meta_error_code'    => $code,
            'meta_error_type'    => $type,
            'meta_error_subcode' => $subcode,
            'meta_error_message' => $message,
            'meta_user_msg'      => $userMsg,
            'raw_response'       => $response->body(),
        ]);

        $formatted = "Meta Error [{$code} / {$type}]: {$message}";
        if ($userMsg) {
            $formatted .= " ({$userMsg})";
        }

        // Expired / revoked access token: tell the user how to fix it (retrying cannot help until they reconnect).
        if ((int) $code === 190) {
            $formatted .= ' The access token has expired or was revoked. Reconnect the account in API Connections, then use Retry.';
        }

        // Instagram media-fetch / media-format failures: explain what Meta needs from the media URL.
        if (str_starts_with($action, 'publishInstagram')
            && (in_array((int) $code, [9004, 36000, 36001, 36003, 36004], true)
                || in_array((int) $subcode, [2207003, 2207004, 2207005, 2207009, 2207026, 2207052], true))) {
            $formatted .= ' Meta could not download or accept the media from its URL. Instagram needs a public HTTPS URL that returns HTTP 200 with a JPEG image (max 8 MB, aspect ratio between 4:5 and 1.91:1) or an MP4/MOV video.';
        }

        if ((int) $code === 200 && (str_contains($message, 'pages_manage_posts') || (str_contains($message, 'permission') && str_contains($message, 'App Review')))) {
            $formatted = "Meta Permission Error (#200): 'pages_manage_posts' is not available. To resolve: (1) For Live Meta Apps, submit 'pages_manage_posts' for Meta App Review (Advanced Access); (2) For Development Mode Apps, add this Facebook account as an App Admin/Developer/Tester, and reconnect the Page in Integrations with publishing permissions approved.";
        }

        throw new Exception($formatted);
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

        \Illuminate\Support\Facades\Log::info('[FACEBOOK PUBLISH] Starting publishTextPost', [
            'page_id'  => $pageId,
            'endpoint' => $url,
            'has_link' => !empty($linkUrl),
        ]);

        $response = $this->client()->post($url, $params);

        if (!$response->successful()) {
            $this->logAndThrowMetaError('publishTextPost', $url, $pageId, $response);
        }

        \Illuminate\Support\Facades\Log::info('[FACEBOOK PUBLISH] publishTextPost successful', [
            'page_id'  => $pageId,
            'response' => $response->json(),
        ]);

        return $response->json();
    }

    /**
     * Publish Single Photo Post
     */
    public function publishSinglePhoto(string $pageId, string $accessToken, string $caption, $fileOrUrl): array
    {
        $url = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/photos";

        \Illuminate\Support\Facades\Log::info('[FACEBOOK PUBLISH] Starting publishSinglePhoto', [
            'page_id'   => $pageId,
            'endpoint'  => $url,
            'file_type' => is_object($fileOrUrl) ? get_class($fileOrUrl) : (is_string($fileOrUrl) && file_exists($fileOrUrl) ? 'local_path' : 'url_string'),
        ]);

        if (is_object($fileOrUrl) && method_exists($fileOrUrl, 'getRealPath')) {
            $response = $this->client()->attach(
                'source',
                file_get_contents($fileOrUrl->getRealPath()),
                $fileOrUrl->getClientOriginalName()
            )->post($url, [
                'message'      => $caption,
                'access_token' => $accessToken,
            ]);
        } elseif (is_string($fileOrUrl) && file_exists($fileOrUrl)) {
            $response = $this->client()->attach(
                'source',
                file_get_contents($fileOrUrl),
                basename($fileOrUrl)
            )->post($url, [
                'caption'      => $caption,
                'access_token' => $accessToken,
            ]);
        } else {
            $response = $this->client()->post($url, [
                'url'          => (string) $fileOrUrl,
                'message'      => $caption,
                'access_token' => $accessToken,
            ]);
        }

        if (!$response->successful()) {
            $this->logAndThrowMetaError('publishSinglePhoto', $url, $pageId, $response);
        }

        \Illuminate\Support\Facades\Log::info('[FACEBOOK PUBLISH] publishSinglePhoto successful', [
            'page_id'  => $pageId,
            'response' => $response->json(),
        ]);

        return $response->json();
    }

    /**
     * Publish Multiple Photos as a Carousel / Multi-photo post
     */
    public function publishMultiplePhotos(string $pageId, string $accessToken, string $caption, array $files): array
    {
        $attachedMedia = [];

        \Illuminate\Support\Facades\Log::info('[FACEBOOK PUBLISH] Starting publishMultiplePhotos', [
            'page_id'     => $pageId,
            'total_files' => count($files),
        ]);

        foreach ($files as $file) {
            $photoUrl = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/photos";
            if (is_object($file) && method_exists($file, 'getRealPath')) {
                $response = $this->client()->attach(
                    'source',
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName()
                )->post($photoUrl, [
                    'published'    => 'false',
                    'access_token' => $accessToken,
                ]);
            } elseif (is_string($file) && file_exists($file)) {
                $response = $this->client()->attach(
                    'source',
                    file_get_contents($file),
                    basename($file)
                )->post($photoUrl, [
                    'published'    => 'false',
                    'access_token' => $accessToken,
                ]);
            } else {
                $response = $this->client()->post($photoUrl, [
                    'url'          => (string) $file,
                    'published'    => 'false',
                    'access_token' => $accessToken,
                ]);
            }

            if ($response->successful() && $response->json('id')) {
                $attachedMedia[] = ['media_fbid' => $response->json('id')];
            } else {
                $this->logAndThrowMetaError('publishMultiplePhotos (upload photo)', $photoUrl, $pageId, $response);
            }
        }

        if (empty($attachedMedia)) {
            throw new Exception('Failed to upload images for multi-photo post: No media IDs returned.');
        }

        // Post all attached media together in feed
        $feedUrl = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/feed";
        $response = $this->client()->post($feedUrl, [
            'message'        => $caption,
            'attached_media' => $attachedMedia,
            'access_token'   => $accessToken,
        ]);

        if (!$response->successful()) {
            $this->logAndThrowMetaError('publishMultiplePhotos (feed publish)', $feedUrl, $pageId, $response);
        }

        \Illuminate\Support\Facades\Log::info('[FACEBOOK PUBLISH] publishMultiplePhotos successful', [
            'page_id'  => $pageId,
            'response' => $response->json(),
        ]);

        return $response->json();
    }

    /**
     * Publish Video Post
     */
    public function publishVideo(string $pageId, string $accessToken, string $description, $fileOrUrl, ?string $thumbnailPath = null): array
    {
        $result = $this->uploadFacebookVideo($pageId, $accessToken, $description, $fileOrUrl);

        if ($thumbnailPath !== null && $thumbnailPath !== '') {
            $videoId = (string) ($result['id'] ?? '');
            $result['thumbnail'] = $videoId === ''
                ? ['requested' => true, 'applied' => false, 'message' => 'Facebook did not return a video ID, so the custom thumbnail could not be set.']
                : $this->setFacebookVideoThumbnail($videoId, $accessToken, $thumbnailPath);
        }

        return $result;
    }

    /**
     * Set the preferred (custom) thumbnail of an uploaded Facebook video: POST /{video-id}/thumbnails with
     * source=<image> and is_preferred=true. Only reported as applied when Meta answers success.
     * Never throws: the video is already published, so a thumbnail problem must not turn it into a failure.
     *
     * @return array{requested: bool, applied: bool, message: string}
     */
    public function setFacebookVideoThumbnail(string $videoId, string $accessToken, string $thumbnailPath): array
    {
        if (!is_file($thumbnailPath)) {
            return ['requested' => true, 'applied' => false, 'message' => 'The custom thumbnail file is missing from server storage.'];
        }

        $url = "{$this->baseUrl}/{$this->apiVersion}/{$videoId}/thumbnails";
        try {
            $response = $this->client()
                ->attach('source', file_get_contents($thumbnailPath), basename($thumbnailPath))
                ->post($url, [
                    'is_preferred' => 'true',
                    'access_token' => $accessToken,
                ]);

            if ($response->successful() && ($response->json('success') === true || $response->json('id'))) {
                \Illuminate\Support\Facades\Log::info('[FACEBOOK PUBLISH] Custom video thumbnail applied', ['video_id' => $videoId]);
                return ['requested' => true, 'applied' => true, 'message' => 'Custom thumbnail applied.'];
            }

            $error = $response->json('error.error_user_msg') ?? $response->json('error.message') ?? "HTTP {$response->status()}";
            \Illuminate\Support\Facades\Log::warning('[FACEBOOK PUBLISH] Custom video thumbnail rejected', ['video_id' => $videoId, 'error' => $error]);
            return ['requested' => true, 'applied' => false, 'message' => "Facebook did not accept the custom thumbnail: {$error}"];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[FACEBOOK PUBLISH] Custom video thumbnail error: ' . $e->getMessage(), ['video_id' => $videoId]);
            return ['requested' => true, 'applied' => false, 'message' => 'The custom thumbnail could not be sent to Facebook: ' . \Illuminate\Support\Str::limit($e->getMessage(), 160)];
        }
    }

    protected function uploadFacebookVideo(string $pageId, string $accessToken, string $description, $fileOrUrl): array
    {
        $url = "https://graph-video.facebook.com/{$this->apiVersion}/{$pageId}/videos";

        \Illuminate\Support\Facades\Log::info('[FACEBOOK PUBLISH] Starting publishVideo', [
            'page_id'   => $pageId,
            'endpoint'  => $url,
            'file_type' => is_object($fileOrUrl) ? get_class($fileOrUrl) : (is_string($fileOrUrl) && file_exists($fileOrUrl) ? 'local_path' : 'url_string'),
        ]);

        if (is_object($fileOrUrl) && method_exists($fileOrUrl, 'getRealPath')) {
            $response = $this->client()->attach(
                'source',
                file_get_contents($fileOrUrl->getRealPath()),
                $fileOrUrl->getClientOriginalName()
            )->post($url, [
                'description'  => $description,
                'access_token' => $accessToken,
            ]);
        } elseif (is_string($fileOrUrl) && file_exists($fileOrUrl)) {
            $response = $this->client()->attach(
                'source',
                file_get_contents($fileOrUrl),
                basename($fileOrUrl)
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
            $this->logAndThrowMetaError('publishVideo', $url, $pageId, $response);
        }

        \Illuminate\Support\Facades\Log::info('[FACEBOOK PUBLISH] publishVideo successful', [
            'page_id'  => $pageId,
            'response' => $response->json(),
        ]);

        return $response->json();
    }

    /**
     * Upload local media file to Cloudinary to obtain a public HTTPS URL accessible by Meta Graph API.
     */
    public function uploadToCloudinary($fileOrPath, string $filename, string $resourceType = 'image'): ?string
    {
        return $this->publicMedia()->uploadToCloudinary($fileOrPath, $filename, $resourceType);
    }

    /**
     * Resolves post media into a validated public HTTPS URL that Meta can download (Instagram only;
     * Facebook publishing uploads file bytes directly and does not use this).
     */
    protected function publicMedia(): \App\Services\PublicMediaUrlService
    {
        return app(\App\Services\PublicMediaUrlService::class);
    }

    /**
     * Poll an Instagram media container until Meta has finished downloading/processing the media.
     *
     * @throws Exception when Meta reports ERROR/EXPIRED or the container is still IN_PROGRESS after all attempts.
     */
    protected function waitForInstagramContainer(string $apiBase, string $containerId, string $accessToken, int $attempts, int $defaultIntervalSeconds, string $label): void
    {
        $configured = config('services.instagram.container_poll_seconds');
        $interval   = ($configured === null || $configured === '') ? $defaultIntervalSeconds : max(0, (int) $configured);
        $lastStatus = null;

        for ($i = 0; $i < $attempts; $i++) {
            if ($interval > 0) {
                sleep($interval);
            }

            $statusRes = $this->client()->get("{$apiBase}/{$containerId}", [
                'fields'       => 'status_code,status',
                'access_token' => $accessToken,
            ]);

            if (!$statusRes->successful()) {
                continue;
            }

            $statusCode = $statusRes->json('status_code');
            $lastStatus = $statusCode;

            // An empty status_code is treated as ready (matches the previous behaviour for image containers).
            if (in_array($statusCode, ['FINISHED', 'FINISHED_SUCCESS', 'PUBLISHED'], true) || empty($statusCode)) {
                return;
            }

            if ($statusCode === 'ERROR' || $statusCode === 'EXPIRED') {
                $details = $statusRes->json('status') ?? $statusRes->json('error_message') ?? $statusRes->body();
                $hint = $statusCode === 'EXPIRED'
                    ? ' The container was not published within 24 hours; please publish again.'
                    : ' Meta could not download or process the media. Make sure the media URL is public HTTPS, returns HTTP 200 and is a supported format.';
                throw new Exception("Instagram {$label} container processing failed on Meta servers: [{$statusCode}] {$details}.{$hint}");
            }
        }

        if ($lastStatus === 'IN_PROGRESS') {
            $waited = $attempts * $interval;
            throw new Exception("Instagram {$label} container processing timed out on Meta servers (still IN_PROGRESS after {$waited} seconds). Please try again in a few minutes.");
        }
    }

    /**
     * Resolve verified linked Instagram Business Account from Facebook Page
     */
    public function getLinkedInstagramBusinessAccount(string $pageId, string $pageAccessToken): ?array
    {
        try {
            $response = $this->client()->timeout(8)->get("{$this->baseUrl}/{$this->apiVersion}/{$pageId}", [
                'fields'       => 'id,name,instagram_business_account{id,username,name}',
                'access_token' => $pageAccessToken,
            ]);
            if ($response->successful() && $response->json('instagram_business_account.id')) {
                return $response->json('instagram_business_account');
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[getLinkedInstagramBusinessAccount] Failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Publish Single Photo Container & Post to Instagram Business Account
     */
    public function publishInstagramSinglePhoto(string $igAccountId, string $accessToken, string $caption, $fileOrUrl, int $workspaceId = 1): array
    {
        $igAccountId = trim((string) $igAccountId);
        $accessToken = trim((string) $accessToken);

        // Step 1: Verify correct Instagram Business Account ID and token
        if (empty($igAccountId)) {
            throw new Exception("Instagram publishing failed: Instagram Business Account ID is missing.");
        }
        if (empty($accessToken)) {
            throw new Exception("Instagram publishing failed: Access token is missing.");
        }

        $originalName = is_object($fileOrUrl) && method_exists($fileOrUrl, 'getClientOriginalName')
            ? $fileOrUrl->getClientOriginalName()
            : (is_string($fileOrUrl) ? basename(str_replace('\\', '/', $fileOrUrl)) : 'image.jpg');

        // Step 2: Build the public HTTPS URL Meta will download, from configuration (never from the request host),
        // and verify it is HTTPS, public, returns HTTP 200 + image/jpeg and meets Instagram's format rules.
        $mediaUrl = $this->publicMedia()->resolveForInstagram($fileOrUrl, $workspaceId, 'image');

        // Determine API endpoint base: graph.instagram.com for IGAA... tokens, graph.facebook.com for EAA... tokens
        $isIgToken = str_starts_with($accessToken, 'IGAA');
        $apiBase   = $isIgToken ? 'https://graph.instagram.com/v23.0' : "{$this->baseUrl}/{$this->apiVersion}";

        // Step 3: Create Instagram media container
        $containerUrl = "{$apiBase}/{$igAccountId}/media";
        $response = $this->client()->post($containerUrl, [
            'image_url'    => $mediaUrl,
            'caption'      => $caption,
            'access_token' => $accessToken,
        ]);

        if (!$response->successful() || !($containerId = $response->json('id'))) {
            $this->logAndThrowMetaError('publishInstagramSinglePhoto (create container)', $containerUrl, $igAccountId, $response);
        }

        // Step 4: Wait until container is ready (up to 10 attempts, 2 seconds each)
        $this->waitForInstagramContainer($apiBase, (string) $containerId, $accessToken, 10, 2, 'media');

        // Step 5: Call media_publish
        $publishUrl = "{$apiBase}/{$igAccountId}/media_publish";
        $pubResponse = $this->client()->post($publishUrl, [
            'creation_id'  => $containerId,
            'access_token' => $accessToken,
        ]);

        if (!$pubResponse->successful()) {
            $this->logAndThrowMetaError('publishInstagramSinglePhoto (publish container)', $publishUrl, $igAccountId, $pubResponse);
        }

        // Step 6: Only after media_publish returns the final Instagram media ID, return published status
        $publishedId = $pubResponse->json('id');
        if (empty($publishedId)) {
            throw new Exception("Instagram media_publish succeeded but Meta did not return a valid published media ID.");
        }

        \Illuminate\Support\Facades\Log::info('[Instagram Publishing Success]', [
            'workspace_id'         => $workspaceId,
            'selected_file_name'   => $originalName,
            'public_media_url'     => $mediaUrl,
            'instagram_account_id' => $igAccountId,
            'container_id'         => $containerId,
            'published_media_id'   => $publishedId,
        ]);

        return [
            'id'     => (string) $publishedId,
            'status' => 'published',
        ];
    }

    /**
     * Publish Reel / Video Container & Post to Instagram Business Account
     */
    public function publishInstagramVideo(string $igAccountId, string $accessToken, string $caption, $fileOrUrl, int $workspaceId = 1, $coverSource = null): array
    {
        $originalName = is_object($fileOrUrl) && method_exists($fileOrUrl, 'getClientOriginalName')
            ? $fileOrUrl->getClientOriginalName()
            : (is_string($fileOrUrl) ? basename(str_replace('\\', '/', $fileOrUrl)) : 'video.mp4');

        // Public HTTPS URL Meta downloads the video from (validated: HTTPS, public host, HTTP 200, video/*).
        $videoUrl = $this->publicMedia()->resolveForInstagram($fileOrUrl, $workspaceId, 'video');

        // Optional custom Reel cover (cover_url). Meta downloads it like the video, so it needs a public HTTPS
        // JPEG URL too. If that is not possible the Reel is still published, without a custom cover, and the
        // result says so; it is never reported as applied unless Meta published the container that carried it.
        $thumbnail = null;
        $coverUrl = null;
        if ($coverSource !== null && $coverSource !== '') {
            try {
                $coverUrl = $this->publicMedia()->resolveForInstagram($coverSource, $workspaceId, 'cover');
            } catch (\App\Exceptions\InstagramMediaException $e) {
                $thumbnail = ['requested' => true, 'applied' => false, 'message' => 'Custom Reel cover not applied: ' . $e->getMessage()];
            }
        }

        $isIgToken = str_starts_with($accessToken, 'IGAA');
        $apiBase   = $isIgToken ? 'https://graph.instagram.com/v23.0' : "{$this->baseUrl}/{$this->apiVersion}";

        $containerUrl = "{$apiBase}/{$igAccountId}/media";
        $containerParams = [
            'media_type'   => 'REELS',
            'video_url'    => $videoUrl,
            'caption'      => $caption,
            'access_token' => $accessToken,
        ];
        $response = $this->client()->post($containerUrl, $coverUrl ? $containerParams + ['cover_url' => $coverUrl] : $containerParams);

        if ($coverUrl && (!$response->successful() || !$response->json('id'))) {
            // The account/API rejected the container with a cover: retry once without cover_url so the Reel
            // itself can still be published, and report why the cover was not applied.
            $coverError = $response->json('error.error_user_msg') ?? $response->json('error.message') ?? "HTTP {$response->status()}";
            \Illuminate\Support\Facades\Log::warning('[Instagram Reel] Container with cover_url rejected; retrying without cover', ['error' => $coverError]);
            $thumbnail = ['requested' => true, 'applied' => false, 'message' => "Instagram did not accept the custom Reel cover: {$coverError}"];
            $coverUrl = null;
            $response = $this->client()->post($containerUrl, $containerParams);
        }

        if (!$response->successful() || !($containerId = $response->json('id'))) {
            $this->logAndThrowMetaError('publishInstagramVideo (create container)', $containerUrl, $igAccountId, $response);
        }

        // Wait for Meta to download and transcode the video (up to 20 attempts, 3 seconds each)
        $this->waitForInstagramContainer($apiBase, (string) $containerId, $accessToken, 20, 3, 'video');

        $publishUrl = "{$apiBase}/{$igAccountId}/media_publish";
        $pubResponse = $this->client()->post($publishUrl, [
            'creation_id'  => $containerId,
            'access_token' => $accessToken,
        ]);

        if (!$pubResponse->successful()) {
            $this->logAndThrowMetaError('publishInstagramVideo (publish container)', $publishUrl, $igAccountId, $pubResponse);
        }

        $publishedId = $pubResponse->json('id');

        \Illuminate\Support\Facades\Log::info('[Instagram Video Publishing Flow]', [
            'workspace_id'         => $workspaceId,
            'selected_file_name'   => $originalName,
            'public_media_url'     => $videoUrl,
            'instagram_account_id' => $igAccountId,
            'container_response'   => $response->json(),
            'container_id'         => $containerId,
            'publish_response'     => $pubResponse->json(),
            'published_media_id'   => $publishedId,
            'cover_url'            => $coverUrl,
        ]);

        $result = $pubResponse->json() ?? [];
        if ($coverUrl && !empty($publishedId)) {
            // Meta published the container that carried cover_url.
            $thumbnail = ['requested' => true, 'applied' => true, 'message' => 'Custom Reel cover applied.'];
        }
        if ($thumbnail !== null) {
            $result['thumbnail'] = $thumbnail;
        }

        return $result;
    }

    /**
     * Publish Carousel (multi-image) Container & Post to Instagram Business Account
     */
    public function publishInstagramCarousel(string $igAccountId, string $accessToken, string $caption, array $filesOrUrls, int $workspaceId = 1): array
    {
        $childContainerIds = [];
        $isIgToken = str_starts_with($accessToken, 'IGAA');
        $apiBase   = $isIgToken ? 'https://graph.instagram.com/v23.0' : "{$this->baseUrl}/{$this->apiVersion}";

        // Resolve and validate every image URL before creating any container, so one bad image fails
        // the whole carousel up front instead of leaving orphaned item containers on Meta.
        $mediaUrls = [];
        foreach (array_values($filesOrUrls) as $index => $fileOrUrl) {
            try {
                $mediaUrls[$index] = $this->publicMedia()->resolveForInstagram($fileOrUrl, $workspaceId, 'image');
            } catch (\App\Exceptions\InstagramMediaException $e) {
                throw new \App\Exceptions\InstagramMediaException('Carousel image ' . ($index + 1) . ': ' . $e->getMessage(), 0, $e);
            }
        }

        foreach ($mediaUrls as $index => $mediaUrl) {
            $itemContainerUrl = "{$apiBase}/{$igAccountId}/media";
            $itemResponse = $this->client()->post($itemContainerUrl, [
                'media_type'        => 'IMAGE',
                'image_url'         => $mediaUrl,
                'is_carousel_item'  => 'true',
                'access_token'      => $accessToken,
            ]);

            if (!$itemResponse->successful() || !($itemId = $itemResponse->json('id'))) {
                $this->logAndThrowMetaError("publishInstagramCarousel (item {$index})", $itemContainerUrl, $igAccountId, $itemResponse);
            }

            $childContainerIds[] = $itemId;
        }

        if (empty($childContainerIds)) {
            throw new Exception('No valid media items created for Instagram carousel.');
        }

        $carouselContainerUrl = "{$apiBase}/{$igAccountId}/media";
        $carouselResponse = $this->client()->post($carouselContainerUrl, [
            'media_type'   => 'CAROUSEL',
            'children'     => implode(',', $childContainerIds),
            'caption'      => $caption,
            'access_token' => $accessToken,
        ]);

        if (!$carouselResponse->successful() || !($carouselContainerId = $carouselResponse->json('id'))) {
            $this->logAndThrowMetaError('publishInstagramCarousel (create parent container)', $carouselContainerUrl, $igAccountId, $carouselResponse);
        }

        $this->waitForInstagramContainer($apiBase, (string) $carouselContainerId, $accessToken, 5, 2, 'carousel');

        $publishUrl = "{$apiBase}/{$igAccountId}/media_publish";
        $pubResponse = $this->client()->post($publishUrl, [
            'creation_id'  => $carouselContainerId,
            'access_token' => $accessToken,
        ]);

        if (!$pubResponse->successful()) {
            $this->logAndThrowMetaError('publishInstagramCarousel (publish container)', $publishUrl, $igAccountId, $pubResponse);
        }

        \Illuminate\Support\Facades\Log::info('[Instagram Carousel Publishing Flow]', [
            'workspace_id'         => $workspaceId,
            'item_count'           => count($childContainerIds),
            'public_media_urls'    => array_values($mediaUrls),
            'instagram_account_id' => $igAccountId,
            'carousel_container_id'=> $carouselContainerId,
            'published_media_id'   => $pubResponse->json('id'),
        ]);

        return $pubResponse->json();
    }

    /**
     * Resolve Instagram Business Account ID from access token or database if not directly provided.
     */
    public function resolveInstagramAccountId(string $accessToken, $igAccountId = null): ?string
    {
        if (!empty($igAccountId) && (!is_numeric($igAccountId) || strlen((string)$igAccountId) > 6)) {
            return (string)$igAccountId;
        }

        if (str_starts_with($accessToken, 'IGAA')) {
            return 'me';
        }

        try {
            $response = $this->client()->timeout(5)->get("{$this->baseUrl}/{$this->apiVersion}/me", [
                'fields'       => 'instagram_business_account',
                'access_token' => $accessToken,
            ]);
            if ($response->successful() && $response->json('instagram_business_account.id')) {
                return (string)$response->json('instagram_business_account.id');
            }
        } catch (\Throwable $e) {}

        return !empty($igAccountId) ? (string)$igAccountId : 'me';
    }

    /**
     * Fetch Instagram Business / Professional Account Insights via Instagram Graph API.
     */
    public function getInstagramAccountInsights(string $accessToken, $igAccountId = null, int $days = 30, $startDate = null, $endDate = null): array
    {
        if (is_numeric($igAccountId) && (int)$igAccountId < 10000) {
            $endDate = $startDate;
            $startDate = $days;
            $days = (int)$igAccountId;
            $igAccountId = null;
        }

        $igAccountId = $this->resolveInstagramAccountId($accessToken, $igAccountId);

        if ($startDate && $endDate) {
            $since = \Carbon\Carbon::parse($startDate)->startOfDay()->timestamp;
            $until = \Carbon\Carbon::parse($endDate)->endOfDay()->timestamp;
        } else {
            $since = now()->subDays($days - 1)->startOfDay()->timestamp;
            $until = now()->endOfDay()->timestamp;
        }

        // Meta Graph API enforces a strict max window of 90 days for /insights
        if ($until - $since > 89 * 86400) {
            $since = $until - 89 * 86400;
        }

        $isIgToken   = str_starts_with($accessToken, 'IGAA');
        $baseUrl     = $isIgToken ? 'https://graph.instagram.com/v23.0' : "{$this->baseUrl}/{$this->apiVersion}";
        $targetNode  = !empty($igAccountId) ? $igAccountId : 'me';
        $url         = "{$baseUrl}/{$targetNode}/insights";

        \Illuminate\Support\Facades\Log::info("[Instagram API Request] getInstagramAccountInsights", [
            'target_node' => $targetNode,
            'days'        => $days,
            'since'       => $since,
            'until'       => $until,
        ]);

        // Attempt 1: Graph API v22+/v23+ total_value metrics
        $response = $this->client()->get($url, [
            'metric'       => 'reach,total_interactions,accounts_engaged',
            'metric_type'  => 'total_value',
            'period'       => 'day',
            'since'        => $since,
            'until'        => $until,
            'access_token' => $accessToken,
        ]);

        if (!$response->successful()) {
            // Attempt 2: Fallback for older Graph versions or alternative metric types
            $fallbackRes = $this->client()->get($url, [
                'metric'       => 'reach',
                'period'       => 'day',
                'since'        => $since,
                'until'        => $until,
                'access_token' => $accessToken,
            ]);

            if ($fallbackRes->successful()) {
                $response = $fallbackRes;
            } else {
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
        }

        $metrics = [];
        foreach ($response->json('data') ?? [] as $item) {
            $name = $item['name'] ?? '';
            // Parse total_value if available, else sum entry values
            if (isset($item['total_value']['value'])) {
                $metrics[$name] = (int)$item['total_value']['value'];
            } else {
                $values = $item['values'] ?? [];
                $sum = 0;
                foreach ($values as $entry) {
                    $val = is_array($entry['value'] ?? null) ? array_sum($entry['value']) : (int)($entry['value'] ?? 0);
                    $sum += $val;
                }
                $metrics[$name] = $sum;
            }
        }

        \Illuminate\Support\Facades\Log::info("[Instagram API Response] getInstagramAccountInsights", [
            'target_node' => $targetNode,
            'metrics'     => $metrics,
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
     * Fetch Instagram followers gained during the date range via follower_count metric.
     */
    public function getInstagramFollowersGained(string $accessToken, $igAccountId = null, int $days = 30, $startDate = null, $endDate = null): int
    {
        if (is_numeric($igAccountId) && (int)$igAccountId < 10000) {
            $endDate = $startDate;
            $startDate = $days;
            $days = (int)$igAccountId;
            $igAccountId = null;
        }

        $igAccountId = $this->resolveInstagramAccountId($accessToken, $igAccountId);
        if (empty($igAccountId)) {
            return 0;
        }

        if ($startDate && $endDate) {
            $since = \Carbon\Carbon::parse($startDate)->startOfDay()->timestamp;
            $until = \Carbon\Carbon::parse($endDate)->endOfDay()->timestamp;
        } else {
            $since = now()->subDays($days - 1)->startOfDay()->timestamp;
            $until = now()->endOfDay()->timestamp;
        }

        // Meta Graph API enforces a strict max window of 90 days for /insights
        if ($until - $since > 89 * 86400) {
            $since = $until - 89 * 86400;
        }

        $isIgToken  = str_starts_with($accessToken, 'IGAA');
        $baseUrl    = $isIgToken ? 'https://graph.instagram.com/v23.0' : "{$this->baseUrl}/{$this->apiVersion}";
        $targetNode = !empty($igAccountId) ? $igAccountId : 'me';
        $url        = "{$baseUrl}/{$targetNode}/insights";

        try {
            $response = $this->client()->timeout(5)->get($url, [
                'metric'       => 'follower_count',
                'period'       => 'day',
                'since'        => $since,
                'until'        => $until,
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                $total = 0;
                foreach ($response->json('data') ?? [] as $item) {
                    if (($item['name'] ?? '') === 'follower_count') {
                        foreach ($item['values'] ?? [] as $v) {
                            $total += (int)($v['value'] ?? 0);
                        }
                    }
                }
                return $total;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[Instagram API Exception] getInstagramFollowersGained: " . $e->getMessage());
        }

        return 0;
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
                        'metric'       => 'post_media_view',
                        'access_token' => $accessToken,
                    ]);

                    if ($insightRes->successful()) {
                        $pViewsData = $insightRes->json('data') ?? [];
                        $viewVal = $this->extractInsightMetricTotal($pViewsData, 'post_media_view');

                        \Illuminate\Support\Facades\Log::info("[FACEBOOK VIEWS] Post media views found", [
                            'Page ID'             => $pageId,
                            'Post ID'             => $firstPostId,
                            'Metric'              => 'post_media_view',
                            'Meta response'       => $insightRes->json(),
                            'Final backend value' => $viewVal,
                            'Supported'           => true,
                        ]);

                        return [
                            'views'     => $viewVal ?? 0,
                            'supported' => true,
                            'reason'    => 'post_media_view_supported',
                        ];
                    }

                    $postError = $insightRes->json('error.message') ?? $insightRes->body();
                    $errorCode = $insightRes->json('error.code');

                    \Illuminate\Support\Facades\Log::info("[FACEBOOK VIEWS]", [
                        'Page ID'             => $pageId,
                        'Post/Media ID'       => $firstPostId,
                        'Media type'          => 'post',
                        'Endpoint'            => "{$this->baseUrl}/{$this->apiVersion}/{$firstPostId}/insights",
                        'Metric'              => 'post_media_view',
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
            'Metric'              => 'post_media_view',
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
     * Extracts real likes and comments via attachment media targets (photo/video objects) and video views.
     * Upserts posts into the local FacebookPost table for persistent and fast reporting.
     */
    public function getFacebookFeedPostMetrics(string $pageId, string $accessToken, int $days = 30, bool $forceRefresh = false, $startDate = null, $endDate = null, ?int $workspaceId = null, ?int $cacheTtl = null): array
    {
        $ttl = ($cacheTtl !== null && $cacheTtl > 0) ? $cacheTtl : 300;
        $startStr = $startDate ? \Carbon\Carbon::parse($startDate)->format('Ymd') : '';
        $endStr   = $endDate ? \Carbon\Carbon::parse($endDate)->format('Ymd') : '';
        $cacheKey = "fb_feed_metrics_v2_{$workspaceId}_{$pageId}_{$days}_{$startStr}_{$endStr}" . ($ttl < 300 ? "_{$ttl}" : "");
        if ($forceRefresh) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
        }
        return \Illuminate\Support\Facades\Cache::remember($cacheKey, $ttl, function () use ($pageId, $accessToken, $days, $forceRefresh, $startDate, $endDate, $workspaceId, $ttl) {
            if ($startDate && $endDate) {
                $since = \Carbon\Carbon::parse($startDate)->startOfDay()->timestamp;
                $until = \Carbon\Carbon::parse($endDate)->endOfDay()->timestamp;
            } else {
                $since = strtotime("-{$days} days midnight UTC");
                $until = time();
            }

            \Illuminate\Support\Facades\Log::info("[FACEBOOK API CALL] getFacebookFeedPostMetrics start", [
                'page_id'     => $pageId,
                'days'        => $days,
                'since'       => date('Y-m-d H:i:s', $since),
                'until'       => date('Y-m-d H:i:s', $until),
            ]);

            // 1. Fetch published posts with attachments from Meta Graph API v23.0 (with pagination support)
            $publishedPostsEndpoint = "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/published_posts";
            $publishedPostsParams = [
                'fields'       => 'id,message,created_time,shares,permalink_url,attachments{target,type,subattachments{target,type}}',
                'limit'        => 100,
                'access_token' => $accessToken,
            ];
            if ($since) {
                $publishedPostsParams['since'] = $since;
            }
            if ($until) {
                $publishedPostsParams['until'] = $until;
            }

            $postsData  = [];
            $nextUrl     = $publishedPostsEndpoint;
            $currentParams = $publishedPostsParams;

            // Safety ceiling: for live auto-sync, 2 pages (200 posts) is fast and sufficient.
            $safetyLimit = ($ttl < 300) ? 2 : 200;
            $pageIter    = 0;

            while ($nextUrl) {
                if ($pageIter >= $safetyLimit) {
                    \Illuminate\Support\Facades\Log::warning(
                        "[FACEBOOK PAGINATION] Safety ceiling ({$safetyLimit} pages) reached for page {$pageId}. " .
                        "Stopping cursor walk. Total posts collected so far: " . count($postsData)
                    );
                    break;
                }

                // Polite rate-limit courtesy: brief pause every 5 API calls
                if ($pageIter > 0 && $pageIter % 5 === 0) {
                    usleep(500000); // 0.5 s
                }

                $pageIter++;
                $response = $this->client()->timeout(20)->get($nextUrl, $currentParams ?: null);
                if (!$response->successful()) {
                    \Illuminate\Support\Facades\Log::warning(
                        "[FACEBOOK PAGINATION] Non-200 response on page {$pageIter} for {$pageId}: " .
                        $response->status()
                    );
                    break;
                }

                $json       = $response->json();
                $batchPosts = $json['data'] ?? [];

                if (empty($batchPosts)) {
                    // No more posts returned — cursor exhausted
                    break;
                }

                $postsData = array_merge($postsData, $batchPosts);

                // Early-exit: Meta returns posts newest → oldest.
                // Once the last post in this batch pre-dates our window start
                // all subsequent pages will also be out of range.
                if ($since) {
                    $lastPost     = end($batchPosts);
                    $lastPostTime = !empty($lastPost['created_time']) ? strtotime($lastPost['created_time']) : 0;
                    if ($lastPostTime > 0 && $lastPostTime < $since) {
                        break;
                    }
                }

                $nextUrl       = $json['paging']['next'] ?? null;
                $currentParams = []; // subsequent requests use the full cursor URL, no extra params
            }

            \Illuminate\Support\Facades\Log::info("[FACEBOOK PAGINATION] Cursor walk complete", [
                'page_id'      => $pageId,
                'pages_walked' => $pageIter,
                'total_posts'  => count($postsData),
            ]);

            $fbPage = null;
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('facebook_pages')) {
                    $fbPage = FacebookPage::where('page_id', $pageId)
                        ->when($workspaceId !== null, fn($q) => $q->where('workspace_id', $workspaceId))->first();
                }
            } catch (\Throwable $e) {}

            $totalLikes       = 0;
            $totalComments    = 0;
            $totalShares      = 0;
            $totalViews       = 0;
            $periodPostsCount = 0;
            $postsByDate      = [];

            // Resolve Canonical Page ID from permalinks
            $canonicalPageId = $this->resolveCanonicalPageId($pageId, $postsData, $accessToken);

            // Fetch Page Reel IDs to differentiate Reels from regular landscape/standard Videos
            $reelIds = [];
            try {
                $reelIds = \Illuminate\Support\Facades\Cache::remember("fb_page_reels_{$pageId}", 600, function () use ($pageId, $accessToken) {
                    $res = $this->client()->get("{$this->baseUrl}/{$this->apiVersion}/{$pageId}/video_reels", [
                        'fields'       => 'id',
                        'limit'        => 100,
                        'access_token' => $accessToken,
                    ]);
                    return collect($res->json('data') ?? [])->pluck('id')->map(fn($id) => (string)$id)->all();
                });
            } catch (\Throwable $e) {}

            $postParsed = [];
            $poolEndpoints = [];

            foreach ($postsData as $p) {
                $cTime = !empty($p['created_time']) ? strtotime($p['created_time']) : null;
                if ($since && $until && $cTime) {
                    if ($cTime < $since || $cTime > $until) {
                        continue;
                    }
                }
                $postId = $p['id'];
                $shortId = last(explode('_', $postId));
                $canonicalPostId = "{$canonicalPageId}_{$shortId}";
                $permalink = $p['permalink_url'] ?? '';
                $attachments = $p['attachments']['data'] ?? [];

                $targets = [];
                $isVideo = false;
                $isMulti = false;
                $isReel = false;
                $videoId = null;

                if (str_contains($permalink, '/reel/')) {
                    $isReel = true;
                    if (preg_match('#/reel/(\d+)#', $permalink, $rm)) {
                        $videoId = (string)$rm[1];
                    }
                }

                foreach ($attachments as $att) {
                    $type = $att['type'] ?? $att['media_type'] ?? '';
                    $isVid = in_array($type, ['video_inline', 'video', 'video_direct_response']) || ($att['media_type'] ?? '') === 'video';
                    if ($isVid) {
                        $isVideo = true;
                        if (!empty($att['target']['id'])) {
                            $videoId = (string)$att['target']['id'];
                            $targets[] = ['id' => (string)$att['target']['id'], 'is_video' => true];
                        }
                    }
                    if (!empty($att['subattachments']['data'])) {
                        $isMulti = true;
                        foreach ($att['subattachments']['data'] as $sub) {
                            if (!empty($sub['target']['id'])) {
                                $targets[] = ['id' => (string)$sub['target']['id'], 'is_video' => false];
                            }
                        }
                    } elseif (!empty($att['target']['id']) && $type !== 'album' && !$isVid) {
                        $targets[] = ['id' => (string)$att['target']['id'], 'is_video' => false];
                    }
                }

                if ($videoId && in_array($videoId, $reelIds, true)) {
                    $isReel = true;
                }

                // Register pool endpoints for this post
                $poolEndpoints["post_canon_{$canonicalPostId}"] = [
                    'url'    => "{$this->baseUrl}/{$this->apiVersion}/{$canonicalPostId}",
                    'params' => ['fields' => 'id,shares,reactions.summary(total_count)', 'access_token' => $accessToken],
                ];
                if ($postId !== $canonicalPostId) {
                    $poolEndpoints["post_direct_{$postId}"] = [
                        'url'    => "{$this->baseUrl}/{$this->apiVersion}/{$postId}",
                        'params' => ['fields' => 'id,shares,reactions.summary(total_count)', 'access_token' => $accessToken],
                    ];
                }
                if ($videoId) {
                    $poolEndpoints["vid_{$videoId}"] = [
                        'url'    => "{$this->baseUrl}/{$this->apiVersion}/{$videoId}",
                        'params' => ['fields' => 'id,views,likes.summary(true),comments.summary(true)', 'access_token' => $accessToken],
                    ];
                }
                // Include photo targets for mock test fallback
                foreach ($targets as $t) {
                    if (!$t['is_video'] && !empty($t['id'])) {
                        $tId = $t['id'];
                        if (!isset($poolEndpoints["tgt_{$tId}"])) {
                            $poolEndpoints["tgt_{$tId}"] = [
                                'url'    => "{$this->baseUrl}/{$this->apiVersion}/{$tId}",
                                'params' => ['fields' => 'id,likes.summary(true),comments.summary(true)', 'access_token' => $accessToken],
                            ];
                        }
                    }
                }

                $postParsed[] = [
                    'id'                => $postId,
                    'short_id'          => $shortId,
                    'canonical_post_id' => $canonicalPostId,
                    'post'              => $p,
                    'created'           => $cTime,
                    'shares'            => (int)($p['shares']['count'] ?? 0),
                    'targets'           => $targets,
                    'is_video'          => $isVideo,
                    'is_reel'           => $isReel,
                    'is_multi'          => $isMulti,
                    'video_id'          => $videoId,
                ];
            }

            // Concurrently query Meta Graph API for all endpoints
            $postReactions = [];
            $postShares = [];
            $videoMetrics = [];
            $photoTargetMetrics = [];

            if (!empty($poolEndpoints)) {
                try {
                    $responses = \Illuminate\Support\Facades\Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($poolEndpoints) {
                        return collect($poolEndpoints)->map(function ($req, $key) use ($pool) {
                            return $pool->as($key)->withoutVerifying()->timeout(8)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                                ->get($req['url'], $req['params']);
                        })->all();
                    });

                    foreach ($poolEndpoints as $key => $req) {
                        $r = $responses[$key] ?? null;

                        if ($r instanceof \Throwable) {
                            \Illuminate\Support\Facades\Log::warning("[FACEBOOK POOL METRICS ERROR] Request failed for {$key}: " . $r->getMessage());
                            continue;
                        }

                        if (!$r instanceof \Illuminate\Http\Client\Response) {
                            continue;
                        }

                        if ($r->successful()) {
                            $d = $r->json();
                            if (str_starts_with($key, 'vid_')) {
                                $vId = substr($key, 4);
                                $videoMetrics[$vId] = [
                                    'views'    => isset($d['views']) ? (int)$d['views'] : null,
                                    'likes'    => isset($d['likes']['summary']['total_count']) ? (int)$d['likes']['summary']['total_count'] : null,
                                    'comments' => isset($d['comments']['summary']['total_count']) ? (int)$d['comments']['summary']['total_count'] : null,
                                ];
                            } elseif (str_starts_with($key, 'tgt_')) {
                                $tId = substr($key, 4);
                                $photoTargetMetrics[$tId] = [
                                    'likes'    => (int)($d['likes']['summary']['total_count'] ?? 0),
                                    'comments' => (int)($d['comments']['summary']['total_count'] ?? 0),
                                ];
                            } elseif (str_starts_with($key, 'post_canon_')) {
                                $cId = substr($key, 11);
                                if (isset($d['reactions']['summary']['total_count'])) {
                                    $postReactions[$cId] = (int)$d['reactions']['summary']['total_count'];
                                }
                                if (isset($d['shares']['count'])) {
                                    $postShares[$cId] = (int)$d['shares']['count'];
                                }
                            } elseif (str_starts_with($key, 'post_direct_')) {
                                $fId = substr($key, 12);
                                if (isset($d['reactions']['summary']['total_count'])) {
                                    $postReactions[$fId] = (int)$d['reactions']['summary']['total_count'];
                                }
                                if (isset($d['shares']['count'])) {
                                    $postShares[$fId] = (int)$d['shares']['count'];
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("[FACEBOOK POOL METRICS ERROR] " . $e->getMessage());
                }
            }

            $postMediaViews = $this->getPostMediaViews(
                array_column($postParsed, 'id'),
                $accessToken,
                $startDate,
                $endDate,
                $forceRefresh
            );
            $hasViews = false;

            foreach ($postParsed as $pInfo) {
                $p = $pInfo['post'];
                $postId = $pInfo['id'];
                $cId = $pInfo['canonical_post_id'];
                $vId = $pInfo['video_id'];
                $cTime = $pInfo['created'];
                $periodPostsCount++;

                // 1. Reactions / Likes
                $pLikes = null;
                if (isset($postReactions[$cId])) {
                    $pLikes = $postReactions[$cId];
                } elseif (isset($postReactions[$postId])) {
                    $pLikes = $postReactions[$postId];
                } elseif ($vId && isset($videoMetrics[$vId]['likes'])) {
                    $pLikes = $videoMetrics[$vId]['likes'];
                } elseif (!empty($pInfo['targets'])) {
                    $sumPhotoLikes = 0;
                    $hasPhotoTarget = false;
                    foreach ($pInfo['targets'] as $t) {
                        if (isset($photoTargetMetrics[$t['id']])) {
                            $sumPhotoLikes += $photoTargetMetrics[$t['id']]['likes'];
                            $hasPhotoTarget = true;
                        }
                    }
                    if ($hasPhotoTarget) {
                        $pLikes = $sumPhotoLikes;
                    }
                }
                if ($pLikes === null) {
                    $pLikes = 0;
                }

                // 2. Comments
                $pComments = null;
                if ($vId && isset($videoMetrics[$vId]['comments'])) {
                    $pComments = $videoMetrics[$vId]['comments'];
                } elseif (!empty($pInfo['targets'])) {
                    $sumPhotoComments = 0;
                    $hasPhotoComments = false;
                    foreach ($pInfo['targets'] as $t) {
                        if (isset($photoTargetMetrics[$t['id']]['comments']) && $photoTargetMetrics[$t['id']]['comments'] > 0) {
                            $sumPhotoComments += $photoTargetMetrics[$t['id']]['comments'];
                            $hasPhotoComments = true;
                        }
                    }
                    if ($hasPhotoComments) {
                        $pComments = $sumPhotoComments;
                    }
                }

                // 3. Views
                // Prefer Meta's Page Post Media View metric; keep video-object views as the existing video/reel fallback.
                $pViews = null;
                if (array_key_exists($postId, $postMediaViews)) {
                    $pViews = $postMediaViews[$postId];
                } elseif ($vId && isset($videoMetrics[$vId]['views'])) {
                    $pViews = $videoMetrics[$vId]['views'];
                }
                if ($pViews !== null) {
                    \Illuminate\Support\Facades\Cache::put("fb_video_views_{$postId}", $pViews, 86400);
                    $totalViews += (int)$pViews;
                    $hasViews = true;
                }

                // 4. Shares
                $pShares = $postShares[$cId] ?? $postShares[$postId] ?? $pInfo['shares'] ?? 0;
                $totalShares += $pShares;

                // Upsert into local FacebookPost table for persistent storage & table display
                if ($fbPage) {
                    $postType = $pInfo['is_reel'] ? 'reel' : ($pInfo['is_video'] ? 'video' : ($pInfo['is_multi'] ? 'multi_image' : 'single_image'));
                    $imgUrl = $p['full_picture'] ?? $p['picture'] ?? null;
                    if (!$imgUrl && !empty($pInfo['post']['attachments']['data'][0]['media']['image']['src'])) {
                        $imgUrl = $pInfo['post']['attachments']['data'][0]['media']['image']['src'];
                    }
                    $this->persistFacebookPost(
                        $fbPage,
                        $postId,
                        [
                            'facebook_page_id' => $fbPage->id,
                            'content'          => $p['message'] ?? '',
                            'post_type'        => $postType,
                            'link_url'         => $p['permalink_url'] ?? null,
                            'status'           => 'published',
                            'published_at'     => $cTime ? \Carbon\Carbon::createFromTimestamp($cTime) : now(),
                            'created_at'       => $cTime ? \Carbon\Carbon::createFromTimestamp($cTime) : now(),
                            'views_count'      => $pViews,
                            'likes_count'      => $pLikes,
                            'comments_count'   => $pComments,
                            'shares_count'     => $pShares,
                            'reactions_count'  => $pLikes,
                            'engagement_count' => ($pLikes !== null || $pComments !== null || $pShares !== null)
                                ? ((int)($pLikes ?? 0) + (int)($pComments ?? 0) + (int)($pShares ?? 0))
                                : 0,
                            'last_synced_at'   => now(),
                        ],
                        $imgUrl,
                        ($pInfo['is_video'] || $pInfo['is_reel'])
                    );
                }

                $totalLikes    += (int)$pLikes;
                $totalComments += (int)($pComments ?? 0);

                if (!empty($p['created_time'])) {
                    $dateKey = \Carbon\Carbon::parse($p['created_time'])->format('Y-m-d');
                    if (!isset($postsByDate[$dateKey])) {
                        $postsByDate[$dateKey] = ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'posts' => 0];
                    }
                    $postsByDate[$dateKey]['likes']    += $pLikes;
                    $postsByDate[$dateKey]['comments'] += $pComments;
                    $postsByDate[$dateKey]['shares']   += $pShares;
                    $postsByDate[$dateKey]['views']    += (int)($pViews ?? 0);
                    $postsByDate[$dateKey]['posts']    += 1;
                }
            }

            // Views are only reported when Meta returns post_media_view or a video/reel views value.
            $allVideoViews = 0;

            \Illuminate\Support\Facades\Log::info("[FACEBOOK METRICS FINAL]", [
                'page_id'        => $pageId,
                'period_posts'   => $periodPostsCount,
                'total_views'    => $totalViews,
                'total_likes'    => $totalLikes,
                'total_comments' => $totalComments,
                'total_shares'   => $totalShares,
            ]);

            return [
                'success'                    => true,
                'posts_count'                => $periodPostsCount,
                'views'                      => $hasViews ? $totalViews : null,
                'lifetime_views'             => null,
                'likes'                      => $totalLikes,
                'comments'                   => $totalComments,
                'shares'                     => $totalShares,
                'views_supported'            => $hasViews,
                'likes_supported'            => true,
                'comments_supported'         => true,
                'shares_supported'           => true,
                'likes_permission_needed'    => null,
                'comments_permission_needed' => null,
                'views_permission_needed'    => null,
                'posts_by_date'              => $postsByDate,
            ];
        });
    }

    /**
     * Resolve the Canonical Facebook Page ID from post permalinks.
     * This canonical ID (e.g. 617888917988795) is required by Meta Graph API v23.0
     * to authorize post-level reactions querying with a Page Access Token.
     */
    public function resolveCanonicalPageId(string $pageId, array $postsData = [], ?string $accessToken = null): string
    {
        $cacheKey = "fb_canonical_page_id_{$pageId}";
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached) {
            return (string)$cached;
        }

        foreach ($postsData as $p) {
            if (!empty($p['permalink_url']) && preg_match('#facebook\.com/(\d+)/posts/#', $p['permalink_url'], $m)) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, (string)$m[1], 86400 * 7);
                return (string)$m[1];
            }
        }

        if ($accessToken) {
            try {
                $res = $this->client()->timeout(5)->get("{$this->baseUrl}/{$this->apiVersion}/{$pageId}/published_posts", [
                    'fields'       => 'id,permalink_url',
                    'limit'        => 5,
                    'access_token' => $accessToken,
                ]);
                foreach ($res->json('data') ?? [] as $p) {
                    if (!empty($p['permalink_url']) && preg_match('#facebook\.com/(\d+)/posts/#', $p['permalink_url'], $m)) {
                        \Illuminate\Support\Facades\Cache::put($cacheKey, (string)$m[1], 86400 * 7);
                        return (string)$m[1];
                    }
                }
            } catch (\Throwable $e) {}
        }

        return $pageId;
    }

    /**
     * Current (freshly signed) picture URL of a published Page post, or null when Meta does not return one.
     * Read-only GET; used to restore thumbnails whose stored CDN link has expired.
     */
    public function fetchPostPicture(string $postId, string $accessToken): ?string
    {
        try {
            $res = $this->client()->timeout(15)->get("{$this->baseUrl}/{$this->apiVersion}/{$postId}", [
                'fields'       => 'full_picture',
                'access_token' => $accessToken,
            ]);
            $url = $res->successful() ? $res->json('full_picture') : null;

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[FACEBOOK POST PICTURE] {$postId}: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Safely persist Facebook post and media attachments to database with column constraint fallbacks.
     */
    public function persistFacebookPost(
        FacebookPage $fbPage,
        string $fbPostId,
        array $attributes,
        ?string $imageUrl = null,
        bool $isVideoOrReel = false
    ): ?FacebookPost {
        static $schemaRelaxed = false;
        if (!$schemaRelaxed) {
            $schemaRelaxed = true;
            try {
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE `facebook_posts` MODIFY `comments_count` INT UNSIGNED NULL DEFAULT NULL");
            } catch (\Throwable $e) {}
            try {
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE `facebook_posts` MODIFY `engagement_count` INT UNSIGNED NULL DEFAULT NULL");
            } catch (\Throwable $e) {}
            try {
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE `facebook_posts` MODIFY `views_count` BIGINT UNSIGNED NULL DEFAULT NULL");
            } catch (\Throwable $e) {}
        }

        $dbPost = null;
        try {
            $dbPost = FacebookPost::updateOrCreate(
                [
                    'workspace_id' => $fbPage->workspace_id,
                    'fb_post_id'   => $fbPostId,
                ],
                $attributes
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[FACEBOOK POST PERSIST WARNING] Initial updateOrCreate failed for {$fbPostId}: " . $e->getMessage());

            // Try running ALTER TABLE in case migration did not run on server
            try {
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE `facebook_posts` MODIFY `comments_count` INT UNSIGNED NULL DEFAULT NULL");
            } catch (\Throwable $ignored) {}

            // Retry with safe fallbacks (comments_count => 0, engagement_count => 0)
            try {
                $safeAttributes = $attributes;
                if (!array_key_exists('comments_count', $safeAttributes) || $safeAttributes['comments_count'] === null) {
                    $safeAttributes['comments_count'] = 0;
                }
                if (!array_key_exists('engagement_count', $safeAttributes) || $safeAttributes['engagement_count'] === null) {
                    $safeAttributes['engagement_count'] = 0;
                }
                $dbPost = FacebookPost::updateOrCreate(
                    [
                        'workspace_id' => $fbPage->workspace_id,
                        'fb_post_id'   => $fbPostId,
                    ],
                    $safeAttributes
                );
            } catch (\Throwable $retryEx) {
                \Illuminate\Support\Facades\Log::error("[FACEBOOK POST PERSIST ERROR] Retry updateOrCreate failed for {$fbPostId}: " . $retryEx->getMessage());
                return null;
            }
        }

        if ($dbPost && !empty($imageUrl)) {
            try {
                $previews = app(\App\Services\PostMediaPreviewService::class);
                $existing = \App\Models\FacebookPostMedia::where('facebook_post_id', $dbPost->id)->get();

                // Media uploaded through Marketing Command is the original: never replace it with Meta's CDN copy.
                $hasOriginalUpload = $existing->contains(fn ($m) => !empty($m->file_path) && !$previews->isMirrorPath($m->file_path));

                if (!$hasOriginalUpload) {
                    // Meta CDN links expire after a few days, so keep a local copy for the thumbnail (file_url keeps
                    // Meta's link for other consumers; file_path points at the copy).
                    // Downloads are capped per process so a first sync of many posts does not stall the request;
                    // later syncs (or `php artisan posts:repair-media`) copy the rest.
                    static $mirrorBudget = 25;
                    $mirror = $previews->existingMirror((int) $fbPage->workspace_id, $fbPostId);
                    if ($mirror === null && $mirrorBudget > 0) {
                        $mirrorBudget--;
                        $mirror = $previews->mirrorRemoteImage($imageUrl, (int) $fbPage->workspace_id, $fbPostId);
                    }

                    $values = [
                        'media_type' => $isVideoOrReel ? 'video' : 'image',
                        'file_url'   => $imageUrl,
                        'sort_order' => 0,
                    ];
                    // Without a local copy, fall back to Meta's fresh link (drops a stale copy path, never an upload).
                    $values['file_path'] = $mirror;
                    \App\Models\FacebookPostMedia::updateOrCreate(['facebook_post_id' => $dbPost->id], $values);
                }
                \Illuminate\Support\Facades\Cache::put("fb_post_pic_{$fbPostId}", $imageUrl, 86400);
            } catch (\Throwable $medEx) {
                \Illuminate\Support\Facades\Log::warning("[FACEBOOK POST MEDIA ERROR] Failed to save media for {$fbPostId}: " . $medEx->getMessage());
            }
        }

        return $dbPost;
    }

    /**
     * Fetch, parse, classify, and sync live Facebook Page posts directly from Meta Graph API v23.0.
     * Extracts full attachment images, permalinks, post types (Photo, Video, Reel, Carousel, Other),
     * and live metrics (Likes, Comments, Shares, Video Views).
     * Upserts into local facebook_posts and facebook_post_media tables.
     */
    public function syncFacebookPagePosts(
        FacebookPage $fbPage,
        ?string $startDate = null,
        ?string $endDate = null,
        bool $forceRefresh = false,
        int $limit = 100
    ): array {
        if (empty($fbPage->page_access_token) || $fbPage->token_status === 'disconnected') {
            return [];
        }

        $pageId = $fbPage->page_id;
        $token  = $fbPage->page_access_token;

        $since = null;
        $until = null;
        if (!empty($startDate) && !empty($endDate) && $startDate !== 'all' && $endDate !== 'all') {
            $since = \Carbon\Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay()->setTimezone('UTC')->timestamp;
            $until = \Carbon\Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay()->setTimezone('UTC')->timestamp;
        }

        $cacheLockKey = "fb_sync_posts_run_{$fbPage->id}_" . md5("{$startDate}_{$endDate}");
        if (!$forceRefresh && \Illuminate\Support\Facades\Cache::has($cacheLockKey)) {
            return \Illuminate\Support\Facades\Cache::get($cacheLockKey) ?: [];
        }

        \Illuminate\Support\Facades\Log::info("[FACEBOOK LIVE POSTS SYNC] Starting sync for page {$pageId}", [
            'startDate' => $startDate,
            'endDate'   => $endDate,
            'since'     => $since ? date('Y-m-d H:i:s', $since) : null,
            'until'     => $until ? date('Y-m-d H:i:s', $until) : null,
        ]);

        // 1. Fetch Reel IDs to differentiate Reels from regular landscape/standard Videos
        $reelIds = [];
        try {
            if ($forceRefresh) {
                \Illuminate\Support\Facades\Cache::forget("fb_page_reels_{$pageId}");
            }
            $reelIds = \Illuminate\Support\Facades\Cache::remember("fb_page_reels_{$pageId}", 600, function () use ($pageId, $token) {
                $res = $this->client()->get("{$this->baseUrl}/{$this->apiVersion}/{$pageId}/video_reels", [
                    'fields'       => 'id',
                    'limit'        => 100,
                    'access_token' => $token,
                ]);
                return collect($res->json('data') ?? [])->pluck('id')->map(fn($id) => (string)$id)->all();
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[FACEBOOK REELS ERROR] " . $e->getMessage());
        }

        // 2. Query /published_posts, /feed, and /posts from Meta Graph API to ensure photos, carousels, videos, reels, and timeline posts are all fetched
        $fields = 'id,message,created_time,shares,permalink_url,picture,full_picture,attachments{media_type,type,title,url,target,media,subattachments{media_type,type,url,target,media}}';
        $endpoints = [
            "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/published_posts",
            "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/feed",
            "{$this->baseUrl}/{$this->apiVersion}/{$pageId}/posts",
        ];

        $rawPostsMap = [];

        foreach ($endpoints as $ep) {
            $nextUrl = $ep;
            $currentParams = [
                'fields'       => $fields,
                'limit'        => min(100, $limit),
                'access_token' => $token,
            ];
            if ($since) $currentParams['since'] = $since;
            if ($until) $currentParams['until'] = $until;

            $pageIter = 0;
            // For historical ranges, walk up to 20 pages (up to 2000 posts) to capture the full range; default 3 pages for recent sync
            $maxPages = ($since && $until) ? 20 : 3;

            while ($nextUrl && $pageIter < $maxPages) {
                $pageIter++;
                try {
                    $response = $this->client()->timeout(20)->get($nextUrl, $currentParams ?: null);
                    if (!$response->successful()) {
                        \Illuminate\Support\Facades\Log::warning("[FACEBOOK POSTS SYNC] Graph API non-200: " . $response->status() . " for {$ep}");
                        break;
                    }
                    $json = $response->json();
                    $batch = $json['data'] ?? [];
                    if (empty($batch)) break;

                    foreach ($batch as $p) {
                        if (!empty($p['id'])) {
                            // Deduplicate across endpoints using short_id (e.g. 115864121526929_973082619136088 -> 973082619136088)
                            $shortId = last(explode('_', $p['id']));
                            $mapKey = !empty($shortId) ? $shortId : $p['id'];
                            if (!isset($rawPostsMap[$mapKey])) {
                                $rawPostsMap[$mapKey] = $p;
                            }
                        }
                    }

                    // Early exit if posts pre-date the 'since' boundary (Meta returns posts newest -> oldest)
                    if ($since) {
                        $lastItem = end($batch);
                        $lastTime = !empty($lastItem['created_time']) ? strtotime($lastItem['created_time']) : 0;
                        if ($lastTime > 0 && $lastTime < $since) {
                            break;
                        }
                    }

                    $nextUrl = $json['paging']['next'] ?? null;
                    $currentParams = [];
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("[FACEBOOK POSTS SYNC BATCH ERROR] " . $e->getMessage());
                    break;
                }
            }
        }

        $postsData = array_values($rawPostsMap);

        // Resolve Canonical Page ID from permalinks
        $canonicalPageId = $this->resolveCanonicalPageId($pageId, $postsData, $token);

        // 3. Parse attachments, classify types, collect targets and post endpoints for metrics
        $postParsed = [];
        $poolEndpoints = [];

        foreach ($postsData as $p) {
            $cTime = !empty($p['created_time']) ? strtotime($p['created_time']) : null;
            if ($since && $until) {
                if (!$cTime || $cTime < $since || $cTime > $until) {
                    continue;
                }
            }

            $permalink = $p['permalink_url'] ?? '';
            $atts = $p['attachments']['data'] ?? [];
            $primaryImage = $p['full_picture'] ?? $p['picture'] ?? null;
            $targets = [];
            $isVideo = false;
            $isMulti = false;
            $isReel = false;
            $videoId = null;

            if (str_contains($permalink, '/reel/')) {
                $isReel = true;
                if (preg_match('#/reel/(\d+)#', $permalink, $rm)) {
                    $videoId = (string)$rm[1];
                }
            }

            foreach ($atts as $att) {
                $type = $att['type'] ?? $att['media_type'] ?? '';
                $isVid = in_array($type, ['video', 'video_inline', 'video_direct_response']) || ($att['media_type'] ?? '') === 'video';
                if ($isVid) {
                    $isVideo = true;
                    if (!empty($att['target']['id'])) {
                        $videoId = (string)$att['target']['id'];
                        $targets[] = ['id' => (string)$att['target']['id'], 'is_video' => true];
                    }
                }
                if (!$primaryImage && !empty($att['media']['image']['src'])) {
                    $primaryImage = $att['media']['image']['src'];
                }
                if (!empty($att['subattachments']['data'])) {
                    $isMulti = true;
                    foreach ($att['subattachments']['data'] as $sub) {
                        if (!$primaryImage && !empty($sub['media']['image']['src'])) {
                            $primaryImage = $sub['media']['image']['src'];
                        }
                        if (!empty($sub['target']['id'])) {
                            $targets[] = ['id' => (string)$sub['target']['id'], 'is_video' => false];
                        }
                    }
                } elseif (!empty($att['target']['id']) && $type !== 'album' && !$isVid) {
                    $targets[] = ['id' => (string)$att['target']['id'], 'is_video' => false];
                }
            }

            if ($videoId && in_array($videoId, $reelIds, true)) {
                $isReel = true;
            }

            $postType = $isReel ? 'reel' : ($isVideo ? 'video' : ($isMulti ? 'multi_image' : (!empty($primaryImage) ? 'single_image' : 'other')));

            $shortId = last(explode('_', $p['id']));
            $canonicalPostId = "{$canonicalPageId}_{$shortId}";
            $fullPostId = $p['id'];

            // Register pool endpoints for this post
            $poolEndpoints["post_canon_{$canonicalPostId}"] = [
                'url'    => "{$this->baseUrl}/{$this->apiVersion}/{$canonicalPostId}",
                'params' => ['fields' => 'id,shares,reactions.summary(total_count)', 'access_token' => $token],
            ];
            if ($fullPostId !== $canonicalPostId) {
                $poolEndpoints["post_direct_{$fullPostId}"] = [
                    'url'    => "{$this->baseUrl}/{$this->apiVersion}/{$fullPostId}",
                    'params' => ['fields' => 'id,shares,reactions.summary(total_count)', 'access_token' => $token],
                ];
            }
            if ($videoId) {
                $poolEndpoints["vid_{$videoId}"] = [
                    'url'    => "{$this->baseUrl}/{$this->apiVersion}/{$videoId}",
                    'params' => ['fields' => 'id,views,likes.summary(true),comments.summary(true)', 'access_token' => $token],
                ];
            }
            // Include photo targets for mock test fallback
            foreach ($targets as $t) {
                if (!$t['is_video'] && !empty($t['id'])) {
                    $tId = $t['id'];
                    if (!isset($poolEndpoints["tgt_{$tId}"])) {
                        $poolEndpoints["tgt_{$tId}"] = [
                            'url'    => "{$this->baseUrl}/{$this->apiVersion}/{$tId}",
                            'params' => ['fields' => 'id,likes.summary(true),comments.summary(true)', 'access_token' => $token],
                        ];
                    }
                }
            }

            $postParsed[] = [
                'id'                => $p['id'],
                'short_id'          => $shortId,
                'canonical_post_id' => $canonicalPostId,
                'message'           => $p['message'] ?? '',
                'created_time'      => $cTime,
                'shares'            => (int)($p['shares']['count'] ?? 0),
                'permalink'         => $permalink,
                'image_url'         => $primaryImage,
                'post_type'         => $postType,
                'targets'           => $targets,
                'is_video'          => $isVideo,
                'is_reel'           => $isReel,
                'video_id'          => $videoId,
            ];
        }

        // 4. Concurrently query Meta Graph API for post reactions, video views, and target metrics
        $postReactions = [];
        $postShares = [];
        $videoMetrics = [];
        $photoTargetMetrics = [];

        if (!empty($poolEndpoints)) {
            try {
                $responses = \Illuminate\Support\Facades\Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($poolEndpoints) {
                    return collect($poolEndpoints)->map(function ($req, $key) use ($pool) {
                        return $pool->as($key)->withoutVerifying()->timeout(8)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                            ->get($req['url'], $req['params']);
                    })->all();
                });

                foreach ($poolEndpoints as $key => $req) {
                    $r = $responses[$key] ?? null;

                    if ($r instanceof \Throwable) {
                        \Illuminate\Support\Facades\Log::warning("[FACEBOOK POOL METRICS ERROR] Request failed for {$key}: " . $r->getMessage());
                        continue;
                    }

                    if (!$r instanceof \Illuminate\Http\Client\Response) {
                        continue;
                    }

                    if ($r->successful()) {
                        $d = $r->json();
                        if (str_starts_with($key, 'vid_')) {
                            $vId = substr($key, 4);
                            $videoMetrics[$vId] = [
                                'views'    => isset($d['views']) ? (int)$d['views'] : null,
                                'likes'    => isset($d['likes']['summary']['total_count']) ? (int)$d['likes']['summary']['total_count'] : null,
                                'comments' => isset($d['comments']['summary']['total_count']) ? (int)$d['comments']['summary']['total_count'] : null,
                            ];
                        } elseif (str_starts_with($key, 'tgt_')) {
                            $tId = substr($key, 4);
                            $photoTargetMetrics[$tId] = [
                                'likes'    => (int)($d['likes']['summary']['total_count'] ?? 0),
                                'comments' => (int)($d['comments']['summary']['total_count'] ?? 0),
                            ];
                        } elseif (str_starts_with($key, 'post_canon_')) {
                            $cId = substr($key, 11);
                            if (isset($d['reactions']['summary']['total_count'])) {
                                $postReactions[$cId] = (int)$d['reactions']['summary']['total_count'];
                            }
                            if (isset($d['shares']['count'])) {
                                $postShares[$cId] = (int)$d['shares']['count'];
                            }
                        } elseif (str_starts_with($key, 'post_direct_')) {
                            $fId = substr($key, 12);
                            if (isset($d['reactions']['summary']['total_count'])) {
                                $postReactions[$fId] = (int)$d['reactions']['summary']['total_count'];
                            }
                            if (isset($d['shares']['count'])) {
                                $postShares[$fId] = (int)$d['shares']['count'];
                            }
                        }
                    } else {
                        \Illuminate\Support\Facades\Log::warning("[FACEBOOK POOL METRICS ERROR] Non-200 for {$key}: " . $r->status());
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("[FACEBOOK POOL METRICS ERROR] " . $e->getMessage());
            }
        }

        $postMediaViews = $this->getPostMediaViews(
            array_column($postParsed, 'id'),
            $token,
            $startDate,
            $endDate,
            $forceRefresh
        );

        // 5. Persist to FacebookPost and FacebookPostMedia
        $syncedPostIds = [];
        foreach ($postParsed as $pp) {
            $cId = $pp['canonical_post_id'];
            $fullId = $pp['id'];
            $vId = $pp['video_id'];

            // 1. Reactions / Likes
            $pLikes = null;
            if (isset($postReactions[$cId])) {
                $pLikes = $postReactions[$cId];
            } elseif (isset($postReactions[$fullId])) {
                $pLikes = $postReactions[$fullId];
            } elseif ($vId && isset($videoMetrics[$vId]['likes'])) {
                $pLikes = $videoMetrics[$vId]['likes'];
            } elseif (!empty($pp['targets'])) {
                // Fallback for mocked unit tests
                $sumPhotoLikes = 0;
                $hasPhotoTarget = false;
                foreach ($pp['targets'] as $t) {
                    if (isset($photoTargetMetrics[$t['id']])) {
                        $sumPhotoLikes += $photoTargetMetrics[$t['id']]['likes'];
                        $hasPhotoTarget = true;
                    }
                }
                if ($hasPhotoTarget) {
                    $pLikes = $sumPhotoLikes;
                }
            }
            if ($pLikes === null) {
                $pLikes = 0;
            }

            // 2. Comments
            // On videos, comments are accessible via Page token without pages_read_user_content.
            // On general posts, reading comments requires pages_read_user_content (which is not granted).
            // Per requirement 7: if not available through permissions, return null (displays '—'). Do NOT fabricate 0.
            $pComments = null;
            if ($vId && isset($videoMetrics[$vId]['comments'])) {
                $pComments = $videoMetrics[$vId]['comments'];
            } elseif (!empty($pp['targets'])) {
                // Fallback for mock photo targets in unit tests
                $sumPhotoComments = 0;
                $hasPhotoComments = false;
                foreach ($pp['targets'] as $t) {
                    if (isset($photoTargetMetrics[$t['id']]['comments']) && $photoTargetMetrics[$t['id']]['comments'] > 0) {
                        $sumPhotoComments += $photoTargetMetrics[$t['id']]['comments'];
                        $hasPhotoComments = true;
                    }
                }
                if ($hasPhotoComments) {
                    $pComments = $sumPhotoComments;
                }
            }

            // 3. Views
            // Prefer Meta's Page Post Media View metric; keep video-object views as the existing video/reel fallback.
            $pViews = null;
            if (array_key_exists($fullId, $postMediaViews)) {
                $pViews = $postMediaViews[$fullId];
            } elseif ($vId && isset($videoMetrics[$vId]['views'])) {
                $pViews = $videoMetrics[$vId]['views'];
            }

            // 4. Shares
            $pShares = $postShares[$cId] ?? $postShares[$fullId] ?? $pp['shares'] ?? 0;

            $pubDate = $pp['created_time'] ? \Carbon\Carbon::createFromTimestamp($pp['created_time']) : now();
            $dbPost = $this->persistFacebookPost(
                $fbPage,
                $pp['id'],
                [
                    'facebook_page_id' => $fbPage->id,
                    'content'          => $pp['message'],
                    'post_type'        => $pp['post_type'],
                    'link_url'         => $pp['permalink'],
                    'status'           => 'published',
                    'published_at'     => $pubDate,
                    'created_at'       => $pubDate,
                    'views_count'      => $pViews,
                    'likes_count'      => $pLikes,
                    'comments_count'   => $pComments,
                    'shares_count'     => $pShares,
                    'reactions_count'  => $pLikes,
                    'engagement_count' => ($pLikes !== null || $pComments !== null || $pShares !== null)
                        ? ((int)($pLikes ?? 0) + (int)($pComments ?? 0) + (int)($pShares ?? 0))
                        : 0,
                    'last_synced_at'   => now(),
                ],
                $pp['image_url'],
                ($pp['is_video'] || $pp['is_reel'])
            );

            if ($pViews !== null) {
                \Illuminate\Support\Facades\Cache::put("fb_video_views_{$pp['id']}", $pViews, 86400);
            }

            if ($dbPost) {
                $syncedPostIds[] = $pp['id'];
            }
        }

        // Cache the sync result for 60 seconds
        \Illuminate\Support\Facades\Cache::put($cacheLockKey, $syncedPostIds, 60);

        return $syncedPostIds;
    }

    /**
     * Fetch Instagram Media List with real likes, comments, and media insights.
     */
    public function getInstagramMediaList(string $accessToken, $igAccountId = null, int $limit = 50, bool $forceRefresh = false, $startDate = null, $endDate = null, ?int $cacheTtl = null): array
    {
        if (is_numeric($igAccountId) && (int)$igAccountId < 10000) {
            $endDate = $startDate;
            $startDate = $forceRefresh;
            $forceRefresh = (bool)$limit;
            $limit = (int)$igAccountId;
            $igAccountId = null;
        }

        $igAccountId = $this->resolveInstagramAccountId($accessToken, $igAccountId);
        $this->lastInstagramMediaListError = null;

        // Date-only filters are calendar days in the reporting timezone (same as the Facebook dashboard),
        // not UTC days. A post published at 01:00 IST belongs to that IST day.
        $sTime = $this->reportingDayBound($startDate, false);
        $eTime = $this->reportingDayBound($endDate, true);

        $ttl = ($cacheTtl !== null && $cacheTtl > 0) ? $cacheTtl : 300;
        $startStr = $sTime ? $sTime->format('Ymd') : '';
        $endStr   = $eTime ? $eTime->format('Ymd') : '';
        $version  = self::instagramDashboardCacheVersion((string) $igAccountId);
        $cacheKey = "ig_media_list_" . md5($accessToken . ($igAccountId ?? '')) . "_{$limit}_{$startStr}_{$endStr}_v{$version}" . ($ttl < 300 ? "_{$ttl}" : "");
        if ($forceRefresh) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
        } else {
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->fetchInstagramMediaItems($accessToken, $igAccountId, $limit, $sTime, $eTime);

        // Only cache complete, successful fetches. A failed Meta call must not be cached as "no posts".
        if ($result['error'] === null) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, $result['items'], $ttl);
        } else {
            $this->lastInstagramMediaListError = $result['error'];
        }

        return $result['items'];
    }

    /** Error from the most recent getInstagramMediaList() call that could not fetch media from Meta (null = OK). */
    protected ?string $lastInstagramMediaListError = null;

    public function lastInstagramMediaListError(): ?string
    {
        return $this->lastInstagramMediaListError;
    }

    /** Calendar-day timezone used by the dashboards for date-range filters and day buckets. */
    public const REPORTING_TIMEZONE = 'Asia/Kolkata';

    /**
     * Start/end of a reporting-timezone calendar day. Accepts 'Y-m-d' strings or DateTime instances.
     */
    public function reportingDayBound($date, bool $endOfDay): ?\Carbon\Carbon
    {
        if ($date === null || $date === '' || $date === false || $date === 'all' || $date === 'null') {
            return null;
        }

        $day = $date instanceof \DateTimeInterface
            ? \Carbon\Carbon::instance($date)->setTimezone(self::REPORTING_TIMEZONE)
            : \Carbon\Carbon::parse((string) $date, self::REPORTING_TIMEZONE);

        return $endOfDay ? $day->endOfDay() : $day->startOfDay();
    }

    /**
     * Cache generation for an Instagram account's dashboard data. Bumped whenever the app publishes to that
     * account so every cached media list / stats / posts payload for it is bypassed immediately.
     */
    public static function instagramDashboardCacheVersion(string $igAccountId): int
    {
        return (int) \Illuminate\Support\Facades\Cache::get("ig_dash_ver_{$igAccountId}", 0);
    }

    /**
     * Invalidate cached Instagram dashboard data after a post is published to Instagram.
     * Bumps the cache generation of the account that was published to and of the workspace's Instagram integration.
     */
    public static function invalidateInstagramDashboardCache(int $workspaceId, ?string $publishedAccountId = null): void
    {
        $accountIds = \App\Models\Integration::where('workspace_id', $workspaceId)
            ->where('platform', 'instagram')
            ->pluck('account_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->push((string) $publishedAccountId)
            ->filter()
            ->unique();

        foreach ($accountIds as $accountId) {
            \Illuminate\Support\Facades\Cache::forever("ig_dash_ver_{$accountId}", self::instagramDashboardCacheVersion($accountId) + 1);
            \Illuminate\Support\Facades\Cache::forget("ig_profile_{$accountId}"); // media_count changed
        }
    }

    /**
     * Fetch media (newest first) for an Instagram account plus per-media insights.
     *
     * @return array{items: array, error: ?string}
     */
    protected function fetchInstagramMediaItems(string $accessToken, $igAccountId, int $limit, ?\Carbon\Carbon $sTime, ?\Carbon\Carbon $eTime): array
    {
            $fetchError = null;
            $isIgToken  = str_starts_with($accessToken, 'IGAA');
            $baseUrl    = $isIgToken ? 'https://graph.instagram.com/v23.0' : "{$this->baseUrl}/{$this->apiVersion}";
            $targetNode = !empty($igAccountId) ? $igAccountId : 'me';
            $url        = "{$baseUrl}/{$targetNode}/media";

            try {
                $rawItems = [];
                $nextUrl = $url;
                $currentParams = [
                    'fields'       => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count',
                    'limit'        => min(100, max(1, $limit)),
                    'access_token' => $accessToken,
                ];
                $pageIter = 0;
                $safetyLimit = 200;

                while ($nextUrl && count($rawItems) < $limit) {
                    if ($pageIter >= $safetyLimit) {
                        \Illuminate\Support\Facades\Log::warning("[Instagram Pagination] Safety ceiling reached", [
                            'account_id' => $targetNode,
                            'collected'  => count($rawItems),
                        ]);
                        break;
                    }

                    if ($pageIter > 0 && $pageIter % 5 === 0) {
                        usleep(500000);
                    }

                    $pageIter++;
                    $response = $this->client()->timeout(15)->get($nextUrl, $currentParams ?: null);

                    if (!$response->successful()) {
                        \Illuminate\Support\Facades\Log::warning("[Instagram API Error] getInstagramMediaList", [
                            'status' => $response->status(),
                            'error'  => $response->json('error') ?? $response->body(),
                        ]);
                        $fetchError = (string) ($response->json('error.message') ?? "Instagram media request failed with HTTP {$response->status()}");
                        break;
                    }

                    $batchItems = $response->json('data') ?? [];
                    if (empty($batchItems)) {
                        break;
                    }

                    $rawItems = array_merge($rawItems, $batchItems);

                    if ($sTime) {
                        $lastItem = end($batchItems);
                        $lastItemTime = !empty($lastItem['timestamp']) ? \Carbon\Carbon::parse($lastItem['timestamp']) : null;
                        if ($lastItemTime && $lastItemTime->isBefore($sTime)) {
                            break;
                        }
                    }

                    $nextUrl = $response->json('paging.next');
                    // Cursor URL already carries token/fields; an empty array (not null) would make Guzzle drop its
                    // query string, so page 2+ failed with "(#200) Provide valid app ID" and lists stopped at page 1.
                    $currentParams = [];
                }

                if (empty($rawItems)) {
                    return ['items' => [], 'error' => $fetchError];
                }

                // Filter by date range if provided
                $items = [];

                foreach ($rawItems as $rawItem) {
                    if ($sTime && $eTime) {
                        $itemTime = !empty($rawItem['timestamp']) ? \Carbon\Carbon::parse($rawItem['timestamp']) : null;
                        if ($itemTime && ($itemTime->isBefore($sTime) || $itemTime->isAfter($eTime))) {
                            continue;
                        }
                    }
                    $items[] = $rawItem;
                }

                if (empty($items)) {
                    return ['items' => [], 'error' => $fetchError];
                }

                // Concurrently fetch insights for all media items using Http::pool()
                // Metric MUST NOT include 'impressions' for image posts in v23.0 to prevent API errors.
                $poolResponses = \Illuminate\Support\Facades\Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($items, $baseUrl, $accessToken) {
                    return array_map(function ($item) use ($pool, $baseUrl, $accessToken) {
                        return $pool->as($item['id'])->withoutVerifying()->timeout(10)->get("{$baseUrl}/{$item['id']}/insights", [
                            'metric'       => 'shares,views,saved,reach,total_interactions',
                            'access_token' => $accessToken,
                        ]);
                    }, $items);
                });

                $mediaItems = [];
                $totalViews = 0;
                $totalShares = 0;

                foreach ($items as $item) {
                    $mId   = $item['id'];
                    $likes = array_key_exists('like_count', $item) ? (int)$item['like_count'] : null;
                    $comments = array_key_exists('comments_count', $item) ? (int)$item['comments_count'] : null;
                    $views = null;
                    $reach = null;
                    $shares = null;
                    $saved  = null;
                    $interactions = ((int)($likes ?? 0)) + ((int)($comments ?? 0));
                    $itemSharesSupported = true;
                    $itemViewsSupported = true;

                    $insightsRes = $poolResponses[$mId] ?? null;
                    if ($insightsRes && $insightsRes instanceof \Illuminate\Http\Client\Response && $insightsRes->successful()) {
                        foreach ($insightsRes->json('data') ?? [] as $metric) {
                            $n = $metric['name'] ?? '';
                            $v = (int)($metric['values'][0]['value'] ?? 0);
                            if ($n === 'reach') $reach = $v;
                            if ($n === 'shares' || str_contains($n, 'share')) {
                                $shares = max((int)($shares ?? 0), $v);
                            }
                            if ($n === 'views') $views = $v;
                            if ($n === 'saved') $saved = $v;
                            if ($n === 'total_interactions') $interactions = max($interactions, $v);
                        }
                    } else {
                        $itemSharesSupported = false;
                        $itemViewsSupported = false;
                        $errMessage = 'Unknown error';
                        $status = 'FAILED';
                        if ($insightsRes instanceof \Illuminate\Http\Client\Response) {
                            $errMessage = $insightsRes->json('error.message') ?? $insightsRes->body();
                            $status = $insightsRes->status();
                        } elseif ($insightsRes instanceof \Throwable) {
                            $errMessage = $insightsRes->getMessage();
                            $status = 'EXCEPTION/TIMEOUT';
                        }
                        \Illuminate\Support\Facades\Log::warning("[Instagram Media Insight Failed]", [
                            'media_id'   => $mId,
                            'media_type' => $item['media_type'] ?? 'IMAGE',
                            'status'     => $status,
                            'error'      => $errMessage,
                        ]);
                    }

                    // Strict API value for views (no metric substitution or engagement math)
                    $totalViews += (int)($views ?? 0);
                    $totalShares += (int)($shares ?? 0);

                    $mediaItems[] = [
                        'id'                 => $mId,
                        'caption'            => $item['caption'] ?? '',
                        'media_type'         => $item['media_type'] ?? 'IMAGE',
                        'media_product_type' => $item['media_product_type'] ?? null,
                        'media_url'          => $item['thumbnail_url'] ?? $item['media_url'] ?? '',
                        'thumbnail_url'      => $item['thumbnail_url'] ?? null,
                        'permalink'          => $item['permalink'] ?? '',
                        'timestamp'          => $item['timestamp'] ?? null,
                        'like_count'         => $likes,
                        'comments_count'     => $comments,
                        'shares_count'       => $shares,
                        'views_count'        => $views,
                        'saved_count'        => $saved,
                        'reach'              => $reach,
                        'total_interactions' => $interactions,
                        'shares_supported'   => $itemSharesSupported,
                        'views_supported'    => $itemViewsSupported,
                    ];
                }

                \Illuminate\Support\Facades\Log::info("[Instagram Media Metrics Calculated]", [
                    'items_count'  => count($mediaItems),
                    'total_views'  => $totalViews,
                    'total_shares' => $totalShares,
                ]);

                return ['items' => $mediaItems, 'error' => $fetchError];
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("[Instagram API Exception] getInstagramMediaList: " . $e->getMessage());
                return ['items' => [], 'error' => $e->getMessage()];
            }
    }

    /**
     * Fetch daily Instagram reach and interaction time-series trend over $days.
     */
    public function getInstagramInsightsTrend(string $accessToken, $igAccountId = null, int $days = 30, $startDate = null, $endDate = null): array
    {
        if (is_numeric($igAccountId) && (int)$igAccountId < 10000) {
            $endDate = $startDate;
            $startDate = $days;
            $days = (int)$igAccountId;
            $igAccountId = null;
        }

        $igAccountId = $this->resolveInstagramAccountId($accessToken, $igAccountId);

        if ($startDate && $endDate) {
            $start = \Carbon\Carbon::parse($startDate)->startOfDay();
            $end   = \Carbon\Carbon::parse($endDate)->endOfDay();
            $days  = max(1, (int)$start->diffInDays($end) + 1);
        } else {
            $end   = now()->endOfDay();
            $start = now()->subDays($days - 1)->startOfDay();
        }

        $since = $start->timestamp;
        $until = $end->timestamp;

        $isIgToken  = str_starts_with($accessToken, 'IGAA');
        $baseUrl    = $isIgToken ? 'https://graph.instagram.com/v23.0' : "{$this->baseUrl}/{$this->apiVersion}";
        $targetNode = !empty($igAccountId) ? $igAccountId : 'me';
        $url        = "{$baseUrl}/{$targetNode}/insights";

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

        for ($i = 0; $i < $days; $i++) {
            $currDate = (clone $start)->addDays($i);
            $dateStr = $currDate->format('Y-m-d');
            $label   = $currDate->format('M j');
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
        $fbPage = app(WorkspaceSocialAccounts::class)->facebook($workspaceId);
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
    public function getPagePhotosMetrics(string $pageId, string $accessToken, int $days = 30, $startDate = null, $endDate = null): array
    {
        $likes      = 0;
        $comments   = 0;
        $shares     = 0;
        $seenPhotos = [];
        $albumsProcessed = 0;
        $photosProcessed = 0;
        $errors     = [];
        $sTime = $startDate ? \Carbon\Carbon::parse($startDate)->startOfDay() : null;
        $eTime = $endDate ? \Carbon\Carbon::parse($endDate)->endOfDay() : null;

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
                                    if ($sTime && $eTime) {
                                        $createdTime = !empty($photo['created_time']) ? \Carbon\Carbon::parse($photo['created_time']) : null;
                                        if ($createdTime && ($createdTime->isBefore($sTime) || $createdTime->isAfter($eTime))) {
                                            continue;
                                        }
                                    }
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
