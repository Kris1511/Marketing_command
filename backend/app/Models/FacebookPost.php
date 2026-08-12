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
        'error_message',
        'retry_count',
        'created_by_id',
        'likes_count',
        'comments_count',
        'shares_count',
        'reactions_count',
        'engagement_count',
        'reach_count',
        'last_synced_at',
    ];

    protected $casts = [
        'scheduled_at'     => 'datetime',
        'published_at'     => 'datetime',
        'last_synced_at'   => 'datetime',
        'retry_count'      => 'integer',
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

    public function history()
    {
        return $this->hasMany(FacebookPostHistory::class)->orderBy('created_at', 'desc');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
