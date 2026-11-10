<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Simulates a public HTTPS media host (e.g. a Cloudflare Tunnel) that serves the faked `public` disk,
 * so the Instagram media URL checks run against real file bytes without any network access.
 */
trait FakesPublicMediaHost
{
    protected string $mediaHost = 'https://media.example.test';

    protected function usePublicMediaHost(?string $base = null, bool $serve = true): void
    {
        $base = $base ?? $this->mediaHost;

        config([
            'services.public_media.url'              => $base,
            'services.public_media.driver'           => 'auto',
            'services.public_media.verify'           => true,
            'services.cloudinary.cloud_name'         => null,
            'services.cloudinary.api_key'            => null,
            'services.cloudinary.api_secret'         => null,
            'services.instagram.container_poll_seconds' => 0,
        ]);

        if ($serve) {
            $host = parse_url($base, PHP_URL_HOST);
            Http::fake([
                "{$host}/*" => function ($request) {
                    $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));
                    $relative = preg_replace('#^/storage/#', '', $path);
                    $disk = Storage::disk('public');
                    if ($relative === $path || !$disk->exists($relative)) {
                        return Http::response('<html>Not Found</html>', 404, ['Content-Type' => 'text/html; charset=UTF-8']);
                    }
                    $mime = mime_content_type($disk->path($relative)) ?: 'application/octet-stream';
                    return Http::response($disk->get($relative), 200, ['Content-Type' => $mime]);
                },
            ]);
        }
    }

    /** Every outbound request that was made to Instagram to create a media container. */
    protected function instagramContainerRequests(): array
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST'
            && preg_match('#/media$#', parse_url($request->url(), PHP_URL_PATH)))
            ->map(fn ($pair) => $pair[0])
            ->values()
            ->all();
    }

    /** Raw bytes of a generated JPEG (kept in a variable so the temp file is not garbage-collected early). */
    protected function jpegBytes(int $width = 1080, int $height = 1080): string
    {
        $file = \Illuminate\Http\UploadedFile::fake()->image('generated.jpg', $width, $height);

        return file_get_contents($file->getRealPath());
    }
}
