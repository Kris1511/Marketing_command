<?php

namespace Tests\Feature;

use App\Jobs\PublishScheduledPostJob;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Acceptance criteria:
 *  1. Only explicitly scheduled posts are published automatically (never drafts, unscheduled, cancelled posts).
 *  2. Each scheduled post is published at its own scheduled_at (IST input, UTC storage): never earlier, and in
 *     the first scheduler run (or queue worker pass) at/after that time.
 *
 * Publishing is driven only by backend processes (scheduler command / queue worker), never by an HTTP request.
 * SAFETY: in-memory SQLite, Http::fake + preventStrayRequests (fake Facebook Graph API). Nothing reaches Meta.
 */
class ScheduledPublishingAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $ws;
    /** Captions Facebook received, in order */
    private array $published = [];
    /** Simulates a temporary Meta outage */
    private bool $facebookDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*' => function ($request) {
                if ($this->facebookDown) {
                    return Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503);
                }
                if ($request->method() === 'POST' && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/feed')) {
                    $this->published[] = $request['message'];
                    return Http::response(['id' => '555000111_' . count($this->published)], 200);
                }
                return Http::response(['id' => 'x', 'data' => []], 200);
            },
        ]);
        $this->setIst('2026-11-10 10:00:00');

        $this->user = User::factory()->create(['role' => 'admin']);
        $this->ws = Workspace::create(['name' => 'Acceptance Client']);
        FacebookPage::create(['workspace_id' => $this->ws->id, 'page_id' => '555000111', 'page_name' => 'Page', 'page_access_token' => 'EAA_token']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setIst(string $dateTime): void
    {
        Carbon::setTestNow(Carbon::parse($dateTime, 'Asia/Kolkata'));
    }

    /** The composer's Schedule action (IST wall-clock time) */
    private function schedule(string $caption, string $istDateTime, array $extra = [])
    {
        return $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', array_merge([
            'workspace_id' => $this->ws->id, 'message' => $caption, 'platforms' => ['Facebook'], 'post_type' => 'text',
            'status' => 'scheduled', 'scheduled_at' => $istDateTime,
        ], $extra));
    }

    /** One run of the Laravel scheduler's every-minute task (no HTTP request, no browser) */
    private function schedulerTick(string $istDateTime): void
    {
        $this->setIst($istDateTime);
        $this->artisan('posts:process-scheduled')->assertExitCode(0);
    }

    private function postStatus(int $id): string
    {
        return FacebookPost::withTrashed()->find($id)->status;
    }

    // 1 + 2 ─────────────────────────────────────────────────────────────────

    public function test_post_scheduled_for_1030_is_not_published_at_1029_and_is_published_at_1030(): void
    {
        $id = $this->schedule('Launch at 10:30', '2026-11-10 10:30')->assertCreated()->json('data.id');

        // Stored in UTC: 10:30 IST = 05:00 UTC
        $this->assertSame('2026-11-10 05:00:00', DB::table('facebook_posts')->where('id', $id)->value('scheduled_at'));

        $this->schedulerTick('2026-11-10 10:29:00');
        $this->schedulerTick('2026-11-10 10:29:59');
        // Even a queued job that fires early must not publish (it re-reads scheduled_at)
        (new PublishScheduledPostJob($id))->handle();
        $this->assertSame('scheduled', $this->postStatus($id));
        $this->assertSame([], $this->published);

        $this->schedulerTick('2026-11-10 10:30:00');
        $this->assertSame('published', $this->postStatus($id));
        $this->assertSame(['Launch at 10:30'], $this->published);
        $this->assertSame('2026-11-10 10:30', FacebookPost::find($id)->published_at->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'));
    }

    // 3 ─────────────────────────────────────────────────────────────────────

    public function test_a_draft_never_publishes(): void
    {
        $draftId = $this->schedule('Just a draft', '2026-11-10 10:30', ['status' => 'draft'])->assertCreated()->json('data.id');
        // Even a draft that carries a past time (e.g. a scheduled post saved back as a draft)
        DB::table('facebook_posts')->where('id', $draftId)->update(['scheduled_at' => '2026-11-10 04:00:00']);

        $this->schedulerTick('2026-11-10 11:00:00');
        (new PublishScheduledPostJob($draftId))->handle();
        $this->schedulerTick('2026-11-11 11:00:00');

        $this->assertSame('draft', $this->postStatus($draftId));
        $this->assertSame([], $this->published);

        // The API cannot turn it into a scheduled post except through the Schedule action
        $this->actingAs($this->user)->patchJson("/api/v1/publishing/posts/{$draftId}/reschedule", ['scheduled_at' => '2026-11-10 12:00'])
            ->assertStatus(422);
        $this->schedulerTick('2026-11-10 12:05:00');
        $this->assertSame('draft', $this->postStatus($draftId));
    }

    public function test_a_scheduled_post_saved_back_as_a_draft_is_no_longer_published(): void
    {
        $id = $this->schedule('Changed my mind', '2026-11-10 10:30')->json('data.id');
        $this->schedule('Changed my mind', '2026-11-10 10:30', ['status' => 'draft', 'draft_id' => $id])->assertOk();

        $this->schedulerTick('2026-11-10 10:31:00');
        (new PublishScheduledPostJob($id))->handle(); // the queued job from the original Schedule action

        $this->assertSame('draft', $this->postStatus($id));
        $this->assertSame([], $this->published);
    }

    // 4 ─────────────────────────────────────────────────────────────────────

    public function test_unscheduled_posts_never_publish_automatically(): void
    {
        // A manual publish that failed (it has a time stamp but was never scheduled)
        $failed = FacebookPost::create(['workspace_id' => $this->ws->id, 'content' => 'Manual publish failed', 'post_type' => 'text',
            'status' => 'failed', 'platforms' => ['Facebook'], 'published_at' => now(), 'created_by_id' => $this->user->id]);
        // A "scheduled" row without any scheduled time (never went through the Schedule action)
        $noTime = FacebookPost::create(['workspace_id' => $this->ws->id, 'content' => 'No time', 'post_type' => 'text',
            'status' => 'scheduled', 'platforms' => ['Facebook'], 'created_by_id' => $this->user->id]);

        $this->schedulerTick('2026-11-10 12:00:00');
        (new PublishScheduledPostJob($failed->id))->handle();
        (new PublishScheduledPostJob($noTime->id))->handle();

        $this->assertSame('failed', $this->postStatus($failed->id));
        $this->assertSame('scheduled', $this->postStatus($noTime->id));
        $this->assertSame([], $this->published);

        // The reschedule endpoint cannot schedule them either
        $this->actingAs($this->user)->patchJson("/api/v1/publishing/posts/{$failed->id}/reschedule", ['scheduled_at' => '2026-11-10 13:00'])
            ->assertStatus(422);
        $this->assertSame('failed', $this->postStatus($failed->id));
    }

    public function test_manual_publish_is_independent_and_a_manual_retry_never_becomes_a_scheduled_post(): void
    {
        // Manual Publish posts immediately, without the scheduler
        $this->schedule('Publish now', '', ['status' => 'published', 'scheduled_at' => null])->assertOk();
        $this->assertSame(['Publish now'], $this->published);

        // A failed manual post retried during a temporary Meta outage stays failed: it is not auto-rescheduled
        $failed = FacebookPost::create(['workspace_id' => $this->ws->id, 'content' => 'Retry me', 'post_type' => 'text',
            'status' => 'failed', 'platforms' => ['Facebook'], 'published_at' => now(), 'created_by_id' => $this->user->id]);
        $this->facebookDown = true;
        $this->actingAs($this->user)->postJson("/api/v1/posts/{$failed->id}/retry")->assertStatus(422);
        $this->assertSame('failed', $this->postStatus($failed->id));
        $this->assertNull(FacebookPost::find($failed->id)->scheduled_at);

        $this->schedulerTick('2026-11-10 12:00:00');
        $this->assertSame('failed', $this->postStatus($failed->id));
    }

    // 5 ─────────────────────────────────────────────────────────────────────

    public function test_a_cancelled_post_never_publishes(): void
    {
        $id = $this->schedule('Cancelled', '2026-11-10 10:30')->json('data.id');
        $this->actingAs($this->user)->deleteJson("/api/v1/scheduled-posts/{$id}")->assertOk();

        $this->schedulerTick('2026-11-10 10:30:00');
        (new PublishScheduledPostJob($id))->handle(); // its queued job still fires
        $this->schedulerTick('2026-11-10 11:30:00');

        $this->assertSame([], $this->published);
        $this->assertNull(FacebookPost::find($id), 'cancelled post is removed from the schedule');
        $this->assertNull(FacebookPost::withTrashed()->find($id)->published_at);
    }

    // 6 ─────────────────────────────────────────────────────────────────────

    public function test_a_post_rescheduled_later_respects_its_new_time(): void
    {
        $id = $this->schedule('Moved later', '2026-11-10 10:30')->json('data.id');
        // Rescheduled in the composer to 11:15 (the 10:30 queued job still exists)
        $this->schedule('Moved later', '2026-11-10 11:15', ['draft_id' => $id])->assertOk();

        $this->schedulerTick('2026-11-10 10:30:00');
        (new PublishScheduledPostJob($id))->handle(); // the old 10:30 job fires
        $this->schedulerTick('2026-11-10 11:14:59');
        $this->assertSame('scheduled', $this->postStatus($id));
        $this->assertSame([], $this->published);

        $this->schedulerTick('2026-11-10 11:15:00');
        $this->assertSame('published', $this->postStatus($id));
        $this->assertSame(['Moved later'], $this->published);
    }

    public function test_reschedule_endpoint_moves_only_the_time_of_a_scheduled_post(): void
    {
        $id = $this->schedule('Moved via API', '2026-11-10 10:30')->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/publishing/posts/{$id}/reschedule", ['scheduled_at' => '2026-11-10 12:00'])->assertOk();

        $this->schedulerTick('2026-11-10 10:30:00');
        (new PublishScheduledPostJob($id))->handle();
        $this->assertSame('scheduled', $this->postStatus($id));

        $this->schedulerTick('2026-11-10 12:00:00');
        $this->assertSame('published', $this->postStatus($id));
        $this->assertCount(1, $this->published);
    }

    public function test_a_post_rescheduled_earlier_publishes_at_the_new_time_and_only_once(): void
    {
        $id = $this->schedule('Moved earlier', '2026-11-10 12:00')->json('data.id');
        $this->schedule('Moved earlier', '2026-11-10 10:45', ['draft_id' => $id])->assertOk();

        $this->schedulerTick('2026-11-10 10:45:00');
        $this->assertSame('published', $this->postStatus($id));

        $this->setIst('2026-11-10 12:00:00');
        (new PublishScheduledPostJob($id))->handle(); // the old 12:00 job fires later
        $this->schedulerTick('2026-11-10 12:01:00');
        $this->assertSame(['Moved earlier'], $this->published, 'published exactly once');
    }

    // 7 ─────────────────────────────────────────────────────────────────────

    public function test_publishing_runs_from_the_backend_queue_worker_with_no_browser_or_http_request(): void
    {
        config(['queue.default' => 'database']);
        $id = $this->schedule('Queued', '2026-11-10 10:30')->json('data.id');
        $this->assertSame(1, DB::table('jobs')->count(), 'a delayed job is queued for 10:30');

        // A queue worker running at 10:29 does not even receive the job (it is delayed until 10:30)
        $this->setIst('2026-11-10 10:29:00');
        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);
        $this->assertSame('scheduled', $this->postStatus($id));

        // At 10:30 the worker runs it and the job publishes
        $this->setIst('2026-11-10 10:30:00');
        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);
        $this->assertSame('published', $this->postStatus($id));
        $this->assertSame(['Queued'], $this->published);
        $this->assertSame(0, DB::table('jobs')->count());

        // The every-minute scheduler run afterwards does not publish it again
        $this->schedulerTick('2026-11-10 10:31:00');
        $this->assertSame(['Queued'], $this->published);
    }

    public function test_laravel_scheduler_checks_every_minute_in_ist(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'posts:process-scheduled'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertSame('Asia/Kolkata', $event->timezone);
        $this->setIst('2026-11-10 10:30:00');
        $this->assertTrue($event->isDue($this->app));
    }

    // 8 ─────────────────────────────────────────────────────────────────────

    public function test_multiple_posts_publish_at_their_own_times(): void
    {
        $a = $this->schedule('A 10:30', '2026-11-10 10:30')->json('data.id');
        $b = $this->schedule('B 10:31', '2026-11-10 10:31')->json('data.id');
        $c = $this->schedule('C 23:45', '2026-11-10 23:45')->json('data.id');
        $d = $this->schedule('D 02:00 next day', '2026-11-11 02:00')->json('data.id'); // 20:30 UTC on 10 Nov

        $expect = [
            '2026-11-10 10:29:59' => [],
            '2026-11-10 10:30:00' => ['A 10:30'],
            '2026-11-10 10:30:59' => ['A 10:30'],
            '2026-11-10 10:31:00' => ['A 10:30', 'B 10:31'],
            '2026-11-10 23:44:00' => ['A 10:30', 'B 10:31'],
            '2026-11-10 23:45:00' => ['A 10:30', 'B 10:31', 'C 23:45'],
            '2026-11-11 01:59:00' => ['A 10:30', 'B 10:31', 'C 23:45'],
            '2026-11-11 02:00:00' => ['A 10:30', 'B 10:31', 'C 23:45', 'D 02:00 next day'],
            '2026-11-11 03:00:00' => ['A 10:30', 'B 10:31', 'C 23:45', 'D 02:00 next day'],
        ];
        foreach ($expect as $tick => $published) {
            $this->schedulerTick($tick);
            $this->assertSame($published, $this->published, "after the {$tick} IST run");
        }
        foreach ([$a, $b, $c, $d] as $id) {
            $post = FacebookPost::find($id);
            $this->assertSame('published', $post->status);
            $this->assertTrue($post->published_at->gte($post->scheduled_at));
        }
    }

    // Failed-job safety ─────────────────────────────────────────────────────

    public function test_an_unexpected_error_after_publishing_never_publishes_the_post_twice(): void
    {
        $id = $this->schedule('Crash after publish', '2026-11-10 10:30')->json('data.id');
        Schema::drop('facebook_post_history'); // the job fails after Facebook accepted the post

        $this->schedulerTick('2026-11-10 10:30:00');
        $post = FacebookPost::find($id);
        $this->assertSame('published', $post->status);
        $this->assertNotNull($post->fb_post_id);

        $this->schedulerTick('2026-11-10 10:31:00');
        (new PublishScheduledPostJob($id))->handle(); // a queue retry of the same job
        $this->assertSame(['Crash after publish'], $this->published);
    }

    public function test_an_unexpected_error_mid_publish_marks_the_post_failed_instead_of_leaving_it_processing(): void
    {
        $id = $this->schedule('Crash before publish', '2026-11-10 10:30')->json('data.id');
        Schema::drop('posts'); // the job fails while loading the post, before any platform call

        $this->schedulerTick('2026-11-10 10:30:00');
        $post = FacebookPost::find($id);
        $this->assertSame('failed', $post->status, 'not left in "processing"');
        $this->assertStringContainsString('Publishing stopped unexpectedly', $post->error_message);

        // A failed post is never picked up again automatically (only an explicit Retry can publish it)
        $this->schedulerTick('2026-11-10 10:35:00');
        (new PublishScheduledPostJob($id))->handle();
        $this->assertSame('failed', FacebookPost::find($id)->status);
        $this->assertSame([], $this->published);
    }
}
