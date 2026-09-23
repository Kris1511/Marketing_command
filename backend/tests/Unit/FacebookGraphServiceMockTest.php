<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\FacebookGraphService;
use Illuminate\Support\Facades\Http;

/**
 * Unit Tests for FacebookGraphService using MOCKED Meta Graph API responses.
 * 
 * NOTE: These tests use Http::fake() to simulate Meta Graph API responses.
 * ZERO requests are made to real Meta accounts.
 */
class FacebookGraphServiceMockTest extends TestCase
{
    /**
     * Test getPageDetails returns correct page info and follower count from mocked Meta API.
     */
    public function test_get_page_details_with_mocked_meta_api(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/115864121526929*' => Http::response([
                'id'              => '115864121526929',
                'name'            => 'RedMind Technologies',
                'followers_count' => 31,
                'fan_count'       => 31,
                'picture'         => [
                    'data' => ['url' => 'https://example.com/profile.jpg']
                ],
            ], 200),
        ]);

        $service = new FacebookGraphService();
        $details = $service->getPageDetails('115864121526929', 'fake_page_token');

        $this->assertEquals('115864121526929', $details['page_id']);
        $this->assertEquals('RedMind Technologies', $details['page_name']);
        $this->assertEquals(31, $details['followers_count']);
        $this->assertEquals(31, $details['fan_count']);
    }

    /**
     * Test getInstagramAccountInsights parses total_value metrics correctly.
     */
    public function test_get_instagram_account_insights_with_mocked_meta_api(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/17841447923134069/insights*' => Http::response([
                'data' => [
                    [
                        'name'        => 'reach',
                        'period'      => 'day',
                        'total_value' => ['value' => 1026],
                    ],
                    [
                        'name'        => 'total_interactions',
                        'period'      => 'day',
                        'total_value' => ['value' => 15],
                    ],
                    [
                        'name'        => 'accounts_engaged',
                        'period'      => 'day',
                        'total_value' => ['value' => 9],
                    ],
                ]
            ], 200),
        ]);

        $service = new FacebookGraphService();
        $insights = $service->getInstagramAccountInsights('fake_token', '17841447923134069', 30);

        $this->assertEquals(1026, $insights['reach']);
        $this->assertEquals(15, $insights['total_interactions']);
        $this->assertEquals(9, $insights['accounts_engaged']);
    }

    /**
     * Test getInstagramMediaList correctly aggregates views, likes, and shares.
     */
    public function test_get_instagram_media_list_with_mocked_meta_api(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/17841447923134069/media*' => Http::response([
                'data' => [
                    [
                        'id'             => '18112447154006128',
                        'caption'        => 'Test office post',
                        'media_type'     => 'IMAGE',
                        'permalink'      => 'https://instagram.com/p/test',
                        'timestamp'      => '2026-08-30T10:00:00+0000',
                        'like_count'     => 5,
                        'comments_count' => 2,
                    ]
                ]
            ], 200),
            'https://graph.facebook.com/v23.0/18112447154006128/insights*' => Http::response([
                'data' => [
                    ['name' => 'views', 'values' => [['value' => 120]]],
                    ['name' => 'reach', 'values' => [['value' => 80]]],
                    ['name' => 'saved', 'values' => [['value' => 4]]],
                    ['name' => 'shares', 'values' => [['value' => 1]]],
                ]
            ], 200),
        ]);

        $service = new FacebookGraphService();
        $media = $service->getInstagramMediaList('fake_token', '17841447923134069', 10, true);

        $this->assertCount(1, $media);
        $this->assertEquals(120, $media[0]['views_count']);
        $this->assertEquals(5, $media[0]['like_count']);
        $this->assertEquals(2, $media[0]['comments_count']);
        $this->assertEquals(1, $media[0]['shares_count']);
        $this->assertEquals(4, $media[0]['saved_count']);
    }

    /**
     * Test getFacebookFeedPostMetrics returns real metrics with views, likes, and comments supported.
     */
    public function test_get_facebook_feed_post_metrics_fallback_with_mocked_meta_api(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/115864121526929/published_posts*' => Http::response([
                'data' => [
                    [
                        'id'           => '115864121526929_1001',
                        'message'      => 'Real post without like breakdown',
                        'created_time' => '2026-08-25T10:00:00+0000',
                        'shares'       => ['count' => 0],
                        'permalink_url'=> 'https://facebook.com/1001',
                        'attachments'  => [
                            'data' => [
                                [
                                    'type'   => 'photo',
                                    'target' => ['id' => '1001_photo'],
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200),
            'https://graph.facebook.com/v23.0/115864121526929_1001/insights*' => Http::response([
                'data' => [],
            ], 200),
            'https://graph.facebook.com/v23.0/1001_photo*' => Http::response([
                'id'       => '1001_photo',
                'likes'    => ['summary' => ['total_count' => 5]],
                'comments' => ['summary' => ['total_count' => 1]],
            ], 200),
            'https://graph.facebook.com/v23.0/115864121526929_1001*' => Http::response([
                'id' => '115864121526929_1001',
            ], 200),
            'https://graph.facebook.com/v23.0/115864121526929/videos*' => Http::response([
                'data' => [
                    [
                        'id'           => '1001_video',
                        'views'        => 250,
                        'created_time' => '2026-08-20T10:00:00+0000',
                    ]
                ]
            ], 200),
        ]);

        $service = new FacebookGraphService();
        $metrics = $service->getFacebookFeedPostMetrics('115864121526929', 'fake_token', 30, true);

        $this->assertTrue($metrics['success']);
        $this->assertEquals(1, $metrics['posts_count']);
        $this->assertTrue($metrics['likes_supported']);
        $this->assertTrue($metrics['comments_supported']);
        $this->assertFalse($metrics['views_supported']);
        $this->assertTrue($metrics['shares_supported']);
        $this->assertEquals(5, $metrics['likes']);
        $this->assertEquals(1, $metrics['comments']);
        $this->assertNull($metrics['views']);
        $this->assertEquals(0, $metrics['shares']);
    }

    /**
     * Test getFacebookFeedPostMetrics wires real Facebook post_media_view values
     * for photo/carousel/video posts and keeps the existing video-object fallback for reels.
     */
    public function test_get_facebook_feed_post_metrics_maps_views_for_supported_post_types(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/115864121526929/published_posts*' => Http::response([
                'data' => [
                    [
                        'id'           => '115864121526929_2001',
                        'message'      => 'Photo post',
                        'created_time' => '2026-09-17T10:00:00+0000',
                        'permalink_url'=> 'https://facebook.com/2001',
                        'attachments'  => ['data' => [['type' => 'photo', 'target' => ['id' => '2001_photo']]]],
                    ],
                    [
                        'id'           => '115864121526929_2002',
                        'message'      => 'Carousel post',
                        'created_time' => '2026-09-17T11:00:00+0000',
                        'permalink_url'=> 'https://facebook.com/2002',
                        'attachments'  => ['data' => [[
                            'type' => 'album',
                            'subattachments' => ['data' => [
                                ['type' => 'photo', 'target' => ['id' => '2002_photo_a']],
                                ['type' => 'photo', 'target' => ['id' => '2002_photo_b']],
                            ]],
                        ]]],
                    ],
                    [
                        'id'           => '115864121526929_2003',
                        'message'      => 'Video post',
                        'created_time' => '2026-09-17T12:00:00+0000',
                        'permalink_url'=> 'https://facebook.com/2003',
                        'attachments'  => ['data' => [['type' => 'video', 'target' => ['id' => '2003_video']]]],
                    ],
                    [
                        'id'           => '115864121526929_2004',
                        'message'      => 'Reel post',
                        'created_time' => '2026-09-17T13:00:00+0000',
                        'permalink_url'=> 'https://facebook.com/reel/2004_video',
                        'attachments'  => ['data' => [['type' => 'video', 'target' => ['id' => '2004_video']]]],
                    ],
                ],
            ], 200),
            'https://graph.facebook.com/v23.0/115864121526929/video_reels*' => Http::response([
                'data' => [['id' => '2004_video']],
            ], 200),
            'https://graph.facebook.com/v23.0/115864121526929_2001/insights*' => Http::response([
                'data' => [['name' => 'post_media_view', 'period' => 'lifetime', 'values' => [['value' => 12]]]],
            ], 200),
            'https://graph.facebook.com/v23.0/115864121526929_2002/insights*' => Http::response([
                'data' => [['name' => 'post_media_view', 'period' => 'lifetime', 'values' => [['value' => ['organic' => 20, 'paid' => 3]]]]],
            ], 200),
            'https://graph.facebook.com/v23.0/115864121526929_2003/insights*' => Http::response([
                'data' => [['name' => 'post_media_view', 'period' => 'lifetime', 'values' => [['value' => 34]]]],
            ], 200),
            'https://graph.facebook.com/v23.0/115864121526929_2004/insights*' => Http::response([
                'data' => [],
            ], 200),
            'https://graph.facebook.com/v23.0/2003_video*' => Http::response([
                'id' => '2003_video',
                'views' => 340,
                'likes' => ['summary' => ['total_count' => 0]],
                'comments' => ['summary' => ['total_count' => 0]],
            ], 200),
            'https://graph.facebook.com/v23.0/2004_video*' => Http::response([
                'id' => '2004_video',
                'views' => 45,
                'likes' => ['summary' => ['total_count' => 0]],
                'comments' => ['summary' => ['total_count' => 0]],
            ], 200),
            'https://graph.facebook.com/v23.0/2001_photo*' => Http::response(['id' => '2001_photo'], 200),
            'https://graph.facebook.com/v23.0/2002_photo_a*' => Http::response(['id' => '2002_photo_a'], 200),
            'https://graph.facebook.com/v23.0/2002_photo_b*' => Http::response(['id' => '2002_photo_b'], 200),
            'https://graph.facebook.com/v23.0/115864121526929_2001*' => Http::response(['id' => '115864121526929_2001'], 200),
            'https://graph.facebook.com/v23.0/115864121526929_2002*' => Http::response(['id' => '115864121526929_2002'], 200),
            'https://graph.facebook.com/v23.0/115864121526929_2003*' => Http::response(['id' => '115864121526929_2003'], 200),
            'https://graph.facebook.com/v23.0/115864121526929_2004*' => Http::response(['id' => '115864121526929_2004'], 200),
        ]);

        $service = new FacebookGraphService();
        $metrics = $service->getFacebookFeedPostMetrics('115864121526929', 'fake_token', 7, true, '2026-09-17', '2026-09-17');

        $this->assertTrue($metrics['success']);
        $this->assertTrue($metrics['views_supported']);
        $this->assertSame(4, $metrics['posts_count']);
        $this->assertSame(114, $metrics['views']);
    }

    /**
     * Test getPostMediaViews reads Facebook's post_media_view metric for post views.
     */
    public function test_get_post_media_views_with_mocked_meta_api(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/photo_post/insights*' => Http::response([
                'data' => [
                    [
                        'name'   => 'post_media_view',
                        'period' => 'lifetime',
                        'values' => [['value' => 123]],
                    ],
                ],
            ], 200),
            'https://graph.facebook.com/v23.0/carousel_post/insights*' => Http::response([
                'data' => [
                    [
                        'name'   => 'post_media_view',
                        'period' => 'lifetime',
                        'values' => [['value' => ['organic' => 200, 'paid' => 25]]],
                    ],
                ],
            ], 200),
            'https://graph.facebook.com/v23.0/reel_post/insights*' => Http::response([
                'data' => [
                    [
                        'name'   => 'post_media_view',
                        'period' => 'lifetime',
                        'values' => [['value' => 456]],
                    ],
                ],
            ], 200),
        ]);

        $service = new FacebookGraphService();
        $views = $service->getPostMediaViews(['photo_post', 'carousel_post', 'reel_post'], 'fake_token');

        $this->assertSame(123, $views['photo_post']);
        $this->assertSame(225, $views['carousel_post']);
        $this->assertSame(456, $views['reel_post']);
    }

    /**
     * Test getPostMediaViews leaves posts absent when Meta provides no view data.
     */
    public function test_get_post_media_views_skips_empty_meta_data(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/no_views_post/insights*' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $service = new FacebookGraphService();
        $views = $service->getPostMediaViews(['no_views_post'], 'fake_token');

        $this->assertSame([], $views);
    }

    /**
     * Test getInstagramFollowersGained correctly sums follower_count values.
     */
    public function test_get_instagram_followers_gained_with_mocked_meta_api(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/17841447923134069/insights*' => Http::response([
                'data' => [
                    [
                        'name'   => 'follower_count',
                        'period' => 'day',
                        'values' => [
                            ['value' => 2, 'end_time' => '2026-08-20T07:00:00+0000'],
                            ['value' => 3, 'end_time' => '2026-08-21T07:00:00+0000'],
                        ],
                    ],
                ]
            ], 200),
        ]);

        $service = new FacebookGraphService();
        $gained = $service->getInstagramFollowersGained('fake_token', '17841447923134069', 30);

        $this->assertEquals(5, $gained);
    }
}
