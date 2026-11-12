<?php

declare(strict_types=1);

namespace App\Sale\Controller;

use App\Catalog\Repository\ProductRepository;
use App\Ordering\Service\SaleOrders;
use App\Sale\Enum\SaleStatus;
use App\Sale\Repository\SaleRepository;
use App\Sale\Service\SubscriptionGate;
use App\Shop\Service\CurrentShop;
use App\Shop\Service\OnboardingChecklist;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly SaleRepository $sales,
        private readonly ProductRepository $products,
        private readonly SaleOrders $saleOrders,
        private readonly SubscriptionGate $subscriptionGate,
        private readonly ClockInterface $clock,
        private readonly OnboardingChecklist $onboarding,
    ) {
    }

    #[Route('/pro', name: 'pro_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        $shop = $this->currentShop->get();

        $upcoming = $this->sales->findUpcomingDeliveries($shop, $this->clock->now());
        $open = $this->sales->findByShopAndStatuses($shop, [SaleStatus::Open]);

        return $this->render('sale/dashboard.html.twig', [
            'shop' => $shop,
            'producer' => $this->currentShop->getUser(),
            'upcoming_deliveries' => $upcoming,
            'open_sales' => $open,
            'order_counts' => $this->saleOrders->countBySale([...$upcoming, ...$open]),
            'allows_new_sales' => $this->subscriptionGate->allowsNewSales($shop),
            // Nothing set up yet: the page sends the producer to the onboarding.
            'needs_onboarding' => 0 === $this->sales->countByShop($shop) && [] === $this->products->findByShop($shop, true),
            // Shown until the five first steps are done.
            'onboarding' => $this->onboarding->progress($shop),
        ]);
    }
}
