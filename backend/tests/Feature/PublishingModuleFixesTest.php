<?php

namespace Tests\Feature;

use App\Models\FacebookPost;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\YouTubeConnection;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression tests for the Publishing QA fixes (B1-B16).
 *
 * SAFETY: in-memory SQLite, Storage::fake, Http::fake (every outbound call is recorded and asserted to be
 * empty), a YouTubeService mock that must never upload, and only draft/validation-failure requests.
 * Nothing here publishes or schedules anything.
 */
class PublishingModuleFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Workspace $wsA;
    private Workspace $wsB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(fn () => Http::response(['error' => 'external calls are forbidden in tests'], 500));

        // Any real upload attempt fails the test immediately.
        $yt = $this->createMock(YouTubeService::class);
        $yt->expects($this->never())->method('uploadVideo');
        $this->app->instance(YouTubeService::class, $yt);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->wsA = Workspace::create(['name' => 'Client A']);
        $this->wsB = Workspace::create(['name' => 'Client B']);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function member(Workspace $ws, string $role = 'manager'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $ws->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function img(string $name = 'a.jpg', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'image/jpeg');
    }

    private function save(array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post('/api/v1/facebook/publish-post', array_merge([
            'workspace_id' => $this->wsA->id,
            'message'      => 'Safe local draft',
            'status'       => 'draft',
            'platforms'    => ['Facebook'],
            'post_type'    => 'text',
        ], $over), ['Accept' => 'application/json']);
    }

    // ── B1 ────────────────────────────────────────────────────────────────────
    public function test_missing_status_is_rejected_and_never_defaults_to_published(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->wsA->id,
            'message'      => 'No explicit status',
            'platforms'    => ['Facebook'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(0, FacebookPost::count());
        $this->assertSame(0, Post::count());
    }

    public function test_draft_status_never_calls_a_platform(): void
    {
        $this->save(['platforms' => ['Facebook', 'Instagram', 'YouTube'], 'post_type' => 'video', 'video' => UploadedFile::fake()->create('v.mp4', 500, 'video/mp4')])
            ->assertStatus(201);
        // tearDown asserts Http::assertNothingSent(); the YouTubeService mock asserts it is never used.
        $this->assertSame('draft', FacebookPost::first()->status);
    }

    // ── B2 ────────────────────────────────────────────────────────────────────
    public function test_youtube_channel_of_another_workspace_is_never_used(): void
    {
        // The same user owns a channel, but it belongs to workspace B. Workspace A has none.
        YouTubeConnection::create(['user_id' => $this->admin->id, 'workspace_id' => $this->wsB->id, 'channel_id' => 'UC_B', 'channel_name' => 'B Channel', 'access_token' => 'tok-b']);

        $this->save([
            'status' => 'published', 'platforms' => ['YouTube'], 'post_type' => 'video',
            'video'  => UploadedFile::fake()->create('v.mp4', 500, 'video/mp4'),
        ])->assertStatus(400)->assertJsonFragment(['message' => 'No connected YouTube Channel found for this workspace. Please connect YouTube in Integrations.']);

        $this->assertSame(0, FacebookPost::count());
    }

    // ── B3 ────────────────────────────────────────────────────────────────────
    public function test_member_of_one_workspace_cannot_use_another_workspaces_publishing_data(): void
    {
        $alpha = $this->member($this->wsA);
        $bravoDraft = FacebookPost::create(['workspace_id' => $this->wsB->id, 'post_type' => 'text', 'content' => 'Bravo private', 'status' => 'draft', 'created_by_id' => $this->admin->id]);
        $bravoScheduled = FacebookPost::create(['workspace_id' => $this->wsB->id, 'post_type' => 'text', 'content' => 'Bravo sched', 'status' => 'scheduled', 'scheduled_at' => now()->addDays(3), 'created_by_id' => $this->admin->id]);

        $this->save(['workspace_id' => $this->wsB->id], $alpha)->assertStatus(403);
        $this->actingAs($alpha)->getJson("/api/v1/drafts?workspace_id={$this->wsB->id}")->assertStatus(403);
        $this->actingAs($alpha)->getJson("/api/v1/drafts/{$bravoDraft->id}")->assertStatus(403);
        $this->actingAs($alpha)->deleteJson("/api/v1/drafts/{$bravoDraft->id}")->assertStatus(403);
        $this->actingAs($alpha)->getJson("/api/v1/posts/history?workspace_id={$this->wsB->id}")->assertStatus(403);
        $this->actingAs($alpha)->getJson("/api/v1/scheduled-posts?workspace_id={$this->wsB->id}")->assertStatus(403);
        $this->actingAs($alpha)->deleteJson("/api/v1/scheduled-posts/{$bravoScheduled->id}")->assertStatus(403);
        $this->actingAs($alpha)->postJson("/api/v1/posts/{$bravoDraft->id}/duplicate")->assertStatus(403);
        $this->actingAs($alpha)->postJson("/api/v1/posts/{$bravoDraft->id}/retry")->assertStatus(403);
        $this->actingAs($alpha)->getJson("/api/v1/publishing/calendar?workspace_id={$this->wsB->id}")->assertStatus(403);
        $this->actingAs($alpha)->patchJson("/api/v1/publishing/posts/{$bravoDraft->id}/reschedule", ['scheduled_at' => now()->addDays(5)->toIso8601String()])->assertStatus(403);

        $this->assertDatabaseHas('facebook_posts', ['id' => $bravoDraft->id, 'status' => 'draft', 'deleted_at' => null]);
        $this->assertDatabaseHas('facebook_posts', ['id' => $bravoScheduled->id, 'status' => 'scheduled', 'deleted_at' => null]);
        $this->assertSame(2, FacebookPost::count());
    }

    public function test_member_can_use_their_own_workspace_and_admin_can_use_any(): void
    {
        $alpha = $this->member($this->wsA);

        $this->save([], $alpha)->assertStatus(201);
        $this->actingAs($alpha)->getJson("/api/v1/drafts?workspace_id={$this->wsA->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->save(['workspace_id' => $this->wsB->id])->assertStatus(201); // admin
    }

    public function test_calendar_combined_view_only_lists_the_users_own_workspaces(): void
    {
        $alpha = $this->member($this->wsA);
        FacebookPost::create(['workspace_id' => $this->wsA->id, 'post_type' => 'text', 'content' => 'mine', 'status' => 'draft', 'created_by_id' => $alpha->id]);
        FacebookPost::create(['workspace_id' => $this->wsB->id, 'post_type' => 'text', 'content' => 'theirs', 'status' => 'draft', 'created_by_id' => $this->admin->id]);

        $events = $this->actingAs($alpha)->getJson('/api/v1/publishing/calendar')->assertOk()->json('events');
        $this->assertSame(['mine'], array_column($events, 'content'));
    }

    // ── B4 + B6 ───────────────────────────────────────────────────────────────
    public function test_saving_a_loaded_draft_updates_it_instead_of_creating_a_duplicate_and_keeps_every_field(): void
    {
        $first = $this->save([
            'message' => "Caption body\n\n#alpha #beta", 'internal_title' => 'Spring launch', 'cta' => 'Learn more',
            'hashtags' => '#alpha, #beta', 'video_title' => 'Launch day', 'platforms' => ['Facebook', 'Instagram'],
            'post_type' => 'single_image', 'images' => [$this->img()],
        ])->assertStatus(201);

        $id = $first->json('data.id');
        $first->assertJsonPath('data.internal_title', 'Spring launch')
            ->assertJsonPath('data.cta', 'Learn more')
            ->assertJsonPath('data.hashtags', '#alpha #beta')
            ->assertJsonPath('data.video_title', 'Launch day')
            ->assertJsonPath('data.platforms', ['Facebook', 'Instagram']);

        // Re-open it
        $this->actingAs($this->admin)->getJson("/api/v1/drafts/{$id}")->assertOk()
            ->assertJsonPath('data.internal_title', 'Spring launch')
            ->assertJsonPath('data.platform_list', ['Facebook', 'Instagram'])
            ->assertJsonCount(1, 'data.media');

        // Save again with the draft id and without re-uploading: updated in place, media kept
        $this->save(['draft_id' => $id, 'message' => "Edited\n\n#alpha #beta", 'internal_title' => 'Spring launch v2', 'cta' => 'Shop now',
            'hashtags' => '#alpha #beta', 'platforms' => ['Facebook', 'Instagram'], 'post_type' => 'single_image'])
            ->assertStatus(200)->assertJsonPath('data.id', $id)->assertJsonPath('message', 'Draft updated successfully!');

        $this->assertSame(1, FacebookPost::count());
        $this->assertSame(1, Post::count());
        $draft = FacebookPost::with('media')->find($id);
        $this->assertSame('Spring launch v2', $draft->internal_title);
        $this->assertSame('Shop now', $draft->cta);
        $this->assertCount(1, $draft->media);
        $this->assertSame('Edited' . "\n\n" . '#alpha #beta', $draft->content);
        $this->assertSame(['Facebook', 'Instagram'], Post::first()->platform_list);

        // Replace the media
        $this->save(['draft_id' => $id, 'post_type' => 'multi_image', 'images' => [$this->img('x.jpg'), $this->img('y.jpg')]])->assertStatus(200);
        $this->assertCount(2, FacebookPost::with('media')->find($id)->media);

        // Remove the media
        $this->save(['draft_id' => $id, 'post_type' => 'text', 'clear_media' => 1])->assertStatus(200);
        $this->assertCount(0, FacebookPost::with('media')->find($id)->media);
        $this->assertSame(1, FacebookPost::count());
    }

    public function test_a_draft_id_from_another_workspace_or_a_non_draft_is_not_editable(): void
    {
        $other = FacebookPost::create(['workspace_id' => $this->wsB->id, 'post_type' => 'text', 'content' => 'B draft', 'status' => 'draft', 'created_by_id' => $this->admin->id]);
        $this->save(['draft_id' => $other->id, 'message' => 'hijack'])->assertStatus(404);
        $this->assertSame('B draft', $other->fresh()->content);

        $done = FacebookPost::create(['workspace_id' => $this->wsA->id, 'post_type' => 'text', 'content' => 'live', 'status' => 'published', 'created_by_id' => $this->admin->id]);
        $this->save(['draft_id' => $done->id, 'message' => 'overwrite'])->assertStatus(404);
        $this->assertSame('live', $done->fresh()->content);
    }

    public function test_draft_delete_removes_the_linked_history_row_only(): void
    {
        $id = $this->save()->json('data.id');
        $unrelated = Post::create(['workspace_id' => $this->wsA->id, 'content' => 'other', 'platform_list' => ['Facebook'], 'status' => 'draft', 'approval_status' => 'approved', 'created_by_id' => $this->admin->id]);

        $this->actingAs($this->admin)->deleteJson("/api/v1/drafts/{$id}")->assertOk();

        $this->assertSame(0, FacebookPost::count());
        $this->assertSame([$unrelated->id], Post::pluck('id')->all());
    }

    // ── B7 / B8 ───────────────────────────────────────────────────────────────
    public function test_image_size_limit_is_20mb_with_a_clear_message(): void
    {
        $this->save(['post_type' => 'single_image', 'images' => [$this->img('big.jpg', 21 * 1024)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0' => 'Each image must be 20 MB or smaller.']);
        $this->save(['post_type' => 'single_image', 'images' => [$this->img('ok.jpg', 20 * 1024)]])->assertStatus(201);
    }

    public function test_video_formats_match_the_ui_and_webm_is_rejected_clearly(): void
    {
        foreach (['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska'] as $ext => $mime) {
            $this->save(['post_type' => 'video', 'message' => "v {$ext}", 'video' => UploadedFile::fake()->create("clip.{$ext}", 200, $mime)])->assertStatus(201);
        }

        $this->save(['post_type' => 'video', 'video' => UploadedFile::fake()->create('clip.webm', 200, 'video/webm')])
            ->assertStatus(422)->assertJsonPath('errors.video.0', 'The video must be an MP4, MOV, AVI or MKV file.');
    }

    // ── B9 ────────────────────────────────────────────────────────────────────
    public function test_empty_posts_are_rejected(): void
    {
        $this->save(['message' => ''])->assertStatus(422)->assertJsonPath('errors.message.0', 'Add a caption or attach media before saving.');
        $this->save(['message' => '   '])->assertStatus(422);
        $this->assertSame(0, FacebookPost::count());
    }

    public function test_carousel_requires_between_2_and_10_images(): void
    {
        $this->save(['post_type' => 'multi_image', 'images' => [$this->img()]])->assertStatus(422);
        $this->save(['post_type' => 'multi_image', 'images' => array_map(fn ($i) => $this->img("s{$i}.jpg"), range(1, 11))])->assertStatus(422);
        $this->assertSame(0, FacebookPost::count());

        $this->save(['post_type' => 'multi_image', 'images' => array_map(fn ($i) => $this->img("s{$i}.jpg"), range(1, 10))])->assertStatus(201);
        $this->save(['post_type' => 'multi_image', 'images' => [$this->img('a.jpg'), $this->img('b.jpg')]])->assertStatus(201);
    }

    public function test_incompatible_media_combinations_are_rejected_not_silently_dropped(): void
    {
        $this->save(['post_type' => 'video', 'images' => [$this->img()], 'video' => UploadedFile::fake()->create('v.mp4', 100, 'video/mp4')])->assertStatus(422);
        $this->save(['post_type' => 'video', 'images' => [$this->img()]])->assertStatus(422);
        $this->save(['post_type' => 'text', 'video' => UploadedFile::fake()->create('v.mp4', 100, 'video/mp4')])->assertStatus(422);
        $this->save(['post_type' => 'single_image', 'images' => [$this->img('a.jpg'), $this->img('b.jpg')]])->assertStatus(422);
        $this->assertSame(0, FacebookPost::count());
    }

    public function test_caption_limits_follow_the_selected_platforms(): void
    {
        $long = str_repeat('a', 2201);
        $this->save(['message' => $long, 'platforms' => ['Facebook', 'Instagram']])->assertStatus(422);
        $this->save(['message' => $long, 'platforms' => ['Facebook']])->assertStatus(201);
        $this->save(['message' => str_repeat('a', 5001), 'platforms' => ['YouTube'], 'post_type' => 'video'])->assertStatus(422);
        $this->save(['message' => str_repeat('a', 2200), 'platforms' => ['Instagram']])->assertStatus(201);
    }

    // ── B15 ───────────────────────────────────────────────────────────────────
    public function test_hashtag_format_and_instagram_limit(): void
    {
        $this->save(['hashtags' => '#ok no_hash ###'])->assertStatus(422)->assertJsonStructure(['errors' => ['hashtags']]);
        $this->save(['hashtags' => '#bad-tag'])->assertStatus(422);

        $thirty = implode(' ', array_map(fn ($i) => "#tag{$i}", range(1, 30)));
        $thirtyOne = $thirty . ' #tag31';
        $this->save(['hashtags' => $thirty, 'message' => "x\n\n{$thirty}", 'platforms' => ['Instagram']])->assertStatus(201);
        $this->save(['hashtags' => $thirtyOne, 'message' => "x\n\n{$thirtyOne}", 'platforms' => ['Instagram']])
            ->assertStatus(422)->assertJsonPath('errors.hashtags.0', 'Instagram allows at most 30 hashtags (you have 31).');
        $this->save(['hashtags' => $thirtyOne, 'message' => "x\n\n{$thirtyOne}", 'platforms' => ['Facebook']])->assertStatus(201);

        // Hashtags typed straight into the caption count too
        $this->save(['message' => 'y ' . $thirtyOne, 'platforms' => ['Instagram']])->assertStatus(422);
    }

    // ── B11 ───────────────────────────────────────────────────────────────────
    public function test_cancelling_a_scheduled_post_never_deletes_an_unrelated_legacy_post_with_the_same_id(): void
    {
        $scheduled = FacebookPost::create(['workspace_id' => $this->wsA->id, 'post_type' => 'text', 'content' => 'sched A', 'status' => 'scheduled', 'scheduled_at' => now()->addDays(2), 'created_by_id' => $this->admin->id]);
        // An unrelated legacy post in ANOTHER workspace that happens to share the numeric id.
        $other = new Post(['workspace_id' => $this->wsB->id, 'content' => 'legacy B scheduled', 'platform_list' => ['Facebook'], 'status' => 'scheduled', 'scheduled_at' => now()->addDays(2), 'approval_status' => 'approved', 'created_by_id' => $this->admin->id]);
        $other->id = $scheduled->id;
        $other->save();
        // The post's own linked history row (different id).
        $linked = Post::create(['workspace_id' => $this->wsA->id, 'facebook_post_id' => $scheduled->id, 'content' => 'sched A', 'platform_list' => ['Facebook'], 'status' => 'scheduled', 'approval_status' => 'approved', 'created_by_id' => $this->admin->id]);

        $this->actingAs($this->admin)->deleteJson("/api/v1/scheduled-posts/{$scheduled->id}")->assertOk();

        $this->assertNull(FacebookPost::find($scheduled->id));
        $this->assertNull(Post::find($linked->id), 'The linked history row is removed with its post.');
        $this->assertNotNull(Post::find($other->id), 'Unrelated legacy post in another workspace must survive.');
    }

    public function test_only_scheduled_posts_can_be_cancelled(): void
    {
        $draft = FacebookPost::create(['workspace_id' => $this->wsA->id, 'post_type' => 'text', 'content' => 'd', 'status' => 'draft', 'created_by_id' => $this->admin->id]);
        $this->actingAs($this->admin)->deleteJson("/api/v1/scheduled-posts/{$draft->id}")->assertStatus(422);
        $this->assertNotNull(FacebookPost::find($draft->id));
    }

    // ── B12 ───────────────────────────────────────────────────────────────────
    public function test_duplicate_keeps_the_source_platforms_and_history_never_mixes_same_caption_posts(): void
    {
        $fb = $this->save(['message' => 'same text', 'platforms' => ['Facebook']])->json('data.id');
        $yt = $this->save(['message' => 'same text', 'platforms' => ['YouTube'], 'post_type' => 'video'])->json('data.id');

        $dup = $this->actingAs($this->admin)->postJson("/api/v1/posts/{$yt}/duplicate")->assertOk();
        $this->assertSame(['YouTube'], $dup->json('data.platforms'));
        $this->assertSame(['YouTube'], Post::where('facebook_post_id', $dup->json('data.id'))->first()->platform_list);

        $rows = collect($this->actingAs($this->admin)->getJson("/api/v1/posts/history?workspace_id={$this->wsA->id}&per_page=50")->assertOk()->json('data'));
        $this->assertSame(['Facebook'], $rows->firstWhere('id', $fb)['platform_list']);
        $this->assertSame(['YouTube'], $rows->firstWhere('id', $yt)['platform_list']);
        $this->assertSame(['YouTube'], $rows->firstWhere('id', $dup->json('data.id'))['platform_list']);
    }

    public function test_history_derives_platforms_from_real_data_when_nothing_is_stored(): void
    {
        $row = FacebookPost::create(['workspace_id' => $this->wsA->id, 'post_type' => 'video', 'content' => 'legacy', 'status' => 'published', 'yt_video_id' => 'abc', 'created_by_id' => $this->admin->id]);
        $rows = collect($this->actingAs($this->admin)->getJson("/api/v1/posts/history?workspace_id={$this->wsA->id}")->json('data'));
        $this->assertSame(['YouTube'], $rows->firstWhere('id', $row->id)['platform_list']);
    }

    // ── B14 ───────────────────────────────────────────────────────────────────
    public function test_all_workspaces_cannot_be_used_for_publishing(): void
    {
        $this->save(['workspace_id' => 'all'])->assertStatus(422)
            ->assertJsonPath('message', 'Please select an individual client workspace. "All Workspaces" cannot be used for publishing.');
        $this->save(['workspace_id' => ''])->assertStatus(422);
        $this->save(['workspace_id' => 99999])->assertStatus(404);
        $this->actingAs($this->admin)->getJson('/api/v1/drafts?workspace_id=all')->assertStatus(422);
        $this->assertSame(0, FacebookPost::count());

        // Read-only history overview stays empty instead of mixing clients
        $this->actingAs($this->admin)->getJson('/api/v1/posts/history?workspace_id=all')->assertOk()->assertJsonPath('total', 0);
    }

    // ── B16 ───────────────────────────────────────────────────────────────────
    public function test_legacy_publish_image_alias_keeps_the_request_body(): void
    {
        $this->actingAs($this->admin)->post('/api/v1/facebook/publish-image', [
            'workspace_id' => $this->wsA->id, 'message' => 'via alias', 'status' => 'draft', 'platforms' => ['Facebook'], 'post_type' => 'single_image', 'images' => [$this->img()],
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertDatabaseHas('facebook_posts', ['content' => 'via alias', 'status' => 'draft']);
    }

    // ── Migration back-fill ───────────────────────────────────────────────────
    public function test_migration_backfill_links_each_legacy_row_to_at_most_one_post(): void
    {
        $a = FacebookPost::create(['workspace_id' => $this->wsA->id, 'post_type' => 'text', 'content' => 'dup', 'status' => 'draft', 'created_by_id' => $this->admin->id]);
        $b = FacebookPost::create(['workspace_id' => $this->wsA->id, 'post_type' => 'text', 'content' => 'dup', 'status' => 'draft', 'created_by_id' => $this->admin->id]);
        $p1 = Post::create(['workspace_id' => $this->wsA->id, 'content' => 'dup', 'platform_list' => ['Facebook'], 'status' => 'draft', 'approval_status' => 'approved', 'created_by_id' => $this->admin->id]);
        $p2 = Post::create(['workspace_id' => $this->wsA->id, 'content' => 'dup', 'platform_list' => ['YouTube'], 'status' => 'draft', 'approval_status' => 'approved', 'created_by_id' => $this->admin->id]);

        $migration = require database_path('migrations/2026_11_06_100000_add_draft_fields_to_facebook_posts_and_link_posts.php');
        $migration->up();
        $migration->up(); // idempotent

        $this->assertSame($a->id, $p1->fresh()->facebook_post_id);
        $this->assertSame($b->id, $p2->fresh()->facebook_post_id);
        $this->assertSame(['Facebook'], $a->fresh()->platforms);
        $this->assertSame(['YouTube'], $b->fresh()->platforms);
    }
}
