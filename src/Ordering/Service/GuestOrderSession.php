<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Ordering\Entity\Order;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Remembers, in the visitor's session, the references of the orders they placed as a guest, so
 * the confirmation page can be shown without an account.
 */
final class GuestOrderSession
{
    private const string KEY = 'ordering.guest_order_references';
    private const int MAX = 20;

    public function remember(SessionInterface $session, Order $order): void
    {
        $references = $this->references($session);
        $references[] = $order->getReference();

        $session->set(self::KEY, \array_slice(array_values(array_unique($references)), -self::MAX));
    }

    public function knows(SessionInterface $session, Order $order): bool
    {
        return \in_array($order->getReference(), $this->references($session), true);
    }

    /**
     * @return list<string>
     */
    private function references(SessionInterface $session): array
    {
        $stored = $session->get(self::KEY, []);

        return \is_array($stored) ? array_values(array_filter($stored, \is_string(...))) : [];
    }
}
