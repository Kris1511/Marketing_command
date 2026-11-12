<?php

namespace App\Services;

use App\Models\FacebookPost;
use App\Models\PostPlatformVerification;
use Illuminate\Support\Collection;

/**
 * Per-platform status of a post for the Overview's Latest Posts:
 *   published | scheduled | failed | deleted | unknown   (per platform)
 *   + partial for the post overall (some platforms published, others failed/deleted).
 * Publication results come from facebook_posts.platform_statuses; "is it still there?" from
 * post_platform_verifications (see PlatformPostVerifier). Nothing here calls a platform.
 */
class OverviewPostStatus
{
    public const LABELS = [
        'published' => 'Published',
        'scheduled' => 'Scheduled',
        'failed'    => 'Failed',
        'partial'   => 'Partially published',
        'deleted'   => 'Deleted',
        'unknown'   => 'Unable to verify',
    ];

    private const PLATFORM_NAMES = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube'];

    /** @var array<int, array<string, PostPlatformVerification>> */
    private array $verifications = [];

    /** Loads the verification rows of these posts in one query. */
    public function load(Collection $posts): self
    {
        $this->verifications = [];
        if (PostPlatformVerification::available() && $posts->isNotEmpty()) {
            foreach (PostPlatformVerification::whereIn('facebook_post_id', $posts->pluck('id'))->get() as $row) {
                $this->verifications[$row->facebook_post_id][$row->platform] = $row;
            }
        }

        return $this;
    }

    public function verification(FacebookPost $post, string $platform): ?PostPlatformVerification
    {
        return $this->verifications[$post->id][$platform] ?? null;
    }

    /** Platforms this post was meant for / is on, in display order. */
    public function targets(FacebookPost $post): array
    {
        $targets = [];
        foreach ((array) ($post->platforms ?? []) as $name) {
            $key = strtolower((string) $name);
            if (isset(self::PLATFORM_NAMES[$key])) {
                $targets[$key] = true;
            }
        }
        foreach (array_keys((array) ($post->platform_statuses ?? [])) as $key) {
            if (isset(self::PLATFORM_NAMES[$key])) {
                $targets[$key] = true;
            }
        }
        if ($post->fb_post_id) $targets['facebook'] = true;
        if ($post->ig_media_id) $targets['instagram'] = true;
        if ($post->yt_video_id) $targets['youtube'] = true;

        return array_values(array_filter(array_keys(self::PLATFORM_NAMES), fn ($k) => isset($targets[$k])));
    }

    public static function remoteId(FacebookPost $post, string $platform): ?string
    {
        $id = match ($platform) {
            'facebook'  => $post->fb_post_id,
            'instagram' => $post->ig_media_id,
            'youtube'   => $post->yt_video_id,
            default     => null,
        };
        if (!$id && (($post->platform_statuses[$platform]['status'] ?? null) === 'published')) {
            $id = $post->platform_statuses[$platform]['id'] ?? null;
        }

        return $id ? (string) $id : null;
    }

    /** @return array{status: string, label: string, message: ?string, verified_at: ?string, deleted_at: ?string, reason: ?string} */
    public function platformStatus(FacebookPost $post, string $platform): array
    {
        $name = self::PLATFORM_NAMES[$platform] ?? ucfirst($platform);
        $entry = $post->platform_statuses[$platform] ?? null;
        $remote = self::remoteId($post, $platform);
        $make = fn (string $status, ?string $message = null, ?string $reason = null, $verifiedAt = null, $deletedAt = null) => [
            'status'      => $status,
            'label'       => self::LABELS[$status],
            'message'     => $message,
            'reason'      => $reason,
            'verified_at' => $verifiedAt?->toIso8601String(),
            'deleted_at'  => $deletedAt?->toIso8601String(),
        ];

        if (($entry['status'] ?? null) === 'failed') {
            return $make('failed', $entry['error'] ?? null, 'publish_failed');
        }
        if ($remote) {
            $v = $this->verification($post, $platform);
            if (!$v || $v->remote_id !== $remote) {
                return $make('published', "Not checked on {$name} yet.", 'not_checked');
            }
            return match ($v->state) {
                PostPlatformVerification::STATE_EXISTS  => $make('published', "Confirmed on {$name}.", null, $v->last_seen_at),
                PostPlatformVerification::STATE_DELETED => $make('deleted', "Deleted from {$name} (confirmed " . $v->deleted_confirmed_at?->toDayDateTimeString() . ' UTC).', 'deleted', $v->last_seen_at, $v->deleted_confirmed_at),
                PostPlatformVerification::STATE_NOT_FOUND => $make('unknown', "Not found on {$name} on the last check; confirming before marking it deleted.", 'not_found', $v->last_seen_at),
                default => $make('unknown', self::reasonMessage((string) $v->reason, $name, $v->message), $v->reason, $v->last_seen_at),
            };
        }
        if (in_array($post->status, ['scheduled', 'processing'], true)) {
            return $make('scheduled', $post->scheduled_at ? 'Scheduled for ' . $post->scheduled_at->copy()->setTimezone('Asia/Kolkata')->format('d M Y, h:i A') . ' IST.' : null);
        }
        if ($post->status === 'failed') {
            return $make('failed', $post->error_message, 'publish_failed');
        }

        return $make('unknown', "No {$name} post ID was recorded for this post.", 'no_remote_id');
    }

    /** @return array<string, array> keyed by platform */
    public function platformStatuses(FacebookPost $post): array
    {
        $out = [];
        foreach ($this->targets($post) as $platform) {
            $out[$platform] = ['platform' => self::PLATFORM_NAMES[$platform]] + $this->platformStatus($post, $platform);
        }

        return $out;
    }

    /** Overall status of a (possibly multi-platform) post. */
    public static function overall(array $platformStatuses): string
    {
        $s = array_column($platformStatuses, 'status');
        if ($s === []) {
            return 'unknown';
        }
        $only = fn (string $x) => count(array_unique($s)) === 1 && $s[0] === $x;

        return match (true) {
            in_array('scheduled', $s, true) => 'scheduled',
            $only('failed')                 => 'failed',
            $only('deleted')                => 'deleted',
            $only('published')              => 'published',
            in_array('published', $s, true) && (in_array('failed', $s, true) || in_array('deleted', $s, true)) => 'partial',
            default                         => 'unknown',
        };
    }

    public static function reasonMessage(string $reason, string $name, ?string $platformMessage = null): string
    {
        $text = match ($reason) {
            'token_expired'       => "The {$name} connection has expired or was revoked. Reconnect {$name} so this post can be checked.",
            'permission'          => "Marketing Command does not have permission to check this post on {$name}.",
            'rate_limited'        => "{$name} is limiting requests right now; the check will run again later.",
            'temporary'           => "{$name} did not respond; the check will run again later.",
            'listing_incomplete'  => "The {$name} post list could not be read completely, so deletion was not checked.",
            'account_changed'     => "This post belongs to a different {$name} account than the one connected now.",
            'account_unavailable' => "The connected {$name} account could not be read.",
            'owner_unknown'       => "The {$name} account that published this post was not recorded, so its deletion cannot be confirmed.",
            'not_connected'       => "{$name} is not connected for this client.",
            default               => "{$name} returned an unexpected response, so this post could not be checked.",
        };

        return $platformMessage ? "{$text} ({$platformMessage})" : $text;
    }
}
