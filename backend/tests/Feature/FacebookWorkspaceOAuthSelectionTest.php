<?php

namespace Tests\Feature;

use App\Models\FacebookMessengerWebhookEvent;
use App\Models\FacebookPage;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookWorkspaceOAuthSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key'                                 => 'base64:TjZ0avaepo0xutIJPStkZYOlCeaR/ODGhOzg3s0g7fU=',
            'services.facebook.client_id'             => '1731551660900836',
            'services.facebook.client_secret'         => 'cortex_crm_test_secret_abc123',
            'services.facebook.redirect'              => 'https://mc-api.redmindtechnologies.com/api/v1/auth/facebook/callback',
            'services.facebook.testing_client_id'     => '1390717679611716',
            'services.facebook.testing_client_secret' => 'redmind_demo_test_secret_xyz789',
            'services.facebook.testing_workspace_id'  => '8',
            'services.facebook.webhook_verify_token'  => 'RedMindCRM_FBWebhook_2026_Secure',
        ]);
    }

    public function test_production_testing_workspace_8_redirect_selects_redmind_demo(): void
    {
        Workspace::create(['id' => 8, 'name' => 'Testing', 'status' => 'active']);

        $response = $this->getJson('/api/v1/auth/facebook?workspace_id=8');

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'app_id'  => '1390717679611716',
        ]);
        $this->assertStringContainsString('client_id=1390717679611716', $response->json('url'));
        $this->assertStringNotContainsString('1731551660900836', $response->json('url'));
    }

    public function test_workspace_9_redirect_selects_cortex_crm(): void
    {
        Workspace::create(['id' => 9, 'name' => 'Redmind Technologies', 'status' => 'active']);

        $response = $this->getJson('/api/v1/auth/facebook?workspace_id=9');

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'app_id'  => '1731551660900836',
        ]);
        $this->assertStringContainsString('client_id=1731551660900836', $response->json('url'));
        $this->assertStringNotContainsString('1390717679611716', $response->json('url'));
    }

    public function test_workspace_4_continues_using_cortex_crm(): void
    {
        Workspace::create(['id' => 4, 'name' => 'RedMind Technologies', 'status' => 'active']);

        $response = $this->getJson('/api/v1/auth/facebook?workspace_id=4');

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'app_id'  => '1731551660900836',
        ]);
        $this->assertStringContainsString('client_id=1731551660900836', $response->json('url'));
        $this->assertStringNotContainsString('1390717679611716', $response->json('url'));
    }

    public function test_workspace_8_supports_custom_testing_redirect_uri(): void
    {
        Workspace::create(['id' => 8, 'name' => 'Testing', 'status' => 'active']);

        config([
            'services.facebook.testing_redirect' => 'http://localhost:8000/api/v1/auth/facebook/callback',
        ]);

        $response = $this->getJson('/api/v1/auth/facebook?workspace_id=8');

        $response->assertOk();
        $this->assertStringContainsString('client_id=1390717679611716', $response->json('url'));
        $this->assertStringContainsString(urlencode('http://localhost:8000/api/v1/auth/facebook/callback'), $response->json('url'));
    }

    public function test_workspace_4_ignores_custom_testing_redirect_uri(): void
    {
        Workspace::create(['id' => 4, 'name' => 'RedMind Technologies', 'status' => 'active']);

        config([
            'services.facebook.testing_redirect' => 'http://localhost:8000/api/v1/auth/facebook/callback',
        ]);

        $response = $this->getJson('/api/v1/auth/facebook?workspace_id=4');

        $response->assertOk();
        $this->assertStringContainsString('client_id=1731551660900836', $response->json('url'));
        // Cortex CRM must continue using the global redirect URI
        $this->assertStringContainsString(urlencode('https://mc-api.redmindtechnologies.com/api/v1/auth/facebook/callback'), $response->json('url'));
    }

    public function test_missing_testing_credentials_fails_safely_without_silent_cortex_fallback(): void
    {
        Workspace::create(['id' => 8, 'name' => 'Testing', 'status' => 'active']);

        // Explicitly clear Redmind_Demo testing credentials
        config([
            'services.facebook.testing_client_id'     => null,
            'services.facebook.testing_client_secret' => null,
        ]);

        $response = $this->getJson('/api/v1/auth/facebook?workspace_id=8');

        // Must fail with 500 configuration error, NEVER falling back to Cortex CRM (1731551660900836)
        $response->assertStatus(500);
        $response->assertJson([
            'success' => false,
            'error'   => 'configuration_error',
        ]);
        $this->assertStringContainsString('Testing workspace (ID 8) requires FACEBOOK_TESTING_APP_ID', $response->json('message'));
        $this->assertNull($response->json('url'));
    }

    public function test_unsigned_legacy_state_is_strictly_rejected(): void
    {
        // Plain Base64 state without HMAC signature envelope is completely rejected
        $plainBase64 = base64_encode(json_encode(['workspace_id' => 8, 'ts' => time()]));
        $response = $this->getJson('/api/v1/auth/facebook/callback?code=abc&state=' . urlencode($plainBase64));

        $response->assertStatus(403);
        $response->assertJson(['error' => 'invalid_state_format']);
    }

    public function test_tampered_state_signature_is_strictly_rejected(): void
    {
        // Valid structure but tampered payload or signature
        $tamperedState = base64_encode(json_encode([
            'payload' => json_encode(['workspace_id' => 8, 'ts' => time(), 'nonce' => 'nonce123']),
            'sig'     => 'forged_tampered_signature_string',
        ]));
        $response = $this->getJson('/api/v1/auth/facebook/callback?code=abc&state=' . urlencode($tamperedState));

        $response->assertStatus(403);
        $response->assertJson(['error' => 'tampered_state']);
    }

    public function test_strict_timestamp_validation_rejects_expired_and_future_timestamps(): void
    {
        $nonce = 'test_nonce_' . bin2hex(random_bytes(8));
        Cache::put("fb_oauth_nonce_{$nonce}", 8, now()->addMinutes(15));

        // 1. Expired timestamp (>900 seconds in past)
        $expiredPayload = json_encode(['workspace_id' => 8, 'ts' => time() - 901, 'nonce' => $nonce]);
        $expiredSig     = hash_hmac('sha256', $expiredPayload, config('app.key'));
        $expiredState   = base64_encode(json_encode(['payload' => $expiredPayload, 'sig' => $expiredSig]));
        $resExpired     = $this->getJson('/api/v1/auth/facebook/callback?code=abc&state=' . urlencode($expiredState));
        $resExpired->assertStatus(403)->assertJson(['error' => 'expired_timestamp']);

        // 2. Future-dated timestamp (>60 seconds in future)
        $futurePayload = json_encode(['workspace_id' => 8, 'ts' => time() + 120, 'nonce' => $nonce]);
        $futureSig     = hash_hmac('sha256', $futurePayload, config('app.key'));
        $futureState   = base64_encode(json_encode(['payload' => $futurePayload, 'sig' => $futureSig]));
        $resFuture     = $this->getJson('/api/v1/auth/facebook/callback?code=abc&state=' . urlencode($futureState));
        $resFuture->assertStatus(403)->assertJson(['error' => 'future_timestamp']);

        // 3. Invalid non-integer timestamp
        $invalidPayload = json_encode(['workspace_id' => 8, 'ts' => 'not-a-timestamp', 'nonce' => $nonce]);
        $invalidSig     = hash_hmac('sha256', $invalidPayload, config('app.key'));
        $invalidState   = base64_encode(json_encode(['payload' => $invalidPayload, 'sig' => $invalidSig]));
        $resInvalid     = $this->getJson('/api/v1/auth/facebook/callback?code=abc&state=' . urlencode($invalidState));
        $resInvalid->assertStatus(403)->assertJson(['error' => 'invalid_timestamp']);

        // 4. Missing timestamp
        $missingTsPayload = json_encode(['workspace_id' => 8, 'nonce' => $nonce]);
        $missingTsSig     = hash_hmac('sha256', $missingTsPayload, config('app.key'));
        $missingTsState   = base64_encode(json_encode(['payload' => $missingTsPayload, 'sig' => $missingTsSig]));
        $resMissingTs     = $this->getJson('/api/v1/auth/facebook/callback?code=abc&state=' . urlencode($missingTsState));
        $resMissingTs->assertStatus(403)->assertJson(['error' => 'invalid_payload']);

        // 5. Missing or empty nonce
        $missingNoncePayload = json_encode(['workspace_id' => 8, 'ts' => time(), 'nonce' => '']);
        $missingNonceSig     = hash_hmac('sha256', $missingNoncePayload, config('app.key'));
        $missingNonceState   = base64_encode(json_encode(['payload' => $missingNoncePayload, 'sig' => $missingNonceSig]));
        $resMissingNonce     = $this->getJson('/api/v1/auth/facebook/callback?code=abc&state=' . urlencode($missingNonceState));
        $resMissingNonce->assertStatus(403)->assertJson(['error' => 'invalid_payload']);
    }

    public function test_missing_state_parameter_is_strictly_rejected(): void
    {
        $response = $this->getJson('/api/v1/auth/facebook/callback?code=abc');
        $response->assertStatus(403);
        $response->assertJson(['error' => 'missing_state']);
    }

    public function test_single_use_nonce_prevents_replay_and_concurrent_replay_attacks(): void
    {
        Workspace::create(['id' => 8, 'name' => 'Testing', 'status' => 'active']);

        Http::fake([
            'https://graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'mock_token_one_time',
            ], 200),
            'https://graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [
                    [
                        'id'           => '888888888888888',
                        'name'         => 'Testing Demo Page',
                        'access_token' => 'page_token_secret_123',
                        'category'     => 'Community',
                    ],
                ],
            ], 200),
            'https://graph.facebook.com/*/debug_token*' => Http::response([
                'data' => ['scopes' => ['pages_show_list']],
            ], 200),
            'https://graph.facebook.com/*/me*' => Http::response([
                'id'   => '123456789',
                'name' => 'Demo User',
            ], 200),
        ]);

        // Generate authentic redirect that writes single-use nonce to Cache
        $redirectRes = $this->getJson('/api/v1/auth/facebook?workspace_id=8');
        $redirectRes->assertOk();
        $state = $redirectRes->json('state');

        // First callback attempt: valid, consumes nonce and sets atomic consumption marker
        $firstAttempt = $this->get('/api/v1/auth/facebook/callback?code=code_1&state=' . urlencode($state));
        $firstAttempt->assertOk();
        $this->assertStringContainsString('FACEBOOK_PAGES_FETCHED', $firstAttempt->content());

        // Replay attempt with exact same state: nonce already consumed -> REJECTED (403)
        $replayAttempt = $this->getJson('/api/v1/auth/facebook/callback?code=code_2&state=' . urlencode($state));
        $replayAttempt->assertStatus(403)->assertJson(['error' => 'nonce_consumed_or_invalid']);
    }

    public function test_callback_token_exchange_uses_redmind_demo_credentials_and_preserves_production_response(): void
    {
        Workspace::create(['id' => 8, 'name' => 'Testing', 'status' => 'active']);

        Http::fake([
            'https://graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'mock_redmind_demo_token_12345',
            ], 200),
            'https://graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [
                    [
                        'id'           => '888888888888888',
                        'name'         => 'Testing Demo Page',
                        'access_token' => 'page_token_demo_987',
                        'category'     => 'Product/Service',
                    ],
                ],
            ], 200),
            'https://graph.facebook.com/*/debug_token*' => Http::response([
                'data' => ['scopes' => ['pages_show_list']],
            ], 200),
            'https://graph.facebook.com/*/me*' => Http::response([
                'id'   => '123456789',
                'name' => 'Demo User',
            ], 200),
        ]);

        $redirectRes = $this->getJson('/api/v1/auth/facebook?workspace_id=8');
        $state = $redirectRes->json('state');

        $response = $this->get('/api/v1/auth/facebook/callback?code=mock_code_for_demo&state=' . urlencode($state));

        // 1. Preserves existing production HTML popup window behavior
        $response->assertOk();
        $this->assertStringContainsString('FACEBOOK_PAGES_FETCHED', $response->content());
        $this->assertStringContainsString('oauth_session_id', $response->content());

        // 2. Access tokens are NEVER exposed in the HTML/JSON payload
        $this->assertStringNotContainsString('mock_redmind_demo_token_12345', $response->content());

        // 3. User token is securely stored in server-side session cache only
        preg_match('/oauth_session_id: "([^"]+)"/', $response->content(), $matches);
        $this->assertNotEmpty($matches[1]);
        $cachedSession = Cache::get($matches[1]);
        $this->assertSame(8, $cachedSession['workspace_id']);
        $this->assertSame('mock_redmind_demo_token_12345', $cachedSession['user_token']);

        // 4. Token exchange strictly verified to use Redmind_Demo credentials
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'oauth/access_token')
                && ($request['client_id'] ?? null) === '1390717679611716'
                && ($request['client_secret'] ?? null) === 'redmind_demo_test_secret_xyz789';
        });
    }

    public function test_callback_token_exchange_uses_cortex_crm_credentials_for_workspace_9(): void
    {
        Workspace::create(['id' => 9, 'name' => 'Redmind Technologies', 'status' => 'active']);

        Http::fake([
            'https://graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'mock_cortex_token_67890',
            ], 200),
            'https://graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [
                    [
                        'id'           => '115864121526929',
                        'name'         => 'Cortex CRM Page',
                        'access_token' => 'page_token_cortex_456',
                        'category'     => 'Software',
                    ],
                ],
            ], 200),
            'https://graph.facebook.com/*/debug_token*' => Http::response([
                'data' => ['scopes' => ['pages_show_list']],
            ], 200),
            'https://graph.facebook.com/*/me*' => Http::response([
                'id'   => '987654321',
                'name' => 'Cortex User',
            ], 200),
        ]);

        $redirectRes = $this->getJson('/api/v1/auth/facebook?workspace_id=9');
        $state = $redirectRes->json('state');

        $response = $this->get('/api/v1/auth/facebook/callback?code=mock_code_for_cortex&state=' . urlencode($state));

        $response->assertOk();
        $this->assertStringContainsString('FACEBOOK_PAGES_FETCHED', $response->content());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'oauth/access_token')
                && ($request['client_id'] ?? null) === '1731551660900836'
                && ($request['client_secret'] ?? null) === 'cortex_crm_test_secret_abc123';
        });
    }

    public function test_webhook_routing_isolates_workspaces_and_handles_duplicates(): void
    {
        $ws4 = Workspace::create(['name' => 'RedMind Technologies', 'status' => 'active']);
        $ws8 = Workspace::create(['name' => 'Testing', 'status' => 'active']);

        // 1. Workspace 4 active page
        FacebookPage::create([
            'workspace_id'      => $ws4->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies FB Page',
            'page_access_token' => 'token_for_page_4',
            'token_status'      => 'valid',
        ]);

        // 2. Workspace 8 active page
        FacebookPage::create([
            'workspace_id'      => $ws8->id,
            'page_id'           => '888888888888888',
            'page_name'         => 'Testing Demo FB Page',
            'page_access_token' => 'token_for_page_8',
            'token_status'      => 'valid',
        ]);

        // 3. Stale duplicate record in another workspace (revoked) - must NOT receive events
        FacebookPage::create([
            'workspace_id'      => $ws8->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'Stale Duplicate Connection',
            'page_access_token' => 'stale_token',
            'token_status'      => 'revoked',
        ]);

        // ── Delivery for Workspace 4 Page (signed with Cortex CRM secret) ──
        $json4 = json_encode([
            'entry' => [
                [
                    'id'        => '115864121526929',
                    'messaging' => [['message' => ['mid' => 'mid_ws4_001', 'text' => 'Hello WS4']]],
                ],
            ],
        ]);
        $sig4 = 'sha256=' . hash_hmac('sha256', $json4, 'cortex_crm_test_secret_abc123');
        $res4 = $this->call('POST', '/api/v1/webhook/facebook', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $sig4,
            'CONTENT_TYPE'             => 'application/json',
        ], $json4);
        $res4->assertOk();

        // ── Delivery for Workspace 8 Page (signed with Redmind_Demo secret) ──
        $json8 = json_encode([
            'entry' => [
                [
                    'id'        => '888888888888888',
                    'messaging' => [['message' => ['mid' => 'mid_ws8_001', 'text' => 'Hello WS8']]],
                ],
            ],
        ]);
        $sig8 = 'sha256=' . hash_hmac('sha256', $json8, 'redmind_demo_test_secret_xyz789');
        $res8 = $this->call('POST', '/api/v1/webhook/facebook', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $sig8,
            'CONTENT_TYPE'             => 'application/json',
        ], $json8);
        $res8->assertOk();

        // ── Delivery for Unknown Page ID (must be discarded) ──
        $jsonUnknown = json_encode([
            'entry' => [
                [
                    'id'        => '999999999999999',
                    'messaging' => [['message' => ['mid' => 'mid_unknown_001', 'text' => 'Unknown Page']]],
                ],
            ],
        ]);
        $sigUnknown = 'sha256=' . hash_hmac('sha256', $jsonUnknown, 'redmind_demo_test_secret_xyz789');
        $resUnknown = $this->call('POST', '/api/v1/webhook/facebook', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $sigUnknown,
            'CONTENT_TYPE'             => 'application/json',
        ], $jsonUnknown);
        $resUnknown->assertOk()->assertJson(['processed' => 0]);

        // Verifications:
        // 1. Workspace 4 has exactly 1 event (for page 115864121526929)
        $ws4Events = FacebookMessengerWebhookEvent::where('workspace_id', $ws4->id)->get();
        $this->assertCount(1, $ws4Events);
        $this->assertSame('115864121526929', $ws4Events->first()->page_id);
        $this->assertSame('mid_ws4_001', $ws4Events->first()->message_id);

        // 2. Workspace 8 has exactly 1 event (for page 888888888888888, stale duplicate was ignored)
        $ws8Events = FacebookMessengerWebhookEvent::where('workspace_id', $ws8->id)->get();
        $this->assertCount(1, $ws8Events);
        $this->assertSame('888888888888888', $ws8Events->first()->page_id);
        $this->assertSame('mid_ws8_001', $ws8Events->first()->message_id);

        // 3. Total persisted events is 2 (unknown page event was discarded)
        $this->assertSame(2, FacebookMessengerWebhookEvent::count());

        // 4. Strict cross-workspace isolation verification:
        $this->assertFalse($ws4Events->pluck('message_id')->contains('mid_ws8_001'));
        $this->assertFalse($ws4Events->pluck('page_id')->contains('888888888888888'));

        $this->assertFalse($ws8Events->pluck('message_id')->contains('mid_ws4_001'));
        $this->assertFalse($ws8Events->pluck('page_id')->contains('115864121526929'));
    }

    public function test_webhook_quarantines_ambiguous_events_when_multiple_workspaces_claim_same_page(): void
    {
        $ws4 = Workspace::create(['name' => 'RedMind Technologies', 'status' => 'active']);
        $ws8 = Workspace::create(['name' => 'Testing', 'status' => 'active']);

        // Both workspaces claim the exact same Facebook Page ID as active/valid
        FacebookPage::create([
            'workspace_id'      => $ws4->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'Page Claimed by WS4',
            'page_access_token' => 'token_for_page_4',
            'token_status'      => 'valid',
        ]);

        FacebookPage::create([
            'workspace_id'      => $ws8->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'Page Claimed by WS8',
            'page_access_token' => 'token_for_page_8',
            'token_status'      => 'valid',
        ]);

        $jsonAmbiguous = json_encode([
            'entry' => [
                [
                    'id'        => '115864121526929',
                    'messaging' => [['message' => ['mid' => 'mid_ambiguous_001', 'text' => 'Ambiguous message']]],
                ],
            ],
        ]);
        $sigAmbiguous = 'sha256=' . hash_hmac('sha256', $jsonAmbiguous, 'cortex_crm_test_secret_abc123');

        $res = $this->call('POST', '/api/v1/webhook/facebook', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $sigAmbiguous,
            'CONTENT_TYPE'             => 'application/json',
        ], $jsonAmbiguous);

        $res->assertOk();
        $res->assertJson(['processed' => 0]);

        // Security check: event must be quarantined. Neither workspace receives it!
        $this->assertCount(0, FacebookMessengerWebhookEvent::where('workspace_id', $ws4->id)->get());
        $this->assertCount(0, FacebookMessengerWebhookEvent::where('workspace_id', $ws8->id)->get());
        $this->assertSame(0, FacebookMessengerWebhookEvent::count());
    }
}
