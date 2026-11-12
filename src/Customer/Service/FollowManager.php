<?php

declare(strict_types=1);

namespace App\Customer\Service;

use App\Customer\Entity\Customer;
use App\Customer\Entity\ShopFollower;
use App\Customer\Repository\ShopFollowerRepository;
use App\Shop\Entity\Shop;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Follows and unfollows a shop on behalf of a customer.
 */
final readonly class FollowManager
{
    public function __construct(
        private ShopFollowerRepository $followers,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function find(Customer $customer, Shop $shop): ?ShopFollower
    {
        return $this->followers->findOneByCustomerAndShop($customer, $shop);
    }

    /**
     * Starts following the shop, or updates the e-mail consent of an existing follow.
     */
    public function follow(Customer $customer, Shop $shop, bool $emailConsent): ShopFollower
    {
        $follower = $this->find($customer, $shop);

        if (!$follower instanceof ShopFollower) {
            $follower = new ShopFollower($customer, $shop, $emailConsent, $this->clock->now());
            $this->entityManager->persist($follower);
        } else {
            $follower->setEmailConsent($emailConsent);
        }

        $this->entityManager->flush();

        return $follower;
    }

    public function unfollow(Customer $customer, Shop $shop): void
    {
        $follower = $this->find($customer, $shop);

        if ($follower instanceof ShopFollower) {
            $this->entityManager->remove($follower);
            $this->entityManager->flush();
        }
    }

    /**
     * The shops a customer follows, by shop name.
     *
     * @return list<ShopFollower>
     */
    public function findByCustomer(Customer $customer): array
    {
        $followers = $this->followers->findBy(['customer' => $customer]);
        usort($followers, static fn (ShopFollower $a, ShopFollower $b): int => strcasecmp($a->getShop()->getName(), $b->getShop()->getName()));

        return $followers;
    }
}
