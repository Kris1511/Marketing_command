<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacebookPostMedia extends Model
{
    use HasFactory;

    protected $table = 'facebook_post_media';

    protected $fillable = [
        'facebook_post_id',
        'media_type',
        'file_path',
        'file_url',
        'fb_media_id',
        'sort_order',
    ];

    public function post()
    {
        return $this->belongsTo(FacebookPost::class, 'facebook_post_id');
    }
}
