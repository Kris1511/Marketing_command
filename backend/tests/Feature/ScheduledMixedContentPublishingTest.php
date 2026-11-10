<?php

namespace Tests\Feature;

use App\Console\Commands\ProcessScheduledPosts;
use App\Jobs\PublishScheduledPostJob;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\Models\YouTubeConnection;
use App\Services\YouTubeService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesPublicMediaHost;
use Tests\TestCase;

/**
 * Scheduling -> automatic publishing, end to end, for every content type the app publishes.
 *
 * Posts are scheduled through the real API, time is moved forward, and the real scheduler command runs the real
 * PublishScheduledPostJob, FacebookGraphService and PublicMediaUrlService against a fake Meta Graph API that records
 * WHICH FILE reached WHICH endpoint. YouTubeService is mocked (Google SDK).
 *
 * SAFETY: in-memory SQLite, Storage::fake, Http::fake + preventStrayRequests. Nothing reaches Meta or YouTube.
 */
class ScheduledMixedContentPublishingTest extends TestCase
{
    use RefreshDatabase;
    use FakesPublicMediaHost;

    private const PAGE = '555000111';
    private const IG = '17841400000000077';

    private User $user;
    private Workspace $ws;

    /** Semantic log of what the fake Meta API received: [type, detail] */
    private array $meta = [];
    private int $seq = 0;
    /** Fake behaviour switches */
    private array $failFacebookFiles = [];
    private bool $facebookTokenExpired = false;
    private int $igRejectContainers = 0;
    private bool $igInProgress = false;
    /** YouTube uploads received by the mock: [videoPath, thumbnailPath] */
    private array $youtubeUploads = [];
    private ?\Closure $onYouTubeUpload = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Kolkata'));

        $this->user = User::factory()->create(['role' => 'admin']);
        $this->ws = Workspace::create(['name' => 'Scheduling Client']);

        FacebookPage::create(['workspace_id' => $this->ws->id, 'page_id' => self::PAGE, 'page_name' => 'Client Page', 'page_access_token' => 'EAA_page_token']);
        Integration::create(['workspace_id' => $this->ws->id, 'platform' => 'instagram', 'is_connected' => true,
            'account_id' => self::IG, 'account_name' => '@client', 'refresh_token' => 'IGAA_ig_token']);
        YouTubeConnection::create(['user_id' => $this->user->id, 'workspace_id' => $this->ws->id, 'channel_id' => 'UC_sched',
            'channel_name' => 'Client Channel', 'access_token' => 'ya29.x', 'token_expires_at' => now()->addYear()]);
        Integration::create(['workspace_id' => $this->ws->id, 'platform' => 'youtube', 'is_connected' => true, 'account_id' => 'UC_sched', 'account_name' => 'Client Channel']);

        $this->usePublicMediaHost();
        $this->fakeMeta();
        $this->mockYouTube();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fakes ──────────────────────────────────────────────────────────────

    private function fakeMeta(): void
    {
        $expired = fn () => Http::response(['error' => ['message' => 'Error validating access token: Session has expired', 'type' => 'OAuthException', 'code' => 190]], 400);

        // Instagram Graph endpoints (graph.instagram.com for IG tokens, graph.facebook.com for page tokens)
        $instagram = function ($request) {
                $path = trim((string) parse_url($request->url(), PHP_URL_PATH), '/');
                [, $node, $edge] = array_pad(explode('/', $path), 3, null);
                if ($request->method() === 'POST' && $edge === 'media') {
                    if ($this->igRejectContainers > 0) {
                        $this->igRejectContainers--;
                        return Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400);
                    }
                    $this->meta[] = ['ig_container', $request->data()];
                    return Http::response(['id' => 'igc_' . (++$this->seq)], 200);
                }
                if ($request->method() === 'POST' && $edge === 'media_publish') {
                    $this->meta[] = ['ig_publish', $request['creation_id']];
                    return Http::response(['id' => 'igmedia_' . (++$this->seq)], 200);
                }
                return Http::response(['status_code' => $this->igInProgress ? 'IN_PROGRESS' : 'FINISHED'], 200);
            };

        Http::fake([
            'graph-video.facebook.com/*' => function ($request) use ($expired) {
                if ($this->facebookTokenExpired) return $expired();
                $file = $this->multipartFilename($request, 'source');
                $this->meta[] = ['fb_video', $file];
                return Http::response(['id' => 'fbvideo_' . (++$this->seq)], 200);
            },
            'graph.facebook.com/*' => function ($request) use ($expired, $instagram) {
                $path = trim((string) parse_url($request->url(), PHP_URL_PATH), '/');
                [, $node, $edge] = array_pad(explode('/', $path), 3, null);
                if ($node === self::IG || str_starts_with((string) $node, 'igc_')) {
                    return $instagram($request);
                }
                if ($request->method() !== 'POST') {
                    return Http::response(['id' => $node, 'data' => []], 200); // lookups / metric syncs
                }
                if ($this->facebookTokenExpired) return $expired();
                if ($edge === 'photos') {
                    $file = $this->multipartFilename($request, 'source');
                    if (in_array($file, $this->failFacebookFiles, true)) {
                        return Http::response(['error' => ['message' => '(#200) The permission(s) pages_manage_posts are not available.', 'type' => 'OAuthException', 'code' => 200]], 403);
                    }
                    $this->meta[] = [$this->multipartValue($request, 'published') === 'false' ? 'fb_carousel_photo' : 'fb_photo', $file];
                    return Http::response(['id' => 'fbphoto_' . (++$this->seq), 'post_id' => self::PAGE . '_' . $this->seq], 200);
                }
                if ($edge === 'feed') {
                    $this->meta[] = ['fb_feed', count($request['attached_media'] ?? [])];
                    return Http::response(['id' => self::PAGE . '_feed' . (++$this->seq)], 200);
                }
                if ($edge === 'thumbnails') {
                    $this->meta[] = ['fb_thumbnail', $node];
                    return Http::response(['success' => true], 200);
                }
                return Http::response(['id' => 'x' . (++$this->seq)], 200);
            },
            'graph.instagram.com/*' => $instagram,
        ]);
        config(['services.instagram.container_poll_seconds' => 0]);
    }

    private function mockYouTube(): void
    {
        $yt = $this->createMock(YouTubeService::class);
        $yt->method('uploadVideo')->willReturnCallback(function ($conn, $videoPath, $title, $desc, $privacy, $tags = null, $cat = null, $thumb = null) {
            if ($this->onYouTubeUpload) ($this->onYouTubeUpload)();
            $this->youtubeUploads[] = [basename($videoPath), $thumb ? basename($thumb) : null];
            return ['id' => 'yt_' . (++$this->seq), 'url' => 'https://youtu.be/x', 'thumbnail_uploaded' => (bool) $thumb];
        });
        $this->app->instance(YouTubeService::class, $yt);
    }

    private function multipartFilename($request, string $name): ?string
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) return $part['filename'] ?? null;
        }
        return null;
    }

    private function multipartValue($request, string $name): ?string
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) return (string) $part['contents'];
        }
        return null;
    }

    private function events(string $type): array
    {
        return array_values(array_map(fn ($e) => $e[1], array_filter($this->meta, fn ($e) => $e[0] === $type)));
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function jpg(string $name = 'img.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 1080, 1080);
    }

    private function video(string $name = 'clip.mp4'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 4096) . $name);
    }

    /** Schedule a post through the real API at an IST wall-clock time ("H:i" today). */
    private function schedule(string $timeIst, array $data)
    {
        return $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', array_merge([
            'workspace_id' => $this->ws->id,
            'status'       => 'scheduled',
            'scheduled_at' => "2026-11-10 {$timeIst}:00",
            'message'      => 'Caption',
        ], $data));
    }

    private function at(string $timeIst): void
    {
        Carbon::setTestNow(Carbon::parse("2026-11-10 {$timeIst}:00", 'Asia/Kolkata'));
    }

    private function runScheduler(): void
    {
        $this->artisan('posts:process-scheduled')->assertExitCode(0);
    }

    private function mediaNames(int $postId): array
    {
        return FacebookPost::find($postId)->media->map(fn ($m) => basename($m->file_path))->all();
    }

    // ── the main scenario ──────────────────────────────────────────────────

    public function test_four_mixed_posts_are_scheduled_and_each_is_published_automatically_at_its_own_time(): void
    {
        $p1 = $this->schedule('10:10', ['message' => "Post 1 image\n\n#one #image", 'hashtags' => '#one #image', 'platforms' => ['Facebook', 'Instagram'],
            'post_type' => 'single_image', 'images' => [$this->jpg('p1.jpg')]])->assertCreated()->json('data.id');
        $p2 = $this->schedule('10:20', ['message' => 'Post 2 video', 'platforms' => ['Facebook', 'YouTube'], 'post_type' => 'video',
            'video' => $this->video('p2.mp4'), 'thumbnail' => UploadedFile::fake()->image('p2thumb.jpg', 1280, 720), 'video_title' => 'P2'])->assertCreated()->json('data.id');
        $p3 = $this->schedule('10:30', ['message' => 'Post 3 carousel', 'platforms' => ['Facebook', 'Instagram'], 'post_type' => 'multi_image',
            'images' => [$this->jpg('p3a.jpg'), $this->jpg('p3b.jpg'), $this->jpg('p3c.jpg')]])->assertCreated()->json('data.id');
        $p4 = $this->schedule('10:40', ['message' => 'Post 4 reel #reel', 'platforms' => ['Instagram'], 'post_type' => 'video',
            'video' => $this->video('p4.mp4'), 'thumbnail' => UploadedFile::fake()->image('p4cover.jpg', 1080, 1920)])->assertCreated()->json('data.id');

        // ── each post is its own scheduled record with its own time, media, caption, accounts, thumbnail
        $posts = FacebookPost::with('media')->orderBy('id')->get();
        $this->assertCount(4, $posts);
        $this->assertSame(['scheduled', 'scheduled', 'scheduled', 'scheduled'], $posts->pluck('status')->all());
        $this->assertSame(['04:40', '04:50', '05:00', '05:10'], $posts->map(fn ($p) => $p->scheduled_at->utc()->format('H:i'))->all());
        $this->assertSame([1, 1, 3, 1], $posts->map(fn ($p) => $p->media->count())->all());
        $this->assertSame(['image', 'video', 'image', 'video'], $posts->map(fn ($p) => $p->media->first()->media_type)->all());
        $this->assertSame([['Facebook', 'Instagram'], ['Facebook', 'YouTube'], ['Facebook', 'Instagram'], ['Instagram']], $posts->pluck('platforms')->all());
        $this->assertSame('#one #image', $posts[0]->hashtags);
        $this->assertStringContainsString('#one #image', $posts[0]->content);
        $this->assertNull($posts[0]->thumbnail_path);
        $this->assertNotNull($posts[1]->thumbnail_path);
        $this->assertNotNull($posts[3]->thumbnail_path);
        $allFiles = $posts->flatMap(fn ($p) => $p->media->pluck('file_path'))->all();
        $this->assertCount(6, array_unique($allFiles), 'every post keeps its own media files');
        foreach ($allFiles as $f) $this->assertFileExists($f);

        // ── a page refresh still lists all four
        $listed = $this->actingAs($this->user)->getJson("/api/v1/scheduled-posts?workspace_id={$this->ws->id}")->assertOk()->json('data');
        $this->assertSame([$p1, $p2, $p3, $p4], array_column($listed, 'id'));

        // ── before anything is due: nothing is published
        $this->at('10:05');
        $this->runScheduler();
        $this->assertSame([], $this->meta);

        // ── 10:12 IST: only Post 1 (image -> Facebook photo + Instagram image)
        $this->at('10:12');
        $this->runScheduler();
        $p1File = $this->mediaNames($p1)[0];
        $this->assertSame([$p1File], $this->events('fb_photo'));
        $igImage = $this->events('ig_container')[0];
        $this->assertStringEndsWith($p1File, $igImage['image_url']);
        $this->assertStringStartsWith('https://media.example.test/storage/', $igImage['image_url']);
        $this->assertSame('published', FacebookPost::find($p1)->status);
        $this->assertSame(['scheduled', 'scheduled', 'scheduled'], FacebookPost::whereIn('id', [$p2, $p3, $p4])->orderBy('id')->pluck('status')->all());

        // ── 10:25 IST: Post 2 (video -> Facebook video + custom thumbnail, YouTube upload + thumbnail)
        $this->at('10:25');
        $this->runScheduler();
        $p2Post = FacebookPost::find($p2);
        $this->assertSame([$this->mediaNames($p2)[0]], $this->events('fb_video'));
        $this->assertCount(1, $this->events('fb_thumbnail'));
        $this->assertSame([[$this->mediaNames($p2)[0], basename($p2Post->thumbnail_path)]], $this->youtubeUploads);
        $this->assertSame('published', $p2Post->status);
        $this->assertTrue($p2Post->platform_statuses['facebook']['thumbnail']['applied']);
        $this->assertTrue($p2Post->platform_statuses['youtube']['thumbnail']['applied']);

        // ── 10:35 IST: Post 3 (carousel -> 3 Facebook photos in order + feed; Instagram carousel in order)
        $this->at('10:35');
        $this->runScheduler();
        $p3Files = $this->mediaNames($p3);
        $this->assertSame($p3Files, $this->events('fb_carousel_photo'));
        $this->assertSame([3], $this->events('fb_feed'));
        $items = array_values(array_filter($this->events('ig_container'), fn ($c) => ($c['is_carousel_item'] ?? null) === 'true'));
        $this->assertSame($p3Files, array_map(fn ($c) => basename($c['image_url']), $items));
        $this->assertSame('CAROUSEL', $this->events('ig_container')[4]['media_type']);

        // ── 10:45 IST: Post 4 (Instagram Reel with cover)
        $this->at('10:45');
        $this->runScheduler();
        $reel = collect($this->events('ig_container'))->firstWhere('media_type', 'REELS');
        $this->assertStringEndsWith($this->mediaNames($p4)[0], $reel['video_url']);
        $this->assertStringEndsWith(basename(FacebookPost::find($p4)->thumbnail_path), $reel['cover_url']);

        // ── all four published with their platform IDs
        $final = FacebookPost::orderBy('id')->get();
        $this->assertSame(['published', 'published', 'published', 'published'], $final->pluck('status')->all());
        $this->assertNotNull($final[0]->fb_post_id);
        $this->assertNotNull($final[0]->ig_media_id);
        $this->assertNotNull($final[1]->fb_post_id);
        $this->assertNotNull($final[1]->yt_video_id);
        $this->assertNotNull($final[2]->fb_post_id);
        $this->assertNotNull($final[2]->ig_media_id);
        $this->assertNotNull($final[3]->ig_media_id);
        $this->assertNull($final[3]->fb_post_id);
        foreach ($final as $p) {
            $this->assertNotNull($p->published_at);
            $this->assertTrue($p->published_at->gte($p->scheduled_at), 'never published before its time');
        }
        $this->assertSame(4, \App\Models\FacebookPostHistory::where('action', 'published')->count());

        // ── running the scheduler again publishes nothing twice
        $before = count($this->meta);
        $this->at('11:30');
        $this->runScheduler();
        $this->assertCount($before, $this->meta);
        $this->assertCount(1, $this->youtubeUploads);
    }

    // ── four posts of the same type ────────────────────────────────────────

    public static function sameTypeScenarios(): array
    {
        return ['4 image posts' => ['single_image'], '4 video posts' => ['video'], '4 carousel posts' => ['multi_image']];
    }

    #[DataProvider('sameTypeScenarios')]
    public function test_four_posts_of_the_same_type_are_all_published_with_their_own_media(string $type): void
    {
        $ids = [];
        foreach (['10:10', '10:11', '10:12', '10:13'] as $i => $time) {
            $data = ['message' => "Post {$i}", 'platforms' => ['Facebook', 'Instagram'], 'post_type' => $type];
            if ($type === 'single_image') $data['images'] = [$this->jpg("s{$i}.jpg")];
            if ($type === 'multi_image') $data['images'] = [$this->jpg("c{$i}a.jpg"), $this->jpg("c{$i}b.jpg")];
            if ($type === 'video') $data['video'] = $this->video("v{$i}.mp4");
            $ids[] = $this->schedule($time, $data)->assertCreated()->json('data.id');
        }
        $this->assertSame(4, FacebookPost::where('status', 'scheduled')->count());

        $this->at('10:15');
        $this->runScheduler();

        $this->assertSame(4, FacebookPost::where('status', 'published')->whereNotNull('fb_post_id')->whereNotNull('ig_media_id')->count());
        $expected = collect($ids)->flatMap(fn ($id) => $this->mediaNames($id))->all();
        $fbFiles = match ($type) {
            'single_image' => $this->events('fb_photo'),
            'multi_image'  => $this->events('fb_carousel_photo'),
            'video'        => $this->events('fb_video'),
        };
        $this->assertSame($expected, $fbFiles, 'each post published exactly its own files, in order');
        $this->assertCount(4, $this->events('ig_publish'));
    }

    // ── failures, retries, recovery ────────────────────────────────────────

    public function test_one_failing_post_does_not_stop_the_others_and_is_not_marked_published(): void
    {
        $a = $this->schedule('10:10', ['platforms' => ['Facebook'], 'post_type' => 'single_image', 'images' => [$this->jpg('a.jpg')]])->json('data.id');
        $b = $this->schedule('10:10', ['platforms' => ['Facebook'], 'post_type' => 'single_image', 'images' => [$this->jpg('b.jpg')]])->json('data.id');
        $c = $this->schedule('10:10', ['platforms' => ['Facebook'], 'post_type' => 'text', 'message' => 'Text only post'])->json('data.id');
        $this->failFacebookFiles = [$this->mediaNames($b)[0]];

        $this->at('10:11');
        $this->runScheduler();

        $this->assertSame('published', FacebookPost::find($a)->status);
        $this->assertSame('published', FacebookPost::find($c)->status);
        $failed = FacebookPost::find($b);
        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->fb_post_id);
        $this->assertStringContainsString('pages_manage_posts', $failed->error_message);
        $this->assertSame('failed', $failed->platform_statuses['facebook']['status']);
    }

    public function test_retry_publishes_only_the_failed_platform(): void
    {
        $id = $this->schedule('10:10', ['platforms' => ['Facebook', 'Instagram'], 'post_type' => 'single_image', 'images' => [$this->jpg('r.jpg')]])->json('data.id');
        $this->igRejectContainers = 2; // Instagram rejects both the IG-token attempt and the page-token fallback

        $this->at('10:11');
        $this->runScheduler();
        $post = FacebookPost::find($id);
        $this->assertSame('published', $post->status); // Facebook succeeded
        $this->assertSame('failed', $post->platform_statuses['instagram']['status']);
        $this->assertCount(1, $this->events('fb_photo'));

        $res = $this->actingAs($this->user)->postJson("/api/v1/posts/{$id}/retry")->assertOk();
        $res->assertJsonPath('platform_statuses.instagram.status', 'published')
            ->assertJsonPath('platform_statuses.facebook.status', 'published');

        $this->assertCount(1, $this->events('fb_photo'), 'Facebook is not published a second time');
        $this->assertCount(1, $this->events('ig_publish'));
        $post->refresh();
        $this->assertNotNull($post->ig_media_id);
        $this->assertNull($post->error_message);

        // A fully published post cannot be retried
        $this->actingAs($this->user)->postJson("/api/v1/posts/{$id}/retry")->assertStatus(422);
    }

    public function test_rescheduled_post_is_not_published_early_by_its_old_queue_job(): void
    {
        $id = $this->schedule('10:10', ['platforms' => ['Facebook'], 'post_type' => 'single_image', 'images' => [$this->jpg('late.jpg')]])->json('data.id');
        // Rescheduled to 11:00 (the 10:10 delayed job still exists in the queue)
        $this->schedule('11:00', ['draft_id' => $id, 'platforms' => ['Facebook'], 'post_type' => 'single_image'])->assertOk();

        $this->at('10:10');
        (new PublishScheduledPostJob($id))->handle(); // the stale job fires
        $this->assertSame('scheduled', FacebookPost::find($id)->status);
        $this->assertSame([], $this->meta);

        $this->at('11:01');
        $this->runScheduler();
        $this->assertSame('published', FacebookPost::find($id)->status);
        $this->assertCount(1, $this->events('fb_photo'));
    }

    public function test_platform_ids_are_saved_as_each_platform_succeeds(): void
    {
        $id = $this->schedule('10:10', ['platforms' => ['Facebook', 'YouTube'], 'post_type' => 'video', 'video' => $this->video('cp.mp4')])->json('data.id');
        $seenDuringYouTube = null;
        $this->onYouTubeUpload = function () use ($id, &$seenDuringYouTube) {
            $seenDuringYouTube = FacebookPost::find($id)->fb_post_id; // what the DB holds while YouTube is still uploading
        };

        $this->at('10:11');
        $this->runScheduler();

        $this->assertNotNull($seenDuringYouTube, 'Facebook ID is stored before the next platform starts');
    }

    public function test_post_stuck_in_processing_is_recovered_and_retry_skips_what_was_published(): void
    {
        $id = $this->schedule('10:10', ['platforms' => ['Facebook', 'Instagram'], 'post_type' => 'single_image', 'images' => [$this->jpg('stuck.jpg')]])->json('data.id');
        // Simulate a run that died after Facebook succeeded
        FacebookPost::where('id', $id)->update([
            'status' => 'processing', 'fb_post_id' => self::PAGE . '_999',
            'platform_statuses' => json_encode(['facebook' => ['platform' => 'Facebook', 'status' => 'published', 'id' => self::PAGE . '_999']]),
            'updated_at' => Carbon::parse('2026-11-10 08:00:00', 'Asia/Kolkata')->utc(), // 2h15m before the scheduler run
        ]);

        $this->at('10:15');
        $this->runScheduler();
        $post = FacebookPost::find($id);
        $this->assertSame('failed', $post->status);
        $this->assertStringContainsString('Already published to: Facebook', $post->error_message);

        $this->actingAs($this->user)->postJson("/api/v1/posts/{$id}/retry")->assertOk();
        $this->assertSame([], $this->events('fb_photo'), 'Facebook already had the post');
        $this->assertCount(1, $this->events('ig_publish'));
        $this->assertSame(self::PAGE . '_999', FacebookPost::find($id)->fb_post_id);
    }

    public function test_expired_token_fails_with_a_clear_message_and_is_not_retried_automatically(): void
    {
        $id = $this->schedule('10:10', ['platforms' => ['Facebook'], 'post_type' => 'single_image', 'images' => [$this->jpg('t.jpg')]])->json('data.id');
        $this->facebookTokenExpired = true;

        $this->at('10:11');
        $this->runScheduler();

        $post = FacebookPost::find($id);
        $this->assertSame('failed', $post->status);
        $this->assertStringContainsString('Reconnect the account', $post->error_message);
        $this->assertSame(1, (int) $post->retry_count);
    }

    public function test_instagram_processing_delay_is_retried_later_and_published_once(): void
    {
        $id = $this->schedule('10:10', ['platforms' => ['Instagram'], 'post_type' => 'video', 'video' => $this->video('slow.mp4')])->json('data.id');
        $this->igInProgress = true;

        $this->at('10:11');
        $this->runScheduler();
        $post = FacebookPost::find($id);
        $this->assertSame('scheduled', $post->status, 'temporary failure: re-scheduled, not failed');
        $this->assertSame(1, (int) $post->retry_count);
        $this->assertStringContainsString('IN_PROGRESS', $post->error_message);
        $this->assertSame([], $this->events('ig_publish'));
        $this->assertNotNull(FacebookPost::find($id)->media->first(), 'media kept for the retry');

        $this->igInProgress = false;
        $this->at('10:20');
        $this->runScheduler();
        $this->assertSame('published', FacebookPost::find($id)->status);
        $this->assertCount(1, $this->events('ig_publish'));
    }

    // ── scheduling-time validation, scheduler wiring, UI data ──────────────

    public function test_instagram_media_problems_are_reported_when_scheduling(): void
    {
        $this->schedule('10:10', ['platforms' => ['Instagram'], 'post_type' => 'single_image', 'images' => [UploadedFile::fake()->image('wide.jpg', 1500, 400)]])
            ->assertStatus(422)
            ->assertJsonPath('errors.images.0', fn ($m) => str_contains($m, 'Instagram:') && str_contains($m, 'aspect ratio'));
        // Same image is fine for Facebook only
        $this->schedule('10:10', ['platforms' => ['Facebook'], 'post_type' => 'single_image', 'images' => [UploadedFile::fake()->image('wide.jpg', 1500, 400)]])
            ->assertCreated();
        $this->assertSame(1, FacebookPost::count());
    }

    public function test_page_load_publishes_due_posts_only_when_the_scheduler_is_not_running(): void
    {
        $id = $this->schedule('10:10', ['platforms' => ['Facebook'], 'post_type' => 'text', 'message' => 'Due text'])->json('data.id');
        $this->at('10:11');

        // Scheduler heartbeat is fresh: the page load must not publish (and must not block)
        Cache::forever(ProcessScheduledPosts::HEARTBEAT_KEY, now()->toIso8601String());
        $this->actingAs($this->user)->getJson("/api/v1/scheduled-posts?workspace_id={$this->ws->id}")
            ->assertOk()->assertJsonPath('scheduler.running', true);
        $this->actingAs($this->user)->getJson("/api/v1/posts/history?workspace_id={$this->ws->id}")->assertOk();
        $this->assertSame('scheduled', FacebookPost::find($id)->status);

        // Scheduler stopped (heartbeat 10 minutes old): the page load publishes as a fallback and says so
        Cache::forever(ProcessScheduledPosts::HEARTBEAT_KEY, now()->subMinutes(10)->toIso8601String());
        $this->actingAs($this->user)->getJson("/api/v1/scheduled-posts?workspace_id={$this->ws->id}")
            ->assertOk()->assertJsonPath('scheduler.running', false);
        $this->assertSame('published', FacebookPost::find($id)->status);
        // A web fallback run does not pretend to be the scheduler
        $this->assertFalse(ProcessScheduledPosts::schedulerIsRunning());
    }

    public function test_scheduler_runs_the_publisher_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'posts:process-scheduled'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
    }

    public function test_calendar_exposes_type_thumbnail_and_status_for_scheduled_posts(): void
    {
        $this->schedule('10:10', ['platforms' => ['Instagram'], 'post_type' => 'video', 'video' => $this->video('cal.mp4'),
            'thumbnail' => UploadedFile::fake()->image('calthumb.jpg', 1080, 1920)])->assertCreated();
        $this->schedule('10:20', ['platforms' => ['Facebook'], 'post_type' => 'multi_image', 'images' => [$this->jpg('k1.jpg'), $this->jpg('k2.jpg')]])->assertCreated();

        $events = $this->actingAs($this->user)->getJson("/api/v1/publishing/calendar?workspace_id={$this->ws->id}&month=2026-11")->assertOk()->json('events');
        $byType = collect($events)->keyBy('post_type');
        $this->assertSame('scheduled', $byType['video']['status']);
        $this->assertSame(['instagram'], $byType['video']['platforms']);
        $this->assertStringContainsString('/thumbnails/', $byType['video']['thumbnail_url']);
        $this->assertSame('video', $byType['video']['media_type']);
        $this->assertNotEmpty($byType['multi_image']['thumbnail_url']);
        $this->assertStringEndsWith('+00:00', $byType['multi_image']['scheduled_at']);
    }
}
