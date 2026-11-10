<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public static function protectedEndpoints(): array
    {
        return [
            'list posts' => ['GET', '/api/v1/posts'],
            'create post' => ['POST', '/api/v1/posts'],
            'delete post' => ['DELETE', '/api/v1/posts/1'],
            'list integrations' => ['GET', '/api/v1/integrations'],
            'delete integration' => ['DELETE', '/api/v1/integrations/1'],
            'publish post' => ['POST', '/api/v1/facebook/publish-post'],
            'facebook posts' => ['GET', '/api/v1/facebook/posts'],
            'facebook inbox' => ['GET', '/api/v1/facebook/inbox/conversations'],
            'instagram inbox send' => ['POST', '/api/v1/instagram/inbox/send-message'],
            'youtube upload' => ['POST', '/api/v1/youtube/videos'],
            'youtube status' => ['GET', '/api/v1/youtube/status'],
            'twitter disconnect' => ['POST', '/api/v1/twitter/disconnect'],
            'twitter connect mock' => ['POST', '/api/v1/twitter/connect-mock'],
            'legacy twitter status' => ['GET', '/api/twitter/status'],
            'legacy youtube upload' => ['POST', '/api/youtube/videos'],
            'ga metrics' => ['GET', '/api/v1/google-analytics/metrics'],
            'gsc metrics' => ['GET', '/api/v1/search-console/metrics'],
            'ai caption' => ['POST', '/api/v1/ai/generate-caption'],
            'report email' => ['POST', '/api/v1/reports/send-client-email'],
            'reports analytics' => ['GET', '/api/v1/reports/analytics'],
            'workspaces' => ['GET', '/api/v1/workspaces'],
            'team' => ['GET', '/api/v1/team'],
            'leads' => ['GET', '/api/v1/leads'],
        ];
    }

    /**
     * @dataProvider protectedEndpoints
     */
    public function test_protected_endpoints_reject_anonymous_requests(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertStatus(401);
    }

    public function test_public_endpoints_stay_reachable_without_a_token(): void
    {
        $this->getJson('/api/v1/health')->assertSuccessful();
        $this->postJson('/api/v1/login', [])->assertStatus(422);
    }

    public function test_health_endpoint_reports_the_real_database_driver(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'database' => 'Connected ('.config('database.default').')']);
    }

    public function test_inbox_event_stream_requires_a_valid_token(): void
    {
        $this->getJson('/api/v1/facebook/inbox/events?workspace_id=1')->assertStatus(401);
        $this->getJson('/api/v1/instagram/inbox/events?workspace_id=1&token=not-a-real-token')->assertStatus(401);
    }

    public function test_login_returns_token_and_rejects_bad_credentials(): void
    {
        $user = User::factory()->create(['email' => 'login@example.com', 'password' => bcrypt('secret-pass')]);

        $this->postJson('/api/v1/login', ['email' => 'login@example.com', 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJson(['success' => false]);

        $this->postJson('/api/v1/login', ['email' => 'login@example.com', 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token']);
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->userWithRole('admin');
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_register_is_open_only_for_the_first_account(): void
    {
        $payload = ['name' => 'First Admin', 'email' => 'first@example.com', 'password' => 'secret-pass'];

        $this->postJson('/api/v1/register', $payload)->assertStatus(201)->assertJsonPath('user.role', 'admin');

        $this->postJson('/api/v1/register', ['name' => 'Intruder', 'email' => 'intruder@example.com', 'password' => 'secret-pass'])
            ->assertStatus(401);
        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
    }

    public function test_register_after_bootstrap_rejects_non_admins(): void
    {
        $manager = $this->userWithRole('manager');

        $this->withToken($manager->createToken('t')->plainTextToken)
            ->postJson('/api/v1/register', ['name' => 'New Person', 'email' => 'new@example.com', 'password' => 'secret-pass'])
            ->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_register_after_bootstrap_allows_administrators(): void
    {
        $admin = $this->userWithRole('admin');

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->postJson('/api/v1/register', ['name' => 'New Person', 'email' => 'new@example.com', 'password' => 'secret-pass'])
            ->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_viewer_is_read_only(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)->getJson('/api/v1/team')->assertOk();
        $this->actingAs($viewer)->postJson('/api/v1/posts', [])->assertStatus(403);
        $this->actingAs($viewer)->postJson('/api/v1/facebook/publish-post', [])->assertStatus(403);
        $this->actingAs($viewer)->postJson('/api/v1/leads', [])->assertStatus(403);
    }

    public function test_team_management_is_admin_only(): void
    {
        $payload = ['name' => 'Eve', 'email' => 'eve@example.com', 'role' => 'admin'];

        foreach (['manager', 'executive', 'viewer'] as $role) {
            $this->actingAs($this->userWithRole($role))->postJson('/api/v1/team', $payload)->assertStatus(403);
        }
        $this->assertDatabaseMissing('users', ['email' => 'eve@example.com']);

        $target = $this->userWithRole('viewer');
        $this->actingAs($this->userWithRole('manager'))->putJson("/api/v1/team/{$target->id}", ['role' => 'admin'])->assertStatus(403);
        $this->actingAs($this->userWithRole('manager'))->deleteJson("/api/v1/team/{$target->id}")->assertStatus(403);
        $this->assertSame('viewer', $target->fresh()->role);
        $this->assertTrue((bool) $target->fresh()->is_active);

        $this->actingAs($this->userWithRole('admin'))->postJson('/api/v1/team', $payload)->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'eve@example.com']);
    }

    public function test_integration_management_is_admin_only(): void
    {
        foreach (['manager', 'executive'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->postJson('/api/v1/integrations', [])->assertStatus(403);
            $this->actingAs($this->userWithRole($role))
                ->postJson('/api/v1/facebook/disconnect', [])->assertStatus(403);
            $this->actingAs($this->userWithRole($role))
                ->deleteJson('/api/v1/integrations/1')->assertStatus(403);
        }

        // Admins pass the permission check (the request itself then fails validation, which is not a 401/403).
        $status = $this->actingAs($this->userWithRole('admin'))->postJson('/api/v1/integrations', [])->getStatusCode();
        $this->assertNotContains($status, [401, 403]);
    }

    public function test_only_admins_and_managers_can_create_clients(): void
    {
        $this->actingAs($this->userWithRole('executive'))->postJson('/api/v1/workspaces', ['name' => 'Acme'])->assertStatus(403);

        foreach (['admin', 'manager'] as $role) {
            $status = $this->actingAs($this->userWithRole($role))->postJson('/api/v1/workspaces', ['name' => 'Acme'])->getStatusCode();
            $this->assertNotContains($status, [401, 403], "{$role} should be allowed to create clients");
        }
    }

    public function test_executive_can_still_publish_and_manage_leads(): void
    {
        $executive = $this->userWithRole('executive');

        $status = $this->actingAs($executive)->postJson('/api/v1/facebook/publish-post', [])->getStatusCode();
        $this->assertNotContains($status, [401, 403]);

        $status = $this->actingAs($executive)->postJson('/api/v1/leads', [])->getStatusCode();
        $this->assertNotContains($status, [401, 403]);
    }
}
