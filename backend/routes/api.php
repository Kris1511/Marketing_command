<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use App\Models\Workspace;
use App\Models\Campaign;
use App\Models\CampaignMetric;
use App\Models\Lead;
use App\Models\Integration;

/*
|--------------------------------------------------------------------------
| API Routes - Digital Marketing Dashboard (Connected to MySQL)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Healthcheck endpoint
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'Marketing Command API',
            'database' => 'Connected (MySQL WAMP)',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    // Workspaces API
    Route::get('/workspaces', function () {
        $workspaces = Workspace::with('owner')->get();
        return response()->json([
            'success' => true,
            'data' => $workspaces
        ]);
    });

    // Create new client workspace
    Route::post('/workspaces', function (Request $request) {
        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'industry'              => 'nullable|string|max:255',
            'primary_contact'       => 'nullable|string|max:255',
            'primary_contact_email' => 'nullable|email|max:255',
            'budget'                => 'nullable|numeric|min:0',
            'status'                => 'nullable|string|in:active,paused,archived,inactive,pending',
        ]);

        $workspace = Workspace::create([
            'name'                  => $validated['name'],
            'industry'              => $validated['industry'] ?? null,
            'primary_contact'       => $validated['primary_contact'] ?? null,
            'primary_contact_email' => $validated['primary_contact_email'] ?? null,
            'budget'                => $validated['budget'] ?? 0,
            'status'                => $validated['status'] ?? 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Client workspace created successfully.',
            'data'    => $workspace
        ], 201);
    });

    // Dashboard Overview Metrics API
    Route::get('/dashboard/metrics', function (Request $request) {
        $workspaceId = $request->query('workspace_id');

        if ($workspaceId) {
            $campaignIds = Campaign::where('workspace_id', $workspaceId)->pluck('id');
            $totalReach = CampaignMetric::whereIn('campaign_id', $campaignIds)->sum('impressions');
            $totalClicks = CampaignMetric::whereIn('campaign_id', $campaignIds)->sum('clicks');
            $totalConversions = CampaignMetric::whereIn('campaign_id', $campaignIds)->sum('conversions');
            $totalRevenue = CampaignMetric::whereIn('campaign_id', $campaignIds)->sum('revenue');
            $leadsCount = Lead::where('workspace_id', $workspaceId)->count();
            $metricsTrend = CampaignMetric::whereIn('campaign_id', $campaignIds)->orderBy('metric_date', 'asc')->take(7)->get();
            $activeCampaigns = Campaign::where('workspace_id', $workspaceId)->where('status', 'active')->count();
        } else {
            $totalReach = CampaignMetric::sum('impressions');
            $totalClicks = CampaignMetric::sum('clicks');
            $totalConversions = CampaignMetric::sum('conversions');
            $totalRevenue = CampaignMetric::sum('revenue');
            $leadsCount = Lead::count();
            $metricsTrend = CampaignMetric::orderBy('metric_date', 'asc')->take(7)->get();
            $activeCampaigns = Campaign::where('status', 'active')->count();
        }

        $engagementRate = $totalReach > 0 ? number_format(($totalClicks / $totalReach) * 100, 1) . '%' : '0%';

        $labels = $metricsTrend->pluck('metric_date')->map(fn($d) => date('D', strtotime($d)))->toArray();
        $reach = $metricsTrend->pluck('impressions')->toArray();
        $engagement = $metricsTrend->pluck('clicks')->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'total_reach' => number_format($totalReach),
                'reach_change' => '+0%',
                'engagement_rate' => $engagementRate,
                'engagement_change' => '+0%',
                'new_leads' => $leadsCount,
                'leads_change' => '+0',
                'website_traffic' => '0',
                'traffic_change' => '+0%',
                'active_campaigns' => $activeCampaigns,
                'monthly_revenue' => '$' . number_format($totalRevenue),
                'revenue_change' => '+0%',
                'trend_data' => [
                    'labels' => count($labels) > 0 ? $labels : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    'reach' => count($reach) > 0 ? $reach : [0, 0, 0, 0, 0, 0, 0],
                    'engagement' => count($engagement) > 0 ? $engagement : [0, 0, 0, 0, 0, 0, 0],
                ]
            ]
        ]);
    });

    // CRM Leads API
    Route::get('/leads', function () {
        $leads = Lead::with('workspace', 'campaign')->orderBy('created_at', 'desc')->get();
        return response()->json([
            'success' => true,
            'data' => $leads
        ]);
    });

    // Integrations (API Connections) — list all
    Route::get('/integrations', function (Request $request) {
        $query = \App\Models\Integration::query();
        if ($request->has('workspace_id')) {
            $query->where('workspace_id', $request->workspace_id);
        }
        $integrations = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['success' => true, 'data' => $integrations]);
    });

    // Integrations — connect a new platform
    Route::post('/integrations', function (Request $request) {
        $validated = $request->validate([
            'workspace_id'   => 'required|exists:workspaces,id',
            'platform'       => 'required|string|in:facebook,instagram,youtube,google_analytics,search_console,google_business,linkedin,twitter,mailchimp,slack',
            'account_name'   => 'nullable|string|max:255',
            'account_id'     => 'required|string|max:255',
            'refresh_token'  => 'nullable|string',
        ]);

        // Upsert — if same workspace+platform+account already exists, update it
        $integration = \App\Models\Integration::updateOrCreate(
            [
                'workspace_id' => $validated['workspace_id'],
                'platform'     => $validated['platform'],
                'account_id'   => $validated['account_id'],
            ],
            [
                'account_name'      => $validated['account_name'] ?? $validated['account_id'],
                'refresh_token'     => $validated['refresh_token'] ?? null,
                'is_connected'      => true,
                'connection_status' => 'connected',
                'last_sync_at'      => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Platform connected successfully.',
            'data'    => $integration,
        ], 201);
    });

    // Integrations — disconnect (delete)
    Route::delete('/integrations/{id}', function ($id) {
        $integration = Integration::findOrFail($id);
        $integration->delete();
        return response()->json(['success' => true, 'message' => 'Platform disconnected.']);
    });

    // ── Facebook OAuth ────────────────────────────────────────────────────────

    // Step 1: Redirect to Facebook login
    // Frontend opens: http://localhost:8000/api/v1/auth/facebook/redirect?workspace_id=1
    Route::get('/auth/facebook/redirect', function (Request $request) {
        $workspaceId = $request->query('workspace_id', '1');

        // Encode workspace_id in the OAuth state parameter (safe base64)
        $state = base64_encode(json_encode(['workspace_id' => $workspaceId, 'ts' => time()]));

        return Socialite::driver('facebook')
            ->scopes([
                'pages_show_list',
                'pages_read_engagement',
                'instagram_basic',
                'instagram_manage_insights',
                'read_insights',
            ])
            ->with(['state' => $state])
            ->stateless()
            ->redirect();
    });

    // Step 2: Facebook sends user back here after login
    Route::get('/auth/facebook/callback', function (Request $request) {
        try {
            $fbUser = Socialite::driver('facebook')->stateless()->user();

            // Decode workspace_id from OAuth state parameter
            $workspaceId = 1; // default
            $stateRaw = $request->query('state', '');
            if ($stateRaw) {
                $decoded = json_decode(base64_decode($stateRaw), true);
                $workspaceId = $decoded['workspace_id'] ?? 1;
            }

            // Save / update the Facebook integration in DB
            $integration = Integration::updateOrCreate(
                [
                    'workspace_id' => $workspaceId ?: 1,
                    'platform'     => 'facebook',
                    'account_id'   => $fbUser->getId(),
                ],
                [
                    'account_name'      => $fbUser->getName(),
                    'refresh_token'     => $fbUser->token,
                    'is_connected'      => true,
                    'connection_status' => 'connected',
                    'last_sync_at'      => now(),
                ]
            );

            // Also save Instagram integration using same Facebook token
            Integration::updateOrCreate(
                [
                    'workspace_id' => $workspaceId ?: 1,
                    'platform'     => 'instagram',
                    'account_id'   => $fbUser->getId(),
                ],
                [
                    'account_name'      => $fbUser->getName() . ' (Instagram)',
                    'refresh_token'     => $fbUser->token,
                    'is_connected'      => true,
                    'connection_status' => 'connected',
                    'last_sync_at'      => now(),
                ]
            );

            // Close the popup and notify the parent window
            return response()->make(
                '<html><body style="font-family:sans-serif;text-align:center;padding:40px;background:#f0fdf4">'
                . '<h2 style="color:#16a34a">&#x2705; Facebook Connected!</h2>'
                . '<p style="color:#374151">Connected as <strong>' . htmlspecialchars($fbUser->getName()) . '</strong></p>'
                . '<p style="color:#6b7280;font-size:13px">This window will close automatically...</p>'
                . '<script>'
                . 'setTimeout(function(){'  
                . '  if(window.opener){ window.opener.postMessage({type:"FACEBOOK_OAUTH_SUCCESS",name:"' . addslashes($fbUser->getName()) . '"}, "*"); }'
                . '  window.close();'
                . '}, 1500);'
                . '</script>'
                . '</body></html>',
                200,
                ['Content-Type' => 'text/html']
            );

        } catch (\Exception $e) {
            return response()->make(
                '<html><body style="font-family:sans-serif;text-align:center;padding:40px;background:#fef2f2">'
                . '<h2 style="color:#dc2626">&#x274C; Connection Failed</h2>'
                . '<p style="color:#374151">' . htmlspecialchars($e->getMessage()) . '</p>'
                . '<p style="color:#6b7280;font-size:13px">You can close this window.</p>'
                . '<script>'
                . 'setTimeout(function(){'
                . '  if(window.opener){ window.opener.postMessage({type:"FACEBOOK_OAUTH_ERROR",error:"' . addslashes($e->getMessage()) . '"}, "*"); }'
                . '  window.close();'
                . '}, 2500);'
                . '</script>'
                . '</body></html>',
                200,
                ['Content-Type' => 'text/html']
            );
        }
    });
});
