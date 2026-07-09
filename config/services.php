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
    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'evpay' => [
        'merchant_id' => env('EVPAY_MERCHANT_ID'),
        'secret_key' => env('EVPAY_SECRET_KEY'),
        'checkout_url' => env('EVPAY_CHECKOUT_URL', 'https://checkout.evmak.com/checkout'),
        'return_url' => env('EVPAY_RETURN_URL', 'https://thetravela.com/dashboard'),
        'callback_url' => env('EVPAY_CALLBACK_URL'),
        'callback_require_signature' => env('EVPAY_CALLBACK_REQUIRE_SIGNATURE', false),
    ],

   'fx' => [
        // TZS per 1 USD (used when returning bundles in USD)
        'tzs_to_usd_rate' => (float) env('TZS_TO_USD_RATE', 2610),
   ],

   'vodacom_sim' => [
        // Full origin only (e.g. https://simmanager.vodacom.co.tz) — paths include /api/...
        'base_url' => env('VODACOM_SIM_BASE_URL', env('ESIM_MANAGER_URL')),
        'api_key' => env('VODACOM_SIM_API_KEY', env('ESIM_MANAGER_KEY')),
        // Vodacom product_id (sim_bundle_id) => TZS airtime_amount string for /api/recharge
        'recharge_airtime_by_product_id' => array_filter([
            66 => env('VODACOM_RECHARGE_AIRTIME_66', '500'),
            72 => env('VODACOM_RECHARGE_AIRTIME_72', '500'),
        ], fn ($v) => $v !== null && $v !== ''),
        'recharge_reference_prefix' => env('VODACOM_RECHARGE_REFERENCE_PREFIX', 'RECHARGE'),
   ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
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

    'ghostscript' => [
        'binary' => env('GHOSTSCRIPT_BINARY'),
    ],

];
