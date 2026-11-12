<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Shop\Entity\Shop;
use App\Shop\Enum\ShopCategory;
use App\Tests\Legal\Support\FakeCompanyRegistry;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Shop>
 */
final class ShopFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Shop::class;
    }

    /**
     * A shop whose SIRET the company register knows as an active farm (FakeCompanyRegistry): it is
     * verified when saved, like a real one, and may appear in the producer directory.
     */
    public function verified(string $nafCode = '01.13Z'): static
    {
        return $this->beforeInstantiate(static function (array $attributes) use ($nafCode): array {
            $siret = \is_string($attributes['siret'] ?? null) ? $attributes['siret'] : self::randomSiret();
            FakeCompanyRegistry::knows($siret, naf: $nafCode);

            return ['siret' => $siret] + $attributes;
        });
    }

    /**
     * 14 digits with a valid Luhn checksum.
     */
    public static function randomSiret(): string
    {
        $digits = self::faker()->unique()->numerify('#############');
        for ($check = 0; $check <= 9; ++$check) {
            $sum = 0;
            foreach (str_split(strrev($digits.$check)) as $position => $digit) {
                $value = (int) $digit * (1 === $position % 2 ? 2 : 1);
                $sum += $value > 9 ? $value - 9 : $value;
            }

            if (0 === $sum % 10) {
                return $digits.$check;
            }
        }

        throw new \LogicException('One check digit always fits.');
    }

    /**
     * Opted in to the producer directory, with a category (still needs a subscription and a located point).
     *
     * @param list<ShopCategory> $categories
     */
    public function listed(array $categories = [ShopCategory::Bread]): static
    {
        return $this->with(['listedInSearch' => true, 'categories' => $categories]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $name = self::faker()->unique()->company();

        return [
            'name' => $name,
            'slug' => strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-')).'-'.self::faker()->unique()->numberBetween(1, 999999),
            'paymentInfo' => 'Espèces ou chèque à la livraison',
        ];
    }

    /**
     * Factories write properties directly (always_force_properties): the enum lists are stored as
     * their values, as the setters do.
     */
    protected function initialize(): static
    {
        return $this->beforeInstantiate(static function (array $attributes): array {
            foreach (['categories', 'paymentMethods'] as $field) {
                if (\is_array($attributes[$field] ?? null)) {
                    $attributes[$field] = array_map(static fn (mixed $case): mixed => $case instanceof \BackedEnum ? $case->value : $case, $attributes[$field]);
                }
            }

            return $attributes;
        });
    }
}
