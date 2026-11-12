<?php

declare(strict_types=1);

namespace App\Ordering\Entity;

use App\Catalog\Enum\ProductUnit;
use App\Ordering\Repository\OrderLineRepository;
use App\Sale\Entity\SaleOffer;
use App\Shared\Service\Quantity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One product of an order. Price, VAT rate (0 when the order is VAT-exempt), label and unit are frozen at order time.
 * The quantity is in thousandths of the unit (App\Shared\Service\Quantity): 2 loaves = 2000,
 * 1.5 kg = 1500. For a product sold by weight, the producer enters the actual weight when
 * handing it over (`actualQuantity`, App\Ordering\Service\OrderWeighing): the line is then
 * billed on it. The line total is the unit price times the billed quantity, rounded to the cent
 * (Quantity::priceCents()). Stock stays reserved on the ordered quantity. The VAT split of a line is computed by
 * App\Accounting\Service\VatCalculator (integer arithmetic).
 */
#[ORM\Entity(repositoryClass: OrderLineRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_order_line_order_offer', columns: ['order_id', 'sale_offer_id'])]
#[ORM\Index(name: 'idx_order_line_sale_offer', columns: ['sale_offer_id'])]
final class OrderLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $unitPriceCents;

    #[ORM\Column]
    private int $vatRateBp;

    #[ORM\Column(length: 160)]
    private string $label;

    #[ORM\Column(length: 20, enumType: ProductUnit::class)]
    private ProductUnit $unit;

    /** Weight actually handed over (thousandths of a kilogram), by weight only; null = not weighed yet. */
    #[ORM\Column(nullable: true)]
    private ?int $actualQuantity = null;

    /**
     * Snapshots price, VAT rate, label and unit from the offer and attaches itself to the order.
     *
     * @throws \InvalidArgumentException when the quantity is a fraction of a product sold by the unit
     */
    public function __construct(#[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'lines')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Order $order, #[ORM\ManyToOne(targetEntity: SaleOffer::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private SaleOffer $saleOffer, #[ORM\Column]
        #[Assert\Positive]
        private int $quantity)
    {
        $this->unitPriceCents = $this->saleOffer->getPriceCents();
        // Franchise en base de TVA (frozen on the order): no VAT at all.
        $this->vatRateBp = $this->order->isVatExempt() ? 0 : $this->saleOffer->getVatRateBp();
        $this->label = $this->saleOffer->getProduct()->getName();
        $this->unit = $this->saleOffer->getProduct()->getUnit();
        $this->assertQuantity($this->quantity);
        $this->order->addLine($this);
    }

    /**
     * Unit price × billed quantity, rounded to the cent (Quantity::priceCents()).
     */
    public function getTotalCents(): int
    {
        return Quantity::priceCents($this->getBilledQuantity(), $this->unitPriceCents);
    }

    /**
     * The actual weight once entered, the ordered quantity otherwise.
     */
    public function getBilledQuantity(): int
    {
        return $this->actualQuantity ?? $this->quantity;
    }

    public function getActualQuantity(): ?int
    {
        return $this->actualQuantity;
    }

    /**
     * Sets (or clears, with null) the weight handed over, and recomputes the order total.
     * Use App\Ordering\Service\OrderWeighing, which records it in the order's history.
     *
     * @throws \InvalidArgumentException when the product is not sold by weight or the weight is not positive
     */
    public function setActualQuantity(?int $actualQuantity): static
    {
        if (null !== $actualQuantity && (!$this->unit->isWeighed() || $actualQuantity <= 0)) {
            throw new \InvalidArgumentException(\sprintf('"%s": only a positive weight of a product sold by weight can be entered.', $this->label));
        }

        $this->actualQuantity = $actualQuantity;
        $this->order->recomputeTotal();

        return $this;
    }

    /**
     * Number of items handed over: the pieces or bunches, one per product sold by weight.
     */
    public function getItemCount(): int
    {
        return $this->unit->isWeighed() ? 1 : intdiv($this->quantity, Quantity::UNIT);
    }

    public function isWeighed(): bool
    {
        return $this->unit->isWeighed();
    }

    /**
     * Sold by weight and not weighed yet: its amount is an estimate ("environ").
     */
    public function isEstimated(): bool
    {
        return $this->unit->isWeighed() && null === $this->actualQuantity;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getSaleOffer(): SaleOffer
    {
        return $this->saleOffer;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /**
     * @throws \InvalidArgumentException when the quantity is a fraction of a product sold by the unit
     */
    public function setQuantity(int $quantity): static
    {
        $this->assertQuantity($quantity);
        $this->quantity = $quantity;
        $this->order->recomputeTotal();

        return $this;
    }

    public function getUnitPriceCents(): int
    {
        return $this->unitPriceCents;
    }

    public function getVatRateBp(): int
    {
        return $this->vatRateBp;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getUnit(): ProductUnit
    {
        return $this->unit;
    }

    private function assertQuantity(int $quantity): void
    {
        if (!$this->unit->accepts($quantity)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is sold by the unit: %d thousandths is not a whole quantity.', $this->label, $quantity));
        }
    }
}
