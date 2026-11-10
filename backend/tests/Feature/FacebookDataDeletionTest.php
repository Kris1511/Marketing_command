<?php

namespace Tests\Feature;

use App\Models\FacebookDataDeletionRequest;
use App\Models\FacebookPage;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookDataDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const MAIN_ID = '1731551660900836';

    private const MAIN_SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const TEST_ID = '1390717679611716';

    private const TEST_SECRET = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const USER = '4242424242424242';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:TjZ0avaepo0xutIJPStkZYOlCeaR/ODGhOzg3s0g7fU=',
            'services.facebook.client_id' => self::MAIN_ID,
            'services.facebook.client_secret' => self::MAIN_SECRET,
            'services.facebook.testing_client_id' => self::TEST_ID,
            'services.facebook.testing_client_secret' => self::TEST_SECRET,
            'services.facebook.frontend_url' => 'https://app.example.test/',
            'services.facebook.preflight' => false,
        ]);
    }

    // --- helpers -------------------------------------------------------------------------------

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function signedRequest(array $payload, string $secret = self::MAIN_SECRET): string
    {
        $encodedPayload = $this->b64(json_encode($payload));

        return $this->b64(hash_hmac('sha256', $encodedPayload, $secret, true)).'.'.$encodedPayload;
    }

    private function validPayload(string $userId = self::USER): array
    {
        return ['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), 'user_id' => $userId];
    }

    private function postCallback(string $signedRequest)
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/facebook/data-deletion', ['signed_request' => $signedRequest]);
    }

    private function connect(Workspace $workspace, string $pageId, ?string $userId, string $appId = self::MAIN_ID, ?string $igId = null): array
    {
        $page = FacebookPage::create([
            'workspace_id' => $workspace->id,
            'page_id' => $pageId,
            'page_name' => "Page {$pageId}",
            'page_access_token' => "token-{$pageId}",
            'token_status' => 'valid',
            'facebook_user_id' => $userId,
            'facebook_app_id' => $userId ? $appId : null,
        ]);

        $facebook = Integration::create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_id' => $pageId,
            'account_name' => "Page {$pageId}",
            'refresh_token' => "token-{$pageId}",
            'is_connected' => true,
            'connection_status' => 'connected',
            'facebook_user_id' => $userId,
            'facebook_app_id' => $userId ? $appId : null,
        ]);

        $instagram = $igId ? Integration::create([
            'workspace_id' => $workspace->id,
            'platform' => 'instagram',
            'account_id' => $igId,
            'account_name' => '@ig'.$igId,
            'refresh_token' => "token-{$pageId}",
            'is_connected' => true,
            'connection_status' => 'connected',
            'facebook_user_id' => $userId,
            'facebook_app_id' => $userId ? $appId : null,
        ]) : null;

        return [$page, $facebook, $instagram];
    }

    // --- signature verification ----------------------------------------------------------------

    public function test_valid_signed_request_returns_metas_required_response_without_authentication(): void
    {
        $response = $this->postCallback($this->signedRequest($this->validPayload()));

        $response->assertOk()->assertJsonStructure(['url', 'confirmation_code']);
        $code = $response->json('confirmation_code');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $code);
        $this->assertSame("https://app.example.test/data-deletion?code={$code}", $response->json('url'));
        $this->assertSame(['url', 'confirmation_code'], array_keys($response->json()), 'only the two fields Meta expects');
    }

    public function test_request_signed_by_the_testing_app_is_verified_with_the_testing_secret(): void
    {
        $response = $this->postCallback($this->signedRequest($this->validPayload(), self::TEST_SECRET));

        $response->assertOk();
        $this->assertSame(self::TEST_ID, FacebookDataDeletionRequest::firstOrFail()->facebook_app_id);

        $main = $this->postCallback($this->signedRequest($this->validPayload('999'), self::MAIN_SECRET));
        $main->assertOk();
        $this->assertSame(self::MAIN_ID, FacebookDataDeletionRequest::where('confirmation_code', $main->json('confirmation_code'))->firstOrFail()->facebook_app_id);
    }

    public function test_signature_from_an_unknown_secret_is_rejected_and_nothing_is_recorded(): void
    {
        $this->postCallback($this->signedRequest($this->validPayload(), 'cccccccccccccccccccccccccccccccc'))
            ->assertStatus(400)->assertExactJson(['error' => 'invalid_signed_request']);

        $this->assertDatabaseCount('facebook_data_deletion_requests', 0);
    }

    public function test_tampered_payload_is_rejected(): void
    {
        [$signature] = explode('.', $this->signedRequest($this->validPayload('111')));
        $forgedPayload = $this->b64(json_encode($this->validPayload('222')));

        $this->postCallback($signature.'.'.$forgedPayload)->assertStatus(400)->assertJson(['error' => 'invalid_signed_request']);
        $this->assertDatabaseCount('facebook_data_deletion_requests', 0);
    }

    public function test_malformed_and_unexpected_requests_are_rejected(): void
    {
        $bad = [
            'no dot' => 'abcdef',
            'too many parts' => 'a.b.c',
            'not base64url' => '@@@.###',
            'empty parts' => '.',
            'wrong algorithm' => $this->signedRequest(['algorithm' => 'HMAC-SHA1', 'user_id' => self::USER]),
            'non numeric user' => $this->signedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => "1' OR '1'='1"]),
            'missing user' => $this->signedRequest(['algorithm' => 'HMAC-SHA256']),
            'oversized' => str_repeat('a', 9000).'.'.str_repeat('b', 10),
        ];

        foreach ($bad as $label => $value) {
            $this->postCallback($value)->assertStatus(400, "case: {$label}");
        }

        $this->withHeaders(['Accept' => 'application/json'])->post('/api/v1/facebook/data-deletion', [])
            ->assertStatus(400)->assertExactJson(['error' => 'missing_signed_request']);
        $this->withHeaders(['Accept' => 'application/json'])->post('/api/v1/facebook/data-deletion', ['signed_request' => ['x']])
            ->assertStatus(400);

        $this->assertDatabaseCount('facebook_data_deletion_requests', 0);
    }

    public function test_requests_cannot_be_validated_when_no_app_secret_is_configured(): void
    {
        config([
            'services.facebook.client_secret' => '',
            'services.facebook.testing_client_secret' => null,
        ]);

        // A signature made with an empty key must never be accepted.
        $this->postCallback($this->signedRequest($this->validPayload(), ''))->assertStatus(400);
    }

    // --- tracking and duplicates ----------------------------------------------------------------

    public function test_repeated_requests_reuse_the_same_confirmation_code(): void
    {
        $first = $this->postCallback($this->signedRequest($this->validPayload()))->assertOk()->json('confirmation_code');
        $second = $this->postCallback($this->signedRequest($this->validPayload()))->assertOk()->json('confirmation_code');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('facebook_data_deletion_requests', 1);
        $this->assertSame(2, FacebookDataDeletionRequest::firstOrFail()->request_count);

        // The same Facebook user id under a different Meta app is a different person to Meta: separate record.
        $other = $this->postCallback($this->signedRequest($this->validPayload(), self::TEST_SECRET))->json('confirmation_code');
        $this->assertNotSame($first, $other);
        $this->assertDatabaseCount('facebook_data_deletion_requests', 2);
    }

    public function test_raw_facebook_user_id_is_not_stored_in_the_tracking_table(): void
    {
        $this->postCallback($this->signedRequest($this->validPayload()))->assertOk();

        $stored = json_encode(\DB::table('facebook_data_deletion_requests')->get()->all());
        $this->assertStringNotContainsString(self::USER, $stored);
    }

    // --- what gets deleted ------------------------------------------------------------------------

    public function test_only_the_requesting_users_facebook_connections_are_deleted(): void
    {
        $workspace = Workspace::create(['name' => 'Client A', 'status' => 'active']);
        $otherWorkspace = Workspace::create(['name' => 'Client B', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin']);
        Lead::create(['workspace_id' => $workspace->id, 'name' => 'Keep Me', 'email' => 'keep@x.test', 'source' => 'manual_entry']);

        // Belongs to the user asking for deletion (Facebook Page + its Instagram account), same Meta app.
        [$ownPage, $ownFacebook, $ownInstagram] = $this->connect($workspace, '111', self::USER, self::MAIN_ID, '9001');
        // Same user, but granted through the OTHER Meta app: a different app-scoped identity.
        [$testingAppPage] = $this->connect($otherWorkspace, '222', self::USER, self::TEST_ID);
        // A colleague's connection in the same workspace.
        [$colleaguePage, $colleagueFacebook] = $this->connect($workspace, '333', '5555555555555555');
        // Manually connected (no Facebook user recorded): cannot be attributed, so it must be kept.
        [$manualPage, $manualFacebook] = $this->connect($otherWorkspace, '444', null);

        $this->postCallback($this->signedRequest($this->validPayload()))->assertOk();

        // Gone for good (not merely soft-deleted), including the tokens.
        $this->assertNull(FacebookPage::withTrashed()->find($ownPage->id));
        $this->assertNull(Integration::withTrashed()->find($ownFacebook->id));
        $this->assertNull(Integration::withTrashed()->find($ownInstagram->id));

        // Everything else is untouched.
        foreach ([$testingAppPage, $colleaguePage, $manualPage] as $page) {
            $this->assertNotNull(FacebookPage::find($page->id), 'unrelated page connection must remain');
        }
        foreach ([$colleagueFacebook, $manualFacebook] as $integration) {
            $this->assertNotNull(Integration::find($integration->id), 'unrelated integration must remain');
        }
        $this->assertDatabaseHas('leads', ['name' => 'Keep Me']);
        $this->assertSame(2, Workspace::count());
        $this->assertNotNull(User::find($admin->id));

        $record = FacebookDataDeletionRequest::firstOrFail();
        $this->assertSame('completed', $record->status);
        $this->assertSame(['facebook_pages' => 1, 'integrations' => 2], $record->deleted_counts);
        $this->assertNotNull($record->completed_at);
    }

    public function test_a_request_for_a_user_with_no_linked_data_still_completes_and_deletes_nothing(): void
    {
        $workspace = Workspace::create(['name' => 'Client A', 'status' => 'active']);
        [$page] = $this->connect($workspace, '111', '5555555555555555');

        $this->postCallback($this->signedRequest($this->validPayload()))->assertOk();

        $this->assertNotNull(FacebookPage::find($page->id));
        $this->assertSame(['facebook_pages' => 0, 'integrations' => 0], FacebookDataDeletionRequest::firstOrFail()->deleted_counts);
    }

    public function test_deletion_removes_data_connected_again_after_an_earlier_request(): void
    {
        $workspace = Workspace::create(['name' => 'Client A', 'status' => 'active']);
        $this->connect($workspace, '111', self::USER);
        $code = $this->postCallback($this->signedRequest($this->validPayload()))->json('confirmation_code');

        [$reconnected] = $this->connect($workspace, '112', self::USER);
        $again = $this->postCallback($this->signedRequest($this->validPayload()))->json('confirmation_code');

        $this->assertSame($code, $again);
        $this->assertNull(FacebookPage::withTrashed()->find($reconnected->id));
        $this->assertSame(['facebook_pages' => 2, 'integrations' => 2], FacebookDataDeletionRequest::firstOrFail()->deleted_counts);
    }

    // --- status endpoint ---------------------------------------------------------------------------

    public function test_status_endpoint_is_public_and_reports_progress_without_personal_data(): void
    {
        $workspace = Workspace::create(['name' => 'Client A', 'status' => 'active']);
        $this->connect($workspace, '111', self::USER);
        $code = $this->postCallback($this->signedRequest($this->validPayload()))->json('confirmation_code');

        $response = $this->getJson("/api/v1/facebook/data-deletion/status/{$code}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.deleted.facebook_pages', 1)
            ->assertJsonPath('data.deleted.integrations', 1);

        $this->assertStringNotContainsString(self::USER, $response->getContent());
        $this->assertStringNotContainsString('facebook_user_hash', $response->getContent());

        $this->getJson('/api/v1/facebook/data-deletion/status/'.str_repeat('0', 32))->assertStatus(404);
        $this->getJson('/api/v1/facebook/data-deletion/status/not-a-valid-code!')->assertStatus(404);
    }

    public function test_the_callback_route_is_reachable_without_a_token_while_normal_api_routes_still_require_one(): void
    {
        $this->postCallback($this->signedRequest($this->validPayload()))->assertOk();
        $this->getJson('/api/v1/posts')->assertStatus(401);
    }

    // --- the Facebook user id is recorded when a connection is made --------------------------------

    public function test_oauth_login_records_the_facebook_user_and_app_on_the_connection(): void
    {
        $workspace = Workspace::create(['name' => 'Client A', 'status' => 'active']);

        Http::fake([
            'https://graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'user_token_abc'], 200),
            'https://graph.facebook.com/*/me/accounts*' => Http::response(['data' => [[
                'id' => '555000111', 'name' => 'Client Page', 'access_token' => 'page-token', 'category' => 'Business',
            ]]], 200),
            'https://graph.facebook.com/*/debug_token*' => Http::response(['data' => ['scopes' => ['pages_show_list']]], 200),
            'https://graph.facebook.com/*/me*' => Http::response(['id' => self::USER, 'name' => 'Demo User'], 200),
        ]);

        $state = $this->getJson("/api/v1/auth/facebook?workspace_id={$workspace->id}")->json('state');
        $this->get('/api/v1/auth/facebook/callback?code=abc&state='.urlencode($state))->assertOk();

        $this->assertDatabaseHas('facebook_pages', ['page_id' => '555000111', 'facebook_user_id' => self::USER, 'facebook_app_id' => self::MAIN_ID]);
        $this->assertDatabaseHas('integrations', ['platform' => 'facebook', 'account_id' => '555000111', 'facebook_user_id' => self::USER, 'facebook_app_id' => self::MAIN_ID]);

        // ...and a deletion request for that same user now removes exactly that connection.
        $this->postCallback($this->signedRequest($this->validPayload()))->assertOk();
        $this->assertDatabaseMissing('facebook_pages', ['page_id' => '555000111']);
        $this->assertDatabaseMissing('integrations', ['platform' => 'facebook', 'account_id' => '555000111']);
    }

    public function test_connect_page_records_the_facebook_user_from_the_oauth_session(): void
    {
        $workspace = Workspace::create(['name' => 'Client A', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Http::fake(['https://graph.facebook.com/*' => Http::response([], 200)]);

        Cache::put('fb_auth_session_test', [
            'workspace_id' => $workspace->id,
            'user_token' => 'long-lived',
            'user_data' => ['id' => self::USER, 'name' => 'Demo User'],
            'pages' => [['id' => '777', 'name' => 'Chosen Page', 'access_token' => 'page-token-777']],
        ], 600);

        $this->postJson('/api/v1/facebook/connect-page', [
            'workspace_id' => $workspace->id,
            'page_id' => '777',
            'page_name' => 'Chosen Page',
            'oauth_session_id' => 'fb_auth_session_test',
        ])->assertOk();

        $this->assertDatabaseHas('facebook_pages', ['page_id' => '777', 'facebook_user_id' => self::USER, 'facebook_app_id' => self::MAIN_ID]);
        $this->assertDatabaseHas('integrations', ['platform' => 'facebook', 'account_id' => '777', 'facebook_user_id' => self::USER]);
    }

    public function test_connect_page_without_an_oauth_session_leaves_the_owner_unrecorded(): void
    {
        $workspace = Workspace::create(['name' => 'Client A', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Http::fake(['https://graph.facebook.com/*' => Http::response([], 200)]);

        $this->postJson('/api/v1/facebook/connect-page', [
            'workspace_id' => $workspace->id,
            'page_id' => '888',
            'page_name' => 'Manual Page',
            'page_access_token' => 'manual-token',
        ])->assertOk();

        $this->assertDatabaseHas('facebook_pages', ['page_id' => '888', 'facebook_user_id' => null, 'facebook_app_id' => null]);
    }
}
