<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'campaign_id',
        'name',
        'email',
        'phone',
        'status',
        'source',
        'assigned_to_id',
        'contacted_at',
        'converted_at',
        'notes',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class);
    }
}
