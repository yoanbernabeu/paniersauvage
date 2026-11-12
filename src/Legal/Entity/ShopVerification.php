<?php

declare(strict_types=1);

namespace App\Legal\Entity;

use App\Legal\Enum\VerificationStatus;
use App\Legal\Repository\ShopVerificationRepository;
use App\Shop\Entity\Shop;
use Doctrine\ORM\Mapping as ORM;

/**
 * Light check of a producer: its SIRET looked up in the public company register (Recherche
 * d'entreprises). Valid only for the SIRET it was made with: when the shop's SIRET changes, it is
 * checked again (ShopVerificationListener).
 */
#[ORM\Entity(repositoryClass: ShopVerificationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_shop_verification_shop', columns: ['shop_id'])]
#[ORM\Index(name: 'idx_shop_verification_status', columns: ['status'])]
final class ShopVerification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: VerificationStatus::class)]
    private VerificationStatus $status = VerificationStatus::Pending;

    /** Name of the company in the register. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $companyName = null;

    /** Main activity of the establishment (NAF rev. 2, "10.71C"). */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $nafCode = null;

    /** Last answer of the register (null: never reached). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    /** Note of the operator (app:shops:review). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $reviewNote = null;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Shop::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Shop $shop,
        /** The SIRET this verification is about. */
        #[ORM\Column(length: 14)]
        private string $siret,
    ) {
    }

    /**
     * Fixtures and tests: a shop known to be verified, without calling the register.
     */
    public static function verified(Shop $shop, string $siret, string $companyName, string $nafCode, \DateTimeImmutable $at): self
    {
        $verification = new self($shop, $siret);
        $verification->record(VerificationStatus::Verified, $companyName, $nafCode, $at);

        return $verification;
    }

    /**
     * Starts over for another SIRET.
     */
    public function restartFor(string $siret): static
    {
        $this->siret = $siret;
        $this->status = VerificationStatus::Pending;
        $this->companyName = null;
        $this->nafCode = null;
        $this->checkedAt = null;
        $this->verifiedAt = null;
        $this->reviewNote = null;

        return $this;
    }

    /**
     * Answer of the register.
     */
    public function record(VerificationStatus $status, ?string $companyName, ?string $nafCode, \DateTimeImmutable $at): static
    {
        $this->status = $status;
        $this->companyName = $companyName;
        $this->nafCode = $nafCode;
        $this->checkedAt = $at;
        $this->verifiedAt = VerificationStatus::Verified === $status ? ($this->verifiedAt ?? $at) : null;

        return $this;
    }

    /**
     * Decision of the operator on a shop to review.
     */
    public function decide(bool $approved, ?string $note, \DateTimeImmutable $at): static
    {
        $this->status = $approved ? VerificationStatus::Verified : VerificationStatus::Rejected;
        $this->verifiedAt = $approved ? $at : null;
        $this->reviewNote = null === $note || '' === trim($note) ? null : mb_substr(trim($note), 0, 500);

        return $this;
    }

    /**
     * Verified, for the shop's current SIRET.
     */
    public function isValid(): bool
    {
        return VerificationStatus::Verified === $this->status && $this->siret === $this->shop->getSiret();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShop(): Shop
    {
        return $this->shop;
    }

    public function getSiret(): string
    {
        return $this->siret;
    }

    public function getStatus(): VerificationStatus
    {
        return $this->status;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function getNafCode(): ?string
    {
        return $this->nafCode;
    }

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getReviewNote(): ?string
    {
        return $this->reviewNote;
    }
}
