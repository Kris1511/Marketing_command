<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FacebookPost extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'facebook_page_id',
        'post_type',
        'content',
        'link_url',
        'status',
        'scheduled_at',
        'published_at',
        'fb_post_id',
        'ig_media_id',
        'yt_video_id',
        'youtube_privacy',
        'platform_statuses',
        'error_message',
        'retry_count',
        'created_by_id',
        'likes_count',
        'comments_count',
        'shares_count',
        'reactions_count',
        'engagement_count',
        'reach_count',
        'views_count',
        'last_synced_at',
        'internal_title',
        'cta',
        'hashtags',
        'video_title',
        'platforms',
        'thumbnail_path',
        'thumbnail_url',
    ];

    private static ?bool $thumbnailColumnsExist = null;

    /**
     * Whether the custom-thumbnail columns exist (migration 2026_11_10_120000). Lets publishing keep
     * working on a database that has not been migrated yet.
     */
    public static function supportsThumbnails(): bool
    {
        return self::$thumbnailColumnsExist ??= \Illuminate\Support\Facades\Schema::hasColumns('facebook_posts', ['thumbnail_path', 'thumbnail_url']);
    }

    /** Absolute local path of the stored custom thumbnail, or null when there is none / the file is gone. */
    public function thumbnailLocalPath(): ?string
    {
        if (!self::supportsThumbnails() || empty($this->thumbnail_path)) {
            return null;
        }
        $disk = \Illuminate\Support\Facades\Storage::disk('public');

        return $disk->exists($this->thumbnail_path) ? $disk->path($this->thumbnail_path) : null;
    }

    protected $casts = [
        'platforms'         => 'array',
        'scheduled_at'      => 'datetime',
        'published_at'      => 'datetime',
        'last_synced_at'    => 'datetime',
        'platform_statuses' => 'array',
        'retry_count'       => 'integer',
        'views_count'      => 'integer',
        'likes_count'      => 'integer',
        'comments_count'   => 'integer',
        'shares_count'     => 'integer',
        'reactions_count'  => 'integer',
        'engagement_count' => 'integer',
        'reach_count'      => 'integer',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function page()
    {
        return $this->belongsTo(FacebookPage::class, 'facebook_page_id');
    }

    public function media()
    {
        return $this->hasMany(FacebookPostMedia::class)->orderBy('sort_order', 'asc');
    }

    /** Legacy multi-platform history row, linked by posts.facebook_post_id (no content matching). */
    public function legacyPost()
    {
        return $this->hasOne(Post::class, 'facebook_post_id');
    }

    /** Alias used by the calendar endpoint. */
    public function facebookPage()
    {
        return $this->page();
    }

    public function history()
    {
        return $this->hasMany(FacebookPostHistory::class)->orderBy('created_at', 'desc');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
