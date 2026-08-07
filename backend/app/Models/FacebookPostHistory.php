<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacebookPostHistory extends Model
{
    use HasFactory;

    protected $table = 'facebook_post_history';

    protected $fillable = [
        'facebook_post_id',
        'action',
        'attempt_number',
        'status_code',
        'response_payload',
        'error_details',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'attempt_number'   => 'integer',
        'status_code'      => 'integer',
    ];

    public function post()
    {
        return $this->belongsTo(FacebookPost::class, 'facebook_post_id');
    }
}
