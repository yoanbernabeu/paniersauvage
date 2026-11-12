<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Customer\Entity\Customer;
use App\Customer\Repository\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds or creates the customer an order is for, from an e-mail address typed by a guest or by a
 * producer. It never logs anybody in.
 *
 * The account's profile (name, phone) is never touched, nor revealed: whoever typed the address
 * cannot prove they own it. The identity typed in the form is frozen on the order instead
 * (`Order::setCustomerIdentity()`); only the authenticated customer updates their own profile.
 */
final readonly class OrderCustomerResolver
{
    public function __construct(
        private CustomerRepository $customers,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The customer is persisted but not flushed: OrderManager::placeOrder() flushes it together
     * with the order.
     */
    public function resolve(string $email): Customer
    {
        $customer = $this->customers->findOneByEmail($email);

        if (!$customer instanceof Customer) {
            $customer = new Customer($email);
            $this->entityManager->persist($customer);
        }

        return $customer;
    }
}
