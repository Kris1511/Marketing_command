<?php

namespace Tests\Feature;

use App\Jobs\PublishScheduledPostJob;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\FacebookPostMedia;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FacebookGraphService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Publishing -> Instagram Overview dashboard data flow.
 *
 * SAFETY: in-memory SQLite, Storage::fake, a fake Meta Graph API (Http::fake + preventStrayRequests), and
 * mocked publish calls. Nothing reaches Meta or the real database.
 */
class InstagramDashboardSyncTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT_A = '17841400000000001';
    private const ACCOUNT_B = '17841400000000002';

    private User $user;
    private Workspace $wsA;
    private Workspace $wsB;

    /** Media each fake Instagram account currently has, newest first (what Meta's /{ig-user}/media returns). */
    private array $metaMedia = [self::ACCOUNT_A => [], self::ACCOUNT_B => []];
    private bool $metaMediaDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        // 10 Nov 2026, 12:00 IST
        Carbon::setTestNow(Carbon::parse('2026-11-10 06:30:00', 'UTC'));

        $this->user = User::factory()->create(['role' => 'admin']);
        $this->wsA = Workspace::create(['name' => 'Client A']);
        $this->wsB = Workspace::create(['name' => 'Client B']);

        foreach ([[$this->wsA, self::ACCOUNT_A, '@client_a'], [$this->wsB, self::ACCOUNT_B, '@client_b']] as [$ws, $acct, $name]) {
            Integration::create([
                'workspace_id'      => $ws->id,
                'platform'          => 'instagram',
                'is_connected'      => true,
                'connection_status' => 'connected',
                'account_id'        => $acct,
                'account_name'      => $name,
                'refresh_token'     => 'EAA_token_' . $acct,
            ]);
        }

        $this->fakeMeta();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeMeta(): void
    {
        Http::fake([
            'graph.facebook.com/*' => function ($request) {
                $path = trim((string) parse_url($request->url(), PHP_URL_PATH), '/');
                $parts = explode('/', $path); // v23.0/{node}[/{edge}]
                $node = $parts[1] ?? '';
                $edge = $parts[2] ?? null;

                if (isset($this->metaMedia[$node]) && $edge === 'media') {
                    if ($this->metaMediaDown) {
                        return Http::response(['error' => ['message' => 'Application request limit reached', 'code' => 4]], 400);
                    }
                    return Http::response(['data' => $this->metaMedia[$node]], 200);
                }
                if (isset($this->metaMedia[$node]) && $edge === null) {
                    return Http::response(['id' => $node, 'username' => 'acct' . substr($node, -1), 'followers_count' => 1200, 'media_count' => count($this->metaMedia[$node])], 200);
                }
                if (isset($this->metaMedia[$node]) && $edge === 'insights') {
                    return Http::response(['data' => [
                        ['name' => 'reach', 'total_value' => ['value' => 900], 'values' => [['value' => 900]]],
                    ]], 200);
                }
                if ($edge === 'insights') { // per-media insights
                    return Http::response(['data' => [
                        ['name' => 'views', 'values' => [['value' => 150]]],
                        ['name' => 'reach', 'values' => [['value' => 120]]],
                        ['name' => 'shares', 'values' => [['value' => 4]]],
                        ['name' => 'saved', 'values' => [['value' => 2]]],
                    ]], 200);
                }
                return Http::response(['data' => []], 200);
            },
        ]);
    }

    private function media(string $id, string $timestampUtc, string $type = 'IMAGE', string $product = 'FEED', int $likes = 10, int $comments = 3): array
    {
        return [
            'id' => $id, 'caption' => "Caption {$id}\nsecond line", 'media_type' => $type, 'media_product_type' => $product,
            'media_url' => "https://cdn.example.test/{$id}.jpg", 'permalink' => "https://www.instagram.com/p/{$id}/",
            'timestamp' => $timestampUtc, 'like_count' => $likes, 'comments_count' => $comments,
        ];
    }

    private function stats(Workspace $ws, string $start = '2026-11-10', string $end = '2026-11-10', array $extra = [])
    {
        return $this->actingAs($this->user)->getJson('/api/v1/instagram/dashboard-stats?' . http_build_query(
            ['workspace_id' => $ws->id, 'start_date' => $start, 'end_date' => $end] + $extra
        ));
    }

    private function posts(Workspace $ws, string $start = '2026-11-01', string $end = '2026-11-10', array $extra = [])
    {
        return $this->actingAs($this->user)->getJson('/api/v1/instagram/posts?' . http_build_query(
            ['workspace_id' => $ws->id, 'start_date' => $start, 'end_date' => $end, 'per_page' => 5] + $extra
        ));
    }

    public function test_date_range_uses_ist_calendar_days(): void
    {
        $this->metaMedia[self::ACCOUNT_A] = [
            $this->media('m_next_day', '2026-11-10T19:00:00+0000'),   // 11 Nov 00:30 IST -> outside
            $this->media('m_late', '2026-11-10T17:00:00+0000'),       // 10 Nov 22:30 IST -> inside
            $this->media('m_early', '2026-11-09T20:00:00+0000'),      // 10 Nov 01:30 IST -> inside (UTC says 9 Nov)
            $this->media('m_prev_day', '2026-11-09T18:00:00+0000'),   //  9 Nov 23:30 IST -> outside
        ];

        $res = $this->stats($this->wsA)->assertOk()->assertJsonPath('success', true);

        $this->assertSame(2, $res->json('summary.total_posts'));
        $this->assertSame(['Nov 10'], $res->json('posts_chart.labels'));
        $this->assertSame([2], $res->json('posts_chart.counts'));

        // Exactly the two posts published on 10 Nov IST (a UTC window would pick m_next_day instead of m_early).
        $ids = array_column($this->posts($this->wsA, '2026-11-10', '2026-11-10')->json('data'), 'id');
        $this->assertSame(['m_late', 'm_early'], $ids);
    }

    public function test_post_types_and_latest_posts_fields(): void
    {
        $this->metaMedia[self::ACCOUNT_A] = [
            $this->media('m_reel', '2026-11-09T10:00:00+0000', 'VIDEO', 'REELS', 7, 1),
            $this->media('m_carousel', '2026-11-08T10:00:00+0000', 'CAROUSEL_ALBUM', 'FEED', 5, 2),
            $this->media('m_video', '2026-11-07T10:00:00+0000', 'VIDEO', 'FEED', 4, 0),
            $this->media('m_photo', '2026-11-06T10:00:00+0000', 'IMAGE', 'FEED', 9, 3),
        ];

        $stats = $this->stats($this->wsA, '2026-11-01', '2026-11-10')->assertOk();
        $this->assertSame(['photo' => 1, 'video' => 1, 'reel' => 1, 'carousel' => 1, 'other' => 0], $stats->json('post_types'));
        $this->assertSame(4, $stats->json('summary.total_posts'));
        $this->assertSame(25, $stats->json('summary.likes'));
        $this->assertSame(6, $stats->json('summary.comments'));
        $this->assertSame(1200, $stats->json('summary.total_followers'));

        $posts = $this->posts($this->wsA)->assertOk();
        $this->assertSame(['Reel', 'Carousel', 'Video', 'Photo'], array_column($posts->json('data'), 'formatted_type'));
        $first = $posts->json('data.0');
        $this->assertSame('instagram', $first['platform']);
        $this->assertSame('Caption m_reel', $first['title']);
        $this->assertSame(150, $first['views']);
        $this->assertSame(7, $first['likes_count']);
        $this->assertSame(1, $first['comments_count']);
        $this->assertSame(4, $first['shares_count']);
        $this->assertSame('2026-11-09T10:00:00+00:00', $first['published_at']);
    }

    public function test_publishing_to_instagram_updates_cached_dashboard_immediately(): void
    {
        $this->metaMedia[self::ACCOUNT_A] = [$this->media('m_old', '2026-11-10T01:00:00+0000')];

        // Dashboard loaded before publishing: 1 post, now cached for 5 minutes.
        $this->stats($this->wsA)->assertJsonPath('summary.total_posts', 1);
        $this->posts($this->wsA)->assertJsonPath('total', 1);

        // Publish a photo through the Publishing module (Meta's publish call is mocked).
        $mock = $this->createPartialMock(FacebookGraphService::class, ['publishInstagramSinglePhoto']);
        $mock->expects($this->once())->method('publishInstagramSinglePhoto')->willReturnCallback(function () {
            array_unshift($this->metaMedia[self::ACCOUNT_A], $this->media('m_new', '2026-11-10T06:29:00+0000'));
            return ['id' => 'm_new', 'status' => 'published'];
        });
        $this->app->instance(FacebookGraphService::class, $mock);

        $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->wsA->id,
            'message'      => 'Fresh Instagram photo',
            'status'       => 'published',
            'platforms'    => ['Instagram'],
            'post_type'    => 'single_image',
            'images'       => [UploadedFile::fake()->image('p.jpg', 1080, 1080)],
        ])->assertOk()->assertJsonPath('platform_statuses.instagram.id', 'm_new');

        // Same request as before (no force_refresh): the new post is counted and listed first.
        $stats = $this->stats($this->wsA)->assertOk();
        $this->assertSame(2, $stats->json('summary.total_posts'));
        $this->assertSame(1, $stats->json('summary.app_published_posts'));
        $this->assertSame([], $stats->json('summary.app_published_not_on_meta'));

        $posts = $this->posts($this->wsA)->assertOk();
        $this->assertSame(2, $posts->json('total'));
        $this->assertSame('m_new', $posts->json('data.0.id'));
        $this->assertSame('Photo', $posts->json('data.0.formatted_type'));
    }

    public function test_scheduled_instagram_publish_invalidates_dashboard_cache(): void
    {
        $this->metaMedia[self::ACCOUNT_A] = [];
        $this->stats($this->wsA)->assertJsonPath('summary.total_posts', 0);
        $versionBefore = FacebookGraphService::instagramDashboardCacheVersion(self::ACCOUNT_A);

        $relative = "posts/{$this->wsA->id}/scheduled.jpg";
        $img = UploadedFile::fake()->image('s.jpg', 1080, 1080);
        Storage::disk('public')->put($relative, file_get_contents($img->getRealPath()));
        $post = FacebookPost::create([
            'workspace_id'  => $this->wsA->id,
            'content'       => 'Scheduled carousel',
            'status'        => 'scheduled',
            'scheduled_at'  => now()->subMinute(),
            'post_type'     => 'single_image',
            'platforms'     => ['Instagram'],
            'created_by_id' => $this->user->id,
        ]);
        FacebookPostMedia::create([
            'facebook_post_id' => $post->id, 'media_type' => 'image', 'sort_order' => 0,
            'file_path' => Storage::disk('public')->path($relative), 'file_url' => "https://x.test/storage/{$relative}",
        ]);

        $mock = $this->createPartialMock(FacebookGraphService::class, ['publishInstagramSinglePhoto']);
        $mock->method('publishInstagramSinglePhoto')->willReturnCallback(function () {
            array_unshift($this->metaMedia[self::ACCOUNT_A], $this->media('m_sched', '2026-11-10T06:29:30+0000'));
            return ['id' => 'm_sched', 'status' => 'published'];
        });
        $this->app->instance(FacebookGraphService::class, $mock);

        PublishScheduledPostJob::dispatchSync($post->id);

        $this->assertSame('published', $post->fresh()->status);
        $this->assertGreaterThan($versionBefore, FacebookGraphService::instagramDashboardCacheVersion(self::ACCOUNT_A));
        $this->stats($this->wsA)->assertJsonPath('summary.total_posts', 1);
    }

    public function test_refresh_metrics_refetches_from_meta(): void
    {
        $this->metaMedia[self::ACCOUNT_A] = [$this->media('m1', '2026-11-10T01:00:00+0000', likes: 10)];
        $this->stats($this->wsA)->assertJsonPath('summary.likes', 10);

        // Likes change on Instagram; the cached response is served until "Refresh metrics".
        $this->metaMedia[self::ACCOUNT_A] = [$this->media('m1', '2026-11-10T01:00:00+0000', likes: 42)];
        $this->stats($this->wsA)->assertJsonPath('summary.likes', 10);
        $this->stats($this->wsA, extra: ['force_refresh' => 1])->assertJsonPath('summary.likes', 42);
        $this->posts($this->wsA, extra: ['force_refresh' => 1])->assertJsonPath('data.0.likes_count', 42);
    }

    public function test_meta_failure_is_reported_and_not_cached_as_zero_posts(): void
    {
        $this->metaMedia[self::ACCOUNT_A] = [$this->media('m1', '2026-11-10T01:00:00+0000')];
        $this->metaMediaDown = true;

        $this->stats($this->wsA)->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'Could not load Instagram posts from Meta: Application request limit reached']);
        $this->posts($this->wsA)->assertJsonPath('success', false);

        // Meta recovers: the very next load (no force) shows the real data, not a cached "0 posts".
        $this->metaMediaDown = false;
        $this->stats($this->wsA)->assertJsonPath('success', true)->assertJsonPath('summary.total_posts', 1);
        $this->posts($this->wsA)->assertJsonPath('total', 1);
    }

    public function test_workspaces_are_isolated(): void
    {
        $this->metaMedia[self::ACCOUNT_A] = [$this->media('a1', '2026-11-10T01:00:00+0000')];
        $this->metaMedia[self::ACCOUNT_B] = [
            $this->media('b1', '2026-11-10T02:00:00+0000'),
            $this->media('b2', '2026-11-10T03:00:00+0000'),
        ];
        FacebookPost::create(['workspace_id' => $this->wsB->id, 'content' => 'B', 'status' => 'published', 'post_type' => 'single_image',
            'platforms' => ['Instagram'], 'ig_media_id' => 'b1', 'published_at' => now(), 'created_by_id' => $this->user->id]);

        $a = $this->stats($this->wsA)->assertOk();
        $b = $this->stats($this->wsB)->assertOk();
        $this->assertSame(1, $a->json('summary.total_posts'));
        $this->assertSame(0, $a->json('summary.app_published_posts'));
        $this->assertSame(2, $b->json('summary.total_posts'));
        $this->assertSame(1, $b->json('summary.app_published_posts'));
        $this->assertSame(['a1'], array_column($this->posts($this->wsA)->json('data'), 'id'));
    }

    public function test_drafts_failed_and_scheduled_posts_are_not_counted_and_removed_media_is_reported(): void
    {
        $this->metaMedia[self::ACCOUNT_A] = [$this->media('live1', '2026-11-10T01:00:00+0000')];
        $base = ['workspace_id' => $this->wsA->id, 'content' => 'x', 'post_type' => 'single_image', 'platforms' => ['Instagram'], 'created_by_id' => $this->user->id];
        FacebookPost::create($base + ['status' => 'draft']);
        FacebookPost::create($base + ['status' => 'failed', 'published_at' => now()]);
        FacebookPost::create($base + ['status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
        FacebookPost::create($base + ['status' => 'published', 'ig_media_id' => 'live1', 'published_at' => now()]);
        // Published from the app, then deleted on Instagram: Meta no longer returns it.
        FacebookPost::create($base + ['status' => 'published', 'ig_media_id' => 'deleted1', 'published_at' => now()]);

        $res = $this->stats($this->wsA)->assertOk();
        $this->assertSame(1, $res->json('summary.total_posts'));
        $this->assertSame(2, $res->json('summary.app_published_posts'));
        $this->assertSame(['deleted1'], $res->json('summary.app_published_not_on_meta'));
    }

    public function test_overview_light_mode_returns_instagram_metrics_for_the_instagram_tab(): void
    {
        $this->metaMedia[self::ACCOUNT_A] = [
            $this->media('m1', '2026-11-10T01:00:00+0000', likes: 10, comments: 2),
            $this->media('m2', '2026-11-09T01:00:00+0000', likes: 5, comments: 1),
        ];

        $res = $this->actingAs($this->user)->getJson('/api/v1/dashboard/overview?' . http_build_query([
            'workspace_id' => $this->wsA->id, 'start_date' => '2026-11-01', 'end_date' => '2026-11-10',
            'platform' => 'instagram', 'light' => 1,
        ]))->assertOk();

        $ig = $res->json('data.instagram_metrics');
        $this->assertSame(2, $ig['posts_count']);
        $this->assertSame(300, $ig['views']);   // 150 per media
        $this->assertSame(8, $ig['shares']);    // 4 per media
        $this->assertSame(15, $ig['likes']);
        $this->assertSame(3, $ig['comments']);
        $this->assertSame(1200, $ig['followers_count']);
    }

    public function test_facebook_dashboard_does_not_include_instagram_only_posts(): void
    {
        $page = FacebookPage::create(['workspace_id' => $this->wsA->id, 'page_id' => '555000111', 'page_name' => 'Client A Page', 'page_access_token' => 'EAA_page']);
        $base = ['workspace_id' => $this->wsA->id, 'content' => 'x', 'post_type' => 'single_image', 'status' => 'published', 'published_at' => now(), 'created_by_id' => $this->user->id];
        FacebookPost::create($base + ['platforms' => ['Instagram'], 'ig_media_id' => 'ig_only']);
        FacebookPost::create($base + ['platforms' => ['Facebook'], 'facebook_page_id' => $page->id, 'fb_post_id' => '555000111_1']);

        $fb = $this->actingAs($this->user)->getJson('/api/v1/facebook/dashboard-stats?' . http_build_query([
            'workspace_id' => $this->wsA->id, 'start_date' => '2026-11-10', 'end_date' => '2026-11-10',
        ]))->assertOk();
        $this->assertSame(1, $fb->json('summary.total_posts'));
    }
}
