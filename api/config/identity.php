<?php

declare(strict_types=1);

return [

    // One-time codes for mobile sign-in and invitations.
    'otp' => [
        'length' => 6,
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        'max_sends_per_window' => 3,
        'send_window_minutes' => 10,
        // Tried in order; the next channel is used if one fails.
        'channels' => array_filter(explode(',', (string) env('OTP_CHANNELS', 'whatsapp,sms'))),
    ],

    'tokens' => [
        'web_days' => 7,
        'mobile_days' => 60,
    ],

    'two_factor' => [
        'issuer' => env('APP_NAME', 'SimuWork'),
        'challenge_ttl_minutes' => 5,
        'recovery_codes' => 8,
    ],

    'invitations' => [
        'ttl_days' => 14,
    ],

    'password_reset' => [
        'ttl_minutes' => 60,
    ],

    /*
    | POPIA consents. Bump a version to ask everyone again at their next sign-in.
    */
    'consents' => [
        'terms' => '2026-11',
        'popia_processing' => '2026-11',
    ],

    /*
    | Message delivery. 'log' writes to the application log (development and tests).
    */
    'messaging' => [
        'driver_override' => env('MESSAGING_DRIVER'), // e.g. "log" in development
        'whatsapp' => [
            'token' => env('WHATSAPP_TOKEN'),
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
            'otp_template' => env('WHATSAPP_OTP_TEMPLATE', 'login_code'),
            'reminder_template' => env('WHATSAPP_REMINDER_TEMPLATE'), // approved utility template with one body parameter
            'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        ],
        'sms' => [
            'provider' => 'clickatell',
            'api_key' => env('CLICKATELL_API_KEY'),
        ],
    ],
];
