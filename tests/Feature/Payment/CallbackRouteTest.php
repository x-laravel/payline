<?php

namespace XLaravel\Payline\Tests\Feature\Payment;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
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
            'payline.routes.callback_response' => 'redirect',
            'payline.callback_success_url' => '/orders/done',
        ]);

        $this->post('/payline/callback/fake', $this->pendingCallback())
            ->assertRedirect('/orders/done');
    }

    public function test_the_view_response_reports_the_result_to_the_parent_frame(): void
    {
        config(['payline.routes.callback_response' => 'view']);

        $response = $this->post('/payline/callback/fake', $this->pendingCallback());

        $response->assertOk();
        $response->assertSee('window.parent.postMessage({"type":"payline","status":"successful","approved":true,"message":null}', false);
    }

    public function test_the_view_response_carries_the_failure_message(): void
    {
        config(['payline.routes.callback_response' => 'view']);

        $this->post('/payline/callback/fake', $this->pendingCallback(new PaymentResponse(
            status: TransactionStatus::Failed,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-3ds-1',
            errorMessage: 'Insufficient funds',
        )))->assertSee('Insufficient funds');
    }

    public function test_the_view_response_renders_the_configured_view_per_outcome(): void
    {
        View::addNamespace('app', __DIR__ . '/../../Fixtures/views');

        config([
            'payline.routes.callback_response' => 'view',
            'payline.routes.callback_views.success' => 'app::paid',
        ]);

        $this->post('/payline/callback/fake', $this->pendingCallback())
            ->assertSee('paid:successful');
    }

    public function test_an_unknown_response_mode_is_rejected_before_the_callback_is_applied(): void
    {
        config(['payline.routes.callback_response' => 'popup']);

        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->post('/payline/callback/fake', $this->pendingCallback());
        } finally {
            $this->assertSame(TransactionStatus::Pending, Transaction::firstOrFail()->status);
        }
    }

    public function test_a_missing_success_view_is_rejected_before_the_callback_is_applied(): void
    {
        config([
            'payline.routes.callback_response' => 'view',
            'payline.routes.callback_views.success' => 'app::nowhere',
        ]);

        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->post('/payline/callback/fake', $this->pendingCallback());
        } finally {
            $this->assertSame(TransactionStatus::Pending, Transaction::firstOrFail()->status);
        }
    }

    public function test_a_missing_failure_view_is_rejected_although_the_callback_succeeds(): void
    {
        config([
            'payline.routes.callback_response' => 'view',
            'payline.routes.callback_views.failure' => 'app::nowhere',
        ]);

        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->post('/payline/callback/fake', $this->pendingCallback());
        } finally {
            $this->assertSame(TransactionStatus::Pending, Transaction::firstOrFail()->status);
        }
    }

    public function test_request_forgery_protection_is_removed_from_both_routes(): void
    {
        foreach (['payline.callback', 'payline.webhook'] as $name) {
            $this->assertContains(
                PreventRequestForgery::class,
                Route::getRoutes()->getByName($name)->excludedMiddleware(),
            );
        }
    }

    public function test_the_callback_settles_the_payment(): void
    {
        $this->post('/payline/callback/fake', $this->pendingCallback());

        $this->assertSame(PaymentStatus::Paid, Payment::firstOrFail()->status);
        $this->assertSame(TransactionStatus::Successful, Transaction::firstOrFail()->status);
    }

    private function pendingCallback(?PaymentResponse $callback = null): array
    {
        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Pending,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-3ds-1',
            redirectUrl: 'https://bank.test/3ds',
        ));

        Payline::via('fake')->reference('ORD-001')->amount(10000)->charge();

        FakeGateway::willReturn($callback ?? new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-3ds-1',
        ));

        return [];
    }
}
