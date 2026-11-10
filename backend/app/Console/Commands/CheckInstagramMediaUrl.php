<?php

namespace App\Console\Commands;

use App\Exceptions\InstagramMediaException;
use App\Services\PublicMediaUrlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Proves end-to-end that Instagram (Meta) will be able to download post media from this server:
 * writes a small probe JPEG to public storage, builds its public URL exactly as publishing does,
 * downloads it through that URL, then deletes the probe. No Meta API call is made.
 */
class CheckInstagramMediaUrl extends Command
{
    protected $signature = 'instagram:check-media-url
                            {--url= : Check an existing media URL instead of uploading a probe image}';

    protected $description = 'Verify that post media is reachable by Meta over public HTTPS (no Instagram API call is made)';

    public function handle(PublicMediaUrlService $media): int
    {
        $this->line('PUBLIC_MEDIA_URL    : ' . (config('services.public_media.url') ?: '(not set, falls back to APP_URL)'));
        $this->line('APP_URL             : ' . config('app.url'));
        $this->line('Media driver        : ' . config('services.public_media.driver', 'auto'));
        $this->line('Cloudinary fallback : ' . ($media->cloudinaryConfigured() ? 'configured' : 'not configured'));
        $this->line('Verify downloads    : ' . (filter_var(config('services.public_media.verify', true), FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'NO (PUBLIC_MEDIA_VERIFY=false)'));
        $this->checkStorageLink();
        $this->newLine();

        if ($url = $this->option('url')) {
            try {
                $media->assertPublicHttpsUrl($url);
                $media->verifyReachable($url);
                $this->info("OK: {$url} is public HTTPS, returns HTTP 200 and a valid JPEG for Instagram.");
                return self::SUCCESS;
            } catch (InstagramMediaException $e) {
                $this->error('FAILED: ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        if (!function_exists('imagecreatetruecolor')) {
            $this->error('The PHP GD extension is required to generate the probe image. Use --url=<https url> instead.');
            return self::FAILURE;
        }

        $disk  = Storage::disk('public');
        $probe = 'posts/_healthcheck/probe-' . Str::uuid()->toString() . '.jpg';

        $img = imagecreatetruecolor(1080, 1080);
        imagefill($img, 0, 0, imagecolorallocate($img, 24, 119, 242));
        ob_start();
        imagejpeg($img, null, 85);
        $disk->put($probe, (string) ob_get_clean());

        try {
            $previewUrl = $media->storageUrl($probe);
            $this->line("Probe file          : storage/app/public/{$probe}");
            $this->line("Probe public URL    : {$previewUrl}");

            $url = $media->resolveForInstagram($disk->path($probe), 0, 'image');

            $this->newLine();
            $this->info("OK: Instagram will be able to download media from {$url}");
            if (str_contains($url, 'res.cloudinary.com')) {
                $this->warn('Note: the public storage URL was not usable, so the Cloudinary fallback was used.');
            }
            return self::SUCCESS;
        } catch (InstagramMediaException $e) {
            $this->newLine();
            $this->error('FAILED: ' . $e->getMessage());
            return self::FAILURE;
        } finally {
            $disk->delete($probe);
            $converted = preg_replace('/\.jpg$/', '.ig.jpg', $probe);
            if ($disk->exists($converted)) {
                $disk->delete($converted);
            }
            if (empty($disk->allFiles('posts/_healthcheck'))) {
                $disk->deleteDirectory('posts/_healthcheck');
            }
        }
    }

    protected function checkStorageLink(): void
    {
        $link   = public_path('storage');
        $target = storage_path('app/public');

        if (!file_exists($link)) {
            $this->warn('public/storage      : MISSING or broken. Run `php artisan storage:link --force` (needed when the web server serves /storage).');
            return;
        }

        $resolved = realpath($link);
        if ($resolved !== false && strcasecmp(str_replace('\\', '/', $resolved), str_replace('\\', '/', (string) realpath($target))) === 0) {
            $this->line('public/storage      : OK (links to storage/app/public)');
        } else {
            $this->warn("public/storage      : points to {$resolved}, expected {$target}. Run `php artisan storage:link --force`.");
        }
    }
}
