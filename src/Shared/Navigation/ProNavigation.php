<?php

declare(strict_types=1);

namespace App\Shared\Navigation;

use App\Shop\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the producer navigation. Entries whose route does not exist (yet) are left out,
 * so the layout never breaks while features are being built. The subscription is shown to the
 * owner of the shop only (members cannot manage it).
 */
final readonly class ProNavigation
{
    /**
     * route => [label, icon, route-name prefixes that mark the entry as active].
     *
     * @var array<string, array{string, string, list<string>}>
     */
    private const array ENTRIES = [
        'pro_dashboard' => ['Accueil', 'lucide:layout-dashboard', ['pro_dashboard']],
        'pro_sale_index' => ['Ventes', 'lucide:shopping-basket', ['pro_sale_', 'pro_order_']],
        'pro_schedule_index' => ['Récurrences', 'lucide:repeat', ['pro_schedule_']],
        'pro_recurring_index' => ['Paniers récurrents', 'lucide:package', ['pro_recurring_']],
        'pro_product_index' => ['Produits', 'lucide:wheat', ['pro_product_']],
        'pro_delivery_point_index' => ['Points de livraison', 'lucide:map-pin', ['pro_delivery_point_']],
        'pro_customer_index' => ['Clients', 'lucide:users', ['pro_customer_', 'pro_offline_customer_']],
        'pro_stats' => ['Statistiques', 'lucide:chart-column', ['pro_stats']],
        'pro_accounting' => ['Comptabilité', 'lucide:calculator', ['pro_accounting']],
        'pro_shop_settings' => ['Ma boutique', 'lucide:store', ['pro_shop_settings']],
        'pro_team' => ['Équipe', 'lucide:user-round-plus', ['pro_team']],
        'pro_billing' => ['Abonnement', 'lucide:credit-card', ['pro_billing']],
    ];

    /** Entries only the owner of the shop sees. */
    private const array OWNER_ONLY = ['pro_billing'];

    /** Entries of the mobile bottom bar, next to the "Plus" menu (route => short label). */
    private const array MOBILE_PRIMARY = [
        'pro_dashboard' => 'Accueil',
        'pro_sale_index' => 'Ventes',
        'pro_product_index' => 'Produits',
    ];

    public function __construct(
        private SafeRouter $router,
        private RequestStack $requestStack,
        private Security $security,
    ) {
    }

    /**
     * @return list<NavItem>
     */
    public function items(): array
    {
        $currentRoute = $this->requestStack->getMainRequest()?->attributes->getString('_route') ?? '';
        $items = [];

        $owner = $this->security->isGranted(User::ROLE_SHOP_OWNER);

        foreach (self::ENTRIES as $route => [$label, $icon, $prefixes]) {
            $url = $this->router->path($route);
            if (null === $url || (!$owner && \in_array($route, self::OWNER_ONLY, true))) {
                continue;
            }

            $active = false;
            foreach ($prefixes as $prefix) {
                $active = $active || str_starts_with($currentRoute, $prefix);
            }

            $items[] = new NavItem($route, $label, $url, $icon, $active, isset(self::MOBILE_PRIMARY[$route]), self::MOBILE_PRIMARY[$route] ?? null);
        }

        return $items;
    }
}
