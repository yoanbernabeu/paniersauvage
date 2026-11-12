<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Customer\Entity\Customer;
use App\Ordering\Exception\FirstOrderCapExceededException;
use App\Ordering\Repository\OrderRepository;
use App\Shared\Service\FrenchFormatter;
use App\Shop\Entity\Shop;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Protection of new customers, set by the producer (both off by default): a cap on the amount of
 * a first order, and a deposit asked outside the platform (a text, never a payment record, SPEC §1).
 *
 * A customer is new at a shop until one of their orders there was picked up (`delivered`). A guest
 * proves nothing by typing an address: their order is capped like a new customer's, so that the
 * answer never tells whether that address already bought here; signing in lifts the cap.
 */
final readonly class NewCustomerPolicy
{
    public function __construct(
        private OrderRepository $orders,
        private Security $security,
        private FrenchFormatter $formatter,
    ) {
    }

    /**
     * Null (a visitor who has not said who they are) counts as new.
     */
    public function isNewCustomer(Shop $shop, ?Customer $customer): bool
    {
        return !$customer instanceof Customer || !$this->orders->hasDeliveredInShop($customer, $shop);
    }

    /**
     * Refuses a customer's order above the cap of the shop, unless the signed-in customer already
     * picked up an order there. Orders entered by the producer are never capped (not called).
     *
     * @throws FirstOrderCapExceededException
     */
    public function assertWithinFirstOrderCap(Shop $shop, Customer $customer, int $totalCents): void
    {
        $cap = $shop->getFirstOrderCapCents();
        if (null === $cap || $totalCents <= $cap) {
            return;
        }

        $signedIn = $this->isSignedIn($customer);
        if ($signedIn && !$this->isNewCustomer($shop, $customer)) {
            return;
        }

        throw new FirstOrderCapExceededException($this->refusal($shop, $cap, $signedIn));
    }

    private function isSignedIn(Customer $customer): bool
    {
        $user = $this->security->getUser();

        return $user instanceof Customer && null !== $customer->getId() && $user->getId() === $customer->getId();
    }

    private function refusal(Shop $shop, int $cap, bool $signedIn): string
    {
        $contact = match (true) {
            null !== $shop->getPhone() && '' !== trim($shop->getPhone()) => \sprintf('appelle %s au %s', $shop->getName(), trim($shop->getPhone())),
            null !== $shop->publicEmail() => \sprintf('écris à %s (%s)', $shop->getName(), $shop->publicEmail()),
            default => \sprintf('contacte directement %s', $shop->getName()),
        };

        $message = \sprintf(
            'Merci pour ta commande ! Pour une première commande, %s accepte %s au plus en ligne. Retire quelques produits, ou %s : vous verrez ensemble pour la suite.',
            $shop->getName(),
            $this->formatter->money($cap),
            $contact,
        );

        return $signedIn ? $message : $message.' Tu as déjà retiré une commande ici ? Connecte-toi pour commander sans limite.';
    }
}
