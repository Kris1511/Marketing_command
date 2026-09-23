<?php

namespace App\Http\Controllers;

use App\Models\FacebookMessengerWebhookEvent;
use App\Services\WorkspaceSocialAccounts;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramInboxController extends Controller
{
    /**
     * Pre-configured HTTP client for Meta Graph API requests.
     */
    protected function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withoutVerifying()
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]]);
    }

    /**
     * GET /api/v1/instagram/inbox/conversations
     * Fetch real Instagram Direct Message conversations from Meta Graph API
     * and merge with local webhook/reply events in database.
     */
    public function conversations(Request $request, WorkspaceSocialAccounts $socialAccounts)
    {
        $validated = $request->validate([
            'workspace_id' => 'required|integer|exists:workspaces,id',
            'limit'        => 'nullable|integer|min:1|max:50',
            'after'        => 'nullable|string',
            'fresh'        => 'nullable|boolean',
        ]);

        $workspaceId = (int) $validated['workspace_id'];
        $limit       = min(50, max(1, (int) ($validated['limit'] ?? 20)));
        $after       = $validated['after'] ?? null;
        $isFresh     = $request->boolean('fresh');

        $fbPage = $socialAccounts->facebook($workspaceId);
        $igIntegration = $socialAccounts->instagram($workspaceId);

        if (!$fbPage && !$igIntegration) {
            return response()->json([
                'success' => false,
                'code'    => 'NOT_CONNECTED',
                'message' => 'No connected Instagram Professional account was found for this workspace. Please connect Instagram in Integrations.',
            ], 404);
        }

        $token = $fbPage?->page_access_token ?: $igIntegration?->refresh_token;
        $pageId = (string) ($fbPage?->page_id ?? '');
        $igAccountId = (string) ($igIntegration?->account_id ?? '');

        if (empty($token)) {
            return response()->json([
                'success' => false,
                'code'    => 'NOT_CONNECTED',
                'message' => 'Valid access token not found for the connected Instagram account.',
            ], 404);
        }

        // Smart cache layer
        $version = Cache::get("ig_inbox_conv_v_{$workspaceId}", 1);
        $cacheKey = "ig_inbox_conv_{$workspaceId}_v{$version}_" . md5("{$limit}_{$after}");

        if (!$isFresh && Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $targetNode = !empty($pageId) ? $pageId : $igAccountId;
        $params = [
            'platform'     => 'instagram',
            'fields'       => 'id,updated_time,unread_count,message_count,participants,snippet',
            'limit'        => $limit,
            'access_token' => $token,
        ];
        if (!empty($after)) {
            $params['after'] = $after;
        }

        $response = $this->client()->get("https://graph.facebook.com/v23.0/{$targetNode}/conversations", $params);

        if (!$response->successful()) {
            $errorCode = (int) $response->json('error.code');
            $errorMessage = (string) ($response->json('error.message') ?: 'Instagram Direct conversations could not be loaded.');

            // Detect permission errors: #230, #3, #10 or permission-related message
            if ($errorCode === 230 || $errorCode === 3 || $errorCode === 10 || stripos($errorMessage, 'instagram_manage_messages') !== false || stripos($errorMessage, 'permission') !== false) {
                return response()->json([
                    'success'            => false,
                    'code'               => 'PERMISSION_REQUIRED',
                    'missing_permission' => 'instagram_manage_messages',
                    'message'            => 'Instagram messaging permission (instagram_manage_messages) is required to view and send Instagram Direct Messages.',
                    'account'            => [
                        'id'                  => $igAccountId,
                        'name'                => $igIntegration?->account_name ?: ($fbPage?->page_name . ' Instagram'),
                        'username'            => ltrim((string) $igIntegration?->account_name, '@'),
                        'page_id'             => $pageId,
                        'profile_picture_url' => null,
                    ],
                    'reconnect_url'      => "/api/v1/auth/facebook?workspace_id={$workspaceId}&force=1",
                    'meta_code'          => $errorCode,
                    'meta_message'       => $errorMessage,
                ], 403);
            }

            return response()->json([
                'success'   => false,
                'code'      => 'META_API_ERROR',
                'message'   => $errorMessage,
                'meta_code' => $errorCode,
            ], 502);
        }

        // 1. Process Meta Graph API conversations
        $metaConversations = collect($response->json('data') ?? [])->map(function ($conversation) use ($pageId, $igAccountId) {
            $participants = collect(data_get($conversation, 'participants.data', []));
            $customer = $participants->first(function ($participant) use ($pageId, $igAccountId) {
                $id = (string) ($participant['id'] ?? '');
                return $id !== $pageId && $id !== $igAccountId;
            }) ?? $participants->first();

            $customerName = (string) ($customer['name'] ?? $customer['username'] ?? 'Instagram user');
            $customerUsername = (string) ($customer['username'] ?? '');
            $snippet = $conversation['snippet'] ?? null;

            return [
                'id'             => $conversation['id'] ?? null,
                'updated_time'   => $conversation['updated_time'] ?? null,
                'unread_count'   => (int) ($conversation['unread_count'] ?? 0),
                'message_count'  => (int) ($conversation['message_count'] ?? 0),
                'platform'       => 'instagram',
                'customer'       => [
                    'name'                => $customerName,
                    'username'            => $customerUsername,
                    'id'                  => $customer['id'] ?? null,
                    'profile_picture_url' => $customer['profile_pic'] ?? $customer['profile_picture_url'] ?? null,
                ],
                'latest_message' => $snippet !== null ? [
                    'message'      => $snippet,
                    'created_time' => $conversation['updated_time'] ?? null,
                    'direction'    => null,
                    'attachments'  => [],
                ] : null,
            ];
        })->filter(fn ($conv) => !empty($conv['id']))->keyBy('id');

        // 2. Query local DB webhook events for Instagram messages
        $targetIds = array_values(array_filter([$pageId, $igAccountId]));
        $dbEvents = FacebookMessengerWebhookEvent::where('workspace_id', $workspaceId)
            ->where(function ($q) use ($targetIds) {
                if (!empty($targetIds)) {
                    $q->whereIn('page_id', $targetIds)
                      ->orWhereIn('sender_id', $targetIds)
                      ->orWhereIn('recipient_id', $targetIds);
                }
            })
            ->whereNotNull('message_id')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $dbConversations = [];
        foreach ($dbEvents as $evt) {
            $sender = (string) $evt->sender_id;
            $recipient = (string) $evt->recipient_id;
            $customerId = in_array($sender, $targetIds, true) ? $recipient : $sender;
            if (empty($customerId)) {
                continue;
            }

            if (!isset($dbConversations[$customerId])) {
                $payload = $evt->payload ?? [];
                $text = data_get($payload, 'message.text') ?? ($evt->event_type === 'message' ? 'Message' : ucfirst($evt->event_type));
                $fromUsername = data_get($payload, 'sender.username');
                $fromName = data_get($payload, 'sender.name') ?: ($fromUsername ? "@{$fromUsername}" : "Instagram user");
                $createdTime = !empty($evt->event_timestamp)
                    ? Carbon::createFromTimestampMs((int) $evt->event_timestamp)->toIso8601String()
                    : ($evt->created_at?->toIso8601String() ?? now()->toIso8601String());

                $dbConversations[$customerId] = [
                    'id'             => "ig_{$customerId}",
                    'customer_id'    => $customerId,
                    'updated_time'   => $createdTime,
                    'unread_count'   => 0,
                    'message_count'  => 0,
                    'platform'       => 'instagram',
                    'customer'       => [
                        'name'                => $fromName,
                        'username'            => $fromUsername ?: '',
                        'id'                  => $customerId,
                        'profile_picture_url' => null,
                    ],
                    'latest_message' => [
                        'message'      => $text,
                        'created_time' => $createdTime,
                        'direction'    => in_array($sender, $targetIds, true) ? 'outbound' : 'inbound',
                        'attachments'  => [],
                    ],
                ];
            }

            $dbConversations[$customerId]['message_count']++;
            if (!in_array($sender, $targetIds, true)) {
                $dbConversations[$customerId]['unread_count']++;
            }
        }

        // 3. Merge Meta API conversations and DB conversations
        // If a customer ID is already represented in Meta conversations, keep Meta's thread ID
        $metaCustomerIds = $metaConversations->map(fn ($c) => $c['customer']['id'] ?? null)->filter()->values()->all();

        $merged = $metaConversations->values();
        foreach ($dbConversations as $customerId => $dbConv) {
            if (!in_array($customerId, $metaCustomerIds, true)) {
                $merged->push($dbConv);
            }
        }

        // Sort by updated_time descending
        $sortedConversations = $merged->sortByDesc(function ($c) {
            return !empty($c['updated_time']) ? strtotime($c['updated_time']) : 0;
        })->values()->all();

        $responseData = [
            'success'  => true,
            'platform' => 'instagram',
            'data'     => $sortedConversations,
            'paging'   => [
                'before' => $response->json('paging.cursors.before'),
                'after'  => $response->json('paging.cursors.after'),
            ],
        ];

        Cache::put($cacheKey, $responseData, now()->addSeconds(60));

        return response()->json($responseData);
    }

    /**
     * GET /api/v1/instagram/inbox/conversations/{conversationId}/messages
     * Fetch messages for a specific Instagram conversation.
     */
    public function messages(Request $request, string $conversationId, WorkspaceSocialAccounts $socialAccounts)
    {
        $validated = $request->validate([
            'workspace_id' => 'required|integer|exists:workspaces,id',
            'limit'        => 'nullable|integer|min:1|max:50',
            'after'        => 'nullable|string',
        ]);

        $workspaceId = (int) $validated['workspace_id'];
        $limit       = min(50, max(1, (int) ($validated['limit'] ?? 25)));
        $fbPage      = $socialAccounts->facebook($workspaceId);
        $igIntegration = $socialAccounts->instagram($workspaceId);

        $token = $fbPage?->page_access_token ?: $igIntegration?->refresh_token;
        $pageId = (string) ($fbPage?->page_id ?? '');
        $igAccountId = (string) ($igIntegration?->account_id ?? '');
        $targetIds = array_values(array_filter([$pageId, $igAccountId]));

        // Check if conversationId is a local DB thread (format: ig_{customerId})
        $isLocalThread = str_starts_with($conversationId, 'ig_');
        $customerId = $isLocalThread ? substr($conversationId, 3) : null;

        $metaMessages = [];
        $paging = ['before' => null, 'after' => null];

        if (!$isLocalThread && !empty($token)) {
            $params = [
                'fields'       => 'id,message,created_time,from,to,attachments,shares',
                'limit'        => $limit,
                'access_token' => $token,
            ];
            if (!empty($validated['after'])) {
                $params['after'] = $validated['after'];
            }

            $response = $this->client()->get("https://graph.facebook.com/v23.0/{$conversationId}/messages", $params);

            if ($response->successful()) {
                $paging = [
                    'before' => $response->json('paging.cursors.before'),
                    'after'  => $response->json('paging.cursors.after'),
                ];

                $metaMessages = collect($response->json('data') ?? [])->map(function ($message) use ($targetIds) {
                    $attachments = collect(data_get($message, 'attachments.data', []))->map(function ($attachment) {
                        return [
                            'type' => $attachment['type'] ?? null,
                            'url'  => data_get($attachment, 'image_data.url')
                                ?? data_get($attachment, 'video_data.url')
                                ?? data_get($attachment, 'file_url'),
                        ];
                    })->filter(fn ($attachment) => !empty($attachment['url']))->values()->all();

                    $fromId = (string) data_get($message, 'from.id');
                    $isOutbound = in_array($fromId, $targetIds, true);

                    return [
                        'id'           => $message['id'] ?? null,
                        'message'      => $message['message'] ?? '',
                        'created_time' => $message['created_time'] ?? null,
                        'direction'    => $isOutbound ? 'outbound' : 'inbound',
                        'sender_name'  => data_get($message, 'from.username') ?: data_get($message, 'from.name'),
                        'attachments'  => $attachments,
                        'shares'       => data_get($message, 'shares.data', []),
                    ];
                })->filter(fn ($msg) => !empty($msg['id']))->values()->all();
            }
        }

        // Fetch any messages from local DB events for this customer/conversation
        $dbQuery = FacebookMessengerWebhookEvent::where('workspace_id', $workspaceId)->whereNotNull('message_id');
        if ($isLocalThread && $customerId) {
            $dbQuery->where(function ($q) use ($customerId) {
                $q->where('sender_id', $customerId)->orWhere('recipient_id', $customerId);
            });
        } elseif (!$isLocalThread) {
            $dbQuery->where(function ($q) use ($conversationId, $targetIds) {
                $q->where('message_id', $conversationId)
                  ->orWhere('page_id', $conversationId);
            });
        }

        $dbEvents = $dbQuery->orderBy('id', 'asc')->limit(100)->get();
        $dbMessages = $dbEvents->map(function ($evt) use ($targetIds) {
            $payload = $evt->payload ?? [];
            $senderId = (string) ($evt->sender_id ?? data_get($payload, 'sender.id'));
            $isOutbound = in_array($senderId, $targetIds, true);
            $msgText = data_get($payload, 'message.text') ?: '';

            $rawAttachments = data_get($payload, 'message.attachments', []);
            $attachments = collect($rawAttachments)->map(function ($att) {
                return [
                    'type' => data_get($att, 'type'),
                    'url'  => data_get($att, 'payload.url'),
                ];
            })->filter(fn ($a) => !empty($a['url']))->values()->all();

            $createdTime = !empty($evt->event_timestamp)
                ? Carbon::createFromTimestampMs((int) $evt->event_timestamp)->toIso8601String()
                : ($evt->created_at?->toIso8601String() ?? now()->toIso8601String());

            return [
                'id'           => $evt->message_id ?: (string) $evt->id,
                'message'      => $msgText,
                'created_time' => $createdTime,
                'direction'    => $isOutbound ? 'outbound' : 'inbound',
                'sender_name'  => $isOutbound
                    ? 'You'
                    : (data_get($payload, 'sender.name') ?: data_get($payload, 'sender.username') ?: 'Instagram user'),
                'attachments'  => $attachments,
                'shares'       => [],
            ];
        })->all();

        // Merge messages and deduplicate by ID
        $seenIds = [];
        $mergedMessages = [];
        foreach (array_merge($metaMessages, $dbMessages) as $msg) {
            $id = $msg['id'] ?? null;
            if ($id && !isset($seenIds[$id])) {
                $seenIds[$id] = true;
                $mergedMessages[] = $msg;
            }
        }

        // Sort chronologically ascending
        usort($mergedMessages, function ($a, $b) {
            return strtotime($a['created_time'] ?? '0') <=> strtotime($b['created_time'] ?? '0');
        });

        return response()->json([
            'success'  => true,
            'platform' => 'instagram',
            'data'     => $mergedMessages,
            'paging'   => $paging,
        ]);
    }

    /**
     * POST /api/v1/instagram/inbox/send-message
     * POST /api/v1/instagram/inbox/conversations/{conversationId}/messages
     * Send an Instagram Direct Message reply.
     */
    public function sendMessage(Request $request, WorkspaceSocialAccounts $socialAccounts, ?string $conversationId = null)
    {
        $validated = $request->validate([
            'workspace_id'    => 'required|integer|exists:workspaces,id',
            'message'         => 'required|string|min:1|max:2000',
            'conversation_id' => 'nullable|string',
            'recipient_id'    => 'nullable|string',
        ]);

        $workspaceId = (int) $validated['workspace_id'];
        $convId = $conversationId ?: (string) ($validated['conversation_id'] ?? '');
        $fbPage = $socialAccounts->facebook($workspaceId);
        $igIntegration = $socialAccounts->instagram($workspaceId);

        $token = $fbPage?->page_access_token ?: $igIntegration?->refresh_token;
        if (empty($token)) {
            return response()->json([
                'success' => false,
                'message' => 'No connected account with valid token found.',
            ], 404);
        }

        $pageId = (string) ($fbPage?->page_id ?? '');
        $igAccountId = (string) ($igIntegration?->account_id ?? '');
        $targetIds = array_values(array_filter([$pageId, $igAccountId]));

        // 1. Resolve recipient IGSID
        $recipientId = $validated['recipient_id'] ?? null;

        if (empty($recipientId) && str_starts_with($convId, 'ig_')) {
            $recipientId = substr($convId, 3);
        }

        if (empty($recipientId) && !empty($convId)) {
            // Fetch conversation participants from Meta to resolve recipient IGSID
            $convResponse = $this->client()->get("https://graph.facebook.com/v23.0/{$convId}", [
                'fields'       => 'participants',
                'access_token' => $token,
            ]);

            if ($convResponse->successful()) {
                $recipient = collect($convResponse->json('participants.data') ?? [])->first(function ($participant) use ($targetIds) {
                    $id = (string) ($participant['id'] ?? '');
                    return !in_array($id, $targetIds, true);
                });
                $recipientId = $recipient['id'] ?? null;
            }

            // Fallback: check local DB webhook events for this conversation
            if (empty($recipientId)) {
                $dbEvt = FacebookMessengerWebhookEvent::where('workspace_id', $workspaceId)
                    ->where(function ($q) use ($convId) {
                        $q->where('page_id', $convId)->orWhere('message_id', $convId);
                    })
                    ->first();
                if ($dbEvt) {
                    $sender = (string) $dbEvt->sender_id;
                    $recipient = (string) $dbEvt->recipient_id;
                    $recipientId = in_array($sender, $targetIds, true) ? $recipient : $sender;
                }
            }
        }

        if (empty($recipientId)) {
            return response()->json([
                'success' => false,
                'message' => 'Could not resolve a valid Instagram recipient ID for this conversation.',
            ], 422);
        }

        // 2. Send reply via Meta Graph API
        $targetNode = !empty($pageId) ? $pageId : (!empty($igAccountId) ? $igAccountId : 'me');
        $sendResponse = $this->client()->post("https://graph.facebook.com/v23.0/{$targetNode}/messages", [
            'recipient'      => ['id' => $recipientId],
            'messaging_type' => 'RESPONSE',
            'message'        => ['text' => trim($validated['message'])],
            'access_token'   => $token,
        ]);

        if (!$sendResponse->successful()) {
            return response()->json([
                'success'   => false,
                'message'   => $sendResponse->json('error.message') ?: 'Instagram reply could not be sent.',
                'meta_code' => $sendResponse->json('error.code'),
            ], 502);
        }

        $sentMid = $sendResponse->json('message_id');
        $nowMs = round(microtime(true) * 1000);

        // 3. Save outgoing message to DB
        try {
            $eventKey = ($igAccountId ?: $pageId) . ':outbound:' . ($sentMid ?: uniqid());
            FacebookMessengerWebhookEvent::create([
                'workspace_id'      => $workspaceId,
                'facebook_page_id'  => $fbPage?->id,
                'page_id'           => $igAccountId ?: $pageId,
                'sender_id'         => $igAccountId ?: $pageId,
                'recipient_id'      => (string) $recipientId,
                'message_id'        => $sentMid,
                'event_key'         => $eventKey,
                'event_type'        => 'message',
                'event_timestamp'   => $nowMs,
                'payload'           => [
                    'sender'    => ['id' => $igAccountId ?: $pageId],
                    'recipient' => ['id' => (string) $recipientId],
                    'timestamp' => $nowMs,
                    'message'   => [
                        'mid'     => $sentMid,
                        'text'    => trim($validated['message']),
                        'is_echo' => true,
                    ],
                ],
                'processed_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[INSTAGRAM INBOX] Failed to store outbound reply in DB', ['error' => $e->getMessage()]);
        }

        // 4. Invalidate cache version so next conversation fetch pulls fresh data
        $vKey = "ig_inbox_conv_v_{$workspaceId}";
        Cache::put($vKey, (int) Cache::get($vKey, 1) + 1, now()->addDays(7));

        return response()->json([
            'success' => true,
            'message' => 'Reply sent through Instagram Direct.',
            'data'    => [
                'message_id'   => $sentMid,
                'id'           => $sentMid,
                'message'      => trim($validated['message']),
                'direction'    => 'outbound',
                'created_time' => now()->toIso8601String(),
            ],
        ]);
    }
}
