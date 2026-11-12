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
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Publishing → YouTube visibility: only Public (default) and Private.
 *
 * The REAL YouTubeService runs, with Guzzle's MockHandler as its transport, so each test asserts the exact
 * videos.insert body (status.privacyStatus) the YouTube Data API would receive. Nothing leaves the machine.
 */
class YouTubePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $ws;
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $googleRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->ws = Workspace::create(['name' => 'YouTube Client']);
        YouTubeConnection::create([
            'user_id' => $this->user->id, 'workspace_id' => $this->ws->id, 'channel_id' => 'UC_privacy',
            'channel_name' => 'Client Channel', 'access_token' => 'ya29.test', 'token_expires_at' => now()->addHour(),
        ]);
        Integration::create(['workspace_id' => $this->ws->id, 'platform' => 'youtube', 'is_connected' => true, 'account_id' => 'UC_privacy', 'account_name' => 'Client Channel']);
        $this->fakeGoogleTransport();
    }

    /** Real YouTubeService whose Google client talks to a mock: resumable session, then the uploaded video. */
    private function fakeGoogleTransport(): void
    {
        $this->googleRequests = [];
        $mock = new MockHandler([
            new Response(200, ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=test-session']),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['kind' => 'youtube#video', 'id' => 'yt_vid_1'])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->googleRequests));

        $service = new YouTubeService();
        $prop = new \ReflectionProperty(YouTubeService::class, 'client');
        $prop->getValue($service)->setHttpClient(new \GuzzleHttp\Client(['handler' => $stack]));
        $this->app->instance(YouTubeService::class, $service);
    }

    /** privacyStatus in the videos.insert metadata request, or null when no upload was attempted. */
    private function sentPrivacyStatus(): ?string
    {
        foreach ($this->googleRequests as $entry) {
            $req = $entry['request'];
            if ($req->getMethod() === 'POST' && str_contains((string) $req->getUri(), '/youtube/v3/videos')) {
                $this->assertStringContainsString('uploadType=resumable', (string) $req->getUri());
                $body = json_decode((string) $req->getBody(), true);

                return $body['status']['privacyStatus'] ?? null;
            }
        }

        return null;
    }

    private function video(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 2048));
    }

    private function publish(array $data)
    {
        return $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', array_merge([
            'workspace_id' => $this->ws->id,
            'message'      => 'Video description',
            'video_title'  => 'Video title',
            'platforms'    => ['YouTube'],
            'post_type'    => 'video',
        ], $data), ['Accept' => 'application/json']);
    }

    /** A saved YouTube video post (with its video on the public disk). */
    private function savedPost(string $status, ?string $privacy, array $extra = []): FacebookPost
    {
        $rel = "posts/{$this->ws->id}/saved.mp4";
        Storage::disk('public')->put($rel, "\x00\x00\x00\x18ftypmp42" . str_repeat("\x00", 2048));
        $post = FacebookPost::create(array_merge([
            'workspace_id' => $this->ws->id, 'post_type' => 'video', 'content' => 'Saved video', 'status' => $status,
            'platforms' => ['YouTube'], 'youtube_privacy' => $privacy, 'video_title' => 'Saved title',
        ], $extra));
        FacebookPostMedia::create([
            'facebook_post_id' => $post->id, 'media_type' => 'video', 'sort_order' => 0,
            'file_path' => Storage::disk('public')->path($rel), 'file_url' => "https://api.example.com/storage/{$rel}",
        ]);

        return $post;
    }

    // ── immediate publishing ───────────────────────────────────────────────

    public function test_public_sends_privacy_status_public_to_youtube(): void
    {
        $this->publish(['status' => 'published', 'youtube_privacy' => 'public', 'video' => $this->video()])->assertOk();

        $this->assertSame('public', $this->sentPrivacyStatus());
        $post = FacebookPost::firstOrFail();
        $this->assertSame('public', $post->youtube_privacy);
        $this->assertSame(['published', 'public'], [$post->platform_statuses['youtube']['status'], $post->platform_statuses['youtube']['privacy_status']]);
    }

    public function test_private_sends_privacy_status_private_to_youtube(): void
    {
        $this->publish(['status' => 'published', 'youtube_privacy' => 'private', 'video' => $this->video()])->assertOk();

        $this->assertSame('private', $this->sentPrivacyStatus());
        $this->assertSame('private', FacebookPost::firstOrFail()->youtube_privacy);
    }

    public function test_public_is_the_default_when_no_choice_is_sent(): void
    {
        $this->publish(['status' => 'published', 'video' => $this->video()])->assertOk();

        $this->assertSame('public', $this->sentPrivacyStatus());
        $this->assertSame('public', FacebookPost::firstOrFail()->youtube_privacy);
    }

    public function test_unlisted_and_unknown_values_are_rejected(): void
    {
        foreach (['unlisted', 'friends'] as $value) {
            $this->publish(['status' => 'published', 'youtube_privacy' => $value, 'video' => $this->video()])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['youtube_privacy' => 'YouTube visibility must be Public or Private.']);
        }
        $this->publish(['status' => 'draft', 'youtube_privacy' => 'unlisted', 'video' => $this->video()])->assertStatus(422);

        $this->assertSame(0, FacebookPost::count());
        $this->assertNull($this->sentPrivacyStatus());
    }

    public function test_a_saved_private_choice_is_kept_when_publishing_without_resending_it(): void
    {
        $draft = $this->savedPost('draft', 'private');

        $this->publish(['status' => 'published', 'draft_id' => $draft->id])->assertOk();

        $this->assertSame('private', $this->sentPrivacyStatus());
        $this->assertSame('private', $draft->fresh()->youtube_privacy);
    }

    // ── existing drafts saved as Unlisted ──────────────────────────────────

    public function test_unlisted_draft_is_left_unchanged_until_the_user_chooses(): void
    {
        $draft = $this->savedPost('draft', 'unlisted');

        // Saving the draft without a choice keeps the stored value (no silent switch to Public).
        $this->publish(['status' => 'draft', 'draft_id' => $draft->id, 'message' => 'Edited caption'])->assertOk();
        $this->assertSame('unlisted', $draft->fresh()->youtube_privacy);

        // Scheduling or publishing to YouTube without a choice is refused, and nothing is uploaded.
        foreach (['published', 'scheduled'] as $status) {
            $this->publish(['status' => $status, 'draft_id' => $draft->id, 'scheduled_at' => now()->addDay()->toIso8601String()])
                ->assertStatus(422)
                ->assertJsonPath('errors.youtube_privacy.0', \App\Support\PublishingRules::YOUTUBE_PRIVACY_REQUIRED);
        }
        $this->assertSame(['draft', 'unlisted'], [$draft->fresh()->status, $draft->fresh()->youtube_privacy]);
        $this->assertNull($this->sentPrivacyStatus());

        // Once the user picks Public, it is used.
        $this->publish(['status' => 'published', 'draft_id' => $draft->id, 'youtube_privacy' => 'public'])->assertOk();
        $this->assertSame('public', $this->sentPrivacyStatus());
        $this->assertSame('public', $draft->fresh()->youtube_privacy);
    }

    public function test_column_default_unlisted_on_non_youtube_posts_needs_no_choice(): void
    {
        $draft = FacebookPost::create(['workspace_id' => $this->ws->id, 'post_type' => 'single_image', 'content' => 'FB only', 'status' => 'draft', 'platforms' => ['Facebook'], 'youtube_privacy' => 'unlisted']);

        $this->publish(['status' => 'draft', 'draft_id' => $draft->id, 'platforms' => ['Facebook'], 'post_type' => 'single_image'])->assertOk();

        $this->assertSame('public', $draft->fresh()->youtube_privacy);
        $this->assertNull($this->sentPrivacyStatus());
    }

    // ── scheduled publishing ───────────────────────────────────────────────

    public function test_scheduled_job_respects_public_and_private(): void
    {
        foreach (['public', 'private'] as $privacy) {
            FacebookPost::query()->forceDelete();
            $this->fakeGoogleTransport();
            $post = $this->savedPost('scheduled', $privacy, ['scheduled_at' => now()->subMinute()]);

            (new PublishScheduledPostJob($post->id))->handle();

            $this->assertSame($privacy, $this->sentPrivacyStatus(), "scheduled {$privacy}");
            $fresh = $post->fresh();
            $this->assertSame('published', $fresh->platform_statuses['youtube']['status']);
            $this->assertSame($privacy, $fresh->platform_statuses['youtube']['privacy_status']);
            $this->assertArrayNotHasKey('privacy_note', $fresh->platform_statuses['youtube']);
        }
    }

    public function test_scheduled_job_uploads_old_unlisted_posts_as_private_with_a_note(): void
    {
        $post = $this->savedPost('scheduled', 'unlisted', ['scheduled_at' => now()->subMinute()]);

        (new PublishScheduledPostJob($post->id))->handle();

        $this->assertSame('private', $this->sentPrivacyStatus());
        $yt = $post->fresh()->platform_statuses['youtube'];
        $this->assertSame(['published', 'private'], [$yt['status'], $yt['privacy_status']]);
        $this->assertStringContainsString('no longer offered', $yt['privacy_note']);
        $this->assertSame('unlisted', $post->fresh()->youtube_privacy, 'the stored choice is not rewritten');
    }
}
