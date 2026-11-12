<?php

namespace Tests\Feature;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\FacebookPostMedia;
use App\Models\Integration;
use App\Models\PostPlatformVerification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\YouTubeConnection;
use App\Services\PlatformPostVerifier;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Overview → Latest Posts: thumbnails and per-platform status (Published / Scheduled / Failed / Partially
 * published / Deleted / Unable to verify), with the background deletion check.
 *
 * SAFETY: in-memory SQLite, Storage::fake, a scripted fake Graph API (Http::fake + preventStrayRequests) and a
 * mocked YouTubeService. Nothing is published, deleted or sent anywhere.
 */
class OverviewLatestPostsTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '555';
    private const IG = '1784';

    private User $user;
    private Workspace $ws;

    // Scripted Graph state
    private array $fbListing = [];          // fb post ids on the page
    private array $igListing = [];          // ig media ids on the account
    private array $lookup = [];             // object id => [http, json]
    private ?array $fbControl = null;       // [http, json] override for GET /{page}
    private ?array $igControl = null;
    private bool $fbListingBreaksOnPage2 = false;
    private array $graphCalls = [];
    /** @var \Closure|null youtube getVideosStatus behaviour */
    private ?\Closure $youtubeStatus = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['app.url' => 'https://api.example.com']);
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->ws = Workspace::create(['name' => 'Overview Client']);
        FacebookPage::create(['workspace_id' => $this->ws->id, 'page_id' => self::PAGE, 'page_name' => 'Client Page', 'page_access_token' => 'EAA_page']);
        Integration::create(['workspace_id' => $this->ws->id, 'platform' => 'instagram', 'is_connected' => true, 'account_id' => self::IG, 'account_name' => 'client', 'refresh_token' => 'EAA_ig']);
        $conn = YouTubeConnection::create(['user_id' => $this->user->id, 'workspace_id' => $this->ws->id, 'channel_id' => 'UC_main', 'channel_name' => 'Main', 'access_token' => 'ya29.x', 'token_expires_at' => now()->addYear()]);
        $conn->forceFill(['created_at' => now()->subYear()])->save();
        $this->fakeGraph();
        $this->fakeYouTube();
    }

    // ── fakes ──────────────────────────────────────────────────────────────

    private function jpeg(): string
    {
        $img = imagecreatetruecolor(64, 36);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 200));
        ob_start();
        imagejpeg($img);
        return (string) ob_get_clean();
    }

    private function fakeGraph(): void
    {
        Http::fake(function (HttpRequest $req) {
            $url = $req->url();
            $host = parse_url($url, PHP_URL_HOST);
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $this->graphCalls[] = $req->method() . ' ' . $path . (isset($q['after']) ? '?after=' . $q['after'] : '');
            if ($req->method() !== 'GET') {
                return Http::response(['error' => ['message' => 'writes are not allowed in this test']], 500);
            }
            if (str_contains((string) $host, 'cdn.example.com')) {
                return Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']);
            }
            $node = trim(str_replace('/v23.0', '', $path), '/');
            $node = preg_replace('#^me(/|$)#', self::IG . '$1', $node); // the IG list may address the account as "me"
            if ($node === self::PAGE) {
                return $this->fbControl ? Http::response($this->fbControl[1], $this->fbControl[0]) : Http::response(['id' => self::PAGE]);
            }
            if ($node === self::IG) {
                return $this->igControl ? Http::response($this->igControl[1], $this->igControl[0]) : Http::response(['id' => self::IG]);
            }
            if ($node === self::PAGE . '/published_posts') {
                $items = array_map(fn ($id) => ['id' => $id, 'full_picture' => "https://cdn.example.com/fb/{$id}.jpg"], $this->fbListing);
                if (!isset($q['after'])) {
                    $first = array_slice($items, 0, 2);
                    $rest = array_slice($items, 2);
                    return Http::response(['data' => $first] + ($rest || $this->fbListingBreaksOnPage2
                        ? ['paging' => ['next' => 'https://graph.facebook.com/v23.0/' . self::PAGE . '/published_posts?after=p2&access_token=EAA_page&fields=id']]
                        : []));
                }
                if ($this->fbListingBreaksOnPage2) {
                    return Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503);
                }
                return Http::response(['data' => array_slice($items, 2)]);
            }
            if ($node === self::IG . '/media') {
                return Http::response(['data' => array_map(fn ($id) => ['id' => $id, 'media_type' => 'VIDEO', 'thumbnail_url' => "https://cdn.example.com/ig/{$id}.jpg"], $this->igListing)]);
            }
            if (isset($this->lookup[$node])) {
                return Http::response($this->lookup[$node][1], $this->lookup[$node][0]);
            }
            return Http::response(['error' => ['message' => "unexpected {$path}", 'code' => 1]], 500);
        });
    }

    private function fakeYouTube(): void
    {
        $yt = $this->createMock(YouTubeService::class);
        $yt->method('getVideosStatus')->willReturnCallback(fn ($conn, $ids) => $this->youtubeStatus ? ($this->youtubeStatus)($ids) : []);
        $this->app->instance(YouTubeService::class, $yt);
    }

    private function makePost(array $attrs, array $media = []): FacebookPost
    {
        $post = FacebookPost::create(array_merge([
            'workspace_id' => $this->ws->id, 'post_type' => 'single_image', 'content' => 'Caption',
            'status' => 'published', 'published_at' => now()->subDays(2),
        ], $attrs));
        foreach ($media as $i => $m) {
            FacebookPostMedia::create(array_merge(['facebook_post_id' => $post->id, 'media_type' => 'image', 'sort_order' => $i], $m));
        }
        return $post;
    }

    private function verify(): array
    {
        return app(PlatformPostVerifier::class)->verifyWorkspace($this->ws->id);
    }

    private function state(FacebookPost $post, string $platform): ?PostPlatformVerification
    {
        return PostPlatformVerification::where('facebook_post_id', $post->id)->where('platform', $platform)->first();
    }

    private function notFound(int $code = 100, ?int $sub = 33, string $msg = 'Unsupported get request. Object with ID does not exist'): array
    {
        return [400, ['error' => array_filter(['message' => $msg, 'type' => 'GraphMethodException', 'code' => $code, 'error_subcode' => $sub])]];
    }

    // ── Facebook deletion ──────────────────────────────────────────────────

    public function test_facebook_post_missing_twice_is_confirmed_deleted_and_the_record_is_kept(): void
    {
        $live = $this->makePost(['fb_post_id' => self::PAGE . '_1']);
        $gone = $this->makePost(['fb_post_id' => self::PAGE . '_2', 'content' => 'Deleted on Facebook']);
        $this->fbListing = [self::PAGE . '_1'];
        $this->lookup[self::PAGE . '_2'] = $this->notFound(10, null, '(#10) Object does not exist, cannot be loaded due to missing permission or reviewable feature');

        $this->verify();
        $this->assertSame('exists', $this->state($live, 'facebook')->state);
        $this->assertSame('not_found', $this->state($gone, 'facebook')->state, 'one miss is not enough');

        $this->verify(); // second miss, but too soon
        $this->assertSame('not_found', $this->state($gone, 'facebook')->state);

        $this->travel(11)->minutes();
        $this->verify();
        $v = $this->state($gone, 'facebook');
        $this->assertSame('deleted', $v->state);
        $this->assertNotNull($v->deleted_confirmed_at);
        $this->assertSame(3, $v->not_found_count);

        // The post itself is untouched
        $fresh = FacebookPost::find($gone->id);
        $this->assertSame(['published', 'Deleted on Facebook', self::PAGE . '_2'], [$fresh->status, $fresh->content, $fresh->fb_post_id]);
        // Only reads were made
        $this->assertEmpty(array_filter($this->graphCalls, fn ($c) => !str_starts_with($c, 'GET ')));
    }

    public function test_expired_token_never_marks_posts_deleted(): void
    {
        $post = $this->makePost(['fb_post_id' => self::PAGE . '_9']);
        $this->fbControl = [400, ['error' => ['message' => 'Error validating access token: Session has expired', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 463]]];

        foreach ([0, 11, 30] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->verify();
        }

        $v = $this->state($post, 'facebook');
        $this->assertSame(['unverifiable', 'token_expired', 0], [$v->state, $v->reason, $v->not_found_count]);
        $this->assertFalse(collect($this->graphCalls)->contains(fn ($c) => str_contains($c, 'published_posts')), 'nothing else is attempted with a dead token');
    }

    public function test_incomplete_listing_rate_limits_and_permission_errors_are_unverifiable(): void
    {
        $listed = $this->makePost(['fb_post_id' => self::PAGE . '_1']);
        $absent = $this->makePost(['fb_post_id' => self::PAGE . '_5']);
        $this->fbListing = [self::PAGE . '_1', self::PAGE . '_3', self::PAGE . '_4'];
        $this->fbListingBreaksOnPage2 = true;

        $this->verify();
        $this->assertSame('exists', $this->state($listed, 'facebook')->state);
        $this->assertSame(['unverifiable', 'listing_incomplete'], [$this->state($absent, 'facebook')->state, $this->state($absent, 'facebook')->reason]);

        $this->fbListingBreaksOnPage2 = false;
        $this->lookup[self::PAGE . '_5'] = [400, ['error' => ['message' => '(#4) Application request limit reached', 'code' => 4]]];
        $this->verify();
        $this->assertSame('rate_limited', $this->state($absent, 'facebook')->reason);

        $this->lookup[self::PAGE . '_5'] = [400, ['error' => ['message' => '(#10) This endpoint requires the pages_read_engagement permission', 'code' => 10]]];
        $this->verify();
        $this->assertSame(['unverifiable', 'permission'], [$this->state($absent, 'facebook')->state, $this->state($absent, 'facebook')->reason]);
        $this->assertSame(0, $this->state($absent, 'facebook')->not_found_count);
    }

    public function test_post_of_another_page_is_not_checked_against_the_connected_page(): void
    {
        $other = $this->makePost(['fb_post_id' => '999_1']);
        $this->verify();
        $this->assertSame(['unverifiable', 'account_changed'], [$this->state($other, 'facebook')->state, $this->state($other, 'facebook')->reason]);
    }

    public function test_confirmed_deletion_is_not_undone_by_a_later_failed_check(): void
    {
        $gone = $this->makePost(['fb_post_id' => self::PAGE . '_2']);
        $this->lookup[self::PAGE . '_2'] = $this->notFound();
        $this->verify();
        $this->travel(11)->minutes();
        $this->verify();
        $this->assertSame('deleted', $this->state($gone, 'facebook')->state);

        $this->fbControl = [400, ['error' => ['message' => 'expired', 'code' => 190]]];
        $this->verify();
        $this->assertSame('deleted', $this->state($gone, 'facebook')->state);
    }

    // ── Instagram deletion ─────────────────────────────────────────────────

    public function test_instagram_deletion_needs_a_known_owner(): void
    {
        $recorded = $this->makePost(['ig_media_id' => '17_1', 'platforms' => ['Instagram'], 'platform_statuses' => ['instagram' => ['status' => 'published', 'id' => '17_1', 'account_id' => self::IG]]]);
        $legacy = $this->makePost(['ig_media_id' => '17_2', 'platforms' => ['Instagram'], 'platform_statuses' => ['instagram' => ['status' => 'published', 'id' => '17_2']]]);
        $moved = $this->makePost(['ig_media_id' => '17_3', 'platforms' => ['Instagram'], 'platform_statuses' => ['instagram' => ['status' => 'published', 'id' => '17_3', 'account_id' => '4242']]]);
        foreach (['17_1', '17_2', '17_3'] as $id) {
            $this->lookup[$id] = $this->notFound();
        }

        $this->verify();
        $this->travel(11)->minutes();
        $this->verify();

        $this->assertSame('deleted', $this->state($recorded, 'instagram')->state);
        $this->assertSame(['unverifiable', 'owner_unknown'], [$this->state($legacy, 'instagram')->state, $this->state($legacy, 'instagram')->reason]);
        $this->assertSame(['unverifiable', 'account_changed'], [$this->state($moved, 'instagram')->state, $this->state($moved, 'instagram')->reason]);
    }

    public function test_instagram_post_seen_live_records_its_owner_so_a_later_deletion_is_detectable(): void
    {
        $legacy = $this->makePost(['ig_media_id' => '17_7', 'post_type' => 'video', 'platforms' => ['Instagram'], 'platform_statuses' => ['instagram' => ['status' => 'published', 'id' => '17_7']]],
            [['media_type' => 'video', 'file_path' => "posts/{$this->ws->id}/v.mp4"]]);
        Storage::disk('public')->put("posts/{$this->ws->id}/v.mp4", 'mp4');
        $this->igListing = ['17_7'];

        $this->verify();
        $v = $this->state($legacy, 'instagram');
        $this->assertSame(['exists', self::IG], [$v->state, $v->account_id]);
        // Instagram's generated Reel thumbnail was kept locally (no custom thumbnail uploaded)
        Storage::disk('public')->assertExists("posts/{$this->ws->id}/synced/ig_17_7.jpg");

        // Later it disappears
        $this->igListing = [];
        $this->lookup['17_7'] = $this->notFound();
        $this->verify();
        $this->travel(11)->minutes();
        $this->verify();
        $this->assertSame('deleted', $this->state($legacy, 'instagram')->state);
    }

    // ── YouTube deletion ───────────────────────────────────────────────────

    public function test_youtube_deleted_expired_quota_and_channel_rules(): void
    {
        $live = $this->makePost(['yt_video_id' => 'vid_live', 'post_type' => 'video', 'platforms' => ['YouTube'], 'platform_statuses' => ['youtube' => ['status' => 'published', 'id' => 'vid_live', 'channel_id' => 'UC_main']]]);
        $gone = $this->makePost(['yt_video_id' => 'vid_gone', 'post_type' => 'video', 'platforms' => ['YouTube'], 'platform_statuses' => ['youtube' => ['status' => 'published', 'id' => 'vid_gone', 'channel_id' => 'UC_main']]]);
        $legacy = $this->makePost(['yt_video_id' => 'vid_legacy', 'post_type' => 'video', 'platforms' => ['YouTube'], 'platform_statuses' => ['youtube' => ['status' => 'published', 'id' => 'vid_legacy']]]);
        $otherChannel = $this->makePost(['yt_video_id' => 'vid_other', 'post_type' => 'video', 'platforms' => ['YouTube'], 'platform_statuses' => ['youtube' => ['status' => 'published', 'id' => 'vid_other', 'channel_id' => 'UC_old']]]);
        $this->youtubeStatus = fn ($ids) => ['vid_live' => [
            'upload_status' => 'processed', 'privacy_status' => 'public', 'reason' => null, 'channel_id' => 'UC_main',
            'thumbnail' => 'https://cdn.example.com/yt/live.jpg', 'duration_seconds' => 42, 'vertical' => true, 'views' => 120, 'likes' => 9, 'comments' => 2,
        ]];

        $this->verify();
        $this->travel(11)->minutes();
        $this->verify();

        $this->assertSame('exists', $this->state($live, 'youtube')->state);
        $this->assertSame(120, $this->state($live, 'youtube')->metrics['views']);
        $this->assertSame('deleted', $this->state($gone, 'youtube')->state);
        $this->assertSame('deleted', $this->state($legacy, 'youtube')->state, 'only channel, connected before publishing');
        $this->assertSame('account_changed', $this->state($otherChannel, 'youtube')->reason);

        // Expired OAuth / quota: unverifiable, never deleted
        $fresh = $this->makePost(['yt_video_id' => 'vid_new', 'post_type' => 'video', 'platforms' => ['YouTube'], 'platform_statuses' => ['youtube' => ['status' => 'published', 'id' => 'vid_new', 'channel_id' => 'UC_main']]]);
        $this->youtubeStatus = fn () => throw new \Google\Service\Exception('Invalid Credentials', 401, null, [['reason' => 'authError']]);
        $this->verify();
        $this->travel(11)->minutes();
        $this->verify();
        $this->assertSame(['unverifiable', 'token_expired'], [$this->state($fresh, 'youtube')->state, $this->state($fresh, 'youtube')->reason]);
        $this->assertSame('deleted', $this->state($gone, 'youtube')->state, 'confirmed deletion kept');

        $this->youtubeStatus = fn () => throw new \Google\Service\Exception('quota', 403, null, [['reason' => 'quotaExceeded']]);
        $this->verify();
        $this->assertSame('rate_limited', $this->state($fresh, 'youtube')->reason);
    }

    // ── Overview endpoints ─────────────────────────────────────────────────

    public function test_facebook_latest_posts_thumbnails_and_statuses(): void
    {
        $ws = $this->ws->id;
        foreach (['img.jpg', 'c0.jpg', 'c1.jpg', 'thumbs/custom.jpg', "synced/" . self::PAGE . "_14.jpg", 'v.mp4'] as $f) {
            Storage::disk('public')->put("posts/{$ws}/{$f}", str_ends_with($f, 'mp4') ? 'mp4' : $this->jpeg());
        }
        $image = $this->makePost(['fb_post_id' => self::PAGE . '_10', 'published_at' => now()->subDays(1)], [['file_path' => "posts/{$ws}/img.jpg"]]);
        $carousel = $this->makePost(['fb_post_id' => self::PAGE . '_11', 'post_type' => 'multi_image', 'published_at' => now()->subDays(2)], [
            ['file_path' => "posts/{$ws}/c1.jpg", 'sort_order' => 1], ['file_path' => "posts/{$ws}/c0.jpg", 'sort_order' => 0],
        ]);
        $custom = $this->makePost(['fb_post_id' => self::PAGE . '_12', 'post_type' => 'video', 'thumbnail_path' => "posts/{$ws}/thumbs/custom.jpg", 'published_at' => now()->subDays(3)],
            [['media_type' => 'video', 'file_path' => "posts/{$ws}/v.mp4"]]);
        $frame = $this->makePost(['fb_post_id' => self::PAGE . '_13', 'post_type' => 'video', 'published_at' => now()->subDays(4)], [['media_type' => 'video', 'file_path' => "posts/{$ws}/v.mp4"]]);
        $generated = $this->makePost(['fb_post_id' => self::PAGE . '_14', 'post_type' => 'video', 'published_at' => now()->subDays(5)], [['media_type' => 'video', 'file_path' => "posts/{$ws}/v.mp4"]]);
        $missing = $this->makePost(['fb_post_id' => self::PAGE . '_15', 'published_at' => now()->subDays(6)], [['file_path' => "posts/{$ws}/gone.jpg"]]);
        $scheduled = $this->makePost(['status' => 'scheduled', 'published_at' => null, 'scheduled_at' => now()->addDay(), 'platforms' => ['Facebook']]);
        $failed = $this->makePost(['status' => 'failed', 'published_at' => null, 'platforms' => ['Facebook'], 'error_message' => 'Facebook: token expired', 'platform_statuses' => ['facebook' => ['status' => 'failed', 'error' => 'token expired']]]);

        $rows = collect($this->actingAs($this->user)->getJson("/api/v1/facebook/posts?workspace_id={$ws}&all_posts=1&per_page=50")->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(['image', 'image'], [$rows[$image->id]['thumbnail']['source'], $rows[$image->id]['thumbnail']['kind']]);
        $this->assertStringEndsWith("/api/v1/media/posts/{$ws}/c0.jpg", $rows[$carousel->id]['thumbnail']['url']);
        $this->assertSame(2, $rows[$carousel->id]['thumbnail']['media_count']);
        $this->assertSame('custom', $rows[$custom->id]['thumbnail']['source']);
        $this->assertSame(['video_frame', 'video'], [$rows[$frame->id]['thumbnail']['source'], $rows[$frame->id]['thumbnail']['kind']]);
        $this->assertSame('platform', $rows[$generated->id]['thumbnail']['source']);
        $this->assertSame(['none', 'missing'], [$rows[$missing->id]['thumbnail']['source'], $rows[$missing->id]['thumbnail']['status']]);
        $this->assertSame(['published', 'not_checked'], [$rows[$image->id]['platform_status']['status'], $rows[$image->id]['platform_status']['reason']]);
        $this->assertSame('scheduled', $rows[$scheduled->id]['platform_status']['status']);
        $this->assertSame(['failed', 'failed'], [$rows[$failed->id]['platform_status']['status'], $rows[$failed->id]['status']]);
        $this->assertNull($rows[$failed->id]['permalink']);
    }

    public function test_multi_platform_post_shows_each_platform_status(): void
    {
        $post = $this->makePost([
            'fb_post_id' => self::PAGE . '_20', 'ig_media_id' => '17_20', 'yt_video_id' => 'vid_20', 'post_type' => 'video',
            'platforms' => ['Facebook', 'Instagram', 'YouTube'],
            'platform_statuses' => [
                'facebook' => ['status' => 'published', 'id' => self::PAGE . '_20'],
                'instagram' => ['status' => 'published', 'id' => '17_20', 'account_id' => self::IG],
                'youtube' => ['status' => 'published', 'id' => 'vid_20', 'channel_id' => 'UC_main'],
            ],
        ]);
        $this->fbListing = [self::PAGE . '_20'];
        $this->lookup['17_20'] = $this->notFound();
        $this->youtubeStatus = fn () => ['vid_20' => ['upload_status' => 'processed', 'privacy_status' => 'public', 'reason' => null, 'channel_id' => 'UC_main', 'thumbnail' => null, 'duration_seconds' => 300, 'vertical' => false, 'views' => 1, 'likes' => 0, 'comments' => 0]];
        $this->verify();
        $this->travel(11)->minutes();
        $this->verify();

        $row = collect($this->actingAs($this->user)->getJson("/api/v1/facebook/posts?workspace_id={$this->ws->id}&all_posts=1")->json('data'))->firstWhere('id', $post->id);
        $this->assertSame(
            ['facebook' => 'published', 'instagram' => 'deleted', 'youtube' => 'published'],
            array_map(fn ($s) => $s['status'], $row['statuses'])
        );
        $this->assertSame('partial', $row['status']);
        $this->assertSame('published', $row['platform_status']['status']);

        // Instagram deleted + one failure elsewhere stays distinguishable; an expired token shows "Unable to verify"
        $this->fbControl = [400, ['error' => ['message' => 'expired', 'code' => 190]]];
        $this->verify();
        \Illuminate\Support\Facades\Cache::flush();
        $row = collect($this->actingAs($this->user)->getJson("/api/v1/facebook/posts?workspace_id={$this->ws->id}&all_posts=1")->json('data'))->firstWhere('id', $post->id);
        $this->assertSame(['unknown', 'token_expired'], [$row['platform_status']['status'], $row['platform_status']['reason']]);
        $this->assertSame('Unable to verify', $row['platform_status']['label']);
    }

    public function test_instagram_latest_posts_keep_deleted_app_posts_and_use_custom_thumbnails(): void
    {
        $ws = $this->ws->id;
        Storage::disk('public')->put("posts/{$ws}/thumbs/cover.jpg", $this->jpeg());
        $liveApp = $this->makePost(['ig_media_id' => '17_31', 'post_type' => 'video', 'thumbnail_path' => "posts/{$ws}/thumbs/cover.jpg", 'platforms' => ['Instagram'],
            'platform_statuses' => ['instagram' => ['status' => 'published', 'id' => '17_31', 'account_id' => self::IG]], 'published_at' => now()->subHours(2)]);
        $deleted = $this->makePost(['ig_media_id' => '17_32', 'platforms' => ['Instagram'], 'content' => 'Gone from Instagram',
            'platform_statuses' => ['instagram' => ['status' => 'published', 'id' => '17_32', 'account_id' => self::IG]], 'published_at' => now()->subHours(3)]);
        $this->igListing = ['17_31', '17_30'];
        $this->lookup['17_32'] = $this->notFound();
        $this->verify();
        $this->travel(11)->minutes();
        $this->verify();
        \Illuminate\Support\Facades\Cache::flush();

        // The Instagram media list endpoint (live from Meta) is served by the same fake Graph
        $rows = collect($this->actingAs($this->user)->getJson("/api/v1/instagram/posts?workspace_id={$ws}&all_posts=1&per_page=50")->assertOk()->json('data'));

        $live = $rows->firstWhere('ig_media_id', '17_31');
        $this->assertSame('custom', $live['thumbnail']['source']);
        $this->assertSame('published', $live['platform_status']['status']);
        $notApp = $rows->firstWhere('ig_media_id', '17_30');
        $this->assertSame(['platform', 'https://cdn.example.com/ig/17_30.jpg'], [$notApp['thumbnail']['source'], $notApp['thumbnail']['url']]);
        $gone = $rows->firstWhere('post_id', $deleted->id);
        $this->assertNotNull($gone, 'a deleted post stays in Latest Posts');
        $this->assertSame(['deleted', 'Deleted', 'Gone from Instagram'], [$gone['platform_status']['status'], $gone['platform_status']['label'], $gone['content']]);
        $this->assertNull($gone['likes_count']);
    }

    public function test_youtube_latest_posts_short_detection_thumbnail_fallback_and_deleted(): void
    {
        $short = $this->makePost(['yt_video_id' => 'vid_s', 'post_type' => 'video', 'video_title' => 'My Short', 'platforms' => ['YouTube'],
            'platform_statuses' => ['youtube' => ['status' => 'published', 'id' => 'vid_s', 'channel_id' => 'UC_main']], 'published_at' => now()->subHour()]);
        $gone = $this->makePost(['yt_video_id' => 'vid_g', 'post_type' => 'video', 'platforms' => ['YouTube'],
            'platform_statuses' => ['youtube' => ['status' => 'published', 'id' => 'vid_g', 'channel_id' => 'UC_main']], 'published_at' => now()->subHours(2)]);
        $this->youtubeStatus = fn () => ['vid_s' => ['upload_status' => 'processed', 'privacy_status' => 'public', 'reason' => null, 'channel_id' => 'UC_main',
            'thumbnail' => 'https://i.ytimg.com/vi/vid_s/maxresdefault.jpg', 'duration_seconds' => 30, 'vertical' => true, 'views' => 50, 'likes' => 4, 'comments' => 1]];
        Http::fake(['i.ytimg.com/*' => Http::response('not an image', 404)]); // the local copy fails: the API URL is used
        $this->verify();
        $this->travel(11)->minutes();
        $this->verify();

        $rows = collect($this->actingAs($this->user)->getJson("/api/v1/youtube/published-posts?workspace_id={$this->ws->id}&all_posts=1")->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['Short', 'https://www.youtube.com/shorts/vid_s', 50, 4, 1, null], [
            $rows[$short->id]['formatted_type'], $rows[$short->id]['permalink'], $rows[$short->id]['views_count'],
            $rows[$short->id]['likes_count'], $rows[$short->id]['comments_count'], $rows[$short->id]['shares_count'],
        ]);
        $this->assertSame(['platform', 'https://i.ytimg.com/vi/vid_s/maxresdefault.jpg'], [$rows[$short->id]['thumbnail']['source'], $rows[$short->id]['thumbnail']['url']]);
        $this->assertSame('deleted', $rows[$gone->id]['platform_status']['status']);
    }

    public function test_verification_command_reports_and_dry_run_stores_nothing(): void
    {
        $this->makePost(['fb_post_id' => self::PAGE . '_1']);
        $this->fbListing = [self::PAGE . '_1'];

        $this->artisan('posts:verify-platform-status', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, PostPlatformVerification::count());

        $this->artisan('posts:verify-platform-status')->assertSuccessful()->expectsOutputToContain('facebook:exists: 1');
        $this->assertSame(1, PostPlatformVerification::count());
    }
}
