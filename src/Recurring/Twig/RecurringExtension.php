<?php

declare(strict_types=1);

namespace App\Recurring\Twig;

use App\Customer\Entity\Customer;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Repository\RecurringOrderRepository;
use App\Recurring\Service\RecurringPlanCatalog;
use App\Sale\Entity\Sale;
use App\Shop\Entity\Shop;
use Twig\Attribute\AsTwigFunction;

/**
 * Lets the pages of other features show the recurring orders without changing their controllers
 * (shop page, sale page, customer account).
 */
final readonly class RecurringExtension
{
    public function __construct(
        private RecurringPlanCatalog $catalog,
        private RecurringOrderRepository $recurringOrders,
    ) {
    }

    /**
     * @return list<RecurringPlan>
     */
    #[AsTwigFunction('recurring_plans')]
    public function plans(Shop $shop): array
    {
        return $this->catalog->openPlans($shop);
    }

    #[AsTwigFunction('recurring_plan_for_sale')]
    public function planForSale(Sale $sale): ?RecurringPlan
    {
        return $this->catalog->planForSale($sale);
    }

    #[AsTwigFunction('recurring_places_left')]
    public function placesLeft(RecurringPlan $plan): ?int
    {
        return $this->catalog->placesLeft($plan);
    }

    /**
     * @return list<RecurringOrder>
     */
    #[AsTwigFunction('customer_recurring_orders')]
    public function customerRecurringOrders(Customer $customer): array
    {
        return $this->recurringOrders->findByCustomer($customer);
    }
}
