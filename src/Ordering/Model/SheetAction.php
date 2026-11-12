<?php

declare(strict_types=1);

namespace App\Ordering\Model;

use App\Ordering\Enum\OrderStatus;
use App\Ordering\Enum\SheetActionType;

/**
 * One action of the delivery sheet, with the state the producer saw when doing it: the server
 * applies it only if the order is still in that state (App\Ordering\Service\DeliverySheetSync).
 */
final readonly class SheetAction
{
    /**
     * @param array<int, int|null> $weights         order line id => actual weight in thousandths (null = clear it)
     * @param array<int, int|null> $expectedWeights order line id => weight the producer saw
     */
    public function __construct(
        public string $id,
        public SheetActionType $type,
        public ?OrderStatus $status = null,
        public ?OrderStatus $expected = null,
        public array $weights = [],
        public array $expectedWeights = [],
    ) {
    }
}
