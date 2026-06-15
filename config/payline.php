<?php

return [
    'default' => env('PAYLINE_DRIVER', 'hoppa'),

    'gateways' => [
        'hoppa' => [
            'merchant_id' => env('HOPPA_MERCHANT_ID'),
            'secret_key' => env('HOPPA_SECRET_KEY'),
            'mode' => env('HOPPA_MODE', 'sandbox'),
            'callback_success_url' => env('HOPPA_CALLBACK_SUCCESS_URL'),
            'callback_failure_url' => env('HOPPA_CALLBACK_FAILURE_URL'),
        ],

        'qnb' => [
            'merchant_id' => env('QNB_MERCHANT_ID'),
            'terminal_id' => env('QNB_TERMINAL_ID'),
            'secret_key' => env('QNB_SECRET_KEY'),
            'mode' => env('QNB_MODE', 'test'),
            'callback_success_url' => env('QNB_CALLBACK_SUCCESS_URL'),
            'callback_failure_url' => env('QNB_CALLBACK_FAILURE_URL'),
        ],

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

    'payment_model' => XLaravel\Payline\Models\Payment::class,

    'transaction_model' => XLaravel\Payline\Models\Transaction::class,

    'webhook_log_model' => XLaravel\Payline\Models\WebhookLog::class,
];
