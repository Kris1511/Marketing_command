<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAndTeamApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    public function test_workspace_creation_validates_email_budget_and_status(): void
    {
        $this->postJson('/api/v1/workspaces', ['name' => 'A', 'primary_contact_email' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('primary_contact_email');
        $this->postJson('/api/v1/workspaces', ['name' => 'A', 'budget' => -1])
            ->assertStatus(422)->assertJsonValidationErrors('budget');
        $this->postJson('/api/v1/workspaces', ['name' => 'A', 'budget' => 1e15])
            ->assertStatus(422)->assertJsonValidationErrors('budget');
        $this->postJson('/api/v1/workspaces', ['name' => 'A', 'status' => 'bogus'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_workspace_setup_status_is_stored_as_a_valid_enum_value(): void
    {
        $this->postJson('/api/v1/workspaces', ['name' => 'Acme', 'status' => 'setup', 'budget' => 250.5])
            ->assertStatus(201);

        $this->assertDatabaseHas('workspaces', ['name' => 'Acme', 'status' => 'pending', 'budget' => 250.5]);
        $this->assertSame($this->admin->id, Workspace::where('name', 'Acme')->value('owner_id'));
    }

    public function test_team_roles_are_validated_on_create_and_update(): void
    {
        $this->postJson('/api/v1/team', ['name' => 'Eve', 'email' => 'eve@example.com', 'role' => 'superuser'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'eve@example.com']);

        $member = User::factory()->create(['role' => 'viewer']);
        $this->putJson("/api/v1/team/{$member->id}", ['role' => 'superuser'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertSame('viewer', $member->fresh()->role);

        $this->putJson("/api/v1/team/{$member->id}", ['role' => 'Marketing Manager'])->assertOk();
        $this->assertSame('manager', $member->fresh()->role);
    }

    public function test_team_member_deactivation_and_self_protection(): void
    {
        $member = User::factory()->create(['role' => 'executive']);

        $this->deleteJson("/api/v1/team/{$member->id}")->assertOk();
        $this->assertFalse((bool) $member->fresh()->is_active);

        $this->deleteJson("/api/v1/team/{$this->admin->id}")->assertStatus(403);
        $this->assertTrue((bool) $this->admin->fresh()->is_active);
    }

    public function test_integration_test_and_disconnect_require_a_platform(): void
    {
        $this->postJson('/api/v1/integrations/test', ['workspace_id' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('platform');
        $this->postJson('/api/v1/integrations/disconnect', ['workspace_id' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('platform');
    }

    public function test_report_email_validates_recipient(): void
    {
        $this->postJson('/api/v1/reports/send-client-email', ['email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/reports/send-client-email', ['workspace_id' => 9999])
            ->assertStatus(422)->assertJsonValidationErrors('workspace_id');
    }
}
