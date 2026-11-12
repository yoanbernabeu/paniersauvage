<?php

declare(strict_types=1);

namespace App\Customer\Service;

use App\Customer\Entity\Customer;
use App\Customer\Event\CustomerErased;
use App\Customer\Exception\PendingDeliveryException;
use App\Customer\Repository\ShopFollowerRepository;
use App\Ordering\Entity\Order;
use App\Ordering\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Erases a customer account (GDPR): deleted when it has no order, anonymised otherwise, because
 * the producers' order history must stay consistent. Refused while a delivery is still pending.
 * The same goes for a customer without e-mail, erased by the producer of their shop.
 *
 * Other domains erase their own copies of the personal data by listening to CustomerErased,
 * dispatched within the same transaction.
 */
final readonly class CustomerEraser
{
    public function __construct(
        private OrderRepository $orders,
        private ShopFollowerRepository $followers,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
    ) {
    }

    public static function isAnonymized(Customer $customer): bool
    {
        return $customer->isAnonymized();
    }

    /**
     * Confirmed orders a producer still has to deliver: while there is one, the account is kept,
     * because the producer needs the name, phone and address to hand the order over.
     *
     * @return list<Order>
     */
    public function findPendingOrders(Customer $customer): array
    {
        $today = $this->clock->now()->setTime(0, 0);

        return array_values(array_filter(
            $this->orders->findByCustomer($customer),
            static function (Order $order) use ($today): bool {
                $sale = $order->getSale();

                return $order->isConfirmed() && $sale->getStatus()->isOngoing() && $sale->getDeliveryDate() >= $today;
            },
        ));
    }

    /**
     * @return bool true when the account was anonymised, false when it was deleted
     *
     * @throws PendingDeliveryException when an order is still to be delivered
     */
    public function erase(Customer $customer): bool
    {
        if ([] !== $this->findPendingOrders($customer)) {
            throw new PendingDeliveryException('This customer still has an order to be delivered.');
        }

        $id = $customer->getId() ?? throw new \LogicException('Only a saved customer can be erased.');
        $event = new CustomerErased($id, $customer->getEmail(), Customer::anonymizedEmailFor($id));

        return $this->entityManager->wrapInTransaction(function () use ($customer, $event): bool {
            $this->eventDispatcher->dispatch($event);

            foreach ($this->followers->findBy(['customer' => $customer]) as $follower) {
                $this->entityManager->remove($follower);
            }

            $orders = $this->orders->findByCustomer($customer);

            if ([] === $orders) {
                $this->entityManager->remove($customer);

                return false;
            }

            foreach ($orders as $order) {
                $order->setDeliveryAddress(null);
                $order->setNote(null);
                $order->setCustomerIdentity(null, null, null);
            }

            $customer
                ->setEmail($event->anonymizedEmail)
                ->setFirstName(null)
                ->setLastName(null)
                ->setPhone(null);

            return true;
        });
    }
}
