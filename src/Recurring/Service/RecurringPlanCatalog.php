<?php

declare(strict_types=1);

namespace App\Recurring\Service;

use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Repository\RecurringOrderRepository;
use App\Recurring\Repository\RecurringPlanRepository;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleSchedule;
use App\Sale\Service\SubscriptionGate;
use App\Shop\Entity\Shop;
use Psr\Clock\ClockInterface;

/**
 * The plans a customer can subscribe to: subscriptions open, recurrence active, season not over,
 * and a shop whose Panier Sauvage subscription allows new sales (SPEC §7).
 */
final readonly class RecurringPlanCatalog
{
    public function __construct(
        private RecurringPlanRepository $plans,
        private RecurringOrderRepository $recurringOrders,
        private SubscriptionGate $subscriptionGate,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<RecurringPlan>
     */
    public function openPlans(Shop $shop): array
    {
        if (!$this->subscriptionGate->allowsNewSales($shop)) {
            return [];
        }

        return $this->plans->findOpenByShop($shop, $this->clock->now());
    }

    public function isOpen(RecurringPlan $plan): bool
    {
        return $plan->acceptsSubscriptions($this->clock->now()) && $this->subscriptionGate->allowsNewSales($plan->getShop());
    }

    /**
     * The open plan of the recurrence of this sale, if any.
     */
    public function planForSale(Sale $sale): ?RecurringPlan
    {
        $schedule = $sale->getSchedule();
        if (!$schedule instanceof SaleSchedule) {
            return null;
        }

        $plan = $this->plans->findOneBySchedule($schedule);

        return $plan instanceof RecurringPlan && $this->isOpen($plan) ? $plan : null;
    }

    /**
     * Places left; null = no limit.
     */
    public function placesLeft(RecurringPlan $plan): ?int
    {
        $capacity = $plan->getCapacity();

        return null === $capacity ? null : max(0, $capacity - $this->recurringOrders->countInProgress($plan));
    }
}
