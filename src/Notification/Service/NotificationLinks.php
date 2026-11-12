<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Customer\Entity\Customer;
use App\Ordering\Entity\Order;
use App\Sale\Entity\Sale;
use App\Shared\Navigation\SafeRouter;
use App\Shop\Entity\Shop;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Absolute URLs used in e-mails. Routes owned by other features are resolved through SafeRouter, with
 * the path frozen in the specification as a fallback.
 */
final readonly class NotificationLinks
{
    /**
     * An e-mail is often opened a while after it was sent: the magic link stays valid for 48 hours.
     * It works once and dies with any later sign-in (security.yaml); an expired link leads to a page
     * that sends a fresh one to the same order.
     */
    public const int LOGIN_LINK_LIFETIME = 48 * 3600;

    public function __construct(
        private SafeRouter $router,
        private UrlHelper $urlHelper,
        // The handler of the customer firewall: the default alias depends on the current request,
        // which may be a producer page, a worker or a console command.
        #[Autowire(service: 'security.authenticator.login_link_handler.main')]
        private LoginLinkHandlerInterface $loginLinkHandler,
        private UriSigner $uriSigner,
    ) {
    }

    /**
     * Magic link: logs the customer in (firewall `main`), then lands on their order.
     */
    public function order(Order $order): string
    {
        $reference = $order->getReference();
        $target = $this->router->path('customer_order_show', ['reference' => $reference]) ?? '/mes-commandes/'.rawurlencode($reference);
        $loginUrl = $this->loginLinkHandler->createLoginLink($order->getCustomer(), null, self::LOGIN_LINK_LIFETIME)->getUrl();

        return $loginUrl.(str_contains($loginUrl, '?') ? '&' : '?').http_build_query(['_target_path' => $target]);
    }

    /**
     * Public page of the producer, where a customer follows / unfollows the shop.
     */
    public function shop(Shop $shop): string
    {
        return $this->absolute($this->router->path('shop_show', ['slug' => $shop->getSlug()]) ?? '/'.rawurlencode($shop->getSlug()));
    }

    /**
     * Public ordering page of a sale.
     */
    public function sale(Sale $sale): string
    {
        $shop = $sale->getShop();

        return $this->absolute(
            $this->router->path('sale_show', ['slug' => $shop->getSlug(), 'code' => $sale->getCode()])
            ?? \sprintf('/%s/vente/%s', rawurlencode($shop->getSlug()), rawurlencode($sale->getCode())),
        );
    }

    /**
     * Producer screen of a sale.
     */
    public function proSale(Sale $sale): string
    {
        return $this->absolute($this->router->path('pro_sale_show', ['id' => $sale->getId()]) ?? '/pro/ventes/'.$sale->getId());
    }

    /**
     * Subscription page of the producer.
     */
    public function proBilling(): string
    {
        return $this->absolute($this->router->path('pro_billing') ?? '/pro/abonnement');
    }

    /**
     * Signed one-click link that stops the sale announcements of the shop for this customer (no expiry:
     * an old e-mail must still let them unsubscribe).
     */
    public function unsubscribe(Shop $shop, Customer $customer): string
    {
        $path = $this->router->path('customer_unsubscribe', ['shopId' => $shop->getId(), 'customerId' => $customer->getId()])
            ?? \sprintf('/e-mails/ne-plus-recevoir/%d/%d', $shop->getId(), $customer->getId());

        return $this->uriSigner->sign($this->absolute($path));
    }

    private function absolute(string $path): string
    {
        return $this->urlHelper->getAbsoluteUrl($path);
    }
}
