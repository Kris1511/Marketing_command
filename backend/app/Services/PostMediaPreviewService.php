<?php

namespace App\Services;

use App\Models\FacebookPost;
use App\Models\FacebookPostMedia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Display URLs for the media of saved posts (Publishing History thumbnails).
 *
 * Stored `file_url` values cannot be trusted for display:
 *  - uploads store `APP_URL/storage/...`, which breaks when APP_URL names another server or when the
 *    `public/storage` symlink is missing (shared hosting often blocks symlink());
 *  - posts imported from Facebook store Meta CDN URLs whose signature (`oe=`) expires after a few days.
 *
 * So the URL is rebuilt at read time from the file on the public disk and served by the
 * `GET /api/v1/media/{path}` route (no symlink needed). Remote copies are kept only while their signature is valid.
 * `file_path` may be absolute (legacy uploads: C:\...\storage\app/public\posts/6/x.jpg) or disk-relative.
 */
class PostMediaPreviewService
{
    public const ROUTE_PATH = 'api/v1/media';
    public const MEDIA_ROOT = 'posts';
    public const MIRROR_DIR = 'synced';
    public const MAX_MIRROR_BYTES = 8 * 1024 * 1024;

    public const MIME_TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/mp4',
        'mov'  => 'video/quicktime',
        'webm' => 'video/webm',
    ];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function __construct(private PublicMediaUrlService $publicMedia)
    {
    }

    // ── Paths ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Disk-relative path ("posts/6/abc.jpeg") of a media file, taken from an absolute or relative file path or
     * from one of our own /storage or /api/v1/media URLs. Null when neither points inside the public posts folder.
     */
    public function relativePath(?string $filePath, ?string $fileUrl = null): ?string
    {
        $candidates = [$filePath];
        if ($fileUrl) {
            $candidates[] = parse_url($fileUrl, PHP_URL_PATH) ?: null;
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $path = str_replace('\\', '/', rawurldecode(trim($candidate)));
            if (preg_match('#(?:^|/)(?:storage/app/public|storage|api/v1/media)/(' . self::MEDIA_ROOT . '/.+)$#', $path, $m)) {
                $relative = $m[1];
            } elseif (str_starts_with(ltrim($path, '/'), self::MEDIA_ROOT . '/') && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)) {
                $relative = ltrim($path, '/');
            } else {
                continue;
            }
            if ($safe = $this->safeRelativePath($relative)) {
                return $safe;
            }
        }

        return null;
    }

    /** The path when it is a servable file inside posts/ (no traversal, known media extension), else null. */
    public function safeRelativePath(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }
        $path = preg_replace('#/+#', '/', ltrim($path, '/'));
        $segments = explode('/', $path);
        if ($segments[0] !== self::MEDIA_ROOT || in_array('..', $segments, true) || in_array('.', $segments, true) || in_array('', $segments, true)) {
            return null;
        }

        return isset(self::MIME_TYPES[$this->extension($path)]) ? $path : null;
    }

    public function mimeType(string $path): string
    {
        return self::MIME_TYPES[$this->extension($path)] ?? 'application/octet-stream';
    }

    public function isImagePath(string $path): bool
    {
        return in_array($this->extension($path), self::IMAGE_EXTENSIONS, true);
    }

    /** Absolute URL of a public-disk file, served by GET /api/v1/media/{path}. */
    public function publicUrl(string $relativePath): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return $this->baseUrl() . '/' . self::ROUTE_PATH . '/' . $encoded;
    }

    /**
     * Host that serves the media route. Production uses APP_URL (never a localhost/private host); elsewhere the
     * files live on the machine answering this request, while APP_URL may name the remote production server.
     */
    public function baseUrl(): string
    {
        $appUrl = rtrim(trim((string) config('app.url')), '/');
        // In console runs Laravel builds this request from APP_URL, so the fallbacks below stay consistent there.
        $request = app()->bound('request') ? request() : null;

        if (app()->environment('production')) {
            $host = (string) parse_url($appUrl, PHP_URL_HOST);
            if ($appUrl !== '' && !$this->publicMedia->isLocalOrPrivateHost($host)) {
                return $appUrl;
            }
            if ($request) {
                Log::warning('[MEDIA PREVIEW] APP_URL is empty or local in production; using the request host for media URLs.', ['app_url' => $appUrl]);

                return rtrim($request->getSchemeAndHttpHost(), '/');
            }

            return $appUrl;
        }

        if ($request) {
            return rtrim($request->getSchemeAndHttpHost(), '/');
        }

        return $appUrl !== '' ? $appUrl : 'http://localhost';
    }

    // ── Describing media ──────────────────────────────────────────────────────────────────────

    /**
     * How one stored file can be displayed.
     *
     * @return array{url: ?string, kind: string, status: string}
     *   kind: image|video|unknown (of the file itself: an imported video's poster is an image);
     *   status: ok (our file) | remote (valid third-party URL) | missing (our file is gone) | expired (CDN link
     *   expired) | none (nothing stored)
     */
    public function describe(?string $filePath, ?string $fileUrl, ?string $mediaType = null): array
    {
        $relative = $this->relativePath($filePath, $fileUrl);
        if ($relative !== null) {
            $kind = $this->isImagePath($relative) ? 'image' : 'video';

            return Storage::disk('public')->exists($relative)
                ? ['url' => $this->publicUrl($relative), 'kind' => $kind, 'status' => 'ok']
                : ['url' => null, 'kind' => $kind, 'status' => 'missing'];
        }

        $url = trim((string) $fileUrl);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return ['url' => null, 'kind' => $mediaType === 'video' ? 'video' : 'unknown', 'status' => 'none'];
        }

        $kind = $this->isImagePath((string) parse_url($url, PHP_URL_PATH)) ? 'image' : ($mediaType === 'video' ? 'video' : 'image');
        if ($this->remoteUrlExpired($url)) {
            return ['url' => null, 'kind' => $kind, 'status' => 'expired'];
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($this->publicMedia->isLocalOrPrivateHost($host)) {
            // e.g. http://localhost:8000/... pointing outside the posts folder: unreachable for any viewer.
            return ['url' => null, 'kind' => $kind, 'status' => 'missing'];
        }

        return ['url' => $url, 'kind' => $kind, 'status' => 'remote'];
    }

    /** Meta CDN links carry their expiry as hex unix time in `oe`; true once it has passed. */
    public function remoteUrlExpired(string $url, int $graceSeconds = 0): bool
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $oe = $query['oe'] ?? null;
        if (!is_string($oe) || !ctype_xdigit($oe)) {
            return false;
        }

        return hexdec($oe) <= time() + $graceSeconds;
    }

    /**
     * Thumbnail data for one post: custom thumbnail, else first image (carousel cover = lowest sort_order),
     * else the video itself (the browser renders its first frame), else a placeholder reason.
     *
     * $generatedKeys: local copies of the platforms' own generated thumbnails to use for a video without a custom
     * thumbnail (e.g. [fb_post_id, 'ig_<media id>', 'yt_<video id>'], see PlatformPostVerifier), before the video
     * frame fallback. source: custom | image | platform | video_frame | none.
     *
     * @return array{url: ?string, kind: string, status: string, source: string, is_video: bool, media_count: int, video_url: ?string}
     */
    public function previewFor(FacebookPost $post, array $generatedKeys = []): array
    {
        $media = $post->relationLoaded('media') ? $post->media : $post->media()->get();
        $media = $media->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();

        $described = $media->map(fn (FacebookPostMedia $m) => $this->describe($m->file_path, $m->file_url, $m->media_type));
        $isVideo = in_array($post->post_type, ['video', 'reel'], true) || $media->contains('media_type', 'video');
        $videoUrl = $described->first(fn ($d) => $d['url'] && $d['kind'] === 'video')['url'] ?? null;

        $result = fn (?string $url, string $kind, string $status, string $source) => [
            'url'         => $url,
            'kind'        => $kind,
            'status'      => $status,
            'source'      => $source,
            'is_video'    => $isVideo,
            'media_count' => $media->count(),
            'video_url'   => $videoUrl,
        ];

        if (FacebookPost::supportsThumbnails() && ($post->thumbnail_path || $post->thumbnail_url)) {
            $thumb = $this->describe($post->thumbnail_path, $post->thumbnail_url, 'image');
            if ($thumb['url'] && $thumb['kind'] === 'image') {
                return $result($thumb['url'], 'image', $thumb['status'], 'custom');
            }
        }

        if ($image = $described->first(fn ($d) => $d['url'] && $d['kind'] === 'image')) {
            return $result($image['url'], 'image', $image['status'], 'image');
        }
        foreach ($generatedKeys as $key) {
            if ($key && ($copy = $this->existingMirror((int) $post->workspace_id, (string) $key))) {
                return $result($this->publicUrl($copy), 'image', 'ok', 'platform');
            }
        }
        if ($videoUrl) {
            return $result($videoUrl, 'video', 'ok', 'video_frame');
        }

        $reason = $described->first(fn ($d) => in_array($d['status'], ['missing', 'expired'], true))['status'] ?? 'none';

        return $result(null, 'none', $reason, 'none');
    }

    /** Adds `display_url` / `media_status` to each media item and a `preview` block to the post (response only). */
    public function decorate(FacebookPost $post): FacebookPost
    {
        if ($post->relationLoaded('media')) {
            foreach ($post->media as $m) {
                $d = $this->describe($m->file_path, $m->file_url, $m->media_type);
                $m->setAttribute('display_url', $d['url']);
                $m->setAttribute('display_kind', $d['kind']);
                $m->setAttribute('media_status', $d['status']);
            }
        }
        $post->setAttribute('preview', $this->previewFor($post));

        return $post;
    }

    // ── Local copies of remote images ─────────────────────────────────────────────────────────

    /** Disk-relative folder for local copies of a workspace's imported (Meta) images. */
    public function mirrorPathFor(int $workspaceId, string $key, string $extension = 'jpg'): string
    {
        $safeKey = preg_replace('/[^A-Za-z0-9_-]/', '_', $key);

        return self::MEDIA_ROOT . "/{$workspaceId}/" . self::MIRROR_DIR . "/{$safeKey}.{$extension}";
    }

    /** Disk-relative path of an existing local copy for this key (any image extension), else null. */
    public function existingMirror(int $workspaceId, string $key): ?string
    {
        foreach (['jpg', 'png', 'webp', 'gif'] as $extension) {
            $path = $this->mirrorPathFor($workspaceId, $key, $extension);
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /** True when the path is one of our local copies of an imported image (not an original upload). */
    public function isMirrorPath(?string $filePath): bool
    {
        $relative = $this->relativePath($filePath);

        return $relative !== null && (bool) preg_match('#^' . self::MEDIA_ROOT . '/\d+/' . self::MIRROR_DIR . '/#', $relative);
    }

    /**
     * Downloads a public HTTPS image (e.g. a Meta CDN picture) into the public disk so its thumbnail never expires.
     * Returns the disk-relative path, or null when the URL is unusable. Never throws.
     */
    public function mirrorRemoteImage(string $url, int $workspaceId, string $key): ?string
    {
        try {
            $parts = parse_url($url);
            if (strtolower($parts['scheme'] ?? '') !== 'https' || $this->publicMedia->isLocalOrPrivateHost((string) ($parts['host'] ?? ''))) {
                return null;
            }
            if ($this->remoteUrlExpired($url)) {
                return null;
            }

            $response = Http::timeout(15)->withHeaders(['Accept' => 'image/*'])->get($url);
            if (!$response->successful()) {
                return null;
            }
            $body = $response->body();
            if ($body === '' || strlen($body) > self::MAX_MIRROR_BYTES) {
                return null;
            }
            $info = @getimagesizefromstring($body);
            $extension = match ($info['mime'] ?? null) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
                default      => null,
            };
            if ($extension === null) {
                return null;
            }

            $relative = $this->mirrorPathFor($workspaceId, $key, $extension);
            Storage::disk('public')->put($relative, $body);

            return $relative;
        } catch (\Throwable $e) {
            Log::warning('[MEDIA PREVIEW] Could not copy remote image: ' . $e->getMessage(), ['key' => $key]);

            return null;
        }
    }

    private function extension(string $path): string
    {
        return strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
    }
}
