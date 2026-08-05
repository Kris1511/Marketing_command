<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use App\Models\Campaign;
use App\Models\CampaignMetric;
use App\Models\Lead;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Default Admin User
        $user = User::create([
            'name' => 'Digital Marketing Team',
            'email' => 'admin@marketingcommand.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // 2. Create Sample Client Workspaces
        $acmeWorkspace = Workspace::create([
            'name' => 'Acme Growth Labs',
            'industry' => 'SaaS / Software',
            'primary_contact' => 'Sarah Connor',
            'primary_contact_email' => 'sarah@acmegrowth.io',
            'budget' => 45000.00,
            'status' => 'active',
            'owner_id' => $user->id,
        ]);

        $nexusWorkspace = Workspace::create([
            'name' => 'Nexus Retail Group',
            'industry' => 'E-Commerce',
            'primary_contact' => 'David Miller',
            'primary_contact_email' => 'david@nexusretail.com',
            'budget' => 62000.00,
            'status' => 'active',
            'owner_id' => $user->id,
        ]);

        // 3. Attach User Roles to Workspaces
        $user->workspaces()->attach($acmeWorkspace->id, ['role' => 'admin']);
        $user->workspaces()->attach($nexusWorkspace->id, ['role' => 'admin']);

        // 4. Create Active Campaigns
        $campaign1 = Campaign::create([
            'workspace_id' => $acmeWorkspace->id,
            'name' => 'Q3 SaaS Growth Lead Gen',
            'platform' => 'meta_facebook',
            'status' => 'active',
            'budget' => 15000.00,
            'spent' => 8400.00,
            'start_date' => now()->subDays(15),
            'end_date' => now()->addDays(15),
            'description' => 'Targeting CTOs & Product Managers',
            'created_by_id' => $user->id,
        ]);

        $campaign2 = Campaign::create([
            'workspace_id' => $acmeWorkspace->id,
            'name' => 'Google Search High-Intent Ads',
            'platform' => 'google_ads',
            'status' => 'active',
            'budget' => 20000.00,
            'spent' => 12100.00,
            'start_date' => now()->subDays(20),
            'end_date' => now()->addDays(10),
            'description' => 'Brand & Competitor keywords',
            'created_by_id' => $user->id,
        ]);

        // 5. Seed Daily Campaign Metrics
        for ($i = 6; $i >= 0; $i--) {
            CampaignMetric::create([
                'campaign_id' => $campaign1->id,
                'metric_date' => now()->subDays($i)->toDateString(),
                'impressions' => rand(12000, 32000),
                'clicks' => rand(800, 2600),
                'conversions' => rand(40, 120),
                'revenue' => rand(2500, 8500),
            ]);
        }

        // 6. Create CRM Sample Leads
        Lead::create([
            'workspace_id' => $acmeWorkspace->id,
            'campaign_id' => $campaign1->id,
            'name' => 'Marcus Vance',
            'email' => 'marcus.v@enterprises.com',
            'phone' => '+1 (555) 234-5678',
            'status' => 'new',
            'source' => 'facebook_lead_ad',
            'assigned_to_id' => $user->id,
            'notes' => 'Interested in enterprise SaaS plan.',
        ]);

        Lead::create([
            'workspace_id' => $acmeWorkspace->id,
            'campaign_id' => $campaign2->id,
            'name' => 'Elena Rostova',
            'email' => 'elena@biotechlabs.io',
            'phone' => '+1 (555) 876-5432',
            'status' => 'contacted',
            'source' => 'google_lead_form',
            'assigned_to_id' => $user->id,
            'notes' => 'Followed up via email call scheduled for tomorrow.',
        ]);

        Lead::create([
            'workspace_id' => $acmeWorkspace->id,
            'campaign_id' => $campaign1->id,
            'name' => 'Jordan Hayes',
            'email' => 'jordan@cloudscale.net',
            'phone' => '+1 (555) 345-6789',
            'status' => 'qualified',
            'source' => 'linkedin',
            'assigned_to_id' => $user->id,
            'notes' => 'Budget approved, preparing proposal.',
        ]);
    }
}
