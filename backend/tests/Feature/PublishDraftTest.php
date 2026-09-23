<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Workspace;
use App\Models\FacebookPost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PublishDraftTest extends TestCase
{
    use RefreshDatabase;
    public function test_save_draft_post_without_publishing()
    {
        Storage::fake('public');
        $user = User::first() ?? User::factory()->create();
        $workspace = Workspace::first() ?? Workspace::create(['name' => 'Office Workspace']);

        $response = $this->actingAs($user)->postJson('/api/v1/facebook/publish-post', [
            'workspace_id' => $workspace->id,
            'message'      => 'Office draft post text #marketing',
            'status'       => 'draft',
            'platforms'    => ['Facebook'],
            'post_type'    => 'text',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Draft saved successfully!',
        ]);

        $this->assertDatabaseHas('facebook_posts', [
            'workspace_id' => $workspace->id,
            'content'      => 'Office draft post text #marketing',
            'status'       => 'draft',
        ]);
    }

    public function test_save_draft_carousel_without_publishing()
    {
        Storage::fake('public');
        $user = User::first() ?? User::factory()->create();
        $workspace = Workspace::first() ?? Workspace::create(['name' => 'Office Workspace']);

        $file1 = UploadedFile::fake()->create('slide1.jpg', 100, 'image/jpeg');
        $file2 = UploadedFile::fake()->create('slide2.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $workspace->id,
            'message'      => 'Carousel draft caption',
            'status'       => 'draft',
            'platforms'    => ['Facebook', 'Instagram'],
            'post_type'    => 'multi_image',
            'images'       => [$file1, $file2],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Draft saved successfully!',
        ]);

        $post = FacebookPost::where('workspace_id', $workspace->id)
            ->where('content', 'Carousel draft caption')
            ->first();

        $this->assertNotNull($post);
        $this->assertEquals('draft', $post->status);
        $this->assertEquals('multi_image', $post->post_type);
        $this->assertCount(2, $post->media);
    }

    public function test_save_draft_video_without_publishing()
    {
        Storage::fake('public');
        $user = User::first() ?? User::factory()->create();
        $workspace = Workspace::first() ?? Workspace::create(['name' => 'Office Workspace']);

        $video = UploadedFile::fake()->create('promo.mp4', 1024, 'video/mp4');

        $response = $this->actingAs($user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $workspace->id,
            'message'      => 'Video draft caption',
            'status'       => 'draft',
            'platforms'    => ['YouTube', 'Facebook'],
            'post_type'    => 'video',
            'video'        => $video,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Draft saved successfully!',
        ]);

        $post = FacebookPost::where('workspace_id', $workspace->id)
            ->where('content', 'Video draft caption')
            ->first();

        $this->assertNotNull($post);
        $this->assertEquals('draft', $post->status);
        $this->assertEquals('video', $post->post_type);
    }
}
