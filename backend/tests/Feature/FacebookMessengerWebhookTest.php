<?php

namespace Tests\Feature;

use App\Models\FacebookMessengerWebhookEvent;
use App\Models\FacebookPage;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacebookMessengerWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_facebook_webhook_verification_returns_meta_challenge(): void
    {
        config(['services.facebook.webhook_verify_token' => 'verify-token']);

        $response = $this->get('/api/v1/webhook/facebook?hub.mode=subscribe&hub.verify_token=verify-token&hub.challenge=challenge-123');

        $response->assertOk();
        $this->assertSame('challenge-123', $response->getContent());
    }

    public function test_facebook_messenger_webhook_stores_message_once(): void
    {
        config(['services.facebook.webhook_verify_token' => 'verify-token']);

        $workspace = Workspace::create([
            'name' => 'Webhook Workspace',
            'status' => 'active',
        ]);

        $page = FacebookPage::create([
            'workspace_id' => $workspace->id,
            'page_id' => '115864121526929',
            'page_name' => 'RedMind Technologies',
            'page_access_token' => 'fake-page-token',
            'token_status' => 'valid',
        ]);

        $payload = [
            'object' => 'page',
            'entry' => [
                [
                    'id' => $page->page_id,
                    'time' => 1797590000,
                    'messaging' => [
                        [
                            'sender' => ['id' => 'psid-123'],
                            'recipient' => ['id' => $page->page_id],
                            'timestamp' => 1797590001,
                            'message' => [
                                'mid' => 'm_test_message_1',
                                'text' => 'Hello from Facebook',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $first = $this->postJson('/api/v1/webhook/facebook', $payload);
        $second = $this->postJson('/api/v1/webhook/facebook', $payload);

        $first->assertOk()->assertJson([
            'success' => true,
            'processed' => 1,
            'duplicates' => 0,
        ]);
        $second->assertOk()->assertJson([
            'success' => true,
            'processed' => 0,
            'duplicates' => 1,
        ]);

        $this->assertSame(1, FacebookMessengerWebhookEvent::count());
        $event = FacebookMessengerWebhookEvent::first();
        $this->assertSame($workspace->id, $event->workspace_id);
        $this->assertSame($page->id, $event->facebook_page_id);
        $this->assertSame('m_test_message_1', $event->message_id);
        $this->assertSame('psid-123', $event->sender_id);
    }
}
