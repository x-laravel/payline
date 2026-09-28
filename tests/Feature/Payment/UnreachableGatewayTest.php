<?php

namespace XLaravel\Payline\Tests\Feature\Payment;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Events\PaymentErrored;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\Tests\Fixtures\Gateways\FakeGateway;
use XLaravel\Payline\Tests\TestCase;

class UnreachableGatewayTest extends TestCase
{
    public function test_a_charge_the_gateway_never_answered_comes_back_unknown(): void
    {
        FakeGateway::willThrow(new ConnectionException('cURL error 28: Operation timed out'));

        $response = Payline::via('fake')->reference('ORD-001')->amount(10000)->charge();

        $this->assertSame(TransactionStatus::Unknown, $response->status);
        $this->assertSame(TransactionType::Payment, $response->type);
        $this->assertSame(10000, $response->amount);
        $this->assertSame('TRY', $response->currency);
        $this->assertSame(TransactionStatus::Unknown, Transaction::firstOrFail()->status);
        $this->assertSame(PaymentStatus::Unknown, Payment::firstOrFail()->status);
    }

    public function test_a_refund_the_gateway_never_answered_comes_back_unknown(): void
    {
        $payment = $this->paidPayment();

        FakeGateway::willThrow(new ConnectionException('Connection refused'));

        $response = Payline::payment($payment)->refund(amount: 4000);

        $this->assertSame(TransactionStatus::Unknown, $response->status);
        $this->assertSame(TransactionType::Refund, $response->type);
        $this->assertSame(PaymentStatus::Unknown, $payment->fresh()->status);
    }

    public function test_an_unreachable_gateway_still_reports_the_error(): void
    {
        FakeGateway::willThrow(new ConnectionException('cURL error 28'));

        Event::fake([PaymentErrored::class]);

        Payline::via('fake')->reference('ORD-001')->amount(10000)->charge();

        Event::assertDispatched(PaymentErrored::class);
    }

    public function test_any_other_failure_is_still_raised(): void
    {
        FakeGateway::willThrow(new RuntimeException('Driver bug.'));

        $this->expectException(RuntimeException::class);

        try {
            Payline::via('fake')->reference('ORD-001')->amount(10000)->charge();
        } finally {
            $this->assertSame(TransactionStatus::Unknown, Transaction::firstOrFail()->status);
        }
    }

    private function paidPayment(): Payment
    {
        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-sale-1',
        ));

        Payline::via('fake')->reference('ORD-PAID')->amount(10000)->charge();

        return Payment::firstOrFail();
    }
}
