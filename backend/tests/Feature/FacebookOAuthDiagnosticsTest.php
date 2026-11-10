<?php

namespace Tests\Feature;

use App\Http\Controllers\FacebookAuthController;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class FacebookOAuthDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = '1731551660900836';

    private const SECRET = 'abcdef0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:TjZ0avaepo0xutIJPStkZYOlCeaR/ODGhOzg3s0g7fU=',
            'services.facebook.client_id' => self::APP_ID,
            'services.facebook.client_secret' => self::SECRET,
            'services.facebook.redirect' => 'https://api.example.test/api/v1/auth/facebook/callback',
            'services.facebook.testing_workspace_id' => '8',
            'services.facebook.preflight' => true,
        ]);

        Workspace::create(['name' => 'Client', 'status' => 'active']);
    }

    private function translate($code, string $description, string $reason = ''): string
    {
        $method = new ReflectionMethod(FacebookAuthController::class, 'translateOAuthError');

        return $method->invoke(app(FacebookAuthController::class), $code, $description, $reason, self::APP_ID, 'https://api.example.test/cb');
    }

    private function fakeMetaApp(array $app = [], ?array $error = null, int $status = 200): void
    {
        Http::fake([
            'https://graph.facebook.com/*/'.self::APP_ID.'*' => Http::response(
                $error ? ['error' => $error] : array_merge([
                    'id' => self::APP_ID,
                    'name' => 'Cortex CRM',
                    'category' => 'Business',
                    'privacy_policy_url' => 'https://example.test/privacy-policy',
                    'terms_of_service_url' => 'https://example.test/terms',
                    'app_domains' => ['example.test'],
                ], $app),
                $error ? 400 : $status
            ),
        ]);
    }

    // --- Error translation ---------------------------------------------------------------------

    public function test_meta_login_unavailable_message_is_attributed_to_meta_and_keeps_the_original_text(): void
    {
        $original = 'Facebook Login is currently unavailable for this app, since we are updating additional details for this app. Please try again later.';
        $message = $this->translate('', $original);

        $this->assertStringContainsString('cannot be fixed by changing this application', $message);
        $this->assertStringContainsString('Privacy Policy URL', $message);
        $this->assertStringContainsString($original, $message, 'the actual Meta text must be shown');
    }

    public function test_invalid_secret_error_points_at_the_app_secret_and_shows_meta_text(): void
    {
        $message = $this->translate(190, 'Invalid OAuth access token signature.');

        $this->assertStringContainsString('App Secret', $message);
        $this->assertStringContainsString('Invalid OAuth access token signature.', $message);
        $this->assertStringContainsString('[190]', $message);
    }

    public function test_unknown_meta_errors_always_include_meta_code_and_description(): void
    {
        $message = $this->translate('1349999', 'Some brand new Meta restriction text.');

        $this->assertStringContainsString('Some brand new Meta restriction text.', $message);
    }

    public function test_credentials_are_redacted_from_displayed_meta_errors(): void
    {
        $message = $this->translate(1, 'Request failed access_token=EAABsbCS1iHgBO7ZAbcdefghijklmnopqrst&client_secret='.self::SECRET.' for app 1731551660900836|'.self::SECRET);

        $this->assertStringNotContainsString(self::SECRET, $message);
        $this->assertStringNotContainsString('EAABsbCS1iHgBO7Z', $message);
        $this->assertStringContainsString('[redacted]', $message);
    }

    // --- Preflight in the redirect step --------------------------------------------------------

    public function test_redirect_shows_metas_real_error_when_credentials_are_rejected(): void
    {
        $this->fakeMetaApp(error: ['message' => 'Invalid OAuth access token signature.', 'type' => 'OAuthException', 'code' => 190]);

        $response = $this->getJson('/api/v1/auth/facebook?workspace_id=1');

        $response->assertStatus(502)
            ->assertJsonPath('error', 'meta_app_error')
            ->assertJsonPath('meta_error.code', 190);
        $this->assertStringContainsString('Invalid OAuth access token signature.', $response->json('message'));
        $this->assertStringNotContainsString('facebook.com/v', (string) $response->json('url'));

        // The popup (non-JSON) path renders the error page instead of redirecting to facebook.com.
        $popup = $this->get('/api/v1/auth/facebook?workspace_id=1');
        $popup->assertOk();
        $this->assertStringContainsString('Meta App Configuration Error', $popup->getContent());
        $this->assertStringContainsString('FACEBOOK_OAUTH_ERROR', $popup->getContent());
    }

    public function test_preflight_sends_the_app_token_in_a_header_never_in_the_url(): void
    {
        $this->fakeMetaApp(error: ['message' => 'Invalid OAuth access token signature.', 'type' => 'OAuthException', 'code' => 190]);

        $this->getJson('/api/v1/auth/facebook?workspace_id=1');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/'.self::APP_ID)
                && $request->hasHeader('Authorization', 'Bearer '.self::APP_ID.'|'.self::SECRET)
                && ! str_contains($request->url(), self::SECRET);
        });
    }

    public function test_redirect_proceeds_to_facebook_when_meta_accepts_the_app(): void
    {
        $this->fakeMetaApp();

        $response = $this->getJson('/api/v1/auth/facebook?workspace_id=1')->assertOk();

        $this->assertStringContainsString('https://www.facebook.com/v23.0/dialog/oauth', $response->json('url'));
    }

    public function test_redirect_is_not_blocked_when_meta_cannot_be_reached(): void
    {
        Http::fake(['https://graph.facebook.com/*' => fn () => throw new ConnectionException('network down')]);

        $this->getJson('/api/v1/auth/facebook?workspace_id=1')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_rate_limit_or_other_meta_errors_do_not_block_login(): void
    {
        $this->fakeMetaApp(error: ['message' => 'Application request limit reached', 'type' => 'OAuthException', 'code' => 4]);

        $this->getJson('/api/v1/auth/facebook?workspace_id=1')->assertOk()->assertJsonPath('success', true);
    }

    // --- Diagnostics endpoint ------------------------------------------------------------------

    public function test_diagnose_requires_an_administrator(): void
    {
        $this->getJson('/api/v1/auth/facebook/diagnose')->assertStatus(401);

        $this->actingAs(User::factory()->create(['role' => 'manager']))
            ->getJson('/api/v1/auth/facebook/diagnose')->assertStatus(403);
    }

    public function test_diagnose_reports_meta_side_and_config_problems_without_leaking_secrets(): void
    {
        config(['services.facebook.redirect' => 'http://localhost:9999/api/v1/auth/facebook/callback']);
        $this->fakeMetaApp(['privacy_policy_url' => 'https://example.test/', 'app_domains' => []]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/v1/auth/facebook/diagnose?workspace_id=1')->assertOk();
        $codes = collect($response->json('data.warnings'))->pluck('code');

        $this->assertTrue($codes->contains('privacy_policy_is_homepage'));
        $this->assertTrue($codes->contains('app_domains_empty'));
        $this->assertTrue($codes->contains('redirect_not_https'));
        $this->assertTrue($response->json('data.credentials.meta_accepts_credentials'));
        $this->assertTrue($response->json('data.credentials.secret_format_ok'));
        $this->assertSame('Cortex CRM', $response->json('data.meta_app.name'));

        $payload = $response->getContent();
        $this->assertStringNotContainsString(self::SECRET, $payload);
        $this->assertStringNotContainsString(self::APP_ID, $payload, 'the App ID must be masked');
    }

    public function test_diagnose_flags_a_malformed_secret_and_metas_rejection(): void
    {
        config(['services.facebook.client_secret' => 'not-a-valid-secret-value-with-dashes!']);
        $this->fakeMetaApp(error: ['message' => 'Invalid OAuth access token signature.', 'type' => 'OAuthException', 'code' => 190]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/v1/auth/facebook/diagnose?workspace_id=1')->assertOk();
        $codes = collect($response->json('data.warnings'))->pluck('code');

        $this->assertTrue($codes->contains('secret_format'));
        $this->assertTrue($codes->contains('meta_rejects_app'));
        $this->assertFalse($response->json('data.credentials.meta_accepts_credentials'));
        $this->assertStringNotContainsString('not-a-valid-secret-value', $response->getContent());
    }

    // --- Tokens stay on the server -------------------------------------------------------------

    public function test_page_access_tokens_are_not_sent_to_the_browser_after_login(): void
    {
        config(['services.facebook.preflight' => false]);
        Http::fake([
            'https://graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'user_token_value_123'], 200),
            'https://graph.facebook.com/*/me/accounts*' => Http::response(['data' => [[
                'id' => '555000111', 'name' => 'Client Page', 'access_token' => 'PAGE_TOKEN_SHOULD_STAY_SERVER_SIDE', 'category' => 'Business',
            ]]], 200),
            'https://graph.facebook.com/*/debug_token*' => Http::response(['data' => ['scopes' => ['pages_show_list']]], 200),
            'https://graph.facebook.com/*/me*' => Http::response(['id' => '1', 'name' => 'Demo User'], 200),
        ]);

        $state = $this->getJson('/api/v1/auth/facebook?workspace_id=1')->json('state');
        $response = $this->get('/api/v1/auth/facebook/callback?code=abc&state='.urlencode($state))->assertOk();

        $this->assertStringContainsString('FACEBOOK_PAGES_FETCHED', $response->getContent());
        $this->assertStringContainsString('Client Page', $response->getContent());
        $this->assertStringNotContainsString('PAGE_TOKEN_SHOULD_STAY_SERVER_SIDE', $response->getContent());
        $this->assertStringNotContainsString('user_token_value_123', $response->getContent());

        preg_match('/oauth_session_id: "([^"]+)"/', $response->getContent(), $m);
        $session = Cache::get($m[1]);
        $this->assertSame('PAGE_TOKEN_SHOULD_STAY_SERVER_SIDE', $session['pages'][0]['access_token'], 'the token must still be available server-side to connect the Page');
    }
}
