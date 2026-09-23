<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class FacebookPage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'page_id',
        'page_name',
        'page_access_token',
        'followers_count',
        'fan_count',
        'profile_picture_url',
        'connected_since',
        'token_status',
    ];

    protected $casts = [
        'followers_count' => 'integer',
        'fan_count'       => 'integer',
        'connected_since' => 'datetime',
    ];

    // Encrypt Page Access Token safely when saving to DB
    public function setPageAccessTokenAttribute($value)
    {
        if (empty($value)) return;
        
        try {
            // If already encrypted, do not re-encrypt
            Crypt::decryptString($value);
            $this->attributes['page_access_token'] = $value;
        } catch (\Exception $e) {
            $this->attributes['page_access_token'] = Crypt::encryptString($value);
        }
    }

    // Decrypt Page Access Token safely when reading in backend
    public function getPageAccessTokenAttribute($value)
    {
        if (empty($value)) return $value;

        try {
            $decrypted = Crypt::decryptString($value);
            try {
                return Crypt::decryptString($decrypted);
            } catch (\Exception $ex) {
                return $decrypted;
            }
        } catch (\Exception $e) {
            return $value;
        }
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function posts()
    {
        return $this->hasMany(FacebookPost::class);
    }
}
