<?php

declare(strict_types=1);

namespace App\Notification\EventListener;

use App\Notification\Enum\NotificationType;
use App\Notification\Repository\NotificationLogRepository;
use App\Notification\Service\NotificationComposer;
use App\Notification\Service\NotificationSender;
use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderSource;
use App\Ordering\Event\OrderCancelled;
use App\Ordering\Event\OrderPlaced;
use App\Ordering\Event\OrderUpdated;
use App\Shared\Enum\Actor;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Keeps the customer informed about their order (SPEC §6).
 */
final readonly class OrderNotificationListener
{
    public const string RELATED_TYPE = 'order';

    public function __construct(
        private NotificationSender $sender,
        private NotificationComposer $composer,
        private NotificationLogRepository $logs,
    ) {
    }

    /**
     * The confirmation is sent once per order, even if the event is dispatched again.
     */
    #[AsEventListener]
    public function onOrderPlaced(OrderPlaced $event): void
    {
        $order = $event->order;

        // An order generated for a recurring order has its own e-mail (App\Recurring, "recurring_order_generated").
        if (Actor::System === $event->actor && OrderSource::Subscription === $order->getSource()) {
            return;
        }

        $this->notify(NotificationType::OrderPlaced, $order, fn (): TemplatedEmail => $this->composer->orderPlaced($order, $event->actor), once: true);
    }

    #[AsEventListener]
    public function onOrderUpdated(OrderUpdated $event): void
    {
        $order = $event->order;

        $this->notify(NotificationType::OrderUpdated, $order, fn (): TemplatedEmail => $this->composer->orderUpdated($order, $event->actor));
    }

    #[AsEventListener]
    public function onOrderCancelled(OrderCancelled $event): void
    {
        $order = $event->order;

        $this->sender->guard(NotificationType::OrderCancelled, function () use ($order, $event): void {
            // Orders cancelled by the system along with their sale are covered by the sale notice.
            if (Actor::System === $event->actor && $order->getSale()->isCancelled()) {
                return;
            }

            // The whole sale was cancelled and the customer already knows: no second e-mail.
            $saleId = $order->getSale()->getId();
            $email = $order->getCustomer()->getEmail();
            if (null !== $saleId && null !== $email && $this->logs->wasSent(NotificationType::SaleCancelled, SaleNotificationListener::RELATED_TYPE, $saleId, $email)) {
                return;
            }

            $this->notify(NotificationType::OrderCancelled, $order, fn (): TemplatedEmail => $this->composer->orderCancelled($order, $event->actor), once: true);
        });
    }

    /**
     * A customer without e-mail (order taken by phone) is never written to.
     *
     * @param \Closure(): Email $compose
     */
    private function notify(NotificationType $type, Order $order, \Closure $compose, bool $once = false): void
    {
        $customer = $order->getCustomer();
        $email = $customer->getEmail();
        if (null === $email) {
            return;
        }

        $this->sender->send(
            $type,
            new Address($email, $order->getCustomerDisplayName()),
            self::RELATED_TYPE,
            $order->getId(),
            $compose,
            $customer,
            $once,
        );
    }
}
