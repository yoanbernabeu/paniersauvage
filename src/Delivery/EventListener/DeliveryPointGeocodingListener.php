<?php

declare(strict_types=1);

namespace App\Delivery\EventListener;

use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Geocoding\DeliveryPointLocator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Geocodes a pick-up point when it is saved with a new address (or a new type), whatever saves it.
 *
 * `preFlush` runs before Doctrine computes the change set and before the transaction starts: the
 * coordinates set here are saved by the same flush, and no database transaction waits for the API.
 */
#[AsEntityListener(event: Events::preFlush, method: 'preFlush', entity: DeliveryPoint::class)]
final readonly class DeliveryPointGeocodingListener
{
    public function __construct(
        private DeliveryPointLocator $locator,
    ) {
    }

    public function preFlush(DeliveryPoint $point): void
    {
        if ($point->isGeocodingPending()) {
            $this->locator->locate($point);
        }
    }
}
