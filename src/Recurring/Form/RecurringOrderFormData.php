<?php

declare(strict_types=1);

namespace App\Recurring\Form;

use App\Delivery\Entity\DeliveryPoint;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Model\RecurringOrderRequest;

/**
 * Data of the subscription form (subscribe, then change the next deliveries).
 */
final class RecurringOrderFormData
{
    public ?DeliveryPoint $deliveryPoint = null;

    public ?string $deliveryAddress = null;

    public int $shares = 1;

    /** @var array<int|string, int|null> product id => quantity in thousandths */
    public array $quantities = [];

    public bool $acceptTerms = false;

    public bool $acceptConditions = false;

    public bool $adultCertified = false;

    /**
     * Prefilled with the quantities proposed by the producer and the only pick-up point, if any.
     */
    public static function forPlan(RecurringPlan $plan): self
    {
        $data = new self();
        foreach ($plan->getItems() as $item) {
            $data->quantities[(string) $item->getProduct()->getId()] = $item->getQuantity();
        }

        $points = self::pointsOf($plan);
        if (1 === \count($points)) {
            $data->deliveryPoint = $points[0];
        }

        return $data;
    }

    public static function fromRecurringOrder(RecurringOrder $recurringOrder): self
    {
        $data = new self();
        $data->deliveryPoint = $recurringOrder->getDeliveryPoint();
        $data->deliveryAddress = $recurringOrder->getDeliveryAddress();
        $data->shares = $recurringOrder->getShares();
        foreach ($recurringOrder->getPlan()->getItems() as $item) {
            $data->quantities[(string) $item->getProduct()->getId()] = $recurringOrder->getQuantityOf($item->getProduct());
        }

        return $data;
    }

    /**
     * Pick-up points a subscriber may choose: those of the recurrence, not archived.
     *
     * @return list<DeliveryPoint>
     */
    public static function pointsOf(RecurringPlan $plan): array
    {
        return array_values(array_filter(
            $plan->getSchedule()->getDeliveryPoints()->toArray(),
            static fn (DeliveryPoint $point): bool => !$point->isArchived(),
        ));
    }

    public function toRequest(): RecurringOrderRequest
    {
        $quantities = [];
        foreach ($this->quantities as $productId => $quantity) {
            $quantities[(int) $productId] = $quantity ?? 0;
        }

        return new RecurringOrderRequest(
            $this->deliveryPoint ?? throw new \LogicException('The form must be valid.'),
            $this->shares,
            $quantities,
            $this->deliveryAddress,
        );
    }
}
