<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workspace extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'industry',
        'primary_contact',
        'primary_contact_email',
        'budget',
        'status',
        'logo_url',
        'owner_id',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_workspace_roles')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function integrations()
    {
        return $this->hasMany(Integration::class);
    }
}
