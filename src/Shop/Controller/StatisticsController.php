<?php

declare(strict_types=1);

namespace App\Shop\Controller;

use App\Shop\Service\CurrentShop;
use App\Shop\Statistics\ShopStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Statistiques": the sober figures of the current shop, rendered on the server.
 */
final class StatisticsController extends AbstractController
{
    #[Route('/pro/statistiques', name: 'pro_stats', methods: ['GET'])]
    public function __invoke(CurrentShop $currentShop, ShopStatistics $statistics): Response
    {
        return $this->render('shop/stats/index.html.twig', [
            'stats' => $statistics->compute($currentShop->get()),
            'weeks' => ShopStatistics::WEEKS,
            'months' => ShopStatistics::MONTHS,
            'regular_days' => ShopStatistics::REGULAR_DAYS,
        ]);
    }
}
