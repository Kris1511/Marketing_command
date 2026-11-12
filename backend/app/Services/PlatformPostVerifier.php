<?php

namespace App\Services;

use App\Models\FacebookPost;
use App\Models\PostPlatformVerification;
use App\Models\YouTubeConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Checks whether published posts still exist on Facebook, Instagram and YouTube (read-only API calls).
 *
 * A post is recorded as DELETED only when deletion is confirmed by independent signals:
 *  - the connection works: a control request with the same token to the owning account succeeds;
 *  - the post belongs to the connected account (page id prefix / recorded IG account / recorded channel);
 *  - Facebook/Instagram: it is missing from a COMPLETE listing of the account's posts (every page read without
 *    error) AND a direct lookup returns the platform's not-found error;
 *    YouTube: videos.list, authorised as the owner, does not return it (or reports it deleted/rejected);
 *  - it was missing on at least CONFIRM_CHECKS checks spread over CONFIRM_AFTER_MINUTES.
 * Expired tokens, missing permissions, rate limits, incomplete listings and other errors are recorded as
 * UNVERIFIABLE with a reason, never as deleted. Posts are never deleted from the database.
 *
 * While a post is confirmed to exist, the platform's generated thumbnail of an uploaded video without a custom
 * thumbnail is copied to the public disk (so the Overview shows it without expiring CDN links).
 */
class PlatformPostVerifier
{
    public const CONFIRM_CHECKS = 2;
    public const CONFIRM_AFTER_MINUTES = 10;
    public const MAX_LISTING_PAGES = 100;
    private const FB_GRAPH = 'https://graph.facebook.com/v23.0';
    private const IG_GRAPH = 'https://graph.instagram.com/v23.0';

    private bool $dryRun = false;
    /** @var array<string, int> */
    private array $summary = [];

    public function __construct(
        private FacebookGraphService $graph,
        private YouTubeService $youtube,
        private PostMediaPreviewService $previews,
        private WorkspaceSocialAccounts $accounts,
    ) {
    }

    /** Verifies every published post of a workspace; returns counts per "platform:state". */
    public function verifyWorkspace(int $workspaceId, bool $dryRun = false): array
    {
        $this->dryRun = $dryRun;
        $this->summary = [];
        // A dry run stores nothing, so it also works before the migration has run.
        if (!$dryRun && !PostPlatformVerification::available()) {
            return ['skipped' => 'post_platform_verifications table missing: run php artisan migrate'];
        }

        $posts = FacebookPost::with('media')->where('workspace_id', $workspaceId)
            ->where(fn ($q) => $q->whereNotNull('fb_post_id')->orWhereNotNull('ig_media_id')->orWhereNotNull('yt_video_id'))
            ->get();

        $this->verifyFacebook($workspaceId, $posts->filter(fn ($p) => !empty($p->fb_post_id))->values());
        $this->verifyInstagram($workspaceId, $posts->filter(fn ($p) => !empty($p->ig_media_id))->values());
        $this->verifyYouTube($workspaceId, $posts->filter(fn ($p) => !empty($p->yt_video_id))->values());

        return $this->summary;
    }

    // ── Facebook ───────────────────────────────────────────────────────────────────────────────

    private function verifyFacebook(int $workspaceId, Collection $posts): void
    {
        if ($posts->isEmpty()) {
            return;
        }
        $page = $this->accounts->facebook($workspaceId);
        if (!$page || empty($page->page_access_token)) {
            $this->unverifiableAll($posts, 'facebook', fn ($p) => $p->fb_post_id, 'not_connected', null);
            return;
        }
        $token = $page->page_access_token;

        $control = $this->get(self::FB_GRAPH . "/{$page->page_id}", ['fields' => 'id', 'access_token' => $token]);
        if (!$control['ok']) {
            $c = self::controlFailure($control);
            $this->unverifiableAll($posts, 'facebook', fn ($p) => $p->fb_post_id, $c['reason'], $c['message']);
            return;
        }

        $listing = $this->listing(self::FB_GRAPH . "/{$page->page_id}/published_posts", [
            'fields' => 'id,full_picture', 'limit' => 100, 'access_token' => $token,
        ]);

        foreach ($posts as $post) {
            $id = (string) $post->fb_post_id;
            if (!str_starts_with($id, $page->page_id . '_')) {
                $this->record($post, 'facebook', $id, 'unverifiable', null, 'account_changed', "Connected page is {$page->page_id}.");
                continue;
            }
            if (isset($listing['items'][$id])) {
                $this->record($post, 'facebook', $id, 'exists', $page->page_id);
                $this->keepGeneratedThumbnail($post, $id, $listing['items'][$id]['full_picture'] ?? null);
                continue;
            }
            if (!$listing['ok']) {
                $c = self::classifyGraphError($listing);
                $this->record($post, 'facebook', $id, 'unverifiable', $page->page_id, 'listing_incomplete', $c['message']);
                continue;
            }
            $this->lookup($post, 'facebook', $id, $page->page_id, self::FB_GRAPH . "/{$id}", $token);
        }
    }

    // ── Instagram ──────────────────────────────────────────────────────────────────────────────

    private function verifyInstagram(int $workspaceId, Collection $posts): void
    {
        if ($posts->isEmpty()) {
            return;
        }
        $ig = $this->accounts->instagram($workspaceId);
        if (!$ig || empty($ig->refresh_token) || empty($ig->account_id)) {
            $this->unverifiableAll($posts, 'instagram', fn ($p) => $p->ig_media_id, 'not_connected', null);
            return;
        }
        $token = $ig->refresh_token;
        $account = (string) $ig->account_id;
        $base = str_starts_with($token, 'IGAA') ? self::IG_GRAPH : self::FB_GRAPH;

        $control = $this->get("{$base}/{$account}", ['fields' => 'id', 'access_token' => $token]);
        if (!$control['ok']) {
            $c = self::controlFailure($control);
            $this->unverifiableAll($posts, 'instagram', fn ($p) => $p->ig_media_id, $c['reason'], $c['message']);
            return;
        }

        $listing = $this->listing("{$base}/{$account}/media", [
            'fields' => 'id,media_type,thumbnail_url', 'limit' => 100, 'access_token' => $token,
        ]);
        $previous = $this->previousRows($posts, 'instagram');

        foreach ($posts as $post) {
            $id = (string) $post->ig_media_id;
            if (isset($listing['items'][$id])) {
                $this->record($post, 'instagram', $id, 'exists', $account);
                $this->keepGeneratedThumbnail($post, 'ig_' . $id, $listing['items'][$id]['thumbnail_url'] ?? null);
                continue;
            }
            // Ownership: recorded at publish time, or the account it was last seen on.
            $owner = $post->platform_statuses['instagram']['account_id'] ?? ($previous[$post->id]->account_id ?? null);
            if ($owner === null) {
                $this->record($post, 'instagram', $id, 'unverifiable', null, 'owner_unknown', null);
                continue;
            }
            if ((string) $owner !== $account) {
                $this->record($post, 'instagram', $id, 'unverifiable', (string) $owner, 'account_changed', "Connected account is {$account}.");
                continue;
            }
            if (!$listing['ok']) {
                $c = self::classifyGraphError($listing);
                $this->record($post, 'instagram', $id, 'unverifiable', $account, 'listing_incomplete', $c['message']);
                continue;
            }
            $this->lookup($post, 'instagram', $id, $account, "{$base}/{$id}", $token);
        }
    }

    // ── YouTube ────────────────────────────────────────────────────────────────────────────────

    private function verifyYouTube(int $workspaceId, Collection $posts): void
    {
        if ($posts->isEmpty()) {
            return;
        }
        $connections = YouTubeConnection::where('workspace_id', $workspaceId)->whereNotNull('access_token')->orderByDesc('updated_at')->get();
        $connection = $connections->first();
        if (!$connection) {
            $this->unverifiableAll($posts, 'youtube', fn ($p) => $p->yt_video_id, 'not_connected', null);
            return;
        }
        $channel = (string) $connection->channel_id;
        $previous = $this->previousRows($posts, 'youtube');

        foreach ($posts->chunk(50) as $chunk) {
            try {
                $found = $this->youtube->getVideosStatus($connection, $chunk->pluck('yt_video_id')->map(fn ($v) => (string) $v)->all());
            } catch (\Throwable $e) {
                $c = self::classifyYouTubeError($e);
                $this->unverifiableAll($chunk, 'youtube', fn ($p) => $p->yt_video_id, $c['reason'], $c['message']);
                continue;
            }

            foreach ($chunk as $post) {
                $id = (string) $post->yt_video_id;
                $info = $found[$id] ?? null;
                if ($info && !in_array($info['upload_status'], ['deleted', 'rejected'], true)) {
                    $metrics = [
                        'views' => $info['views'], 'likes' => $info['likes'], 'comments' => $info['comments'],
                        'privacy_status' => $info['privacy_status'], 'duration_seconds' => $info['duration_seconds'],
                        'vertical' => $info['vertical'], 'thumbnail' => $info['thumbnail'],
                    ];
                    $this->record($post, 'youtube', $id, 'exists', $info['channel_id'] ?: $channel, null, null, $metrics);
                    $this->keepGeneratedThumbnail($post, 'yt_' . $id, $info['thumbnail']);
                    continue;
                }

                // Ownership: recorded at publish time, last seen on, or (older posts) the only channel this client
                // has ever had connected, connected before the video was published.
                $owner = $post->platform_statuses['youtube']['channel_id'] ?? ($previous[$post->id]->account_id ?? null);
                if ($owner === null && $connections->count() === 1 && $post->published_at && $connection->created_at && $connection->created_at->lte($post->published_at)) {
                    $owner = $channel;
                }
                if ($owner === null) {
                    $this->record($post, 'youtube', $id, 'unverifiable', null, 'owner_unknown', null);
                } elseif ((string) $owner !== $channel) {
                    $this->record($post, 'youtube', $id, 'unverifiable', (string) $owner, 'account_changed', "Connected channel is {$channel}.");
                } else {
                    $why = $info ? "YouTube reports the video as {$info['upload_status']}" . ($info['reason'] ? " ({$info['reason']})" : '') . '.' : 'videos.list did not return the video for its owner.';
                    $this->record($post, 'youtube', $id, 'not_found', $channel, 'not_found', $why);
                }
            }
        }
    }

    // ── shared ─────────────────────────────────────────────────────────────────────────────────

    /** Direct lookup of one Facebook/Instagram object that is missing from the complete listing. */
    private function lookup(FacebookPost $post, string $platform, string $id, string $account, string $url, string $token): void
    {
        $res = $this->get($url, ['fields' => 'id', 'access_token' => $token]);
        if ($res['ok']) {
            // Readable although not listed (e.g. listing delay): it exists.
            $this->record($post, $platform, $id, 'exists', $account);
            return;
        }
        $c = self::classifyGraphError($res);
        $c['reason'] === 'not_found'
            ? $this->record($post, $platform, $id, 'not_found', $account, 'not_found', $c['message'])
            : $this->record($post, $platform, $id, 'unverifiable', $account, $c['reason'], $c['message']);
    }

    /**
     * Graph API error → reason. "not_found" only for the platform's object-not-found errors; everything else is a
     * reason the post could not be verified.
     *
     * @return array{reason: string, message: ?string}
     */
    public static function classifyGraphError(array $res): array
    {
        $error = $res['error'] ?? null;
        $http = (int) ($res['http'] ?? 0);
        $code = (int) ($error['code'] ?? 0);
        $sub = (int) ($error['error_subcode'] ?? 0);
        $message = $error['message'] ?? ($http ? "HTTP {$http}" : 'No response');

        $reason = match (true) {
            $http === 0                                                       => 'temporary',
            $code === 190 || $code === 102 || in_array($sub, [458, 459, 460, 463, 464, 467], true) => 'token_expired',
            in_array($code, [4, 17, 32, 613], true) || ($code >= 80000 && $code <= 80014) => 'rate_limited',
            $code === 100 && $sub === 33                                      => 'not_found',
            $code === 10 && stripos((string) $message, 'does not exist') !== false => 'not_found',
            $code === 10 || ($code >= 200 && $code <= 299)                    => 'permission',
            $http >= 500 || in_array($code, [1, 2], true)                     => 'temporary',
            default                                                           => 'api_error',
        };

        return ['reason' => $reason, 'message' => $message];
    }

    /** A failed control request means the account (not the post) is unavailable; never "not_found". */
    private static function controlFailure(array $res): array
    {
        $c = self::classifyGraphError($res);
        return ['reason' => $c['reason'] === 'not_found' ? 'account_unavailable' : $c['reason'], 'message' => $c['message']];
    }

    /** @return array{reason: string, message: string} */
    public static function classifyYouTubeError(\Throwable $e): array
    {
        $code = (int) $e->getCode();
        $reason = null;
        if ($e instanceof \Google\Service\Exception) {
            $reason = $e->getErrors()[0]['reason'] ?? null;
        }
        $text = $e->getMessage();
        return [
            'reason' => match (true) {
                $code === 401 || str_contains($text, 'refresh token') || str_contains($text, 'invalid_grant') || $reason === 'authError' => 'token_expired',
                in_array($reason, ['quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded'], true) || $code === 429 => 'rate_limited',
                $code === 403                                                   => 'permission',
                $code >= 500 || $code === 0                                     => 'temporary',
                default                                                         => 'api_error',
            },
            'message' => $text,
        ];
    }

    /**
     * Stores a check result. Deletion is confirmed only after CONFIRM_CHECKS not-found results spread over
     * CONFIRM_AFTER_MINUTES; a confirmed deletion is not undone by a later failed (unverifiable) check.
     */
    public function record(FacebookPost $post, string $platform, string $remoteId, string $outcome, ?string $account, ?string $reason = null, ?string $message = null, ?array $metrics = null): ?PostPlatformVerification
    {
        $key = "{$platform}:{$outcome}" . ($outcome === 'unverifiable' ? ':' . ($reason ?? 'unknown') : '');
        if ($this->dryRun) {
            $this->summary[$key] = ($this->summary[$key] ?? 0) + 1;
            return null;
        }

        $row = PostPlatformVerification::firstOrNew(['facebook_post_id' => $post->id, 'platform' => $platform]);
        if ($row->exists && $row->remote_id !== $remoteId) {
            $row->fill(['state' => null, 'not_found_count' => 0, 'first_not_found_at' => null, 'deleted_confirmed_at' => null, 'account_id' => null]);
        }
        $now = now();
        $row->remote_id = $remoteId;
        $row->last_checked_at = $now;

        if ($outcome === 'exists') {
            $row->fill([
                'state' => PostPlatformVerification::STATE_EXISTS, 'reason' => null, 'message' => null,
                'not_found_count' => 0, 'first_not_found_at' => null, 'deleted_confirmed_at' => null,
                'last_seen_at' => $now, 'account_id' => $account ?? $row->account_id,
            ]);
            if ($metrics !== null) {
                $row->metrics = $metrics;
            }
        } elseif ($outcome === 'not_found') {
            $row->not_found_count = (int) $row->not_found_count + 1;
            $row->first_not_found_at ??= $now;
            $row->account_id = $account ?? $row->account_id;
            $row->reason = 'not_found';
            $row->message = $message;
            $confirmed = $row->state === PostPlatformVerification::STATE_DELETED
                || ($row->not_found_count >= self::CONFIRM_CHECKS
                    && $row->first_not_found_at->lte($now->copy()->subMinutes(self::CONFIRM_AFTER_MINUTES)));
            if ($confirmed) {
                $row->state = PostPlatformVerification::STATE_DELETED;
                $row->deleted_confirmed_at ??= $now;
                $key = "{$platform}:deleted";
            } else {
                $row->state = PostPlatformVerification::STATE_NOT_FOUND;
            }
        } else {
            if ($row->state !== PostPlatformVerification::STATE_DELETED) {
                $row->state = PostPlatformVerification::STATE_UNVERIFIABLE;
                $row->reason = $reason;
                $row->message = $message;
            }
            $row->account_id = $row->account_id ?? $account;
            $key = "{$platform}:unverifiable:" . ($reason ?? 'unknown');
        }
        $row->save();
        $this->summary[$key] = ($this->summary[$key] ?? 0) + 1;

        return $row;
    }

    private function unverifiableAll(Collection $posts, string $platform, \Closure $remoteId, string $reason, ?string $message): void
    {
        foreach ($posts as $post) {
            $this->record($post, $platform, (string) $remoteId($post), 'unverifiable', null, $reason, $message);
        }
    }

    /** @return array<int, PostPlatformVerification> keyed by post id */
    private function previousRows(Collection $posts, string $platform): array
    {
        if (!PostPlatformVerification::available()) {
            return [];
        }
        return PostPlatformVerification::where('platform', $platform)->whereIn('facebook_post_id', $posts->pluck('id'))
            ->get()->keyBy('facebook_post_id')->all();
    }

    /**
     * Copies the platform's generated thumbnail of an uploaded video that has no custom thumbnail and no image,
     * so the Overview can show it (never replaces a custom thumbnail or an uploaded image).
     */
    private function keepGeneratedThumbnail(FacebookPost $post, string $key, ?string $url): void
    {
        if ($this->dryRun || !$url) {
            return;
        }
        $isVideo = in_array($post->post_type, ['video', 'reel'], true) || $post->media->contains('media_type', 'video');
        if (!$isVideo) {
            return;
        }
        $hasCustom = FacebookPost::supportsThumbnails() && $post->thumbnail_path
            && \Illuminate\Support\Facades\Storage::disk('public')->exists($post->thumbnail_path);
        $hasImage = $post->media->contains(function ($m) {
            $rel = $this->previews->relativePath($m->file_path, $m->file_url);
            return $rel && $this->previews->isImagePath($rel) && \Illuminate\Support\Facades\Storage::disk('public')->exists($rel);
        });
        if ($hasCustom || $hasImage || $this->previews->existingMirror((int) $post->workspace_id, $key)) {
            return;
        }
        $this->previews->mirrorRemoteImage($url, (int) $post->workspace_id, $key);
    }

    /** @return array{ok: bool, http: int, json: ?array, error: ?array} */
    private function get(string $url, ?array $params): array
    {
        try {
            $res = $this->graph->client()->timeout(30)->get($url, $params ?: null);
            $json = $res->json();
            return ['ok' => $res->successful(), 'http' => $res->status(), 'json' => is_array($json) ? $json : null, 'error' => is_array($json) ? ($json['error'] ?? null) : null];
        } catch (\Throwable $e) {
            Log::warning('[PlatformPostVerifier] request failed: ' . $e->getMessage());
            return ['ok' => false, 'http' => 0, 'json' => null, 'error' => ['message' => $e->getMessage()]];
        }
    }

    /**
     * Every page of a Graph edge. `ok` is true only when the last page was reached without an error, so a
     * partial listing is never mistaken for proof that a post is gone.
     *
     * @return array{ok: bool, http: int, error: ?array, items: array<string, array>}
     */
    private function listing(string $url, array $params): array
    {
        $items = [];
        $next = $url;
        $query = $params;
        for ($page = 0; $next && $page < self::MAX_LISTING_PAGES; $page++) {
            $res = $this->get($next, $query);
            if (!$res['ok']) {
                return ['ok' => false, 'http' => $res['http'], 'error' => $res['error'], 'items' => $items];
            }
            foreach ($res['json']['data'] ?? [] as $item) {
                if (!empty($item['id'])) {
                    $items[(string) $item['id']] = $item;
                }
            }
            $next = $res['json']['paging']['next'] ?? null;
            $query = null; // the cursor URL carries its own query string
        }
        if ($next) {
            return ['ok' => false, 'http' => 200, 'error' => ['message' => 'Listing has more than ' . self::MAX_LISTING_PAGES . ' pages.'], 'items' => $items];
        }

        return ['ok' => true, 'http' => 200, 'error' => null, 'items' => $items];
    }
}
