<?php

namespace XLaravel\Payline\Tests\Feature\Routing;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use XLaravel\Payline\BinLookupManager;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Enums\CardScheme;
use XLaravel\Payline\Enums\CardType;
use XLaravel\Payline\Tests\Fixtures\BinLookup\StubBinLookup;
use XLaravel\Payline\Tests\TestCase;

class BinLookupManagerTest extends TestCase
{
    private function manager(): BinLookupManager
    {
        return $this->app->make('payline.bin_lookup');
    }

    private function register(string $name, callable $answer): StubBinLookup
    {
        $stub = new StubBinLookup($answer(...));

        $this->manager()->extend($name, fn () => $stub);

        return $stub;
    }

    private function use(string ...$providers): void
    {
        config(['payline.bin_lookup.providers' => $providers]);
    }

    public function test_nothing_is_resolved_without_a_configured_provider(): void
    {
        $this->assertNull($this->manager()->lookup('5101520012345678'));
    }

    public function test_a_single_provider_answers_on_its_own(): void
    {
        $this->register('one', fn () => new CardProfile(family: 'maximum', type: CardType::Credit));
        $this->use('one');

        $profile = $this->manager()->lookup('5101520012345678');

        $this->assertSame('maximum', $profile->family);
        $this->assertSame(CardType::Credit, $profile->type);
    }

    public function test_providers_are_asked_for_the_first_eight_digits(): void
    {
        $stub = $this->register('one', fn () => null);
        $this->use('one');

        $this->manager()->lookup('5101520012345678');

        $this->assertSame(['51015200'], $stub->asked);
    }

    public function test_what_one_provider_leaves_open_the_next_one_fills(): void
    {
        $this->register('family', fn () => new CardProfile(family: 'maximum', issuerCode: '64'));
        $this->register('country', fn () => new CardProfile(issuerCountry: 'TR', scheme: CardScheme::Mastercard));
        $this->use('family', 'country');

        $profile = $this->manager()->lookup('5101520012345678');

        $this->assertSame('maximum', $profile->family);
        $this->assertSame('64', $profile->issuerCode);
        $this->assertSame('TR', $profile->issuerCountry);
        $this->assertSame(CardScheme::Mastercard, $profile->scheme);
    }

    public function test_the_earlier_provider_owns_a_field_both_of_them_fill(): void
    {
        $this->register('first', fn () => new CardProfile(type: CardType::Credit, issuer: 'IS BANK'));
        $this->register('second', fn () => new CardProfile(type: CardType::Debit, issuer: 'Turkiye Is Bankasi A.S.'));
        $this->use('first', 'second');

        $profile = $this->manager()->lookup('5101520012345678');

        $this->assertSame(CardType::Credit, $profile->type);
        $this->assertSame('IS BANK', $profile->issuer);
    }

    public function test_a_provider_that_knows_nothing_is_stepped_over(): void
    {
        $this->register('silent', fn () => null);
        $this->register('knows', fn () => new CardProfile(issuerCountry: 'DK'));
        $this->use('silent', 'knows');

        $this->assertSame('DK', $this->manager()->lookup('4571736012345678')->issuerCountry);
    }

    public function test_an_unreachable_provider_does_not_take_the_others_down(): void
    {
        $this->register('down', fn () => throw new ConnectionException('Connection timed out'));
        $this->register('up', fn () => new CardProfile(family: 'bonus'));
        $this->use('down', 'up');

        $this->assertSame('bonus', $this->manager()->lookup('5101520012345678')->family);
    }

    public function test_every_provider_being_unreachable_resolves_nothing(): void
    {
        $this->register('down', fn () => throw new ConnectionException('Connection timed out'));
        $this->use('down');

        $this->assertNull($this->manager()->lookup('5101520012345678'));
    }

    public function test_a_failure_that_is_not_a_connection_problem_is_not_swallowed(): void
    {
        $this->register('broken', fn () => throw new RuntimeException('Provider bug.'));
        $this->use('broken');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Provider bug.');

        $this->manager()->lookup('5101520012345678');
    }

    public function test_the_first_provider_is_the_one_driver_resolves(): void
    {
        $this->register('one', fn () => null);
        $this->register('two', fn () => null);
        $this->use('one', 'two');

        $this->assertSame('one', $this->manager()->getDefaultDriver());
    }

    public function test_the_null_provider_stands_in_for_an_empty_list(): void
    {
        $this->assertSame('null', $this->manager()->getDefaultDriver());
        $this->assertNull($this->manager()->driver()->lookup('51015200'));
    }
}
