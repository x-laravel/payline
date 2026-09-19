<?php

namespace XLaravel\Payline\Tests\Feature\Payment;

use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Tests\Fixtures\Models\Order;
use XLaravel\Payline\Tests\TestCase;

class PayableAmountsTest extends TestCase
{
    public function test_a_charged_order_reports_the_charged_amount(): void
    {
        $order = $this->order();

        $order->pay('fake')->charge();

        $this->assertSame(10000, $order->amountPaid());
        $this->assertSame(0, $order->amountRefunded());
        $this->assertSame(10000, $order->amountNet());
    }

    public function test_refunds_reduce_the_net_amount(): void
    {
        $order = $this->order();
        $order->pay('fake')->charge();

        Payline::payment(Payment::firstOrFail())->refund(amount: 4000);

        $this->assertSame(10000, $order->amountPaid());
        $this->assertSame(4000, $order->amountRefunded());
        $this->assertSame(6000, $order->amountNet());
    }

    public function test_a_partially_captured_order_reports_only_what_was_captured(): void
    {
        $order = $this->order();
        $order->pay('fake')->authorize();

        Payline::payment(Payment::firstOrFail())->capture(amount: 4000);

        $this->assertSame(4000, $order->amountPaid());
        $this->assertSame(4000, $order->amountNet());
    }

    public function test_an_authorized_order_has_collected_nothing(): void
    {
        $order = $this->order();
        $order->pay('fake')->authorize();

        $this->assertSame(0, $order->amountPaid());
        $this->assertSame(0, Payment::firstOrFail()->capturedAmount());
    }

    public function test_only_the_captured_amount_is_refundable(): void
    {
        $this->order()->pay('fake')->authorize();

        $payment = Payment::firstOrFail();
        Payline::payment($payment)->capture(amount: 4000);

        $this->assertSame(4000, $payment->fresh()->remainingRefundable());
    }

    public function test_refunds_reduce_the_refundable_amount(): void
    {
        $this->order()->pay('fake')->charge();

        $payment = Payment::firstOrFail();
        Payline::payment($payment)->refund(amount: 2500);

        $this->assertSame(10000, $payment->fresh()->capturedAmount());
        $this->assertSame(7500, $payment->fresh()->remainingRefundable());
    }

    private function order(int $amount = 10000): Order
    {
        return Order::create([
            'reference' => 'ORD-001',
            'amount' => $amount,
            'currency' => 'TRY',
        ]);
    }
}
