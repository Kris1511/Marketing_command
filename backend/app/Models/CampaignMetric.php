<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CampaignMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'metric_date',
        'impressions',
        'clicks',
        'conversions',
        'shares',
        'comments',
        'revenue',
        'cpc',
        'ctr',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
