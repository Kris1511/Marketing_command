<?php

namespace App\Support;

/**
 * Server-side composition rules for the Publishing composer. The React page mirrors these values
 * (frontend/src/pages/publishingRules.js) so both sides show the same limits and messages.
 */
class PublishingRules
{
    public const MAX_IMAGE_MB = 20;
    public const MAX_VIDEO_MB = 500;
    public const CAROUSEL_MIN = 2;
    public const CAROUSEL_MAX = 10;
    public const INSTAGRAM_MAX_HASHTAGS = 30;

    /*
     * Custom video thumbnail / Reel cover. One image is shared by every selected platform, so the limits are
     * the intersection of what they accept: JPG/PNG (Instagram converts covers to JPEG), at most 8 MB
     * (Instagram cover limit), at least 320 px on each side, and an aspect ratio between 9:16 (Reel cover)
     * and 16:9 (Facebook/YouTube landscape thumbnails).
     */
    public const THUMBNAIL_MAX_MB = 8;
    public const THUMBNAIL_MIMES = ['image/jpeg', 'image/png'];
    public const THUMBNAIL_MIN_SIDE = 320;
    public const THUMBNAIL_MIN_RATIO = 9 / 16;
    public const THUMBNAIL_MAX_RATIO = 16 / 9;
    private const THUMBNAIL_RATIO_TOLERANCE = 0.01;

    /**
     * Thumbnail outcome of a YouTube upload (YouTubeService::uploadVideo reports thumbnail_uploaded).
     *
     * @return array{requested: bool, applied: bool, message: string}
     */
    public static function youtubeThumbnailStatus(array $ytResult): array
    {
        return !empty($ytResult['thumbnail_uploaded'])
            ? ['requested' => true, 'applied' => true, 'message' => 'Custom thumbnail applied.']
            : ['requested' => true, 'applied' => false, 'message' => 'YouTube did not accept the custom thumbnail. Custom thumbnails require a verified YouTube channel (and YouTube allows at most 2 MB).'];
    }

    /**
     * Validate a thumbnail image file. Returns user-facing messages (empty when valid).
     *
     * @return list<string>
     */
    public static function thumbnailErrors(string $path): array
    {
        if (!is_file($path)) {
            return ['The thumbnail file could not be read.'];
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        if (!in_array($mime, self::THUMBNAIL_MIMES, true)) {
            return ['The thumbnail must be a JPG or PNG image.'];
        }
        if (filesize($path) > self::THUMBNAIL_MAX_MB * 1024 * 1024) {
            return ['The thumbnail must be ' . self::THUMBNAIL_MAX_MB . ' MB or smaller.'];
        }

        $size = @getimagesize($path);
        if ($size === false || empty($size[0]) || empty($size[1])) {
            return ['The thumbnail is not a valid image.'];
        }
        [$width, $height] = [(int) $size[0], (int) $size[1]];
        if (min($width, $height) < self::THUMBNAIL_MIN_SIDE) {
            return ["The thumbnail is {$width}x{$height}px. Each side must be at least " . self::THUMBNAIL_MIN_SIDE . 'px (1280x720 or 1080x1920 recommended).'];
        }
        $ratio = $width / $height;
        if ($ratio < self::THUMBNAIL_MIN_RATIO - self::THUMBNAIL_RATIO_TOLERANCE || $ratio > self::THUMBNAIL_MAX_RATIO + self::THUMBNAIL_RATIO_TOLERANCE) {
            return ["The thumbnail is {$width}x{$height}px. Use an aspect ratio between 9:16 (portrait Reel cover) and 16:9 (landscape video thumbnail)."];
        }

        return [];
    }

    /** Caption limits per platform (characters). */
    public const CAPTION_LIMITS = [
        'Facebook'  => 63206,
        'Instagram' => 2200,
        'YouTube'   => 5000,
    ];

    /** A hashtag is "#" followed by letters, numbers or underscores (max 100 characters). */
    private const HASHTAG_TOKEN = '/^#[\p{L}\p{N}_]{1,100}$/u';

    /** @return list<string> */
    public static function hashtagTokens(?string $hashtags): array
    {
        $hashtags = trim((string) $hashtags);
        if ($hashtags === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/[\s,]+/u', $hashtags) ?: [], fn ($t) => $t !== ''));
    }

    /** Collapses separators so "#a, #b   #c" is stored as "#a #b #c". */
    public static function normalizeHashtags(?string $hashtags): ?string
    {
        $tokens = self::hashtagTokens($hashtags);

        return $tokens ? implode(' ', $tokens) : null;
    }

    /**
     * @param  list<string>  $platforms
     * @return array<string, list<string>>  field => messages (empty when the composition is valid)
     */
    public static function compositionErrors(
        string $message,
        ?string $hashtags,
        array $platforms,
        ?string $postType,
        int $imageCount,
        bool $hasVideo,
        bool $isDraft
    ): array {
        $errors = [];
        $message = trim($message);

        if ($message === '' && $imageCount === 0 && ! $hasVideo) {
            $errors['message'][] = 'Add a caption or attach media before saving.';
        }

        if ($imageCount > 0 && $hasVideo) {
            $errors['video'][] = 'Images and a video cannot be combined in one post. Remove one of them.';
        }

        if ($hasVideo && $postType !== null && $postType !== 'video') {
            $errors['video'][] = 'A video file can only be attached to a Video post.';
        }

        if ($imageCount > 0 && $postType === 'video') {
            $errors['images'][] = 'Images cannot be attached to a Video post.';
        }

        if ($imageCount > self::CAROUSEL_MAX) {
            $errors['images'][] = 'A carousel can contain at most ' . self::CAROUSEL_MAX . ' images.';
        }

        if ($postType === 'multi_image' && $imageCount < self::CAROUSEL_MIN && ($imageCount > 0 || ! $isDraft)) {
            $errors['images'][] = 'A carousel needs at least ' . self::CAROUSEL_MIN . ' images.';
        }

        if ($imageCount > 1 && $postType !== null && $postType !== 'multi_image') {
            $errors['images'][] = 'Several images require the Carousel post type.';
        }

        if (! $isDraft) {
            if (in_array($postType, ['single_image', 'multi_image'], true) && $imageCount === 0) {
                $errors['images'][] = 'This post type needs at least one image attached.';
            }
            if ($postType === 'video' && ! $hasVideo) {
                $errors['video'][] = 'A video post needs a video file attached.';
            }
        }

        $limit = self::captionLimit($platforms);
        if (mb_strlen($message) > $limit) {
            $errors['message'][] = 'Caption is too long for the selected platforms (' . mb_strlen($message) . ' / ' . $limit . ' characters).';
        }

        $instagram = in_array('Instagram', $platforms, true);
        foreach (self::hashtagErrors($hashtags, $message, $instagram) as $msg) {
            $errors['hashtags'][] = $msg;
        }

        return $errors;
    }

    /** @param list<string> $platforms */
    public static function captionLimit(array $platforms): int
    {
        $limits = [];
        foreach ($platforms as $platform) {
            if (isset(self::CAPTION_LIMITS[$platform])) {
                $limits[] = self::CAPTION_LIMITS[$platform];
            }
        }

        return $limits ? min($limits) : self::CAPTION_LIMITS['Facebook'];
    }

    /** @return list<string> */
    public static function hashtagErrors(?string $hashtags, string $message, bool $instagram): array
    {
        $errors = [];
        $tokens = self::hashtagTokens($hashtags);

        foreach ($tokens as $token) {
            if (! preg_match(self::HASHTAG_TOKEN, $token)) {
                $errors[] = 'Invalid hashtag "' . mb_substr($token, 0, 40) . '". Use #word with letters, numbers or underscores only.';
                break;
            }
        }

        if ($instagram) {
            $inMessage = preg_match_all('/(?<![\p{L}\p{N}_#])#[\p{L}\p{N}_]+/u', $message);
            $count = max(count($tokens), (int) $inMessage);
            if ($count > self::INSTAGRAM_MAX_HASHTAGS) {
                $errors[] = 'Instagram allows at most ' . self::INSTAGRAM_MAX_HASHTAGS . ' hashtags (you have ' . $count . ').';
            }
        }

        return $errors;
    }
}
