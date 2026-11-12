<?php

declare(strict_types=1);

namespace App\Customer\Entity;

use App\Customer\Repository\ShopFollowerRepository;
use App\Shop\Entity\Shop;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer following a shop; `emailConsent` gates the "sale opened" e-mails.
 */
#[ORM\Entity(repositoryClass: ShopFollowerRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_shop_follower_customer_shop', columns: ['customer_id', 'shop_id'])]
#[ORM\Index(name: 'idx_shop_follower_shop', columns: ['shop_id', 'email_consent'])]
final class ShopFollower
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Customer::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Customer $customer,
        #[ORM\ManyToOne(targetEntity: Shop::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Shop $shop,
        #[ORM\Column(options: ['default' => false])]
        private bool $emailConsent,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getShop(): Shop
    {
        return $this->shop;
    }

    public function hasEmailConsent(): bool
    {
        return $this->emailConsent;
    }

    public function setEmailConsent(bool $emailConsent): static
    {
        $this->emailConsent = $emailConsent;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
