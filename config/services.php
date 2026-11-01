<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        // The shop's Australian mobile number in E.164, e.g. +61400000000.
        'from' => env('TWILIO_FROM_NUMBER'),
        // What Twilio charges per text (segment) to Australian mobiles, in AUD cents. Only used for cost estimates.
        'segment_cost' => (int) env('TWILIO_SEGMENT_COST', 8),
    ],

    // Cloudflare's bot check on the public forms. Both keys must be set for the check to run.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    // Meta (Facebook and Instagram) ads. Without a pixel ID nothing is tracked; without a token, only the browser
    // pixel runs. Set a test event code to see server events in Events Manager's Test Events tab instead of live.
    'meta' => [
        'pixel_id' => env('META_PIXEL_ID'),
        'conversions_api_token' => env('META_CONVERSIONS_API_TOKEN'),
        'test_event_code' => env('META_TEST_EVENT_CODE'),
        'graph_api_version' => env('META_GRAPH_API_VERSION', 'v26.0'),
    ],

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

];
