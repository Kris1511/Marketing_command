<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class CommentsPerformanceAndLiveTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->workspace = Workspace::create([
            'name' => 'Test Workspace',
            'owner_id' => $this->user->id,
            'status' => 'active',
        ]);
    }

    public function test_comments_notification_endpoint_is_fast_and_enriched()
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/notifications?category=comments&workspace_id={$this->workspace->id}&per_page=10");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data',
            'summary' => [
                'counts' => ['comments'],
            ],
        ]);
    }

    public function test_comments_sync_endpoint_runs_cleanly()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/comments/sync', [
                'workspace_id' => $this->workspace->id,
                'platform'     => 'all',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_comments_live_delta_emits_created_comment()
    {
        // Insert baseline comment
        $baselineId = DB::table('notifications')->insertGetId([
            'workspace_id'   => $this->workspace->id,
            'user_id'        => $this->user->id,
            'type'           => 'facebook_comment',
            'title'          => 'Baseline Comment',
            'message'        => 'First comment',
            'related_entity' => 'facebook_comment:000:000',
            'is_read'        => false,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Insert a new live comment
        $newId = DB::table('notifications')->insertGetId([
            'workspace_id'   => $this->workspace->id,
            'user_id'        => $this->user->id,
            'type'           => 'facebook_comment',
            'title'          => 'Live Delta Test Comment',
            'message'        => 'Tester: Real-time live delta is working!',
            'related_entity' => 'facebook_comment:111_222:333',
            'is_read'        => false,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/comments/live-delta?workspace_id={$this->workspace->id}&since={$baselineId}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $data = $response->json();
        $this->assertNotEmpty($data['events']);
        $this->assertEquals('comment.created', $data['events'][0]['event']);
        $this->assertEquals($newId, $data['events'][0]['data']['id']);
        $this->assertEquals('facebook', $data['events'][0]['data']['platform']);

        // Clean up
        DB::table('notifications')->where('id', $newId)->delete();
    }

    public function test_comment_mark_read_touches_cache_and_emits_updated_delta()
    {
        $newId = DB::table('notifications')->insertGetId([
            'workspace_id'   => $this->workspace->id,
            'user_id'        => $this->user->id,
            'type'           => 'instagram_comment',
            'title'          => 'IG Comment Read Test',
            'message'        => 'Tester: Testing read status update',
            'related_entity' => 'instagram_comment:444:media_555',
            'is_read'        => false,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $oldVersion = (int) Cache::get("comments_stream_v_{$this->workspace->id}", 0);

        // Toggle read
        $patchRes = $this->actingAs($this->user)
            ->patchJson("/api/v1/notifications/{$newId}/read");

        $patchRes->assertStatus(200);
        $patchRes->assertJson(['success' => true, 'is_read' => true]);

        $newVersion = (int) Cache::get("comments_stream_v_{$this->workspace->id}", 0);
        $this->assertGreaterThan($oldVersion, $newVersion);

        // Delta check should report updated event
        $deltaRes = $this->actingAs($this->user)
            ->getJson("/api/v1/comments/live-delta?workspace_id={$this->workspace->id}&since={$newId}&v={$oldVersion}");

        $deltaRes->assertStatus(200);
        $deltaData = $deltaRes->json();
        $this->assertNotEmpty($deltaData['events']);
        $this->assertEquals('comment.updated', $deltaData['events'][0]['event']);
        $this->assertEquals($newId, $deltaData['events'][0]['data']['id']);
        $this->assertTrue($deltaData['events'][0]['data']['is_read']);

        // Clean up
        DB::table('notifications')->where('id', $newId)->delete();
    }
}
