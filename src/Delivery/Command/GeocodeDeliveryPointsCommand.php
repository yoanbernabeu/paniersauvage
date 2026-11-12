<?php

declare(strict_types=1);

namespace App\Delivery\Command;

use App\Delivery\Geocoding\DeliveryPointLocator;
use App\Delivery\Geocoding\GeocodingOutcome;
use App\Delivery\Repository\DeliveryPointRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Catch-up of the coordinates of pick-up points: points created before geocoding existed, or
 * saved while the API Adresse was unavailable. Safe to run again at any time.
 */
#[AsCommand(name: 'app:delivery-points:geocode', description: 'Looks up the coordinates of the pick-up points that have none yet (API Adresse).')]
final readonly class GeocodeDeliveryPointsCommand
{
    private const int BATCH_SIZE = 50;

    public function __construct(
        private DeliveryPointRepository $deliveryPoints,
        private DeliveryPointLocator $locator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Geocode every fixed point again, even those that already have coordinates.')]
        bool $all = false,
    ): int {
        $counts = array_fill_keys(array_map(static fn (GeocodingOutcome $outcome): string => $outcome->value, GeocodingOutcome::cases()), 0);

        foreach ($this->deliveryPoints->findToGeocode($all) as $index => $point) {
            ++$counts[$this->locator->locate($point)->value];

            if (0 === ($index + 1) % self::BATCH_SIZE) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        $io->success(\sprintf(
            '%d located, %d not found, %d skipped because the API was unavailable.',
            $counts[GeocodingOutcome::Located->value],
            $counts[GeocodingOutcome::NotFound->value],
            $counts[GeocodingOutcome::Unavailable->value],
        ));

        return $counts[GeocodingOutcome::Unavailable->value] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
