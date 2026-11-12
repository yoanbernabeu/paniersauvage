<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Ordering\Exception\InsufficientStockException;
use App\Ordering\Exception\StockShortage;
use App\Sale\Entity\SaleOffer;
use App\Sale\Repository\SaleOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * The only door to `SaleOffer::reservedQty` (SPEC §1.2: no overselling).
 *
 * Every change runs in a transaction and locks the offer rows with `SELECT … FOR UPDATE`, so two
 * customers ordering the last loaf at the same time are served one after the other: the second
 * one sees the counter left by the first. Rows are locked in ascending id order to avoid deadlocks.
 *
 * When called inside a wider transaction (see OrderManager), the locks are held until that
 * transaction ends, which keeps the order and its stock consistent.
 *
 * Public so that separate processes (see ReservationConcurrencyTest) can fetch it from the container.
 */
#[Autoconfigure(public: true)]
final readonly class ReservationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SaleOfferRepository $offers,
    ) {
    }

    /**
     * @param int $quantity thousandths of the product's unit (App\Shared\Service\Quantity)
     *
     * @throws InsufficientStockException when less than $quantity is left
     */
    public function reserve(SaleOffer $offer, int $quantity): void
    {
        if ($quantity < 0) {
            throw new \InvalidArgumentException('Cannot reserve a negative quantity.');
        }

        $this->apply([$this->idOf($offer) => $quantity]);
    }

    public function release(SaleOffer $offer, int $quantity): void
    {
        if ($quantity < 0) {
            throw new \InvalidArgumentException('Cannot release a negative quantity.');
        }

        $this->apply([$this->idOf($offer) => -$quantity]);
    }

    /**
     * Applies several stock changes at once, all or nothing.
     *
     * @param array<int, int> $deltas sale offer id => thousandths of the unit to reserve (positive) or release (negative)
     *
     * @throws InsufficientStockException when at least one reservation exceeds what is left;
     *                                    nothing is reserved nor released in that case
     */
    public function apply(array $deltas): void
    {
        $deltas = array_filter($deltas, static fn (int $delta): bool => 0 !== $delta);
        if ([] === $deltas) {
            return;
        }

        ksort($deltas);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $locked = [];
            $shortages = [];

            foreach ($deltas as $offerId => $delta) {
                // Locks the row and refreshes the counters from the database.
                $offer = $this->offers->findForUpdate($offerId)
                    ?? throw new \InvalidArgumentException(\sprintf('Sale offer #%d does not exist.', $offerId));
                $locked[$offerId] = $offer;

                if ($delta > 0 && !$offer->canReserve($delta)) {
                    $product = $offer->getProduct();
                    $shortages[] = new StockShortage($offerId, $product->getName(), $delta, $offer->remaining() ?? 0, $product->getUnit(), $product->getQuantityStep(), $product->getMinQuantity());
                }
            }

            if ([] !== $shortages) {
                throw new InsufficientStockException($shortages);
            }

            foreach ($deltas as $offerId => $delta) {
                if ($delta > 0) {
                    $locked[$offerId]->reserve($delta);
                } else {
                    $locked[$offerId]->release(-$delta);
                }
            }

            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    private function idOf(SaleOffer $offer): int
    {
        return $offer->getId() ?? throw new \InvalidArgumentException('The sale offer must be persisted before reserving stock.');
    }
}
