<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Exception\OrderNotAllowedException;
use App\Shared\Enum\Actor;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Records the actual weight of the lines sold by weight, entered by the producer on the delivery
 * sheet or in the order. The lines are then billed on it: the amount to settle, the exports and the
 * accounting follow. Nothing about payment is recorded (SPEC §1), and no e-mail is sent: the
 * customer sees the exact amount when collecting the order.
 *
 * The order row is locked while the weights change; every change is written in the order's
 * history (`weighed`, with the ordered and actual weights and both totals).
 */
final readonly class OrderWeighing
{
    /** Heaviest weight of one line: 999 kg, in thousandths. */
    public const int MAX_WEIGHT = 999_000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<int, int|null> $weights order line id => actual weight in thousandths of a
     *                                      kilogram (null = not weighed yet); other lines are left as they are
     *
     * @return bool true when at least one weight changed
     *
     * @throws OrderNotAllowedException when the order or its sale is cancelled, a line is not part
     *                                  of the order or not sold by weight, or a weight is out of range
     */
    public function record(Order $order, array $weights, Actor $actor = Actor::Producer): bool
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
            foreach ($order->getLines() as $line) {
                $this->entityManager->refresh($line);
            }

            if ($order->isCancelled() || $order->getSale()->isCancelled()) {
                throw new OrderNotAllowedException('Cette commande est annulée : son poids ne peut plus être saisi.');
            }

            $previousTotal = $order->getTotalCents();
            $changes = [];
            foreach ($weights as $lineId => $weight) {
                $line = $order->getLines()->findFirst(static fn (int $key, OrderLine $line): bool => $line->getId() === $lineId)
                    ?? throw new OrderNotAllowedException('Ce produit ne fait pas partie de la commande.');
                if (!$line->isWeighed()) {
                    throw new OrderNotAllowedException(\sprintf('« %s » ne se vend pas au poids.', $line->getLabel()));
                }
                if (null !== $weight && ($weight <= 0 || $weight > self::MAX_WEIGHT)) {
                    throw new OrderNotAllowedException(\sprintf('Indique un poids entre 0,001 et %d kg pour « %s ».', intdiv(self::MAX_WEIGHT, 1000), $line->getLabel()));
                }
                if ($weight === $line->getActualQuantity()) {
                    continue;
                }

                $changes[] = ['label' => $line->getLabel(), 'ordered' => $line->getQuantity(), 'from' => $line->getActualQuantity(), 'to' => $weight];
                $line->setActualQuantity($weight);
            }

            if ([] !== $changes) {
                $now = $this->clock->now();
                $order->touch($now);
                $order->recordEvent(OrderEventType::Weighed, $actor, [
                    'previous_total_cents' => $previousTotal,
                    'total_cents' => $order->recomputeTotal(),
                    'changes' => $changes,
                ], $now);
                $this->entityManager->flush();
            }

            $connection->commit();

            return [] !== $changes;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }
}
