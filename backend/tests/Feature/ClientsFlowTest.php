<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Models\Workspace;
use App\Models\YouTubeConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientsFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    public function test_every_column_the_workspace_listing_queries_exists(): void
    {
        // SQLite silently treats an unknown "column" as a string literal, so assert the schema explicitly.
        foreach (['workspace_id', 'refresh_token', 'access_token'] as $column) {
            $this->assertTrue(Schema::hasColumn('youtube_connections', $column), "youtube_connections.{$column} is missing");
        }
    }

    public function test_created_client_is_saved_and_returned_by_the_listing(): void
    {
        $this->postJson('/api/v1/workspaces', [
            'name' => 'Northwind Traders',
            'industry' => 'Retail',
            'primary_contact' => 'Alex Doe',
            'primary_contact_email' => 'alex@northwind.test',
            'budget' => 2500,
        ])->assertStatus(201)->assertJsonPath('data.name', 'Northwind Traders');

        $this->assertDatabaseHas('workspaces', [
            'name' => 'Northwind Traders',
            'status' => 'active',
            'owner_id' => $this->admin->id,
        ]);

        $listing = $this->getJson('/api/v1/workspaces')->assertOk()->assertJsonPath('success', true);
        $client = collect($listing->json('data'))->firstWhere('name', 'Northwind Traders');

        $this->assertNotNull($client, 'newly created client is missing from GET /workspaces');
        $this->assertSame('Retail', $client['industry']);
        $this->assertSame(0, $client['channels_count']);
        $this->assertSame(0, $client['leads_count']);
        $this->assertSame($this->admin->name, $client['owner']['name']);
    }

    public function test_listing_returns_every_client_with_real_counts(): void
    {
        $alpha = Workspace::create(['name' => 'Alpha', 'status' => 'active']);
        Workspace::create(['name' => 'Beta', 'status' => 'pending']);
        Lead::create(['workspace_id' => $alpha->id, 'name' => 'L1', 'email' => 'l1@x.test', 'source' => 'manual_entry']);
        Lead::create(['workspace_id' => $alpha->id, 'name' => 'L2', 'email' => 'l2@x.test', 'source' => 'manual_entry']);
        YouTubeConnection::create([
            'workspace_id' => $alpha->id,
            'channel_id' => 'UC123',
            'channel_name' => 'Alpha Channel',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
        ]);

        $data = collect($this->getJson('/api/v1/workspaces')->assertOk()->json('data'));

        $this->assertCount(2, $data);
        $alphaRow = $data->firstWhere('name', 'Alpha');
        $this->assertSame(2, $alphaRow['leads_count']);
        $this->assertSame(1, $alphaRow['channels_count']);
        $this->assertContains('YouTube', $alphaRow['connected_channels']);
        $this->assertSame(0, $data->firstWhere('name', 'Beta')['channels_count']);
    }

    public function test_client_creation_errors_are_reported_and_nothing_is_saved(): void
    {
        $this->postJson('/api/v1/workspaces', ['name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('workspaces', 0);
    }
}
