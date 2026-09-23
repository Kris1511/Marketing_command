<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'facebook' => [
        'client_id'     => env('FACEBOOK_APP_ID'),
        'client_secret' => env('FACEBOOK_APP_SECRET'),
        'redirect'      => env('FACEBOOK_REDIRECT_URI', 'http://localhost:8000/api/v1/auth/facebook/callback'),
        'scopes'        => env('FACEBOOK_OAUTH_SCOPES', 'public_profile,pages_show_list,pages_read_engagement,pages_read_user_content,pages_manage_metadata,pages_messaging,instagram_basic,instagram_manage_insights,instagram_manage_comments,instagram_content_publish,instagram_manage_messages,read_insights'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v23.0'),
        'webhook_verify_token' => env('FACEBOOK_WEBHOOK_VERIFY_TOKEN', env('META_WEBHOOK_VERIFY_TOKEN', '')),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID', env('YOUTUBE_CLIENT_ID')),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', env('YOUTUBE_CLIENT_SECRET')),
        'redirect_uri'  => env('GOOGLE_REDIRECT_URI', env('YOUTUBE_REDIRECT_URI', 'http://localhost:8000/api/youtube/callback')),
    ],

    'twitter' => [
        'client_id'     => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'redirect'      => env('TWITTER_REDIRECT_URI', 'http://localhost:8000/api/twitter/callback'),
    ],

    'google_analytics' => [
        'client_id'     => env('GOOGLE_ANALYTICS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_ANALYTICS_CLIENT_SECRET'),
        'redirect_uri'  => env('GOOGLE_ANALYTICS_REDIRECT_URI', 'http://localhost:8000/api/v1/google-analytics/callback'),
        'scopes'        => [
            'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ],
    ],

    'google_search_console' => [
        'client_id'     => env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET'),
        'redirect_uri'  => env('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI', 'http://localhost:8000/api/v1/search-console/callback'),
        'scopes'        => [
            'https://www.googleapis.com/auth/webmasters.readonly',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ],
    ],

];
