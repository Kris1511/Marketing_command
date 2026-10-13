<?php

namespace Tests\Feature;

use App\Exceptions\GoogleOAuthException;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\GoogleAnalyticsService;
use App\Services\GoogleOAuthService;
use App\Services\GoogleSearchConsoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleOAuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::create([
            'name'     => 'Test Workspace',
            'owner_id' => $this->user->id,
            'status'   => 'active',
        ]);
    }

    /**
     * 1. Test Auth URLs always include access_type=offline and prompt=consent
     */
    public function test_auth_urls_contain_offline_access_and_prompt_consent()
    {
        $gaService = app(GoogleAnalyticsService::class);
        $gaUrl = $gaService->createAuthUrl($this->workspace->id);
        $this->assertStringContainsString('access_type=offline', $gaUrl);
        $this->assertStringContainsString('prompt=consent', $gaUrl);

        $gscService = app(GoogleSearchConsoleService::class);
        $gscUrl = $gscService->createAuthUrl($this->workspace->id);
        $this->assertStringContainsString('access_type=offline', $gscUrl);
        $this->assertStringContainsString('prompt=consent', $gscUrl);
    }

    /**
     * 2. Test refresh token encryption and hidden attribute
     */
    public function test_refresh_token_is_encrypted_and_hidden_from_json()
    {
        $integ = new Integration([
            'workspace_id'      => $this->workspace->id,
            'platform'          => 'google_analytics',
            'account_id'        => '538133379',
            'refresh_token'     => '1//0gSecretTestRefreshToken12345',
            'connection_status' => 'connected',
            'is_connected'      => true,
        ]);

        // Raw attribute in database model should not be plaintext
        $rawAttributes = $integ->getAttributes();
        $this->assertNotEquals('1//0gSecretTestRefreshToken12345', $rawAttributes['refresh_token']);

        // Model accessor decrypts seamlessly
        $this->assertEquals('1//0gSecretTestRefreshToken12345', $integ->refresh_token);

        // JSON serialization hides refresh_token
        $json = $integ->toArray();
        $this->assertArrayNotHasKey('refresh_token', $json);
    }

    /**
     * 3. Test automatic access token refresh with rotated refresh token persistence
     */
    public function test_automatic_token_refresh_and_rotated_token_persistence()
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token'  => 'ya29.new_fresh_simulated_access_token',
                'expires_in'    => 3600,
                'token_type'    => 'Bearer',
                'refresh_token' => '1//0gRotatedNewRefreshToken9999',
            ], 200),
        ]);

        Integration::create([
            'workspace_id'            => $this->workspace->id,
            'platform'                => 'google_analytics',
            'account_id'              => '538133379',
            'refresh_token'           => '1//0gInitialRefreshToken',
            'connection_status'       => 'connected',
            'is_connected'            => true,
            'access_token_expires_at' => now()->subMinutes(10), // expired
        ]);

        $oauthService = app(GoogleOAuthService::class);
        $token = $oauthService->getValidAccessToken('google_analytics', $this->workspace->id);

        $this->assertEquals('ya29.new_fresh_simulated_access_token', $token);

        // Reload integration from database
        $freshInteg = Integration::where('workspace_id', $this->workspace->id)
            ->where('platform', 'google_analytics')
            ->first();

        $this->assertEquals('1//0gRotatedNewRefreshToken9999', $freshInteg->refresh_token);
        $this->assertEquals('connected', $freshInteg->connection_status);
        $this->assertTrue($freshInteg->is_connected);
        $this->assertNotNull($freshInteg->access_token_expires_at);
        $this->assertTrue($freshInteg->access_token_expires_at->isFuture());
    }

    /**
     * 4. Test invalid_grant marks integration as expired and throws GoogleOAuthException
     */
    public function test_invalid_grant_marks_integration_as_expired()
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error'             => 'invalid_grant',
                'error_description' => 'Bad Request',
            ], 400),
        ]);

        Integration::create([
            'workspace_id'            => $this->workspace->id,
            'platform'                => 'google_analytics',
            'account_id'              => '538133379',
            'refresh_token'           => '1//0gExpiredToken777',
            'connection_status'       => 'connected',
            'is_connected'            => true,
            'access_token_expires_at' => now()->subMinutes(10),
        ]);

        $oauthService = app(GoogleOAuthService::class);

        try {
            $oauthService->getValidAccessToken('google_analytics', $this->workspace->id);
            $this->fail('Expected GoogleOAuthException was not thrown.');
        } catch (GoogleOAuthException $e) {
            $this->assertTrue($e->authRequired);
            $this->assertEquals('google_analytics', $e->platform);
        }

        $freshInteg = Integration::where('workspace_id', $this->workspace->id)
            ->where('platform', 'google_analytics')
            ->first();

        $this->assertEquals('expired', $freshInteg->connection_status);
        $this->assertTrue($freshInteg->isExpired());
    }

    /**
     * 5. Test GA4 & GSC metrics endpoints return honest errors without fake zero metrics when expired
     */
    public function test_metrics_endpoints_return_honest_error_when_session_expired()
    {
        // GA4 expired integration
        Integration::create([
            'workspace_id'      => $this->workspace->id,
            'platform'          => 'google_analytics',
            'account_id'        => '538133379',
            'account_name'      => 'GA4 Property (538133379)',
            'refresh_token'     => '1//0gExpiredTokenGA',
            'connection_status' => 'expired',
            'is_connected'      => true,
        ]);

        // GSC expired integration
        Integration::create([
            'workspace_id'      => $this->workspace->id,
            'platform'          => 'search_console',
            'account_id'        => 'sc-domain:redmindtechnologies.com',
            'account_name'      => 'sc-domain:redmindtechnologies.com',
            'refresh_token'     => '1//0gExpiredTokenGSC',
            'connection_status' => 'expired',
            'is_connected'      => true,
        ]);

        // Test GA4 metrics endpoint
        $gaRes = $this->actingAs($this->user)
            ->getJson("/api/v1/google-analytics/metrics?workspace_id={$this->workspace->id}");

        $gaRes->assertStatus(200);
        $gaRes->assertJson([
            'success'       => false,
            'auth_required' => true,
            'property_id'   => '538133379',
            'data'          => null,
        ]);
        // Crucial: data must be null, never fake zero metrics
        $this->assertNull($gaRes->json('data'));

        // Test GSC metrics endpoint
        $gscRes = $this->actingAs($this->user)
            ->getJson("/api/v1/search-console/metrics?workspace_id={$this->workspace->id}");

        $gscRes->assertStatus(200);
        $gscRes->assertJson([
            'success'       => false,
            'auth_required' => true,
            'site_url'      => 'sc-domain:redmindtechnologies.com',
            'data'          => null,
        ]);
        $this->assertNull($gscRes->json('data'));
    }

    /**
     * 6. Test status endpoints report expired status accurately
     */
    public function test_status_endpoints_report_expired_status()
    {
        Integration::create([
            'workspace_id'      => $this->workspace->id,
            'platform'          => 'google_analytics',
            'account_id'        => '123456789',
            'refresh_token'     => '1//0gExpiredToken',
            'connection_status' => 'expired',
            'is_connected'      => true,
        ]);

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/google-analytics/status?workspace_id={$this->workspace->id}");

        $res->assertStatus(200);
        $res->assertJson([
            'success'   => true,
            'connected' => false,
            'status'    => 'expired',
        ]);
    }
}
