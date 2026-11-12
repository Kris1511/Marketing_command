<?php

namespace Tests\Feature;

use App\Jobs\PublishScheduledPostJob;
use App\Models\FacebookPost;
use App\Models\FacebookPostMedia;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\Models\YouTubeConnection;
use App\Services\YouTubeService;
use App\Services\YouTubeThumbnailPublisher;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Publishing → YouTube custom thumbnail.
 *
 * The REAL YouTubeService runs against a fake Google transport (Guzzle handler), so the tests check the actual
 * YouTube Data API calls: videos.insert, videos.list (processing), thumbnails.set with the exact uploaded bytes.
 * Nothing leaves the machine and nothing is published.
 */
class YouTubeCustomThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $ws;
    private array $history = [];
    /** @var list<string> processingStatus returned by successive videos.list calls (last one repeats) */
    private array $processing = ['succeeded'];
    /** @var \Closure(RequestInterface): Response|null */
    private ?\Closure $thumbnailsSet = null;
    private int $videoInserts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['services.youtube.thumbnail_wait_seconds' => 30, 'services.youtube.thumbnail_job_wait_seconds' => 30]);
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->ws = Workspace::create(['name' => 'Thumb Client']);
        YouTubeConnection::create([
            'user_id' => $this->user->id, 'workspace_id' => $this->ws->id, 'channel_id' => 'UC_thumbs',
            'channel_name' => 'Client Channel', 'access_token' => 'ya29.test', 'token_expires_at' => now()->addHour(),
        ]);
        Integration::create(['workspace_id' => $this->ws->id, 'platform' => 'youtube', 'is_connected' => true, 'account_id' => 'UC_thumbs', 'account_name' => 'Client Channel']);
        $this->fakeGoogle();
        $this->app->bind(YouTubeThumbnailPublisher::class, function ($app) {
            $publisher = new YouTubeThumbnailPublisher($app->make(YouTubeService::class));
            $publisher->sleeper = function (int $seconds): void {}; // no real waiting in tests
            return $publisher;
        });
    }

    // ── fake YouTube Data API ──────────────────────────────────────────────

    private function fakeGoogle(): void
    {
        $handler = function (RequestInterface $req) {
            $uri = (string) $req->getUri();
            $path = $req->getUri()->getPath();
            if ($req->getMethod() === 'POST' && str_contains($path, '/upload/youtube/v3/videos')) {
                $this->videoInserts++;
                return Create::promiseFor(new Response(200, ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=session' . $this->videoInserts]));
            }
            if ($req->getMethod() === 'PUT' && str_contains($uri, 'upload_id=session')) {
                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode(['kind' => 'youtube#video', 'id' => 'yt_vid_' . $this->videoInserts])));
            }
            if ($req->getMethod() === 'GET' && str_ends_with($path, '/youtube/v3/videos')) {
                $state = count($this->processing) > 1 ? array_shift($this->processing) : $this->processing[0];
                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode(['items' => [[
                    'id' => 'yt_vid_1',
                    'processingDetails' => ['processingStatus' => $state],
                    'status' => ['uploadStatus' => $state === 'succeeded' ? 'processed' : 'uploaded'],
                ]]])));
            }
            if ($req->getMethod() === 'POST' && str_contains($path, '/youtube/v3/thumbnails/set')) {
                if ($this->thumbnailsSet) {
                    return Create::promiseFor(($this->thumbnailsSet)($req));
                }
                parse_str($req->getUri()->getQuery(), $q);
                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'kind' => 'youtube#thumbnailSetResponse',
                    'items' => [[
                        'default' => ['url' => "https://i.ytimg.com/vi/{$q['videoId']}/default.jpg", 'width' => 120, 'height' => 90],
                        'high'    => ['url' => "https://i.ytimg.com/vi/{$q['videoId']}/hqdefault.jpg", 'width' => 480, 'height' => 360],
                    ]],
                ])));
            }
            return Create::promiseFor(new Response(404, [], 'unexpected ' . $req->getMethod() . ' ' . $uri));
        };
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));

        $service = new YouTubeService();
        (new \ReflectionProperty(YouTubeService::class, 'client'))->getValue($service)->setHttpClient(new \GuzzleHttp\Client(['handler' => $stack]));
        $this->app->instance(YouTubeService::class, $service);
    }

    /** @return list<RequestInterface> */
    private function requests(string $method, string $pathPart): array
    {
        return array_values(array_map(fn ($e) => $e['request'], array_filter(
            $this->history,
            fn ($e) => $e['request']->getMethod() === $method && str_contains($e['request']->getUri()->getPath(), $pathPart)
        )));
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function video(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 2048));
    }

    /** A real JPEG; $noise makes it large (incompressible) for the 2 MB limit test. */
    private function jpeg(int $w = 1280, int $h = 720, bool $noise = false, string $name = 'thumb.jpg'): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 40, 40));
        if ($noise) {
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    imagesetpixel($img, $x, $y, mt_rand(0, 0xFFFFFF));
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'thumb') . '.jpg';
        imagejpeg($img, $path, 100);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function publish(array $data)
    {
        return $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', array_merge([
            'workspace_id' => $this->ws->id, 'message' => 'Video description', 'video_title' => 'Video title',
            'platforms' => ['YouTube'], 'post_type' => 'video', 'youtube_privacy' => 'private',
        ], $data), ['Accept' => 'application/json']);
    }

    private function assertExactBytesSent(RequestInterface $req, string $expectedSha, string $videoId, string $mime = 'image/jpeg'): void
    {
        parse_str($req->getUri()->getQuery(), $q);
        $this->assertSame($videoId, $q['videoId']);
        $this->assertSame('media', $q['uploadType']);
        $this->assertSame($mime, $req->getHeaderLine('Content-Type'));
        $this->assertSame($expectedSha, hash('sha256', (string) $req->getBody()), 'thumbnails.set must receive the exact uploaded file');
    }

    // ── immediate publishing ───────────────────────────────────────────────

    public function test_thumbnail_is_set_with_the_exact_file_after_processing_completes(): void
    {
        $this->processing = ['processing', 'processing', 'succeeded'];
        $thumb = $this->jpeg();
        $sha = hash_file('sha256', $thumb->getRealPath());

        $res = $this->publish(['status' => 'published', 'video' => $this->video(), 'thumbnail' => $thumb])->assertOk();

        // Order: video upload → processing checks → thumbnails.set
        $calls = array_map(fn ($e) => $e['request']->getMethod() . ' ' . $e['request']->getUri()->getPath(), $this->history);
        $this->assertSame([
            'POST /upload/youtube/v3/videos', 'PUT /upload/youtube/v3/videos',
            'GET /youtube/v3/videos', 'GET /youtube/v3/videos', 'GET /youtube/v3/videos',
            'POST /upload/youtube/v3/thumbnails/set',
        ], $calls);
        $this->assertExactBytesSent($this->requests('POST', 'thumbnails/set')[0], $sha, 'yt_vid_1');
        // The video upload itself carries no thumbnail
        $this->assertStringNotContainsString('thumbnail', (string) $this->requests('POST', '/upload/youtube/v3/videos')[0]->getBody());

        $t = $res->json('platform_statuses.youtube.thumbnail');
        $this->assertTrue($t['applied']);
        $this->assertSame('applied', $t['status']);
        $this->assertSame('succeeded', $t['processing_status']);
        $this->assertSame($sha, $t['sha256']);
        $this->assertSame('https://i.ytimg.com/vi/yt_vid_1/hqdefault.jpg', $t['api_response']['thumbnails']['high']);
        $this->assertSame('published', $res->json('platform_statuses.youtube.status'));
        $this->assertSame('UC_thumbs', $res->json('platform_statuses.youtube.channel_id'), 'owner recorded for the Overview deletion check');
    }

    public function test_png_thumbnail_is_sent_as_png(): void
    {
        $img = imagecreatetruecolor(1280, 720);
        $path = tempnam(sys_get_temp_dir(), 'thumb') . '.png';
        imagepng($img, $path);
        $png = new UploadedFile($path, 'cover.png', 'image/png', null, true);

        $this->publish(['status' => 'published', 'video' => $this->video(), 'thumbnail' => $png])->assertOk()
            ->assertJsonPath('platform_statuses.youtube.thumbnail.applied', true);
        $this->assertExactBytesSent($this->requests('POST', 'thumbnails/set')[0], hash_file('sha256', $path), 'yt_vid_1', 'image/png');
    }

    public function test_shorts_vertical_thumbnail_uses_the_same_flow(): void
    {
        $thumb = $this->jpeg(1080, 1920, false, 'short-cover.jpg');
        $sha = hash_file('sha256', $thumb->getRealPath());

        $this->publish(['status' => 'published', 'video' => $this->video(), 'thumbnail' => $thumb, 'video_title' => 'My Short #Shorts'])
            ->assertOk()->assertJsonPath('platform_statuses.youtube.thumbnail.applied', true);
        $this->assertExactBytesSent($this->requests('POST', 'thumbnails/set')[0], $sha, 'yt_vid_1');
    }

    public function test_api_error_is_reported_and_never_marked_applied(): void
    {
        $this->thumbnailsSet = fn () => new Response(403, ['Content-Type' => 'application/json'], json_encode(['error' => [
            'code' => 403, 'message' => "The authenticated user doesn't have permissions to upload and set custom video thumbnails.",
            'errors' => [['reason' => 'forbidden', 'domain' => 'youtube.thumbnail', 'message' => "The authenticated user doesn't have permissions to upload and set custom video thumbnails."]],
        ]]));

        $res = $this->publish(['status' => 'published', 'video' => $this->video(), 'thumbnail' => $this->jpeg()])->assertOk();

        $this->assertSame('published', $res->json('platform_statuses.youtube.status'));
        $this->assertSame('yt_vid_1', FacebookPost::firstOrFail()->yt_video_id);
        $t = $res->json('platform_statuses.youtube.thumbnail');
        $this->assertFalse($t['applied']);
        $this->assertSame(['failed', 403, 'forbidden'], [$t['status'], $t['error']['code'], $t['error']['reason']]);
        $this->assertStringContainsString("doesn't have permissions", $t['message']);
    }

    public function test_empty_api_response_is_not_treated_as_success(): void
    {
        $this->thumbnailsSet = fn () => new Response(200, ['Content-Type' => 'application/json'], json_encode(['kind' => 'youtube#thumbnailSetResponse', 'items' => []]));

        $t = $this->publish(['status' => 'published', 'video' => $this->video(), 'thumbnail' => $this->jpeg()])->assertOk()
            ->json('platform_statuses.youtube.thumbnail');

        $this->assertFalse($t['applied']);
        $this->assertSame('failed', $t['status']);
        $this->assertStringContainsString('did not confirm', $t['message']);
    }

    public function test_thumbnail_over_2_mb_is_refused_before_anything_is_uploaded(): void
    {
        $big = $this->jpeg(1600, 1200, true, 'huge.jpg');
        $this->assertGreaterThan(2 * 1024 * 1024, $big->getSize());

        $this->publish(['status' => 'published', 'video' => $this->video(), 'thumbnail' => $big])
            ->assertStatus(422)
            ->assertJsonPath('errors.thumbnail.0', fn ($m) => str_contains($m, 'up to 2 MB'));

        $this->assertSame([], $this->history, 'nothing may be sent to YouTube');
        $this->assertSame(0, FacebookPost::count());
        $this->assertSame([], Storage::disk('public')->allFiles("posts/{$this->ws->id}/thumbnails"));
    }

    // ── pending, retry, catch-up ───────────────────────────────────────────

    public function test_still_processing_is_pending_then_retry_applies_without_reuploading_the_video(): void
    {
        config(['services.youtube.thumbnail_wait_seconds' => 0]);
        $this->processing = ['processing'];
        $thumb = $this->jpeg();
        $sha = hash_file('sha256', $thumb->getRealPath());

        $res = $this->publish(['status' => 'published', 'video' => $this->video(), 'thumbnail' => $thumb])->assertOk();
        $this->assertSame('pending', $res->json('platform_statuses.youtube.thumbnail.status'));
        $this->assertFalse($res->json('platform_statuses.youtube.thumbnail.applied'));
        $this->assertCount(0, $this->requests('POST', 'thumbnails/set'));

        $post = FacebookPost::firstOrFail();
        $before = $post->getAttributes();
        $this->processing = ['succeeded'];

        $retry = $this->actingAs($this->user)->postJson("/api/v1/posts/{$post->id}/youtube-thumbnail/retry")->assertOk();

        $this->assertTrue($retry->json('thumbnail.applied'));
        $this->assertSame(2, $retry->json('thumbnail.attempts'));
        $this->assertSame(1, $this->videoInserts, 'the video must not be uploaded again');
        $this->assertExactBytesSent($this->requests('POST', 'thumbnails/set')[0], $sha, 'yt_vid_1');
        $post->refresh();
        $after = $post->getAttributes();
        foreach (['status', 'yt_video_id', 'content', 'published_at', 'scheduled_at', 'youtube_privacy'] as $col) {
            $this->assertSame($before[$col], $after[$col], "{$col} must not change");
        }
        $this->assertTrue($post->platform_statuses['youtube']['thumbnail']['applied']);
        $this->assertSame('published', $post->platform_statuses['youtube']['status']);
    }

    public function test_retry_after_failure_and_its_guards(): void
    {
        $this->thumbnailsSet = fn () => new Response(429, ['Content-Type' => 'application/json'], json_encode(['error' => ['code' => 429, 'message' => 'Too many thumbnail uploads.', 'errors' => [['reason' => 'uploadRateLimitExceeded', 'message' => 'Too many thumbnail uploads.']]]]));
        $this->publish(['status' => 'published', 'video' => $this->video(), 'thumbnail' => $this->jpeg()])->assertOk();
        $post = FacebookPost::firstOrFail();
        $this->assertSame('failed', $post->platform_statuses['youtube']['thumbnail']['status']);

        // Still failing: 422 with YouTube's message, status stays failed
        $this->actingAs($this->user)->postJson("/api/v1/posts/{$post->id}/youtube-thumbnail/retry")
            ->assertStatus(422)->assertJsonPath('thumbnail.error.reason', 'uploadRateLimitExceeded');

        // YouTube accepts it now
        $this->thumbnailsSet = null;
        $this->actingAs($this->user)->postJson("/api/v1/posts/{$post->id}/youtube-thumbnail/retry")
            ->assertOk()->assertJsonPath('thumbnail.applied', true)->assertJsonPath('thumbnail.attempts', 3);
        $this->assertSame(1, $this->videoInserts);

        // Already applied → nothing to retry
        $this->actingAs($this->user)->postJson("/api/v1/posts/{$post->id}/youtube-thumbnail/retry")->assertStatus(422);

        // A post without a YouTube video cannot be retried
        $other = FacebookPost::create(['workspace_id' => $this->ws->id, 'post_type' => 'video', 'content' => 'x', 'status' => 'draft', 'platforms' => ['YouTube']]);
        $this->actingAs($this->user)->postJson("/api/v1/posts/{$other->id}/youtube-thumbnail/retry")->assertStatus(422);
        // Other workspaces' posts are not reachable
        $viewer = User::factory()->create(['role' => 'manager']);
        $this->actingAs($viewer)->postJson("/api/v1/posts/{$post->id}/youtube-thumbnail/retry")->assertStatus(403);
    }

    public function test_scheduled_catch_up_command_applies_pending_thumbnails(): void
    {
        config(['services.youtube.thumbnail_wait_seconds' => 0]);
        $this->processing = ['processing'];
        $this->publish(['status' => 'published', 'video' => $this->video(), 'thumbnail' => $this->jpeg()])->assertOk();
        $post = FacebookPost::firstOrFail();

        $this->artisan('posts:apply-youtube-thumbnails')->assertSuccessful(); // still processing
        $this->assertSame('pending', $post->fresh()->platform_statuses['youtube']['thumbnail']['status']);

        $this->processing = ['succeeded'];
        $this->artisan('posts:apply-youtube-thumbnails')->assertSuccessful();
        $this->assertTrue($post->fresh()->platform_statuses['youtube']['thumbnail']['applied']);
        $this->assertSame(1, $this->videoInserts);
    }

    // ── scheduled publishing ───────────────────────────────────────────────

    public function test_scheduled_publishing_sets_the_saved_thumbnail(): void
    {
        $thumb = $this->jpeg();
        $sha = hash_file('sha256', $thumb->getRealPath());
        Storage::disk('public')->putFileAs("posts/{$this->ws->id}/thumbnails", $thumb, 'saved.jpg');
        Storage::disk('public')->put("posts/{$this->ws->id}/v.mp4", "\x00\x00\x00\x18ftypmp42" . str_repeat("\x00", 2048));
        $post = FacebookPost::create([
            'workspace_id' => $this->ws->id, 'post_type' => 'video', 'content' => 'Scheduled video', 'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(), 'platforms' => ['YouTube'], 'youtube_privacy' => 'public', 'video_title' => 'Scheduled',
            'thumbnail_path' => "posts/{$this->ws->id}/thumbnails/saved.jpg",
        ]);
        FacebookPostMedia::create(['facebook_post_id' => $post->id, 'media_type' => 'video', 'sort_order' => 0,
            'file_path' => Storage::disk('public')->path("posts/{$this->ws->id}/v.mp4"), 'file_url' => 'https://x/v.mp4']);
        $this->processing = ['processing', 'succeeded'];

        (new PublishScheduledPostJob($post->id))->handle();

        $yt = $post->fresh()->platform_statuses['youtube'];
        $this->assertSame('published', $yt['status']);
        $this->assertTrue($yt['thumbnail']['applied']);
        $this->assertExactBytesSent($this->requests('POST', 'thumbnails/set')[0], $sha, 'yt_vid_1');
    }
}
