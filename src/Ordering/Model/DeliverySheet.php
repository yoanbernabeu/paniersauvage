<?php

declare(strict_types=1);

namespace App\Ordering\Model;

use App\Catalog\Enum\ProductUnit;
use App\Ordering\Entity\Order;

/**
 * What the producer sees on the delivery sheet of one delivery: orders still to hand over, orders
 * delivered, and the truck load per product.
 */
final readonly class DeliverySheet
{
    /**
     * @param list<Order>                                                                           $todo  confirmed first, then the ones marked not delivered
     * @param list<Order>                                                                           $done  delivered
     * @param list<array{offer: int, label: string, unit: ProductUnit, total: int, remaining: int}> $truck per product (sale offer id): quantity loaded and still to hand over (thousandths)
     */
    public function __construct(
        public array $todo,
        public array $done,
        public array $truck,
    ) {
    }
}
