<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'name',
        'platform',
        'status',
        'budget',
        'spent',
        'start_date',
        'end_date',
        'description',
        'created_by_id',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function metrics()
    {
        return $this->hasMany(CampaignMetric::class);
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }
}
