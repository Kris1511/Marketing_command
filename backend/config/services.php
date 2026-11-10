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
        'scopes'        => env('FACEBOOK_OAUTH_SCOPES', 'public_profile,pages_show_list,pages_read_engagement,pages_manage_posts,pages_read_user_content,pages_manage_metadata,pages_messaging,instagram_basic,instagram_manage_insights,instagram_manage_comments,instagram_content_publish,instagram_manage_messages,read_insights'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v23.0'),
        // Public frontend origin used to build the data-deletion status URL returned to Meta.
        'frontend_url'  => env('FRONTEND_URL'),
        // Ask Meta to validate the App ID/Secret before opening the login dialog (set false to skip the extra call).
        'preflight'     => env('FACEBOOK_OAUTH_PREFLIGHT', true),
        'publishing_enabled' => env('FACEBOOK_ENABLE_PUBLISHING', false),
        'webhook_verify_token' => env('FACEBOOK_WEBHOOK_VERIFY_TOKEN', env('META_WEBHOOK_VERIFY_TOKEN', '')),
        // Dedicated Testing workspace credentials (Redmind_Demo)
        'testing_client_id'     => env('FACEBOOK_TESTING_APP_ID'),
        'testing_client_secret' => env('FACEBOOK_TESTING_APP_SECRET'),
        'testing_workspace_id'  => env('FACEBOOK_TESTING_WORKSPACE_ID', '8'),
        'testing_redirect'      => env('FACEBOOK_TESTING_REDIRECT_URI'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID', env('YOUTUBE_CLIENT_ID')),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', env('YOUTUBE_CLIENT_SECRET')),
        'redirect_uri'  => env('GOOGLE_REDIRECT_URI', env('YOUTUBE_REDIRECT_URI', 'http://localhost:8000/api/youtube/callback')),
    ],

    /*
    | Public media URLs for Instagram publishing.
    | Instagram downloads post media from a URL (it cannot receive file uploads), so that URL must be
    | HTTPS, on a public host and serve this server's /storage/posts files. See docs/instagram-media-publishing.md.
    |  - url:     public base that serves /storage (defaults to APP_URL). In local development use an HTTPS
    |            tunnel (Cloudflare Tunnel / ngrok); in production your real domain or CDN.
    |  - driver:  auto (public storage, falling back to Cloudinary when that URL is unusable) | storage | cloudinary
    |  - verify:  download the URL before calling Meta to confirm HTTP 200 + image/jpeg (recommended).
    */
    'public_media' => [
        'url'            => env('PUBLIC_MEDIA_URL'),
        'driver'         => env('PUBLIC_MEDIA_DRIVER', 'auto'),
        'verify'         => env('PUBLIC_MEDIA_VERIFY', true),
        'verify_timeout' => (int) env('PUBLIC_MEDIA_VERIFY_TIMEOUT', 15),
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key'    => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
    ],

    'instagram' => [
        // Seconds between media-container status checks (null = 2s for images, 3s for videos).
        'container_poll_seconds' => env('INSTAGRAM_CONTAINER_POLL_SECONDS'),
    ],

    'twitter' => [
        'client_id'     => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'redirect'      => env('TWITTER_REDIRECT_URI', 'http://localhost:8000/api/twitter/callback'),
    ],

    'google_analytics' => [
        'client_id'     => env('GOOGLE_ANALYTICS_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
        'client_secret' => env('GOOGLE_ANALYTICS_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET')),
        'redirect_uri'  => env('GOOGLE_ANALYTICS_REDIRECT_URI', 'http://localhost:8000/api/v1/google-analytics/callback'),
        'scopes'        => [
            'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ],
    ],

    'google_search_console' => [
        'client_id'     => env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
        'client_secret' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET')),
        'redirect_uri'  => env('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI', 'http://localhost:8000/api/v1/search-console/callback'),
        'scopes'        => [
            'https://www.googleapis.com/auth/webmasters.readonly',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ],
    ],

];
