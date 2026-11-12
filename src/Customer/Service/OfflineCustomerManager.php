<?php

declare(strict_types=1);

namespace App\Customer\Service;

use App\Customer\Entity\Customer;
use App\Customer\Repository\CustomerRepository;
use App\Ordering\Repository\OrderRepository;
use App\Shop\Entity\Shop;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Customers without e-mail ("clients sans e-mail"), created by a producer for someone who orders
 * by phone or at the market. They belong to one shop (Customer::getOwnerShop()): every lookup here
 * starts from that shop, so another producer never sees them. They never sign in and never
 * receive an e-mail.
 */
final readonly class OfflineCustomerManager
{
    public function __construct(
        private CustomerRepository $customers,
        private OrderRepository $orders,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The shop's customer without e-mail with this phone number and name, or a new one (persisted,
     * not flushed: OrderManager::placeOrder() flushes it with the order). Phone numbers are
     * compared on their digits only ("06 12 34 56 78" = "0612345678"), names ignoring case.
     */
    public function resolve(Shop $shop, ?string $firstName, ?string $lastName, ?string $phone): Customer
    {
        $digits = $this->digits($phone);

        if ('' !== $digits) {
            foreach ($this->customers->findAllWithoutEmailInShop($shop) as $customer) {
                if ($this->digits($customer->getPhone()) === $digits
                    && $this->same($customer->getFirstName(), $firstName)
                    && $this->same($customer->getLastName(), $lastName)) {
                    return $customer;
                }
            }
        }

        $customer = Customer::withoutEmail($shop, $this->clock->now())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setPhone($phone);
        $this->entityManager->persist($customer);

        return $customer;
    }

    /**
     * Corrects the name and phone of a customer without e-mail, on their profile and on their
     * orders (the producer typed both). With an address:
     * - nobody uses it yet: the customer becomes a regular account, who can sign in with a magic
     *   link and will receive the next e-mails (none is sent now);
     * - an account already uses it: the orders move to that account and this customer is deleted.
     *
     * @return Customer the customer who now holds the orders
     */
    public function update(Customer $customer, ?string $firstName, ?string $lastName, ?string $phone, ?string $email): Customer
    {
        if (!$customer->isWithoutEmail()) {
            throw new \LogicException('Only a customer without e-mail is managed by a producer.');
        }

        $orders = $this->orders->findByCustomer($customer);
        $customer->setFirstName($firstName)->setLastName($lastName)->setPhone($phone);
        foreach ($orders as $order) {
            $order->setCustomerIdentity($customer->getFirstName(), $customer->getLastName(), $customer->getPhone());
        }

        // The form trims the address; the repository and Customer::becomeAccount() normalise it.
        $holder = $customer;
        if (null !== $email && '' !== $email) {
            $account = $this->customers->findOneByEmail($email);
            if ($account instanceof Customer && $account !== $customer) {
                foreach ($orders as $order) {
                    $order->transferTo($account);
                }
                $this->entityManager->remove($customer);
                $holder = $account;
            } else {
                $customer->becomeAccount($email);
            }
        }

        $this->entityManager->flush();

        return $holder;
    }

    private function digits(?string $phone): string
    {
        return preg_replace('/\D+/', '', $phone ?? '') ?? '';
    }

    private function same(?string $a, ?string $b): bool
    {
        return mb_strtolower(trim($a ?? '')) === mb_strtolower(trim($b ?? ''));
    }
}
