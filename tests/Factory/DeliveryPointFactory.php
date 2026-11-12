<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Enum\DeliveryPointType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Default: a meeting point.
 *
 * @extends PersistentObjectFactory<DeliveryPoint>
 */
final class DeliveryPointFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return DeliveryPoint::class;
    }

    public function home(): static
    {
        return $this->with(['type' => DeliveryPointType::Home, 'name' => 'Livraison à domicile', 'address' => null]);
    }

    /**
     * A fixed point already geocoded (no call to the geocoder).
     */
    public function located(float $latitude, float $longitude): static
    {
        return $this->afterInstantiate(static function (DeliveryPoint $point) use ($latitude, $longitude): void {
            $point->setCoordinates($latitude, $longitude, new \DateTimeImmutable('2026-01-01 12:00'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'shop' => ShopFactory::new(),
            'type' => DeliveryPointType::MeetingPoint,
            'name' => 'Place '.self::faker()->unique()->lastName(),
            'address' => self::faker()->streetAddress(),
        ];
    }

    /**
     * Factories write properties directly (always_force_properties): the town is read from the
     * address, as DeliveryPoint::setAddress() does.
     */
    protected function initialize(): static
    {
        return $this->afterInstantiate(static function (DeliveryPoint $point): void {
            [$postcode, $city] = DeliveryPoint::townOf($point->getAddress());
            $point->setTown($city, $postcode);
        });
    }
}
