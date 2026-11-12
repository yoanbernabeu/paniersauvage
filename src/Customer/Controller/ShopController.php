<?php

declare(strict_types=1);

namespace App\Customer\Controller;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use App\Customer\Entity\Customer;
use App\Customer\Entity\ShopFollower;
use App\Customer\Service\FollowManager;
use App\Customer\Service\PendingFollow;
use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Repository\DeliveryPointRepository;
use App\Ordering\Enum\OrderSource;
use App\Sale\Entity\Sale;
use App\Sale\Enum\SaleStatus;
use App\Sale\Repository\SaleRepository;
use App\Shop\Entity\Shop;
use App\Shop\Repository\ShopRepository;
use App\Shop\Search\CrossDiscovery;
use App\Shop\Search\PointsMap;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Public page of a producer, and the "follow" buttons.
 *
 * The `/{slug}` routes are declared with a low priority so they never shadow another route.
 */
final class ShopController extends AbstractController
{
    use TargetPathTrait;

    private const int LOW_PRIORITY = -100;
    private const string SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';
    private const int MAX_SCHEDULED_SALES = 4;

    public function __construct(
        private readonly ShopRepository $shops,
        private readonly FollowManager $followManager,
    ) {
    }

    /**
     * The shop page: open sales, the catalogue even without a sale, the pick-up points with a small
     * map, the means of payment, the identity of the seller (distance selling) and a few other
     * producers of the directory nearby. `?via=` (where the visitor came from) is passed on to the
     * order links, without a cookie.
     */
    #[Route('/{slug}', name: 'shop_show', requirements: ['slug' => self::SLUG], methods: ['GET'], priority: self::LOW_PRIORITY)]
    public function show(
        string $slug,
        Request $request,
        SaleRepository $sales,
        ProductRepository $products,
        DeliveryPointRepository $deliveryPoints,
        CrossDiscovery $crossDiscovery,
        PointsMap $pointsMap,
        ClockInterface $clock,
        #[CurrentUser] ?Customer $customer = null,
    ): Response {
        $shop = $this->findShop($slug);
        $now = $clock->now();
        $points = $deliveryPoints->findByShop($shop);
        $catalogue = array_filter($products->findByShop($shop), static fn (Product $product): bool => $product->isAvailableOn($now));
        $openSales = array_filter($sales->findOpenByShop($shop), static fn (Sale $sale): bool => $sale->acceptsOrders($now));

        return $this->render('customer/shop/show.html.twig', [
            'shop' => $shop,
            'open_sales' => $openSales,
            'scheduled_sales' => $sales->findBy(['shop' => $shop, 'status' => SaleStatus::Scheduled], ['opensAt' => 'ASC'], self::MAX_SCHEDULED_SALES),
            'next_sale' => current($sales->findNextByShops([$shop], $now)) ?: null,
            'permanent_products' => array_filter($catalogue, static fn (Product $product): bool => $product->isPermanent()),
            'seasonal_products' => array_filter($catalogue, static fn (Product $product): bool => $product->isSeasonal()),
            'labels' => Product::uniqueLabels(array_map(static fn (Product $product): array => $product->getLabels(), $catalogue)),
            'home_points' => array_filter($points, static fn (DeliveryPoint $point): bool => $point->isHome()),
            'fixed_points' => array_filter($points, static fn (DeliveryPoint $point): bool => !$point->isHome()),
            'points_map' => $pointsMap->build($points),
            'nearby_shops' => $crossDiscovery->aroundFirstOf($points),
            'follower' => $customer instanceof Customer ? $this->followManager->find($customer, $shop) : null,
            'via' => OrderSource::fromVia($request->query->get('via'))?->via(),
        ]);
    }

    #[Route('/{slug}/suivre', name: 'shop_follow', requirements: ['slug' => self::SLUG], methods: ['POST'], priority: self::LOW_PRIORITY)]
    public function follow(string $slug, Request $request, PendingFollow $pendingFollow, #[CurrentUser] ?Customer $customer = null): RedirectResponse
    {
        $shop = $this->findShop($slug);
        $this->denyUnlessCsrfTokenIsValid($request);
        $emailConsent = $request->request->getBoolean('email_consent');

        if (!$customer instanceof Customer) {
            // Anonymous visitor: sign in first, the follow is applied on the way back (PendingFollowSubscriber).
            $session = $request->getSession();
            $pendingFollow->remember($session, $shop->getSlug(), $emailConsent);
            $this->saveTargetPath($session, 'main', $this->generateUrl('shop_show', ['slug' => $shop->getSlug()]));
            $this->addFlash('info', \sprintf('Connecte-toi pour suivre %s : on t\'envoie un lien par e-mail.', $shop->getName()));

            return $this->redirectToRoute('customer_login');
        }

        $alreadyFollowing = $this->followManager->find($customer, $shop) instanceof ShopFollower;
        $this->followManager->follow($customer, $shop, $emailConsent);

        if ($alreadyFollowing) {
            $this->addFlash('success', $emailConsent
                ? \sprintf('C\'est noté : %s te préviendra par e-mail.', $shop->getName())
                : \sprintf('C\'est noté : plus d\'e-mail de la part de %s.', $shop->getName()));
        } else {
            $this->addFlash('success', \sprintf('Tu suis maintenant %s.', $shop->getName()));
        }

        return $this->redirectBack($request, $shop);
    }

    #[Route('/{slug}/ne-plus-suivre', name: 'shop_unfollow', requirements: ['slug' => self::SLUG], methods: ['POST'], priority: self::LOW_PRIORITY)]
    public function unfollow(string $slug, Request $request, #[CurrentUser] ?Customer $customer = null): RedirectResponse
    {
        $shop = $this->findShop($slug);
        $this->denyUnlessCsrfTokenIsValid($request);

        if (!$customer instanceof Customer) {
            return $this->redirectToRoute('customer_login');
        }

        $this->followManager->unfollow($customer, $shop);
        $this->addFlash('success', \sprintf('Tu ne suis plus %s.', $shop->getName()));

        return $this->redirectBack($request, $shop);
    }

    private function findShop(string $slug): Shop
    {
        $shop = Shop::isReservedSlug($slug) ? null : $this->shops->findOneBySlug($slug);

        return $shop ?? throw $this->createNotFoundException('No shop at this address.');
    }

    private function denyUnlessCsrfTokenIsValid(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function redirectBack(Request $request, Shop $shop): RedirectResponse
    {
        if ('account' === $request->request->getString('_back')) {
            return $this->redirect($this->generateUrl('customer_account').'#producteurs');
        }

        return $this->redirectToRoute('shop_show', ['slug' => $shop->getSlug()]);
    }
}
