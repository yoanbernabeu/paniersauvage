<?php

declare(strict_types=1);

namespace App\Customer\Entity;

use App\Customer\Repository\CustomerRepository;
use App\Shop\Entity\Shop;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A customer: one account for the whole platform, passwordless (magic link by e-mail).
 *
 * A customer without e-mail ("client sans e-mail", e.g. someone who orders by phone) is created
 * by a producer (Customer::withoutEmail()): they belong to that shop only (`ownerShop`), are
 * never shown to another shop, can never sign in and never receive an e-mail. The producer can
 * correct, erase or give them an address (App\Customer\Service\OfflineCustomerManager): they
 * then become a regular account. Invariant: `ownerShop` is set exactly when there is no real address.
 */
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_customer_email', columns: ['email'])]
#[ORM\Index(name: 'idx_customer_owner_shop', columns: ['owner_shop_id'])]
#[UniqueEntity(fields: ['email'])]
final class Customer implements UserInterface
{
    public const string ROLE_CUSTOMER = 'ROLE_CUSTOMER';

    /** Domain of the placeholder address given to an erased account (reserved TLD: never deliverable). */
    public const string ANONYMIZED_EMAIL_DOMAIN = 'compte-supprime.invalid';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null for a customer without e-mail (several of them never clash on the unique index). */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    /** The only shop that knows a customer without e-mail; null for a regular account. */
    #[ORM\ManyToOne(targetEntity: Shop::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Shop $ownerShop = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $firstName = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $lastName = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $phone = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Version of the terms of use accepted (App\Legal\LegalDocuments::TERMS_VERSION), and when. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $termsVersion = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    /**
     * Part of the magic-link signature (security.yaml): every sign-in changes it, which invalidates
     * every link sent before, including the one just used.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /**
     * @param \DateTimeImmutable|null $createdAt pass the clock's time; null (now) only for forms and legacy callers
     */
    public function __construct(string $email = '', ?\DateTimeImmutable $createdAt = null)
    {
        $this->setEmail($email);
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    /**
     * A customer without e-mail, known by the producer of this shop only.
     */
    public static function withoutEmail(Shop $shop, \DateTimeImmutable $createdAt): self
    {
        $customer = new self('', $createdAt);
        $customer->email = null;
        $customer->ownerShop = $shop;

        return $customer;
    }

    /**
     * Placeholder address of an erased account.
     */
    public static function anonymizedEmailFor(int $id): string
    {
        return \sprintf('client-%d@%s', $id, self::ANONYMIZED_EMAIL_DOMAIN);
    }

    /**
     * Is this the placeholder address of an erased account? Such an address is never written to.
     */
    public static function isAnonymizedEmail(string $email): bool
    {
        return str_ends_with(mb_strtolower($email), '@'.self::ANONYMIZED_EMAIL_DOMAIN);
    }

    public function isAnonymized(): bool
    {
        return null !== $this->email && self::isAnonymizedEmail($this->email);
    }

    /**
     * Can e-mails be sent to this customer? Not without an address, nor to an erased account.
     */
    public function hasEmail(): bool
    {
        return null !== $this->email && '' !== $this->email && !$this->isAnonymized();
    }

    /**
     * Created by a producer without e-mail address: known by `getOwnerShop()` only.
     */
    public function isWithoutEmail(): bool
    {
        return $this->ownerShop instanceof Shop;
    }

    public function getOwnerShop(): ?Shop
    {
        return $this->ownerShop;
    }

    /**
     * A customer without e-mail gets an address: they become a regular account, can sign in with
     * a magic link and receive the next e-mails. Check first that no account uses this address.
     */
    public function becomeAccount(string $email): static
    {
        $this->setEmail($email);
        $this->ownerShop = null;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Null for a customer without e-mail: use hasEmail() before writing to it.
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return null === $this->email || '' === $this->email ? '-' : $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [self::ROLE_CUSTOMER];
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $this->clean($firstName);

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $this->clean($lastName);

        return $this;
    }

    /**
     * "Prénom Nom", or the e-mail address when no name is known yet, or the phone number of a
     * customer without e-mail.
     */
    public function getDisplayName(): string
    {
        $name = trim(($this->firstName ?? '').' '.($this->lastName ?? ''));

        return match (true) {
            '' !== $name => $name,
            null !== $this->email && '' !== $this->email => $this->email,
            default => $this->phone ?? 'Client sans e-mail',
        };
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $this->clean($phone);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function acceptTerms(string $version, \DateTimeImmutable $at): static
    {
        $this->termsVersion = $version;
        $this->termsAcceptedAt = $at;

        return $this;
    }

    public function hasAcceptedTerms(string $version): bool
    {
        return $version === $this->termsVersion && $this->termsAcceptedAt instanceof \DateTimeImmutable;
    }

    public function getTermsVersion(): ?string
    {
        return $this->termsVersion;
    }

    public function setTermsVersion(?string $termsVersion): static
    {
        $this->termsVersion = $termsVersion;

        return $this;
    }

    public function getTermsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function setTermsAcceptedAt(?\DateTimeImmutable $termsAcceptedAt): static
    {
        $this->termsAcceptedAt = $termsAcceptedAt;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    private function clean(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }
}
