<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\FacebookPost;
use App\Models\FacebookPage;
use App\Models\Post;
use App\Models\Integration;
use App\Models\FacebookPostHistory;
use App\Services\FacebookGraphService;

class ProcessScheduledPosts extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'posts:process-scheduled';

    /**
     * The console command description.
     */
    protected $description = 'Find all due scheduled posts and publish them to Facebook, Instagram, and other platforms.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $nowUtc = Carbon::now('UTC');
        $nowIst = Carbon::now('Asia/Kolkata');

        Log::info('[SCHEDULER] ============================================================');
        Log::info('[SCHEDULER] Started', [
            'server_time' => date('Y-m-d H:i:s'),
            'utc_time'    => $nowUtc->toIso8601String(),
            'ist_time'    => $nowIst->toIso8601String(),
        ]);

        $this->info("[SCHEDULER] IST: {$nowIst->toIso8601String()}");
        $this->info("[SCHEDULER] UTC: {$nowUtc->toIso8601String()}");

        // ── 1. Find all due scheduled posts ─────────────────────────────────
        // scheduled_at is stored as UTC (converted from IST on save).
        // now() is UTC. So the comparison is always apples-to-apples.
        $duePosts = FacebookPost::where('status', 'scheduled')
            ->where('scheduled_at', '<=', $nowUtc)
            ->whereNull('published_at')
            ->orderBy('scheduled_at', 'asc')
            ->get();

        Log::info('[SCHEDULER] Checking scheduled posts', [
            'due_count' => $duePosts->count(),
            'query_time_utc' => $nowUtc->toIso8601String(),
        ]);

        $this->info("[SCHEDULER] Found {$duePosts->count()} due post(s).");

        if ($duePosts->isEmpty()) {
            Log::info('[SCHEDULER] No due posts. Exiting.');
            return Command::SUCCESS;
        }

        $graphService = new FacebookGraphService();

        foreach ($duePosts as $post) {
            $this->processPost($post, $graphService, $nowUtc, $nowIst);
        }

        Log::info('[SCHEDULER] Run complete.');
        $this->info('[SCHEDULER] Done.');
        return Command::SUCCESS;
    }

    /**
     * Process a single due post with atomic locking.
     */
    protected function processPost(FacebookPost $post, FacebookGraphService $graphService, Carbon $nowUtc, Carbon $nowIst): void
    {
        $postId       = $post->id;
        $scheduledUtc = $post->scheduled_at ? Carbon::parse($post->scheduled_at, 'UTC') : null;
        $scheduledIst = $scheduledUtc ? $scheduledUtc->copy()->setTimezone('Asia/Kolkata') : null;

        Log::info('[SCHEDULER] Processing post', [
            'post_id'       => $postId,
            'scheduled_utc' => $scheduledUtc?->toIso8601String(),
            'scheduled_ist' => $scheduledIst?->toIso8601String(),
            'current_utc'   => $nowUtc->toIso8601String(),
            'current_ist'   => $nowIst->toIso8601String(),
            'content_preview' => mb_substr($post->content ?? '', 0, 80),
            'workspace_id'  => $post->workspace_id,
            'facebook_page_id' => $post->facebook_page_id,
        ]);

        $this->info("[SCHEDULER] Post ID={$postId} | Scheduled (IST): {$scheduledIst?->toIso8601String()}");

        // ── 2. Atomic lock: mark as 'processing' in a transaction ───────────
        // If two scheduler runs overlap, only one will succeed this update.
        $locked = DB::transaction(function () use ($postId) {
            $fresh = FacebookPost::where('id', $postId)
                ->where('status', 'scheduled')   // won't match if already processing/published
                ->lockForUpdate()
                ->first();

            if (!$fresh) {
                Log::warning('[SCHEDULER] Post already locked or published by another process', ['post_id' => $postId]);
                return false;
            }

            $fresh->status = 'processing';
            $fresh->save();
            return true;
        });

        if (!$locked) {
            $this->warn("[SCHEDULER] Post ID={$postId} skipped (already being processed).");
            return;
        }

        // Reload with fresh data
        $post->refresh();

        // ── 3. Determine platforms from linked Post record ───────────────────
        $legacyPost = Post::where('workspace_id', $post->workspace_id)
            ->where('content', $post->content)
            ->where('status', 'scheduled')
            ->latest()
            ->first();

        $platforms = $legacyPost?->platform_list ?? [];
        if (empty($platforms)) {
            // Default: if a FB page is linked, publish to Facebook
            $platforms = $post->facebook_page_id ? ['Facebook'] : [];
        }

        $requiresFacebook  = in_array('Facebook', $platforms);
        $requiresInstagram = in_array('Instagram', $platforms);

        Log::info('[SCHEDULER] Platforms for post', [
            'post_id'   => $postId,
            'platforms' => $platforms,
        ]);

        $publishedSummary = [];
        $errors           = [];

        $mediaItems = \App\Models\FacebookPostMedia::where('facebook_post_id', $postId)->orderBy('sort_order', 'asc')->get();

        Log::info('[SCHEDULER] Post details', [
            'post_id'     => $postId,
            'post_type'   => $post->post_type,
            'media_count' => $mediaItems->count(),
            'has_content' => !empty(trim($post->content ?? '')),
        ]);

        // ── 4. Facebook Publishing ───────────────────────────────────────────
        if ($requiresFacebook) {
            $fbPage = null;
            if ($post->facebook_page_id) {
                $fbPage = FacebookPage::find($post->facebook_page_id);
            }
            if (!$fbPage) {
                $fbPage = FacebookPage::where('workspace_id', $post->workspace_id)->first() ?? FacebookPage::latest()->first();
            }

            if (!$fbPage || empty($fbPage->page_access_token)) {
                $errors[] = 'Facebook: No connected Page or access token found for workspace ' . $post->workspace_id;
                Log::error('[FACEBOOK PUBLISH FAILED] No page token', [
                    'post_id'      => $postId,
                    'workspace_id' => $post->workspace_id,
                ]);
            } else {
                Log::info('[FACEBOOK PUBLISH] Starting publish request', [
                    'post_id'      => $postId,
                    'page_id'      => $fbPage->page_id,
                    'page_name'    => $fbPage->page_name,
                    'post_type'    => $post->post_type,
                    'media_count'  => $mediaItems->count(),
                    'token_status' => $fbPage->token_status ?? 'valid',
                ]);

                try {
                    $fbResult = null;
                    if ($post->post_type === 'single_image' || ($mediaItems->count() === 1 && $mediaItems->first()->media_type === 'image')) {
                        $media = $mediaItems->first();
                        $filePath = $this->resolveLocalFilePath($media?->file_path) ?? ($media?->file_url ?? null);
                        if (!$filePath) {
                            throw new \Exception("Cannot publish single-photo post: Image file is missing from server storage.");
                        }
                        $fbResult = $graphService->publishSinglePhoto($fbPage->page_id, $fbPage->page_access_token, $post->content ?? '', $filePath);
                    } elseif ($post->post_type === 'multi_image' || ($mediaItems->count() > 1 && $mediaItems->first()->media_type === 'image')) {
                        $filePaths = $mediaItems->map(fn($m) => $this->resolveLocalFilePath($m->file_path) ?? $m->file_url)->filter()->values()->toArray();
                        if (empty($filePaths)) {
                            throw new \Exception("Cannot publish multi-photo post: Image files are missing from server storage.");
                        }
                        $fbResult = $graphService->publishMultiplePhotos($fbPage->page_id, $fbPage->page_access_token, $post->content ?? '', $filePaths);
                    } elseif ($post->post_type === 'video' || ($mediaItems->count() >= 1 && $mediaItems->first()->media_type === 'video')) {
                        $media = $mediaItems->first();
                        $filePath = $this->resolveLocalFilePath($media?->file_path) ?? ($media?->file_url ?? null);
                        if (!$filePath) {
                            throw new \Exception("Cannot publish video post: Video file is missing from server storage.");
                        }
                        $fbResult = $graphService->publishVideo($fbPage->page_id, $fbPage->page_access_token, $post->content ?? '', $filePath);
                    } else {
                        if (empty(trim($post->content ?? ''))) {
                            throw new \Exception("Cannot publish empty post to Facebook: Message caption and media attachments are both empty.");
                        }
                        $fbResult = $graphService->publishTextPost(
                            $fbPage->page_id,
                            $fbPage->page_access_token,
                            $post->content,
                            $post->link_url ?? null
                        );
                    }

                    $fbPostId = $fbResult['post_id'] ?? $fbResult['id'] ?? null;
                    $post->fb_post_id = $fbPostId;

                    Log::info('[FACEBOOK PUBLISH] Success', [
                        'post_id'    => $postId,
                        'page_id'    => $fbPage->page_id,
                        'fb_post_id' => $fbPostId,
                        'response'   => $fbResult,
                    ]);

                    $this->info("[FACEBOOK PUBLISH] OK. Meta Post ID: {$fbPostId}");
                    $publishedSummary[] = "Facebook Page ({$fbPage->page_name})";

                } catch (\Exception $e) {
                    $errors[] = 'Facebook: ' . $e->getMessage();
                    Log::error('[FACEBOOK PUBLISH FAILED]', [
                        'post_id'      => $postId,
                        'page_id'      => $fbPage->page_id,
                        'workspace_id' => $post->workspace_id,
                        'error'        => $e->getMessage(),
                    ]);
                    $this->error("============================================================");
                    $this->error("Post ID: {$postId}");
                    $this->error("Facebook Page ID: {$fbPage->page_id}");
                    $this->error("Scheduled Time: " . ($scheduledIst ? $scheduledIst->toIso8601String() : 'N/A'));
                    $this->error("Publishing Time: " . $nowIst->toIso8601String());
                    $this->error("API Endpoint: https://graph.facebook.com/v23.0/{$fbPage->page_id}/" . ($post->post_type === 'video' ? 'videos' : ($post->post_type === 'single_image' ? 'photos' : 'feed')));
                    $this->error("Error: {$e->getMessage()}");
                    $this->error("============================================================");
                }
            }
        }

        // ── 5. Instagram Publishing ──────────────────────────────────────────
        if ($requiresInstagram) {
            $igInteg = Integration::where('workspace_id', $post->workspace_id)
                ->where('platform', 'instagram')
                ->where('is_connected', true)
                ->latest()
                ->first();

            $fbPageForIg = FacebookPage::where('workspace_id', $post->workspace_id)->first() ?? FacebookPage::latest()->first();
            $igAccountId = $igInteg ? $igInteg->account_id : ($fbPageForIg ? $fbPageForIg->page_id : null);
            $primaryToken = $igInteg ? $igInteg->refresh_token : ($fbPageForIg ? $fbPageForIg->page_access_token : null);

            if (!$igAccountId || !$primaryToken) {
                $errors[] = 'Instagram: No connected Instagram account found for workspace ' . $post->workspace_id;
                Log::warning('[INSTAGRAM PUBLISH FAILED] No integration or token', ['post_id' => $postId]);
            } elseif ($mediaItems->isEmpty()) {
                $errors[] = 'Instagram: Graph API requires an image or video to create a post.';
                Log::warning('[INSTAGRAM PUBLISH FAILED] Text-only post not supported by Instagram Graph API', ['post_id' => $postId]);
            } else {
                try {
                    $firstMedia = $mediaItems->first();
                    $mediaPath = $this->resolveLocalFilePath($firstMedia->file_path) ?? $firstMedia->file_url;
                    
                    if ($firstMedia->media_type === 'video') {
                        $igResult = $graphService->publishInstagramVideo($igAccountId, $primaryToken, $post->content ?? '', $mediaPath, $post->workspace_id);
                    } else {
                        try {
                            $igResult = $graphService->publishInstagramSinglePhoto($igAccountId, $primaryToken, $post->content ?? '', $mediaPath, $post->workspace_id);
                        } catch (\Exception $igErr) {
                            if ($fbPageForIg && !empty($fbPageForIg->page_access_token) && $primaryToken !== $fbPageForIg->page_access_token) {
                                $igResult = $graphService->publishInstagramSinglePhoto($igAccountId, $fbPageForIg->page_access_token, $post->content ?? '', $mediaPath, $post->workspace_id);
                            } else {
                                throw $igErr;
                            }
                        }
                    }
                    $publishedSummary[] = 'Instagram (@' . ($igInteg ? $igInteg->account_name : 'Account') . ')';
                } catch (\Exception $e) {
                    $errors[] = 'Instagram: ' . $e->getMessage();
                    Log::error('[INSTAGRAM PUBLISH FAILED]', [
                        'post_id' => $postId,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        // ── 6. Update post status ────────────────────────────────────────────
        if (!empty($publishedSummary)) {
            // At least one platform published successfully
            $post->status       = 'published';
            $post->published_at = now();
            $post->save();

            // Also update the generic Post record
            if ($legacyPost) {
                $legacyPost->status       = 'published';
                $legacyPost->published_at = now();
                $legacyPost->save();
            }

            // Log publish history
            FacebookPostHistory::create([
                'facebook_post_id' => $postId,
                'action'           => 'published',
                'attempt_number'   => max(1, ($post->retry_count ?? 0) + 1),
                'status_code'      => 200,
                'response_payload' => [
                    'summary'    => $publishedSummary,
                    'errors'     => $errors,
                    'source'     => 'scheduler',
                    'utc_time'   => $nowUtc->toIso8601String(),
                    'ist_time'   => $nowIst->toIso8601String(),
                ],
            ]);

            Log::info('[SCHEDULER] Post published successfully', [
                'post_id'       => $postId,
                'platforms'     => $publishedSummary,
                'fb_post_id'    => $post->fb_post_id,
                'published_at'  => $post->published_at,
            ]);

            $this->info("[SCHEDULER] Post ID={$postId} ✅ Published to: " . implode(', ', $publishedSummary));

            // Sync metrics in background (best-effort, don't fail on error)
            if (!empty($post->fb_post_id) && $requiresFacebook) {
                try {
                    $fbPage = FacebookPage::find($post->facebook_page_id)
                           ?? FacebookPage::where('workspace_id', $post->workspace_id)->first();
                    if ($fbPage) {
                        $graphService->syncPost($post, $fbPage->page_access_token);
                    }
                } catch (\Exception $syncErr) {
                    Log::warning('[SCHEDULER] Post metrics sync failed (non-critical): ' . $syncErr->getMessage());
                }
            }

        } else {
            // All platforms failed — mark as failed
            $errorMessage = implode(' | ', $errors);
            $post->status        = 'failed';
            $post->error_message = $errorMessage;
            $post->retry_count   = ($post->retry_count ?? 0) + 1;
            $post->save();

            // Also update the generic Post record
            if ($legacyPost) {
                $legacyPost->status = 'failed';
                $legacyPost->save();
            }

            FacebookPostHistory::create([
                'facebook_post_id' => $postId,
                'action'           => 'failed',
                'attempt_number'   => $post->retry_count,
                'status_code'      => 500,
                'response_payload' => [
                    'errors'   => $errors,
                    'source'   => 'scheduler',
                    'utc_time' => $nowUtc->toIso8601String(),
                    'ist_time' => $nowIst->toIso8601String(),
                ],
                'error_details' => $errorMessage,
            ]);

            Log::error('[SCHEDULER] Post FAILED', [
                'post_id'     => $postId,
                'errors'      => $errors,
                'retry_count' => $post->retry_count,
            ]);

            $this->error("[SCHEDULER] Post ID={$postId} ❌ FAILED: {$errorMessage}");
        }

        Log::info('[SCHEDULER] ────────────────────────────────────────────────────');
    }

    /**
     * Resolve absolute local file path from storage
     */
    protected function resolveLocalFilePath(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (file_exists($path)) {
            return $path;
        }

        $clean = ltrim(str_replace(['public/', 'storage/', 'public\\', 'storage\\'], '', $path), '/\\');
        
        $publicPath = storage_path('app/public/' . $clean);
        if (file_exists($publicPath)) {
            return $publicPath;
        }

        $privatePublic = storage_path('app/private/public/' . $clean);
        if (file_exists($privatePublic)) {
            return $privatePublic;
        }

        $privatePath = storage_path('app/private/' . $clean);
        if (file_exists($privatePath)) {
            return $privatePath;
        }

        return null;
    }
}
