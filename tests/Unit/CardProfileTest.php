<?php

namespace XLaravel\Payline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Enums\CardCategory;
use XLaravel\Payline\Enums\CardScheme;
use XLaravel\Payline\Enums\CardType;

class CardProfileTest extends TestCase
{
    public function test_every_field_is_optional(): void
    {
        $profile = new CardProfile();

        $this->assertNull($profile->family);
        $this->assertNull($profile->type);
        $this->assertSame([], $profile->localSchemes);
        $this->assertSame([], $profile->raw);
    }

    public function test_the_issuing_country_is_answered_both_ways(): void
    {
        $profile = new CardProfile(issuerCountry: 'DE');

        $this->assertTrue($profile->issuedIn('DE'));
        $this->assertFalse($profile->issuedIn('TR'));
        $this->assertTrue($profile->issuedOutside('TR'));
        $this->assertFalse($profile->issuedOutside('DE'));
    }

    public function test_an_unknown_issuing_country_answers_no_to_both(): void
    {
        $profile = new CardProfile();

        $this->assertFalse($profile->issuedIn('TR'));
        $this->assertFalse($profile->issuedOutside('TR'));
    }

    public function test_a_card_carrying_a_local_scheme_is_co_badged(): void
    {
        $profile = new CardProfile(scheme: CardScheme::Visa, localSchemes: [CardScheme::Troy]);

        $this->assertTrue($profile->isCoBadged());
        $this->assertFalse((new CardProfile(scheme: CardScheme::Visa))->isCoBadged());
    }

    public function test_a_co_badged_card_supports_both_schemes(): void
    {
        $profile = new CardProfile(scheme: CardScheme::Visa, localSchemes: [CardScheme::Troy]);

        $this->assertTrue($profile->supports(CardScheme::Visa));
        $this->assertTrue($profile->supports(CardScheme::Troy));
        $this->assertFalse($profile->supports(CardScheme::Amex));
    }

    public function test_merging_fills_what_a_profile_leaves_open(): void
    {
        $merged = (new CardProfile(family: 'maximum', issuerCode: '64'))
            ->mergedWith(new CardProfile(issuerCountry: 'TR', scheme: CardScheme::Mastercard));

        $this->assertSame('maximum', $merged->family);
        $this->assertSame('64', $merged->issuerCode);
        $this->assertSame('TR', $merged->issuerCountry);
        $this->assertSame(CardScheme::Mastercard, $merged->scheme);
    }

    public function test_merging_carries_every_field_the_profile_has(): void
    {
        $full = new CardProfile(
            bin: '45717360',
            scheme: CardScheme::Visa,
            localSchemes: [CardScheme::Troy],
            type: CardType::Debit,
            category: CardCategory::Consumer,
            family: 'bonus',
            productId: 'F',
            productType: 'classic',
            issuer: 'Jyske Bank A/S',
            issuerCode: '64',
            issuerCountry: 'DK',
            currency: 'DKK',
            prepaid: true,
            numberLength: 16,
            source: 'test',
            raw: ['scheme' => 'visa'],
        );

        $merged = (new CardProfile())->mergedWith($full);

        foreach (get_object_vars($full) as $field => $value) {
            $this->assertSame($value, $merged->$field, "mergedWith() dropped {$field}");
        }
    }

    public function test_merging_keeps_what_a_profile_already_knows(): void
    {
        $merged = (new CardProfile(type: CardType::Credit, issuer: 'IS BANK'))
            ->mergedWith(new CardProfile(type: CardType::Debit, issuer: 'Turkiye Is Bankasi A.S.'));

        $this->assertSame(CardType::Credit, $merged->type);
        $this->assertSame('IS BANK', $merged->issuer);
    }

    public function test_merging_keeps_a_false_apart_from_an_unknown(): void
    {
        $merged = (new CardProfile(prepaid: false))->mergedWith(new CardProfile(prepaid: true));

        $this->assertFalse($merged->prepaid);
        $this->assertTrue((new CardProfile())->mergedWith(new CardProfile(prepaid: true))->prepaid);
    }

    public function test_merging_with_nothing_changes_nothing(): void
    {
        $profile = new CardProfile(family: 'bonus');

        $this->assertSame($profile, $profile->mergedWith(null));
    }

    public function test_merging_carries_both_raw_payloads(): void
    {
        $merged = (new CardProfile(source: 'hoppa', raw: ['Card_Family' => 'Maximum']))
            ->mergedWith(new CardProfile(source: 'handyapi', raw: ['Scheme' => 'MASTERCARD']));

        $this->assertSame('hoppa', $merged->source);
        $this->assertSame(['Card_Family' => 'Maximum', 'Scheme' => 'MASTERCARD'], $merged->raw);
    }

    public function test_scheme_names_are_parsed_the_way_each_provider_spells_them(): void
    {
        $this->assertSame(CardScheme::Mastercard, CardScheme::parse('MASTERCARD'));
        $this->assertSame(CardScheme::Mastercard, CardScheme::parse('MASTER_CARD'));
        $this->assertSame(CardScheme::Mastercard, CardScheme::parse('master card'));
        $this->assertSame(CardScheme::Amex, CardScheme::parse('AMERICAN_EXPRESS'));
        $this->assertSame(CardScheme::Troy, CardScheme::parse('troy'));
        $this->assertNull(CardScheme::parse('Visa/Dankort'));
        $this->assertNull(CardScheme::parse(null));
    }

    public function test_card_types_are_parsed_the_way_each_provider_spells_them(): void
    {
        $this->assertSame(CardType::Credit, CardType::parse('CREDIT'));
        $this->assertSame(CardType::Credit, CardType::parse('CREDIT_CARD'));
        $this->assertSame(CardType::Debit, CardType::parse('debit'));
        $this->assertSame(CardType::Prepaid, CardType::parse('PREPAID_CARD'));
        $this->assertNull(CardType::parse('unknown'));
        $this->assertNull(CardType::parse(null));
    }

    public function test_card_categories_are_parsed_the_way_each_provider_spells_them(): void
    {
        $this->assertSame(CardCategory::Consumer, CardCategory::parse('CONSUMER'));
        $this->assertSame(CardCategory::Commercial, CardCategory::parse('COMMERCIAL'));
        $this->assertSame(CardCategory::Commercial, CardCategory::parse('business'));
        $this->assertNull(CardCategory::parse(null));
    }
}
