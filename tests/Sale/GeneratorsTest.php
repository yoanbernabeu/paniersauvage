<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Ordering\Service\OrderReferenceGenerator;
use App\Sale\Service\SaleCodeGenerator;
use App\Shared\Service\RandomCode;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\SaleFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class GeneratorsTest extends KernelTestCase
{
    use Factories;

    public function testSaleCodesAreShortUnambiguousAndUnique(): void
    {
        $existing = SaleFactory::createOne()->getCode();
        $generator = self::getContainer()->get(SaleCodeGenerator::class);

        $codes = [];
        for ($i = 0; $i < 200; ++$i) {
            $codes[] = $code = $generator->generate();
            self::assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{6}$/', $code, 'no 0/O, no 1/I/L');
            self::assertNotSame($existing, $code);
        }

        self::assertGreaterThan(195, \count(array_unique($codes)));
    }

    public function testOrderReferencesAreReadableAndUnique(): void
    {
        $existing = OrderFactory::createOne()->getReference();
        $generator = self::getContainer()->get(OrderReferenceGenerator::class);

        self::assertMatchesRegularExpression(OrderReferenceGenerator::PATTERN, $existing);

        for ($i = 0; $i < 50; ++$i) {
            $reference = $generator->generate();
            self::assertMatchesRegularExpression(OrderReferenceGenerator::PATTERN, $reference);
            self::assertNotSame($existing, $reference);
        }
    }

    public function testRandomCodeAlphabetHasNoAmbiguousCharacter(): void
    {
        foreach (['0', 'O', '1', 'I', 'L'] as $ambiguous) {
            self::assertStringNotContainsString($ambiguous, RandomCode::ALPHABET);
        }

        self::assertSame(12, \strlen(RandomCode::generate(12)));
    }
}
