<?php

namespace Tests\Feature;

use App\Models\FacebookPage;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FacebookGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramPublishFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->workspace = Workspace::create([
            'name'        => 'Test Agency',
            'owner_id'    => $this->user->id,
            'is_personal' => false,
        ]);
    }

    /**
     * Step 1 & 7: When Instagram connection is missing or invalid, publish fails with actual error.
     */
    public function test_instagram_publish_fails_without_connected_account(): void
    {
        $file = UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg');

        $response = $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'message'      => 'Testing Instagram flow',
            'status'       => 'published',
            'platforms'    => ['Instagram'],
            'post_type'    => 'single_image',
            'images'       => [$file],
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('Instagram', $response->json('message'));
    }

    /**
     * Steps 1-6: Successful flow with verified Instagram Business Account ID, container creation,
     * container polling, media_publish, and final media ID.
     */
    public function test_instagram_publish_success_flow_marks_published_with_media_id(): void
    {
        Integration::create([
            'workspace_id'  => $this->workspace->id,
            'platform'      => 'instagram',
            'is_connected'  => true,
            'account_id'    => '17841447923134069',
            'account_name'  => '@testaccount',
            'refresh_token' => 'EAA_valid_ig_token',
        ]);

        $mockGraph = $this->createPartialMock(FacebookGraphService::class, ['publishInstagramSinglePhoto']);
        $mockGraph->expects($this->once())
            ->method('publishInstagramSinglePhoto')
            ->with(
                $this->equalTo('17841447923134069'),
                $this->equalTo('EAA_valid_ig_token'),
                $this->equalTo('Testing Instagram Success Flow #test'),
                $this->anything(),
                $this->equalTo($this->workspace->id)
            )
            ->willReturn([
                'id'     => '17999888777666',
                'status' => 'published',
            ]);

        $this->app->instance(FacebookGraphService::class, $mockGraph);

        $file = UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg');

        $response = $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'message'      => 'Testing Instagram Success Flow #test',
            'status'       => 'published',
            'platforms'    => ['Instagram'],
            'post_type'    => 'single_image',
            'images'       => [$file],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'partial' => false,
            'status'  => 'published',
            'platform_statuses' => [
                'instagram' => [
                    'platform' => 'Instagram',
                    'status'   => 'published',
                    'id'       => '17999888777666',
                ],
            ],
        ]);

        $this->assertDatabaseHas('facebook_posts', [
            'workspace_id' => $this->workspace->id,
            'status'       => 'published',
            'ig_media_id'  => '17999888777666',
        ]);
    }

    /**
     * Step 7: When Instagram publishing fails (e.g. Meta permission error #10),
     * status is marked "failed" with actual error, NOT "published".
     */
    public function test_instagram_publish_failure_marks_status_failed(): void
    {
        Integration::create([
            'workspace_id'  => $this->workspace->id,
            'platform'      => 'instagram',
            'is_connected'  => true,
            'account_id'    => '17841444194617900',
            'account_name'  => '@testfor8639',
            'refresh_token' => 'EAATw2bFGr0QBSUhD4XH',
        ]);

        $mockGraph = $this->createPartialMock(FacebookGraphService::class, ['publishInstagramSinglePhoto']);
        $mockGraph->expects($this->once())
            ->method('publishInstagramSinglePhoto')
            ->willThrowException(new \Exception('(#10) Application does not have permission for this action'));

        $this->app->instance(FacebookGraphService::class, $mockGraph);

        $file = UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg');

        $response = $this->actingAs($this->user)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => $this->workspace->id,
            'message'      => 'Testing Instagram Failure',
            'status'       => 'published',
            'platforms'    => ['Instagram'],
            'post_type'    => 'single_image',
            'images'       => [$file],
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'partial' => false,
            'status'  => 'failed',
            'platform_statuses' => [
                'instagram' => [
                    'platform' => 'Instagram',
                    'status'   => 'failed',
                ],
            ],
        ]);

        $this->assertDatabaseHas('facebook_posts', [
            'workspace_id' => $this->workspace->id,
            'status'       => 'failed',
            'ig_media_id'  => null,
        ]);
    }

    /**
     * Step 2: Test that FacebookGraphService strictly rejects non-HTTPS or localhost media URLs.
     */
    public function test_publish_instagram_single_photo_rejects_non_https_urls(): void
    {
        $service = app(FacebookGraphService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Instagram requires a publicly accessible HTTPS image URL');

        $service->publishInstagramSinglePhoto(
            '17841447923134069',
            'EAAYm1kmogeQBSh',
            'Caption',
            'http://example.com/insecure.jpg',
            $this->workspace->id
        );
    }

    public function test_publish_instagram_single_photo_rejects_localhost_urls(): void
    {
        $service = app(FacebookGraphService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Instagram cannot fetch local URLs');

        $service->publishInstagramSinglePhoto(
            '17841447923134069',
            'EAAYm1kmogeQBSh',
            'Caption',
            'https://localhost:8000/storage/posts/1/image.jpg',
            $this->workspace->id
        );
    }
}
