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
   'evpay' => [
    'merchant_id' => env('EVPAY_MERCHANT_ID'),
    'secret_key' => env('EVPAY_SECRET_KEY'),
    'checkout_url' => env('EVPAY_CHECKOUT_URL', 'https://checkout.evmak.com/checkout'),
   ],

   'fx' => [
        // TZS per 1 USD (used when returning bundles in USD)
        'tzs_to_usd_rate' => (float) env('TZS_TO_USD_RATE', 2500),
   ],

   'vodacom_sim' => [
        'base_url' => env('VODACOM_SIM_BASE_URL'),
        'api_key' => env('VODACOM_SIM_API_KEY'),
   ],

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

];
