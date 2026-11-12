<?php

declare(strict_types=1);

namespace App\Customer\Repository;

use App\Customer\Entity\Customer;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
final class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    public function findOneByEmail(string $email): ?Customer
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /**
     * A customer without e-mail of this shop; null for another shop's customer or a regular account.
     */
    public function findWithoutEmailInShop(int $id, Shop $shop): ?Customer
    {
        return $this->findOneBy(['id' => $id, 'ownerShop' => $shop]);
    }

    /**
     * @return list<Customer> the customers without e-mail of this shop
     */
    public function findAllWithoutEmailInShop(Shop $shop): array
    {
        return $this->findBy(['ownerShop' => $shop]);
    }
}
