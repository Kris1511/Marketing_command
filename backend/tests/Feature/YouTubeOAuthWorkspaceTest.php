<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\Models\YouTubeConnection;
use App\Services\YouTubeService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * YouTube OAuth -> workspace mapping (foreign-key error 1452 on integrations.workspace_id).
 *
 * SAFETY: OAuth is fully mocked. The token exchange and channel lookup are stubbed on the service, every outbound
 * HTTP request is faked and asserted empty, and nothing here uploads, publishes or schedules anything.
 */
class YouTubeOAuthWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS  = 'ya29.SECRET-ACCESS-TOKEN-should-never-leak-0123456789';
    private const REFRESH = '1//SECRET-REFRESH-TOKEN-should-never-leak-0123456789';

    private User $admin;
    private Workspace $wsA;
    private Workspace $wsB;
    public array $channel;
    public array $tokens;
    private $svc;
    private bool $noExchange = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(fn () => Http::response(['error' => 'external calls are forbidden in tests'], 500));

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->wsA = Workspace::create(['name' => 'Client A']);
        $this->wsB = Workspace::create(['name' => 'Client B']);

        $this->channel = $this->channel('UC_AAA', 'Channel A');
        $this->tokens  = ['access_token' => self::ACCESS, 'refresh_token' => self::REFRESH, 'expires_in' => 3600];

        // Real getAuthUrl()/saveChannelConnection(); only the two Google calls are stubbed.
        config(['services.google.client_id' => 'test-client-id', 'services.google.client_secret' => 'test-secret', 'services.google.redirect_uri' => 'http://localhost/api/youtube/callback']);
        $this->svc = $this->makeService();
        $this->app->instance(YouTubeService::class, $this->svc);
    }

    /** The service reads its Google config in the constructor, so build a fresh one after changing config. */
    private function makeService(): YouTubeService
    {
        return new class($this) extends YouTubeService {
            public int $exchangeCalls = 0;
            public ?\Throwable $throw = null;

            public function __construct(private $test)
            {
                parent::__construct();
            }

            public function exchangeCodeForTokens(string $code): array
            {
                $this->exchangeCalls++;
                if ($this->throw) {
                    throw $this->throw;
                }

                return $this->test->tokens;
            }

            public function getChannelInfo(string $accessToken, ?string $channelId = null): array
            {
                return $this->test->channel;
            }
        };
    }

    /** From here on the authorization code must never be exchanged (and nothing may be written). */
    private function expectNoExchange(): void
    {
        $this->svc->exchangeCalls = 0;
        $this->noExchange = true;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->noExchange) {
            $this->assertSame(0, $this->svc->exchangeCalls, 'the authorization code must not be exchanged');
        }
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function channel(string $id, string $name): array
    {
        return [
            'channel_id' => $id, 'channel_name' => $name, 'channel_description' => 'd', 'channel_thumbnail' => null,
            'subscriber_count' => 10, 'video_count' => 2, 'view_count' => 99,
        ];
    }

    private function member(Workspace $ws, string $role = 'manager'): User
    {
        $u = User::factory()->create(['role' => $role]);
        $ws->users()->attach($u->id, ['role' => $role]);

        return $u;
    }

    /** Starts the flow as $user for $workspaceId and returns the signed state Google would echo back. */
    private function startFlow(User $user, mixed $workspaceId, bool $force = true): string
    {
        Sanctum::actingAs($user);
        $res = $this->getJson('/api/v1/youtube/connect?' . http_build_query(['workspace_id' => $workspaceId, 'force' => $force ? 1 : 0]));
        $res->assertOk()->assertJsonPath('success', true);
        parse_str((string) parse_url($res->json('url'), PHP_URL_QUERY), $q);
        $this->assertNotEmpty($q['state'] ?? null, 'auth URL must carry the signed state');
        $this->assertSame('offline', $q['access_type'] ?? null);

        return $q['state'];
    }

    private function oauthCallback(string $state, array $extra = [])
    {
        // The browser follows Google's redirect without any Authorization header.
        $this->app['auth']->forgetGuards();

        return $this->get('/api/youtube/callback?' . http_build_query(['code' => 'mock-auth-code', 'state' => $state] + $extra));
    }

    private function claimsOf(string $state): array
    {
        $env = json_decode(base64_decode($state), true);

        return json_decode($env['payload'], true);
    }

    // ── Valid connection ──────────────────────────────────────────────────────
    public function test_valid_workspace_connection_is_saved_under_that_workspace(): void
    {
        $state = $this->startFlow($this->admin, $this->wsA->id);
        $claims = $this->claimsOf($state);
        $this->assertSame($this->wsA->id, $claims['workspace_id']);
        $this->assertSame($this->admin->id, $claims['user_id']);

        $res = $this->oauthCallback($state);
        $res->assertOk()->assertSee('YouTube Channel Connected!')->assertDontSee(self::ACCESS)->assertDontSee(self::REFRESH);

        $conn = YouTubeConnection::where('channel_id', 'UC_AAA')->sole();
        $this->assertSame($this->wsA->id, (int) $conn->workspace_id);
        $this->assertSame($this->admin->id, (int) $conn->user_id);

        $integ = Integration::where('platform', 'youtube')->sole();
        $this->assertSame($this->wsA->id, (int) $integ->workspace_id);
        $this->assertSame('UC_AAA', $integ->account_id);
        $this->assertTrue($integ->is_connected);
        $this->assertSame('connected', $integ->connection_status);

        // Nothing for the other client
        $this->assertSame(0, YouTubeConnection::where('workspace_id', $this->wsB->id)->count());
        $this->assertSame(0, Integration::where('workspace_id', $this->wsB->id)->count());
    }

    public function test_tokens_are_encrypted_at_rest_and_never_exposed(): void
    {
        $this->oauthCallback($this->startFlow($this->admin, $this->wsA->id));

        $raw = DB::table('youtube_connections')->where('channel_id', 'UC_AAA')->first();
        $this->assertNotSame(self::ACCESS, $raw->access_token);
        $this->assertStringNotContainsString('SECRET-ACCESS', $raw->access_token);
        $this->assertNotSame(self::REFRESH, $raw->refresh_token);
        $this->assertStringNotContainsString('SECRET-REFRESH', (string) $raw->refresh_token);
        $this->assertSame(self::ACCESS, YouTubeConnection::first()->access_token); // decrypts transparently

        $rawInteg = DB::table('integrations')->where('platform', 'youtube')->first();
        $this->assertStringNotContainsString('SECRET-REFRESH', (string) $rawInteg->refresh_token);

        Sanctum::actingAs($this->admin);
        $status = $this->getJson("/api/v1/youtube/status?workspace_id={$this->wsA->id}")->assertOk();
        $this->assertStringNotContainsString('SECRET', $status->getContent());
    }

    // ── Invalid / deleted workspace, missing access ───────────────────────────
    public function test_connect_rejects_missing_all_unknown_and_deleted_workspaces_without_falling_back(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/youtube/connect')->assertStatus(422);
        $this->getJson('/api/v1/youtube/connect?workspace_id=all')->assertStatus(422);
        $this->getJson('/api/v1/youtube/connect?workspace_id=abc')->assertStatus(422);
        $this->getJson('/api/v1/youtube/connect?workspace_id=6')->assertStatus(404); // the id from the bug report: does not exist

        $this->wsB->delete(); // soft-deleted workspace
        $this->getJson("/api/v1/youtube/connect?workspace_id={$this->wsB->id}")->assertStatus(404);

        $this->assertSame(0, YouTubeConnection::count());
        $this->assertSame(0, Integration::count());
    }

    public function test_user_without_access_to_the_workspace_cannot_start_or_finish_the_flow(): void
    {
        $alphaOnly = $this->member($this->wsA);

        Sanctum::actingAs($alphaOnly);
        $this->getJson("/api/v1/youtube/connect?workspace_id={$this->wsB->id}&force=1")->assertStatus(403);

        // ...and a member of A can use A
        $this->oauthCallback($this->startFlow($alphaOnly, $this->wsA->id))->assertSee('YouTube Channel Connected!');
        $this->assertSame($this->wsA->id, (int) YouTubeConnection::sole()->workspace_id);
    }

    public function test_connect_requires_sign_in_and_blocks_viewers(): void
    {
        $this->getJson("/api/v1/youtube/connect?workspace_id={$this->wsA->id}")->assertStatus(401);

        $viewer = $this->member($this->wsA, 'viewer');
        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/youtube/connect?workspace_id={$this->wsA->id}")->assertStatus(403);
    }

    public function test_workspace_deleted_during_the_flow_is_a_clean_error_not_a_foreign_key_failure(): void
    {
        $this->expectNoExchange();
        $state = $this->startFlow($this->admin, $this->wsA->id);

        $this->wsA->forceDelete(); // gone entirely, like workspace 6 in the report

        $res = $this->oauthCallback($state);
        $res->assertOk()->assertSee('Workspace Not Found')->assertDontSee('SQLSTATE')->assertDontSee('1452')->assertDontSee('integrations_workspace_id_foreign');
        $this->assertSame(0, YouTubeConnection::count());
        $this->assertSame(0, Integration::count());
    }

    public function test_user_who_lost_access_during_the_flow_is_refused_before_tokens_are_exchanged(): void
    {
        $this->expectNoExchange();
        $member = $this->member($this->wsA);
        $state = $this->startFlow($member, $this->wsA->id);

        $this->wsA->users()->detach($member->id);

        $this->oauthCallback($state)->assertOk()->assertSee('Access Denied');
        $this->assertSame(0, YouTubeConnection::count());
    }

    // ── State validation ──────────────────────────────────────────────────────
    public function test_invalid_tampered_legacy_expired_and_replayed_states_are_rejected(): void
    {
        $this->expectNoExchange();

        // garbage / missing
        $this->oauthCallback('not-base64-$$$')->assertSee('Invalid State');
        $this->get('/api/youtube/callback?code=abc')->assertSee('Invalid State');

        // old unsigned format with an arbitrary workspace id (what the old code accepted)
        $legacy = base64_encode(json_encode(['user_id' => 1, 'workspace_id' => 6, 'time' => time()]));
        $this->oauthCallback($legacy)->assertSee('Invalid State');

        // tampered: workspace swapped after signing
        $state = $this->startFlow($this->admin, $this->wsA->id);
        $env = json_decode(base64_decode($state), true);
        $payload = json_decode($env['payload'], true);
        $payload['workspace_id'] = $this->wsB->id;
        $forged = base64_encode(json_encode(['payload' => json_encode($payload), 'sig' => $env['sig']]));
        $this->oauthCallback($forged)->assertSee('Invalid State');

        // expired (>15 min)
        $state = $this->startFlow($this->admin, $this->wsA->id);
        Carbon::setTestNow(now()->addMinutes(16));
        $this->oauthCallback($state)->assertSee('expired');
        Carbon::setTestNow();

        $this->assertSame(0, YouTubeConnection::count());
        $this->assertSame(0, Integration::count());
    }

    public function test_a_state_can_only_be_used_once(): void
    {
        $state = $this->startFlow($this->admin, $this->wsA->id);
        $this->oauthCallback($state)->assertSee('YouTube Channel Connected!');

        $this->expectNoExchange();
        $this->oauthCallback($state)->assertSee('already used');
        $this->assertSame(1, YouTubeConnection::count());
    }

    public function test_google_error_parameter_shows_a_safe_message(): void
    {
        $res = $this->get('/api/youtube/callback?error=access_denied&error_description=' . urlencode('<script>alert(1)</script>'));
        $res->assertOk()->assertSee('permission was denied')->assertDontSee('<script>alert(1)</script>', false);
    }

    // ── State payload, encoding and the Google round-trip ─────────────────────
    public function test_generated_state_carries_user_workspace_expiry_and_nonce_and_is_url_safe(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $state = $this->startFlow($this->admin, $i % 2 ? $this->wsA->id : $this->wsB->id);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $state, 'state must survive any URL handling untouched');
        }

        $state = $this->startFlow($this->admin, $this->wsB->id);
        $claims = $this->claimsOf($state);
        $this->assertSame($this->wsB->id, $claims['workspace_id']);
        $this->assertSame($this->admin->id, $claims['user_id']);
        $this->assertEqualsWithDelta(now()->timestamp, $claims['ts'], 5);
        $this->assertSame(32, strlen($claims['nonce']));

        $env = json_decode(base64_decode(strtr($state, '-_', '+/')), true);
        $this->assertSame(hash_hmac('sha256', $env['payload'], config('app.key')), $env['sig']);
    }

    public function test_workspace_id_is_recovered_after_google_echoes_the_state_back_in_every_encoding(): void
    {
        $this->channel = $this->channel('UC_ENC', 'Encoding channel');

        // 1. The way Google does it: state parsed out of the auth URL, then re-encoded into the redirect query string.
        $state = $this->startFlow($this->admin, $this->wsB->id);
        $this->oauthCallback($state)->assertSee('YouTube Channel Connected!');
        $this->assertSame($this->wsB->id, (int) YouTubeConnection::where('channel_id', 'UC_ENC')->sole()->workspace_id);
        YouTubeConnection::query()->delete();

        // 2. Sent back completely un-encoded (the state is URL-safe, so this is identical)
        $state = $this->startFlow($this->admin, $this->wsA->id);
        $this->app['auth']->forgetGuards();
        $this->get('/api/youtube/callback?code=mock-auth-code&state=' . $state)->assertSee('YouTube Channel Connected!');
        $this->assertSame($this->wsA->id, (int) YouTubeConnection::where('channel_id', 'UC_ENC')->sole()->workspace_id);
        YouTubeConnection::query()->delete();

        // 3. Padding "==" appended by some proxies, percent-encoded
        $state = $this->startFlow($this->admin, $this->wsB->id);
        $this->app['auth']->forgetGuards();
        $this->get('/api/youtube/callback?code=mock-auth-code&state=' . rawurlencode($state . '=='))->assertSee('YouTube Channel Connected!');
        $this->assertSame($this->wsB->id, (int) YouTubeConnection::where('channel_id', 'UC_ENC')->sole()->workspace_id);
        YouTubeConnection::query()->delete();

        // 4. Standard base64 alphabet where "+" arrived as a space (classic query-string corruption)
        $state = $this->startFlow($this->admin, $this->wsA->id);
        $standard = base64_encode(base64_decode(strtr($state, '-_', '+/') . str_repeat('=', (4 - strlen($state) % 4) % 4)));
        $this->oauthCallback(str_replace('+', ' ', $standard))->assertSee('YouTube Channel Connected!');
        $this->assertSame($this->wsA->id, (int) YouTubeConnection::where('channel_id', 'UC_ENC')->sole()->workspace_id);
    }

    public function test_the_authorization_url_contains_exactly_the_state_that_the_callback_accepts(): void
    {
        Sanctum::actingAs($this->admin);
        $url = $this->getJson("/api/v1/youtube/connect?workspace_id={$this->wsA->id}&force=1")->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertSame('http://localhost/api/youtube/callback', $q['redirect_uri']);
        $this->assertSame(1, substr_count($url, 'state='));
        $this->assertSame($this->wsA->id, $this->claimsOf($q['state'])['workspace_id']);
        $this->oauthCallback($q['state'])->assertSee('YouTube Channel Connected!');
    }

    public function test_signature_made_with_another_app_key_is_rejected(): void
    {
        $this->expectNoExchange();
        $state = $this->startFlow($this->admin, $this->wsA->id);

        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]); // e.g. a different server/environment

        $this->oauthCallback($state)->assertSee('Invalid State');
        $this->assertSame(0, YouTubeConnection::count());
    }

    public function test_a_state_whose_nonce_is_not_in_this_servers_cache_is_rejected_cleanly(): void
    {
        $this->expectNoExchange();
        $state = $this->startFlow($this->admin, $this->wsA->id);

        \Illuminate\Support\Facades\Cache::flush(); // callback served by a server that never issued this state

        $this->oauthCallback($state)->assertSee('already used or is no longer valid');
        $this->assertSame(0, YouTubeConnection::count());
        $this->assertSame(0, Integration::count());
    }

    /**
     * ROOT-CAUSE DOCUMENTATION. The callback that is still deployed (git HEAD) read workspace_id from the TOP LEVEL of
     * a plain base64 JSON state. A state signed by the fixed code is {payload, sig}, so that older callback finds no
     * workspace_id and shows "Missing workspace_id in OAuth state parameter." This happens when Google is told to
     * redirect to a server that still runs the old code (GOOGLE_REDIRECT_URI -> production) while the flow was started
     * on a server that runs the fix.
     */
    public function test_documents_why_an_older_callback_reports_a_missing_workspace_id(): void
    {
        $state = $this->startFlow($this->admin, $this->wsA->id);

        // The old callback decoding, verbatim.
        $decoded = json_decode(base64_decode($state), true);
        $legacyWorkspaceId = is_array($decoded) && isset($decoded['workspace_id']) ? (int) $decoded['workspace_id'] : null;
        $this->assertNull($legacyWorkspaceId, 'old callbacks cannot read the new signed envelope');

        // ...while the fixed callback reads the very same state correctly.
        $this->assertSame($this->wsA->id, $this->claimsOf($state)['workspace_id']);
    }

    public function test_connect_warns_when_google_will_redirect_to_a_different_host(): void
    {
        Sanctum::actingAs($this->admin);

        // Match the host the test client uses so the first request is the "same host" case.
        config(['services.google.redirect_uri' => 'http://' . parse_url(config('app.url'), PHP_URL_HOST) . '/api/youtube/callback']);
        $this->app->instance(YouTubeService::class, $this->makeService());
        $same = $this->getJson("/api/v1/youtube/connect?workspace_id={$this->wsA->id}&force=1");
        $same->assertOk();
        $this->assertArrayNotHasKey('warning', $same->json());

        config(['services.google.redirect_uri' => 'https://mc-api.example.test/api/youtube/callback']);
        $this->app->instance(YouTubeService::class, $this->makeService());
        $other = $this->getJson("/api/v1/youtube/connect?workspace_id={$this->wsA->id}&force=1");
        $other->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('mc-api.example.test', $other->json('warning'));
        $this->assertStringContainsString(parse_url(config('app.url'), PHP_URL_HOST), $other->json('warning'));
        $this->assertStringNotContainsString('SECRET', $other->getContent());
    }

    // ── Reconnect / existing connections ──────────────────────────────────────
    public function test_reconnecting_the_same_channel_updates_in_place_and_keeps_the_refresh_token(): void
    {
        $this->oauthCallback($this->startFlow($this->admin, $this->wsA->id));
        $firstId = YouTubeConnection::sole()->id;

        // Google omits the refresh token on re-consent and the channel was renamed
        $this->tokens = ['access_token' => 'ya29.second-access', 'expires_in' => 3600];
        $this->channel = $this->channel('UC_AAA', 'Channel A renamed');
        $this->oauthCallback($this->startFlow($this->admin, $this->wsA->id))->assertSee('YouTube Channel Connected!');

        $conn = YouTubeConnection::sole();
        $this->assertSame($firstId, $conn->id, 'no duplicate connection row');
        $this->assertSame('Channel A renamed', $conn->channel_name);
        $this->assertSame('ya29.second-access', $conn->access_token);
        $this->assertSame(self::REFRESH, $conn->refresh_token, 'refresh token must not be wiped');
        $this->assertSame(1, Integration::where('platform', 'youtube')->count(), 'no duplicate integration row');
        $this->assertSame(self::REFRESH, Integration::where('platform', 'youtube')->first()->refresh_token);
    }

    public function test_reconnecting_revives_a_disconnected_or_soft_deleted_integration_row(): void
    {
        $this->oauthCallback($this->startFlow($this->admin, $this->wsA->id));
        $integ = Integration::where('platform', 'youtube')->sole();
        $integ->update(['is_connected' => false, 'connection_status' => 'error']);
        $integ->delete();

        $this->oauthCallback($this->startFlow($this->admin, $this->wsA->id))->assertSee('YouTube Channel Connected!');

        $this->assertSame(1, Integration::withTrashed()->where('platform', 'youtube')->count());
        $fresh = Integration::where('platform', 'youtube')->sole();
        $this->assertTrue($fresh->is_connected);
        $this->assertSame('connected', $fresh->connection_status);
    }

    public function test_a_channel_owned_by_another_client_is_never_silently_moved(): void
    {
        $this->oauthCallback($this->startFlow($this->admin, $this->wsA->id));

        $res = $this->oauthCallback($this->startFlow($this->admin, $this->wsB->id)); // same channel UC_AAA
        $res->assertOk()->assertSee('YouTube Connection Error')->assertSee('already connected to a different client workspace');

        $this->assertSame($this->wsA->id, (int) YouTubeConnection::sole()->workspace_id, 'stays with client A');
        $this->assertSame(0, Integration::where('workspace_id', $this->wsB->id)->count());
    }

    public function test_a_channel_whose_workspace_no_longer_exists_can_be_claimed(): void
    {
        YouTubeConnection::create(['user_id' => $this->admin->id, 'workspace_id' => null, 'channel_id' => 'UC_AAA', 'channel_name' => 'Orphan', 'access_token' => 'old']);

        $this->oauthCallback($this->startFlow($this->admin, $this->wsB->id))->assertSee('YouTube Channel Connected!');

        $this->assertSame(1, YouTubeConnection::count());
        $this->assertSame($this->wsB->id, (int) YouTubeConnection::sole()->workspace_id);
    }

    // ── Switching between client workspaces ───────────────────────────────────
    public function test_each_client_gets_its_own_channel_when_switching_workspaces(): void
    {
        $this->channel = $this->channel('UC_AAA', 'Channel A');
        $this->oauthCallback($this->startFlow($this->admin, $this->wsA->id));

        $this->channel = $this->channel('UC_BBB', 'Channel B');
        $this->tokens = ['access_token' => 'ya29.b-access', 'refresh_token' => '1//b-refresh', 'expires_in' => 3600];
        $this->oauthCallback($this->startFlow($this->admin, $this->wsB->id));

        $this->assertSame('UC_AAA', YouTubeConnection::where('workspace_id', $this->wsA->id)->sole()->channel_id);
        $this->assertSame('UC_BBB', YouTubeConnection::where('workspace_id', $this->wsB->id)->sole()->channel_id);
        $this->assertSame('UC_AAA', Integration::where('workspace_id', $this->wsA->id)->sole()->account_id);
        $this->assertSame('UC_BBB', Integration::where('workspace_id', $this->wsB->id)->sole()->account_id);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/youtube/status?workspace_id={$this->wsA->id}")->assertJsonPath('data.channel_id', 'UC_AAA');
        $this->getJson("/api/v1/youtube/status?workspace_id={$this->wsB->id}")->assertJsonPath('data.channel_id', 'UC_BBB');
    }

    public function test_status_without_a_workspace_no_longer_falls_back_to_the_first_workspace(): void
    {
        $this->oauthCallback($this->startFlow($this->admin, $this->wsA->id));

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/youtube/status')->assertStatus(400)->assertJsonPath('success', false);
    }

    // ── Integrity + error hygiene ─────────────────────────────────────────────
    public function test_service_refuses_a_missing_workspace_before_touching_the_database(): void
    {
        $real = new YouTubeService();

        try {
            $real->saveChannelConnection($this->admin->id, $this->tokens, $this->channel, 6);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('no longer exists', $e->getMessage());
        }

        $this->assertSame(0, YouTubeConnection::count());
        $this->assertSame(0, Integration::count());
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys, 'FK enforcement stays ON (never bypassed)');
    }

    public function test_connection_and_integration_roll_back_together(): void
    {
        $real = new YouTubeService();
        Integration::saving(function (Integration $i) {
            if ($i->platform === 'youtube') {
                throw new \RuntimeException('boom');
            }
        });

        try {
            $real->saveChannelConnection($this->admin->id, $this->tokens, $this->channel, $this->wsA->id);
            $this->fail('expected failure');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, YouTubeConnection::count(), 'no half-saved connection');
        $this->assertSame(0, Integration::count());
    }

    public function test_database_errors_are_sanitised_for_the_user_and_the_log(): void
    {
        $leak = new QueryException(
            'mysql',
            'insert into `integrations` (`refresh_token`) values (' . self::REFRESH . ')',
            [self::REFRESH],
            new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (`marketing_command`.`integrations`, CONSTRAINT `integrations_workspace_id_foreign`)')
        );
        $this->svc->throw = $leak;

        $logged = [];
        \Illuminate\Support\Facades\Log::listen(function ($m) use (&$logged) {
            $logged[] = $m->message . ' ' . json_encode($m->context);
        });

        $res = $this->oauthCallback($this->startFlow($this->admin, $this->wsA->id));
        $html = $res->getContent();

        foreach (['SQLSTATE', '1452', 'insert into', 'integrations_workspace_id_foreign', 'marketing_command', 'SECRET'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "user-facing page leaked: {$needle}");
            $this->assertStringNotContainsString($needle, implode("\n", $logged), "log leaked: {$needle}");
        }
        $res->assertSee('database error occurred');
    }

    public function test_unknown_exceptions_never_echo_their_message(): void
    {
        $this->svc->throw = new \RuntimeException('Bearer ' . self::ACCESS . ' rejected by upstream');

        $html = $this->oauthCallback($this->startFlow($this->admin, $this->wsA->id))->getContent();

        $this->assertStringNotContainsString('SECRET', $html);
        $this->assertStringContainsString('Could not complete the YouTube connection', $html);
    }
}
