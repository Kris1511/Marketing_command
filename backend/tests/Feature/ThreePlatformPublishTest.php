<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Workspace;
use App\Models\FacebookPage;
use App\Models\Integration;
use App\Models\YouTubeConnection;
use App\Models\FacebookPost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ThreePlatformPublishTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake([
            'https://api.cloudinary.com/*' => Http::response(['secure_url' => 'https://res.cloudinary.com/demo/image/upload/sample.jpg'], 200),
        ]);
        $this->user = User::factory()->create();
        $this->workspace = Workspace::create(['name' => 'Redmind Technologies Test']);
    }

    public function test_facebook_text_and_image_publish()
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => 'fb_test_post_101'], 200),
        ]);

        FacebookPage::create([
            'workspace_id'      => $this->workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'EAA_valid_token',
        ]);

        $file = UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg');

        $response = $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'message'      => 'Single image post test',
            'status'       => 'published',
            'platforms'    => ['Facebook'],
            'post_type'    => 'single_image',
            'images'       => [$file],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'partial' => false,
        ]);

        $post = FacebookPost::where('workspace_id', $this->workspace->id)->first();
        $this->assertNotNull($post);
        $this->assertEquals('published', $post->status);
        $this->assertEquals('fb_test_post_101', $post->fb_post_id);
        $this->assertEquals('published', $post->platform_statuses['facebook']['status']);
    }

    public function test_facebook_carousel_publish()
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::sequence()
                ->push(['id' => 'fb_photo_1'], 200)
                ->push(['id' => 'fb_photo_2'], 200)
                ->push(['id' => 'fb_carousel_post_202'], 200),
        ]);

        FacebookPage::create([
            'workspace_id'      => $this->workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'EAA_valid_token',
        ]);

        $file1 = UploadedFile::fake()->create('slide1.jpg', 100, 'image/jpeg');
        $file2 = UploadedFile::fake()->create('slide2.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'message'      => 'Facebook carousel multi-image test',
            'status'       => 'published',
            'platforms'    => ['Facebook'],
            'post_type'    => 'multi_image',
            'images'       => [$file1, $file2],
        ]);

        $response->assertStatus(200);
        $post = FacebookPost::where('workspace_id', $this->workspace->id)->first();
        $this->assertEquals('fb_carousel_post_202', $post->fb_post_id);
        $this->assertEquals('published', $post->platform_statuses['facebook']['status']);
    }

    public function test_instagram_rejects_text_only_post()
    {
        Integration::create([
            'workspace_id'  => $this->workspace->id,
            'platform'      => 'instagram',
            'is_connected'  => true,
            'account_id'    => '17841447923134069',
            'account_name'  => '@redmindtechnologies',
            'refresh_token' => 'IGAA_valid_token',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'message'      => 'Text only should fail on Instagram',
            'status'       => 'published',
            'platforms'    => ['Instagram'],
            'post_type'    => 'text',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'Instagram Graph API requires an image or video to create a post. Please attach media to publish to Instagram.',
        ]);
    }

    public function test_instagram_image_and_carousel_publish()
    {
        Http::fake([
            'https://api.cloudinary.com/*' => Http::response(['secure_url' => 'https://res.cloudinary.com/demo/image/upload/sample.jpg'], 200),
            'https://graph.instagram.com/*' => Http::sequence()
                ->push(['id' => 'ig_item_1'], 200) // container 1
                ->push(['id' => 'ig_item_2'], 200) // container 2
                ->push(['id' => 'ig_parent_container'], 200) // parent carousel
                ->push(['status_code' => 'FINISHED'], 200) // poll status
                ->push(['id' => 'ig_published_carousel_303'], 200), // media_publish
        ]);

        Integration::create([
            'workspace_id'  => $this->workspace->id,
            'platform'      => 'instagram',
            'is_connected'  => true,
            'account_id'    => '17841447923134069',
            'account_name'  => '@redmindtechnologies',
            'refresh_token' => 'IGAA_valid_token',
        ]);

        $file1 = UploadedFile::fake()->create('ig1.jpg', 100, 'image/jpeg');
        $file2 = UploadedFile::fake()->create('ig2.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'message'      => 'Instagram carousel test',
            'status'       => 'published',
            'platforms'    => ['Instagram'],
            'post_type'    => 'multi_image',
            'images'       => [$file1, $file2],
        ]);

        $response->assertStatus(200);
        $post = FacebookPost::where('workspace_id', $this->workspace->id)->first();
        $this->assertEquals('ig_published_carousel_303', $post->ig_media_id);
        $this->assertEquals('published', $post->platform_statuses['instagram']['status']);
    }

    public function test_youtube_video_upload_with_privacy_status()
    {
        $ytConn = YouTubeConnection::create([
            'user_id'          => $this->user->id,
            'channel_id'       => 'UCmu0fcvdaWcEa3_FCNTQ-sQ',
            'channel_name'     => 'RedMind Technologies',
            'access_token'     => 'mock_yt_access_token',
            'token_expires_at' => now()->addHour(),
        ]);

        Integration::create([
            'workspace_id'  => $this->workspace->id,
            'platform'      => 'youtube',
            'is_connected'  => true,
            'account_id'    => 'UCmu0fcvdaWcEa3_FCNTQ-sQ',
            'account_name'  => 'RedMind Technologies',
        ]);

        // Mock YouTubeService to test the end-to-end route handling
        $mockYtService = $this->createMock(\App\Services\YouTubeService::class);
        $mockYtService->expects($this->once())
            ->method('uploadVideo')
            ->with(
                $this->isInstanceOf(YouTubeConnection::class),
                $this->isType('string'),
                $this->equalTo('Sample Video Title'),
                $this->equalTo('Video post test description'),
                $this->equalTo('unlisted')
            )
            ->willReturn([
                'success'        => true,
                'id'             => 'yt_vid_404',
                'url'            => 'https://www.youtube.com/watch?v=yt_vid_404',
                'privacy_status' => 'unlisted',
            ]);

        $this->app->instance(\App\Services\YouTubeService::class, $mockYtService);

        $video = UploadedFile::fake()->create('sample.mp4', 1024, 'video/mp4');

        $response = $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', [
            'workspace_id'    => $this->workspace->id,
            'message'         => 'Video post test description',
            'video_title'     => 'Sample Video Title',
            'youtube_privacy' => 'unlisted',
            'status'          => 'published',
            'platforms'       => ['YouTube'],
            'post_type'       => 'video',
            'video'           => $video,
        ]);

        $response->assertStatus(200);
        $post = FacebookPost::where('workspace_id', $this->workspace->id)->first();
        $this->assertEquals('yt_vid_404', $post->yt_video_id);
        $this->assertEquals('unlisted', $post->youtube_privacy);
        $this->assertEquals('published', $post->platform_statuses['youtube']['status']);
    }

    public function test_multi_platform_partial_failure_reporting()
    {
        // Facebook Page succeeds
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => 'fb_post_505'], 200),
        ]);

        FacebookPage::create([
            'workspace_id'      => $this->workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'EAA_valid_token',
        ]);

        // Instagram integration connected, but let mock throw exception
        Integration::create([
            'workspace_id'  => $this->workspace->id,
            'platform'      => 'instagram',
            'is_connected'  => true,
            'account_id'    => '17841447923134069',
            'account_name'  => '@redmindtechnologies',
            'refresh_token' => 'IGAA_broken_token',
        ]);

        // Mock FacebookGraphService to throw an exception for Instagram
        $mockGraph = $this->createPartialMock(\App\Services\FacebookGraphService::class, ['publishSinglePhoto', 'publishInstagramSinglePhoto']);
        $mockGraph->method('publishSinglePhoto')->willReturn(['id' => 'fb_post_505']);
        $mockGraph->expects($this->any())->method('publishInstagramSinglePhoto')->willThrowException(new \Exception('Meta API Error: Token Expired'));

        $this->app->instance(\App\Services\FacebookGraphService::class, $mockGraph);

        $file = UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg');

        $response = $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'message'      => 'Multi platform partial test',
            'status'       => 'published',
            'platforms'    => ['Facebook', 'Instagram'],
            'post_type'    => 'single_image',
            'images'       => [$file],
        ]);

        // Response should indicate partial = true
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'partial' => true,
        ]);

        $data = $response->json();
        $this->assertEquals('published', $data['platform_statuses']['facebook']['status']);
        $this->assertEquals('failed', $data['platform_statuses']['instagram']['status']);
        $this->assertStringContainsString('Token Expired', $data['platform_statuses']['instagram']['error']);
    }

    public function test_duplicate_submission_protection()
    {
        FacebookPost::create([
            'workspace_id'      => $this->workspace->id,
            'content'           => 'Identical duplicate text content',
            'status'            => 'published',
            'platform_statuses' => ['facebook' => ['status' => 'published', 'id' => 'fb_prev_999']],
            'created_at'        => now()->subSeconds(5),
        ]);

        FacebookPage::create([
            'workspace_id'      => $this->workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'EAA_valid_token',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'message'      => 'Identical duplicate text content',
            'status'       => 'published',
            'platforms'    => ['Facebook'],
            'post_type'    => 'text',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Post already published (duplicate submission prevented).',
        ]);
    }
}
