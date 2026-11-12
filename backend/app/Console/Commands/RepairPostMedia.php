<?php

namespace App\Console\Commands;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\FacebookPostMedia;
use App\Services\FacebookGraphService;
use App\Services\PostMediaPreviewService;
use Illuminate\Console\Command;

/**
 * Restores Publishing History thumbnails of existing posts.
 *
 * - Uploaded media: nothing is rewritten (thumbnail URLs are rebuilt at read time); missing files are reported.
 * - Media imported from Facebook (Meta CDN links that expire): copied to the public disk. Expired links are
 *   refreshed with a read-only Graph GET first.
 *
 * Only facebook_post_media.file_path / file_url change. Post status, platform ids, captions and dates are
 * never touched, and nothing is published.
 */
class RepairPostMedia extends Command
{
    protected $signature = 'posts:repair-media
        {--workspace= : Only repair this workspace id}
        {--dry-run : Report what would change without writing anything}
        {--no-refresh : Do not ask Meta for fresh links of expired images}';

    protected $description = 'Restore Publishing History thumbnails: copy imported Meta images locally and report missing uploads';

    /** @var array<int, ?string> page id => token (null when the page has none) */
    private array $tokens = [];

    public function handle(PostMediaPreviewService $previews, FacebookGraphService $graph): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = array_fill_keys(['posts', 'no_media', 'upload_ok', 'upload_missing', 'copy_ok', 'copied', 'refreshed_and_copied', 'would_copy', 'unavailable'], 0);
        $missing = [];
        $unavailable = [];

        $query = FacebookPost::with('media');
        if ($this->option('workspace') !== null) {
            $query->where('workspace_id', (int) $this->option('workspace'));
        }

        $query->chunkById(50, function ($posts) use ($previews, $graph, $dryRun, &$stats, &$missing, &$unavailable) {
            foreach ($posts as $post) {
                $stats['posts']++;
                $media = $post->media->sortBy('id')->values();
                if ($media->isEmpty()) {
                    $stats['no_media']++;
                    continue;
                }

                foreach ($media as $index => $m) {
                    $relative = $previews->relativePath($m->file_path, $m->file_url);
                    $isCopy = $relative !== null && $previews->isMirrorPath($relative);
                    $exists = $relative !== null && \Illuminate\Support\Facades\Storage::disk('public')->exists($relative);

                    if ($relative !== null && !$isCopy) {
                        $exists ? $stats['upload_ok']++ : $stats['upload_missing']++;
                        if (!$exists) {
                            $missing[] = [$post->id, $post->workspace_id, $post->status, $relative];
                        }
                        continue;
                    }
                    if ($isCopy && $exists) {
                        $stats['copy_ok']++;
                        continue;
                    }

                    // Imported from Meta (or a lost local copy): needs an https source.
                    $url = (string) $m->file_url;
                    if (!preg_match('#^https://#i', $url)) {
                        $stats['unavailable']++;
                        $unavailable[] = [$post->id, $post->workspace_id, 'no remote link stored'];
                        continue;
                    }
                    $key = ($post->fb_post_id ?: "post_{$post->id}") . ($index > 0 ? "_{$m->id}" : '');

                    if ($dryRun) {
                        $stats['would_copy']++;
                        continue;
                    }

                    $fresh = null;
                    $copy = $previews->remoteUrlExpired($url) ? null : $previews->mirrorRemoteImage($url, (int) $post->workspace_id, $key);
                    if ($copy === null && !$this->option('no-refresh') && $post->fb_post_id && $index === 0) {
                        $fresh = $this->freshPicture($post, $graph);
                        $copy = $fresh ? $previews->mirrorRemoteImage($fresh, (int) $post->workspace_id, $key) : null;
                    }

                    if ($copy === null) {
                        $stats['unavailable']++;
                        $unavailable[] = [$post->id, $post->workspace_id, $previews->remoteUrlExpired($url) ? 'link expired, Meta returned no picture' : 'download failed'];
                        continue;
                    }

                    // Only the media row changes (its own updated_at included); the post itself is not saved.
                    $updates = ['file_path' => $copy];
                    if ($fresh) {
                        $updates['file_url'] = $fresh;
                    }
                    FacebookPostMedia::whereKey($m->id)->update($updates + ['updated_at' => now()]);
                    $fresh ? $stats['refreshed_and_copied']++ : $stats['copied']++;
                }
            }
        });

        $this->info($dryRun ? 'Dry run - nothing was written.' : 'Repair finished.');
        $this->table(['Result', 'Media items'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        if ($missing) {
            $this->warn('Uploaded files that no longer exist (shown with a placeholder):');
            $this->table(['Post', 'Workspace', 'Status', 'Path'], $missing);
        }
        if ($unavailable) {
            $this->warn('Imported images that could not be restored (shown with a placeholder):');
            $this->table(['Post', 'Workspace', 'Reason'], $unavailable);
        }

        return self::SUCCESS;
    }

    private function freshPicture(FacebookPost $post, FacebookGraphService $graph): ?string
    {
        $page = $post->facebook_page_id
            ? FacebookPage::find($post->facebook_page_id)
            : FacebookPage::where('workspace_id', $post->workspace_id)->first();
        if (!$page) {
            return null;
        }
        if (!array_key_exists($page->id, $this->tokens)) {
            $this->tokens[$page->id] = $page->page_access_token ?: null;
        }
        $token = $this->tokens[$page->id];

        return $token ? $graph->fetchPostPicture((string) $post->fb_post_id, $token) : null;
    }
}
