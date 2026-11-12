<?php

declare(strict_types=1);

namespace App\Shop\Entity;

use App\Shop\Repository\PasswordResetRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A "forgotten password" request of a producer. Only the SHA-256 hash of the token is stored: the
 * token itself exists only in the e-mail. Valid for one hour, usable once.
 */
#[ORM\Entity(repositoryClass: PasswordResetRequestRepository::class)]
#[ORM\Table(name: 'password_reset_request')]
#[ORM\UniqueConstraint(name: 'uniq_password_reset_request_token_hash', columns: ['token_hash'])]
final class PasswordResetRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 64)]
        private string $tokenHash,
        #[ORM\Column]
        private \DateTimeImmutable $requestedAt,
        #[ORM\Column]
        private \DateTimeImmutable $expiresAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return !$this->usedAt instanceof \DateTimeImmutable && $now < $this->expiresAt;
    }

    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->usedAt ??= $now;
    }
}
