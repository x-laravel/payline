<?php

namespace XLaravel\Payline\Tests\Feature\Payment;

use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\Tests\Fixtures\Gateways\FakeGateway;
use XLaravel\Payline\Tests\TestCase;

class CallbackRouteTest extends TestCase
{
    public function test_the_callback_leaves_the_frame_it_was_delivered_into(): void
    {
        config(['payline.callback_success_url' => '/orders/done']);

        $response = $this->post('/payline/callback/fake', $this->pendingCallback());

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('window.top.location.replace("/orders/done")', false);
    }

    public function test_the_breakout_page_offers_a_link_without_scripting(): void
    {
        config(['payline.callback_success_url' => '/orders/done']);

        $this->post('/payline/callback/fake', $this->pendingCallback())
            ->assertSee('<noscript><a href="/orders/done">Continue</a></noscript>', false);
    }

    public function test_the_destination_is_escaped(): void
    {
        config(['payline.callback_success_url' => '/orders?done="><script>alert(1)</script>']);

        $this->post('/payline/callback/fake', $this->pendingCallback())
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_a_plain_redirect_is_still_available(): void
    {
        config([
            'payline.routes.callback_breakout' => false,
            'payline.callback_success_url' => '/orders/done',
        ]);

        $this->post('/payline/callback/fake', $this->pendingCallback())
            ->assertRedirect('/orders/done');
    }

    public function test_the_callback_settles_the_payment(): void
    {
        $this->post('/payline/callback/fake', $this->pendingCallback());

        $this->assertSame(PaymentStatus::Paid, Payment::firstOrFail()->status);
        $this->assertSame(TransactionStatus::Successful, Transaction::firstOrFail()->status);
    }

    private function pendingCallback(): array
    {
        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Pending,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-3ds-1',
            redirectUrl: 'https://bank.test/3ds',
        ));

        Payline::via('fake')->reference('ORD-001')->amount(10000)->charge();

        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-3ds-1',
        ));

        return [];
    }
}
