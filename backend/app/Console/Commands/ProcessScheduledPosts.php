<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\FacebookPost;
use App\Jobs\PublishScheduledPostJob;

class ProcessScheduledPosts extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'posts:process-scheduled
                            {--post-id= : Process a specific scheduled post ID immediately}
                            {--web-fallback : Triggered by a page load because the scheduler is not running (no heartbeat is recorded)}';

    /** Cache key holding the time of the last run started by the real scheduler (cron / Task Scheduler / schedule:work). */
    public const HEARTBEAT_KEY = 'scheduler:last_run_at';

    /** The scheduler is considered running when it has run within this many seconds. */
    public const HEARTBEAT_MAX_AGE = 180;

    /** A post still "processing" after this many minutes was interrupted (server stopped, crash, timeout). */
    public const STUCK_AFTER_MINUTES = 60;

    public static function schedulerIsRunning(): bool
    {
        $last = \Illuminate\Support\Facades\Cache::get(self::HEARTBEAT_KEY);

        return $last !== null && Carbon::parse($last)->gt(Carbon::now()->subSeconds(self::HEARTBEAT_MAX_AGE));
    }

    public static function lastSchedulerRun(): ?string
    {
        return \Illuminate\Support\Facades\Cache::get(self::HEARTBEAT_KEY);
    }

    /**
     * Posts left in "processing" by an interrupted run are marked failed (with what was already published), so they
     * become visible and can be retried. Platforms that already succeeded keep their IDs and are never re-published.
     */
    protected function recoverStuckPosts(): void
    {
        $stuck = FacebookPost::where('status', 'processing')
            ->where('updated_at', '<', Carbon::now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->get();

        foreach ($stuck as $post) {
            $done = collect($post->platform_statuses ?? [])
                ->filter(fn ($s) => ($s['status'] ?? null) === 'published')
                ->map(fn ($s, $key) => ($s['platform'] ?? ucfirst((string) $key)))
                ->values()
                ->all();

            $post->status = 'failed';
            $post->error_message = 'Publishing was interrupted before it finished (the server stopped or timed out).'
                . ($done ? ' Already published to: ' . implode(', ', $done) . '.' : '')
                . ' Use Retry to publish to the remaining platforms.';
            $post->save();

            Log::warning("[SCHEDULER] Post #{$post->id} was stuck in processing; marked failed", ['already_published' => $done]);
            $this->warn("[SCHEDULER] Post ID={$post->id} was stuck in processing and is now marked failed.");
        }
    }

    /**
     * The console command description.
     */
    protected $description = 'Find all due scheduled posts and publish them to Facebook, Instagram, and YouTube.';

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

        if (!$this->option('web-fallback')) {
            \Illuminate\Support\Facades\Cache::forever(self::HEARTBEAT_KEY, $nowUtc->toIso8601String());
        }
        $this->recoverStuckPosts();

        $specificPostId = $this->option('post-id');
        if (!empty($specificPostId)) {
            $post = FacebookPost::where('id', $specificPostId)
                ->where('status', 'scheduled')
                ->whereNull('published_at')
                ->first();

            if (!$post) {
                $this->warn("[SCHEDULER] Post #{$specificPostId} not found or not in scheduled state.");
                return Command::SUCCESS;
            }

            $this->info("[SCHEDULER] Processing single post #{$specificPostId}...");
            PublishScheduledPostJob::dispatchSync($post->id);
            $this->info("[SCHEDULER] Post #{$specificPostId} processed.");
            return Command::SUCCESS;
        }

        // 1. Find all due scheduled posts
        // scheduled_at is stored in UTC; nowUtc is UTC.
        $duePosts = FacebookPost::where('status', 'scheduled')
            ->where('scheduled_at', '<=', $nowUtc)
            ->whereNull('published_at')
            ->orderBy('scheduled_at', 'asc')
            ->get();

        Log::info('[SCHEDULER] Checking scheduled posts', [
            'due_count'      => $duePosts->count(),
            'query_time_utc' => $nowUtc->toIso8601String(),
        ]);

        $this->info("[SCHEDULER] Found {$duePosts->count()} due post(s).");

        if ($duePosts->isEmpty()) {
            Log::info('[SCHEDULER] No due posts. Exiting.');
            return Command::SUCCESS;
        }

        foreach ($duePosts as $post) {
            $this->info("[SCHEDULER] Dispatching post ID={$post->id}...");
            try {
                PublishScheduledPostJob::dispatchSync($post->id);
            } catch (\Throwable $e) {
                Log::error("[SCHEDULER] Failed to dispatch post #{$post->id}: {$e->getMessage()}");
                $this->error("[SCHEDULER] Post ID={$post->id} execution error: {$e->getMessage()}");
            }
        }

        Log::info('[SCHEDULER] Run complete.');
        $this->info('[SCHEDULER] Done.');
        return Command::SUCCESS;
    }
}
