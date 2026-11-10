<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Workspace;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\FacebookPostMedia;
use App\Models\FacebookPostHistory;
use App\Models\Post;
use App\Models\Integration;
use App\Models\YouTubeConnection;
use App\Services\FacebookGraphService;
use App\Services\YouTubeService;
use App\Jobs\PublishScheduledPostJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * Complete test suite for Scheduled Publishing:
 * - Schedule creation and future timestamp validation
 * - Visual calendar display (scheduled, published, drafts, failed)
 * - Editing & rescheduling scheduled posts
 * - Queue & Scheduler execution with mocked Facebook, Instagram, YouTube APIs
 * - Duplicate publishing prevention
 * - Retry handling on temporary failures vs permanent failures
 * - Workspace isolation
 *
 * STRICT SAFETY: Uses in-memory sqlite testing database, mocked APIs, zero external requests.
 */
class ScheduledPublishingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Workspace $workspace;
    private FacebookPage $fbPage;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        Http::fake([
            'https://api.cloudinary.com/*' => Http::response(['secure_url' => 'https://res.cloudinary.com/demo/image/upload/sample.jpg'], 200),
            'https://graph.facebook.com/*' => Http::response(['id' => 'fb_mock_post_123', 'post_id' => 'fb_mock_post_123'], 200),
        ]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->workspace = Workspace::create(['id' => 1, 'name' => 'Alpha Client', 'status' => 'active']);

        $this->fbPage = FacebookPage::create([
            'workspace_id'      => $this->workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'Alpha Client Page',
            'page_access_token' => 'EAAMockPageAccessTokenValid',
        ]);
    }

    public function test_can_create_scheduled_post_with_future_date_and_dispatches_queue_job()
    {
        Queue::fake();

        $futureDate = Carbon::now('Asia/Kolkata')->addDays(2)->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->admin)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'message'      => 'Exciting announcement coming soon! #marketing #launch',
            'status'       => 'scheduled',
            'scheduled_at' => $futureDate,
            'platforms'    => ['Facebook'],
            'post_type'    => 'text',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Post scheduled successfully!',
        ]);

        $post = FacebookPost::where('workspace_id', $this->workspace->id)->first();
        $this->assertNotNull($post);
        $this->assertEquals('scheduled', $post->status);
        $this->assertNotNull($post->scheduled_at);
        $this->assertNull($post->published_at);
        $this->assertEquals(['Facebook'], $post->platforms);

        // Legacy Post row check
        $legacy = Post::where('facebook_post_id', $post->id)->first();
        $this->assertNotNull($legacy);
        $this->assertEquals('scheduled', $legacy->status);

        // Queue check
        Queue::assertPushed(PublishScheduledPostJob::class, function ($job) use ($post) {
            return $job->postId === $post->id;
        });
    }

    public function test_cannot_schedule_in_the_past()
    {
        $pastDate = Carbon::now('Asia/Kolkata')->subHours(3)->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->admin)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'message'      => 'Past scheduled test',
            'status'       => 'scheduled',
            'scheduled_at' => $pastDate,
            'platforms'    => ['Facebook'],
            'post_type'    => 'text',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'The scheduled date and time must be in the future.',
        ]);

        $this->assertEquals(0, FacebookPost::count());
    }

    public function test_scheduling_requires_scheduled_at_timestamp()
    {
        $response = $this->actingAs($this->admin)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'message'      => 'Missing schedule time test',
            'status'       => 'scheduled',
            'platforms'    => ['Facebook'],
            'post_type'    => 'text',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'A future schedule date and time is required to schedule a post.',
        ]);

        $this->assertEquals(0, FacebookPost::count());
    }

    public function test_cannot_schedule_for_unconnected_social_account()
    {
        // Workspace 2 has no connected Facebook page or Instagram or YouTube
        $workspace2 = Workspace::create(['id' => 2, 'name' => 'Empty Client', 'status' => 'active']);

        $futureDate = Carbon::now('Asia/Kolkata')->addDays(1)->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->admin)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $workspace2->id,
            'message'      => 'Testing unconnected account validation',
            'status'       => 'scheduled',
            'scheduled_at' => $futureDate,
            'platforms'    => ['Facebook'],
            'post_type'    => 'text',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'No connected Facebook Page found for this workspace. Please connect a Facebook Page first.',
        ]);
    }

    public function test_can_load_edit_and_reschedule_scheduled_post()
    {
        Queue::fake();

        $initialSchedule = Carbon::now('Asia/Kolkata')->addDays(3);

        $post = FacebookPost::create([
            'workspace_id'     => $this->workspace->id,
            'facebook_page_id' => $this->fbPage->id,
            'content'          => 'Initial scheduled message',
            'status'           => 'scheduled',
            'scheduled_at'     => $initialSchedule->utc(),
            'post_type'        => 'text',
            'platforms'        => ['Facebook'],
            'created_by_id'    => $this->admin->id,
        ]);

        // 1. Load via GET /scheduled-posts/{id}
        $getRes = $this->actingAs($this->admin)->get("/api/v1/scheduled-posts/{$post->id}");
        $getRes->assertStatus(200);
        $getRes->assertJson([
            'success' => true,
            'data'    => [
                'id'      => $post->id,
                'content' => 'Initial scheduled message',
                'status'  => 'scheduled',
            ],
        ]);

        // 2. Load via GET /drafts/{id} (compatibility)
        $draftRes = $this->actingAs($this->admin)->get("/api/v1/drafts/{$post->id}");
        $draftRes->assertStatus(200);
        $draftRes->assertJson([
            'success' => true,
            'data'    => [
                'id'      => $post->id,
                'content' => 'Initial scheduled message',
                'status'  => 'scheduled',
            ],
        ]);

        // 3. Edit and update in place via publish-post endpoint
        $newSchedule = Carbon::now('Asia/Kolkata')->addDays(5)->format('Y-m-d H:i:s');
        $editRes = $this->actingAs($this->admin)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'draft_id'     => $post->id,
            'message'      => 'Updated scheduled message with hashtags #update',
            'status'       => 'scheduled',
            'scheduled_at' => $newSchedule,
            'platforms'    => ['Facebook'],
            'post_type'    => 'text',
        ]);

        $editRes->assertStatus(200);
        $editRes->assertJson([
            'success' => true,
            'message' => 'Scheduled post updated successfully!',
        ]);

        // Verify row was updated, not duplicated
        $this->assertEquals(1, FacebookPost::where('workspace_id', $this->workspace->id)->count());
        $post->refresh();
        $this->assertEquals('Updated scheduled message with hashtags #update', $post->content);
        $this->assertEquals('scheduled', $post->status);

        // 4. Reschedule via PATCH /publishing/posts/{id}/reschedule
        $rescheduleDate = Carbon::now('Asia/Kolkata')->addDays(7)->format('Y-m-d H:i:s');
        $patchRes = $this->actingAs($this->admin)->patch("/api/v1/publishing/posts/{$post->id}/reschedule", [
            'scheduled_at' => $rescheduleDate,
        ]);

        $patchRes->assertStatus(200);
        $patchRes->assertJson([
            'success' => true,
            'message' => 'Post rescheduled successfully',
        ]);
        $post->refresh();
        $this->assertEquals(Carbon::parse($rescheduleDate, 'Asia/Kolkata')->utc()->toDateTimeString(), $post->scheduled_at->toDateTimeString());

        // Past reschedule should be rejected
        $pastReschedule = Carbon::now('Asia/Kolkata')->subDay()->format('Y-m-d H:i:s');
        $failPatch = $this->actingAs($this->admin)->patch("/api/v1/publishing/posts/{$post->id}/reschedule", [
            'scheduled_at' => $pastReschedule,
        ]);
        $failPatch->assertStatus(422);
    }

    public function test_visual_calendar_returns_scheduled_published_drafts_and_failed()
    {
        $currentMonth = Carbon::now()->format('Y-m');

        // 1. Scheduled Post
        FacebookPost::create([
            'workspace_id'     => $this->workspace->id,
            'facebook_page_id' => $this->fbPage->id,
            'content'          => 'Scheduled Promo',
            'status'           => 'scheduled',
            'scheduled_at'     => Carbon::now()->addDays(2),
            'post_type'        => 'text',
            'platforms'        => ['Facebook'],
            'created_by_id'    => $this->admin->id,
        ]);

        // 2. Published Post
        FacebookPost::create([
            'workspace_id'     => $this->workspace->id,
            'facebook_page_id' => $this->fbPage->id,
            'content'          => 'Published Announcement',
            'status'           => 'published',
            'published_at'     => Carbon::now()->subDays(1),
            'fb_post_id'       => 'fb_pub_1',
            'post_type'        => 'text',
            'platforms'        => ['Facebook'],
            'created_by_id'    => $this->admin->id,
        ]);

        // 3. Draft Post
        FacebookPost::create([
            'workspace_id'     => $this->workspace->id,
            'content'          => 'Draft Idea',
            'status'           => 'draft',
            'post_type'        => 'text',
            'platforms'        => ['Facebook'],
            'created_by_id'    => $this->admin->id,
        ]);

        // 4. Failed Post
        FacebookPost::create([
            'workspace_id'     => $this->workspace->id,
            'facebook_page_id' => $this->fbPage->id,
            'content'          => 'Failed Post Campaign',
            'status'           => 'failed',
            'error_message'    => 'Permissions error #200',
            'post_type'        => 'text',
            'platforms'        => ['Facebook'],
            'created_by_id'    => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get("/api/v1/publishing/calendar?workspace_id={$this->workspace->id}&month={$currentMonth}");

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $events = collect($response->json('events'));
        $this->assertEquals(4, $events->count());

        $statuses = $events->pluck('status')->all();
        $this->assertContains('scheduled', $statuses);
        $this->assertContains('published', $statuses);
        $this->assertContains('draft', $statuses);
        $this->assertContains('failed', $statuses);

        // Verify platforms is array with lowercase strings
        $scheduledEvent = $events->firstWhere('status', 'scheduled');
        $this->assertNotNull($scheduledEvent);
        $this->assertEquals(['facebook'], $scheduledEvent['platforms']);
        $this->assertNotNull($scheduledEvent['scheduled_at']);
    }

    public function test_queue_job_executes_and_publishes_to_facebook_and_youtube_mocked()
    {
        $mockGraph = $this->createMock(FacebookGraphService::class);
        $mockGraph->expects($this->once())
            ->method('publishVideo')
            ->willReturn(['id' => 'fb_video_999', 'post_id' => 'fb_video_999']);
        $this->app->instance(FacebookGraphService::class, $mockGraph);

        $mockYt = $this->createMock(YouTubeService::class);
        $mockYt->expects($this->once())
            ->method('uploadVideo')
            ->willReturn(['id' => 'yt_video_888', 'url' => 'https://youtube.com/watch?v=yt_video_888']);
        $this->app->instance(YouTubeService::class, $mockYt);

        // Create YouTube Connection for workspace
        YouTubeConnection::create([
            'workspace_id'    => $this->workspace->id,
            'channel_id'      => 'UC_test_channel_123',
            'channel_name'    => 'Alpha Channel',
            'access_token'    => 'ya29.mock_token',
            'refresh_token'   => '1//mock_refresh',
        ]);

        // Upload fake video file to storage
        $fakeVideoPath = Storage::disk('public')->path('posts/1/sample.mp4');
        @mkdir(dirname($fakeVideoPath), 0777, true);
        file_put_contents($fakeVideoPath, 'fake-video-bytes');

        $post = FacebookPost::create([
            'workspace_id'     => $this->workspace->id,
            'facebook_page_id' => $this->fbPage->id,
            'content'          => 'Watch our new product demo!',
            'status'           => 'scheduled',
            'scheduled_at'     => Carbon::now()->subMinutes(1),
            'post_type'        => 'video',
            'platforms'        => ['Facebook', 'YouTube'],
            'created_by_id'    => $this->admin->id,
        ]);

        FacebookPostMedia::create([
            'facebook_post_id' => $post->id,
            'media_type'       => 'video',
            'file_path'        => $fakeVideoPath,
            'file_url'         => Storage::disk('public')->url('posts/1/sample.mp4'),
            'sort_order'       => 0,
        ]);

        // Execute Job synchronously
        PublishScheduledPostJob::dispatchSync($post->id);

        $post->refresh();
        $this->assertEquals('published', $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertEquals('fb_video_999', $post->fb_post_id);
        $this->assertEquals('yt_video_888', $post->yt_video_id);

        $this->assertEquals('published', $post->platform_statuses['facebook']['status']);
        $this->assertEquals('published', $post->platform_statuses['youtube']['status']);

        // Check history
        $history = FacebookPostHistory::where('facebook_post_id', $post->id)->first();
        $this->assertNotNull($history);
        $this->assertEquals('published', $history->action);
    }

    public function test_duplicate_publishing_prevention()
    {
        $mockGraph = $this->createMock(FacebookGraphService::class);
        // Only ONE call is allowed across both invocations
        $mockGraph->expects($this->once())
            ->method('publishTextPost')
            ->willReturn(['id' => 'fb_once_101']);
        $this->app->instance(FacebookGraphService::class, $mockGraph);

        $post = FacebookPost::create([
            'workspace_id'     => $this->workspace->id,
            'facebook_page_id' => $this->fbPage->id,
            'content'          => 'Post to be executed once only',
            'status'           => 'scheduled',
            'scheduled_at'     => Carbon::now()->subMinutes(2),
            'post_type'        => 'text',
            'platforms'        => ['Facebook'],
            'created_by_id'    => $this->admin->id,
        ]);

        // First run: executes publish
        PublishScheduledPostJob::dispatchSync($post->id);
        $post->refresh();
        $this->assertEquals('published', $post->status);

        // Second run: duplicate execution attempt must abort immediately
        PublishScheduledPostJob::dispatchSync($post->id);

        $this->assertEquals(1, FacebookPostHistory::where('facebook_post_id', $post->id)->count());
    }

    public function test_retry_handling_for_temporary_api_failures()
    {
        $mockGraph = $this->createMock(FacebookGraphService::class);
        $mockGraph->expects($this->once())
            ->method('publishTextPost')
            ->willThrowException(new \Exception('cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received'));
        $this->app->instance(FacebookGraphService::class, $mockGraph);

        $post = FacebookPost::create([
            'workspace_id'     => $this->workspace->id,
            'facebook_page_id' => $this->fbPage->id,
            'content'          => 'Temporary glitch post',
            'status'           => 'scheduled',
            'scheduled_at'     => Carbon::now()->subMinutes(1),
            'post_type'        => 'text',
            'platforms'        => ['Facebook'],
            'retry_count'      => 0,
            'created_by_id'    => $this->admin->id,
        ]);

        PublishScheduledPostJob::dispatchSync($post->id);

        $post->refresh();
        // Post must NOT be marked failed! It is rescheduled with retry incremented
        $this->assertEquals('scheduled', $post->status);
        $this->assertEquals(1, $post->retry_count);
        $this->assertNull($post->published_at);
        $this->assertTrue($post->scheduled_at->isFuture());

        // History records retry
        $history = FacebookPostHistory::where('facebook_post_id', $post->id)->first();
        $this->assertNotNull($history);
        $this->assertEquals('retried', $history->action);
    }

    public function test_permanent_api_failure_marks_failed()
    {
        $mockGraph = $this->createMock(FacebookGraphService::class);
        $mockGraph->expects($this->once())
            ->method('publishTextPost')
            ->willThrowException(new \Exception('OAuthException: Error validating access token: Session has expired.'));
        $this->app->instance(FacebookGraphService::class, $mockGraph);

        $post = FacebookPost::create([
            'workspace_id'     => $this->workspace->id,
            'facebook_page_id' => $this->fbPage->id,
            'content'          => 'Invalid token post',
            'status'           => 'scheduled',
            'scheduled_at'     => Carbon::now()->subMinutes(1),
            'post_type'        => 'text',
            'platforms'        => ['Facebook'],
            'retry_count'      => 0,
            'created_by_id'    => $this->admin->id,
        ]);

        PublishScheduledPostJob::dispatchSync($post->id);

        $post->refresh();
        $this->assertEquals('failed', $post->status);
        $this->assertNotNull($post->error_message);
        $this->assertStringContainsString('Session has expired', $post->error_message);

        $history = FacebookPostHistory::where('facebook_post_id', $post->id)->first();
        $this->assertNotNull($history);
        $this->assertEquals('failed', $history->action);
    }

    public function test_process_scheduled_console_command()
    {
        $mockGraph = $this->createMock(FacebookGraphService::class);
        $mockGraph->expects($this->once())
            ->method('publishTextPost')
            ->willReturn(['id' => 'fb_artisan_sched_1']);
        $this->app->instance(FacebookGraphService::class, $mockGraph);

        $post = FacebookPost::create([
            'workspace_id'     => $this->workspace->id,
            'facebook_page_id' => $this->fbPage->id,
            'content'          => 'Artisan scheduler post',
            'status'           => 'scheduled',
            'scheduled_at'     => Carbon::now()->subMinutes(5),
            'post_type'        => 'text',
            'platforms'        => ['Facebook'],
            'created_by_id'    => $this->admin->id,
        ]);

        $exitCode = \Illuminate\Support\Facades\Artisan::call('posts:process-scheduled');
        $this->assertEquals(0, $exitCode);

        $post->refresh();
        $this->assertEquals('published', $post->status);
        $this->assertEquals('fb_artisan_sched_1', $post->fb_post_id);
    }

    public function test_workspace_isolation_for_scheduled_posts()
    {
        $workspaceA = Workspace::create(['id' => 10, 'name' => 'Workspace A', 'status' => 'active']);
        $workspaceB = Workspace::create(['id' => 20, 'name' => 'Workspace B', 'status' => 'active']);

        $userA = User::factory()->create(['role' => 'viewer']);
        $workspaceA->update(['owner_id' => $userA->id]);

        $postB = FacebookPost::create([
            'workspace_id'     => $workspaceB->id,
            'content'          => 'Secret Campaign of Workspace B',
            'status'           => 'scheduled',
            'scheduled_at'     => Carbon::now()->addDays(2),
            'post_type'        => 'text',
            'platforms'        => ['Facebook'],
            'created_by_id'    => $this->admin->id,
        ]);

        // User A cannot read Post B
        $response = $this->actingAs($userA)->get("/api/v1/scheduled-posts/{$postB->id}");
        $response->assertStatus(403);

        // User A cannot reschedule Post B
        $patchRes = $this->actingAs($userA)->patch("/api/v1/publishing/posts/{$postB->id}/reschedule", [
            'scheduled_at' => Carbon::now()->addDays(3)->format('Y-m-d H:i:s'),
        ]);
        $patchRes->assertStatus(403);

        // User A cannot cancel/delete Post B
        $delRes = $this->actingAs($userA)->delete("/api/v1/scheduled-posts/{$postB->id}");
        $delRes->assertStatus(403);
    }

    public function test_queue_worker_timeout_exceeds_default_and_retry_after_exceeds_job_timeout()
    {
        $job = new PublishScheduledPostJob(1);

        $this->assertGreaterThanOrEqual(600, $job->timeout);
        $this->assertSame(1, $job->tries);
        $this->assertTrue($job->failOnTimeout);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
    }

    public function test_job_failed_handler_recovers_stuck_processing_post_and_keeps_published_checkpoints()
    {
        $unpublished = FacebookPost::create([
            'workspace_id'     => $this->workspace->id,
            'facebook_page_id' => $this->fbPage->id,
            'content'          => 'Worker timed out before publish',
            'status'           => 'processing',
            'scheduled_at'     => Carbon::now()->subMinutes(1),
            'post_type'        => 'video',
            'platforms'        => ['Facebook'],
            'created_by_id'    => $this->admin->id,
        ]);

        (new PublishScheduledPostJob($unpublished->id))->failed(new \RuntimeException('Worker exceeded timeout'));

        $unpublished->refresh();
        $this->assertSame('failed', $unpublished->status);
        $this->assertStringContainsString('Worker exceeded timeout', (string) $unpublished->error_message);

        $partiallyPublished = FacebookPost::create([
            'workspace_id'     => $this->workspace->id,
            'facebook_page_id' => $this->fbPage->id,
            'content'          => 'Worker timed out after FB succeeded',
            'status'           => 'processing',
            'fb_post_id'       => 'fb_partial_999',
            'scheduled_at'     => Carbon::now()->subMinutes(1),
            'post_type'        => 'video',
            'platforms'        => ['Facebook', 'YouTube'],
            'created_by_id'    => $this->admin->id,
        ]);

        (new PublishScheduledPostJob($partiallyPublished->id))->failed(new \RuntimeException('YouTube upload timed out'));

        $partiallyPublished->refresh();
        $this->assertSame('published', $partiallyPublished->status);
        $this->assertSame('fb_partial_999', $partiallyPublished->fb_post_id);
        $this->assertNotNull($partiallyPublished->published_at);
    }

    public function test_dashboard_priorities_formats_scheduled_post_in_asia_kolkata()
    {
        // Freeze time at 2026-11-10 08:00 IST (02:30 UTC), schedule a post for 10:30 IST (05:00 UTC)
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Kolkata'));
        try {
            FacebookPost::create([
                'workspace_id'     => $this->workspace->id,
                'facebook_page_id' => $this->fbPage->id,
                'content'          => 'IST Dashboard priority post',
                'status'           => 'scheduled',
                'scheduled_at'     => Carbon::parse('2026-11-10 10:30:00', 'Asia/Kolkata')->utc(),
                'post_type'        => 'text',
                'platforms'        => ['Facebook'],
                'created_by_id'    => $this->admin->id,
            ]);

            $response = $this->actingAs($this->admin)->getJson("/api/v1/dashboard/priorities?workspace_id={$this->workspace->id}");
            $response->assertStatus(200);
            $item = collect($response->json('data'))->firstWhere('type', 'scheduled_post');
            $this->assertNotNull($item);
            $this->assertSame('Nov 10 at 10:30 AM IST', $item['description']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
