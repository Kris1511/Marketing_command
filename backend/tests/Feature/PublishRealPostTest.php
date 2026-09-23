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

class PublishRealPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_fails_when_no_facebook_page_connected()
    {
        Storage::fake('public');
        $user = User::first() ?? User::factory()->create();
        $workspace = Workspace::first() ?? Workspace::create(['name' => 'Test Workspace']);

        $response = $this->actingAs($user)->postJson('/api/v1/facebook/publish-post', [
            'workspace_id' => $workspace->id,
            'message'      => 'Hello world live publish',
            'status'       => 'published',
            'platforms'    => ['Facebook'],
            'post_type'    => 'text',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'No connected Facebook Page found for this workspace. Please connect a Facebook Page first.',
        ]);
    }

    public function test_publish_fails_when_instagram_lacks_media()
    {
        Storage::fake('public');
        $user = User::first() ?? User::factory()->create();
        $workspace = Workspace::first() ?? Workspace::create(['name' => 'Test Workspace']);

        // Connect Instagram Integration so IG connection check passes
        Integration::create([
            'workspace_id'      => $workspace->id,
            'platform'          => 'instagram',
            'is_connected'      => true,
            'account_id'        => 'ig_test_account_123',
            'account_name'      => 'TestInstaAccount',
            'refresh_token'     => 'IGAA_test_token',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/facebook/publish-post', [
            'workspace_id' => $workspace->id,
            'message'      => 'Text only on Instagram should fail',
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

    public function test_publish_fails_when_youtube_not_connected()
    {
        Storage::fake('public');
        $user = User::first() ?? User::factory()->create();
        $workspace = Workspace::first() ?? Workspace::create(['name' => 'Test Workspace']);

        $video = UploadedFile::fake()->create('video.mp4', 500, 'video/mp4');

        $response = $this->actingAs($user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $workspace->id,
            'message'      => 'Testing YouTube missing connection',
            'status'       => 'published',
            'platforms'    => ['YouTube'],
            'post_type'    => 'video',
            'video'        => $video,
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'No connected YouTube Channel found for this workspace. Please connect YouTube in Integrations.',
        ]);
    }

    public function test_publish_fails_when_unsupported_platform_selected()
    {
        Storage::fake('public');
        $user = User::first() ?? User::factory()->create();
        $workspace = Workspace::first() ?? Workspace::create(['name' => 'Test Workspace']);

        $response = $this->actingAs($user)->postJson('/api/v1/facebook/publish-post', [
            'workspace_id' => $workspace->id,
            'message'      => 'Testing unsupported platform selection',
            'status'       => 'published',
            'platforms'    => ['X'],
            'post_type'    => 'text',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'Please select at least one target platform (Facebook, Instagram, or YouTube).',
        ]);
    }

    public function test_publish_real_facebook_post_success()
    {
        Storage::fake('public');
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => 'fb_live_post_999'], 200),
        ]);

        $user = User::first() ?? User::factory()->create();
        $workspace = Workspace::first() ?? Workspace::create(['name' => 'Test Workspace']);

        FacebookPage::create([
            'workspace_id'      => $workspace->id,
            'page_id'           => '123456789',
            'page_name'         => 'Test Page',
            'page_access_token' => 'EAA_valid_test_token',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/facebook/publish-post', [
            'workspace_id' => $workspace->id,
            'message'      => 'Live announcement post #launch',
            'status'       => 'published',
            'platforms'    => ['Facebook'],
            'post_type'    => 'text',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertDatabaseHas('facebook_posts', [
            'workspace_id' => $workspace->id,
            'content'      => 'Live announcement post #launch',
            'status'       => 'published',
            'fb_post_id'   => 'fb_live_post_999',
        ]);
    }

    public function test_publish_real_carousel_post_success()
    {
        Storage::fake('public');
        Http::fake([
            'https://graph.facebook.com/*' => Http::sequence()
                ->push(['id' => 'photo_media_1'], 200)
                ->push(['id' => 'photo_media_2'], 200)
                ->push(['id' => 'carousel_post_123'], 200)
                ->whenEmpty(Http::response(['id' => 'dummy_id'], 200)),
        ]);

        $user = User::first() ?? User::factory()->create();
        $workspace = Workspace::first() ?? Workspace::create(['name' => 'Test Workspace']);

        FacebookPage::create([
            'workspace_id'      => $workspace->id,
            'page_id'           => '123456789',
            'page_name'         => 'Test Page',
            'page_access_token' => 'EAA_valid_test_token',
        ]);

        $file1 = UploadedFile::fake()->create('slide1.jpg', 100, 'image/jpeg');
        $file2 = UploadedFile::fake()->create('slide2.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $workspace->id,
            'message'      => 'Live carousel publish #slides',
            'status'       => 'published',
            'platforms'    => ['Facebook'],
            'post_type'    => 'multi_image',
            'images'       => [$file1, $file2],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $post = FacebookPost::where('workspace_id', $workspace->id)
            ->where('content', 'Live carousel publish #slides')
            ->first();

        $this->assertNotNull($post);
        $this->assertEquals('published', $post->status);
        $this->assertEquals('multi_image', $post->post_type);
        $this->assertCount(2, $post->media);
    }
}
