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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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


    'flutterwave' => [
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
    ],

    // ── Virtual Numbers / SMS Providers ──────────────────────────────────────

    'sms_providers' => [
        // Comma-separated priority order. First provider is tried first.
        // Remove a provider from this list to disable it entirely.
        'order'  => env('SMS_PROVIDER_ORDER', 'sms_activate,five_sim,twilio'),
        // Markup multiplier applied to provider prices before showing to users.
        // 1.30 = 30% markup. Set to 1.00 for no markup.
        'markup' => env('VIRTUAL_NUMBER_MARKUP', 1.30),
    ],

    'sms_activate' => [
        'api_key'  => env('SMS_ACTIVATE_API_KEY'),
        'base_url' => 'https://api.sms-activate.org/stubs/handler_api.php',
    ],

    'five_sim' => [
        'api_key'  => env('FIVE_SIM_API_KEY'),
        'base_url' => 'https://5sim.net/v1',
    ],

    'twilio' => [
        'sid'         => env('TWILIO_ACCOUNT_SID'),
        'token'       => env('TWILIO_AUTH_TOKEN'),
        'webhook_url' => env('TWILIO_WEBHOOK_URL'),
    ],

];
