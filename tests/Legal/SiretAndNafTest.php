<?php

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Legal\Verification\NafClassifier;
use App\Legal\Verification\Siret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiretAndNafTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function sirets(): iterable
    {
        yield 'valid (DINUM)' => ['13002526500013', true];
        yield 'valid (demo baker)' => ['12345678900015', true];
        yield 'wrong check digit' => ['13002526500014', false];
        yield 'transposed digits' => ['31002526500013', false];
        yield 'all zeros' => ['00000000000000', true];
        yield 'too short' => ['1300252650001', false];
        yield 'too long' => ['130025265000130', false];
        yield 'letters' => ['13002526500O13', false];
        yield 'spaces' => ['130 025 265 00013', false];
        yield 'null' => [null, false];
        yield 'La Poste, digit sum multiple of 5 (Luhn fails)' => ['35600000000001', true];
        yield 'La Poste, digit sum not multiple of 5 (Luhn passes)' => ['35600000000014', false];
        yield 'La Poste prefix only on the SIREN' => ['13560000000001', false];
        yield 'leading letter before a valid number' => ['x13002526500013', false];
        yield '15 digits with a valid checksum' => ['130025265000133', false];
    }

    #[DataProvider('sirets')]
    public function testSiretChecksum(?string $siret, bool $valid): void
    {
        self::assertSame($valid, Siret::isValid($siret));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function nafCodes(): iterable
    {
        yield 'market gardening' => ['01.13Z', true];
        yield 'vine growing' => ['01.21Z', true];
        yield 'forestry' => ['02.10Z', true];
        yield 'fish farming' => ['03.22Z', true];
        yield 'baker' => ['10.71C', true];
        yield 'cheese maker' => ['10.51C', true];
        yield 'winemaker' => ['11.02B', true];
        yield 'brewer' => ['11.05Z', true];
        yield 'lowercase and spaces' => [' 10.71c ', true];
        yield 'fruit and vegetables shop' => ['47.21Z', true];
        yield 'bread shop' => ['47.24Z', true];
        yield 'other food shop' => ['47.29Z', true];
        yield 'market stall' => ['47.81Z', true];
        yield 'supermarket' => ['47.11F', false];
        yield 'clothes on markets' => ['47.82Z', false];
        yield 'tobacco shop' => ['47.26Z', false];
        yield 'mining' => ['05.10Z', false];
        yield 'textile' => ['13.10Z', false];
        yield 'restaurant' => ['56.10A', false];
        yield 'software' => ['62.01Z', false];
        yield 'malformed' => ['1071C', false];
        yield 'extra leading digit' => ['110.71C', false];
        yield 'extra trailing letter' => ['10.71CZ', false];
        yield 'empty' => ['', false];
        yield 'null' => [null, false];
    }

    #[DataProvider('nafCodes')]
    public function testNafClassification(?string $code, bool $food): void
    {
        self::assertSame($food, NafClassifier::isFoodOrFarming($code));
    }
}
