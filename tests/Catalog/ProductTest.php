<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductKind;
use App\Catalog\Repository\ProductRepository;
use App\Shop\Entity\Shop;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\ShopFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ProductTest extends KernelTestCase
{
    use Factories;

    private function product(): Product
    {
        return new Product(new Shop('Fournil', 'fournil'), 'Brioche au beurre', 800);
    }

    public function testAPermanentProductIsAlwaysAvailable(): void
    {
        $product = $this->product();

        self::assertTrue($product->isPermanent());
        self::assertTrue($product->isAvailableOn(new \DateTimeImmutable('2026-01-01')));
        self::assertTrue($product->isAvailableOn(new \DateTimeImmutable('2030-07-14')));
    }

    public function testASeasonalProductIsAvailableWithinItsPeriodBoundsIncluded(): void
    {
        $product = $this->product()
            ->setKind(ProductKind::Seasonal)
            ->setAvailableFrom(new \DateTimeImmutable('2026-11-01'))
            ->setAvailableUntil(new \DateTimeImmutable('2026-12-24'));

        self::assertTrue($product->isSeasonal());
        self::assertFalse($product->isAvailableOn(new \DateTimeImmutable('2026-10-31')));
        self::assertTrue($product->isAvailableOn(new \DateTimeImmutable('2026-11-01')));
        self::assertTrue($product->isAvailableOn(new \DateTimeImmutable('2026-11-16 18:00')));
        self::assertTrue($product->isAvailableOn(new \DateTimeImmutable('2026-12-24 23:00')));
        self::assertFalse($product->isAvailableOn(new \DateTimeImmutable('2026-12-25')));
    }

    public function testASeasonalProductMayHaveOpenBounds(): void
    {
        $product = $this->product()->setKind(ProductKind::Seasonal);
        self::assertTrue($product->isAvailableOn(new \DateTimeImmutable('2026-11-16')));

        $product->setAvailableFrom(new \DateTimeImmutable('2026-12-01'));
        self::assertFalse($product->isAvailableOn(new \DateTimeImmutable('2026-11-16')));
        self::assertTrue($product->isAvailableOn(new \DateTimeImmutable('2027-06-01')));

        $product->setAvailableFrom(null)->setAvailableUntil(new \DateTimeImmutable('2026-11-10'));
        self::assertTrue($product->isAvailableOn(new \DateTimeImmutable('2026-01-01')));
        self::assertFalse($product->isAvailableOn(new \DateTimeImmutable('2026-11-16')));
    }

    public function testAnArchivedProductIsNeverAvailable(): void
    {
        self::assertFalse($this->product()->setArchived(true)->isAvailableOn(new \DateTimeImmutable('2026-11-16')));
    }

    public function testLabelsAreCleaned(): void
    {
        self::assertSame(['Bio', 'Levain naturel'], $this->product()->setLabels([' Bio ', '', 'Levain naturel', 'Bio'])->getLabels());
    }

    public function testRepositoryListsWhatASaleOffersByDefault(): void
    {
        $shop = ShopFactory::createOne();
        $deliveryDate = new \DateTimeImmutable('2026-11-16');

        ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne', 'position' => 1]);
        ProductFactory::new()->seasonal(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'))->create(['shop' => $shop, 'name' => 'Brioche', 'position' => 2]);
        ProductFactory::new()->seasonal(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-08-31'))->create(['shop' => $shop, 'name' => 'Fougasse d\'été']);
        ProductFactory::new()->archived()->create(['shop' => $shop, 'name' => 'Ancien pain']);
        ProductFactory::createOne(['name' => 'Pain d\'un autre producteur']);

        $names = array_map(
            static fn (Product $product): string => $product->getName(),
            self::getContainer()->get(ProductRepository::class)->findAvailableOn($shop, $deliveryDate),
        );

        self::assertSame(['Pain de campagne', 'Brioche'], $names);
    }
}
