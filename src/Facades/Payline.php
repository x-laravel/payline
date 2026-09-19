<?php

namespace XLaravel\Payline\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\Models\WebhookLog;
use XLaravel\Payline\PaylineManager;
use XLaravel\Payline\PaymentOperations;
use XLaravel\Payline\PendingPayment;
use XLaravel\Payline\PendingPaymentBuilder;

/**
 * @method static Gateway driver(?string $driver = null)
 * @method static PendingPayment via(?string $driver = null)
 * @method static PendingPayment viaAuto()
 * @method static PendingPaymentBuilder for(Payable $payable)
 * @method static PaymentOperations payment(Payment $payment)
 * @method static ?string cheapestFor(CardProfile $profile, int $installments = 1)
 * @method static PaylineManager extend(string $driver, Closure $callback)
 *
 * @see PaylineManager
 */
class Payline extends Facade
{
    public const VERSION = '0.1.0';

    protected static ?string $paymentModel = null;

    protected static ?string $transactionModel = null;

    protected static ?string $webhookLogModel = null;

    protected static function getFacadeAccessor(): string
    {
        return 'payline';
    }

    public static function usePaymentModel(string $model): void
    {
        static::$paymentModel = $model;
    }

    public static function useTransactionModel(string $model): void
    {
        static::$transactionModel = $model;
    }

    /** @return class-string<Payment> */
    public static function paymentModel(): string
    {
        return static::$paymentModel
            ?? config('payline.models.payment', Payment::class);
    }

    /** @return class-string<Transaction> */
    public static function transactionModel(): string
    {
        return static::$transactionModel
            ?? config('payline.models.transaction', Transaction::class);
    }

    public static function useWebhookLogModel(string $model): void
    {
        static::$webhookLogModel = $model;
    }

    /** @return class-string<WebhookLog> */
    public static function webhookLogModel(): string
    {
        return static::$webhookLogModel
            ?? config('payline.models.webhook_log', WebhookLog::class);
    }
}
