<?php

declare(strict_types=1);

namespace App\Recurring\Entity;

use App\Catalog\Entity\Product;
use App\Shared\Service\Quantity;
use Doctrine\ORM\Mapping as ORM;

/**
 * One product of a recurring plan. Basket mode: quantity in one basket; choice mode: most a
 * subscriber may take at each delivery. Thousandths of the unit (App\Shared\Service\Quantity).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_recurring_plan_item_product', columns: ['plan_id', 'product_id'])]
final class RecurringPlanItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @throws \InvalidArgumentException when the quantity is not positive, or a fraction of a product sold by the unit
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: RecurringPlan::class, inversedBy: 'items')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private RecurringPlan $plan,
        #[ORM\ManyToOne(targetEntity: Product::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Product $product,
        #[ORM\Column]
        private int $quantity,
        #[ORM\Column]
        private int $position,
    ) {
        $this->assertQuantity($quantity);
    }

    /**
     * Price of this quantity at the current price of the product.
     */
    public function priceCents(int $shares = 1): int
    {
        return Quantity::priceCents($this->quantity * $shares, $this->product->getPriceCents());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlan(): RecurringPlan
    {
        return $this->plan;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->assertQuantity($quantity);
        $this->quantity = $quantity;

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

    private function assertQuantity(int $quantity): void
    {
        if ($quantity <= 0 || !$this->product->getUnit()->accepts($quantity)) {
            throw new \InvalidArgumentException(\sprintf('Invalid quantity %d for "%s".', $quantity, $this->product->getName()));
        }
    }
}
