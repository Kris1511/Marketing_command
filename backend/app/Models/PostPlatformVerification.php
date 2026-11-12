<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Result of the background "is this post still on the platform?" check (see PlatformPostVerifier). */
class PostPlatformVerification extends Model
{
    public const STATE_EXISTS = 'exists';
    public const STATE_NOT_FOUND = 'not_found';     // missing once; deletion not yet confirmed
    public const STATE_DELETED = 'deleted';         // confirmed deleted
    public const STATE_UNVERIFIABLE = 'unverifiable';

    protected $fillable = [
        'facebook_post_id', 'platform', 'remote_id', 'account_id', 'state', 'reason', 'message',
        'not_found_count', 'first_not_found_at', 'deleted_confirmed_at', 'last_seen_at', 'last_checked_at', 'metrics',
    ];

    protected $casts = [
        'first_not_found_at'   => 'datetime',
        'deleted_confirmed_at' => 'datetime',
        'last_seen_at'         => 'datetime',
        'last_checked_at'      => 'datetime',
        'metrics'              => 'array',
    ];

    private static ?bool $tableExists = null;

    /** False until the migration has run, so the dashboard keeps working without it. */
    public static function available(): bool
    {
        return self::$tableExists ??= \Illuminate\Support\Facades\Schema::hasTable('post_platform_verifications');
    }

    public function post()
    {
        return $this->belongsTo(FacebookPost::class, 'facebook_post_id');
    }
}
