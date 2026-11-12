<?php

namespace App\Console\Commands;

use App\Models\FacebookPost;
use App\Services\PlatformPostVerifier;
use Illuminate\Console\Command;

/**
 * Background check: are published posts still on Facebook / Instagram / YouTube? Read-only API calls; results go
 * to post_platform_verifications (shown in the Overview's Latest Posts). Never deletes or republishes anything.
 */
class VerifyPlatformPosts extends Command
{
    protected $signature = 'posts:verify-platform-status
        {--workspace= : Only this workspace id}
        {--dry-run : Report what was found without storing it}';

    protected $description = 'Check whether published posts still exist on Facebook, Instagram and YouTube (deleted / unable to verify)';

    public function handle(PlatformPostVerifier $verifier): int
    {
        $workspaces = $this->option('workspace') !== null
            ? collect([(int) $this->option('workspace')])
            : FacebookPost::where(fn ($q) => $q->whereNotNull('fb_post_id')->orWhereNotNull('ig_media_id')->orWhereNotNull('yt_video_id'))
                ->distinct()->pluck('workspace_id');

        foreach ($workspaces as $workspaceId) {
            try {
                $summary = $verifier->verifyWorkspace((int) $workspaceId, (bool) $this->option('dry-run'));
            } catch (\Throwable $e) {
                $this->error("Workspace {$workspaceId}: " . $e->getMessage());
                continue;
            }
            ksort($summary);
            $this->info("Workspace {$workspaceId}" . ($this->option('dry-run') ? ' (dry run)' : '') . ':');
            foreach ($summary as $key => $count) {
                $this->line("  {$key}: {$count}");
            }
        }

        return self::SUCCESS;
    }
}
