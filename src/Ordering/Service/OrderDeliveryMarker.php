<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Enum\OrderStatus;
use App\Ordering\Exception\DeliveryMarkNotAllowedException;
use App\Shared\Enum\Actor;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Delivered / not delivered / back to confirmed, ticked by the producer on the delivery sheet.
 * Nothing about payment is recorded (SPEC §1).
 *
 * Orders are handed over once the orders are closed: only the orders of a `closed` or `done` sale
 * can be ticked. The order row is locked while its status changes, so a concurrent cancellation
 * (customer, producer or sale cancellation) cannot be overwritten.
 */
final readonly class OrderDeliveryMarker
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DeliveryMarkNotAllowedException when the order is cancelled or its sale is not closed or done
     * @throws \DomainException                when the target status is not a delivery status
     */
    public function mark(Order $order, OrderStatus $status): void
    {
        $type = match ($status) {
            OrderStatus::Delivered => OrderEventType::Delivered,
            OrderStatus::NotDelivered => OrderEventType::NotDelivered,
            OrderStatus::Confirmed => OrderEventType::Reconfirmed,
            OrderStatus::Cancelled => throw new \DomainException('An order is not cancelled from the delivery sheet.'),
        };

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // SELECT … FOR UPDATE, then the latest status of the order and of its sale.
            $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
            $sale = $order->getSale();
            $this->entityManager->refresh($sale);

            if ($order->isCancelled() || $sale->isCancelled()) {
                throw new DeliveryMarkNotAllowedException('Cette commande est annulée : son statut ne peut plus changer.');
            }
            if (!$sale->isClosed() && !$sale->isDone()) {
                throw new DeliveryMarkNotAllowedException('Les commandes de cette vente ne sont pas encore closes : tu pourras cocher les livraisons ensuite.');
            }

            $previous = $order->getStatus();
            if ($previous !== $status) {
                match ($type) {
                    OrderEventType::Delivered => $order->markDelivered(),
                    OrderEventType::NotDelivered => $order->markNotDelivered(),
                    default => $order->reconfirm(),
                };

                $now = $this->clock->now();
                $order->touch($now);
                $order->recordEvent($type, Actor::Producer, ['from' => $previous->value, 'to' => $status->value], $now);
                $this->entityManager->flush();
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }
}
