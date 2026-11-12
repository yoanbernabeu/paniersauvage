<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductKind;
use App\Catalog\Enum\ProductUnit;
use App\Shared\Service\Quantity;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Default: a permanent product sold by the piece, unlimited, 5.5 % VAT. Quantities
 * (`maxQtyDefault`, `quantityStep`, `minQuantity`) are in thousandths of the unit.
 *
 * @extends PersistentObjectFactory<Product>
 */
final class ProductFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Product::class;
    }

    public function seasonal(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $until = null): static
    {
        return $this->with(['kind' => ProductKind::Seasonal, 'availableFrom' => $from, 'availableUntil' => $until]);
    }

    /**
     * @param int $units whole pieces or bunches (use `maxQtyDefault` directly for a weight)
     */
    public function limitedTo(int $units): static
    {
        return $this->with(['maxQtyDefault' => Quantity::units($units)]);
    }

    /**
     * Sold by the kilogram, by steps of `$step` thousandths (250 = 250 g), from `$minimum`.
     */
    public function byWeight(int $step = 250, ?int $minimum = null): static
    {
        return $this->with(['unit' => ProductUnit::Kg, 'quantityStep' => $step, 'minQuantity' => $minimum ?? $step]);
    }

    public function archived(): static
    {
        return $this->with(['archived' => true]);
    }

    /** A drink with alcohol (wine, beer): age certification on order. */
    public function alcoholic(): static
    {
        return $this->with(['alcoholic' => true, 'perishable' => false]);
    }

    /** Keeps (honey, oil, preserves): 14-day right of withdrawal. */
    public function notPerishable(): static
    {
        return $this->with(['perishable' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'shop' => ShopFactory::new(),
            'name' => 'Pain '.self::faker()->unique()->word(),
            'priceCents' => self::faker()->numberBetween(100, 900),
            'vatRateBp' => Product::DEFAULT_VAT_RATE_BP,
            'unit' => ProductUnit::Piece,
            'kind' => ProductKind::Permanent,
        ];
    }
}
