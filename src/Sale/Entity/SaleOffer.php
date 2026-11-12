<?php

declare(strict_types=1);

namespace App\Sale\Entity;

use App\Catalog\Entity\Product;
use App\Sale\Repository\SaleOfferRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A product offered in a sale, with its price frozen for that sale and its stock counter.
 * Quantities (`maxQty`, `reservedQty`) are in thousandths of the product's unit (App\Shared\Service\Quantity).
 *
 * `reservedQty` must only be changed through App\Ordering\Service\ReservationService
 * (transaction + SELECT … FOR UPDATE). The SQL CHECK constraint is the last line of defence
 * against overselling.
 */
#[ORM\Entity(repositoryClass: SaleOfferRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_sale_offer_sale_product', columns: ['sale_id', 'product_id'])]
#[ORM\Index(name: 'idx_sale_offer_product', columns: ['product_id'])]
final class SaleOffer
{
    /** Raw DDL: DBAL has no portable CHECK support, and ignores `columnDefinition` when diffing. */
    private const string RESERVED_QTY_DDL = 'INT DEFAULT 0 NOT NULL CONSTRAINT chk_sale_offer_reserved_qty CHECK (reserved_qty >= 0 AND (max_qty IS NULL OR reserved_qty <= max_qty))';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $priceCents;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 10000)]
    private int $vatRateBp;

    /** Quantity limit for this sale, in thousandths of the unit; null = unlimited. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $maxQty;

    #[ORM\Column(options: ['default' => 0], columnDefinition: self::RESERVED_QTY_DDL)]
    private int $reservedQty = 0;

    /** Put back on sale in the last-minute sale of its closed sale (SPEC §21, lot F). */
    #[ORM\Column(options: ['default' => false])]
    private bool $lastMinute = false;

    /**
     * Price, VAT and quantity limit default to the product's current values.
     */
    public function __construct(#[ORM\ManyToOne(targetEntity: Sale::class, inversedBy: 'offers')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Sale $sale, #[ORM\ManyToOne(targetEntity: Product::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Product $product, ?int $priceCents = null, ?int $vatRateBp = null, ?int $maxQty = null)
    {
        $this->priceCents = $priceCents ?? $this->product->getPriceCents();
        $this->vatRateBp = $vatRateBp ?? $this->product->getVatRateBp();
        $this->maxQty = $maxQty ?? $this->product->getMaxQtyDefault();
        $this->sale->addOffer($this);
    }

    public function isLimited(): bool
    {
        return null !== $this->maxQty;
    }

    /**
     * Quantity still available; null = unlimited.
     */
    public function remaining(): ?int
    {
        return null === $this->maxQty ? null : max(0, $this->maxQty - $this->reservedQty);
    }

    public function isSoldOut(): bool
    {
        return 0 === $this->remaining();
    }

    public function canReserve(int $quantity): bool
    {
        if ($quantity < 0) {
            return false;
        }

        $remaining = $this->remaining();

        return null === $remaining || $quantity <= $remaining;
    }

    /**
     * In-memory counter update. Call it only from ReservationService, on a row locked FOR UPDATE.
     *
     * @throws \DomainException          when the quantity is not available
     * @throws \InvalidArgumentException when the quantity is a fraction of a product sold by the unit
     */
    public function reserve(int $quantity): void
    {
        if ($quantity > 0 && !$this->product->getUnit()->accepts($quantity)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is sold by the unit: %d thousandths is not a whole quantity.', $this->product->getName(), $quantity));
        }

        if (!$this->canReserve($quantity)) {
            throw new \DomainException(\sprintf('Cannot reserve %d of "%s": %d left.', $quantity, $this->product->getName(), $this->remaining() ?? 0));
        }

        $this->reservedQty += $quantity;
    }

    public function release(int $quantity): void
    {
        if ($quantity < 0) {
            throw new \DomainException('Cannot release a negative quantity.');
        }

        $this->reservedQty = max(0, $this->reservedQty - $quantity);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSale(): Sale
    {
        return $this->sale;
    }

    public function getProduct(): Product
    {
        return $this->product;
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

    public function getMaxQty(): ?int
    {
        return $this->maxQty;
    }

    public function setMaxQty(?int $maxQty): static
    {
        $this->maxQty = $maxQty;

        return $this;
    }

    public function getReservedQty(): int
    {
        return $this->reservedQty;
    }

    public function isLastMinute(): bool
    {
        return $this->lastMinute;
    }

    /**
     * Puts $quantity back on sale for the last-minute sale: the limit becomes what is reserved plus
     * $quantity. Call it only on a row locked FOR UPDATE (App\Sale\Service\LastMinuteSale).
     *
     * @throws \DomainException when $quantity is not positive or not a whole quantity of a product sold by the unit
     */
    public function putBackOnSale(int $quantity): static
    {
        if ($quantity <= 0 || !$this->product->getUnit()->accepts($quantity)) {
            throw new \DomainException(\sprintf('Cannot put %d thousandths of "%s" back on sale.', $quantity, $this->product->getName()));
        }

        $this->maxQty = $this->reservedQty + $quantity;
        $this->lastMinute = true;

        return $this;
    }
}
