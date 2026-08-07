<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Integration extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'platform',
        'account_id',
        'account_name',
        'refresh_token',
        'access_token_expires_at',
        'is_connected',
        'last_sync_at',
        'token_expires_at',
        'connection_status',
        'error_message',
    ];

    protected $casts = [
        'is_connected' => 'boolean',
        'last_sync_at' => 'datetime',
        'access_token_expires_at' => 'datetime',
        'token_expires_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
