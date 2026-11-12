<?php

declare(strict_types=1);

namespace App\Accounting\Model;

use App\Ordering\Entity\Order;
use App\Sale\Entity\SaleDelivery;

final readonly class DeliveryGroup
{
    /**
     * @param list<Order> $orders sorted by customer last name, then first name
     */
    public function __construct(
        public SaleDelivery $delivery,
        public array $orders,
        public int $totalCents,
    ) {
    }
}
