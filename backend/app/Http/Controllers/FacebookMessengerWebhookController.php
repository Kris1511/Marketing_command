<?php

namespace App\Http\Controllers;

use App\Models\FacebookMessengerWebhookEvent;
use App\Models\FacebookPage;
use App\Services\WorkspaceSocialAccounts;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FacebookMessengerWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        if ($mode === 'subscribe' && hash_equals((string) config('services.facebook.webhook_verify_token'), (string) $token)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request)
    {
        $rawBody = $request->getContent();
        if (!$this->hasValidSignature($request, $rawBody)) {
            Log::warning('[FACEBOOK MESSENGER WEBHOOK] Invalid webhook signature.');
            return response()->json(['success' => false, 'message' => 'Invalid signature.'], 403);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return response()->json(['success' => false, 'message' => 'Invalid webhook payload.'], 400);
        }

        $created = 0;
        $duplicates = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            $pageId = (string) ($entry['id'] ?? '');
            if ($pageId === '') {
                continue;
            }

            $workspaceId = null;
            $page = FacebookPage::where('page_id', $pageId)
                ->whereNotNull('page_access_token')
                ->where(function ($query) {
                    $query->whereNotIn('token_status', ['invalid', 'disconnected', 'revoked'])->orWhereNull('token_status');
                })
                ->orderByDesc('updated_at')
                ->first();

            // If not found as Facebook Page, check if it's an Instagram Business Account ID
            if (!$page) {
                $igIntegration = \App\Models\Integration::where('platform', 'instagram')
                    ->where('account_id', $pageId)
                    ->first();
                if ($igIntegration && $igIntegration->workspace_id) {
                    $workspaceId = $igIntegration->workspace_id;
                    $page = FacebookPage::where('workspace_id', $workspaceId)
                        ->whereNotNull('page_access_token')
                        ->first();
                }
            }

            // Also check Integration table by account_id if still null
            if (!$workspaceId && !$page) {
                $anyIntegration = \App\Models\Integration::where('account_id', $pageId)->first();
                if ($anyIntegration && $anyIntegration->workspace_id) {
                    $workspaceId = $anyIntegration->workspace_id;
                }
            }

            $effectiveWorkspaceId = $workspaceId ?? $page?->workspace_id ?? 1;
            $userId = 1;
            try {
                $wsUser = \App\Models\Workspace::find($effectiveWorkspaceId)?->users()->first();
                if ($wsUser) {
                    $userId = $wsUser->id;
                }
            } catch (\Throwable $ue) {}

            // ── Handle feed change events (post comments) ────────────────────
            // These arrive when subscribed_fields includes 'feed'.
            // verb=add + item=comment means a new comment was posted.
            foreach ($entry['changes'] ?? [] as $change) {
                if (!is_array($change)) continue;

                $field = $change['field'] ?? '';
                $val   = $change['value'] ?? [];

                if ($field !== 'feed') continue;

                $verb = $val['verb'] ?? '';
                $item = $val['item'] ?? '';

                if ($verb !== 'add' || $item !== 'comment') continue;

                $commentId  = (string) ($val['comment_id'] ?? ($val['id'] ?? ''));
                $postId     = (string) ($val['post_id']    ?? ($val['parent_id'] ?? ''));
                $commentMsg = trim((string) ($val['message'] ?? ''));
                $fromName   = (string) ($val['sender_name'] ?? ($val['from']['name'] ?? 'Someone'));
                $createdAt  = !empty($val['created_time'])
                    ? (is_numeric($val['created_time']) ? \Carbon\Carbon::createFromTimestamp((int) $val['created_time']) : \Carbon\Carbon::parse($val['created_time']))
                    : now();

                if (!$commentId || empty($commentMsg)) continue;

                $relKey = "facebook_comment:{$commentId}" . ($postId ? ":{$postId}" : '');

                // Deduplicate: skip if we already stored this comment
                $exists = \Illuminate\Support\Facades\DB::table('notifications')
                    ->where('type', 'facebook_comment')
                    ->where('related_entity', 'LIKE', "%{$commentId}%")
                    ->exists();

                if ($exists) { $duplicates++; continue; }

                try {
                    \Illuminate\Support\Facades\DB::table('notifications')->insert([
                        'user_id'        => $userId,
                        'workspace_id'   => $effectiveWorkspaceId,
                        'type'           => 'facebook_comment',
                        'title'          => 'Facebook Comment',
                        'message'        => "{$fromName} commented on your Facebook post\n\n\"{$commentMsg}\"",
                        'related_entity' => $relKey,
                        'is_read'        => false,
                        'created_at'     => $createdAt,
                        'updated_at'     => now(),
                    ]);
                    $created++;
                    Log::info('[FB WEBHOOK] Stored comment', [
                        'platform'     => 'facebook',
                        'comment_id'   => $commentId,
                        'workspace_id' => $effectiveWorkspaceId,
                        'event_type'   => 'comment.created',
                        'post_id'      => $postId,
                    ]);
                    \Illuminate\Support\Facades\Cache::put("comments_stream_v_{$effectiveWorkspaceId}", time(), 86400);
                } catch (\Throwable $e) {
                    Log::warning('[FB WEBHOOK] Failed to store feed comment', ['error' => $e->getMessage()]);
                    $duplicates++;
                }
            }

            // ── Handle Instagram comments change events ──────────────────────
            foreach ($entry['changes'] ?? [] as $change) {
                if (!is_array($change)) continue;

                $field = $change['field'] ?? '';
                $val   = $change['value'] ?? [];

                if ($field !== 'comments') continue;

                $commentId = (string) ($val['id'] ?? ($val['comment_id'] ?? ''));
                $text      = trim((string) ($val['text'] ?? ($val['message'] ?? '')));
                $mediaId   = (string) ($val['media']['id'] ?? ($val['post_id'] ?? ''));
                $username  = (string) ($val['from']['username'] ?? ($val['from']['name'] ?? ($val['username'] ?? 'Someone')));
                $createdAt = !empty($val['timestamp'])
                    ? (is_numeric($val['timestamp']) ? \Carbon\Carbon::createFromTimestamp((int) $val['timestamp']) : \Carbon\Carbon::parse($val['timestamp']))
                    : (!empty($val['created_time']) ? (is_numeric($val['created_time']) ? \Carbon\Carbon::createFromTimestamp((int) $val['created_time']) : \Carbon\Carbon::parse($val['created_time'])) : now());

                if (!$commentId || empty($text)) continue;

                $relKey = "instagram_comment:{$commentId}" . ($mediaId ? ":media_{$mediaId}" : '');

                $exists = \Illuminate\Support\Facades\DB::table('notifications')
                    ->where('type', 'instagram_comment')
                    ->where('related_entity', 'LIKE', "%{$commentId}%")
                    ->exists();

                if ($exists) { $duplicates++; continue; }

                try {
                    \Illuminate\Support\Facades\DB::table('notifications')->insert([
                        'user_id'        => $userId,
                        'workspace_id'   => $effectiveWorkspaceId,
                        'type'           => 'instagram_comment',
                        'title'          => 'Instagram Comment',
                        'message'        => "@{$username} commented on your Instagram post\n\n\"{$text}\"",
                        'related_entity' => $relKey,
                        'is_read'        => false,
                        'created_at'     => $createdAt,
                        'updated_at'     => now(),
                    ]);
                    $created++;
                    \Illuminate\Support\Facades\Cache::put("comments_stream_v_{$effectiveWorkspaceId}", time(), 86400);
                    Log::info('[IG WEBHOOK] Stored comment', [
                        'platform'     => 'instagram',
                        'comment_id'   => $commentId,
                        'workspace_id' => $effectiveWorkspaceId,
                        'event_type'   => 'comment.created',
                        'media_id'     => $mediaId,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('[IG WEBHOOK] Failed to store instagram comment', ['error' => $e->getMessage()]);
                    $duplicates++;
                }
            }

            // ── Handle messaging events (Messenger and Instagram DMs) ───────
            $rawMessaging = array_merge($entry['messaging'] ?? [], $entry['standby'] ?? []);
            foreach ($rawMessaging as $messaging) {
                if (!is_array($messaging)) {
                    continue;
                }

                if ((bool) data_get($messaging, 'message.is_echo', false)) {
                    continue;
                }

                $messageId = data_get($messaging, 'message.mid');
                $eventType = $this->eventType($messaging);
                $eventKey = $messageId
                    ? "{$pageId}:message:{$messageId}"
                    : "{$pageId}:{$eventType}:" . sha1(json_encode($messaging));

                try {
                    $event = FacebookMessengerWebhookEvent::firstOrCreate(
                        ['event_key' => $eventKey],
                        [
                            'workspace_id'      => $workspaceId,
                            'facebook_page_id'  => $page?->id,
                            'page_id'           => $pageId,
                            'sender_id'         => data_get($messaging, 'sender.id'),
                            'recipient_id'      => data_get($messaging, 'recipient.id'),
                            'message_id'        => $messageId,
                            'event_type'        => $eventType,
                            'event_timestamp'   => data_get($messaging, 'timestamp'),
                            'payload'           => $messaging,
                            'processed_at'      => now(),
                        ]
                    );

                    if ($event->wasRecentlyCreated) {
                        $created++;
                        if ($workspaceId) {
                            $vKeyFb = "fb_inbox_conv_v_{$workspaceId}";
                            $vKeyIg = "ig_inbox_conv_v_{$workspaceId}";
                            Cache::put($vKeyFb, (int) Cache::get($vKeyFb, 1) + 1, now()->addDays(7));
                            Cache::put($vKeyIg, (int) Cache::get($vKeyIg, 1) + 1, now()->addDays(7));
                        }
                    } else {
                        $duplicates++;
                    }
                } catch (QueryException $e) {
                    $duplicates++;
                }
            }
        }

        return response()->json([
            'success'    => true,
            'processed'  => $created,
            'duplicates' => $duplicates,
        ]);
    }

    public function subscribe(Request $request, WorkspaceSocialAccounts $accounts)
    {
        $validated = $request->validate([
            'workspace_id' => 'required|integer|exists:workspaces,id',
        ]);

        $fbPage = $accounts->facebook((int) $validated['workspace_id']);
        if (!$fbPage || empty($fbPage->page_access_token)) {
            return response()->json([
                'success' => false,
                'message' => 'No connected Facebook Page with a valid Page access token was found for this workspace.',
            ], 404);
        }

        $cacheKey = "facebook_messenger_webhook_subscribed_{$fbPage->page_id}";
        if (Cache::has($cacheKey)) {
            return response()->json([
                'success' => true,
                'cached'  => true,
                'message' => 'Facebook Messenger webhook subscription is already enabled for this Page.',
                'data'    => [
                    'page_id' => $fbPage->page_id,
                ],
            ]);
        }

        $response = Http::withoutVerifying()
            ->asForm()
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->post("https://graph.facebook.com/v23.0/{$fbPage->page_id}/subscribed_apps", [
                // Subscribe to messaging events (Messenger & Instagram) in addition to feed comments
                'subscribed_fields' => 'messages,messaging_postbacks,message_reactions,message_reads,feed',
                'access_token'      => $fbPage->page_access_token,
            ]);

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => $response->json('error.message') ?: 'Facebook webhook subscription could not be enabled for this Page.',
                'meta_code' => $response->json('error.code'),
            ], 502);
        }

        Cache::put($cacheKey, true, now()->addHours(12));

        return response()->json([
            'success' => true,
            'message' => 'Facebook Messenger webhook subscription is enabled for this Page.',
            'data'    => [
                'page_id' => $fbPage->page_id,
            ],
        ]);
    }

    public function events(Request $request, WorkspaceSocialAccounts $accounts): StreamedResponse
    {
        $validated = $request->validate([
            'workspace_id' => 'required|integer|exists:workspaces,id',
            'since'        => 'nullable|integer|min:0',
        ]);

        $workspaceId = (int) $validated['workspace_id'];
        $lastId = (int) ($validated['since'] ?? 0);
        $fbPage = $accounts->facebook($workspaceId);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return response()->stream(function () use ($workspaceId, &$lastId, $fbPage) {
            @set_time_limit(15);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            ob_implicit_flush(true);

            // 2KB padding comment ensures HTTP headers and ready event flush immediately through PHP CLI / reverse proxy
            echo ":" . str_repeat(" ", 2048) . "\n\n";
            echo "retry: 3000\n";
            $this->sendSse('ready', [
                'workspace_id'  => $workspaceId,
                'last_event_id' => $lastId,
                'page_id'       => $fbPage?->page_id,
            ]);

            $igIntegration = $accounts->instagram($workspaceId);
            $validPageIds = array_values(array_filter([$fbPage?->page_id, $igIntegration?->account_id]));

            $query = FacebookMessengerWebhookEvent::where('workspace_id', $workspaceId)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(25);

            if (!empty($validPageIds)) {
                $query->whereIn('page_id', $validPageIds);
            }

            $events = $query->get();
            foreach ($events as $event) {
                $lastId = $event->id;
                $this->sendSse('messenger.message', [
                    'id'         => $event->id,
                    'page_id'    => $event->page_id,
                    'sender_id'  => $event->sender_id,
                    'message_id' => $event->message_id,
                    'event_type' => $event->event_type,
                    'created_at' => $event->created_at?->toIso8601String(),
                ], $event->id);
            }

            echo ": heartbeat " . time() . "\n\n";
            @ob_flush();
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'close',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function poll(Request $request, WorkspaceSocialAccounts $accounts)
    {
        $validated = $request->validate([
            'workspace_id' => 'required|integer|exists:workspaces,id',
            'since'        => 'nullable|integer|min:0',
        ]);

        $workspaceId = (int) $validated['workspace_id'];
        $lastId = (int) ($validated['since'] ?? 0);
        $fbPage = $accounts->facebook($workspaceId);
        $igIntegration = $accounts->instagram($workspaceId);
        $validPageIds = array_values(array_filter([$fbPage?->page_id, $igIntegration?->account_id]));

        $query = FacebookMessengerWebhookEvent::where('workspace_id', $workspaceId)
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->limit(25);

        if (!empty($validPageIds)) {
            $query->whereIn('page_id', $validPageIds);
        }

        $events = $query->get()->map(function ($event) {
            return [
                'id'         => $event->id,
                'page_id'    => $event->page_id,
                'sender_id'  => $event->sender_id,
                'message_id' => $event->message_id,
                'event_type' => $event->event_type,
                'created_at' => $event->created_at?->toIso8601String(),
            ];
        });

        $maxId = $events->isNotEmpty() ? (int) $events->max('id') : $lastId;

        return response()->json([
            'success'       => true,
            'workspace_id'  => $workspaceId,
            'last_event_id' => $maxId,
            'events'        => $events,
        ]);
    }

    private function hasValidSignature(Request $request, string $rawBody): bool
    {
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        if ($signature === '') {
            return !filter_var(env('FACEBOOK_ENFORCE_WEBHOOK_SIGNATURE', false), FILTER_VALIDATE_BOOL);
        }

        $secret = (string) config('services.facebook.client_secret');
        if ($secret === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    private function eventType(array $messaging): string
    {
        foreach (['message', 'postback', 'delivery', 'read', 'optin', 'referral'] as $type) {
            if (array_key_exists($type, $messaging)) {
                return $type;
            }
        }

        return 'messaging';
    }

    private function sendSse(string $event, array $data, ?int $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";
        @ob_flush();
        flush();
    }
}
