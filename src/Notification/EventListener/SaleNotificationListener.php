<?php

declare(strict_types=1);

namespace App\Notification\EventListener;

use App\Customer\Repository\ShopFollowerRepository;
use App\Notification\Enum\NotificationType;
use App\Notification\Service\NotificationComposer;
use App\Notification\Service\NotificationSender;
use App\Ordering\Entity\Order;
use App\Ordering\Repository\OrderRepository;
use App\Sale\Event\LastMinuteSaleOpened;
use App\Sale\Event\SaleCancelled;
use App\Sale\Event\SaleClosed;
use App\Sale\Event\SaleOpened;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mime\Address;

/**
 * Sale life cycle e-mails (SPEC §6): announcement to followers, summary to producers, cancellation notice.
 */
final readonly class SaleNotificationListener
{
    public const string RELATED_TYPE = 'sale';

    public function __construct(
        private NotificationSender $sender,
        private NotificationComposer $composer,
        private ShopFollowerRepository $followers,
        private OrderRepository $orders,
    ) {
    }

    /**
     * One e-mail per consenting follower, never twice for the same sale.
     */
    #[AsEventListener]
    public function onSaleOpened(SaleOpened $event): void
    {
        $sale = $event->sale;

        $this->sender->guard(NotificationType::SaleOpened, function () use ($sale): void {
            foreach ($this->followers->findConsentingByShop($sale->getShop()) as $follower) {
                $customer = $follower->getCustomer();
                $email = $customer->getEmail();
                if (null === $email) {
                    continue;
                }

                $this->sender->send(
                    NotificationType::SaleOpened,
                    new Address($email, $customer->getDisplayName()),
                    self::RELATED_TYPE,
                    $sale->getId(),
                    fn (): TemplatedEmail => $this->composer->saleOpened($sale, $customer),
                    $customer,
                    once: true,
                );
            }
        });
    }

    /**
     * Last-minute sale (SPEC §21): one e-mail per consenting follower who has no order in this sale
     * yet (they could not place a second one), never twice for the same sale.
     */
    #[AsEventListener]
    public function onLastMinuteSaleOpened(LastMinuteSaleOpened $event): void
    {
        $sale = $event->sale;

        $this->sender->guard(NotificationType::LastMinuteSale, function () use ($sale): void {
            foreach ($this->followers->findConsentingByShop($sale->getShop()) as $follower) {
                $customer = $follower->getCustomer();
                $email = $customer->getEmail();
                if (null === $email || $this->orders->findActiveForCustomerInSale($customer, $sale) instanceof Order) {
                    continue;
                }

                $this->sender->send(
                    NotificationType::LastMinuteSale,
                    new Address($email, $customer->getDisplayName()),
                    self::RELATED_TYPE,
                    $sale->getId(),
                    fn (): TemplatedEmail => $this->composer->lastMinuteSale($sale, $customer),
                    $customer,
                    once: true,
                );
            }
        });
    }

    /**
     * Production summary to every producer of the shop.
     */
    #[AsEventListener]
    public function onSaleClosed(SaleClosed $event): void
    {
        $sale = $event->sale;

        $this->sender->guard(NotificationType::SaleClosed, function () use ($sale): void {
            foreach ($sale->getShop()->getUsers() as $producer) {
                $this->sender->send(
                    NotificationType::SaleClosed,
                    new Address($producer->getEmail(), $producer->getFullName()),
                    self::RELATED_TYPE,
                    $sale->getId(),
                    fn (): TemplatedEmail => $this->composer->saleClosed($sale, $producer),
                    once: true,
                );
            }
        });
    }

    /**
     * One notice per customer whose order was still active when the sale was cancelled (the orders
     * are cancelled along with the sale, before this event). Dispatching it again only reaches the
     * customers not warned yet.
     */
    #[AsEventListener]
    public function onSaleCancelled(SaleCancelled $event): void
    {
        $sale = $event->sale;

        $this->sender->guard(NotificationType::SaleCancelled, function () use ($sale): void {
            // Customers without e-mail are left out: the producer warns them directly.
            $ordersByCustomer = [];
            foreach ($this->orders->findToWarnOfSaleCancellation($sale) as $order) {
                $email = $order->getCustomer()->getEmail();
                if (null !== $email) {
                    $ordersByCustomer[$email][] = $order;
                }
            }

            foreach ($ordersByCustomer as $email => $orders) {
                $customer = $orders[0]->getCustomer();

                $this->sender->send(
                    NotificationType::SaleCancelled,
                    new Address((string) $email, $orders[0]->getCustomerDisplayName()),
                    self::RELATED_TYPE,
                    $sale->getId(),
                    fn (): TemplatedEmail => $this->composer->saleCancelled($sale, $customer, $orders),
                    $customer,
                    once: true,
                );
            }
        });
    }
}
