<?php

namespace App\Services;

use App\Exceptions\InstagramMediaException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds the media URL that Instagram's Content Publishing API downloads (`image_url` / `video_url`)
 * and checks that the URL works before it is sent to Meta.
 *
 * Instagram never receives file bytes from us. Meta's servers fetch the URL themselves, so the URL must be:
 *   - HTTPS on a public host (never localhost, 127.0.0.1, a LAN/private IP or an intranet name),
 *   - served anonymously with HTTP 200 and an image/jpeg (or video/*) Content-Type,
 *   - an image Instagram accepts (JPEG, max 8 MB, aspect ratio between 4:5 and 1.91:1).
 *
 * URLs are always built from configuration (PUBLIC_MEDIA_URL, then APP_URL) and never from the incoming
 * request's host, which in local development is http://localhost:8000.
 */
class PublicMediaUrlService
{
    public const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
    public const MIN_ASPECT_RATIO = 0.8;   // 4:5 portrait
    public const MAX_ASPECT_RATIO = 1.91;  // 1.91:1 landscape
    private const ASPECT_TOLERANCE = 0.01;

    /** Image types we accept from users. Anything that is not JPEG is converted to JPEG for Instagram. */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/x-ms-bmp'];
    private const VIDEO_MIMES = ['video/mp4', 'video/quicktime'];

    /** Only post media under this public-disk folder is ever published. */
    private const MEDIA_ROOT = 'posts';

    /**
     * Resolve post media to a validated public HTTPS URL for Instagram.
     *
     * @param  mixed   $source       UploadedFile, absolute local path, public-disk relative path, or a URL.
     * @param  string  $kind         'image' or 'video'
     *
     * @throws InstagramMediaException with a user-facing explanation when no usable URL can be produced.
     */
    public function resolveForInstagram(mixed $source, int $workspaceId, string $kind = 'image'): string
    {
        // 'cover' = Reel cover image: same JPEG / public HTTPS / size rules as feed images, but Instagram does
        // not apply the 4:5–1.91:1 feed aspect-ratio limit to covers (9:16 is the recommended cover shape).
        $previousAspectCheck = $this->checkAspectRatio;
        $this->checkAspectRatio = $kind !== 'cover';
        try {
            return $this->resolve($source, $workspaceId, $kind === 'video' ? 'video' : 'image');
        } finally {
            $this->checkAspectRatio = $previousAspectCheck;
        }
    }

    /** Whether image aspect ratios are enforced (false while resolving a Reel cover). */
    protected bool $checkAspectRatio = true;

    protected function resolve(mixed $source, int $workspaceId, string $kind): string
    {

        $absolutePath = $this->localFileFor($source, $workspaceId);

        if ($absolutePath === null) {
            return $this->resolveRemoteSource($source, $kind);
        }

        $relative = $this->preparePublicCopy($absolutePath, $workspaceId, $kind);
        $disk     = Storage::disk('public');
        $driver   = $this->driver();
        $storageError = null;

        if ($driver !== 'cloudinary') {
            try {
                $url = $this->storageUrl($relative, $kind);
                $this->assertUsable($url, $kind);
                return $url;
            } catch (InstagramMediaException $e) {
                if ($driver === 'storage' || !$this->cloudinaryConfigured()) {
                    throw $e;
                }
                $storageError = $e->getMessage();
                Log::warning('[Instagram Media] Public storage URL is not usable, falling back to Cloudinary', [
                    'relative_path' => $relative,
                    'reason'        => $storageError,
                ]);
            }
        }

        if (!$this->cloudinaryConfigured()) {
            throw new InstagramMediaException(
                'PUBLIC_MEDIA_DRIVER is set to "cloudinary" but CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET are not all configured.'
            );
        }

        $url = $this->uploadToCloudinary($disk->path($relative), basename($relative), $kind);
        if (empty($url)) {
            throw new InstagramMediaException(trim(
                ($storageError ? $storageError . ' ' : '')
                . "Uploading the {$kind} to Cloudinary also failed, so no public HTTPS URL is available for Instagram."
            ));
        }

        $this->assertUsable($url, $kind);

        return $url;
    }

    /**
     * Local, network-free check of a media file against Instagram's format rules (type, size, aspect ratio).
     * Used when a post is scheduled, so a file Instagram will reject is reported now instead of at publish time.
     *
     * @return string|null  user-facing problem, or null when the file is acceptable
     */
    public function localMediaProblem(string $path, string $kind = 'image'): ?string
    {
        if (!is_file($path)) {
            return 'The media file is missing from server storage.';
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';

        if ($kind === 'video') {
            return in_array($mime, self::VIDEO_MIMES, true) ? null : "Unsupported video type '{$mime}'. Instagram accepts MP4 or MOV videos.";
        }

        if (!in_array($mime, self::IMAGE_MIMES, true)) {
            return "Unsupported image type '{$mime}'. Instagram accepts JPEG images (PNG, WebP, GIF and BMP are converted to JPEG automatically).";
        }
        if ($mime === 'image/jpeg' && filesize($path) > self::MAX_IMAGE_BYTES) {
            return 'The image is larger than 8 MB, which is the Instagram limit.';
        }
        $info = @getimagesize($path);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return 'The selected file is not a valid image.';
        }
        try {
            $this->assertAspectRatio((int) $info[0], (int) $info[1]);
        } catch (InstagramMediaException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Validate that a URL is HTTPS, on a public host, and (unless disabled) downloadable as the right media type.
     *
     * @throws InstagramMediaException
     */
    public function assertUsable(string $url, string $kind = 'image'): void
    {
        $this->assertPublicHttpsUrl($url, $kind);

        if ($this->shouldVerify()) {
            $this->verifyReachable($url, $kind);
        }
    }

    /**
     * Syntactic checks only: scheme, host, no credentials, no localhost / private / reserved / intranet hosts.
     *
     * @throws InstagramMediaException
     */
    public function assertPublicHttpsUrl(string $url, string $kind = 'image'): void
    {
        $label = $kind === 'video' ? 'video' : 'image';
        $hint  = ' Set PUBLIC_MEDIA_URL to a public HTTPS address that serves this server\'s /storage files '
               . '(a Cloudflare Tunnel or ngrok URL in local development, your real domain in production), or configure Cloudinary.';

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InstagramMediaException("Invalid {$label} URL for Instagram: '{$url}'. A full public HTTPS URL is required.");
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new InstagramMediaException(
                "Instagram requires a publicly accessible HTTPS {$label} URL. Provided URL is not HTTPS: '{$url}'." . $hint
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InstagramMediaException("Instagram {$label} URLs must not contain credentials: '{$url}'.");
        }

        if ($this->isLocalOrPrivateHost($parts['host'])) {
            throw new InstagramMediaException(
                "Instagram cannot fetch local URLs ('{$url}'). Meta's servers can only download media from a public internet host." . $hint
            );
        }
    }

    /**
     * True for localhost, loopback, private/reserved IP literals and names that only resolve inside a LAN.
     */
    public function isLocalOrPrivateHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]. '));

        if ($host === '' || in_array($host, ['localhost', '0.0.0.0', '::', '::1'], true)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        foreach (['.localhost', '.local', '.internal', '.lan', '.home.arpa', '.intranet'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // Single-label names ("myserver") only resolve on the local network.
        return !str_contains($host, '.');
    }

    /**
     * Download the URL the way Meta would and check status, Content-Type, size and (for images) dimensions.
     *
     * @throws InstagramMediaException
     */
    public function verifyReachable(string $url, string $kind = 'image'): void
    {
        $label = $kind === 'video' ? 'video' : 'image';

        try {
            $response = $this->fetch($url, $kind, true);
        } catch (ConnectionException $e) {
            if (preg_match('/cURL error (60|77)/', $e->getMessage())) {
                // This PHP install has no usable CA bundle (common on Windows). Re-check without TLS
                // verification so the rest of the checks still run; Meta itself validates the certificate.
                Log::warning('[Instagram Media] TLS verification unavailable locally; re-checking media URL without it', ['url' => $url]);
                try {
                    $response = $this->fetch($url, $kind, false);
                } catch (ConnectionException $e2) {
                    throw $this->unreachable($url, $label, $e2);
                }
            } else {
                throw $this->unreachable($url, $label, $e);
            }
        }

        $status = $response->status();
        if ($status === 401 || $status === 403) {
            throw new InstagramMediaException(
                "The {$label} URL '{$url}' requires authentication (HTTP {$status}). Instagram needs media that anyone can download without logging in."
            );
        }
        if ($status === 404 || $status === 410) {
            throw new InstagramMediaException(
                "The {$label} URL '{$url}' returned HTTP {$status} (not found), so Instagram cannot download it. "
                . 'The public base URL must point at the same server that stored the file: in local development set PUBLIC_MEDIA_URL '
                . 'to your HTTPS tunnel instead of the production domain, and make sure `php artisan storage:link` has been run (or the media server is running).'
            );
        }
        if (!in_array($status, [200, 206], true)) {
            throw new InstagramMediaException(
                "The {$label} URL '{$url}' returned HTTP {$status} instead of 200, so Instagram cannot download it."
            );
        }

        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        if ($kind === 'video') {
            if (!str_starts_with($contentType, 'video/')) {
                throw new InstagramMediaException(
                    "The video URL '{$url}' is served as '" . ($contentType ?: 'unknown') . "', not as a video. Instagram needs an MP4/MOV file served with a video Content-Type."
                );
            }
            return;
        }

        if ($contentType !== 'image/jpeg') {
            $why = str_starts_with($contentType, 'text/html')
                ? ' It returned an HTML page instead of the image (a login page, an error page, or a tunnel warning page such as the free ngrok browser warning).'
                : '';
            throw new InstagramMediaException(
                "The image URL '{$url}' is served as '" . ($contentType ?: 'unknown') . "'. Instagram only accepts JPEG images served as image/jpeg." . $why
            );
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_IMAGE_BYTES) {
            throw new InstagramMediaException('The image is larger than 8 MB, which is the Instagram limit. Please upload a smaller image.');
        }

        $size = $body === '' ? false : @getimagesizefromstring($body);
        if ($size === false) {
            throw new InstagramMediaException("The image URL '{$url}' did not return a valid image file.");
        }

        $this->assertAspectRatio((int) $size[0], (int) $size[1]);
    }

    /**
     * Upload a local file to Cloudinary and return its HTTPS URL (JPEG for images), or null on failure.
     */
    public function uploadToCloudinary($fileOrPath, string $filename, string $resourceType = 'image'): ?string
    {
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey    = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');

        if (empty($cloudName) || empty($apiKey) || empty($apiSecret)) {
            return null;
        }

        $realPath = is_object($fileOrPath) && method_exists($fileOrPath, 'getRealPath')
            ? $fileOrPath->getRealPath()
            : (is_string($fileOrPath) ? $fileOrPath : null);

        if (!$realPath || !is_file($realPath)) {
            return null;
        }

        $timestamp = time();
        $signature = sha1("timestamp={$timestamp}{$apiSecret}");

        try {
            $response = Http::withoutVerifying()
                ->timeout(60)
                ->connectTimeout(15)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->attach('file', file_get_contents($realPath), $filename)
                ->post("https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/upload", [
                    'api_key'   => $apiKey,
                    'timestamp' => $timestamp,
                    'signature' => $signature,
                ]);

            $secureUrl = $response->successful() ? $response->json('secure_url') : null;
            if (empty($secureUrl)) {
                Log::warning('[Cloudinary Upload Error]', [
                    'status'   => $response->status(),
                    'response' => $response->json() ?? $response->body(),
                ]);
                return null;
            }

            $lower = strtolower($secureUrl);
            if ($resourceType === 'image' && !str_ends_with($lower, '.jpg') && !str_ends_with($lower, '.jpeg')) {
                // Instagram only accepts JPEG; ask Cloudinary to deliver a JPEG rendition.
                $secureUrl = preg_replace('/\/image\/upload\/(v\d+\/)?/', '/image/upload/f_jpg/$1', $secureUrl);
                $secureUrl = preg_replace('/\.[a-zA-Z0-9]+$/', '.jpg', $secureUrl);
            }

            Log::info('[Cloudinary Upload Success]', [
                'filename'      => $filename,
                'resource_type' => $resourceType,
                'secure_url'    => $secureUrl,
            ]);

            return $secureUrl;
        } catch (\Throwable $e) {
            Log::warning('[Cloudinary Upload Exception] ' . $e->getMessage());
            return null;
        }
    }

    public function cloudinaryConfigured(): bool
    {
        return !empty(config('services.cloudinary.cloud_name'))
            && !empty(config('services.cloudinary.api_key'))
            && !empty(config('services.cloudinary.api_secret'));
    }

    /**
     * The configured public base URL (PUBLIC_MEDIA_URL, else APP_URL), without trailing slash.
     */
    public function publicBaseUrl(): ?string
    {
        $base = trim((string) (config('services.public_media.url') ?: config('app.url')));

        return $base === '' ? null : rtrim($base, '/');
    }

    /**
     * Public URL for a file on the public disk, e.g. posts/6/abc.jpeg → https://media.example.com/storage/posts/6/abc.jpeg
     */
    public function storageUrl(string $relativePath, string $kind = 'image'): string
    {
        $base = $this->publicBaseUrl();
        if ($base === null) {
            throw new InstagramMediaException(
                'Instagram requires a publicly accessible HTTPS ' . ($kind === 'video' ? 'video' : 'image')
                . ' URL, but neither PUBLIC_MEDIA_URL nor APP_URL is configured.'
            );
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', ltrim($relativePath, '/\\')))));

        return "{$base}/storage/{$encoded}";
    }

    // ------------------------------------------------------------------------------------------------

    protected function driver(): string
    {
        $driver = strtolower((string) config('services.public_media.driver', 'auto'));

        return in_array($driver, ['auto', 'storage', 'cloudinary'], true) ? $driver : 'auto';
    }

    protected function shouldVerify(): bool
    {
        return filter_var(config('services.public_media.verify', true), FILTER_VALIDATE_BOOLEAN);
    }

    protected function fetch(string $url, string $kind, bool $verifyTls): Response
    {
        $timeout = max(3, (int) config('services.public_media.verify_timeout', 15));

        $request = Http::withOptions([
                'verify'          => $verifyTls,
                'allow_redirects' => ['max' => 3, 'protocols' => ['https'], 'strict' => true],
            ])
            ->timeout($timeout)
            ->connectTimeout(min(10, $timeout))
            ->withHeaders([
                'User-Agent' => 'MarketingCommand-MediaCheck/1.0',
                'Accept'     => $kind === 'video' ? 'video/*' : 'image/*',
            ]);

        // Images are small (<= 8 MB) and are downloaded in full so dimensions can be checked.
        // For videos only the first bytes are requested.
        if ($kind === 'video') {
            $request = $request->withHeaders(['Range' => 'bytes=0-1023']);
        }

        return $request->get($url);
    }

    protected function unreachable(string $url, string $label, \Throwable $e): InstagramMediaException
    {
        $reason = Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()), 180);

        return new InstagramMediaException(
            "The {$label} URL '{$url}' could not be reached from this server ({$reason}), so Meta will not be able to download it either. "
            . 'Check that the public HTTPS tunnel or web server is running and that PUBLIC_MEDIA_URL is correct.'
        );
    }

    /**
     * Turn the publishing source into an absolute local file path, or null when the source is a URL.
     * Uploaded files are stored on the public disk first (never on the private default disk).
     */
    protected function localFileFor(mixed $source, int $workspaceId): ?string
    {
        if (is_object($source) && method_exists($source, 'getRealPath') && method_exists($source, 'storeAs')) {
            $ext      = strtolower(method_exists($source, 'getClientOriginalExtension') ? ($source->getClientOriginalExtension() ?: 'jpg') : 'jpg');
            $filename = Str::uuid()->toString() . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
            $stored   = $source->storeAs(self::MEDIA_ROOT . "/{$workspaceId}", $filename, 'public');
            if ($stored === false) {
                throw new InstagramMediaException('The uploaded media file could not be saved to public storage.');
            }
            return Storage::disk('public')->path($stored);
        }

        if (!is_string($source) || trim($source) === '') {
            throw new InstagramMediaException('Instagram publishing failed: no media file or URL was provided.');
        }

        $source = trim($source);
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $source)) {
            return null; // a URL
        }

        if (is_file($source)) {
            return $source;
        }

        // A path relative to the public disk ("posts/6/x.jpg" or "storage/posts/6/x.jpg").
        $relative = preg_replace('#^(public|storage)[/\\\\]#i', '', ltrim(str_replace('\\', '/', $source), '/'));
        if (str_starts_with($relative, self::MEDIA_ROOT . '/') && Storage::disk('public')->exists($relative)) {
            return Storage::disk('public')->path($relative);
        }

        throw new InstagramMediaException(
            "Instagram publishing failed: the media file '" . basename(str_replace('\\', '/', $source)) . "' is missing from server storage. Please re-attach the media and try again."
        );
    }

    /**
     * A source that is already a URL (e.g. legacy media rows without a local file).
     */
    protected function resolveRemoteSource(mixed $source, string $kind): string
    {
        $url = trim((string) $source);

        // A URL pointing at our own public disk whose file still exists locally can be re-issued with
        // the current public base (stored URLs go stale when APP_URL or the tunnel address changes).
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && preg_match('#/storage/(' . self::MEDIA_ROOT . '/.+)$#', rawurldecode($path), $m)
            && !str_contains($m[1], '..') && Storage::disk('public')->exists($m[1])) {
            $rebuilt = $this->storageUrl($m[1], $kind);
            if ($rebuilt !== $url) {
                Log::info('[Instagram Media] Re-issued stored media URL with the current public base', ['from' => $url, 'to' => $rebuilt]);
            }
            $url = $rebuilt;
        }

        $this->assertUsable($url, $kind);

        return $url;
    }

    /**
     * Validate the local file and make sure a publishable copy exists on the public disk under posts/.
     * Returns the public-disk relative path to publish.
     *
     * @throws InstagramMediaException
     */
    protected function preparePublicCopy(string $absolutePath, int $workspaceId, string $kind): string
    {
        $real = realpath($absolutePath);
        if ($real === false || !is_file($real)) {
            throw new InstagramMediaException('Instagram publishing failed: the media file is missing from server storage.');
        }

        // Never publish files from outside the application's storage directory.
        if ($this->relativeTo($real, storage_path()) === null) {
            throw new InstagramMediaException('Instagram publishing failed: the media file is outside application storage and cannot be published.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($real) ?: 'application/octet-stream';
        $disk = Storage::disk('public');
        $inPublicDisk = $this->relativeTo($real, $disk->path(''));
        $publishable  = $inPublicDisk !== null && str_starts_with($inPublicDisk, self::MEDIA_ROOT . '/') ? $inPublicDisk : null;

        if ($kind === 'video') {
            if (!in_array($mime, self::VIDEO_MIMES, true)) {
                throw new InstagramMediaException("Unsupported video type '{$mime}'. Instagram accepts MP4 or MOV videos.");
            }
            if ($publishable === null) {
                $ext = $mime === 'video/quicktime' ? 'mov' : 'mp4';
                $publishable = self::MEDIA_ROOT . "/{$workspaceId}/" . Str::uuid()->toString() . ".{$ext}";
                $disk->put($publishable, file_get_contents($real));
            }
            return $publishable;
        }

        if (!in_array($mime, self::IMAGE_MIMES, true)) {
            throw new InstagramMediaException(
                "Unsupported image type '{$mime}'. Instagram accepts JPEG images (PNG, WebP, GIF and BMP are converted to JPEG automatically)."
            );
        }

        $info = @getimagesize($real);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            throw new InstagramMediaException('The selected file is not a valid image.');
        }
        $this->assertAspectRatio((int) $info[0], (int) $info[1]);

        if ($mime === 'image/jpeg') {
            if (filesize($real) > self::MAX_IMAGE_BYTES) {
                throw new InstagramMediaException('The image is larger than 8 MB, which is the Instagram limit. Please upload a smaller image.');
            }
            if ($publishable === null) {
                $publishable = self::MEDIA_ROOT . "/{$workspaceId}/" . Str::uuid()->toString() . '.jpg';
                $disk->put($publishable, file_get_contents($real));
            }
            return $publishable;
        }

        // Convert PNG/WebP/GIF/BMP to a JPEG rendition stored next to the original (deterministic name, so
        // re-publishing a draft or retrying a scheduled post reuses it).
        $target = $publishable !== null
            ? preg_replace('/\.[^.\/]+$/', '', $publishable) . '.ig.jpg'
            : self::MEDIA_ROOT . "/{$workspaceId}/" . pathinfo($real, PATHINFO_FILENAME) . '.ig.jpg';

        if (!$disk->exists($target)) {
            $disk->put($target, $this->convertToJpeg($real, (int) $info[0], (int) $info[1]));
        }

        if ($disk->size($target) > self::MAX_IMAGE_BYTES) {
            throw new InstagramMediaException('The image is larger than 8 MB after conversion to JPEG, which is the Instagram limit. Please upload a smaller image.');
        }

        return $target;
    }

    protected function convertToJpeg(string $path, int $width, int $height): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new InstagramMediaException('Instagram only accepts JPEG images and the PHP GD extension is not available to convert this image. Please upload a JPEG.');
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            throw new InstagramMediaException('This image format cannot be converted to JPEG on this server. Please upload a JPEG image.');
        }

        // Flatten transparency onto white (JPEG has no alpha channel).
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = (string) ob_get_clean();

        return $jpeg;
    }

    /**
     * @throws InstagramMediaException
     */
    protected function assertAspectRatio(int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new InstagramMediaException('The image has invalid dimensions.');
        }
        if (!$this->checkAspectRatio) {
            return;
        }

        $ratio = $width / $height;
        if ($ratio < self::MIN_ASPECT_RATIO - self::ASPECT_TOLERANCE || $ratio > self::MAX_ASPECT_RATIO + self::ASPECT_TOLERANCE) {
            throw new InstagramMediaException(sprintf(
                'Instagram only accepts images with an aspect ratio between 4:5 (portrait) and 1.91:1 (landscape). This image is %dx%d (%.2f:1). Please crop it and try again.',
                $width,
                $height,
                $ratio
            ));
        }
    }

    /**
     * Path of $path relative to $root (forward slashes), or null when $path is not inside $root.
     */
    protected function relativeTo(string $path, string $root): ?string
    {
        $rootReal = realpath($root);
        if ($rootReal === false) {
            return null;
        }

        $normalize = fn (string $p) => rtrim(str_replace('\\', '/', $p), '/');
        $p = $normalize($path);
        $r = $normalize($rootReal) . '/';

        $matches = PHP_OS_FAMILY === 'Windows'
            ? str_starts_with(strtolower($p), strtolower($r))
            : str_starts_with($p, $r);

        return $matches ? substr($p, strlen($r)) : null;
    }
}
