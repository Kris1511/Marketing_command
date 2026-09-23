<?php

namespace App\Services;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookPerformanceService
{
    protected string $baseUrl = 'https://graph.facebook.com';
    protected string $apiVersion = 'v23.0';

    protected function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'Accept' => 'application/json',
        ])->timeout(12);
    }

    /**
     * Get the 6 Facebook performance metrics for a workspace within a date range.
     *
     * @param int $workspaceId
     * @param string|null $startDateParam
     * @param string|null $endDateParam
     * @param int|null $daysParam
     * @param bool $forceRefresh
     * @return array
     */
    public function getPerformanceMetrics(
        int $workspaceId,
        ?string $startDateParam = null,
        ?string $endDateParam = null,
        ?int $daysParam = null,
        bool $forceRefresh = false
    ): array {
        // Keep this selection consistent with the Facebook connection flow. A
        // workspace can retain historical Page rows after a Page is changed, so
        // `first()` can otherwise read an unrelated, older Page.
        $fbPage = app(WorkspaceSocialAccounts::class)->facebook($workspaceId);

        if (!$fbPage || empty($fbPage->page_access_token)) {
            return [
                'success'   => true,
                'connected' => false,
                'message'   => 'No Facebook Page connected for this workspace.',
                'page_name' => null,
                'period'    => null,
                'metrics'   => null,
            ];
        }

        // Determine current date range
        if ($startDateParam && $endDateParam) {
            $startDate = Carbon::parse($startDateParam)->startOfDay();
            $endDate   = Carbon::parse($endDateParam)->endOfDay();
            $days      = max(1, (int)$startDate->diffInDays($endDate) + 1);
        } else {
            $days      = $daysParam ?: 28; // Default to 28 days as in Meta Business Suite
            $endDate   = now()->subDay()->endOfDay(); // Align with Meta Business Suite ending on yesterday
            $startDate = (clone $endDate)->subDays($days - 1)->startOfDay();
        }

        // Determine previous equivalent period (exact same number of days preceding current)
        $prevEndDate   = (clone $startDate)->subDay()->endOfDay();
        $prevStartDate = (clone $prevEndDate)->subDays($days - 1)->startOfDay();

        $cacheKey = "fb_perf_v3_{$workspaceId}_{$fbPage->page_id}_{$startDate->format('Ymd')}_{$endDate->format('Ymd')}";
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 300, function () use ($fbPage, $startDate, $endDate, $prevStartDate, $prevEndDate, $days) {
            return $this->computeMetrics($fbPage, $startDate, $endDate, $prevStartDate, $prevEndDate, $days);
        });
    }

    protected function computeMetrics(
        FacebookPage $fbPage,
        Carbon $startDate,
        Carbon $endDate,
        Carbon $prevStartDate,
        Carbon $prevEndDate,
        int $days
    ): array {
        $pageId = $fbPage->page_id;
        $token  = $fbPage->page_access_token;

        // 1. Fetch Page Details (total followers, fan count)
        $totalFollowers = $fbPage->followers_count ?? 0;
        try {
            $detailsRes = $this->client()->get("{$this->baseUrl}/{$this->apiVersion}/{$pageId}", [
                'fields'       => 'id,name,fan_count,followers_count',
                'access_token' => $token,
            ]);
            if ($detailsRes->successful()) {
                $d = $detailsRes->json();
                $totalFollowers = (int)($d['followers_count'] ?? $d['fan_count'] ?? $totalFollowers);
                $fbPage->update([
                    'followers_count' => $totalFollowers,
                    'fan_count'       => (int)($d['fan_count'] ?? $fbPage->fan_count),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("[FacebookPerformanceService] Page details error: " . $e->getMessage());
        }

        // 2. Fetch Insights for current and previous period
        $currentInsights = $this->fetchPageInsightsSeries($pageId, $token, $startDate, $endDate);
        $prevInsights    = $this->fetchPageInsightsSeries($pageId, $token, $prevStartDate, $prevEndDate);

        // Fetch unique-viewer totals (Viewers) independently from the daily
        // chart series. Unique viewers are not additive across days, so the
        // period total must come directly from Meta's aggregated period API
        // rather than by summing daily values.
        $currentMediaTotals = $this->fetchMediaViewTotals($pageId, $token, $startDate, $endDate, $days);
        $prevMediaTotals    = $this->fetchMediaViewTotals($pageId, $token, $prevStartDate, $prevEndDate, $days);

        // 4. Fetch Post interactions (likes, comments, shares day by day)
        $currentPostInteractions = $this->fetchPostInteractionsSeries($fbPage, $startDate, $endDate);
        $prevPostInteractions    = $this->fetchPostInteractionsSeries($fbPage, $prevStartDate, $prevEndDate);

        // 5. Build date maps for the current period
        $dateLabels = [];
        $viewsSeries = [];
        $viewersSeries = [];
        $interactionsSeries = [];
        $clicksSeries = [];
        $visitsSeries = [];
        $followsSeries = [];

        // Views (page_media_view) is an additive metric: the period total is the sum
        // of daily values fetched with period=day from Meta Graph API. When Meta returns
        // 0 events for the period, the metric is valid with total 0 (not null/unavailable).
        $totalViews     = !empty($currentInsights['page_media_view']) ? (int)array_sum($currentInsights['page_media_view']) : 0;
        $prevTotalViews = !empty($prevInsights['page_media_view']) ? (int)array_sum($prevInsights['page_media_view']) : 0;

        // Viewers (page_total_media_view_unique) is non-additive: unique accounts that viewed
        // content in the period. Sourced from Meta's aggregate period endpoint or max daily unique.
        $totalViewers     = $currentMediaTotals['page_total_media_view_unique'] ?? (!empty($currentInsights['page_total_media_view_unique']) ? (int)max($currentInsights['page_total_media_view_unique']) : 0);
        $prevTotalViewers = $prevMediaTotals['page_total_media_view_unique'] ?? (!empty($prevInsights['page_total_media_view_unique']) ? (int)max($prevInsights['page_total_media_view_unique']) : 0);
        $totalInteractions = 0;
        $totalClicks = 0;
        $totalVisits = 0;
        $totalFollows = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = (clone $startDate)->addDays($i);
            $dateKey = $date->format('Y-m-d');
            $dateLabel = $date->format('j M'); // e.g. "11 Aug"
            $dateLabels[] = $dateLabel;

            // 1. Views: Meta's page_media_view metric
            $dayViews = (int)($currentInsights['page_media_view'][$dateKey] ?? 0);
            $viewsSeries[] = $dayViews;

            // 2. Viewers: Meta's unique-media-viewer metric
            $dayViewers = (int)($currentInsights['page_total_media_view_unique'][$dateKey] ?? 0);
            $viewersSeries[] = $dayViewers;

            // 3. Content interactions: Official Meta metric page_post_engagements (fallback: post likes/comments/shares)
            if (!empty($currentInsights['page_post_engagements'][$dateKey])) {
                $dayInteractions = (int)$currentInsights['page_post_engagements'][$dateKey];
            } else {
                $engFromInsight = (int)($currentInsights['page_actions_post_reactions_total'][$dateKey] ?? 0);
                $engFromPosts   = (int)($currentPostInteractions['interactions_by_date'][$dateKey] ?? 0);
                $dayInteractions = max($engFromInsight, $engFromPosts);
            }
            $interactionsSeries[] = $dayInteractions;
            $totalInteractions += $dayInteractions;

            // 4. Link clicks: Official Meta metric page_total_actions (fallback: post CTA clicks)
            if (!empty($currentInsights['page_total_actions'][$dateKey])) {
                $dayClicks = (int)$currentInsights['page_total_actions'][$dateKey];
            } else {
                $dayClicks = (int)($currentPostInteractions['clicks_by_date'][$dateKey] ?? 0);
            }
            $clicksSeries[] = $dayClicks;
            $totalClicks += $dayClicks;

            // 5. Visits: Official Meta metric page_views_total (profile visits)
            if (!empty($currentInsights['page_views_total'][$dateKey])) {
                $dayVisits = (int)$currentInsights['page_views_total'][$dateKey];
            } else {
                $dayVisits = (int)($currentPostInteractions['visits_by_date'][$dateKey] ?? 0);
            }
            $visitsSeries[] = $dayVisits;
            $totalVisits += $dayVisits;

            // 6. Follows: Official Meta metric page_daily_follows_unique
            $dayFollows = (int)($currentInsights['page_daily_follows_unique'][$dateKey] ?? 0);
            $followsSeries[] = $dayFollows;
            $totalFollows += $dayFollows;
        }

        // 6. Compute previous period totals for percentage comparison
        $prevTotalInteractions = 0;
        $prevTotalClicks = 0;
        $prevTotalVisits = 0;
        $prevTotalFollows = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = (clone $prevStartDate)->addDays($i);
            $dateKey = $date->format('Y-m-d');

            if (!empty($prevInsights['page_post_engagements'][$dateKey])) {
                $prevTotalInteractions += (int)$prevInsights['page_post_engagements'][$dateKey];
            } else {
                $engFromInsight = (int)($prevInsights['page_actions_post_reactions_total'][$dateKey] ?? 0);
                $engFromPosts   = (int)($prevPostInteractions['interactions_by_date'][$dateKey] ?? 0);
                $prevTotalInteractions += max($engFromInsight, $engFromPosts);
            }

            if (!empty($prevInsights['page_total_actions'][$dateKey])) {
                $prevTotalClicks += (int)$prevInsights['page_total_actions'][$dateKey];
            } else {
                $prevTotalClicks += (int)($prevPostInteractions['clicks_by_date'][$dateKey] ?? 0);
            }

            if (!empty($prevInsights['page_views_total'][$dateKey])) {
                $prevTotalVisits += (int)$prevInsights['page_views_total'][$dateKey];
            } else {
                $prevTotalVisits += (int)($prevPostInteractions['visits_by_date'][$dateKey] ?? 0);
            }

            $prevTotalFollows += (int)($prevInsights['page_daily_follows_unique'][$dateKey] ?? 0);
        }

        // 7. Calculate percentage changes vs previous period
        $viewsChange       = $this->calcPercentageChange($totalViews, $prevTotalViews);
        $viewersChange     = $this->calcPercentageChange($totalViewers, $prevTotalViewers);
        $interactionChange = $this->calcPercentageChange($totalInteractions, $prevTotalInteractions);
        $clicksChange      = $this->calcPercentageChange($totalClicks, $prevTotalClicks);
        $visitsChange      = $this->calcPercentageChange($totalVisits, $prevTotalVisits);
        $followsChange     = $this->calcPercentageChange($totalFollows, $prevTotalFollows);

        return [
            'success'   => true,
            'connected' => true,
            'page_id'   => $pageId,
            'page_name' => $fbPage->page_name,
            'period'    => [
                'days'            => $days,
                'start_date'      => $startDate->format('Y-m-d'),
                'end_date'        => $endDate->format('Y-m-d'),
                'label'           => "{$startDate->format('j M Y')} – {$endDate->format('j M Y')}",
                'prev_start_date' => $prevStartDate->format('Y-m-d'),
                'prev_end_date'   => $prevEndDate->format('Y-m-d'),
                'prev_label'      => "{$prevStartDate->format('j M Y')} – {$prevEndDate->format('j M Y')}",
            ],
            'metrics' => [
                'views' => [
                    'key'          => 'views',
                    'name'         => 'Views',
                    'legend'       => 'Views',
                    'info'         => 'The total number of times your Page content or videos were viewed or appeared on screen.',
                    'total'        => $totalViews,
                    'prev_total'   => $prevTotalViews,
                    'change'       => $viewsChange,
                    'is_available' => true,
                    'labels'       => $dateLabels,
                    'values'       => $viewsSeries,
                ],
                'viewers' => [
                    'key'          => 'viewers',
                    'name'         => 'Viewers',
                    'legend'       => 'Viewers',
                    'info'         => 'The estimated number of unique accounts that saw your content at least once.',
                    'total'        => $totalViewers,
                    'prev_total'   => $prevTotalViewers,
                    'change'       => $viewersChange,
                    'is_available' => true,
                    'labels'       => $dateLabels,
                    'values'       => $viewersSeries,
                ],
                'content_interactions' => [
                    'key'          => 'content_interactions',
                    'name'         => 'Content interactions',
                    'legend'       => 'Content interactions',
                    'info'         => 'The number of reactions, comments, shares, and other interactions on your Page content.',
                    'total'        => $totalInteractions,
                    'prev_total'   => $prevTotalInteractions,
                    'change'       => $interactionChange,
                    'is_available' => true,
                    'labels'       => $dateLabels,
                    'values'       => $interactionsSeries,
                ],
                'link_clicks' => [
                    'key'          => 'link_clicks',
                    'name'         => 'Link clicks',
                    'legend'       => 'Facebook link clicks',
                    'info'         => 'The number of clicks on links to select destinations, on or off Meta technologies, on your Page.',
                    'total'        => $totalClicks,
                    'prev_total'   => $prevTotalClicks,
                    'change'       => $clicksChange,
                    'is_available' => true,
                    'labels'       => $dateLabels,
                    'values'       => $clicksSeries,
                ],
                'visits' => [
                    'key'          => 'visits',
                    'name'         => 'Visits',
                    'legend'       => 'Facebook visits',
                    'info'         => 'The number of times your Facebook Page profile was visited.',
                    'total'        => $totalVisits,
                    'prev_total'   => $prevTotalVisits,
                    'change'       => $visitsChange,
                    'is_available' => true,
                    'labels'       => $dateLabels,
                    'values'       => $visitsSeries,
                ],
                'follows' => [
                    'key'             => 'follows',
                    'name'            => 'Follows',
                    'legend'          => 'Facebook follows',
                    'info'            => 'The number of new follows your Facebook Page received.',
                    'total'           => $totalFollows,
                    'prev_total'      => $prevTotalFollows,
                    'total_followers' => $totalFollowers,
                    'change'          => $followsChange,
                    'is_available'    => true,
                    'labels'          => $dateLabels,
                    'values'          => $followsSeries,
                ],
            ],
        ];
    }

    /**
     * Query Meta Graph API Insights day-by-day series for a given period.
     */
    protected function fetchPageInsightsSeries(string $pageId, string $accessToken, Carbon $start, Carbon $end): array
    {
        // Let Meta choose the Page's own daily bucket boundary. The API
        // returns each bucket's end_time; those timestamps are mapped back to
        // the selected calendar dates below. Adding an app-specific UTC offset
        // here can shift the result by a day for Pages in another timezone.
        $since = (clone $start)->startOfDay()->timestamp;
        $until = (clone $end)->endOfDay()->timestamp;

        // Meta enforces a strict max window of 89 days for insights
        if ($until - $since > 89 * 86400) {
            $since = $until - 89 * 86400;
        }

        $result = [
            'page_media_view'              => [],
            'page_total_media_view_unique' => [],
            'page_views_total'             => [],
            'page_post_engagements'        => [],
            'page_total_actions'           => [],
            'page_daily_follows_unique'    => [],
        ];

        Log::info('[FacebookPerformanceService] fetchPageInsightsSeries timestamps.', [
            'since_utc' => gmdate('Y-m-d H:i:s', $since),
            'until_utc' => gmdate('Y-m-d H:i:s', $until),
            'start'     => $start->toDateString(),
            'end'       => $end->toDateString(),
        ]);

        // Keep Views and Viewers as separate requests. They are distinct
        // Page Insights metrics and a retired/unsupported metric must not
        // suppress the other metric's response.
        foreach ([
            'page_media_view',
            'page_total_media_view_unique',
            'page_views_total,page_post_engagements,page_total_actions,page_daily_follows_unique',
        ] as $metrics) {
            try {
                $res = $this->client()->get("{$this->baseUrl}/{$this->apiVersion}/{$pageId}/insights", [
                    'metric'       => $metrics,
                    'period'       => 'day',
                    'since'        => $since,
                    'until'        => $until,
                    'access_token' => $accessToken,
                ]);

                if (!$res->successful()) {
                    Log::warning('[FacebookPerformanceService] Insights request failed.', [
                        'page_id' => $pageId,
                        'metrics' => $metrics,
                        'status'  => $res->status(),
                    ]);
                    continue;
                }

                $data = $res->json('data') ?? [];
                Log::info('[FacebookPerformanceService] fetchPageInsightsSeries response.', [
                    'metrics'     => $metrics,
                    'data_count'  => count($data),
                    'values_each' => array_map(fn($i) => [$i['name'] => count($i['values'] ?? [])], $data),
                ]);

                foreach ($data as $item) {
                    $mName = $item['name'] ?? '';
                    if (!array_key_exists($mName, $result)) {
                        continue;
                    }

                    foreach ($item['values'] ?? [] as $value) {
                        $endTime = $value['end_time'] ?? null;
                        if (!$endTime) {
                            continue;
                        }

                        // Meta's end_time identifies the end of the returned
                        // daily bucket. Mapping the bucket to the preceding
                        // calendar date keeps the user's selected dates stable
                        // without assuming the Page's timezone.
                        $dateStr = Carbon::parse($endTime)->subDay()->format('Y-m-d');
                        $result[$mName][$dateStr] = $this->normaliseInsightValue($value['value'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("[FacebookPerformanceService] fetchPageInsightsSeries error: " . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Get non-additive unique-viewer totals (Viewers) for an exact Meta-supported period.
     *
     * Only page_total_media_view_unique is fetched here because Views
     * (page_media_view) is additive and must be computed as a daily sum
     * from fetchPageInsightsSeries instead.
     *
     * Meta exposes page-level aggregate values for day, week, and 28-day
     * periods. Only those exact periods can correctly represent a unique
     * audience; daily unique-viewer values must never be summed.
     */
    protected function fetchMediaViewTotals(
        string $pageId,
        string $accessToken,
        Carbon $start,
        Carbon $end,
        int $days
    ): array {
        $period = match ($days) {
            1  => 'day',
            7  => 'week',
            28 => 'days_28',
            default => null,
        };

        if ($period === null) {
            return [];
        }

        $totals = [];
        try {
            // Only fetch the unique-viewer metric here. Views (page_media_view)
            // is intentionally excluded: its period aggregate from Meta is a
            // deduplicated count that is lower than the real total play count;
            // the correct Views total is always the sum of daily values.
            //
            // Use the selected calendar range. Meta returns the Page's own
            // aggregate bucket timestamps, which are matched to the selected
            // end date below.
            $since = (clone $start)->startOfDay()->timestamp;
            $until = (clone $end)->endOfDay()->timestamp;

            $res = $this->client()->get("{$this->baseUrl}/{$this->apiVersion}/{$pageId}/insights", [
                'metric'       => 'page_total_media_view_unique',
                'period'       => $period,
                'since'        => $since,
                'until'        => $until,
                'access_token' => $accessToken,
            ]);

            if (!$res->successful()) {
                Log::warning('[FacebookPerformanceService] Viewers totals request failed.', [
                    'page_id' => $pageId,
                    'period'  => $period,
                    'status'  => $res->status(),
                    'error'   => $res->json('error') ?? $res->body(),
                ]);
                return [];
            }

            $data = $res->json('data') ?? [];
            if (empty($data)) {
                Log::info('[FacebookPerformanceService] Viewers total response empty; metric unavailable for selected Page/range.', [
                    'page_id' => $pageId,
                    'period'  => $period,
                ]);
                return [];
            }

            // Meta returns sliding-window period entries. We need the entry
            // whose end_time matches the requested period end.
            // Meta's aggregate entry represents the selected period ending on
            // the requested end date. Match by calendar day rather than an
            // assumed Page timezone or hardcoded UTC hour.
            // Meta reports a period bucket with an end_time at the start of
            // the following calendar day, so the selected end date maps to
            // the next returned calendar date without assuming an hour or
            // timezone.
            $targetEndDate = (clone $end)->addDay()->toDateString();

            Log::info('[FacebookPerformanceService] fetchMediaViewTotals timestamps.', [
                'since_utc'      => gmdate('Y-m-d H:i:s', $since),
                'until_utc'      => gmdate('Y-m-d H:i:s', $until),
                'target_end'     => $targetEndDate,
                'period'         => $period,
            ]);

            foreach ($data as $item) {
                $metric = $item['name'] ?? '';
                if ($metric !== 'page_total_media_view_unique') {
                    continue;
                }
                $values = $item['values'] ?? [];
                if (empty($values)) {
                    continue;
                }

                // Find the value whose returned bucket contains the requested
                // end date. Do not substitute another period when Meta does
                // not return the requested bucket.
                $bestValue = null;
                $bestDiff  = PHP_INT_MAX;
                foreach ($values as $v) {
                    if (empty($v['end_time'])) {
                        continue;
                    }
                    $entryEnd = Carbon::parse($v['end_time']);
                    $diff = abs($entryEnd->toDateString() === $targetEndDate ? 0 : 1);
                    if ($diff < $bestDiff) {
                        $bestDiff  = $diff;
                        $bestValue = $v;
                    }
                }

                if ($bestValue !== null && $bestDiff === 0) {
                    $totals[$metric] = $this->normaliseInsightValue($bestValue['value'] ?? 0);
                } else {
                    Log::info('[FacebookPerformanceService] Viewers aggregate did not contain the selected end date.', [
                        'page_id'      => $pageId,
                        'target_end'   => $targetEndDate,
                        'returned_end' => $bestValue['end_time'] ?? null,
                    ]);
                    continue;
                }

                Log::info('[FacebookPerformanceService] Viewers total fetched.', [
                    'page_id'        => $pageId,
                    'period'         => $period,
                    'target_end'     => $targetEndDate,
                    'viewers_total'  => $totals[$metric] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("[FacebookPerformanceService] fetchMediaViewTotals error: " . $e->getMessage());
        }

        return $totals;
    }

    protected function normaliseInsightValue(mixed $value): int
    {
        return is_array($value) ? (int) array_sum($value) : (int) $value;
    }
    /**
     * Query post interactions (reactions, comments, shares, reach) day by day.
     */
    protected function fetchPostInteractionsSeries(FacebookPage $fbPage, Carbon $start, Carbon $end): array
    {
        $interactionsByDate = [];
        $reachByDate = [];
        $clicksByDate = [];
        $visitsByDate = [];
        $totalInteractions = 0;
        $totalReach = 0;
        $totalClicks = 0;
        $totalVisits = 0;

        // 1. Query uploaded photos from Meta Graph API for live reactions and comments
        try {
            $resP = $this->client()->get("{$this->baseUrl}/{$this->apiVersion}/{$fbPage->page_id}/photos", [
                'type'         => 'uploaded',
                'fields'       => 'id,created_time,likes.summary(true),comments.summary(true)',
                'limit'        => 50,
                'access_token' => $fbPage->page_access_token,
            ]);
            if ($resP->successful()) {
                foreach ($resP->json('data') ?? [] as $photo) {
                    $cTime = !empty($photo['created_time']) ? Carbon::parse($photo['created_time']) : null;
                    if ($cTime && $cTime->between($start, $end)) {
                        $dateKey = $cTime->format('Y-m-d');
                        $likes = (int)($photo['likes']['summary']['total_count'] ?? 0);
                        $comments = (int)($photo['comments']['summary']['total_count'] ?? 0);
                        $count = $likes + $comments;
                        if ($count > 0) {
                            $interactionsByDate[$dateKey] = ($interactionsByDate[$dateKey] ?? 0) + $count;
                            $totalInteractions += $count;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FacebookPerformanceService] photos error: " . $e->getMessage());
        }

        // 2. Query published_posts from Meta Graph API for live shares
        try {
            $postsRes = $this->client()->get("{$this->baseUrl}/{$this->apiVersion}/{$fbPage->page_id}/published_posts", [
                'fields'       => 'id,shares,created_time',
                'limit'        => 50,
                'access_token' => $fbPage->page_access_token,
            ]);

            if ($postsRes->successful()) {
                $pData = $postsRes->json('data') ?? [];
                foreach ($pData as $post) {
                    $cTime = !empty($post['created_time']) ? Carbon::parse($post['created_time']) : null;
                    if ($cTime && $cTime->between($start, $end)) {
                        $dateKey = $cTime->format('Y-m-d');
                        $shares = (int)($post['shares']['count'] ?? 0);
                        if ($shares > 0) {
                            $interactionsByDate[$dateKey] = ($interactionsByDate[$dateKey] ?? 0) + $shares;
                            $totalInteractions += $shares;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FacebookPerformanceService] published_posts error: " . $e->getMessage());
        }

        // 3. Query DB FacebookPost for any synced post metrics (reach, clicks, visits, likes, comments, shares)
        try {
            $pageIds = FacebookPage::where('workspace_id', $fbPage->workspace_id)
                ->where('page_id', $fbPage->page_id)
                ->pluck('id');
            $posts = FacebookPost::where('workspace_id', $fbPage->workspace_id)
                ->whereIn('facebook_page_id', $pageIds)
                ->where('status', 'published')
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('published_at', [$start, $end])
                      ->orWhere(function ($sub) use ($start, $end) {
                          $sub->whereNull('published_at')
                              ->whereBetween('created_at', [$start, $end]);
                      });
                })
                ->get();

            foreach ($posts as $p) {
                $pDate = $p->published_at ? Carbon::parse($p->published_at) : Carbon::parse($p->created_at);
                $dateKey = $pDate->format('Y-m-d');

                $inter = (int)($p->likes_count + $p->comments_count + $p->shares_count);
                $reach = (int)($p->reach_count ?? 0);

                if ($inter > 0 && empty($interactionsByDate[$dateKey])) {
                    $interactionsByDate[$dateKey] = $inter;
                    $totalInteractions += $inter;
                }
                if ($reach > 0) {
                    $reachByDate[$dateKey] = ($reachByDate[$dateKey] ?? 0) + $reach;
                    $totalReach += $reach;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FacebookPerformanceService] fetchPostInteractionsSeries DB error: " . $e->getMessage());
        }

        return [
            'interactions_by_date' => $interactionsByDate,
            'reach_by_date'        => $reachByDate,
            'clicks_by_date'       => $clicksByDate,
            'visits_by_date'       => $visitsByDate,
            'total_interactions'   => $totalInteractions,
            'total_reach'          => $totalReach,
            'total_clicks'         => $totalClicks,
            'total_visits'         => $totalVisits,
        ];
    }

    /**
     * Calculate percentage change and formatting.
     */
    protected function calcPercentageChange(int $current, int $prev): array
    {
        if ($prev > 0) {
            $diff = $current - $prev;
            $pct = round(($diff / $prev) * 100, 1);
            $direction = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'neutral');
            $prefix = $pct > 0 ? '↑ ' : ($pct < 0 ? '↓ ' : '');
            return [
                'value'     => abs($pct),
                'direction' => $direction,
                'formatted' => $prefix . $this->formatPctNumber(abs($pct)) . '%',
            ];
        } elseif ($current > 0) {
            return [
                'value'     => 100.0,
                'direction' => 'up',
                'formatted' => '↑ 100%',
            ];
        } else {
            return [
                'value'     => 0.0,
                'direction' => 'neutral',
                'formatted' => '0%',
            ];
        }
    }

    protected function formatPctNumber(float $val): string
    {
        if ($val >= 1000) {
            return round($val / 1000, 1) . 'K';
        }
        return (string)$val;
    }
}
