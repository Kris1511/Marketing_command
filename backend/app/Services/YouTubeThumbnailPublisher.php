<?php

namespace App\Services;

use App\Models\YouTubeConnection;
use Illuminate\Support\Facades\Log;

/**
 * Applies the user's uploaded custom thumbnail to a YouTube video that is already uploaded.
 *
 * - Uses exactly the stored file (no resizing, re-encoding or generated frames). Files YouTube would reject
 *   (not JPG/PNG, over 2 MB) are reported without calling the API.
 * - Waits until YouTube has finished processing the video, then calls thumbnails.set with the video ID.
 * - `applied` is true only when thumbnails.set returned the new thumbnail; API errors are kept verbatim.
 * - Never uploads the video again.
 *
 * Result (stored at platform_statuses.youtube.thumbnail):
 *   requested, applied, status (applied|pending|failed), message, error {code, reason, message}|null,
 *   path (public-disk relative, for retries), bytes, sha256, processing_status, attempts, attempted_at, api_response
 */
class YouTubeThumbnailPublisher
{
    public const MAX_BYTES = \App\Support\PublishingRules::YOUTUBE_THUMBNAIL_MAX_MB * 1024 * 1024;   // YouTube's limit
    public const MIME_TYPES = ['image/jpeg', 'image/png'];

    /** @var \Closure(int): void */
    public \Closure $sleeper;

    public function __construct(private YouTubeService $youtube)
    {
        $this->sleeper = static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /** Why YouTube cannot take this file as a custom thumbnail, or null when it can. */
    public function fileProblem(?string $absolutePath): ?string
    {
        if (!$absolutePath || !is_file($absolutePath)) {
            return 'The uploaded thumbnail file no longer exists on the server.';
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath) ?: '';
        if (!in_array($mime, self::MIME_TYPES, true)) {
            return 'YouTube custom thumbnails must be JPG or PNG images.';
        }
        $bytes = filesize($absolutePath);
        if ($bytes > self::MAX_BYTES) {
            return sprintf('YouTube accepts custom thumbnails up to 2 MB; this image is %.1f MB.', $bytes / 1048576);
        }

        return null;
    }

    /**
     * @param array $previous the earlier thumbnail status of this video (attempt counting)
     * @param int|null $waitSeconds how long to wait for YouTube's processing (null = config default)
     */
    public function apply(YouTubeConnection $connection, string $videoId, ?string $absolutePath, ?string $relativePath, array $previous = [], ?int $waitSeconds = null): array
    {
        $result = [
            'requested'         => true,
            'applied'           => false,
            'status'            => 'failed',
            'message'           => '',
            'error'             => null,
            'video_id'          => $videoId,
            'path'              => $relativePath ?? ($previous['path'] ?? null),
            'bytes'             => null,
            'sha256'            => null,
            'processing_status' => null,
            'attempts'          => (int) ($previous['attempts'] ?? 0) + 1,
            'attempted_at'      => now()->toIso8601String(),
            'api_response'      => null,
        ];

        if ($problem = $this->fileProblem($absolutePath)) {
            return ['message' => $problem] + $result;
        }
        $result['bytes'] = filesize($absolutePath);
        $result['sha256'] = hash_file('sha256', $absolutePath);

        // 1. Wait for YouTube to finish processing the uploaded video.
        $wait = $waitSeconds ?? (int) config('services.youtube.thumbnail_wait_seconds', 30);
        $interval = max(1, (int) config('services.youtube.thumbnail_poll_seconds', 5));
        $deadline = time() + max(0, $wait);
        while (true) {
            try {
                $processing = $this->youtube->getProcessingStatus($connection, $videoId);
            } catch (\Throwable $e) {
                return $this->apiFailure($result, $e, 'Could not check the video on YouTube');
            }
            $result['processing_status'] = $processing['processing_status'] ?? null;

            if (!$processing['found']) {
                return ['message' => 'The video was not found on YouTube (it may have been deleted), so the thumbnail cannot be set.'] + $result;
            }
            if (in_array($processing['processing_status'], ['failed', 'terminated'], true)
                || in_array($processing['upload_status'], ['failed', 'rejected', 'deleted'], true)) {
                $why = $processing['reason'] ? " ({$processing['reason']})" : '';
                return ['message' => "YouTube could not process this video{$why}, so the thumbnail was not set."] + $result;
            }
            if ($processing['processing_status'] === 'succeeded' || $processing['upload_status'] === 'processed') {
                break;
            }
            if (time() >= $deadline) {
                return [
                    'status'  => 'pending',
                    'message' => 'YouTube is still processing the video. The custom thumbnail will be applied when processing finishes; you can also use Retry thumbnail.',
                ] + $result;
            }
            ($this->sleeper)($interval);
        }

        // 2. thumbnails.set with the exact uploaded file.
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
            $response = $this->youtube->setCustomThumbnail($connection, $videoId, $absolutePath, $mime);
        } catch (\Throwable $e) {
            return $this->apiFailure($result, $e, 'YouTube did not accept the custom thumbnail');
        }

        Log::info('[YouTube thumbnail] applied', ['video_id' => $videoId, 'sha256' => $result['sha256']]);

        return [
            'applied'      => true,
            'status'       => 'applied',
            'message'      => 'Custom thumbnail applied on YouTube.',
            'api_response' => $response,
        ] + $result;
    }

    /**
     * (Re)applies the saved custom thumbnail to a post's existing YouTube video: Retry thumbnail and the pending
     * catch-up. Only platform_statuses.youtube.thumbnail changes; the video is never uploaded again.
     *
     * @return array{error?: string, thumbnail?: array}
     */
    public function applyToPost(\App\Models\FacebookPost $post, ?int $waitSeconds = null): array
    {
        $statuses = $post->platform_statuses ?? [];
        $youtube = $statuses['youtube'] ?? [];
        $videoId = $post->yt_video_id ?: ($youtube['id'] ?? null);
        if (!$videoId || ($youtube['status'] ?? null) !== 'published') {
            return ['error' => 'This post has no published YouTube video to set a thumbnail on.'];
        }
        $previous = is_array($youtube['thumbnail'] ?? null) ? $youtube['thumbnail'] : [];
        if (!empty($previous['applied'])) {
            return ['error' => 'The custom thumbnail is already applied on YouTube.'];
        }

        $relative = $previous['path'] ?? (\App\Models\FacebookPost::supportsThumbnails() ? $post->thumbnail_path : null);
        if (!$relative) {
            return ['error' => 'No custom thumbnail was saved for this post.'];
        }
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $absolute = $disk->exists($relative) ? $disk->path($relative) : null;

        $connection = YouTubeConnection::where('workspace_id', $post->workspace_id)
            ->whereNotNull('access_token')
            ->orderByDesc('updated_at')
            ->first();
        if (!$connection) {
            return ['error' => 'No connected YouTube channel was found for this client. Reconnect YouTube, then retry.'];
        }

        $thumbnail = $this->apply($connection, (string) $videoId, $absolute, $relative, $previous, $waitSeconds);

        $statuses['youtube']['thumbnail'] = $thumbnail;
        $post->forceFill(['platform_statuses' => $statuses])->save();

        return ['thumbnail' => $thumbnail];
    }

    private function apiFailure(array $result, \Throwable $e, string $prefix): array
    {
        $code = $e->getCode() ?: null;
        $reason = null;
        $apiMessage = $e->getMessage();
        if ($e instanceof \Google\Service\Exception) {
            $first = $e->getErrors()[0] ?? [];
            $reason = $first['reason'] ?? null;
            $apiMessage = $first['message'] ?? $apiMessage;
        }
        $decoded = json_decode($apiMessage, true);
        if (is_array($decoded)) {
            $apiMessage = $decoded['error']['message'] ?? $apiMessage;
            $reason ??= $decoded['error']['errors'][0]['reason'] ?? null;
        }

        $hint = match ($reason) {
            'forbidden'               => ' This channel is not allowed to upload custom thumbnails. Verify it at https://www.youtube.com/verify, then use Retry thumbnail.',
            'mediaBodyTooLarge'       => ' YouTube accepts custom thumbnails up to 2 MB.',
            'invalidImage'            => ' YouTube could not read this image. Upload a different JPG or PNG.',
            'uploadRateLimitExceeded' => ' YouTube is limiting thumbnail uploads for this channel; use Retry thumbnail later.',
            'videoNotFound'           => ' The video was not found on YouTube.',
            default                   => '',
        };

        Log::warning('[YouTube thumbnail] failed', ['video_id' => $result['video_id'], 'code' => $code, 'reason' => $reason, 'message' => $apiMessage]);

        return [
            'status'  => 'failed',
            'message' => "{$prefix}: " . rtrim((string) $apiMessage, ". ") . ".{$hint}",
            'error'   => ['code' => $code, 'reason' => $reason, 'message' => $apiMessage],
        ] + $result;
    }
}
