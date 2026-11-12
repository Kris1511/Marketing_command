<?php

namespace App\Console\Commands;

use App\Models\FacebookPost;
use App\Services\YouTubeThumbnailPublisher;
use Illuminate\Console\Command;

/**
 * Finishes YouTube custom thumbnails left "pending" because YouTube was still processing the video when it was
 * published. Read-only check of the video, then thumbnails.set with the saved file; never re-uploads the video.
 */
class ApplyPendingYouTubeThumbnails extends Command
{
    protected $signature = 'posts:apply-youtube-thumbnails {--max-attempts=60 : Stop retrying a thumbnail after this many attempts}';

    protected $description = 'Apply custom thumbnails to published YouTube videos once YouTube has finished processing them';

    public function handle(YouTubeThumbnailPublisher $publisher): int
    {
        $max = (int) $this->option('max-attempts');
        $done = 0;

        FacebookPost::whereNotNull('yt_video_id')
            ->where('platform_statuses', 'like', '%pending%')
            ->where('updated_at', '>=', now()->subDays(7))
            ->orderBy('id')
            ->each(function (FacebookPost $post) use ($publisher, $max, &$done) {
                $thumb = $post->platform_statuses['youtube']['thumbnail'] ?? null;
                if (($thumb['status'] ?? null) !== 'pending' || (int) ($thumb['attempts'] ?? 0) >= $max) {
                    return;
                }
                $outcome = $publisher->applyToPost($post, 0);
                $status = $outcome['thumbnail']['status'] ?? 'skipped';
                $this->line("Post {$post->id} (YouTube {$post->yt_video_id}): {$status}" . (isset($outcome['error']) ? " - {$outcome['error']}" : ''));
                $done++;
            });

        $this->info("Checked {$done} pending YouTube thumbnail(s).");

        return self::SUCCESS;
    }
}
