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
        $this->call(UserSeeder::class);

        $user = User::firstOrCreate(
            ['email' => 'admin@marketingcommand.com'],
            [
                'name' => 'Digital Marketing Team',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

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
        $user->workspaces()->syncWithoutDetaching([$acmeWorkspace->id => ['role' => 'admin'], $nexusWorkspace->id => ['role' => 'admin']]);

        // 4a. Create Active Campaigns for Acme Growth Labs
        $campaign1 = Campaign::firstOrCreate(
            ['name' => 'Q3 SaaS Growth Lead Gen', 'workspace_id' => $acmeWorkspace->id],
            [
                'platform' => 'meta_facebook',
                'status' => 'active',
                'budget' => 15000.00,
                'spent' => 8400.00,
                'start_date' => now()->subDays(15),
                'end_date' => now()->addDays(15),
                'description' => 'Targeting CTOs & Product Managers',
                'created_by_id' => $user->id,
            ]
        );

        $campaign2 = Campaign::firstOrCreate(
            ['name' => 'Google Search High-Intent Ads', 'workspace_id' => $acmeWorkspace->id],
            [
                'platform' => 'google_ads',
                'status' => 'active',
                'budget' => 20000.00,
                'spent' => 12100.00,
                'start_date' => now()->subDays(20),
                'end_date' => now()->addDays(10),
                'description' => 'Brand & Competitor keywords',
                'created_by_id' => $user->id,
            ]
        );

        // 4b. Create Active Campaigns for Nexus Retail Group
        $nexusCampaign1 = Campaign::firstOrCreate(
            ['name' => 'E-Commerce Summer Flash Sale', 'workspace_id' => $nexusWorkspace->id],
            [
                'platform' => 'instagram_ads',
                'status' => 'active',
                'budget' => 35000.00,
                'spent' => 21400.00,
                'start_date' => now()->subDays(10),
                'end_date' => now()->addDays(20),
                'description' => 'Retargeting cart abandoners & lookalike audiences',
                'created_by_id' => $user->id,
            ]
        );

        $nexusCampaign2 = Campaign::firstOrCreate(
            ['name' => 'TikTok Viral Challenge Push', 'workspace_id' => $nexusWorkspace->id],
            [
                'platform' => 'tiktok',
                'status' => 'active',
                'budget' => 27000.00,
                'spent' => 18900.00,
                'start_date' => now()->subDays(12),
                'end_date' => now()->addDays(18),
                'description' => 'Influencer partnerships & spark ads',
                'created_by_id' => $user->id,
            ]
        );

        // 5. Seed Daily Campaign Metrics
        CampaignMetric::whereIn('campaign_id', [$campaign1->id, $campaign2->id, $nexusCampaign1->id, $nexusCampaign2->id])->delete();

        for ($i = 6; $i >= 0; $i--) {
            CampaignMetric::create([
                'campaign_id' => $campaign1->id,
                'metric_date' => now()->subDays($i)->toDateString(),
                'impressions' => rand(12000, 32000),
                'clicks' => rand(800, 2600),
                'conversions' => rand(40, 120),
                'revenue' => rand(2500, 8500),
            ]);

            CampaignMetric::create([
                'campaign_id' => $nexusCampaign1->id,
                'metric_date' => now()->subDays($i)->toDateString(),
                'impressions' => rand(28000, 58000),
                'clicks' => rand(1900, 4500),
                'conversions' => rand(95, 260),
                'revenue' => rand(6500, 18500),
            ]);
        }

        // 6. Create CRM Sample Leads for Acme
        Lead::firstOrCreate(
            ['email' => 'marcus.v@enterprises.com'],
            [
                'workspace_id' => $acmeWorkspace->id,
                'campaign_id' => $campaign1->id,
                'name' => 'Marcus Vance',
                'phone' => '+1 (555) 234-5678',
                'status' => 'new',
                'source' => 'facebook_lead_ad',
                'assigned_to_id' => $user->id,
                'notes' => 'Interested in enterprise SaaS plan.',
            ]
        );

        Lead::firstOrCreate(
            ['email' => 'elena@biotechlabs.io'],
            [
                'workspace_id' => $acmeWorkspace->id,
                'campaign_id' => $campaign2->id,
                'name' => 'Elena Rostova',
                'phone' => '+1 (555) 876-5432',
                'status' => 'contacted',
                'source' => 'google_lead_form',
                'assigned_to_id' => $user->id,
                'notes' => 'Followed up via email call scheduled for tomorrow.',
            ]
        );

        // CRM Sample Leads for Nexus
        Lead::firstOrCreate(
            ['email' => 'sophia.m@retailbuyer.com'],
            [
                'workspace_id' => $nexusWorkspace->id,
                'campaign_id' => $nexusCampaign1->id,
                'name' => 'Sophia Martinez',
                'phone' => '+1 (555) 987-6543',
                'status' => 'qualified',
                'source' => 'instagram',
                'assigned_to_id' => $user->id,
                'notes' => 'Bulk wholesale inquiry for fashion line.',
            ]
        );
    }
}
