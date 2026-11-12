<?php

declare(strict_types=1);

namespace App\Ordering\Model;

use App\Sale\Entity\SaleDelivery;

/**
 * What a customer (or a producer on their behalf) wants: the full target state of an order.
 */
final readonly class OrderRequest
{
    /**
     * @param array<int, int>       $quantities sale offer id => wanted quantity, in thousandths of the
     *                                          unit (App\Shared\Service\Quantity; 0 or absent = none)
     * @param CustomerIdentity|null $identity   who the order is for, used when the order is placed
     *                                          (defaults to the customer's profile)
     */
    public function __construct(
        public SaleDelivery $delivery,
        public array $quantities,
        public ?string $note = null,
        public ?string $deliveryAddress = null,
        public ?CustomerIdentity $identity = null,
    ) {
    }
}
