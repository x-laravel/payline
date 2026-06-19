<?php

return [
    'default' => env('PAYLINE_DRIVER'),

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
        'webhook_middleware' => [],
    ],

    'bin_lookup' => [
        'default' => env('PAYLINE_BIN_LOOKUP_DRIVER', 'null'),
        'drivers' => [],
    ],

    'payment_model' => XLaravel\Payline\Models\Payment::class,

    'transaction_model' => XLaravel\Payline\Models\Transaction::class,

    'webhook_log_model' => XLaravel\Payline\Models\WebhookLog::class,

    'commission_rate_model' => XLaravel\Payline\Models\CommissionRate::class,
];
