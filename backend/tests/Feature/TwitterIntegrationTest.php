<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Workspace;
use App\Models\Integration;
use App\Models\FacebookPost;
use App\Models\Post;
use Tests\TestCase;

class TwitterIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Seed user with ID 1 to satisfy foreign key constraints (created_by_id)
        $user = User::create([
            'id' => 1,
            'name' => 'Demo User',
            'email' => 'demo@example.com',
            'password' => bcrypt('password123'),
        ]);

        // Seed workspace 1 to satisfy foreign key constraints
        Workspace::create([
            'id' => 1,
            'name' => 'Demo Workspace',
            'owner_id' => 1,
        ]);

        $this->actingAs($user);
    }

    /**
     * Test the connect redirect initiation.
     */
    public function test_connect_returns_redirect_url()
    {
        $response = $this->getJson('/api/twitter/connect?workspace_id=1');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'url'
        ]);
        
        // In mock mode, the URL goes straight to the callback route
        $this->assertStringContainsString('/api/twitter/callback', $response->json('url'));
    }

    /**
     * Test the OAuth callback simulation.
     */
    public function test_callback_creates_integration_connection()
    {
        // 1. Initiate callback
        $response = $this->get('/api/twitter/callback?code=mock_code&state=' . base64_encode(json_encode(['workspace_id' => 1])));

        $response->assertStatus(200);
        // HTML response should contain window.opener postMessage and closing script
        $this->assertStringContainsString('TWITTER_OAUTH_RESULT', $response->getContent());
        $this->assertStringContainsString('Successfully connected', $response->getContent());

        // 2. Verify database record
        $this->assertDatabaseHas('integrations', [
            'workspace_id' => 1,
            'platform' => 'twitter',
            'account_name' => '@DemoAgency_X',
            'is_connected' => true,
        ]);
    }

    /**
     * Test the status checking endpoint.
     */
    public function test_status_returns_connection_details()
    {
        // 1. Verify not connected status initially
        $response = $this->getJson('/api/twitter/status?workspace_id=1');
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'connected' => false,
        ]);

        // 2. Create connected integration
        Integration::create([
            'workspace_id' => 1,
            'platform' => 'twitter',
            'account_id' => '123456',
            'account_name' => '@DemoAgency_X',
            'is_connected' => true,
            'connection_status' => 'connected',
        ]);

        // 3. Verify connected status response
        $response = $this->getJson('/api/twitter/status?workspace_id=1');
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'connected' => true,
            'data' => [
                'account_name' => '@DemoAgency_X',
                'connection_status' => 'connected',
            ]
        ]);
    }

    /**
     * Test the disconnect endpoint.
     */
    public function test_disconnect_deletes_integration()
    {
        // 1. Setup connected integration
        Integration::create([
            'workspace_id' => 1,
            'platform' => 'twitter',
            'account_id' => '123456',
            'account_name' => '@DemoAgency_X',
            'is_connected' => true,
            'connection_status' => 'connected',
        ]);

        // 2. Call disconnect
        $response = $this->postJson('/api/twitter/disconnect', [
            'workspace_id' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'X (Twitter) disconnected successfully.',
        ]);

        // 3. Verify soft deleted from DB
        $this->assertSoftDeleted('integrations', [
            'workspace_id' => 1,
            'platform' => 'twitter',
        ]);
    }

    /**
     * Test publishing flow with Twitter selected.
     */
    public function test_unified_publishing_publishes_to_twitter()
    {
        // 1. Setup connected integration
        Integration::create([
            'workspace_id' => 1,
            'platform' => 'twitter',
            'account_id' => '123456',
            'account_name' => '@DemoAgency_X',
            'is_connected' => true,
            'connection_status' => 'connected',
        ]);

        // 2. Post content to endpoint
        $response = $this->postJson('/api/v1/facebook/publish-post', [
            'workspace_id' => 1,
            'message' => 'Test Tweet content for social dashboard integration.',
            'platforms' => ['X'],
            'status' => 'published',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // 3. Check records created
        $this->assertDatabaseHas('facebook_posts', [
            'workspace_id' => 1,
            'content' => 'Test Tweet content for social dashboard integration.',
            'status' => 'published',
        ]);

        $this->assertDatabaseHas('posts', [
            'workspace_id' => 1,
            'content' => 'Test Tweet content for social dashboard integration.',
            'status' => 'published',
        ]);
    }
}
