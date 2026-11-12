<?php

declare(strict_types=1);

namespace App\Shop\Entity;

use App\Shop\Enum\ShopRole;
use App\Shop\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A producer account (e-mail + password), attached to one shop: one account, one shop. A shop may
 * have several accounts: its owner and the members the owner invited (ShopInvitation).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'producer_user')]
#[ORM\UniqueConstraint(name: 'uniq_producer_user_email', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cette adresse e-mail.')]
final class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_PRODUCER = 'ROLE_PRODUCER';

    /** Granted to the owner of the shop only: subscription and team. */
    public const string ROLE_SHOP_OWNER = 'ROLE_SHOP_OWNER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $firstName = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $lastName = '';

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\ManyToOne(targetEntity: Shop::class, inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Shop $shop = null;

    #[ORM\Column(length: 20, enumType: ShopRole::class, options: ['default' => 'owner'])]
    private ShopRole $shopRole = ShopRole::Owner;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Version of the terms of use accepted (App\Legal\LegalDocuments::TERMS_VERSION), and when. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $termsVersion = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    public function __construct(string $email = '')
    {
        $this->setEmail($email);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
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
        return '' === $this->email ? '-' : $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = trim($firstName);

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = trim($lastName);

        return $this;
    }

    public function getFullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = [...$this->roles, self::ROLE_PRODUCER];
        if ($this->isShopOwner()) {
            $roles[] = self::ROLE_SHOP_OWNER;
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    public function getShop(): ?Shop
    {
        return $this->shop;
    }

    public function setShop(?Shop $shop): static
    {
        $this->shop = $shop;

        return $this;
    }

    public function getShopRole(): ShopRole
    {
        return $this->shopRole;
    }

    public function setShopRole(ShopRole $shopRole): static
    {
        $this->shopRole = $shopRole;

        return $this;
    }

    public function isShopOwner(): bool
    {
        return ShopRole::Owner === $this->shopRole;
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
}
