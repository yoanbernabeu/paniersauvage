<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Notification\Enum\NotificationType;
use App\Notification\EventListener\OrderNotificationListener;
use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderStatus;
use App\Sale\Enum\SaleStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

/**
 * Day-before reminder: one e-mail per confirmed order delivered tomorrow (not to customers without
 * e-mail). Safe to run several times a day.
 */
final readonly class ReminderSender
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationSender $sender,
        private NotificationComposer $composer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int number of reminders sent by this run
     */
    public function sendForTomorrow(): int
    {
        $sent = 0;

        foreach ($this->findOrdersDeliveredTomorrow() as $order) {
            $customer = $order->getCustomer();
            $email = $customer->getEmail();
            if (null === $email) {
                continue;
            }

            $sent += (int) $this->sender->send(
                NotificationType::OrderReminder,
                new Address($email, $order->getCustomerDisplayName()),
                OrderNotificationListener::RELATED_TYPE,
                $order->getId(),
                fn (): TemplatedEmail => $this->composer->orderReminder($order),
                $customer,
                once: true,
            );
        }

        return $sent;
    }

    /**
     * @return list<Order>
     */
    private function findOrdersDeliveredTomorrow(): array
    {
        $tomorrow = $this->clock->now()->modify('tomorrow');

        $result = $this->entityManager->createQuery(<<<'DQL'
            SELECT o, s, d
            FROM App\Ordering\Entity\Order o
            JOIN o.sale s
            JOIN o.saleDelivery d
            WHERE o.status = :confirmed
              AND s.status IN (:saleStatuses)
              AND d.startsAt >= :from
              AND d.startsAt < :to
            ORDER BY o.id ASC
            DQL)
            ->setParameter('confirmed', OrderStatus::Confirmed)
            ->setParameter('saleStatuses', [SaleStatus::Open, SaleStatus::Closed])
            ->setParameter('from', $tomorrow)
            ->setParameter('to', $tomorrow->modify('+1 day'))
            ->getResult();

        $orders = [];
        foreach (is_iterable($result) ? $result : [] as $row) {
            if ($row instanceof Order) {
                $orders[] = $row;
            }
        }

        return $orders;
    }
}
