<?php

namespace Tests\Unit;

use App\Exceptions\InstagramMediaException;
use App\Services\PublicMediaUrlService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesPublicMediaHost;
use Tests\TestCase;

/**
 * SAFETY: Storage::fake + Http::fake with preventStrayRequests: no network, no MySQL, no Meta calls.
 */
class PublicMediaUrlServiceTest extends TestCase
{
    use FakesPublicMediaHost;

    private PublicMediaUrlService $media;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->media = app(PublicMediaUrlService::class);
    }

    private function storeJpeg(string $relative, int $w = 1080, int $h = 1080): string
    {
        $file = UploadedFile::fake()->image('x.jpg', $w, $h);
        Storage::disk('public')->put($relative, file_get_contents($file->getRealPath()));

        return Storage::disk('public')->path($relative);
    }

    public static function localOrPrivateUrls(): array
    {
        return [
            'localhost'          => ['https://localhost:8000/storage/posts/1/a.jpg'],
            'loopback ipv4'      => ['https://127.0.0.1/storage/posts/1/a.jpg'],
            'loopback ipv6'      => ['https://[::1]/storage/posts/1/a.jpg'],
            'private 10/8'       => ['https://10.0.0.5/a.jpg'],
            'private 192.168/16' => ['https://192.168.1.20/a.jpg'],
            'private 172.16/12'  => ['https://172.20.0.3/a.jpg'],
            'link-local'         => ['https://169.254.10.10/a.jpg'],
            'all interfaces'     => ['https://0.0.0.0/a.jpg'],
            '*.localhost'        => ['https://app.localhost/a.jpg'],
            '*.local (mDNS)'     => ['https://my-pc.local/a.jpg'],
            'intranet name'      => ['https://fileserver/a.jpg'],
        ];
    }

    #[DataProvider('localOrPrivateUrls')]
    public function test_local_and_private_hosts_are_rejected_without_any_request(string $url): void
    {
        try {
            $this->media->assertUsable($url);
            $this->fail("Expected {$url} to be rejected");
        } catch (InstagramMediaException $e) {
            $this->assertStringContainsString('Instagram cannot fetch local URLs', $e->getMessage());
            $this->assertStringContainsString('PUBLIC_MEDIA_URL', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_non_https_url_is_rejected_with_meaningful_message(): void
    {
        $this->expectException(InstagramMediaException::class);
        $this->expectExceptionMessage("Instagram requires a publicly accessible HTTPS image URL. Provided URL is not HTTPS: 'http://localhost:8000/storage/posts/6/a.jpeg'");

        $this->media->assertUsable('http://localhost:8000/storage/posts/6/a.jpeg');
    }

    public function test_invalid_url_and_credentials_in_url_are_rejected(): void
    {
        foreach (['not a url', 'https://', 'ftp//x'] as $bad) {
            try {
                $this->media->assertPublicHttpsUrl($bad);
                $this->fail("Expected '{$bad}' to be rejected");
            } catch (InstagramMediaException $e) {
                $this->assertStringContainsString('Invalid image URL', $e->getMessage());
            }
        }

        $this->expectExceptionMessage('must not contain credentials');
        $this->media->assertPublicHttpsUrl('https://user:secret@cdn.example.com/a.jpg');
    }

    public function test_storage_url_is_built_from_config_and_path_segments_are_encoded(): void
    {
        $this->usePublicMediaHost('https://media.example.test/', false);

        $this->assertSame(
            'https://media.example.test/storage/posts/1/my%20photo%20%231.jpg',
            $this->media->storageUrl('posts/1/my photo #1.jpg')
        );
    }

    public function test_url_ignores_the_incoming_request_host(): void
    {
        // Reproduces the bug: during an API request asset() used http://localhost:8000.
        URL::forceRootUrl('http://localhost:8000');
        $this->usePublicMediaHost();
        $path = $this->storeJpeg('posts/6/0439d274-a339-423c-bc38-ae8315ddadec.jpeg');

        $url = $this->media->resolveForInstagram($path, 6);

        $this->assertSame('https://media.example.test/storage/posts/6/0439d274-a339-423c-bc38-ae8315ddadec.jpeg', $url);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === $url);
    }

    public function test_app_url_is_used_when_public_media_url_is_empty(): void
    {
        $this->usePublicMediaHost('https://api.example.test');
        config(['services.public_media.url' => null, 'app.url' => 'https://api.example.test']);
        $path = $this->storeJpeg('posts/2/a.jpg');

        $this->assertSame('https://api.example.test/storage/posts/2/a.jpg', $this->media->resolveForInstagram($path, 2));
    }

    public function test_localhost_base_without_fallback_gives_configuration_error(): void
    {
        $this->usePublicMediaHost('http://localhost:8000', false);
        $path = $this->storeJpeg('posts/1/a.jpg');

        $this->expectException(InstagramMediaException::class);
        $this->expectExceptionMessage("Provided URL is not HTTPS: 'http://localhost:8000/storage/posts/1/a.jpg'");

        $this->media->resolveForInstagram($path, 1);
    }

    public function test_falls_back_to_cloudinary_when_storage_url_is_unusable(): void
    {
        $this->usePublicMediaHost('http://localhost:8000', false);
        config([
            'services.cloudinary.cloud_name' => 'demo',
            'services.cloudinary.api_key'    => 'key',
            'services.cloudinary.api_secret' => 'secret',
        ]);
        $path = $this->storeJpeg('posts/1/a.jpg');
        $jpeg = file_get_contents($path);
        Http::fake([
            'api.cloudinary.com/*' => Http::response(['secure_url' => 'https://res.cloudinary.com/demo/image/upload/v1/a.jpg'], 200),
            'res.cloudinary.com/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->assertSame('https://res.cloudinary.com/demo/image/upload/v1/a.jpg', $this->media->resolveForInstagram($path, 1));
    }

    public function test_storage_driver_never_falls_back_to_cloudinary(): void
    {
        $this->usePublicMediaHost('http://localhost:8000', false);
        config([
            'services.public_media.driver'   => 'storage',
            'services.cloudinary.cloud_name' => 'demo',
            'services.cloudinary.api_key'    => 'key',
            'services.cloudinary.api_secret' => 'secret',
        ]);
        $path = $this->storeJpeg('posts/1/a.jpg');

        try {
            $this->media->resolveForInstagram($path, 1);
            $this->fail('Expected an exception');
        } catch (InstagramMediaException $e) {
            $this->assertStringContainsString('not HTTPS', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_inaccessible_url_reports_http_status(): void
    {
        $this->usePublicMediaHost();
        // The file is NOT on the disk the media host serves -> 404, like APP_URL pointing at another server.
        Http::fake(['cdn.example.test/*' => Http::response('nope', 404, ['Content-Type' => 'text/html'])]);

        $this->expectException(InstagramMediaException::class);
        $this->expectExceptionMessage('returned HTTP 404');

        $this->media->resolveForInstagram('https://cdn.example.test/storage/posts/9/missing.jpg', 9);
    }

    public function test_html_instead_of_image_is_rejected(): void
    {
        $this->usePublicMediaHost();
        Http::fake(['cdn.example.test/*' => Http::response('<html>ngrok warning</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->expectException(InstagramMediaException::class);
        $this->expectExceptionMessage("is served as 'text/html'");

        $this->media->resolveForInstagram('https://cdn.example.test/a.jpg', 1);
    }

    public function test_unreachable_host_is_reported(): void
    {
        $this->usePublicMediaHost();
        Http::fake(['down.example.test/*' => Http::failedConnection('cURL error 6: Could not resolve host')]);

        $this->expectException(InstagramMediaException::class);
        $this->expectExceptionMessage('could not be reached from this server');

        $this->media->resolveForInstagram('https://down.example.test/a.jpg', 1);
    }

    public function test_png_is_converted_to_jpeg_and_served_as_image_jpeg(): void
    {
        $this->usePublicMediaHost();
        $png = UploadedFile::fake()->image('logo.png', 800, 800);
        Storage::disk('public')->put('posts/3/logo.png', file_get_contents($png->getRealPath()));
        $this->assertSame('image/png', mime_content_type(Storage::disk('public')->path('posts/3/logo.png')));

        $url = $this->media->resolveForInstagram(Storage::disk('public')->path('posts/3/logo.png'), 3);

        $this->assertSame('https://media.example.test/storage/posts/3/logo.ig.jpg', $url);
        $this->assertSame('image/jpeg', mime_content_type(Storage::disk('public')->path('posts/3/logo.ig.jpg')));
    }

    public function test_unsupported_aspect_ratio_is_rejected_before_any_request(): void
    {
        $this->usePublicMediaHost();
        $path = $this->storeJpeg('posts/1/banner.jpg', 1500, 400);

        try {
            $this->media->resolveForInstagram($path, 1);
            $this->fail('Expected an exception');
        } catch (InstagramMediaException $e) {
            $this->assertStringContainsString('aspect ratio between 4:5', $e->getMessage());
            $this->assertStringContainsString('1500x400', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_non_image_file_is_rejected(): void
    {
        $this->usePublicMediaHost();
        Storage::disk('public')->put('posts/1/fake.jpg', 'definitely not an image');

        $this->expectException(InstagramMediaException::class);
        $this->expectExceptionMessage('Unsupported image type');

        $this->media->resolveForInstagram(Storage::disk('public')->path('posts/1/fake.jpg'), 1);
    }

    public function test_missing_local_file_is_reported_clearly(): void
    {
        $this->usePublicMediaHost();

        $this->expectException(InstagramMediaException::class);
        $this->expectExceptionMessage("the media file 'gone.jpg' is missing from server storage");

        $this->media->resolveForInstagram('C:\\nowhere\\storage\\app/public\\posts/1/gone.jpg', 1);
    }

    public function test_stale_stored_url_is_reissued_with_current_public_base(): void
    {
        $this->usePublicMediaHost();
        $this->storeJpeg('posts/6/abc.jpeg');

        $url = $this->media->resolveForInstagram('http://localhost:8000/storage/posts/6/abc.jpeg', 6);

        $this->assertSame('https://media.example.test/storage/posts/6/abc.jpeg', $url);
    }

    public function test_files_outside_application_storage_are_never_published(): void
    {
        $this->usePublicMediaHost();
        $outside = tempnam(sys_get_temp_dir(), 'ig');
        file_put_contents($outside, $this->jpegBytes(100, 100));

        try {
            $this->media->resolveForInstagram($outside, 1);
            $this->fail('Expected an exception');
        } catch (InstagramMediaException $e) {
            $this->assertStringContainsString('outside application storage', $e->getMessage());
        } finally {
            @unlink($outside);
        }
    }

    public function test_verification_can_be_disabled_but_https_and_public_host_rules_still_apply(): void
    {
        $this->usePublicMediaHost(null, false);
        config(['services.public_media.verify' => false]);
        $path = $this->storeJpeg('posts/1/a.jpg');

        $this->assertSame('https://media.example.test/storage/posts/1/a.jpg', $this->media->resolveForInstagram($path, 1));
        Http::assertNothingSent();

        $this->expectExceptionMessage('Instagram cannot fetch local URLs');
        $this->media->resolveForInstagram('https://127.0.0.1/a.jpg', 1);
    }
}
