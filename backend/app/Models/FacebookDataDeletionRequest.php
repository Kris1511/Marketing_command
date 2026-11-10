<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacebookDataDeletionRequest extends Model
{
    protected $fillable = [
        'confirmation_code',
        'facebook_app_id',
        'facebook_user_hash',
        'status',
        'deleted_counts',
        'request_count',
        'requested_at',
        'completed_at',
    ];

    protected $hidden = [
        'facebook_user_hash',
    ];

    protected $casts = [
        'deleted_counts' => 'array',
        'request_count' => 'integer',
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
