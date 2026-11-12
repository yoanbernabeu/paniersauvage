<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Catalog\Repository\ProductRepository;
use App\Customer\Repository\ShopFollowerRepository;
use App\Delivery\Repository\DeliveryPointRepository;
use App\Ordering\Repository\OrderRepository;
use App\Sale\Repository\SaleRepository;
use App\Sale\Repository\SaleScheduleRepository;
use App\Shared\Navigation\SafeRouter;
use App\Shop\Entity\Shop;

/**
 * Computes the first-steps checklist of a shop (welcome page, reusable on the dashboard).
 */
final readonly class OnboardingChecklist
{
    public function __construct(
        private ProductRepository $products,
        private DeliveryPointRepository $deliveryPoints,
        private SaleRepository $sales,
        private SaleScheduleRepository $schedules,
        private ShopFollowerRepository $followers,
        private OrderRepository $orders,
        private SafeRouter $router,
    ) {
    }

    public function progress(Shop $shop): OnboardingProgress
    {
        $shopCompleted = '' !== trim($shop->getDescription() ?? '') && null !== $shop->paymentSummary();
        $hasProduct = $this->products->count(['shop' => $shop, 'archived' => false]) > 0;
        $hasDeliveryPoint = $this->deliveryPoints->count(['shop' => $shop, 'archived' => false]) > 0;
        $hasSaleOrSchedule = $this->sales->count(['shop' => $shop]) > 0 || $this->schedules->count(['shop' => $shop]) > 0;
        $shared = $this->followers->count(['shop' => $shop]) > 0 || $this->hasOrder($shop);

        return new OnboardingProgress($shopCompleted, $hasProduct, $hasDeliveryPoint, $hasSaleOrSchedule, $shared, [
            new OnboardingStep(
                'shop', 1, $shopCompleted,
                'Complète ta boutique',
                'Une présentation et les moyens de paiement que tu acceptes : c\'est ce que tes clients voient en premier.',
                $this->router->path('pro_shop_settings') ?? '/pro/boutique',
                'Compléter ma boutique',
            ),
            new OnboardingStep(
                'product', 2, $hasProduct,
                'Ajoute ton premier produit',
                'Un nom, un prix, une photo si tu veux. Ta gamme permanente sera proposée dans chaque vente.',
                $this->router->path('pro_product_new') ?? '/pro/produits/nouveau',
                'Ajouter un produit',
            ),
            new OnboardingStep(
                'delivery_point', 3, $hasDeliveryPoint,
                'Crée ton premier point de livraison',
                'Une place de village, un arrêt de tournée ou la livraison à domicile.',
                $this->router->path('pro_delivery_point_new') ?? '/pro/points-de-livraison/nouveau',
                'Ajouter un point de livraison',
            ),
            new OnboardingStep(
                'sale', 4, $hasSaleOrSchedule,
                'Ouvre ta première vente',
                'Une vente ponctuelle, ou une récurrence qui prépare tes ventes chaque semaine.',
                $this->router->path('pro_sale_new') ?? '/pro/ventes/nouvelle',
                'Créer une vente',
                $this->router->path('pro_schedule_new') ?? '/pro/recurrences/nouvelle',
                'Créer une récurrence',
            ),
            new OnboardingStep(
                'share', 5, $shared,
                'Partage le lien de ta boutique',
                'Envoie-le à tes clients : ils commandent en ligne, tu retrouves tout ici.',
                $this->router->path('pro_onboarding') ?? '/pro/bienvenue',
                'Récupérer mon lien',
            ),
            new OnboardingStep(
                'directory', 6, $shop->isListedInSearch(),
                'Apparaître dans l\'annuaire',
                'Facultatif : choisis ce que tu vends et les gens du coin te trouvent dans « Trouver un producteur », classés par distance seulement.',
                ($this->router->path('pro_shop_settings') ?? '/pro/boutique').'#settings-visibility',
                'Choisir',
                optional: true,
            ),
        ]);
    }

    private function hasOrder(Shop $shop): bool
    {
        return null !== $this->orders->createQueryBuilder('o')
            ->select('o.id')
            ->join('o.sale', 's')
            ->where('s.shop = :shop')
            ->setParameter('shop', $shop)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
