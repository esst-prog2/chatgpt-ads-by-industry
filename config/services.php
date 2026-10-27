<?php

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

    // Google Sign-In (Socialite). allowed_domain restricts login to one
    // company Google Workspace domain - enforced server-side in the OAuth
    // callback, not just via the (spoofable) 'hd' request parameter.
    // admin_emails seeds a user's role as Admin the FIRST time they ever log
    // in, nothing more - it is not re-applied on later logins, so an admin's
    // role changes (made via the access panel) always stick even if the
    // email stays in this list. See PLANNING_LOG.md 2026-10-27.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'allowed_domain' => env('ALLOWED_GOOGLE_DOMAIN'),
        'admin_emails' => array_filter(array_map('trim', explode(',', env('ADMIN_EMAILS', '')))),
    ],

];
