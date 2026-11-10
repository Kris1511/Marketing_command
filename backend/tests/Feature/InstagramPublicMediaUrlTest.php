<?php

namespace Tests\Feature;

use App\Jobs\PublishScheduledPostJob;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\FacebookPostMedia;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesPublicMediaHost;
use Tests\TestCase;

/**
 * End-to-end Instagram publishing through the real FacebookGraphService and PublicMediaUrlService.
 *
 * SAFETY: in-memory SQLite, Storage::fake('public'), Http::fake for Meta + the media host, and
 * Http::preventStrayRequests(), so any un-faked outbound request fails the test. Nothing reaches Meta.
 */
class InstagramPublicMediaUrlTest extends TestCase
{
    use RefreshDatabase;
    use FakesPublicMediaHost;

    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();

        // The API is called on http://localhost:8000 in local development; this must never leak into Meta calls.
        config(['app.url' => 'http://localhost:8000']);

        $this->user = User::factory()->create(['role' => 'admin']);
        $this->workspace = Workspace::create(['name' => 'Instagram Media Client']);

        Integration::create([
            'workspace_id'  => $this->workspace->id,
            'platform'      => 'instagram',
            'is_connected'  => true,
            'account_id'    => '17841447923134069',
            'account_name'  => '@client',
            'refresh_token' => 'IGAA_test_token',
        ]);
    }

    private function fakeInstagramSuccess(string $publishedId = 'ig_media_777'): void
    {
        Http::fake([
            'graph.instagram.com/*' => function ($request) use ($publishedId) {
                $path = parse_url($request->url(), PHP_URL_PATH);
                if ($request->method() === 'POST' && str_ends_with($path, '/media_publish')) {
                    return Http::response(['id' => $publishedId], 200);
                }
                if ($request->method() === 'POST' && str_ends_with($path, '/media')) {
                    return Http::response(['id' => 'container_' . substr(md5((string) json_encode($request->data())), 0, 8)], 200);
                }
                return Http::response(['status_code' => 'FINISHED', 'id' => basename($path)], 200);
            },
        ]);
    }

    private function publish(array $overrides = [])
    {
        return $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', array_merge([
            'workspace_id' => $this->workspace->id,
            'message'      => 'Instagram media URL test',
            'status'       => 'published',
            'platforms'    => ['Instagram'],
            'post_type'    => 'single_image',
            'images'       => [UploadedFile::fake()->image('photo.jpg', 1080, 1080)],
        ], $overrides));
    }

    public function test_single_image_is_sent_to_instagram_as_verified_public_https_url(): void
    {
        $this->usePublicMediaHost();
        $this->fakeInstagramSuccess();

        $response = $this->publish();

        $response->assertOk()
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('platform_statuses.instagram.status', 'published')
            ->assertJsonPath('platform_statuses.instagram.id', 'ig_media_777');

        $containers = $this->instagramContainerRequests();
        $this->assertCount(1, $containers);
        $imageUrl = $containers[0]['image_url'];
        $this->assertMatchesRegularExpression(
            '#^https://media\.example\.test/storage/posts/' . $this->workspace->id . '/[0-9a-f-]{36}\.jpg$#',
            $imageUrl
        );

        // The exact URL was downloaded (HTTP 200, image/jpeg) before Meta was asked to fetch it.
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === $imageUrl);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'localhost') || str_contains(json_encode($r->data()), 'localhost'));

        $this->assertDatabaseHas('facebook_posts', ['workspace_id' => $this->workspace->id, 'status' => 'published', 'ig_media_id' => 'ig_media_777']);
    }

    public function test_localhost_public_url_fails_with_meaningful_error_and_meta_is_never_called(): void
    {
        $this->usePublicMediaHost('http://localhost:8000', false);
        $this->fakeInstagramSuccess();

        $response = $this->publish();

        $response->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('platform_statuses.instagram.status', 'failed');
        $error = $response->json('platform_statuses.instagram.error');
        $this->assertStringContainsString('Instagram requires a publicly accessible HTTPS image URL', $error);
        $this->assertStringContainsString('PUBLIC_MEDIA_URL', $error);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.instagram.com'));
        $this->assertDatabaseHas('facebook_posts', ['workspace_id' => $this->workspace->id, 'status' => 'failed', 'ig_media_id' => null]);
    }

    public function test_inaccessible_public_url_fails_before_calling_meta(): void
    {
        // Public base that does not serve this server's files (e.g. APP_URL = production while running locally).
        $this->usePublicMediaHost('https://prod.example.test', false);
        Http::fake(['prod.example.test/*' => Http::response('<html>404</html>', 404, ['Content-Type' => 'text/html'])]);
        $this->fakeInstagramSuccess();

        $response = $this->publish();

        $response->assertStatus(422);
        $this->assertStringContainsString('returned HTTP 404', $response->json('platform_statuses.instagram.error'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.instagram.com'));
    }

    public function test_multiple_images_each_get_their_own_public_https_url(): void
    {
        $this->usePublicMediaHost();
        $this->fakeInstagramSuccess('ig_carousel_1');

        $response = $this->publish([
            'post_type' => 'multi_image',
            'images'    => [
                UploadedFile::fake()->image('one.jpg', 1080, 1350),
                UploadedFile::fake()->image('two.png', 1200, 1000),
                UploadedFile::fake()->image('three.jpg', 1080, 1080),
            ],
        ]);

        $response->assertOk()->assertJsonPath('platform_statuses.instagram.id', 'ig_carousel_1');

        $items = array_values(array_filter($this->instagramContainerRequests(), fn ($r) => ($r['is_carousel_item'] ?? null) === 'true'));
        $this->assertCount(3, $items);
        $urls = array_map(fn ($r) => $r['image_url'], $items);
        $this->assertCount(3, array_unique($urls));
        foreach ($urls as $url) {
            $this->assertStringStartsWith('https://media.example.test/storage/posts/' . $this->workspace->id . '/', $url);
            $this->assertStringEndsWith('.jpg', $url); // the PNG is converted to a JPEG rendition
            Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === $url);
        }

        $parent = array_values(array_filter($this->instagramContainerRequests(), fn ($r) => ($r['media_type'] ?? null) === 'CAROUSEL'));
        $this->assertCount(1, $parent);
    }

    public function test_saved_draft_published_later_uses_public_https_url(): void
    {
        $this->usePublicMediaHost();
        $this->fakeInstagramSuccess('ig_from_draft');

        $draft = $this->publish(['status' => 'draft'])->assertCreated();
        $draftId = $draft->json('data.id');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.instagram.com'));

        // Publish the saved draft without re-uploading the image.
        $response = $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'draft_id'     => $draftId,
            'message'      => 'Instagram media URL test',
            'status'       => 'published',
            'platforms'    => ['Instagram'],
            'post_type'    => 'single_image',
        ]);

        $response->assertOk()->assertJsonPath('platform_statuses.instagram.id', 'ig_from_draft');
        $imageUrl = $this->instagramContainerRequests()[0]['image_url'];
        $this->assertStringStartsWith('https://media.example.test/storage/posts/' . $this->workspace->id . '/', $imageUrl);
    }

    public function test_scheduled_post_uses_public_https_url_even_when_stored_url_is_localhost(): void
    {
        $this->usePublicMediaHost();
        $this->fakeInstagramSuccess('ig_scheduled_1');

        $relative = "posts/{$this->workspace->id}/0439d274-a339-423c-bc38-ae8315ddadec.jpeg";
        Storage::disk('public')->put($relative, $this->jpegBytes());

        $post = FacebookPost::create([
            'workspace_id'  => $this->workspace->id,
            'content'       => 'Scheduled Instagram post',
            'status'        => 'scheduled',
            'scheduled_at'  => Carbon::now()->subMinute(),
            'post_type'     => 'single_image',
            'platforms'     => ['Instagram'],
            'created_by_id' => $this->user->id,
        ]);
        FacebookPostMedia::create([
            'facebook_post_id' => $post->id,
            'media_type'       => 'image',
            'file_path'        => Storage::disk('public')->path($relative),
            'file_url'         => "http://localhost:8000/storage/{$relative}", // what older uploads stored
            'sort_order'       => 0,
        ]);

        PublishScheduledPostJob::dispatchSync($post->id);

        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertSame('ig_scheduled_1', $post->ig_media_id);
        $this->assertSame("https://media.example.test/storage/{$relative}", $this->instagramContainerRequests()[0]['image_url']);
    }

    public function test_scheduled_post_with_only_a_stale_localhost_url_is_reissued_on_the_public_host(): void
    {
        $this->usePublicMediaHost();
        $this->fakeInstagramSuccess('ig_scheduled_2');

        $relative = "posts/{$this->workspace->id}/legacy.jpg";
        Storage::disk('public')->put($relative, $this->jpegBytes());

        $post = FacebookPost::create([
            'workspace_id'  => $this->workspace->id,
            'content'       => 'Legacy scheduled post',
            'status'        => 'scheduled',
            'scheduled_at'  => Carbon::now()->subMinute(),
            'post_type'     => 'single_image',
            'platforms'     => ['Instagram'],
            'created_by_id' => $this->user->id,
        ]);
        FacebookPostMedia::create([
            'facebook_post_id' => $post->id,
            'media_type'       => 'image',
            'file_path'        => 'Z:\\moved-server\\storage\\app/public\\' . $relative, // path no longer valid
            'file_url'         => "http://localhost:8000/storage/{$relative}",
            'sort_order'       => 0,
        ]);

        PublishScheduledPostJob::dispatchSync($post->id);

        $this->assertSame('published', $post->fresh()->status);
        $this->assertSame("https://media.example.test/storage/{$relative}", $this->instagramContainerRequests()[0]['image_url']);
    }

    public function test_media_error_is_not_retried_with_the_page_token(): void
    {
        // A page token is available as a fallback, but a broken media URL cannot be fixed by another token.
        $this->usePublicMediaHost('https://prod.example.test', false);
        FacebookPage::create([
            'workspace_id'      => $this->workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'Client Page',
            'page_access_token' => 'EAA_page_token',
        ]);
        Http::fake([
            'prod.example.test/*'  => Http::response('<html>404</html>', 404, ['Content-Type' => 'text/html']),
            'graph.facebook.com/*' => Http::response(['id' => '115864121526929'], 200), // no linked IG account
        ]);
        $this->fakeInstagramSuccess();

        $response = $this->publish();

        $response->assertStatus(422);
        $this->assertStringContainsString('returned HTTP 404', $response->json('platform_statuses.instagram.error'));
        // Exactly one download check: the media error was not retried with the page token.
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'prod.example.test')));
        Http::assertNotSent(fn ($r) => preg_match('#/media(_publish)?$#', (string) parse_url($r->url(), PHP_URL_PATH)) === 1);
    }

    public function test_facebook_image_publishing_is_unaffected_by_public_media_configuration(): void
    {
        // Worst case for Instagram (localhost, no Cloudinary): Facebook must still publish, because it uploads bytes.
        $this->usePublicMediaHost('http://localhost:8000', false);
        FacebookPage::create([
            'workspace_id'      => $this->workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'Client Page',
            'page_access_token' => 'EAA_page_token',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'photo_1', 'post_id' => '115864121526929_555'], 200)]);

        $response = $this->publish(['platforms' => ['Facebook']]);

        $response->assertOk()->assertJsonPath('platform_statuses.facebook.status', 'published');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/115864121526929/photos') && $r->isMultipart());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.instagram.com') || str_contains($r->url(), '/storage/'));
    }
}
