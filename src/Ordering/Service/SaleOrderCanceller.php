<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Ordering\Enum\OrderEventType;
use App\Ordering\Repository\OrderRepository;
use App\Sale\Entity\Sale;
use App\Shared\Enum\Actor;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Cancels the orders still active in a cancelled sale and gives their stock back.
 *
 * No OrderCancelled event is dispatched: the customers are warned once, by the sale notice
 * (SaleCancelled). Each order keeps a history entry by the system with the reason.
 */
final readonly class SaleOrderCanceller
{
    public const string REASON = 'sale_cancelled';

    public function __construct(
        private OrderRepository $orders,
        private ReservationService $reservations,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Runs in its own transaction, or joins the caller's one (savepoint), e.g. the sale cancellation.
     *
     * @return int number of orders cancelled
     */
    public function cancelActiveOrders(Sale $sale): int
    {
        if (!$sale->isCancelled()) {
            throw new \LogicException(\sprintf('Sale "%s" is not cancelled.', $sale->getCode()));
        }

        $now = $this->clock->now();
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $count = 0;
            foreach ($this->orders->findBySale($sale) as $order) {
                // Same lock as OrderManager: a concurrent edit cannot move the stock twice.
                $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
                if ($order->isCancelled()) {
                    continue;
                }

                $deltas = [];
                foreach ($order->getLines() as $line) {
                    $this->entityManager->refresh($line);
                    $offerId = $line->getSaleOffer()->getId() ?? throw new \LogicException('The sale offer must be persisted.');
                    $deltas[$offerId] = -$line->getQuantity();
                }
                $this->reservations->apply($deltas);

                $order->cancel();
                $order->touch($now);
                $order->recordEvent(OrderEventType::Cancelled, Actor::System, [
                    'total_cents' => $order->getTotalCents(),
                    'reason' => self::REASON,
                ], $now);
                ++$count;
            }

            $this->entityManager->flush();
            $connection->commit();

            return $count;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }
}
