<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Digital Marketing Dashboard
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Ping healthcheck
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'Marketing Command API',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    // Mock endpoints for initial React frontend integration & prototyping
    Route::get('/workspaces', function () {
        return response()->json([
            'success' => true,
            'data' => [
                [
                    'id' => 1,
                    'name' => 'Acme Growth Labs',
                    'industry' => 'SaaS / Tech',
                    'primary_contact' => 'Sarah Connor',
                    'primary_contact_email' => 'sarah@acmegrowth.io',
                    'budget' => 45000.00,
                    'status' => 'active',
                ],
                [
                    'id' => 2,
                    'name' => 'Nexus Retail Group',
                    'industry' => 'E-Commerce',
                    'primary_contact' => 'David Miller',
                    'primary_contact_email' => 'david@nexusretail.com',
                    'budget' => 62000.00,
                    'status' => 'active',
                ]
            ]
        ]);
    });

    Route::get('/dashboard/metrics', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'total_reach' => '1.2M',
                'reach_change' => '+14.2%',
                'engagement_rate' => '4.8%',
                'engagement_change' => '+0.6%',
                'new_leads' => 342,
                'leads_change' => '+28',
                'active_campaigns' => 8,
                'monthly_revenue' => '$84,500',
                'revenue_change' => '+18.5%',
                'trend_data' => [
                    'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    'reach' => [12000, 19000, 15000, 22000, 28000, 24000, 31000],
                    'engagement' => [800, 1400, 1100, 1800, 2200, 1900, 2600],
                ]
            ]
        ]);
    });

    Route::get('/leads', function () {
        return response()->json([
            'success' => true,
            'data' => [
                [
                    'id' => 101,
                    'name' => 'Marcus Vance',
                    'email' => 'marcus.v@enterprises.com',
                    'phone' => '+1 (555) 234-5678',
                    'status' => 'new',
                    'source' => 'facebook_lead_ad',
                    'created_at' => '2026-08-05 10:15:00',
                ],
                [
                    'id' => 102,
                    'name' => 'Elena Rostova',
                    'email' => 'elena@biotechlabs.io',
                    'phone' => '+1 (555) 876-5432',
                    'status' => 'contacted',
                    'source' => 'google_lead_form',
                    'created_at' => '2026-08-04 14:30:00',
                ],
                [
                    'id' => 103,
                    'name' => 'Jordan Hayes',
                    'email' => 'jordan@cloudscale.net',
                    'phone' => '+1 (555) 345-6789',
                    'status' => 'qualified',
                    'source' => 'linkedin',
                    'created_at' => '2026-08-03 16:45:00',
                ]
            ]
        ]);
    });
});
