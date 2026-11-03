<?php

namespace Tests\Unit\Cms;

use App\Services\Cms\TranslationGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TranslationGuardTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function unsafeTranslations(): array
    {
        return [
            'changed placeholder' => ['Je krijgt {amount}.', 'You receive {count}.', 'en'],
            'omitted amount' => ['Spaar 10%.', 'Save money.', 'en'],
            'duplicate amount' => ['Spaar 10%.', 'Save 10% plus 10%.', 'en'],
            'changed price' => ['Je krijgt €12,50.', 'You receive €50.', 'en'],
            'removed negation' => ['Dit is niet overdraagbaar.', 'This is transferable.', 'en'],
            'new negation' => ['Dit is geldig.', 'This is not valid.', 'en'],
            'cash payout' => ['Gebruik je tegoed.', 'Withdraw your credit.', 'en'],
            'german payout' => ['Gebruik je tegoed.', 'Lass dein Guthaben auszahlen.', 'de'],
            'brand omitted' => ['Welkom bij ShopBonus.', 'Welcome.', 'en'],
            'xss' => ['Welkom.', '<script>alert(1)</script>', 'en'],
            'changed url' => ['Ga naar https://shopbonus.nl.', 'Visit https://evil.example.', 'en'],
            'untranslated' => ['Dit is een lange Nederlandstalige zin die niet vertaald werd.', 'Dit is een lange Nederlandstalige zin die niet vertaald werd.', 'en'],
        ];
    }

    #[DataProvider('unsafeTranslations')]
    public function test_unsafe_translation_is_rejected(string $source, string $target, string $locale): void
    {
        config(['cms.glossary' => ['ShopBonus']]);
        $this->expectException(RuntimeException::class);
        TranslationGuard::assertSafe($source, $target, 'nl', $locale);
    }

    public function test_valid_non_cash_tokens_and_negation_survive_in_english_and_german(): void
    {
        TranslationGuard::assertSafe('ShopBonus: {amount} is niet overdraagbaar.', 'ShopBonus: {amount} is not transferable.', 'nl', 'en');
        TranslationGuard::assertSafe('ShopBonus: {amount} is niet overdraagbaar.', 'ShopBonus: {amount} ist nicht übertragbar.', 'nl', 'de');
        $this->addToAssertionCount(2);
    }
}
