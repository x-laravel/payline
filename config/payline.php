<?php

return [
    'default' => env('PAYLINE_GATEWAY'),

    'test_mode' => env('PAYLINE_TEST_MODE', false),

    'currency' => env('PAYLINE_CURRENCY', 'TRY'),

    'country' => env('PAYLINE_COUNTRY'),

    'gateways' => [
        'iyzico' => [
            'api_key' => env('IYZICO_API_KEY'),
            'secret_key' => env('IYZICO_SECRET_KEY'),
            'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
            'callback_success_url' => env('IYZICO_CALLBACK_SUCCESS_URL'),
            'callback_failure_url' => env('IYZICO_CALLBACK_FAILURE_URL'),
        ],
    ],

    'callback_success_url' => env('PAYLINE_CALLBACK_SUCCESS_URL', '/'),

    'callback_failure_url' => env('PAYLINE_CALLBACK_FAILURE_URL', '/'),

    'routes' => [
        'enabled' => true,
        'prefix' => 'payline',
        'middleware' => ['web'],
        'webhook_middleware' => ['throttle:60,1'],
        'callback_response' => 'breakout',
        'callback_views' => [
            'success' => 'payline::callback',
            'failure' => 'payline::callback',
        ],
    ],

    'bin_lookup' => [
        'providers' => [],
        'drivers' => [],
    ],

    'routing' => [
        'cost_of_capital' => env('PAYLINE_COST_OF_CAPITAL', 0),
        'policies' => [],
    ],

    'transactions' => [
        'pending_ttl' => env('PAYLINE_PENDING_TTL', 60),
    ],

    'models' => [
        'payment' => XLaravel\Payline\Models\Payment::class,
        'transaction' => XLaravel\Payline\Models\Transaction::class,
        'webhook_log' => XLaravel\Payline\Models\WebhookLog::class,
        'commission_rate' => XLaravel\Payline\Models\CommissionRate::class,
    ],

    'database' => [
        'connection' => env('PAYLINE_DB_CONNECTION', env('DB_CONNECTION', 'sqlite')),
    ],

    'storage' => [
        'card_details' => true,
        'card_holder_name' => true,
        'card_profile' => true,
        'webhook_payload' => true,
    ],

    'security' => [
        'redacted_payload_keys' => [
            'card_number',
            'cardnumber',
            'pan',
            'cvv',
            'cvc',
            'security_code',
        ],
    ],
];
