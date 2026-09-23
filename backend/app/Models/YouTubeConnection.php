<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class YouTubeConnection extends Model
{
    use HasFactory;

    protected $table = 'youtube_connections';

    protected $fillable = [
        'user_id',
        'workspace_id',
        'channel_id',
        'channel_name',
        'channel_description',
        'channel_thumbnail',
        'subscriber_count',
        'video_count',
        'view_count',
        'access_token',
        'refresh_token',
        'token_expires_at',
    ];

    /**
     * Never send tokens to the frontend for security.
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * Cast attributes including automatic token encryption.
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'subscriber_count' => 'integer',
            'video_count' => 'integer',
            'view_count' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
