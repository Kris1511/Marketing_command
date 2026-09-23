<?php

namespace Tests\Feature;

use App\Models\FacebookMessengerWebhookEvent;
use App\Models\FacebookPage;
use App\Models\Integration;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_instagram_conversations_returns_404_when_no_account_connected(): void
    {
        $workspace = Workspace::create([
            'name'   => 'Empty Workspace',
            'status' => 'active',
        ]);

        $response = $this->getJson("/api/v1/instagram/inbox/conversations?workspace_id={$workspace->id}");

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'code'    => 'NOT_CONNECTED',
            ]);
    }

    public function test_instagram_conversations_returns_403_when_permission_required(): void
    {
        $workspace = Workspace::create([
            'name'   => 'IG Workspace',
            'status' => 'active',
        ]);

        $page = FacebookPage::create([
            'workspace_id'      => $workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'mock-access-token',
            'token_status'      => 'valid',
        ]);

        Integration::create([
            'workspace_id'      => $workspace->id,
            'platform'          => 'instagram',
            'account_id'        => '17841447923134069',
            'account_name'      => '@redmindtechnologies',
            'refresh_token'     => 'mock-access-token',
            'is_connected'      => true,
            'connection_status' => 'connected',
        ]);

        Http::fake([
            'https://graph.facebook.com/v23.0/115864121526929/conversations*' => Http::response([
                'error' => [
                    'message'   => '(#230) Requires instagram_manage_messages permission to manage the object',
                    'type'      => 'OAuthException',
                    'code'      => 230,
                    'fbtrace_id'=> 'test_trace_id',
                ],
            ], 403),
        ]);

        $response = $this->getJson("/api/v1/instagram/inbox/conversations?workspace_id={$workspace->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success'            => false,
                'code'               => 'PERMISSION_REQUIRED',
                'missing_permission' => 'instagram_manage_messages',
                'meta_code'          => 230,
            ]);
    }

    public function test_instagram_conversations_maps_real_format_when_meta_succeeds(): void
    {
        $workspace = Workspace::create([
            'name'   => 'IG Workspace',
            'status' => 'active',
        ]);

        $page = FacebookPage::create([
            'workspace_id'      => $workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'mock-access-token',
            'token_status'      => 'valid',
        ]);

        Http::fake([
            'https://graph.facebook.com/v23.0/115864121526929/conversations*' => Http::response([
                'data' => [
                    [
                        'id' => 'ig_conv_123',
                        'updated_time' => '2026-03-24T10:00:00+0000',
                        'unread_count' => 1,
                        'message_count' => 4,
                        'snippet' => 'Hey there on Instagram!',
                        'participants' => [
                            'data' => [
                                [
                                    'id' => 'igsid_customer_999',
                                    'username' => 'sarah_designer',
                                    'name' => 'Sarah Connor',
                                    'profile_pic' => 'https://example.com/sarah.jpg',
                                ],
                                [
                                    'id' => '115864121526929',
                                    'username' => 'redmindtechnologies',
                                    'name' => 'RedMind Technologies',
                                ],
                            ],
                        ],
                    ],
                ],
                'paging' => [
                    'cursors' => [
                        'before' => 'cur_before',
                        'after'  => 'cur_after',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson("/api/v1/instagram/inbox/conversations?workspace_id={$workspace->id}");

        $response->assertOk()
            ->assertJson([
                'success'  => true,
                'platform' => 'instagram',
                'data'     => [
                    [
                        'id' => 'ig_conv_123',
                        'unread_count' => 1,
                        'message_count' => 4,
                        'platform' => 'instagram',
                        'customer' => [
                            'name' => 'Sarah Connor',
                            'username' => 'sarah_designer',
                            'id' => 'igsid_customer_999',
                            'profile_picture_url' => 'https://example.com/sarah.jpg',
                        ],
                        'latest_message' => [
                            'message' => 'Hey there on Instagram!',
                        ],
                    ],
                ],
                'paging' => [
                    'after' => 'cur_after',
                ],
            ]);
    }

    public function test_instagram_conversations_merges_database_webhook_events(): void
    {
        $workspace = Workspace::create([
            'name'   => 'IG Workspace',
            'status' => 'active',
        ]);

        $page = FacebookPage::create([
            'workspace_id'      => $workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'mock-access-token',
            'token_status'      => 'valid',
        ]);

        Integration::create([
            'workspace_id'      => $workspace->id,
            'platform'          => 'instagram',
            'account_id'        => '17841447923134069',
            'account_name'      => '@redmindtechnologies',
            'refresh_token'     => 'mock-access-token',
            'is_connected'      => true,
            'connection_status' => 'connected',
        ]);

        // When Meta API returns empty array (e.g. mobile toggle pending or first DM)
        Http::fake([
            'https://graph.facebook.com/v23.0/115864121526929/conversations*' => Http::response([
                'data' => [],
            ], 200),
        ]);

        // Insert incoming Instagram DM stored via webhook
        FacebookMessengerWebhookEvent::create([
            'workspace_id'      => $workspace->id,
            'facebook_page_id'  => $page->id,
            'page_id'           => '17841447923134069',
            'sender_id'         => 'cust_ig_555',
            'recipient_id'      => '17841447923134069',
            'message_id'        => 'mid_ig_msg_999',
            'event_key'         => '17841447923134069:message:mid_ig_msg_999',
            'event_type'        => 'message',
            'event_timestamp'   => round(microtime(true) * 1000),
            'payload'           => [
                'sender' => ['id' => 'cust_ig_555', 'username' => 'alex_dev'],
                'recipient' => ['id' => '17841447923134069'],
                'message' => ['mid' => 'mid_ig_msg_999', 'text' => 'Hello from Instagram DM!'],
            ],
            'processed_at'      => now(),
        ]);

        $response = $this->getJson("/api/v1/instagram/inbox/conversations?workspace_id={$workspace->id}");

        $response->assertOk()
            ->assertJson([
                'success'  => true,
                'platform' => 'instagram',
                'data'     => [
                    [
                        'id'             => 'ig_cust_ig_555',
                        'unread_count'   => 1,
                        'message_count'  => 1,
                        'customer'       => [
                            'id'       => 'cust_ig_555',
                            'username' => 'alex_dev',
                        ],
                        'latest_message' => [
                            'message'   => 'Hello from Instagram DM!',
                            'direction' => 'inbound',
                        ],
                    ],
                ],
            ]);
    }

    public function test_instagram_messages_fetches_from_database_for_local_thread(): void
    {
        $workspace = Workspace::create([
            'name'   => 'IG Workspace',
            'status' => 'active',
        ]);

        $page = FacebookPage::create([
            'workspace_id'      => $workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'mock-access-token',
            'token_status'      => 'valid',
        ]);

        Integration::create([
            'workspace_id'      => $workspace->id,
            'platform'          => 'instagram',
            'account_id'        => '17841447923134069',
            'account_name'      => '@redmindtechnologies',
            'refresh_token'     => 'mock-access-token',
            'is_connected'      => true,
            'connection_status' => 'connected',
        ]);

        // Inbound message
        FacebookMessengerWebhookEvent::create([
            'workspace_id'      => $workspace->id,
            'facebook_page_id'  => $page->id,
            'page_id'           => '17841447923134069',
            'sender_id'         => 'cust_ig_555',
            'recipient_id'      => '17841447923134069',
            'message_id'        => 'mid_inbound_1',
            'event_key'         => '17841447923134069:message:mid_inbound_1',
            'event_type'        => 'message',
            'event_timestamp'   => 1700000000000,
            'payload'           => [
                'sender' => ['id' => 'cust_ig_555', 'username' => 'alex_dev'],
                'recipient' => ['id' => '17841447923134069'],
                'message' => ['mid' => 'mid_inbound_1', 'text' => 'Can you help me with pricing?'],
            ],
            'processed_at'      => now(),
        ]);

        // Outbound reply
        FacebookMessengerWebhookEvent::create([
            'workspace_id'      => $workspace->id,
            'facebook_page_id'  => $page->id,
            'page_id'           => '17841447923134069',
            'sender_id'         => '17841447923134069',
            'recipient_id'      => 'cust_ig_555',
            'message_id'        => 'mid_outbound_2',
            'event_key'         => '17841447923134069:outbound:mid_outbound_2',
            'event_type'        => 'message',
            'event_timestamp'   => 1700000050000,
            'payload'           => [
                'sender' => ['id' => '17841447923134069'],
                'recipient' => ['id' => 'cust_ig_555'],
                'message' => ['mid' => 'mid_outbound_2', 'text' => 'Sure! Our starter plan is $49/mo.'],
            ],
            'processed_at'      => now(),
        ]);

        $response = $this->getJson("/api/v1/instagram/inbox/conversations/ig_cust_ig_555/messages?workspace_id={$workspace->id}");

        $response->assertOk()
            ->assertJson([
                'success'  => true,
                'platform' => 'instagram',
                'data'     => [
                    [
                        'id'        => 'mid_inbound_1',
                        'message'   => 'Can you help me with pricing?',
                        'direction' => 'inbound',
                    ],
                    [
                        'id'        => 'mid_outbound_2',
                        'message'   => 'Sure! Our starter plan is $49/mo.',
                        'direction' => 'outbound',
                    ],
                ],
            ]);
    }

    public function test_instagram_send_message_dispatches_to_meta_and_persists_to_db(): void
    {
        $workspace = Workspace::create([
            'name'   => 'IG Workspace',
            'status' => 'active',
        ]);

        $page = FacebookPage::create([
            'workspace_id'      => $workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'mock-access-token',
            'token_status'      => 'valid',
        ]);

        Integration::create([
            'workspace_id'      => $workspace->id,
            'platform'          => 'instagram',
            'account_id'        => '17841447923134069',
            'account_name'      => '@redmindtechnologies',
            'refresh_token'     => 'mock-access-token',
            'is_connected'      => true,
            'connection_status' => 'connected',
        ]);

        Http::fake([
            'https://graph.facebook.com/v23.0/115864121526929/messages' => Http::response([
                'recipient_id' => 'cust_ig_555',
                'message_id'   => 'm_mid_sent_9999',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/instagram/inbox/send-message', [
            'workspace_id'    => $workspace->id,
            'conversation_id' => 'ig_cust_ig_555',
            'message'         => 'Hello back to you!',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Reply sent through Instagram Direct.',
                'data'    => [
                    'message_id' => 'm_mid_sent_9999',
                    'direction'  => 'outbound',
                ],
            ]);

        $this->assertDatabaseHas('facebook_messenger_webhook_events', [
            'workspace_id' => $workspace->id,
            'message_id'   => 'm_mid_sent_9999',
            'recipient_id' => 'cust_ig_555',
        ]);
    }

    public function test_instagram_webhook_receive_stores_event(): void
    {
        $workspace = Workspace::create([
            'name'   => 'IG Workspace',
            'status' => 'active',
        ]);

        $page = FacebookPage::create([
            'workspace_id'      => $workspace->id,
            'page_id'           => '115864121526929',
            'page_name'         => 'RedMind Technologies',
            'page_access_token' => 'mock-access-token',
            'token_status'      => 'valid',
        ]);

        Integration::create([
            'workspace_id'      => $workspace->id,
            'platform'          => 'instagram',
            'account_id'        => '17841447923134069',
            'account_name'      => '@redmindtechnologies',
            'refresh_token'     => 'mock-access-token',
            'is_connected'      => true,
            'connection_status' => 'connected',
        ]);

        $payload = [
            'object' => 'instagram',
            'entry' => [
                [
                    'id' => '17841447923134069',
                    'time' => 1700000000,
                    'messaging' => [
                        [
                            'sender' => ['id' => 'cust_from_webhook_123'],
                            'recipient' => ['id' => '17841447923134069'],
                            'timestamp' => 1700000000000,
                            'message' => [
                                'mid' => 'm_webhook_mid_123',
                                'text' => 'Hi from webhook!',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/webhook/instagram', $payload);

        $response->assertOk()
            ->assertJson([
                'success'   => true,
                'processed' => 1,
            ]);

        $this->assertDatabaseHas('facebook_messenger_webhook_events', [
            'workspace_id' => $workspace->id,
            'message_id'   => 'm_webhook_mid_123',
            'sender_id'    => 'cust_from_webhook_123',
        ]);

        // Verify that poll retrieves this event for the workspace
        $pollResponse = $this->getJson("/api/v1/instagram/inbox/events/poll?workspace_id={$workspace->id}&since=0");
        $pollResponse->assertOk()
            ->assertJson([
                'success'      => true,
                'workspace_id' => $workspace->id,
            ]);

        $this->assertCount(1, $pollResponse->json('events'));
        $this->assertEquals('m_webhook_mid_123', $pollResponse->json('events.0.message_id'));
    }
}
