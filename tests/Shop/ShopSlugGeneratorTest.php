<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Shop\Entity\Shop;
use App\Shop\Service\ShopSlugGenerator;
use App\Tests\Factory\ShopFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;

final class ShopSlugGeneratorTest extends KernelTestCase
{
    use Factories;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'plain name' => ['Le Fournil du Pont', 'le-fournil-du-pont'];
        yield 'accents and apostrophes' => ['L\'Épi d\'Or — Boulangerie', 'l-epi-d-or-boulangerie'];
        yield 'surrounding noise' => ['  ~ Ferme   des Prés !! ', 'ferme-des-pres'];
        yield 'reserved: pro' => ['Pro', 'chez-pro'];
        yield 'reserved: mon compte' => ['Mon compte', 'chez-mon-compte'];
        yield 'reserved: admin, any case' => ['ADMIN', 'chez-admin'];
        yield 'too short' => ['Ô', 'chez-o'];
        yield 'nothing usable' => ['!!!', 'producteur'];
    }

    #[DataProvider('names')]
    public function testGeneratesAReadableSlug(string $name, string $expected): void
    {
        self::assertSame($expected, $this->generator()->generate($name));
    }

    public function testEveryReservedWordIsAvoided(): void
    {
        $generator = $this->generator();

        foreach (Shop::RESERVED_SLUGS as $reserved) {
            $slug = $generator->generate($reserved);

            self::assertFalse(Shop::isReservedSlug($slug), \sprintf('"%s" gave the reserved slug "%s".', $reserved, $slug));
            self::assertCount(0, $this->validateSlug($slug), \sprintf('"%s" gave the invalid slug "%s".', $reserved, $slug));
        }
    }

    public function testAppendsASuffixWhenTheSlugIsTaken(): void
    {
        ShopFactory::createOne(['slug' => 'ferme-des-pres']);
        self::assertSame('ferme-des-pres-2', $this->generator()->generate('Ferme des Prés'));

        ShopFactory::createOne(['slug' => 'ferme-des-pres-2']);
        self::assertSame('ferme-des-pres-3', $this->generator()->generate('Ferme des Prés'));

        ShopFactory::createOne(['slug' => 'chez-pro']);
        self::assertSame('chez-pro-2', $this->generator()->generate('Pro'));
    }

    public function testVeryLongNamesStayWithinTheColumnAndValid(): void
    {
        $slug = $this->generator()->generate(str_repeat('Boulangerie artisanale ', 12));

        self::assertLessThanOrEqual(60, \strlen($slug));
        self::assertCount(0, $this->validateSlug($slug));
    }

    private function generator(): ShopSlugGenerator
    {
        return self::getContainer()->get(ShopSlugGenerator::class);
    }

    /**
     * @return \Countable&\Traversable<mixed>
     */
    private function validateSlug(string $slug): \Traversable
    {
        return self::getContainer()->get(ValidatorInterface::class)->validate(new Shop('Boutique', $slug));
    }
}
