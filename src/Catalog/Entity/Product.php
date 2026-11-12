<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Enum\ProductKind;
use App\Catalog\Enum\ProductUnit;
use App\Catalog\Repository\ProductRepository;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Index(name: 'idx_product_shop_listing', columns: ['shop_id', 'archived', 'position'])]
final class Product implements \Stringable
{
    public const int DEFAULT_VAT_RATE_BP = 550;

    /** Selling steps offered for a product sold by weight, in thousandths of a kilogram. */
    public const array WEIGHT_STEPS = ['100 g' => 100, '250 g' => 250, '500 g' => 500, '1 kg' => 1000];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPath = null;

    /** VAT rate in basis points (550 = 5.5 %). */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 10000)]
    private int $vatRateBp = self::DEFAULT_VAT_RATE_BP;

    #[ORM\Column(length: 20, enumType: ProductUnit::class)]
    private ProductUnit $unit = ProductUnit::Piece;

    /** Free text ("gluten, fruits à coque"). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $allergens = null;

    /** @var list<string> Short quality labels ("Bio", "Levain naturel"). */
    #[ORM\Column]
    private array $labels = [];

    #[ORM\Column(length: 20, enumType: ProductKind::class)]
    private ProductKind $kind = ProductKind::Permanent;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $availableFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'availableFrom', message: 'La fin de période doit suivre le début.')]
    private ?\DateTimeImmutable $availableUntil = null;

    /** Default quantity limit per sale, in thousandths of the unit (App\Shared\Service\Quantity); null = unlimited. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Indique une quantité positive, ou laisse vide.')]
    private ?int $maxQtyDefault = null;

    /** By weight only: customers order multiples of this step (thousandths of a kilogram). */
    #[ORM\Column(options: ['default' => Quantity::UNIT])]
    private int $quantityStep = Quantity::UNIT;

    /** By weight only: smallest quantity a customer can order (thousandths of a kilogram). */
    #[ORM\Column(options: ['default' => Quantity::UNIT])]
    private int $minQuantity = Quantity::UNIT;

    #[ORM\Column(options: ['default' => false])]
    private bool $archived = false;

    /**
     * Perishable food: excluded from the 14-day right of withdrawal (Code de la consommation,
     * L221-28 4°). True by default; the producer unticks it for wine, honey, oil, preserves...
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $perishable = true;

    /** Alcoholic drink: age certification on order, health warning, age check at pick-up. */
    #[ORM\Column(options: ['default' => false])]
    private bool $alcoholic = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Shop::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Shop $shop,
        #[ORM\Column(length: 120)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        private string $name = '',
        /** Price including VAT, in cents. */
        #[ORM\Column]
        #[Assert\PositiveOrZero]
        private int $priceCents = 0,
    ) {
    }

    /**
     * Should this product be offered by default in a sale delivered on the given date?
     * Permanent products always are; seasonal ones only within their period (open bounds allowed).
     */
    public function isAvailableOn(\DateTimeInterface $date): bool
    {
        if ($this->archived) {
            return false;
        }

        if (ProductKind::Permanent === $this->kind) {
            return true;
        }

        $day = $date->format('Y-m-d');

        if ($this->availableFrom instanceof \DateTimeImmutable && $day < $this->availableFrom->format('Y-m-d')) {
            return false;
        }

        return !$this->availableUntil instanceof \DateTimeImmutable || $day <= $this->availableUntil->format('Y-m-d');
    }

    /**
     * Can a customer order this quantity (in thousandths)? A multiple of the step, at least the
     * minimum; whole units only when the product is not sold by weight.
     */
    public function acceptsOrderQuantity(int $quantity): bool
    {
        // The minimum is at least the step (one unit when not sold by weight): 0 is never accepted.
        return $quantity >= $this->getMinQuantity() && 0 === $quantity % $this->getQuantityStep();
    }

    #[Assert\Callback]
    public function validateQuantities(ExecutionContextInterface $context): void
    {
        if (null !== $this->maxQtyDefault && !$this->unit->accepts($this->maxQtyDefault)) {
            $context->buildViolation('Ce produit se vend à l\'unité : indique un nombre entier.')->atPath('maxQtyDefault')->addViolation();
        }

        if (!$this->unit->isWeighed()) {
            return;
        }

        if (!\in_array($this->quantityStep, self::WEIGHT_STEPS, true)) {
            $context->buildViolation('Choisis un pas de vente proposé.')->atPath('quantityStep')->addViolation();
        } elseif ($this->minQuantity <= 0 || 0 !== $this->minQuantity % $this->quantityStep) {
            $context->buildViolation('Le minimum doit être un multiple du pas de vente.')->atPath('minQuantity')->addViolation();
        }
    }

    public function isPermanent(): bool
    {
        return ProductKind::Permanent === $this->kind;
    }

    public function isSeasonal(): bool
    {
        return ProductKind::Seasonal === $this->kind;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShop(): Shop
    {
        return $this->shop;
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

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function setPhotoPath(?string $photoPath): static
    {
        $this->photoPath = $photoPath;

        return $this;
    }

    public function getPriceCents(): int
    {
        return $this->priceCents;
    }

    public function setPriceCents(int $priceCents): static
    {
        $this->priceCents = $priceCents;

        return $this;
    }

    public function getVatRateBp(): int
    {
        return $this->vatRateBp;
    }

    public function setVatRateBp(int $vatRateBp): static
    {
        $this->vatRateBp = $vatRateBp;

        return $this;
    }

    public function getUnit(): ProductUnit
    {
        return $this->unit;
    }

    public function setUnit(ProductUnit $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getAllergens(): ?string
    {
        return $this->allergens;
    }

    public function setAllergens(?string $allergens): static
    {
        $this->allergens = $allergens;

        return $this;
    }

    /**
     * The labels of several products, each once whatever its case ("Bio" and "bio"), in order of
     * appearance: the badges of a shop.
     *
     * @param iterable<list<string>> $labelLists
     *
     * @return list<string>
     */
    public static function uniqueLabels(iterable $labelLists): array
    {
        $labels = [];
        foreach ($labelLists as $list) {
            foreach ($list as $label) {
                $labels[mb_strtolower($label)] ??= $label;
            }
        }

        return array_values($labels);
    }

    /**
     * @return list<string>
     */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /**
     * @param array<array-key, string> $labels
     */
    public function setLabels(array $labels): static
    {
        $this->labels = array_values(array_unique(array_filter(array_map(trim(...), $labels), static fn (string $label): bool => '' !== $label)));

        return $this;
    }

    public function getKind(): ProductKind
    {
        return $this->kind;
    }

    public function setKind(ProductKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getAvailableFrom(): ?\DateTimeImmutable
    {
        return $this->availableFrom;
    }

    public function setAvailableFrom(?\DateTimeImmutable $availableFrom): static
    {
        $this->availableFrom = $availableFrom;

        return $this;
    }

    public function getAvailableUntil(): ?\DateTimeImmutable
    {
        return $this->availableUntil;
    }

    public function setAvailableUntil(?\DateTimeImmutable $availableUntil): static
    {
        $this->availableUntil = $availableUntil;

        return $this;
    }

    public function getMaxQtyDefault(): ?int
    {
        return $this->maxQtyDefault;
    }

    public function setMaxQtyDefault(?int $maxQtyDefault): static
    {
        $this->maxQtyDefault = $maxQtyDefault;

        return $this;
    }

    /**
     * Step between two quantities a customer can order: the stored step by weight, one unit otherwise.
     */
    public function getQuantityStep(): int
    {
        return $this->unit->isWeighed() ? $this->quantityStep : Quantity::UNIT;
    }

    public function setQuantityStep(int $quantityStep): static
    {
        $this->quantityStep = $quantityStep;

        return $this;
    }

    /**
     * Smallest quantity a customer can order: the stored minimum by weight (never below the step),
     * one unit otherwise.
     */
    public function getMinQuantity(): int
    {
        return $this->unit->isWeighed() ? max($this->minQuantity, $this->quantityStep) : Quantity::UNIT;
    }

    public function setMinQuantity(int $minQuantity): static
    {
        $this->minQuantity = $minQuantity;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): static
    {
        $this->archived = $archived;

        return $this;
    }

    public function isPerishable(): bool
    {
        return $this->perishable;
    }

    public function setPerishable(bool $perishable): static
    {
        $this->perishable = $perishable;

        return $this;
    }

    public function isAlcoholic(): bool
    {
        return $this->alcoholic;
    }

    public function setAlcoholic(bool $alcoholic): static
    {
        $this->alcoholic = $alcoholic;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
