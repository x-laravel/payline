<?php

namespace XLaravel\Payline\Tests\Feature\Payment;

use XLaravel\Payline\DTOs\Card;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\Enums\CardScheme;
use XLaravel\Payline\Enums\CardType;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Tests\TestCase;

class CardProfileStorageTest extends TestCase
{
    private function profile(): CardProfile
    {
        return new CardProfile(
            bin: '51015200',
            scheme: CardScheme::Mastercard,
            type: CardType::Credit,
            family: 'maximum',
            issuer: 'IS BANK',
            issuerCode: '64',
            issuerCountry: 'TR',
            source: 'hoppa',
        );
    }

    private function charge(string $reference, ?CardProfile $profile): Payment
    {
        Payline::via('fake')->charge(new PaymentRequest(
            reference: $reference,
            amount: 10000,
            currency: 'TRY',
            cardProfile: $profile,
        ));

        return Payment::where('reference', $reference)->sole();
    }

    public function test_the_profile_behind_the_card_is_stored_with_the_payment(): void
    {
        $payment = $this->charge('ORD-P1', $this->profile());

        $this->assertSame('maximum', $payment->card_family);
        $this->assertSame(CardType::Credit, $payment->card_type);
        $this->assertSame(CardScheme::Mastercard, $payment->card_scheme);
        $this->assertSame('IS BANK', $payment->card_issuer);
        $this->assertSame('TR', $payment->card_issuer_country);
    }

    public function test_a_payment_without_a_profile_leaves_the_columns_open(): void
    {
        $payment = $this->charge('ORD-P2', null);

        $this->assertNull($payment->card_family);
        $this->assertNull($payment->card_type);
        $this->assertNull($payment->card_scheme);
        $this->assertNull($payment->card_issuer);
        $this->assertNull($payment->card_issuer_country);
    }

    public function test_storage_can_be_turned_off(): void
    {
        config(['payline.storage.card_profile' => false]);

        $payment = $this->charge('ORD-P3', $this->profile());

        $this->assertNull($payment->card_family);
        $this->assertNull($payment->card_issuer_country);
    }

    public function test_the_columns_payline_does_not_keep_are_left_out(): void
    {
        $payment = $this->charge('ORD-P4', $this->profile());

        $this->assertFalse($payment->getConnection()->getSchemaBuilder()->hasColumn('payline_payments', 'card_issuer_code'));
        $this->assertFalse($payment->getConnection()->getSchemaBuilder()->hasColumn('payline_payments', 'card_category'));
    }

    public function test_a_profile_resolved_onto_the_card_wins_over_one_given_on_the_request(): void
    {
        $card = (new Card(
            holderName: 'John Doe',
            number: '5101520012345678',
            expiryMonth: '12',
            expiryYear: '2030',
            cvv: '123',
        ))->withProfile(new CardProfile(family: 'bonus'));

        $data = new PaymentRequest(
            reference: 'ORD-P5',
            amount: 10000,
            currency: 'TRY',
            card: $card,
            cardProfile: $this->profile(),
        );

        $this->assertSame('bonus', $data->profile()->family);

        Payline::via('fake')->charge($data);

        $this->assertSame('bonus', Payment::where('reference', 'ORD-P5')->sole()->card_family);
    }

    public function test_a_request_profile_is_used_when_the_card_carries_none(): void
    {
        $data = new PaymentRequest(
            reference: 'ORD-P6',
            amount: 10000,
            currency: 'TRY',
            cardProfile: $this->profile(),
        );

        $this->assertSame('maximum', $data->profile()->family);
    }
}
