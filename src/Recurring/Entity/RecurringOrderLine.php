<?php

declare(strict_types=1);

namespace App\Recurring\Entity;

use App\Catalog\Entity\Product;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quantity of one product a subscriber wants at each delivery (choice mode), in thousandths of the unit.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_recurring_order_line_product', columns: ['recurring_order_id', 'product_id'])]
final class RecurringOrderLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @throws \InvalidArgumentException when the quantity is not positive, or a fraction of a product sold by the unit
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: RecurringOrder::class, inversedBy: 'lines')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private RecurringOrder $recurringOrder,
        #[ORM\ManyToOne(targetEntity: Product::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Product $product,
        #[ORM\Column]
        private int $quantity,
    ) {
        $this->assertQuantity($quantity);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecurringOrder(): RecurringOrder
    {
        return $this->recurringOrder;
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

    private function assertQuantity(int $quantity): void
    {
        if ($quantity <= 0 || !$this->product->getUnit()->accepts($quantity)) {
            throw new \InvalidArgumentException(\sprintf('Invalid quantity %d for "%s".', $quantity, $this->product->getName()));
        }
    }
}
