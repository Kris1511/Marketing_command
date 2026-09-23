<?php

namespace App\Http\Controllers;

use App\Models\YouTubeConnection;
use App\Services\YouTubeService;
use Carbon\Carbon;
use Google\Service\YouTube as GoogleYouTube;
use Google\Service\YouTube\Comment as GoogleComment;
use Google\Service\YouTube\CommentSnippet as GoogleCommentSnippet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class YouTubeInboxController extends Controller
{
    protected YouTubeService $youtubeService;

    public function __construct(YouTubeService $youtubeService)
    {
        $this->youtubeService = $youtubeService;
    }

    /**
     * Resolve YouTube connection for given workspace and ensure token is refreshed.
     */
    protected function resolveAuthorizedConnection(int $workspaceId): array
    {
        $connection = YouTubeConnection::where('workspace_id', $workspaceId)->first();

        // If no direct connection, check across workspaces as fallback
        if (!$connection) {
            $connection = YouTubeConnection::whereNotNull('access_token')->first();
        }

        if (!$connection) {
            return [
                'error' => response()->json([
                    'success'       => false,
                    'code'          => 'NOT_CONNECTED',
                    'platform'      => 'youtube',
                    'message'       => 'No connected YouTube Channel found for this workspace. Please connect your YouTube Channel first.',
                    'reconnect_url' => "/api/youtube/connect?workspace_id={$workspaceId}&force=true",
                ], 404),
                'connection' => null,
            ];
        }

        try {
            $connection = $this->youtubeService->refreshAccessTokenIfNeeded($connection);
        } catch (\Throwable $e) {
            Log::warning("[YOUTUBE INBOX] Token refresh failed for workspace {$workspaceId}: " . $e->getMessage());

            return [
                'error' => response()->json([
                    'success'            => false,
                    'code'               => 'REAUTHORIZATION_REQUIRED',
                    'platform'           => 'youtube',
                    'missing_permission' => 'https://www.googleapis.com/auth/youtube.force-ssl',
                    'message'            => 'YouTube connection has expired or been revoked by Google. Please reconnect your YouTube Channel.',
                    'account'            => [
                        'id'                => $connection->channel_id,
                        'name'              => $connection->channel_name ?: 'YouTube Channel',
                        'channel_thumbnail' => $connection->channel_thumbnail,
                    ],
                    'reconnect_url'      => "/api/youtube/connect?workspace_id={$workspaceId}&force=true",
                    'details'            => $e->getMessage(),
                ], 403),
                'connection' => null,
            ];
        }

        return [
            'error'      => null,
            'connection' => $connection,
        ];
    }

    /**
     * Get a configured GoogleYouTube service instance for the connection.
     */
    protected function getYouTubeServiceInstance(YouTubeConnection $connection): GoogleYouTube
    {
        $client = $this->youtubeService->getConfiguredClient();
        $client->setAccessToken($connection->access_token);
        return new GoogleYouTube($client);
    }

    /**
     * GET /api/v1/youtube/inbox/conversations
     * Fetch YouTube comment threads as conversations.
     */
    public function conversations(Request $request)
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

        $resolved = $this->resolveAuthorizedConnection($workspaceId);
        if ($resolved['error']) {
            return $resolved['error'];
        }

        /** @var YouTubeConnection $connection */
        $connection = $resolved['connection'];

        $cacheKey = "yt_inbox_conv_{$workspaceId}_" . md5("{$limit}_{$after}");
        if (!$isFresh && empty($after) && Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        try {
            $youtube = $this->getYouTubeServiceInstance($connection);

            $params = [
                'allThreadsRelatedToChannelId' => $connection->channel_id,
                'maxResults'                   => $limit,
                'order'                        => 'time',
            ];
            if (!empty($after)) {
                $params['pageToken'] = $after;
            }

            $threadsList = [];
            $nextPageToken = null;

            try {
                $threadsRes = $youtube->commentThreads->listCommentThreads('snippet,replies', $params);
                $threadsList = $threadsRes->getItems() ?? [];
                $nextPageToken = $threadsRes->getNextPageToken();
            } catch (\Throwable $cte) {
                Log::info("[YOUTUBE INBOX] allThreadsRelatedToChannelId failed, attempting fallback: " . $cte->getMessage());

                // Fallback: list uploads playlist items and query comment threads for recent videos
                $channelRes = $youtube->channels->listChannels('contentDetails', ['id' => $connection->channel_id]);
                $uploadsListId = !empty($channelRes->getItems()) ? $channelRes->getItems()[0]->getContentDetails()?->getRelatedPlaylists()?->getUploads() : null;

                if ($uploadsListId) {
                    $playlistItems = $youtube->playlistItems->listPlaylistItems('contentDetails', [
                        'playlistId' => $uploadsListId,
                        'maxResults' => 10,
                    ]);

                    foreach ($playlistItems->getItems() as $item) {
                        $vId = $item->getContentDetails()?->getVideoId();
                        if (!$vId) continue;
                        try {
                            $vThreads = $youtube->commentThreads->listCommentThreads('snippet,replies', [
                                'videoId'    => $vId,
                                'maxResults' => 10,
                            ]);
                            foreach ($vThreads->getItems() as $vt) {
                                $threadsList[] = $vt;
                            }
                        } catch (\Throwable $ve) {
                            // Video might have comments disabled
                        }
                    }
                }
            }

            $conversations = [];
            foreach ($threadsList as $thread) {
                $snippet = $thread->getSnippet();
                $topLevel = $snippet?->getTopLevelComment();
                if (!$topLevel) continue;

                $topSnippet = $topLevel->getSnippet();
                $threadId   = $thread->getId();
                $authorName = $topSnippet?->getAuthorDisplayName() ?? 'YouTube User';
                $authorPic  = $topSnippet?->getAuthorProfileImageUrl() ?? null;
                $authorUrl  = $topSnippet?->getAuthorChannelUrl() ?? null;
                $text       = trim($topSnippet?->getTextDisplay() ?? '');
                $videoId    = $snippet?->getVideoId() ?? '';
                $replyCount = (int) ($snippet?->getTotalReplyCount() ?? 0);

                $updatedTime = $topSnippet?->getUpdatedAt() ?: $topSnippet?->getPublishedAt();
                $isoTime     = $updatedTime ? Carbon::parse($updatedTime)->toIso8601String() : now()->toIso8601String();

                // Check for latest reply
                $latestMessageText = $text;
                $latestTime = $isoTime;
                $latestDirection = 'inbound';

                if ($thread->getReplies() && $thread->getReplies()->getComments()) {
                    $replies = $thread->getReplies()->getComments();
                    if (!empty($replies)) {
                        $lastReply = end($replies);
                        $rSnippet  = $lastReply->getSnippet();
                        if ($rSnippet) {
                            $latestMessageText = trim($rSnippet->getTextDisplay() ?? '');
                            $rTime = $rSnippet->getPublishedAt();
                            if ($rTime) {
                                $latestTime = Carbon::parse($rTime)->toIso8601String();
                            }
                            $isOutbound = ($rSnippet->getAuthorDisplayName() === $connection->channel_name);
                            $latestDirection = $isOutbound ? 'outbound' : 'inbound';
                        }
                    }
                }

                $conversations[] = [
                    'id'            => $threadId,
                    'platform'      => 'youtube',
                    'updated_time'  => $latestTime,
                    'unread_count'  => 0,
                    'message_count' => 1 + $replyCount,
                    'video_id'      => $videoId,
                    'customer'      => [
                        'name'                => $authorName,
                        'username'            => $authorName,
                        'profile_picture_url' => $authorPic,
                        'channel_url'         => $authorUrl,
                    ],
                    'latest_message' => [
                        'message'      => $latestMessageText,
                        'created_time' => $latestTime,
                        'direction'    => $latestDirection,
                        'attachments'  => [],
                    ],
                ];
            }

            // Sort conversations by latest message timestamp descending
            usort($conversations, function ($a, $b) {
                return strcmp($b['updated_time'], $a['updated_time']);
            });

            $result = [
                'success'  => true,
                'platform' => 'youtube',
                'channel'  => [
                    'id'        => $connection->channel_id,
                    'title'     => $connection->channel_name,
                    'thumbnail' => $connection->channel_thumbnail,
                ],
                'data'     => $conversations,
                'paging'   => [
                    'after' => $nextPageToken,
                ],
            ];

            if (empty($after)) {
                Cache::put($cacheKey, $result, 30); // Cache for 30 seconds
            }

            return response()->json($result);

        } catch (\Throwable $e) {
            Log::error('[YOUTUBE INBOX] Error listing conversations', ['error' => $e->getMessage()]);

            return response()->json([
                'success'  => false,
                'platform' => 'youtube',
                'message'  => 'YouTube conversations could not be loaded: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/youtube/inbox/conversations/{conversationId}/messages
     * Fetch all messages (top-level comment + replies) for a comment thread.
     */
    public function messages(Request $request, string $conversationId)
    {
        $validated = $request->validate([
            'workspace_id' => 'required|integer|exists:workspaces,id',
            'limit'        => 'nullable|integer|min:1|max:100',
        ]);

        $workspaceId = (int) $validated['workspace_id'];

        $resolved = $this->resolveAuthorizedConnection($workspaceId);
        if ($resolved['error']) {
            return $resolved['error'];
        }

        /** @var YouTubeConnection $connection */
        $connection = $resolved['connection'];

        try {
            $youtube = $this->getYouTubeServiceInstance($connection);

            // Fetch thread detail
            $threadRes = $youtube->commentThreads->listCommentThreads('snippet,replies', [
                'id' => $conversationId,
            ]);

            $items = $threadRes->getItems();
            if (empty($items)) {
                return response()->json([
                    'success'  => false,
                    'platform' => 'youtube',
                    'message'  => 'YouTube comment thread not found.',
                ], 404);
            }

            $thread = $items[0];
            $topLevel = $thread->getSnippet()?->getTopLevelComment();
            if (!$topLevel) {
                return response()->json([
                    'success'  => true,
                    'platform' => 'youtube',
                    'data'     => [],
                ]);
            }

            $topSnippet = $topLevel->getSnippet();
            $authorChannel = $topSnippet?->getAuthorChannelId()?->getValue();
            $isOwner = ($authorChannel === $connection->channel_id) || ($topSnippet?->getAuthorDisplayName() === $connection->channel_name);

            $messages = [];

            // 1. Top-level comment
            $messages[] = [
                'id'           => $topLevel->getId(),
                'message'      => $topSnippet?->getTextDisplay() ?? '',
                'created_time' => $topSnippet?->getPublishedAt() ? Carbon::parse($topSnippet->getPublishedAt())->toIso8601String() : now()->toIso8601String(),
                'direction'    => $isOwner ? 'outbound' : 'inbound',
                'sender_name'  => $topSnippet?->getAuthorDisplayName() ?? 'User',
                'author_image' => $topSnippet?->getAuthorProfileImageUrl(),
                'attachments'  => [],
            ];

            // 2. Replies
            $replyItems = [];
            if ($thread->getReplies() && $thread->getReplies()->getComments()) {
                $replyItems = $thread->getReplies()->getComments();
            }

            // If reply count > count(replyItems), fetch complete replies list
            $totalReplies = (int) ($thread->getSnippet()?->getTotalReplyCount() ?? 0);
            if ($totalReplies > count($replyItems)) {
                try {
                    $allRepliesRes = $youtube->comments->listComments('snippet', [
                        'parentId'   => $conversationId,
                        'maxResults' => 100,
                    ]);
                    $replyItems = $allRepliesRes->getItems() ?? $replyItems;
                } catch (\Throwable $re) {
                    Log::info('[YOUTUBE INBOX] comments.list fallback to thread snippet replies: ' . $re->getMessage());
                }
            }

            foreach ($replyItems as $reply) {
                $rSnippet = $reply->getSnippet();
                if (!$rSnippet) continue;

                $rAuthorChannel = $rSnippet->getAuthorChannelId()?->getValue();
                $rIsOwner = ($rAuthorChannel === $connection->channel_id) || ($rSnippet->getAuthorDisplayName() === $connection->channel_name);

                $messages[] = [
                    'id'           => $reply->getId(),
                    'message'      => $rSnippet->getTextDisplay() ?? '',
                    'created_time' => $rSnippet->getPublishedAt() ? Carbon::parse($rSnippet->getPublishedAt())->toIso8601String() : now()->toIso8601String(),
                    'direction'    => $rIsOwner ? 'outbound' : 'inbound',
                    'sender_name'  => $rSnippet->getAuthorDisplayName() ?? 'User',
                    'author_image' => $rSnippet->getAuthorProfileImageUrl(),
                    'attachments'  => [],
                ];
            }

            // Sort messages chronologically (oldest first)
            usort($messages, function ($a, $b) {
                return strcmp($a['created_time'], $b['created_time']);
            });

            return response()->json([
                'success'  => true,
                'platform' => 'youtube',
                'data'     => $messages,
                'paging'   => [
                    'before' => null,
                    'after'  => null,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('[YOUTUBE INBOX] Error fetching messages for thread ' . $conversationId, ['error' => $e->getMessage()]);

            return response()->json([
                'success'  => false,
                'platform' => 'youtube',
                'message'  => 'YouTube messages could not be loaded: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/v1/youtube/inbox/conversations/{conversationId}/messages
     * Post a reply to a YouTube comment thread.
     */
    public function sendMessage(Request $request, string $conversationId)
    {
        $validated = $request->validate([
            'workspace_id' => 'required|integer|exists:workspaces,id',
            'message'      => 'required|string|min:1|max:2000',
        ]);

        $workspaceId = (int) $validated['workspace_id'];
        $replyText   = trim($validated['message']);

        $resolved = $this->resolveAuthorizedConnection($workspaceId);
        if ($resolved['error']) {
            return $resolved['error'];
        }

        /** @var YouTubeConnection $connection */
        $connection = $resolved['connection'];

        try {
            $youtube = $this->getYouTubeServiceInstance($connection);

            $comment = new GoogleComment();
            $commentSnippet = new GoogleCommentSnippet();
            $commentSnippet->setParentId($conversationId);
            $commentSnippet->setTextOriginal($replyText);
            $comment->setSnippet($commentSnippet);

            $inserted = $youtube->comments->insert('snippet', $comment);

            $insertedSnippet = $inserted->getSnippet();
            $messageId       = $inserted->getId();
            $createdTime     = $insertedSnippet?->getPublishedAt() ? Carbon::parse($insertedSnippet->getPublishedAt())->toIso8601String() : now()->toIso8601String();

            // Clear cached conversations for this workspace
            Cache::forget("yt_inbox_conv_{$workspaceId}_" . md5("20_"));

            return response()->json([
                'success' => true,
                'message' => 'YouTube reply posted successfully.',
                'data'    => [
                    'message_id'   => $messageId,
                    'created_time' => $createdTime,
                    'sender_name'  => $connection->channel_name,
                    'message'      => $replyText,
                    'direction'    => 'outbound',
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('[YOUTUBE INBOX] Failed to send reply to thread ' . $conversationId, ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to post YouTube reply: ' . $e->getMessage(),
            ], 500);
        }
    }
}
