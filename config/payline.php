<?php

return [
    'default' => env('PAYLINE_DRIVER'),

    'currency' => env('PAYLINE_CURRENCY', 'TRY'),

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
    ],

    'bin_lookup' => [
        'default' => env('PAYLINE_BIN_LOOKUP_DRIVER', 'null'),
        'drivers' => [],
    ],

    'routing' => [
        'policies' => [],
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
