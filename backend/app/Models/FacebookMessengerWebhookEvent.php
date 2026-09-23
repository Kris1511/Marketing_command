<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacebookMessengerWebhookEvent extends Model
{
    protected $fillable = [
        'workspace_id',
        'facebook_page_id',
        'page_id',
        'sender_id',
        'recipient_id',
        'message_id',
        'event_key',
        'event_type',
        'event_timestamp',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload'         => 'array',
        'processed_at'    => 'datetime',
        'event_timestamp' => 'integer',
    ];

    public function page()
    {
        return $this->belongsTo(FacebookPage::class, 'facebook_page_id');
    }
}
