<?php

declare(strict_types=1);

namespace App\Shop\Entity;

use App\Shop\Repository\ShopInvitationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An invitation to join a shop as a member, sent by its owner. Only the SHA-256 hash of the token
 * is stored: the token itself exists only in the e-mail. Valid seven days, usable once.
 */
#[ORM\Entity(repositoryClass: ShopInvitationRepository::class)]
#[ORM\Table(name: 'shop_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_shop_invitation_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_shop_invitation_shop', columns: ['shop_id', 'accepted_at'])]
final class ShopInvitation
{
    public const int LIFETIME_DAYS = 7;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(length: 180)]
    private string $email;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Shop::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Shop $shop,
        string $email,
        #[ORM\Column(length: 64)]
        private string $tokenHash,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column]
        private \DateTimeImmutable $expiresAt,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $invitedBy = null,
    ) {
        $this->email = mb_strtolower(trim($email));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShop(): Shop
    {
        return $this->shop;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return !$this->acceptedAt instanceof \DateTimeImmutable && $now < $this->expiresAt;
    }

    public function markAccepted(\DateTimeImmutable $at): static
    {
        $this->acceptedAt = $at;

        return $this;
    }
}
