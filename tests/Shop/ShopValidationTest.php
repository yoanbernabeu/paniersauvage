<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Shop\Entity\Shop;
use App\Tests\Factory\ShopFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;

final class ShopValidationTest extends KernelTestCase
{
    use Factories;

    public function testAValidShopPasses(): void
    {
        self::assertCount(0, $this->validate(new Shop('Fournil du Pont', 'fournil-du-pont')));
    }

    #[DataProvider('provideInvalidSlugs')]
    public function testInvalidSlugsAreRefused(string $slug): void
    {
        $violations = $this->validate(new Shop('Fournil du Pont', $slug));

        self::assertGreaterThan(0, \count($violations));
        self::assertSame('slug', $violations->get(0)->getPropertyPath());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidSlugs(): iterable
    {
        yield 'reserved: producer area' => ['pro'];
        yield 'reserved: customer login' => ['connexion'];
        yield 'reserved: account' => ['mon-compte'];
        yield 'reserved: webhooks' => ['webhooks'];
        yield 'reserved: producer landing page' => ['producteurs'];
        yield 'reserved: e-mail actions' => ['e-mails'];
        yield 'uppercase and spaces' => ['Fournil du Pont'];
        yield 'too short' => ['ab'];
    }

    public function testSlugMustBeUnique(): void
    {
        ShopFactory::createOne(['slug' => 'fournil-du-pont']);

        $violations = $this->validate(new Shop('Un autre fournil', 'fournil-du-pont'));

        self::assertCount(1, $violations);
        self::assertSame('Cette adresse de boutique est déjà prise.', $violations->get(0)->getMessage());
    }

    /**
     * @return ConstraintViolationListInterface
     */
    private function validate(Shop $shop): iterable
    {
        return self::getContainer()->get(ValidatorInterface::class)->validate($shop);
    }
}
