<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\GoogleSearchConsoleService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class GoogleSearchConsoleServiceTest extends TestCase
{
    protected GoogleSearchConsoleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('GOOGLE_SEARCH_CONSOLE_SITE_URL=sc-domain:example.com');
        $this->service = new GoogleSearchConsoleService();
    }

    /**
     * Test createAuthUrl generates the correct OAuth 2.0 URL with GSC client ID and scopes.
     */
    public function test_create_auth_url_contains_configured_parameters(): void
    {
        $authUrl = $this->service->createAuthUrl(1);

        $this->assertStringContainsString('accounts.google.com', $authUrl);
        $this->assertStringContainsString(config('services.google_search_console.client_id'), $authUrl);
        $this->assertStringContainsString('webmasters.readonly', $authUrl);
        $this->assertStringContainsString('offline', $authUrl);
        $this->assertStringContainsString('consent', $authUrl);
    }

    /**
     * Test listAvailableSites correctly parses Google Search Console sites.list response.
     */
    public function test_list_available_sites_parses_api_response(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    [
                        'siteUrl'         => 'sc-domain:example.com',
                        'permissionLevel' => 'siteOwner',
                    ],
                    [
                        'siteUrl'         => 'https://example.com/blog/',
                        'permissionLevel' => 'siteFullUser',
                    ],
                ],
            ], 200),
        ]);

        $sites = $this->service->listAvailableSites('fake_access_token');

        $this->assertCount(2, $sites);
        $this->assertEquals('sc-domain:example.com', $sites[0]['site_url']);
        $this->assertEquals('siteOwner', $sites[0]['permission_level']);
        $this->assertEquals('https://example.com/blog/', $sites[1]['site_url']);
        $this->assertEquals('siteFullUser', $sites[1]['permission_level']);
    }

    /**
     * Test getOverview calculates total clicks, impressions, CTR, and average position.
     */
    public function test_get_overview_calculates_metrics(): void
    {
        Cache::put('gsc_token_1', 'mocked_oauth_token', 3600);

        Http::fake([
            'https://searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'rows' => [
                    [
                        'clicks'      => 120,
                        'impressions' => 2400,
                        'ctr'         => 0.05,
                        'position'    => 8.4,
                    ],
                    [
                        'clicks'      => 80,
                        'impressions' => 1600,
                        'ctr'         => 0.05,
                        'position'    => 12.6,
                    ],
                ],
            ], 200),
        ]);

        $overview = $this->service->getOverview('2026-08-01', '2026-08-28', 1);

        $this->assertTrue($overview['connected']);
        $this->assertEquals(200, $overview['clicks']);
        $this->assertEquals(4000, $overview['impressions']);
        $this->assertEquals(5.0, $overview['ctr']);
        $this->assertEquals(10.5, $overview['position']);
    }

    /**
     * Test getTopQueries formats search queries correctly.
     */
    public function test_get_top_queries_formats_correctly(): void
    {
        Cache::put('gsc_token_1', 'mocked_oauth_token', 3600);

        Http::fake([
            'https://searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'rows' => [
                    [
                        'keys'        => ['marketing automation software'],
                        'clicks'      => 45,
                        'impressions' => 900,
                        'ctr'         => 0.05,
                        'position'    => 3.2,
                    ],
                    [
                        'keys'        => ['social media command center'],
                        'clicks'      => 30,
                        'impressions' => 500,
                        'ctr'         => 0.06,
                        'position'    => 4.5,
                    ],
                ],
            ], 200),
        ]);

        $queries = $this->service->getTopQueries('2026-08-01', '2026-08-28', 10, 1);

        $this->assertCount(2, $queries);
        $this->assertEquals('marketing automation software', $queries[0]['query']);
        $this->assertEquals(45, $queries[0]['clicks']);
        $this->assertEquals(900, $queries[0]['impressions']);
        $this->assertEquals(5.0, $queries[0]['ctr']);
        $this->assertEquals(3.2, $queries[0]['position']);
    }

    /**
     * Test getTopQueries sorts by clicks DESC, then impressions DESC for tied clicks.
     */
    public function test_get_top_queries_sorts_by_clicks_desc_then_impressions_desc(): void
    {
        Cache::put('gsc_token_1', 'mocked_oauth_token', 3600);

        Http::fake([
            'https://searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'rows' => [
                    [
                        'keys'        => ['align software'],
                        'clicks'      => 0,
                        'impressions' => 1,
                        'ctr'         => 0,
                        'position'    => 27.0,
                    ],
                    [
                        'keys'        => ['redmind technologies'],
                        'clicks'      => 18,
                        'impressions' => 43,
                        'ctr'         => 0.4186,
                        'position'    => 1.2,
                    ],
                    [
                        'keys'        => ['offshore development center chennai'],
                        'clicks'      => 0,
                        'impressions' => 14,
                        'ctr'         => 0,
                        'position'    => 75.4,
                    ],
                    [
                        'keys'        => ['innovative plot management system'],
                        'clicks'      => 0,
                        'impressions' => 8,
                        'ctr'         => 0,
                        'position'    => 4.4,
                    ],
                ],
            ], 200),
        ]);

        $queries = $this->service->getTopQueries('2026-09-06', '2026-09-12', 10, 1);

        $this->assertCount(4, $queries);
        // 1st: clicks 18
        $this->assertEquals('redmind technologies', $queries[0]['query']);
        $this->assertEquals(18, $queries[0]['clicks']);
        $this->assertEquals(43, $queries[0]['impressions']);

        // 2nd: clicks 0, highest impressions (14)
        $this->assertEquals('offshore development center chennai', $queries[1]['query']);
        $this->assertEquals(0, $queries[1]['clicks']);
        $this->assertEquals(14, $queries[1]['impressions']);

        // 3rd: clicks 0, next impressions (8)
        $this->assertEquals('innovative plot management system', $queries[2]['query']);
        $this->assertEquals(0, $queries[2]['clicks']);
        $this->assertEquals(8, $queries[2]['impressions']);

        // 4th: clicks 0, lowest impressions (1)
        $this->assertEquals('align software', $queries[3]['query']);
        $this->assertEquals(0, $queries[3]['clicks']);
        $this->assertEquals(1, $queries[3]['impressions']);
    }

    /**
     * Test getDailyTrend formats trend labels and metrics.
     */
    public function test_get_daily_trend_formats_correctly(): void
    {
        Cache::put('gsc_token_1', 'mocked_oauth_token', 3600);

        Http::fake([
            'https://searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'rows' => [
                    [
                        'keys'        => ['2026-08-25'],
                        'clicks'      => 15,
                        'impressions' => 300,
                    ],
                    [
                        'keys'        => ['2026-08-26'],
                        'clicks'      => 25,
                        'impressions' => 450,
                    ],
                ],
            ], 200),
        ]);

        $trend = $this->service->getDailyTrend('2026-08-25', '2026-08-26', 1);

        $this->assertTrue($trend['has_data']);
        $this->assertCount(2, $trend['labels']);
        $this->assertEquals('08/25', $trend['labels'][0]);
        $this->assertEquals([15, 25], $trend['clicks']);
        $this->assertEquals([300, 450], $trend['impressions']);
    }
}
