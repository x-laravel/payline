<?php

namespace XLaravel\Payline\Tests\Feature\Payment;

use Illuminate\Support\Facades\Event;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Events\PaymentInitiated;
use XLaravel\Payline\Events\PaymentSucceeded;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Tests\Fixtures\Gateways\FakeGateway;
use XLaravel\Payline\Tests\Fixtures\Models\Order;
use XLaravel\Payline\Tests\TestCase;

class ChargeTest extends TestCase
{
    public function test_charge_creates_payment_record(): void
    {
        $data = new PaymentRequest(reference: 'ORD-001', amount: 10000, currency: 'TRY');

        Payline::via('fake')->charge($data);

        $this->assertDatabaseHas('payline_payments', [
            'gateway' => 'fake',
            'reference' => 'ORD-001',
            'amount' => 10000,
            'status' => TransactionStatus::Successful->value,
        ]);
    }

    public function test_charge_creates_transaction_record(): void
    {
        $data = new PaymentRequest(reference: 'ORD-002', amount: 5000, currency: 'TRY');

        Payline::via('fake')->charge($data);

        $this->assertDatabaseHas('payline_transactions', [
            'type' => TransactionType::Payment->value,
            'status' => TransactionStatus::Successful->value,
            'amount' => 5000,
        ]);
    }

    public function test_charge_dispatches_events(): void
    {
        Event::fake([PaymentInitiated::class, PaymentSucceeded::class]);

        $data = new PaymentRequest(reference: 'ORD-003', amount: 10000, currency: 'TRY');

        Payline::via('fake')->charge($data);

        Event::assertDispatched(PaymentInitiated::class);
        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_charge_links_payment_to_payable_model(): void
    {
        $order = Order::create([
            'reference' => 'ORD-004',
            'amount' => 15000,
            'currency' => 'TRY',
        ]);

        Payline::for($order)->via('fake')->charge(PaymentRequest::fromPayable($order));

        $this->assertDatabaseHas('payline_payments', [
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'reference' => 'ORD-004',
        ]);
    }

    public function test_failed_gateway_response_records_failed_status(): void
    {
        FakeGateway::willReturn(new \XLaravel\Payline\DTOs\PaymentResponse(
            status: TransactionStatus::Failed,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            errorMessage: 'Insufficient funds',
        ));

        $data = new PaymentRequest(reference: 'ORD-005', amount: 99999, currency: 'TRY');

        Payline::via('fake')->charge($data);

        $this->assertDatabaseHas('payline_transactions', [
            'status' => TransactionStatus::Failed->value,
            'error_message' => 'Insufficient funds',
        ]);
    }

    public function test_payments_relation_returns_orders_payments(): void
    {
        $order = Order::create([
            'reference' => 'ORD-006',
            'amount' => 7500,
            'currency' => 'TRY',
        ]);

        Payline::for($order)->via('fake')->charge(PaymentRequest::fromPayable($order));

        $this->assertCount(1, $order->payments);
        $this->assertTrue($order->payments->first()->isSuccessful());
    }
}