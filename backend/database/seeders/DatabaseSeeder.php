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
        $aaraWorkspace = Workspace::firstOrCreate(
            ['name' => 'Aara Wellness'],
            [
                'industry' => 'Wellness & Healthcare',
                'primary_contact' => 'Meena',
                'primary_contact_email' => 'meena@aarawellness.com',
                'budget' => 50000.00,
                'status' => 'active',
                'owner_id' => $user->id,
            ]
        );

        $fastWorkspace = Workspace::firstOrCreate(
            ['name' => 'Fast Logistics'],
            [
                'industry' => 'Logistics',
                'primary_contact' => 'Danesh',
                'primary_contact_email' => 'danesh@fastlogistics.com',
                'budget' => 75000.00,
                'status' => 'active',
                'owner_id' => $user->id,
            ]
        );

        // 3. Attach User Roles to Workspaces
        $user->workspaces()->syncWithoutDetaching([$aaraWorkspace->id => ['role' => 'admin'], $fastWorkspace->id => ['role' => 'admin']]);

        // 4. Create Active Campaigns for Aara Wellness
        $campaign1 = Campaign::firstOrCreate(
            ['name' => 'August Monsoon Wellness Campaign', 'workspace_id' => $aaraWorkspace->id],
            [
                'platform' => 'meta_facebook',
                'status' => 'active',
                'budget' => 15000.00,
                'spent' => 8400.00,
                'start_date' => now()->subDays(15),
                'end_date' => now()->addDays(15),
                'description' => 'Targeting Wellness & Yoga Enthusiasts',
                'created_by_id' => $user->id,
            ]
        );

        // 5. Seed Daily Campaign Metrics for Aara Wellness
        CampaignMetric::where('campaign_id', $campaign1->id)->delete();
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

        // 6. Create CRM Sample Lead for Aara Wellness
        Lead::firstOrCreate(
            ['email' => 'priyanka@example.com'],
            [
                'workspace_id' => $aaraWorkspace->id,
                'campaign_id' => $campaign1->id,
                'name' => 'Priyanka Raj',
                'phone' => '+91 98765 22110',
                'status' => 'new',
                'source' => 'facebook_lead_ad',
                'assigned_to_id' => $user->id,
                'notes' => 'Interested in evening yoga class.',
            ]
        );
    }
}
