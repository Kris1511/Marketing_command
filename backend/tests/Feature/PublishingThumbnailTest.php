<?php

namespace Tests\Feature;

use App\Jobs\PublishScheduledPostJob;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\Models\YouTubeConnection;
use App\Services\YouTubeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesPublicMediaHost;
use Tests\TestCase;

/**
 * Custom video thumbnail / Reel cover / carousel cover through drafts, scheduling and publishing.
 *
 * SAFETY: in-memory SQLite, Storage::fake, fake Meta/YouTube APIs (Http::fake + preventStrayRequests,
 * a mocked YouTubeService). Nothing is published anywhere.
 */
class PublishingThumbnailTest extends TestCase
{
    use RefreshDatabase;
    use FakesPublicMediaHost;

    private User $user;
    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->ws = Workspace::create(['name' => 'Thumbnail Client']);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** A tiny but real MP4 header, so MIME detection sees video/mp4. */
    private function video(string $name = 'clip.mp4'): UploadedFile
    {
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 2048);
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function thumb(int $w = 1280, int $h = 720, string $name = 'thumb.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    private function facebookPage(): FacebookPage
    {
        return FacebookPage::create([
            'workspace_id' => $this->ws->id, 'page_id' => '555000111', 'page_name' => 'Client Page', 'page_access_token' => 'EAA_page',
        ]);
    }

    private function fakeFacebook(bool $thumbnailAccepted = true): void
    {
        Http::fake([
            'graph-video.facebook.com/*' => Http::response(['id' => 'fb_video_1'], 200),
            'graph.facebook.com/*/thumbnails' => $thumbnailAccepted
                ? Http::response(['success' => true], 200)
                : Http::response(['error' => ['message' => 'Invalid image', 'code' => 100]], 400),
            'graph.facebook.com/*' => Http::response(['id' => 'x'], 200),
        ]);
    }

    private function save(array $data)
    {
        return $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', array_merge([
            'workspace_id' => $this->ws->id,
            'message'      => 'Thumbnail test',
            'platforms'    => ['Facebook'],
            'post_type'    => 'video',
        ], $data));
    }

    private function thumbnailRequests(): array
    {
        return Http::recorded(fn ($r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/thumbnails'))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    // ── Facebook ───────────────────────────────────────────────────────────

    public function test_facebook_video_with_custom_thumbnail_is_applied_and_persisted(): void
    {
        $this->facebookPage();
        $this->fakeFacebook();

        $res = $this->save(['status' => 'published', 'video' => $this->video(), 'thumbnail' => $this->thumb()]);

        $res->assertOk()
            ->assertJsonPath('platform_statuses.facebook.status', 'published')
            ->assertJsonPath('platform_statuses.facebook.thumbnail.applied', true);

        $requests = $this->thumbnailRequests();
        $this->assertCount(1, $requests);
        $this->assertStringContainsString('/fb_video_1/thumbnails', $requests[0]->url());
        $this->assertTrue($requests[0]->isMultipart());
        $parts = collect($requests[0]->data())->keyBy('name');
        $this->assertSame('true', $parts['is_preferred']['contents']);
        $this->assertArrayHasKey('source', $parts->all());

        $post = FacebookPost::first();
        $this->assertStringStartsWith("posts/{$this->ws->id}/thumbnails/", $post->thumbnail_path);
        Storage::disk('public')->assertExists($post->thumbnail_path);
        $this->assertNotEmpty($post->thumbnail_url);
        // The thumbnail is stored separately from the video
        $this->assertNotSame($post->media->first()->file_path, Storage::disk('public')->path($post->thumbnail_path));
    }

    public function test_facebook_rejecting_the_thumbnail_keeps_the_video_published_and_says_so(): void
    {
        $this->facebookPage();
        $this->fakeFacebook(false);

        $res = $this->save(['status' => 'published', 'video' => $this->video(), 'thumbnail' => $this->thumb()]);

        $res->assertOk()
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('platform_statuses.facebook.status', 'published')
            ->assertJsonPath('platform_statuses.facebook.thumbnail.applied', false);
        $this->assertStringContainsString('Invalid image', $res->json('platform_statuses.facebook.thumbnail.message'));
    }

    public function test_facebook_video_without_thumbnail_is_unchanged(): void
    {
        $this->facebookPage();
        $this->fakeFacebook();

        $res = $this->save(['status' => 'published', 'video' => $this->video()]);

        $res->assertOk()->assertJsonPath('platform_statuses.facebook.status', 'published');
        $this->assertNull($res->json('platform_statuses.facebook.thumbnail'));
        $this->assertSame([], $this->thumbnailRequests());
    }

    // ── Instagram Reel ─────────────────────────────────────────────────────

    private function instagram(): void
    {
        Integration::create([
            'workspace_id' => $this->ws->id, 'platform' => 'instagram', 'is_connected' => true,
            'account_id' => '17841400000000009', 'account_name' => '@client', 'refresh_token' => 'IGAA_token',
        ]);
        $this->usePublicMediaHost();
    }

    private function fakeInstagram(bool $rejectCover = false): void
    {
        Http::fake([
            'graph.instagram.com/*' => function ($request) use ($rejectCover) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);
                if ($request->method() === 'POST' && str_ends_with($path, '/media_publish')) {
                    return Http::response(['id' => 'ig_reel_1'], 200);
                }
                if ($request->method() === 'POST' && str_ends_with($path, '/media')) {
                    if ($rejectCover && isset($request['cover_url'])) {
                        return Http::response(['error' => ['message' => 'Cover image is not supported for this account', 'code' => 100]], 400);
                    }
                    return Http::response(['id' => 'container_1'], 200);
                }
                return Http::response(['status_code' => 'FINISHED'], 200);
            },
        ]);
    }

    private function containerRequests(): array
    {
        return Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/media'))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    public function test_instagram_reel_gets_public_https_cover_url_and_reports_it_applied(): void
    {
        $this->instagram();
        $this->fakeInstagram();

        $res = $this->save(['status' => 'published', 'platforms' => ['Instagram'], 'video' => $this->video(), 'thumbnail' => $this->thumb(1080, 1920, 'cover.jpg')]);

        $res->assertOk()
            ->assertJsonPath('platform_statuses.instagram.id', 'ig_reel_1')
            ->assertJsonPath('platform_statuses.instagram.thumbnail.applied', true);

        $container = $this->containerRequests()[0];
        $this->assertSame('REELS', $container['media_type']);
        $this->assertMatchesRegularExpression("#^https://media\.example\.test/storage/posts/{$this->ws->id}/thumbnails/[0-9a-f-]{36}\.jpg$#", $container['cover_url']);
        // The cover URL was downloaded and checked before Meta was called
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === $container['cover_url']);
    }

    public function test_instagram_rejecting_the_cover_still_publishes_the_reel_without_claiming_the_cover(): void
    {
        $this->instagram();
        $this->fakeInstagram(true);

        $res = $this->save(['status' => 'published', 'platforms' => ['Instagram'], 'video' => $this->video(), 'thumbnail' => $this->thumb(1080, 1920)]);

        $res->assertOk()
            ->assertJsonPath('platform_statuses.instagram.status', 'published')
            ->assertJsonPath('platform_statuses.instagram.thumbnail.applied', false);
        $this->assertStringContainsString('Cover image is not supported', $res->json('platform_statuses.instagram.thumbnail.message'));
        $containers = $this->containerRequests();
        $this->assertCount(2, $containers);
        $this->assertArrayHasKey('cover_url', $containers[0]->data());
        $this->assertArrayNotHasKey('cover_url', $containers[1]->data());
    }

    public function test_unreachable_cover_url_publishes_reel_without_cover_and_explains_why(): void
    {
        Integration::create([
            'workspace_id' => $this->ws->id, 'platform' => 'instagram', 'is_connected' => true,
            'account_id' => '17841400000000009', 'account_name' => '@client', 'refresh_token' => 'IGAA_token',
        ]);
        $this->usePublicMediaHost(null, false);
        // The media host serves the video but the cover image 404s (e.g. not synced to the public server).
        Http::fake([
            'media.example.test/*' => function ($request) {
                $relative = preg_replace('#^/storage/#', '', rawurldecode((string) parse_url($request->url(), PHP_URL_PATH)));
                if (str_contains($relative, '/thumbnails/') || !Storage::disk('public')->exists($relative)) {
                    return Http::response('<html>404</html>', 404, ['Content-Type' => 'text/html']);
                }
                return Http::response(Storage::disk('public')->get($relative), 200, ['Content-Type' => 'video/mp4']);
            },
        ]);
        $this->fakeInstagram();

        $res = $this->save(['status' => 'published', 'platforms' => ['Instagram'], 'video' => $this->video(), 'thumbnail' => $this->thumb(1080, 1920)]);

        $res->assertOk()
            ->assertJsonPath('platform_statuses.instagram.status', 'published')
            ->assertJsonPath('platform_statuses.instagram.thumbnail.applied', false);
        $this->assertStringContainsString('HTTP 404', $res->json('platform_statuses.instagram.thumbnail.message'));
        $containers = $this->containerRequests();
        $this->assertCount(1, $containers);
        $this->assertArrayNotHasKey('cover_url', $containers[0]->data());
    }

    // ── YouTube ────────────────────────────────────────────────────────────

    public function test_youtube_receives_the_thumbnail_and_reports_the_real_outcome(): void
    {
        YouTubeConnection::create([
            'user_id' => $this->user->id, 'workspace_id' => $this->ws->id, 'channel_id' => 'UC_thumb', 'channel_name' => 'Chan',
            'access_token' => 'ya29.x', 'token_expires_at' => now()->addHour(),
        ]);
        Integration::create(['workspace_id' => $this->ws->id, 'platform' => 'youtube', 'is_connected' => true, 'account_id' => 'UC_thumb', 'account_name' => 'Chan']);

        $thumb = $this->thumb();
        $thumbSha = hash_file('sha256', $thumb->getRealPath());

        // The video goes up without the thumbnail; thumbnails.set then gets the exact uploaded file.
        $yt = $this->createMock(YouTubeService::class);
        $yt->expects($this->once())->method('uploadVideo')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), 'public')
            ->willReturn(['id' => 'yt_1', 'url' => 'https://youtu.be/yt_1']);
        $yt->method('getProcessingStatus')->willReturn(['found' => true, 'processing_status' => 'succeeded', 'upload_status' => 'processed', 'reason' => null]);
        $yt->expects($this->once())->method('setCustomThumbnail')
            ->with($this->anything(), 'yt_1', $this->callback(fn ($p) => is_file($p) && hash_file('sha256', $p) === $thumbSha), 'image/jpeg')
            ->willThrowException(new \Google\Service\Exception(
                'The authenticated user doesn\'t have permissions to upload and set custom video thumbnails.', 403, null,
                [['reason' => 'forbidden', 'message' => 'The authenticated user doesn\'t have permissions to upload and set custom video thumbnails.']]
            ));
        $this->app->instance(YouTubeService::class, $yt);

        $res = $this->save(['status' => 'published', 'platforms' => ['YouTube'], 'video' => $this->video(), 'thumbnail' => $thumb]);

        // The video is published; the thumbnail failure is reported on its own, with YouTube's real error.
        $res->assertOk()
            ->assertJsonPath('platform_statuses.youtube.status', 'published')
            ->assertJsonPath('platform_statuses.youtube.thumbnail.applied', false)
            ->assertJsonPath('platform_statuses.youtube.thumbnail.status', 'failed')
            ->assertJsonPath('platform_statuses.youtube.thumbnail.error.reason', 'forbidden')
            ->assertJsonPath('platform_statuses.youtube.thumbnail.error.code', 403)
            ->assertJsonPath('platform_statuses.youtube.thumbnail.sha256', $thumbSha);
        $this->assertStringContainsString('youtube.com/verify', $res->json('platform_statuses.youtube.thumbnail.message'));
        $this->assertStringContainsString("doesn't have permissions", $res->json('platform_statuses.youtube.thumbnail.message'));
    }

    // ── Drafts & scheduling ────────────────────────────────────────────────

    public function test_draft_keeps_thumbnail_and_publishing_the_draft_uses_it(): void
    {
        $this->facebookPage();
        $this->fakeFacebook();

        $draftId = $this->save(['status' => 'draft', 'video' => $this->video(), 'thumbnail' => $this->thumb()])
            ->assertCreated()->json('data.id');
        $this->assertSame([], $this->thumbnailRequests(), 'Saving a draft must not call Meta');

        // Restore (Edit) returns the thumbnail
        $restored = $this->actingAs($this->user)->getJson("/api/v1/drafts/{$draftId}")->assertOk();
        $this->assertNotEmpty($restored->json('data.thumbnail_url'));
        $this->assertNotEmpty($restored->json('data.thumbnail_path'));

        // Edit something else and save again without re-sending the thumbnail: it is kept
        $this->save(['status' => 'draft', 'draft_id' => $draftId, 'message' => 'Edited'])->assertOk();
        $this->assertNotNull(FacebookPost::find($draftId)->thumbnail_path);

        // Publish the saved draft (no files re-sent): Facebook gets the stored thumbnail
        $this->save(['status' => 'published', 'draft_id' => $draftId])->assertOk()
            ->assertJsonPath('platform_statuses.facebook.thumbnail.applied', true);
        $this->assertCount(1, $this->thumbnailRequests());
    }

    public function test_replace_and_remove_thumbnail_on_a_draft(): void
    {
        $draftId = $this->save(['status' => 'draft', 'video' => $this->video(), 'thumbnail' => $this->thumb()])->json('data.id');
        $first = FacebookPost::find($draftId)->thumbnail_path;

        $this->save(['status' => 'draft', 'draft_id' => $draftId, 'thumbnail' => $this->thumb(1080, 1920, 'new.png')])->assertOk();
        $replaced = FacebookPost::find($draftId)->thumbnail_path;
        $this->assertNotSame($first, $replaced);
        $this->assertStringEndsWith('.png', $replaced);

        $this->save(['status' => 'draft', 'draft_id' => $draftId, 'remove_thumbnail' => 1])->assertOk();
        $post = FacebookPost::find($draftId);
        $this->assertNull($post->thumbnail_path);
        $this->assertNull($post->thumbnail_url);
    }

    public function test_switching_a_draft_to_an_image_post_drops_the_thumbnail(): void
    {
        $draftId = $this->save(['status' => 'draft', 'video' => $this->video(), 'thumbnail' => $this->thumb()])->json('data.id');

        $this->save(['status' => 'draft', 'draft_id' => $draftId, 'post_type' => 'single_image', 'images' => [UploadedFile::fake()->image('p.jpg', 1080, 1080)]])
            ->assertOk();

        $this->assertNull(FacebookPost::find($draftId)->thumbnail_path);
    }

    public function test_scheduled_video_publishes_with_its_saved_thumbnail(): void
    {
        $this->facebookPage();
        $this->fakeFacebook();

        $id = $this->save([
            'status' => 'scheduled', 'scheduled_at' => now('Asia/Kolkata')->addDay()->format('Y-m-d H:i:s'),
            'video' => $this->video(), 'thumbnail' => $this->thumb(),
        ])->assertCreated()->json('data.id');
        $this->assertNotNull(FacebookPost::find($id)->thumbnail_path);

        Carbon::setTestNow(now()->addDays(2));
        try {
            PublishScheduledPostJob::dispatchSync($id);
        } finally {
            Carbon::setTestNow();
        }

        $post = FacebookPost::find($id);
        $this->assertSame('published', $post->status);
        $this->assertTrue($post->platform_statuses['facebook']['thumbnail']['applied']);
        $this->assertCount(1, $this->thumbnailRequests());
    }

    // ── Validation ─────────────────────────────────────────────────────────

    public function test_thumbnail_validation(): void
    {
        // Not a video post
        $this->save(['status' => 'draft', 'post_type' => 'single_image', 'images' => [UploadedFile::fake()->image('p.jpg', 1080, 1080)], 'thumbnail' => $this->thumb()])
            ->assertStatus(422)->assertJsonPath('errors.thumbnail.0', fn ($m) => str_contains($m, 'only be added to a video post'));
        // Wrong type
        $this->save(['status' => 'draft', 'video' => $this->video(), 'thumbnail' => UploadedFile::fake()->image('t.gif', 800, 600)])
            ->assertStatus(422)->assertJsonValidationErrors('thumbnail');
        $this->save(['status' => 'draft', 'video' => $this->video(), 'thumbnail' => UploadedFile::fake()->image('t.webp', 800, 600)])
            ->assertStatus(422)->assertJsonValidationErrors('thumbnail');
        // Too small
        $this->save(['status' => 'draft', 'video' => $this->video(), 'thumbnail' => $this->thumb(300, 300)])
            ->assertStatus(422)->assertJsonPath('errors.thumbnail.0', fn ($m) => str_contains($m, 'at least 320px'));
        // Unsupported aspect ratio
        $this->save(['status' => 'draft', 'video' => $this->video(), 'thumbnail' => $this->thumb(1500, 400)])
            ->assertStatus(422)->assertJsonPath('errors.thumbnail.0', fn ($m) => str_contains($m, 'aspect ratio'));
        // Too large (> 8 MB)
        $this->save(['status' => 'draft', 'video' => $this->video(), 'thumbnail' => UploadedFile::fake()->create('huge.jpg', 9000, 'image/jpeg')])
            ->assertStatus(422)->assertJsonValidationErrors('thumbnail');

        $this->assertSame(0, FacebookPost::count());
    }

    // ── Carousel cover ─────────────────────────────────────────────────────

    public function test_carousel_cover_can_be_changed_on_a_saved_draft_and_is_published_first(): void
    {
        $images = [
            UploadedFile::fake()->image('one.jpg', 1080, 1080),
            UploadedFile::fake()->image('two.jpg', 1080, 1080),
            UploadedFile::fake()->image('three.jpg', 1080, 1080),
        ];
        $draftId = $this->save(['status' => 'draft', 'post_type' => 'multi_image', 'platforms' => ['Instagram'], 'images' => $images])->json('data.id');
        $media = FacebookPost::find($draftId)->media;
        [$one, $two, $three] = [$media[0], $media[1], $media[2]];

        // Invalid order is rejected
        $this->save(['status' => 'draft', 'draft_id' => $draftId, 'post_type' => 'multi_image', 'platforms' => ['Instagram'], 'media_order' => [$three->id, 999]])
            ->assertStatus(422)->assertJsonValidationErrors('media_order');

        // "Set as cover" on image three: it moves to the front, the others keep their order
        $this->save(['status' => 'draft', 'draft_id' => $draftId, 'post_type' => 'multi_image', 'platforms' => ['Instagram'], 'media_order' => [$three->id, $one->id, $two->id]])
            ->assertOk();
        $this->assertSame([$three->id, $one->id, $two->id], FacebookPost::find($draftId)->media->pluck('id')->all());

        $restored = $this->actingAs($this->user)->getJson("/api/v1/drafts/{$draftId}")->json('data.media');
        $this->assertSame($three->id, $restored[0]['id']);

        // Publishing sends the cover as the first carousel item
        $this->instagram();
        $this->fakeInstagram();
        $this->save(['status' => 'published', 'draft_id' => $draftId, 'post_type' => 'multi_image', 'platforms' => ['Instagram']])->assertOk();
        $items = array_values(array_filter($this->containerRequests(), fn ($r) => ($r['is_carousel_item'] ?? null) === 'true'));
        $this->assertCount(3, $items);
        $this->assertStringContainsString(basename($three->file_path), $items[0]['image_url']);
        $this->assertStringContainsString(basename($one->file_path), $items[1]['image_url']);
    }

    // ── Database not migrated yet ──────────────────────────────────────────

    public function test_publishing_keeps_working_before_the_thumbnail_migration_runs(): void
    {
        Schema::table('facebook_posts', function ($table) {
            $table->dropColumn(['thumbnail_path', 'thumbnail_url']);
        });
        $this->resetThumbnailColumnCache();
        try {
            $this->facebookPage();
            $this->fakeFacebook();

            // Ordinary video posts and drafts work exactly as before
            $this->save(['status' => 'draft', 'video' => $this->video()])->assertCreated();
            $this->save(['status' => 'published', 'video' => $this->video()])->assertOk();

            // Saving a thumbnail explains that a migration is needed instead of failing with SQL errors
            $this->save(['status' => 'draft', 'video' => $this->video(), 'thumbnail' => $this->thumb()])
                ->assertStatus(422)->assertJsonPath('errors.thumbnail.0', fn ($m) => str_contains($m, 'php artisan migrate'));
        } finally {
            $this->resetThumbnailColumnCache();
        }
    }

    private function resetThumbnailColumnCache(): void
    {
        $prop = new \ReflectionProperty(FacebookPost::class, 'thumbnailColumnsExist');
        $prop->setValue(null, null);
    }
}
