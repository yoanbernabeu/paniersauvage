<?php

declare(strict_types=1);

namespace App\Sale\Service;

use App\Sale\Entity\Sale;
use App\Sale\Event\SaleCancelled;
use App\Sale\Event\SaleCancelling;
use App\Sale\Event\SaleClosed;
use App\Sale\Event\SaleOpened;
use App\Sale\Repository\SaleRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Automatic status changes of the sales (SPEC §4): scheduled → open → closed → done, or cancelled.
 * Domain events are dispatched once the new status is saved.
 */
final readonly class SaleLifecycle
{
    public function __construct(
        private SaleRepository $sales,
        private SubscriptionGate $subscriptionGate,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Opens the scheduled sales whose opening time has come. A sale stays scheduled while the
     * subscription of its shop does not allow new sales.
     *
     * @return int number of sales opened
     */
    public function openDueSales(): int
    {
        $count = 0;
        foreach ($this->sales->findDueToOpen($this->clock->now()) as $sale) {
            if ($this->openIfDue($sale)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Opens this sale right away when it is due (used after a creation, without waiting for the next tick).
     */
    public function openIfDue(Sale $sale): bool
    {
        if (!$sale->isDueToOpen($this->clock->now()) || !$this->subscriptionGate->allowsNewSales($sale->getShop())) {
            return false;
        }

        $sale->open();
        $this->entityManager->flush();
        $this->eventDispatcher->dispatch(new SaleOpened($sale));

        return true;
    }

    /**
     * Closes the open sales whose deadline has passed.
     *
     * @return int number of sales closed
     */
    public function closeDueSales(): int
    {
        $now = $this->clock->now();
        $count = 0;
        foreach ($this->sales->findDueToClose($now) as $sale) {
            if (!$sale->isDueToClose($now)) {
                continue;
            }

            $sale->close();
            $this->entityManager->flush();
            $this->eventDispatcher->dispatch(new SaleClosed($sale));
            ++$count;
        }

        return $count;
    }

    /**
     * Marks as done the closed sales delivered before today.
     *
     * @return int number of sales finished
     */
    public function finishDueSales(): int
    {
        $now = $this->clock->now();
        $count = 0;
        foreach ($this->sales->findDueToFinish($now) as $sale) {
            if ($sale->isDueToFinish($now)) {
                $sale->markDone();
                ++$count;
            }
        }

        if ($count > 0) {
            $this->entityManager->flush();
        }

        return $count;
    }

    /**
     * Cancels the sales that never opened (inactive subscription) and whose deadline has passed,
     * so they do not stay "scheduled" forever.
     *
     * @return int number of sales cancelled
     */
    public function cancelMissedSales(): int
    {
        $count = 0;
        foreach ($this->sales->findMissed($this->clock->now()) as $sale) {
            $this->cancel($sale);
            ++$count;
        }

        return $count;
    }

    /**
     * Cancels the sale and, in the same transaction, everything that depends on it (SaleCancelling:
     * the orders are cancelled and their stock released). SaleCancelled, which warns the customers,
     * is dispatched only once all of it is committed.
     *
     * @throws \DomainException when the sale is already done or cancelled
     */
    public function cancel(Sale $sale): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // SELECT … FOR UPDATE: two cancellations (or a cancellation and the scheduler) do not overlap.
            if (null !== $sale->getId()) {
                $this->entityManager->refresh($sale, LockMode::PESSIMISTIC_WRITE);
            }

            $sale->cancel();
            $this->entityManager->flush();
            $this->eventDispatcher->dispatch(new SaleCancelling($sale));
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            if ($this->entityManager->isOpen() && null !== $sale->getId()) {
                $this->entityManager->refresh($sale);
            }

            throw $exception;
        }

        $this->eventDispatcher->dispatch(new SaleCancelled($sale));
    }
}
