<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'content',
        'platform_list',
        'status',
        'scheduled_at',
        'published_at',
        'media_urls',
        'approval_status',
        'created_by_id',
        'approved_by_id',
        'approval_notes',
    ];

    protected $casts = [
        'platform_list' => 'array',
        'media_urls' => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
