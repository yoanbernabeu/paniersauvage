<?php

declare(strict_types=1);

namespace App\Shop\Entity;

use App\Shop\Enum\PaymentMethod;
use App\Shop\Enum\ShopCategory;
use App\Shop\Enum\VatRegime;
use App\Shop\Repository\ShopRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A producer's shop: the tenant every producer-side resource belongs to.
 */
#[ORM\Entity(repositoryClass: ShopRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_shop_slug', columns: ['slug'])]
#[UniqueEntity(fields: ['slug'], message: 'Cette adresse de boutique est déjà prise.')]
final class Shop implements \Stringable
{
    /**
     * Slugs that would shadow an application route, a public file or a future page (SPEC §5): the
     * single list used by the validation and the slug generator. ReservedSlugsTest checks that the
     * first segment of every route path is in it: add the segment here when you add a route.
     */
    public const array RESERVED_SLUGS = [
        // Application routes
        'pro', 'producteurs', 'trouver-un-producteur', 'connexion', 'deconnexion', 'mon-compte', 'mes-commandes', 'e-mails', 'webhooks',
        // Framework, assets and public files
        '_components', '_profiler', '_wdt', '_error', 'assets', 'uploads', 'build', 'bundles', 'index.php',
        'favicon.ico', 'robots.txt', 'sitemap.xml', 'health', 'up',
        // Kept for later pages
        'admin', 'api', 'mentions-legales', 'cgu', 'cgv', 'confidentialite', 'engagements', 'contact', 'aide', 'tarifs',
        'inscription', 'boutique', 'boutiques', 'vente', 'ventes', 'panier', 'commande', 'recherche', 'carte',
        'login', 'logout',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Registered name of the business, when it differs from the shop name (seller identity, distance selling). */
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $legalName = null;

    /** Public contact address of the seller; the e-mail of the producer's account when empty. */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.')]
    #[Assert\Length(max: 180)]
    private ?string $contactEmail = null;

    #[ORM\Column(length: 14, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{14}$/', message: 'Le SIRET contient 14 chiffres.')]
    private ?string $siret = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    /** Free text shown to customers: the means of payment the producer accepts. Never a payment record. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $paymentInfo = null;

    /** @var list<string> values of PaymentMethod: the means of payment accepted, never a payment record. */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $paymentMethods = [];

    /** Name of the local currency accepted ("la Mesure"), with PaymentMethod::LocalCurrency. */
    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $localCurrencyName = null;

    /** Which food vouchers or food fund ("caisse alimentaire de Dieulefit"), with PaymentMethod::FoodVoucher. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $foodVoucherDetails = null;

    /** @var list<string> values of ShopCategory: what the shop sells, for the producer directory. */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    #[Assert\Expression('!this.isListedInSearch() or value !== []', message: 'Choisis au moins une catégorie pour apparaître dans l\'annuaire.')]
    private array $categories = [];

    /** Hours before the deadline after which customers can no longer edit; null = until the deadline. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $defaultEditCutoffHours = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $allowCustomerEdit = true;

    /**
     * Opt-in: shown in the producer directory (still subject to the subscription, to at least one
     * category and to a located pick-up point or delivery zone, SPEC §11).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $listedInSearch = false;

    /** Franchise en base (art. 293 B du CGI): new orders are frozen without VAT, with the legal mention. */
    #[ORM\Column(length: 20, enumType: VatRegime::class, options: ['default' => 'normal'])]
    private VatRegime $vatRegime = VatRegime::Normal;

    /**
     * Protection of new customers: the highest amount of a first order at this shop, in cents; null
     * = no limit (default). A customer is new until one of their orders here was picked up.
     */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(notInRangeMessage: 'Choisis un plafond entre 5 € et 1 000 €.', min: 500, max: 100000)]
    private ?int $firstOrderCapCents = null;

    /**
     * Deposit asked of new customers outside the platform ("5 € par Wero à la commande"): a text
     * shown to them, never a payment record (SPEC §1). Null = none (default).
     */
    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200)]
    private ?string $newCustomerDeposit = null;

    /**
     * "D'autres producteurs près de ce point de retrait" on this shop's page, confirmation and
     * e-mails. On by default; it never hides the shop from the other shops' blocks.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $crossDiscoveryEnabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, User> the owner first, then the members by seniority */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'shop')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $users;

    public function __construct(#[ORM\Column(length: 120)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        private string $name = '', #[ORM\Column(length: 80)]
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 80)]
        #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Utilise seulement des minuscules, des chiffres et des tirets.')]
        private string $slug = '')
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->users = new ArrayCollection();
    }

    public static function isReservedSlug(string $slug): bool
    {
        return \in_array(mb_strtolower($slug), self::RESERVED_SLUGS, true);
    }

    #[Assert\Callback]
    public function validateSlugIsNotReserved(ExecutionContextInterface $context): void
    {
        if (self::isReservedSlug($this->slug)) {
            $context->buildViolation('Cette adresse est réservée, choisis-en une autre.')->atPath('slug')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = mb_strtolower(trim($slug));

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): static
    {
        $this->siret = null === $siret ? null : (preg_replace('/\s+/', '', $siret) ?: null);

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;

        return $this;
    }

    public function getPaymentInfo(): ?string
    {
        return $this->paymentInfo;
    }

    public function setPaymentInfo(?string $paymentInfo): static
    {
        $this->paymentInfo = $paymentInfo;

        return $this;
    }

    /**
     * @return list<PaymentMethod> in the order of the enum
     */
    public function getPaymentMethods(): array
    {
        return array_values(array_filter(PaymentMethod::cases(), fn (PaymentMethod $method): bool => \in_array($method->value, $this->paymentMethods, true)));
    }

    /**
     * @param iterable<PaymentMethod> $paymentMethods
     */
    public function setPaymentMethods(iterable $paymentMethods): static
    {
        $this->paymentMethods = $this->values($paymentMethods);

        return $this;
    }

    public function acceptsPaymentMethod(PaymentMethod $method): bool
    {
        return \in_array($method->value, $this->paymentMethods, true);
    }

    /**
     * What customers read: "Espèces", "Monnaie locale (la Mesure)", "Chèques ou caisse alimentaire (…)".
     *
     * @return list<string>
     */
    public function paymentMethodLabels(): array
    {
        return array_map(fn (PaymentMethod $method): string => match (true) {
            PaymentMethod::LocalCurrency === $method && null !== $this->localCurrencyName => \sprintf('%s (%s)', $method->label(), $this->localCurrencyName),
            PaymentMethod::FoodVoucher === $method && null !== $this->foodVoucherDetails => \sprintf('%s (%s)', $method->label(), $this->foodVoucherDetails),
            default => $method->label(),
        }, $this->getPaymentMethods());
    }

    /**
     * The accepted means of payment in one line ("Espèces, Chèque"), then the free text: what the
     * e-mails and summaries show. Null when the producer said nothing.
     */
    public function paymentSummary(): ?string
    {
        $parts = array_filter([implode(', ', $this->paymentMethodLabels()), trim((string) $this->paymentInfo)], static fn (string $part): bool => '' !== $part);

        return [] === $parts ? null : implode('. ', $parts);
    }

    public function getLocalCurrencyName(): ?string
    {
        return $this->localCurrencyName;
    }

    public function setLocalCurrencyName(?string $localCurrencyName): static
    {
        $this->localCurrencyName = $this->nullIfBlank($localCurrencyName);

        return $this;
    }

    public function getFoodVoucherDetails(): ?string
    {
        return $this->foodVoucherDetails;
    }

    public function setFoodVoucherDetails(?string $foodVoucherDetails): static
    {
        $this->foodVoucherDetails = $this->nullIfBlank($foodVoucherDetails);

        return $this;
    }

    /**
     * @return list<ShopCategory> in the order of the enum
     */
    public function getCategories(): array
    {
        return array_values(array_filter(ShopCategory::cases(), fn (ShopCategory $category): bool => \in_array($category->value, $this->categories, true)));
    }

    /**
     * @param iterable<ShopCategory> $categories
     */
    public function setCategories(iterable $categories): static
    {
        $this->categories = $this->values($categories);

        return $this;
    }

    public function getLegalName(): ?string
    {
        return $this->legalName;
    }

    public function setLegalName(?string $legalName): static
    {
        $this->legalName = $this->nullIfBlank($legalName);

        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): static
    {
        $this->contactEmail = $this->nullIfBlank($contactEmail);

        return $this;
    }

    /**
     * The e-mail address customers can write to: the contact address, or the owner's.
     */
    public function publicEmail(): ?string
    {
        return $this->contactEmail ?? $this->getOwner()?->getEmail();
    }

    /**
     * The producer the shop belongs to (seller identity, reply-to, subscription): the owner account,
     * or the oldest account when no owner is left.
     */
    public function getOwner(): ?User
    {
        foreach ($this->users as $user) {
            if ($user->isShopOwner()) {
                return $user;
            }
        }

        $first = $this->users->first();

        return $first instanceof User ? $first : null;
    }

    public function getDefaultEditCutoffHours(): ?int
    {
        return $this->defaultEditCutoffHours;
    }

    public function setDefaultEditCutoffHours(?int $defaultEditCutoffHours): static
    {
        $this->defaultEditCutoffHours = $defaultEditCutoffHours;

        return $this;
    }

    public function isAllowCustomerEdit(): bool
    {
        return $this->allowCustomerEdit;
    }

    public function setAllowCustomerEdit(bool $allowCustomerEdit): static
    {
        $this->allowCustomerEdit = $allowCustomerEdit;

        return $this;
    }

    public function getVatRegime(): VatRegime
    {
        return $this->vatRegime;
    }

    public function setVatRegime(VatRegime $vatRegime): static
    {
        $this->vatRegime = $vatRegime;

        return $this;
    }

    /**
     * Under the franchise en base de TVA: no VAT on new orders.
     */
    public function isVatExempt(): bool
    {
        return VatRegime::Franchise === $this->vatRegime;
    }

    public function getFirstOrderCapCents(): ?int
    {
        return $this->firstOrderCapCents;
    }

    public function setFirstOrderCapCents(?int $firstOrderCapCents): static
    {
        $this->firstOrderCapCents = $firstOrderCapCents;

        return $this;
    }

    public function getNewCustomerDeposit(): ?string
    {
        return $this->newCustomerDeposit;
    }

    public function setNewCustomerDeposit(?string $newCustomerDeposit): static
    {
        $this->newCustomerDeposit = $this->nullIfBlank($newCustomerDeposit);

        return $this;
    }

    public function isCrossDiscoveryEnabled(): bool
    {
        return $this->crossDiscoveryEnabled;
    }

    public function setCrossDiscoveryEnabled(bool $crossDiscoveryEnabled): static
    {
        $this->crossDiscoveryEnabled = $crossDiscoveryEnabled;

        return $this;
    }

    public function isListedInSearch(): bool
    {
        return $this->listedInSearch;
    }

    public function setListedInSearch(bool $listedInSearch): static
    {
        $this->listedInSearch = $listedInSearch;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setShop($this);
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * @param iterable<\BackedEnum> $cases
     *
     * @return list<string>
     */
    private function values(iterable $cases): array
    {
        $values = [];
        foreach ($cases as $case) {
            $values[] = (string) $case->value;
        }

        return array_values(array_unique($values));
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }
}
