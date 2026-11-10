<?php

namespace Tests\Feature;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FacebookGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for Facebook Publishing Permission Chain and Meta Error #200 handling.
 *
 * SAFETY: In-memory testing database, Storage::fake, Http::fake.
 * Zero real external requests are made.
 */
class FacebookPublishingPermissionsTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\FakesPublicMediaHost;

    private User $admin;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake([
            'https://api.cloudinary.com/*' => Http::response(['secure_url' => 'https://res.cloudinary.com/demo/image/upload/sample.jpg'], 200),
        ]);

        config([
            'app.key'                             => 'base64:TjZ0avaepo0xutIJPStkZYOlCeaR/ODGhOzg3s0g7fU=',
            'services.facebook.client_id'         => '1731551660900836',
            'services.facebook.client_secret'     => 'cortex_crm_test_secret_abc123',
            'services.facebook.redirect'          => 'https://mc-api.redmindtechnologies.com/api/v1/auth/facebook/callback',
            'services.facebook.publishing_enabled'=> true,
            'services.facebook.preflight'         => false,
        ]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->workspace = Workspace::create(['id' => 1, 'name' => 'Main Workspace', 'status' => 'active']);
    }

    /**
     * 1. Test that OAuth redirect includes pages_show_list, pages_read_engagement, and pages_manage_posts
     */
    public function test_oauth_redirect_requests_required_publishing_permission_chain(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/auth/facebook?workspace_id=1');
        $response->assertOk();

        $url = $response->json('url');
        $this->assertNotEmpty($url);

        // Decode query parameters from the redirect URL
        parse_str(parse_url($url, PHP_URL_QUERY), $queryParams);
        $scopeString = $queryParams['scope'] ?? '';
        $scopes = explode(',', $scopeString);

        // Required permission chain must be present in OAuth scope
        $this->assertContains('pages_show_list', $scopes, 'pages_show_list must be requested');
        $this->assertContains('pages_read_engagement', $scopes, 'pages_read_engagement must be requested');
        $this->assertContains('pages_manage_posts', $scopes, 'pages_manage_posts must be requested when publishing is enabled');
    }

    /**
     * 2. Test that missing pages_manage_posts permission is detected in OAuth callback and flagged clearly
     */
    public function test_oauth_callback_detects_missing_pages_manage_posts_and_does_not_silently_report_clean_success(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'mock_user_token'], 200),
            'https://graph.facebook.com/*/me?*' => Http::response(['id' => 'fb_user_1', 'name' => 'Test User'], 200),
            'https://graph.facebook.com/*/debug_token*' => Http::response([
                'data' => [
                    'is_valid' => true,
                    // Note: pages_manage_posts is purposefully missing from granted scopes
                    'scopes'   => ['public_profile', 'pages_show_list', 'pages_read_engagement'],
                ]
            ], 200),
            'https://graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [
                    [
                        'id'           => '115864121526929',
                        'name'         => 'RedMind Technologies',
                        'access_token' => 'mock_page_token_no_publish',
                        'category'     => 'Software Company',
                        'tasks'        => ['ANALYZE', 'ADVERTISE', 'MESSAGING'], // Lacks CREATE_CONTENT
                    ]
                ]
            ], 200),
        ]);

        $state = $this->getJson('/api/v1/auth/facebook?workspace_id=1')->json('state');
        $response = $this->get('/api/v1/auth/facebook/callback?code=mock_code&state=' . urlencode($state));

        $response->assertOk();
        $body = $response->getContent();

        // Must display warning in HTML popup
        $this->assertStringContainsString('pages_manage_posts', $body);
        $this->assertStringContainsString('Meta App Review', $body);

        // Check postMessage payload for missing permissions and publishing_ready flag
        $this->assertStringContainsString('publishing_ready: false', $body);
        $this->assertStringContainsString('pages_manage_posts', $body);

        // Auto-connected integration in database must record the permission limitation
        $integration = Integration::where('workspace_id', 1)->where('platform', 'facebook')->first();
        $this->assertNotNull($integration);
        $this->assertStringContainsString('pages_manage_posts', (string) $integration->error_message);
        $this->assertStringContainsString('Meta App Review', (string) $integration->error_message);
    }

    /**
     * 3. Test connectPage endpoint validates publishing tasks and records limitations
     */
    public function test_connect_page_flags_missing_publishing_rights_and_validates_tasks(): void
    {
        $sessionId = 'fb_auth_test_' . uniqid();
        Cache::put($sessionId, [
            'workspace_id'   => 1,
            'user_token'     => 'user_tok',
            'user_data'      => ['id' => 'u1', 'name' => 'Admin'],
            'granted_scopes' => ['pages_show_list', 'pages_read_engagement'], // missing pages_manage_posts
            'pages'          => [
                [
                    'id'           => '115864121526929',
                    'name'         => 'RedMind Technologies',
                    'access_token' => 'mock_page_token',
                    'tasks'        => ['ANALYZE', 'MESSAGING'], // missing CREATE_CONTENT
                ]
            ],
        ], now()->addMinutes(15));

        Http::fake([
            'https://graph.facebook.com/*/115864121526929*' => Http::response([
                'id'              => '115864121526929',
                'name'            => 'RedMind Technologies',
                'followers_count' => 50,
                'fan_count'       => 50,
            ], 200),
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/facebook/connect-page', [
            'workspace_id'     => 1,
            'page_id'          => '115864121526929',
            'page_name'        => 'RedMind Technologies',
            'oauth_session_id' => $sessionId,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success'          => true,
            'publishing_ready' => false,
        ]);
        $this->assertContains('pages_manage_posts', $response->json('missing_permissions'));
        $this->assertStringContainsString('pages_manage_posts', $response->json('warning'));

        // Check Integration error_message in database
        $integration = Integration::where('workspace_id', 1)->where('platform', 'facebook')->first();
        $this->assertNotNull($integration);
        $this->assertStringContainsString('pages_manage_posts', (string) $integration->error_message);
    }

    /**
     * 4. Test testConnection reports missing permissions clearly when pages_manage_posts is absent
     */
    public function test_test_connection_reports_missing_publishing_permissions(): void
    {
        FacebookPage::create([
            'workspace_id'      => 1,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'mock_token_no_publish',
            'token_status'      => 'valid',
        ]);

        Http::fake([
            'https://graph.facebook.com/*/115864121526929*' => Http::response([
                'id'              => '115864121526929',
                'name'            => 'RedMind Technologies',
                'followers_count' => 10,
            ], 200),
            'https://graph.facebook.com/*/debug_token*' => Http::response([
                'data' => [
                    'is_valid' => true,
                    'scopes'   => ['pages_show_list', 'pages_read_engagement'], // missing pages_manage_posts
                ]
            ], 200),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/facebook/test?workspace_id=1');
        $response->assertOk();
        $response->assertJson([
            'success'          => true,
            'publishing_ready' => false,
        ]);
        $this->assertContains('pages_manage_posts', $response->json('missing_permissions'));
        $this->assertStringContainsString('pages_manage_posts', $response->json('warning'));
    }

    /**
     * 5. Test that Meta Error #200 on pages_manage_posts is caught and translated with actionable advice
     */
    public function test_publishing_handles_meta_error_200_with_actionable_app_review_message(): void
    {
        FacebookPage::create([
            'workspace_id'      => 1,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'mock_page_token',
            'token_status'      => 'valid',
        ]);

        // Simulate Meta's exact Error #200 response when pages_manage_posts is not available
        Http::fake([
            'https://graph.facebook.com/*/feed' => Http::response([
                'error' => [
                    'message' => '(#200) The permission(s) pages_manage_posts are not available. It could because either they are deprecated or need to be approved by App Review.',
                    'type'    => 'OAuthException',
                    'code'    => 200,
                    'fbtrace_id' => 'AIUw1eWmnkV8dPuu-MjhSDX',
                ]
            ], 403),
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/facebook/publish-post', [
            'workspace_id' => 1,
            'message'      => 'Test Post attempting publish without approved pages_manage_posts',
            'status'       => 'published',
            'platforms'    => ['Facebook'],
            'post_type'    => 'text',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'status'  => 'failed',
        ]);

        $errorMessage = $response->json('message');
        $this->assertStringContainsString('Meta Permission Error (#200)', $errorMessage);
        $this->assertStringContainsString('pages_manage_posts', $errorMessage);
        $this->assertStringContainsString('Meta App Review', $errorMessage);
        $this->assertStringContainsString('Advanced Access', $errorMessage);

        // Verify post record in database is marked failed with clear error message, no duplicates created
        $this->assertSame(1, FacebookPost::count());
        $post = FacebookPost::first();
        $this->assertSame('failed', $post->status);
        $this->assertStringContainsString('pages_manage_posts', $post->error_message);
    }

    /**
     * 6. Test that other integrations (e.g. YouTube, Instagram) remain unaffected
     */
    public function test_other_integrations_remain_unaffected_when_facebook_fails_permission(): void
    {
        FacebookPage::create([
            'workspace_id'      => 1,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'mock_page_token',
        ]);

        Integration::create([
            'workspace_id'      => 1,
            'platform'          => 'instagram',
            'is_connected'      => true,
            'account_id'        => '17841447923134069',
            'account_name'      => '@redmind',
            'refresh_token'     => 'IGAA_valid_token',
        ]);

        // Instagram downloads the image from a public HTTPS URL; serve the faked public disk from one.
        $this->usePublicMediaHost();

        Http::fake([
            'https://api.cloudinary.com/*' => Http::response(['secure_url' => 'https://res.cloudinary.com/demo/image/upload/sample.jpg'], 200),
            // Instagram succeeds
            'https://graph.instagram.com/*' => Http::sequence()
                ->push(['id' => 'ig_container_1'], 200)
                ->push(['status_code' => 'FINISHED'], 200)
                ->push(['id' => 'ig_published_id_100'], 200),
            // Facebook fails with error 200
            'https://graph.facebook.com/*/photos*' => Http::response([
                'error' => [
                    'message' => '(#200) The permission(s) pages_manage_posts are not available.',
                    'type'    => 'OAuthException',
                    'code'    => 200,
                ]
            ], 403),
        ]);

        $file = UploadedFile::fake()->image('post.jpg', 1080, 1080);

        $response = $this->actingAs($this->admin)->post('/api/v1/facebook/publish-post', [
            'workspace_id' => 1,
            'message'      => 'Multi platform partial publish',
            'status'       => 'published',
            'platforms'    => ['Facebook', 'Instagram'],
            'post_type'    => 'single_image',
            'images'       => [$file],
        ]);

        // Partial success: Instagram published, Facebook reported permission error cleanly
        $response->assertOk();
        $this->assertTrue($response->json('partial'));
        $this->assertSame('published', $response->json('platform_statuses.instagram.status'));
        $this->assertSame('failed', $response->json('platform_statuses.facebook.status'));
        $this->assertStringContainsString('pages_manage_posts', $response->json('platform_statuses.facebook.error'));
    }
}
