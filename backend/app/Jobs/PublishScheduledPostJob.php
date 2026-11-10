<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\FacebookPost;
use App\Models\FacebookPage;
use App\Models\FacebookPostMedia;
use App\Models\FacebookPostHistory;
use App\Models\Post;
use App\Models\Integration;
use App\Models\YouTubeConnection;
use App\Services\FacebookGraphService;
use App\Services\YouTubeService;

class PublishScheduledPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted by queue workers.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $postId,
        // true = the user pressed Retry on a failed / partially published post. Such a post was not necessarily
        // scheduled, so it is never turned into a scheduled post and never auto-retried later.
        public bool $manualRetry = false
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $nowUtc = Carbon::now('UTC');
        $nowIst = Carbon::now('Asia/Kolkata');

        Log::info("[PublishScheduledPostJob] Initiating publish for post #{$this->postId}", [
            'post_id'     => $this->postId,
            'current_utc' => $nowUtc->toIso8601String(),
            'current_ist' => $nowIst->toIso8601String(),
        ]);

        // 1. Atomic Lock: Acquire and set status to 'processing' in a transaction.
        // Prevents duplicate publishing if two workers/scheduler runs collide.
        $post = null;
        $notDueYet = false;
        $locked = DB::transaction(function () use (&$post, &$notDueYet) {
            $query = FacebookPost::where('id', $this->postId);
            if ($this->manualRetry) {
                $query->whereIn('status', ['failed', 'published']);
            } else {
                // Only posts explicitly scheduled by the user (status "scheduled" with a scheduled_at) are eligible.
                $query->where('status', 'scheduled')->whereNull('published_at')->whereNotNull('scheduled_at');
            }
            $p = $query->lockForUpdate()->first();

            if (!$p) {
                return false;
            }

            // Re-read the CURRENT scheduled_at: never publish before it. A delayed queue job created for an
            // earlier time therefore cannot publish a post that was rescheduled to a later time.
            if (!$this->manualRetry && $p->scheduled_at->gt(Carbon::now())) {
                $notDueYet = true;
                return false;
            }

            $p->status = 'processing';
            $p->save();
            $post = $p;
            return true;
        });

        if ($notDueYet) {
            Log::info("[PublishScheduledPostJob] Post #{$this->postId} is scheduled for a later time (it was rescheduled). Skipping this run; the scheduler publishes it when it is due.");
            return;
        }
        if (!$locked || !$post) {
            Log::info("[PublishScheduledPostJob] Post #{$this->postId} is not in scheduled state or already processing/published. Exiting.");
            return;
        }

        try {
            $this->publishLockedPost($post, $nowUtc, $nowIst);
        } catch (\Throwable $e) {
            // Failed-job safety: an unexpected error must never leave the post stuck in "processing" (or let a
            // queue retry publish it again). Platform IDs already saved by the checkpoints are kept.
            Log::error("[PublishScheduledPostJob] Unexpected error while publishing post #{$this->postId}: " . $e->getMessage());
            try {
                // Plain column read/update (no relations): whatever broke must not break the recovery as well.
                $row = DB::table('facebook_posts')->where('id', $this->postId)->first(['status', 'fb_post_id', 'ig_media_id', 'yt_video_id', 'published_at']);
                if ($row && $row->status === 'processing') {
                    $alreadyPublished = !empty($row->fb_post_id) || !empty($row->ig_media_id) || !empty($row->yt_video_id);
                    DB::table('facebook_posts')->where('id', $this->postId)->where('status', 'processing')->update([
                        'status'        => $alreadyPublished ? 'published' : 'failed',
                        'published_at'  => $alreadyPublished ? ($row->published_at ?? now()) : $row->published_at,
                        'error_message' => 'Publishing stopped unexpectedly: ' . \Illuminate\Support\Str::limit($e->getMessage(), 300),
                        'updated_at'    => now(),
                    ]);
                }
            } catch (\Throwable $recoveryError) {
                // Last resort: the scheduler marks posts stuck in "processing" as failed after 60 minutes.
                Log::error("[PublishScheduledPostJob] Could not record the failure of post #{$this->postId}: " . $recoveryError->getMessage());
            }
        }
    }

    protected function publishLockedPost(FacebookPost $post, Carbon $nowUtc, Carbon $nowIst): void
    {
        $post->load(['media', 'facebookPage', 'legacyPost']);

        // 2. Resolve Target Platforms
        $platforms = $post->platforms;
        if (empty($platforms) && $post->legacyPost && !empty($post->legacyPost->platform_list)) {
            $platforms = $post->legacyPost->platform_list;
        }
        if (empty($platforms)) {
            $platforms = $post->facebook_page_id ? ['Facebook'] : ['Facebook'];
        }
        if (is_string($platforms)) {
            $platforms = json_decode($platforms, true) ?? [$platforms];
        }

        $requiresFacebook  = in_array('Facebook', $platforms, true);
        $requiresInstagram = in_array('Instagram', $platforms, true);
        $requiresYouTube   = in_array('YouTube', $platforms, true);

        $mediaItems = $post->media()->orderBy('sort_order', 'asc')->get();
        // Custom video thumbnail / Reel cover saved with the post (null when none or the file is gone)
        $thumbnailPath = $post->thumbnailLocalPath();
        if ($post->thumbnail_path && !$thumbnailPath) {
            Log::warning("[PublishScheduledPostJob] Saved thumbnail file is missing; publishing without a custom thumbnail", ['post_id' => $post->id]);
        }
        $publishedSummary = [];
        $errors = [];
        $platformStatuses = $post->platform_statuses ?? [];

        $graphService = app(FacebookGraphService::class);

        // 3. Facebook Publishing
        if ($requiresFacebook && empty($post->fb_post_id)) {
            $fbPage = null;
            if ($post->facebook_page_id) {
                $fbPage = FacebookPage::find($post->facebook_page_id);
            }
            if (!$fbPage) {
                $fbPage = FacebookPage::where('workspace_id', $post->workspace_id)->whereNotNull('page_access_token')->first();
            }

            if (!$fbPage || empty($fbPage->page_access_token)) {
                $err = "Facebook: No connected Page or access token found for workspace {$post->workspace_id}";
                $errors['facebook'] = $err;
                $platformStatuses['facebook'] = [
                    'platform' => 'Facebook',
                    'status'   => 'failed',
                    'error'    => $err,
                ];
                Log::error("[PublishScheduledPostJob] {$err}", ['post_id' => $post->id]);
            } else {
                try {
                    $fbResult = null;
                    if ($post->post_type === 'single_image' || ($mediaItems->count() === 1 && $mediaItems->first()->media_type === 'image')) {
                        $media = $mediaItems->first();
                        $filePath = self::resolveLocalFilePath($media?->file_path) ?? ($media?->file_url ?? null);
                        if (!$filePath) {
                            throw new \Exception("Cannot publish single-photo post: Image file is missing from server storage.");
                        }
                        $fbResult = $graphService->publishSinglePhoto($fbPage->page_id, $fbPage->page_access_token, $post->content ?? '', $filePath);
                    } elseif ($post->post_type === 'multi_image' || ($mediaItems->count() > 1 && $mediaItems->first()->media_type === 'image')) {
                        $filePaths = $mediaItems->map(fn($m) => self::resolveLocalFilePath($m->file_path) ?? $m->file_url)->filter()->values()->toArray();
                        if (empty($filePaths)) {
                            throw new \Exception("Cannot publish multi-photo post: Image files are missing from server storage.");
                        }
                        $fbResult = $graphService->publishMultiplePhotos($fbPage->page_id, $fbPage->page_access_token, $post->content ?? '', $filePaths);
                    } elseif ($post->post_type === 'video' || ($mediaItems->count() >= 1 && $mediaItems->first()->media_type === 'video')) {
                        $media = $mediaItems->first();
                        $filePath = self::resolveLocalFilePath($media?->file_path) ?? ($media?->file_url ?? null);
                        if (!$filePath) {
                            throw new \Exception("Cannot publish video post: Video file is missing from server storage.");
                        }
                        $fbResult = $graphService->publishVideo($fbPage->page_id, $fbPage->page_access_token, $post->content ?? '', $filePath, $thumbnailPath);
                    } else {
                        if (empty(trim($post->content ?? ''))) {
                            throw new \Exception("Cannot publish empty post to Facebook: Message caption and media attachments are both empty.");
                        }
                        $fbResult = $graphService->publishTextPost($fbPage->page_id, $fbPage->page_access_token, $post->content, $post->link_url ?? null);
                    }

                    $fbPostId = $fbResult['post_id'] ?? $fbResult['id'] ?? null;
                    $post->fb_post_id = $fbPostId;
                    $platformStatuses['facebook'] = [
                        'platform'  => 'Facebook',
                        'status'    => 'published',
                        'id'        => $fbPostId,
                        'timestamp' => now()->toIso8601String(),
                    ];
                    if ($thumbnailPath && isset($fbResult['thumbnail'])) {
                        $platformStatuses['facebook']['thumbnail'] = $fbResult['thumbnail'];
                    }
                    $publishedSummary[] = "Facebook Page ({$fbPage->page_name})";
                    Log::info("[PublishScheduledPostJob] Facebook published: {$fbPostId}", ['post_id' => $post->id]);
                    // Checkpoint: store this platform's ID immediately, so an interruption or a retry never
                    // publishes to a platform that already has the post.
                    $post->platform_statuses = $platformStatuses;
                    $post->save();
                } catch (\Throwable $e) {
                    $errors['facebook'] = 'Facebook: ' . $e->getMessage();
                    $platformStatuses['facebook'] = [
                        'platform' => 'Facebook',
                        'status'   => 'failed',
                        'error'    => $e->getMessage(),
                    ];
                    Log::error("[PublishScheduledPostJob] Facebook publish failed: " . $e->getMessage(), ['post_id' => $post->id]);
                }
            }
        } elseif ($requiresFacebook && !empty($post->fb_post_id)) {
            // Already published in earlier attempt
            $publishedSummary[] = "Facebook Page (Already Published: {$post->fb_post_id})";
        }

        // 4. Instagram Publishing
        if ($requiresInstagram && empty($post->ig_media_id)) {
            $igInteg = Integration::where('workspace_id', $post->workspace_id)
                ->where('platform', 'instagram')
                ->where('is_connected', true)
                ->latest()
                ->first();

            $fbPageForIg = FacebookPage::where('workspace_id', $post->workspace_id)->whereNotNull('page_access_token')->first();
            $igAccountId = $igInteg ? $igInteg->account_id : null;
            $primaryToken = $igInteg ? $igInteg->refresh_token : ($fbPageForIg ? $fbPageForIg->page_access_token : null);

            // Auto-resolve Instagram Business Account linked to Facebook Page
            if ($fbPageForIg && !empty($fbPageForIg->page_access_token)) {
                try {
                    $linkedIg = $graphService->getLinkedInstagramBusinessAccount($fbPageForIg->page_id, $fbPageForIg->page_access_token);
                    if ($linkedIg && !empty($linkedIg['id'])) {
                        $igAccountId = (string) $linkedIg['id'];
                        if (empty($primaryToken)) {
                            $primaryToken = $fbPageForIg->page_access_token;
                        }
                    }
                } catch (\Throwable $igLookupErr) {
                    Log::warning("[PublishScheduledPostJob] Linked IG lookup: " . $igLookupErr->getMessage());
                }
            }

            if (!$igAccountId || !$primaryToken) {
                $err = "Instagram: No connected Instagram account found for workspace {$post->workspace_id}";
                $errors['instagram'] = $err;
                $platformStatuses['instagram'] = [
                    'platform' => 'Instagram',
                    'status'   => 'failed',
                    'error'    => $err,
                ];
                Log::warning("[PublishScheduledPostJob] {$err}", ['post_id' => $post->id]);
            } elseif ($mediaItems->isEmpty()) {
                $err = "Instagram: Graph API requires an image or video to create a post.";
                $errors['instagram'] = $err;
                $platformStatuses['instagram'] = [
                    'platform' => 'Instagram',
                    'status'   => 'failed',
                    'error'    => $err,
                ];
                Log::warning("[PublishScheduledPostJob] {$err}", ['post_id' => $post->id]);
            } else {
                try {
                    $firstMedia = $mediaItems->first();
                    $mediaPath = self::resolveLocalFilePath($firstMedia->file_path) ?? $firstMedia->file_url;
                    $igResult = null;

                    if ($post->post_type === 'multi_image' && $mediaItems->count() > 1) {
                        $mediaPaths = $mediaItems->map(fn($m) => self::resolveLocalFilePath($m->file_path) ?? $m->file_url)->filter()->values()->toArray();
                        $igResult = $graphService->publishInstagramCarousel($igAccountId, $primaryToken, $post->content ?? '', $mediaPaths, $post->workspace_id);
                    } elseif ($firstMedia->media_type === 'video') {
                        $igResult = $thumbnailPath
                            ? $graphService->publishInstagramVideo($igAccountId, $primaryToken, $post->content ?? '', $mediaPath, $post->workspace_id, $thumbnailPath)
                            : $graphService->publishInstagramVideo($igAccountId, $primaryToken, $post->content ?? '', $mediaPath, $post->workspace_id);
                    } else {
                        try {
                            $igResult = $graphService->publishInstagramSinglePhoto($igAccountId, $primaryToken, $post->content ?? '', $mediaPath, $post->workspace_id);
                        } catch (\Throwable $igErr) {
                            if (!($igErr instanceof \App\Exceptions\InstagramMediaException) && $fbPageForIg && !empty($fbPageForIg->page_access_token) && $primaryToken !== $fbPageForIg->page_access_token) {
                                $igResult = $graphService->publishInstagramSinglePhoto($igAccountId, $fbPageForIg->page_access_token, $post->content ?? '', $mediaPath, $post->workspace_id);
                            } else {
                                throw $igErr;
                            }
                        }
                    }

                    $igMediaId = $igResult['id'] ?? null;
                    if (empty($igMediaId)) {
                        throw new \Exception("Instagram publish returned empty media ID.");
                    }

                    $post->ig_media_id = $igMediaId;
                    // Make the Overview dashboard show the new post immediately.
                    FacebookGraphService::invalidateInstagramDashboardCache((int) $post->workspace_id, (string) $igAccountId);
                    $platformStatuses['instagram'] = [
                        'platform'  => 'Instagram',
                        'status'    => 'published',
                        'id'        => $igMediaId,
                        'timestamp' => now()->toIso8601String(),
                    ];
                    if ($thumbnailPath && $firstMedia->media_type === 'video') {
                        $platformStatuses['instagram']['thumbnail'] = $igResult['thumbnail']
                            ?? ['requested' => true, 'applied' => false, 'message' => 'Instagram did not confirm the custom Reel cover.'];
                    }
                    $publishedSummary[] = 'Instagram (@' . ($igInteg ? $igInteg->account_name : 'Account') . ')';
                    Log::info("[PublishScheduledPostJob] Instagram published: {$igMediaId}", ['post_id' => $post->id]);
                    // Checkpoint: store this platform's ID immediately, so an interruption or a retry never
                    // publishes to a platform that already has the post.
                    $post->platform_statuses = $platformStatuses;
                    $post->save();
                } catch (\Throwable $e) {
                    $rawMsg = $e->getMessage();
                    $friendlyMsg = $rawMsg;
                    if (str_contains($rawMsg, 'Application does not have permission') || str_contains($rawMsg, 'code":10') || str_contains($rawMsg, 'code 10')) {
                        $friendlyMsg = 'Your connected Meta account lacks Instagram publishing permissions (Error #10). Please ensure your Instagram Professional account is linked to your Facebook Page in Meta Business Suite.';
                    }
                    $errors['instagram'] = 'Instagram: ' . $friendlyMsg;
                    $platformStatuses['instagram'] = [
                        'platform' => 'Instagram',
                        'status'   => 'failed',
                        'error'    => $friendlyMsg,
                    ];
                    Log::error("[PublishScheduledPostJob] Instagram publish failed: {$friendlyMsg}", ['post_id' => $post->id]);
                }
            }
        } elseif ($requiresInstagram && !empty($post->ig_media_id)) {
            $publishedSummary[] = "Instagram (Already Published: {$post->ig_media_id})";
        }

        // 5. YouTube Publishing
        if ($requiresYouTube && empty($post->yt_video_id)) {
            $ytConn = YouTubeConnection::where('workspace_id', $post->workspace_id)
                ->whereNotNull('access_token')
                ->orderByDesc('updated_at')
                ->first();

            $videoMedia = $mediaItems->firstWhere('media_type', 'video');
            if (!$ytConn) {
                $err = "YouTube: No connected YouTube Channel found for workspace {$post->workspace_id}";
                $errors['youtube'] = $err;
                $platformStatuses['youtube'] = [
                    'platform' => 'YouTube',
                    'status'   => 'failed',
                    'error'    => $err,
                ];
                Log::warning("[PublishScheduledPostJob] {$err}", ['post_id' => $post->id]);
            } elseif (!$videoMedia) {
                $err = "YouTube requires a video file to be uploaded.";
                $errors['youtube'] = $err;
                $platformStatuses['youtube'] = [
                    'platform' => 'YouTube',
                    'status'   => 'failed',
                    'error'    => $err,
                ];
                Log::warning("[PublishScheduledPostJob] {$err}", ['post_id' => $post->id]);
            } else {
                try {
                    $videoPath = self::resolveLocalFilePath($videoMedia->file_path) ?? $videoMedia->file_url;
                    $videoTitle = !empty($post->video_title)
                        ? $post->video_title
                        : (!empty($post->content) ? mb_substr($post->content, 0, 60) : 'Scheduled Video');
                    $privacy = in_array($post->youtube_privacy, ['public', 'unlisted', 'private'], true)
                        ? $post->youtube_privacy
                        : 'unlisted';

                    $ytService = app(YouTubeService::class);
                    $ytResult = $thumbnailPath
                        ? $ytService->uploadVideo($ytConn, $videoPath, $videoTitle, $post->content ?? '', $privacy, null, '22', $thumbnailPath)
                        : $ytService->uploadVideo($ytConn, $videoPath, $videoTitle, $post->content ?? '', $privacy);

                    $ytVideoId = $ytResult['id'] ?? null;
                    if (empty($ytVideoId)) {
                        throw new \Exception("YouTube upload completed without returning video ID.");
                    }

                    $post->yt_video_id = $ytVideoId;
                    $platformStatuses['youtube'] = [
                        'platform'       => 'YouTube',
                        'status'         => 'published',
                        'id'             => $ytVideoId,
                        'url'            => $ytResult['url'] ?? "https://www.youtube.com/watch?v={$ytVideoId}",
                        'privacy_status' => $privacy,
                        'timestamp'      => now()->toIso8601String(),
                    ];
                    if ($thumbnailPath) {
                        $platformStatuses['youtube']['thumbnail'] = \App\Support\PublishingRules::youtubeThumbnailStatus($ytResult);
                    }
                    $publishedSummary[] = "YouTube ({$ytConn->channel_name})";
                    Log::info("[PublishScheduledPostJob] YouTube published: {$ytVideoId}", ['post_id' => $post->id]);
                    // Checkpoint: store this platform's ID immediately, so an interruption or a retry never
                    // publishes to a platform that already has the post.
                    $post->platform_statuses = $platformStatuses;
                    $post->save();
                } catch (\Throwable $e) {
                    $errors['youtube'] = 'YouTube: ' . $e->getMessage();
                    $platformStatuses['youtube'] = [
                        'platform' => 'YouTube',
                        'status'   => 'failed',
                        'error'    => $e->getMessage(),
                    ];
                    Log::error("[PublishScheduledPostJob] YouTube publish failed: " . $e->getMessage(), ['post_id' => $post->id]);
                }
            }
        } elseif ($requiresYouTube && !empty($post->yt_video_id)) {
            $publishedSummary[] = "YouTube (Already Published: {$post->yt_video_id})";
        }

        $post->platform_statuses = $platformStatuses;

        // 6. Evaluate Result: Publish vs Retry vs Fail
        if (!empty($publishedSummary)) {
            // At least one platform succeeded
            $hasIssues = !empty($errors);
            $post->status       = 'published';
            $post->published_at = $post->published_at ?? now();
            $post->error_message = $hasIssues ? implode(' | ', array_values($errors)) : null;
            $post->save();

            // Update legacy generic Post
            $legacyPost = $post->legacyPost ?? Post::where('facebook_post_id', $post->id)->first();
            if ($legacyPost) {
                $legacyPost->status       = 'published';
                $legacyPost->published_at = now();
                $legacyPost->save();
            }

            // Record History
            FacebookPostHistory::create([
                'facebook_post_id' => $post->id,
                'action'           => 'published',
                'attempt_number'   => max(1, ($post->retry_count ?? 0) + 1),
                'status_code'      => $hasIssues ? 207 : 200,
                'response_payload' => [
                    'summary'           => $publishedSummary,
                    'errors'            => array_values($errors),
                    'platform_statuses' => $platformStatuses,
                    'utc_time'          => $nowUtc->toIso8601String(),
                    'ist_time'          => $nowIst->toIso8601String(),
                ],
                'error_details'    => $hasIssues ? implode(' | ', array_values($errors)) : null,
            ]);

            self::sendNotification(
                $post->workspace_id,
                $post->created_by_id,
                'post_published',
                $hasIssues ? 'Scheduled Post Published with Issues' : 'Scheduled Post Published',
                $hasIssues
                    ? 'Published to ' . implode(', ', $publishedSummary) . '; Issues on: ' . implode(', ', array_keys($errors))
                    : 'Scheduled post published successfully to ' . implode(', ', $publishedSummary) . '.'
            );

            Log::info("[PublishScheduledPostJob] Post #{$post->id} published successfully to: " . implode(', ', $publishedSummary));

            // Sync metrics best-effort
            if (!empty($post->fb_post_id) && $requiresFacebook && isset($fbPage)) {
                try {
                    $graphService->syncPost($post, $fbPage->page_access_token);
                } catch (\Throwable $syncErr) {
                    Log::warning("[PublishScheduledPostJob] Post sync notice: " . $syncErr->getMessage());
                }
            }

        } else {
            // All platforms failed
            $errorText = implode(' | ', array_values($errors));
            $isTemporary = self::isTemporaryFailure(array_values($errors));
            $currentRetries = (int) ($post->retry_count ?? 0);

            if ($isTemporary && $currentRetries < 3 && !$this->manualRetry) {
                // Retry eligible
                $nextRetry = $currentRetries + 1;
                $post->retry_count = $nextRetry;
                $post->status = 'scheduled';
                $retryDelayMinutes = 5 * $nextRetry;
                $post->scheduled_at = now()->addMinutes($retryDelayMinutes);
                $post->error_message = "Temporary failure: {$errorText} (Retrying in {$retryDelayMinutes} mins, attempt {$nextRetry}/3)";
                $post->save();

                FacebookPostHistory::create([
                    'facebook_post_id' => $post->id,
                    'action'           => 'retried',
                    'attempt_number'   => $nextRetry,
                    'status_code'      => 429,
                    'response_payload' => [
                        'errors'     => array_values($errors),
                        'retry_in'   => "{$retryDelayMinutes} minutes",
                        'attempt'    => $nextRetry,
                        'max_retry'  => 3,
                    ],
                    'error_details'    => $errorText,
                ]);

                // Dispatch delayed retry (only on async queue connections to avoid sync driver recursion)
                if (config('queue.default') !== 'sync') {
                    try {
                        self::dispatch($post->id)->delay(now()->addMinutes($retryDelayMinutes));
                    } catch (\Throwable $dispatchErr) {
                        Log::warning("[PublishScheduledPostJob] Could not dispatch retry job: " . $dispatchErr->getMessage());
                    }
                }

                Log::warning("[PublishScheduledPostJob] Post #{$post->id} temporary failure. Scheduled retry #{$nextRetry} in {$retryDelayMinutes} min. Error: {$errorText}");

            } else {
                // Fatal failure or exhausted retries
                $post->status = 'failed';
                $post->error_message = $errorText;
                $post->retry_count = $currentRetries + 1;
                $post->save();

                $legacyPost = $post->legacyPost ?? Post::where('facebook_post_id', $post->id)->first();
                if ($legacyPost) {
                    $legacyPost->status = 'failed';
                    $legacyPost->save();
                }

                FacebookPostHistory::create([
                    'facebook_post_id' => $post->id,
                    'action'           => 'failed',
                    'attempt_number'   => $post->retry_count,
                    'status_code'      => 500,
                    'response_payload' => [
                        'errors'            => array_values($errors),
                        'platform_statuses' => $platformStatuses,
                    ],
                    'error_details'    => $errorText,
                ]);

                self::sendNotification(
                    $post->workspace_id,
                    $post->created_by_id,
                    'publishing_failed',
                    'Scheduled Publishing Failed',
                    "Publishing for post #{$post->id} failed on all platforms: {$errorText}"
                );

                Log::error("[PublishScheduledPostJob] Post #{$post->id} permanently failed: {$errorText}");
            }
        }
    }

    /**
     * Determines whether an error set is likely a temporary/transient failure suitable for retry.
     */
    public static function isTemporaryFailure(array $errors): bool
    {
        if (empty($errors)) {
            return false;
        }

        $temporaryKeywords = [
            'timed out',
            'timeout',
            'curl error 28',
            'curl error 7',
            'curl error 6',
            'connection refused',
            'rate limit',
            'request limit',
            'temporarily unavailable',
            'try again later',
            'server error',
            'service unavailable',
            '500 internal',
            '502 bad gateway',
            '503 service',
            '504 gateway',
            'quotaexceeded',
            'ratelimitexceeded',
            'an unexpected error has occurred',
            'an unknown error',
            'still in_progress',
        ];

        // Meta error codes that are transient (1 unknown, 2 service, 4/17/32/613 rate limits, 341 temporary
        // limit), matched EXACTLY so that 100 (invalid parameter) or 190 (expired token) never count as transient.
        $transientCodes = '(?:1|2|4|17|32|341|613)';
        $transientPatterns = [
            '/"code"\s*:\s*' . $transientCodes . '(?!\d)/',
            '/meta error \[' . $transientCodes . ' \//',
        ];

        foreach ($errors as $error) {
            $lower = strtolower($error);
            // An expired/revoked token or a missing permission never fixes itself: do not retry.
            if (preg_match('/"code"\s*:\s*190(?!\d)|meta error \[190 \/|access token has expired|permission error/', $lower)) {
                continue;
            }
            foreach ($temporaryKeywords as $kw) {
                if (str_contains($lower, $kw)) {
                    return true;
                }
            }
            foreach ($transientPatterns as $pattern) {
                if (preg_match($pattern, $lower)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve absolute local file path from storage.
     */
    public static function resolveLocalFilePath(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (file_exists($path)) {
            return $path;
        }

        $clean = ltrim(str_replace(['public/', 'storage/', 'public\\', 'storage\\'], '', $path), '/\\');
        
        $candidates = [
            storage_path('app/public/' . $clean),
            storage_path('app/private/public/' . $clean),
            storage_path('app/private/' . $clean),
            public_path('storage/' . $clean),
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Insert a notification for the workspace user.
     */
    protected static function sendNotification(int $workspaceId, ?int $userId, string $type, string $title, string $message): void
    {
        try {
            $targetUserId = $userId ?: (DB::table('users')->value('id') ?? 1);
            DB::table('notifications')->insert([
                'user_id'      => $targetUserId,
                'workspace_id' => $workspaceId,
                'type'         => $type,
                'title'        => $title,
                'message'      => $message,
                'is_read'      => false,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[PublishScheduledPostJob notification notice] " . $e->getMessage());
        }
    }
}
