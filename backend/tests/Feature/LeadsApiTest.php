<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $alpha;

    private Workspace $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'manager']);
        $this->alpha = Workspace::create(['name' => 'Alpha', 'status' => 'active']);
        $this->beta = Workspace::create(['name' => 'Beta', 'status' => 'active']);
        $this->actingAs($this->user);
    }

    public function test_lead_is_saved_in_the_selected_workspace_without_fabricated_phone(): void
    {
        $this->postJson('/api/v1/leads', [
            'workspace_id' => $this->beta->id,
            'name' => 'Priya Sharma',
            'email' => 'priya@acme.test',
        ])->assertStatus(201);

        $lead = Lead::where('email', 'priya@acme.test')->firstOrFail();
        $this->assertSame($this->beta->id, $lead->workspace_id);
        $this->assertNull($lead->phone);
        $this->assertSame($this->user->id, $lead->assigned_to_id);
    }

    public function test_lead_creation_validates_input(): void
    {
        $this->postJson('/api/v1/leads', ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/leads', ['name' => 'X', 'email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/leads', ['name' => 'X', 'phone' => str_repeat('9', 21)])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/leads', ['name' => 'X', 'workspace_id' => 9999])->assertStatus(422)->assertJsonValidationErrors('workspace_id');
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_lead_list_is_filtered_by_workspace_and_includes_owner(): void
    {
        Lead::create(['workspace_id' => $this->alpha->id, 'name' => 'A1', 'email' => 'a1@x.test', 'source' => 'manual_entry', 'assigned_to_id' => $this->user->id]);
        Lead::create(['workspace_id' => $this->beta->id, 'name' => 'B1', 'email' => 'b1@x.test', 'source' => 'manual_entry']);

        $response = $this->getJson('/api/v1/leads?workspace_id='.$this->alpha->id)->assertOk();
        $this->assertSame(['A1'], collect($response->json('data'))->pluck('name')->all());
        $this->assertSame($this->user->name, $response->json('data.0.assigned_to.name'));

        $this->assertCount(2, $this->getJson('/api/v1/leads')->assertOk()->json('data'));
    }

    public function test_lead_status_update_is_validated_and_stamps_timestamps(): void
    {
        $lead = Lead::create(['workspace_id' => $this->alpha->id, 'name' => 'A1', 'email' => 'a1@x.test', 'source' => 'manual_entry']);

        $this->patchJson("/api/v1/leads/{$lead->id}", ['status' => 'bogus'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('new', $lead->fresh()->status);

        $this->patchJson("/api/v1/leads/{$lead->id}", ['status' => 'contacted'])->assertOk();
        $this->assertNotNull($lead->fresh()->contacted_at);

        $this->patchJson("/api/v1/leads/{$lead->id}", ['status' => 'converted'])->assertOk();
        $fresh = $lead->fresh();
        $this->assertSame('converted', $fresh->status);
        $this->assertNotNull($fresh->converted_at);

        $this->patchJson('/api/v1/leads/99999', ['status' => 'qualified'])->assertStatus(404);
    }
}
