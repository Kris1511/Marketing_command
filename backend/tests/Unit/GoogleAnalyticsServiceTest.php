<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\GoogleAnalyticsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class GoogleAnalyticsServiceTest extends TestCase
{
    protected GoogleAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('GOOGLE_ANALYTICS_PROPERTY_ID=538133379');
        $this->service = new GoogleAnalyticsService();
    }

    /**
     * Test createAuthUrl generates the correct OAuth 2.0 URL with GA4 client ID and scopes.
     */
    public function test_create_auth_url_contains_configured_parameters(): void
    {
        $authUrl = $this->service->createAuthUrl(1);

        $this->assertStringContainsString('accounts.google.com', $authUrl);
        $this->assertStringContainsString(config('services.google_analytics.client_id'), $authUrl);
        $this->assertStringContainsString('analytics.readonly', $authUrl);
        $this->assertStringContainsString('offline', $authUrl);
        $this->assertStringContainsString('consent', $authUrl);
    }

    /**
     * Test listAvailableProperties correctly parses Google Analytics Admin API accountSummaries.
     */
    public function test_list_available_properties_parses_admin_api_response(): void
    {
        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries' => Http::response([
                'accountSummaries' => [
                    [
                        'name' => 'accountSummaries/12345',
                        'displayName' => 'My Test Company',
                        'propertySummaries' => [
                            [
                                'property' => 'properties/987654321',
                                'displayName' => 'Main Website GA4',
                            ],
                            [
                                'property' => 'properties/112233445',
                                'displayName' => 'Mobile App GA4',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $properties = $this->service->listAvailableProperties('fake_access_token');

        $this->assertCount(2, $properties);
        $this->assertEquals('987654321', $properties[0]['property_id']);
        $this->assertEquals('Main Website GA4', $properties[0]['name']);
        $this->assertEquals('My Test Company', $properties[0]['account_name']);
        $this->assertEquals('112233445', $properties[1]['property_id']);
    }

    /**
     * Test runReport executes Data API query with authorization token.
     */
    public function test_run_report_fetches_and_formats_metrics(): void
    {
        Cache::put('ga4_token_1', 'mocked_oauth_token', 3600);

        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/properties/538133379:runReport' => Http::response([
                'rows' => [
                    [
                        'metricValues' => [
                            ['value' => '1250'],
                            ['value' => '1500'],
                            ['value' => '4200'],
                            ['value' => '0.642'],
                            ['value' => '45'],
                        ]
                    ]
                ]
            ], 200),
        ]);

        $body = [
            'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'today']],
            'metrics'    => [['name' => 'activeUsers'], ['name' => 'sessions']],
        ];

        $report = $this->service->runReport('538133379', $body, 1);

        $this->assertArrayHasKey('rows', $report);
        $this->assertEquals('1250', $report['rows'][0]['metricValues'][0]['value']);
        $this->assertEquals('1500', $report['rows'][0]['metricValues'][1]['value']);
    }

    /**
     * Test resolveDateRange defaults to 28daysAgo and yesterday according to GA4 reporting conventions.
     */
    public function test_resolve_date_range_defaults_to_28days_and_yesterday(): void
    {
        $range = $this->service->resolveDateRange(null, null);
        $this->assertEquals('28daysAgo', $range['startDate']);
        $this->assertEquals('yesterday', $range['endDate']);

        $rangeAll = $this->service->resolveDateRange('all', null);
        $this->assertEquals('2020-01-01', $rangeAll['startDate']);
        $this->assertEquals('yesterday', $rangeAll['endDate']);

        $custom = $this->service->resolveDateRange('2026-08-18', '2026-09-14');
        $this->assertEquals('2026-08-18', $custom['startDate']);
        $this->assertEquals('2026-09-14', $custom['endDate']);
    }

    /**
     * Test formatEngagementDuration formats seconds like GA4 (14s, 1m 20s, etc.).
     */
    public function test_format_engagement_duration_formats_correctly(): void
    {
        $this->assertEquals('0s', $this->service->formatEngagementDuration(0));
        $this->assertEquals('14s', $this->service->formatEngagementDuration(14.63));
        $this->assertEquals('1m 20s', $this->service->formatEngagementDuration(80));
        $this->assertEquals('1h 2m 5s', $this->service->formatEngagementDuration(3725));
    }

    /**
     * Test formatRevenue formats currency according to currency code.
     */
    public function test_format_revenue(): void
    {
        $this->assertEquals('₹0.00', $this->service->formatRevenue(0, 'INR'));
        $this->assertEquals('₹1,500.50', $this->service->formatRevenue(1500.5, 'INR'));
        $this->assertEquals('$25.00', $this->service->formatRevenue(25, 'USD'));
    }

    /**
     * Test getTrafficSources parses sessionSourceMedium report rows.
     */
    public function test_get_traffic_sources_parses_source_medium(): void
    {
        Cache::put('ga4_token_1', 'mocked_oauth_token', 3600);

        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/properties/538133379:runReport' => Http::response([
                'rows' => [
                    [
                        'dimensionValues' => [['value' => '(direct) / (none)']],
                        'metricValues'    => [['value' => '327'], ['value' => '0'], ['value' => '0']],
                    ],
                    [
                        'dimensionValues' => [['value' => 'google / organic']],
                        'metricValues'    => [['value' => '184'], ['value' => '0'], ['value' => '0']],
                    ],
                ],
                'metadata' => [
                    'currencyCode' => 'INR'
                ]
            ], 200),
        ]);

        $sources = $this->service->getTrafficSources('2026-08-18', '2026-09-14', 10, 1);

        $this->assertCount(2, $sources);
        $this->assertEquals('(direct) / (none)', $sources[0]['source_medium']);
        $this->assertEquals(327, $sources[0]['sessions']);
        $this->assertEquals(0, $sources[0]['key_events']);
        $this->assertEquals('₹0.00', $sources[0]['revenue_formatted']);

        $this->assertEquals('google / organic', $sources[1]['source_medium']);
        $this->assertEquals(184, $sources[1]['sessions']);
    }
}
