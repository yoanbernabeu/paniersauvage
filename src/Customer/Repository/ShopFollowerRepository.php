<?php

declare(strict_types=1);

namespace App\Customer\Repository;

use App\Customer\Entity\Customer;
use App\Customer\Entity\ShopFollower;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShopFollower>
 */
final class ShopFollowerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShopFollower::class);
    }

    public function findOneByCustomerAndShop(Customer $customer, Shop $shop): ?ShopFollower
    {
        return $this->findOneBy(['customer' => $customer, 'shop' => $shop]);
    }

    /**
     * Followers who agreed to receive the shop's e-mails.
     *
     * @return list<ShopFollower>
     */
    public function findConsentingByShop(Shop $shop): array
    {
        return $this->findBy(['shop' => $shop, 'emailConsent' => true]);
    }
}
