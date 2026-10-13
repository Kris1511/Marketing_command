<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

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

    /**
     * Hide sensitive credentials from JSON serialization.
     */
    protected $hidden = [
        'refresh_token',
    ];

    protected $casts = [
        'is_connected'            => 'boolean',
        'last_sync_at'            => 'datetime',
        'access_token_expires_at' => 'datetime',
        'token_expires_at'        => 'datetime',
    ];

    /**
     * Safely encrypt refresh tokens when persisting.
     */
    public function setRefreshTokenAttribute($value): void
    {
        if (empty($value)) {
            $this->attributes['refresh_token'] = null;
            return;
        }

        try {
            Crypt::decryptString($value);
            $this->attributes['refresh_token'] = $value;
        } catch (\Exception $e) {
            $this->attributes['refresh_token'] = Crypt::encryptString($value);
        }
    }

    /**
     * Safely decrypt refresh tokens with backwards compatibility for plaintext tokens.
     */
    public function getRefreshTokenAttribute($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Check if the integration connection is marked expired.
     */
    public function isExpired(): bool
    {
        return $this->connection_status === 'expired';
    }

    /**
     * Mark connection status as expired with descriptive reason.
     */
    public function markAsExpired(?string $message = null): void
    {
        $this->update([
            'connection_status' => 'expired',
            'error_message'     => $message ?: 'Google OAuth token expired or revoked. Reauthorization required.',
        ]);
    }

    /**
     * Mark connection status as active and clear errors.
     */
    public function markAsConnected(): void
    {
        $this->update([
            'connection_status' => 'connected',
            'error_message'     => null,
            'is_connected'      => true,
            'last_sync_at'      => now(),
        ]);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
