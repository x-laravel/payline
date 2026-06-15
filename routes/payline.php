<?php

use Illuminate\Support\Facades\Route;
use XLaravel\Payline\Http\Controllers\CallbackController;
use XLaravel\Payline\Http\Controllers\WebhookController;

Route::match(['GET', 'POST'], '/callback/{gateway}', CallbackController::class)
    ->name('payline.callback');

Route::post('/webhooks/{gateway}', WebhookController::class)
    ->name('payline.webhook')
    ->withoutMiddleware(['web', 'csrf', \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
