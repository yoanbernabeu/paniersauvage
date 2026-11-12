<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Billing\Entity\ShopSubscription;
use App\Customer\Entity\Customer;
use App\Instance\InstanceSettings;
use App\Notification\Enum\NotificationType;
use App\Ordering\Entity\Order;
use App\Ordering\Service\NewCustomerPolicy;
use App\Ordering\Service\OrderIcsBuilder;
use App\Sale\Entity\Sale;
use App\Shared\Enum\Actor;
use App\Shared\Service\FrenchFormatter;
use App\Shop\Entity\Shop;
use App\Shop\Entity\User;
use App\Shop\Search\CrossDiscovery;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;

/**
 * Builds each e-mail: subject, templates (HTML + text), context and headers.
 *
 * E-mails to customers are sent "on behalf of" the shop: sender name « <Boutique> via <instance name> »
 * (the address stays the platform's, for deliverability) and replies go to the producer.
 */
final readonly class NotificationComposer
{
    public function __construct(
        private NotificationLinks $links,
        private FrenchFormatter $formatter,
        private SaleSummaryBuilder $summaryBuilder,
        private OrderIcsBuilder $calendar,
        private ClockInterface $clock,
        private CrossDiscovery $crossDiscovery,
        private NewCustomerPolicy $newCustomers,
        #[Autowire(env: 'MAILER_FROM')]
        private string $mailerFrom,
        private InstanceSettings $instance,
    ) {
    }

    public function orderPlaced(Order $order, Actor $actor): TemplatedEmail
    {
        $subject = \sprintf('Ta commande chez %s est confirmée 🧺', $order->getShop()->getName());

        $context = $this->orderContext($order, $actor);
        // Other producers near the pick-up point, only when the customer ordered themselves.
        $context['nearby_shops'] = Actor::Customer === $actor ? $this->crossDiscovery->around($order->getSaleDelivery()->getDeliveryPoint()) : [];
        // The deposit the producer asks of new customers, outside the platform (a text, never a payment record).
        $deposit = $order->getShop()->getNewCustomerDeposit();
        $context['new_customer_deposit'] = Actor::Customer === $actor && null !== $deposit && $this->newCustomers->isNewCustomer($order->getShop(), $order->getCustomer()) ? $deposit : null;

        return $this->customerEmail($order->getShop(), NotificationType::OrderPlaced, $subject, $context)
            ->attach($this->calendar->build($order), 'livraison.ics', 'text/calendar');
    }

    public function orderUpdated(Order $order, Actor $actor): TemplatedEmail
    {
        $shopName = $order->getShop()->getName();
        $subject = Actor::Producer === $actor
            ? \sprintf('%s a modifié ta commande', $shopName)
            : \sprintf('Ta commande chez %s est modifiée', $shopName);

        return $this->customerEmail($order->getShop(), NotificationType::OrderUpdated, $subject, $this->orderContext($order, $actor));
    }

    public function orderCancelled(Order $order, Actor $actor): TemplatedEmail
    {
        $shopName = $order->getShop()->getName();
        $subject = Actor::Producer === $actor
            ? \sprintf('%s a annulé ta commande', $shopName)
            : \sprintf('Ta commande chez %s est annulée', $shopName);

        return $this->customerEmail($order->getShop(), NotificationType::OrderCancelled, $subject, $this->orderContext($order, $actor));
    }

    public function orderReminder(Order $order): TemplatedEmail
    {
        $subject = \sprintf('Rappel : ta commande chez %s, c\'est demain', $order->getShop()->getName());

        return $this->customerEmail($order->getShop(), NotificationType::OrderReminder, $subject, $this->orderContext($order, Actor::System));
    }

    public function saleOpened(Sale $sale, Customer $customer): TemplatedEmail
    {
        $shop = $sale->getShop();
        $subject = \sprintf('%s ouvre ses commandes pour %s 🌾', $shop->getName(), $this->formatter->dateLong($sale->getDeliveryDate()));
        $unsubscribeUrl = $this->links->unsubscribe($shop, $customer);

        $email = $this->customerEmail($shop, NotificationType::SaleOpened, $subject, [
            'sale' => $sale,
            'shop' => $shop,
            'customer' => $customer,
            'sale_url' => $this->links->sale($sale),
            'shop_url' => $this->links->shop($shop),
            'unsubscribe_url' => $unsubscribeUrl,
        ]);

        // One-click unsubscribe from the announcements (RFC 2369 / RFC 8058).
        $email->getHeaders()
            ->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>')
            ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        return $email;
    }

    /**
     * Leftovers put back on sale (SPEC §21): the same one-click unsubscribe as the announcements.
     */
    public function lastMinuteSale(Sale $sale, Customer $customer): TemplatedEmail
    {
        $shop = $sale->getShop();
        $subject = \sprintf('Dernière minute chez %s : il reste quelques produits 🧺', $shop->getName());
        $unsubscribeUrl = $this->links->unsubscribe($shop, $customer);

        $email = $this->customerEmail($shop, NotificationType::LastMinuteSale, $subject, [
            'sale' => $sale,
            'shop' => $shop,
            'customer' => $customer,
            'offers' => $sale->getOrderableOffers(),
            'sale_url' => $this->links->sale($sale),
            'shop_url' => $this->links->shop($shop),
            'unsubscribe_url' => $unsubscribeUrl,
        ]);

        $email->getHeaders()
            ->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>')
            ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        return $email;
    }

    public function saleClosed(Sale $sale, User $producer): TemplatedEmail
    {
        $subject = \sprintf('Commandes closes : livraison du %s', $this->formatter->dateLong($sale->getDeliveryDate()));

        return $this->email(NotificationType::SaleClosed, $subject, [
            'sale' => $sale,
            'shop' => $sale->getShop(),
            'producer' => $producer,
            'summary' => $this->summaryBuilder->build($sale),
            'sale_url' => $this->links->proSale($sale),
        ]);
    }

    /**
     * @param list<Order> $orders the customer's active orders in the cancelled sale
     */
    public function saleCancelled(Sale $sale, Customer $customer, array $orders): TemplatedEmail
    {
        $shop = $sale->getShop();
        $subject = \sprintf('%s annule la livraison du %s', $shop->getName(), $this->formatter->dateLong($sale->getDeliveryDate()));

        return $this->customerEmail($shop, NotificationType::SaleCancelled, $subject, [
            'sale' => $sale,
            'shop' => $shop,
            'customer' => $customer,
            'orders' => $orders,
            'shop_url' => $this->links->shop($shop),
        ]);
    }

    /**
     * Reminder to the producer: their free solidarity period ends soon.
     */
    public function solidarityRenewal(ShopSubscription $subscription, User $producer): TemplatedEmail
    {
        return $this->email(NotificationType::SolidarityRenewal, 'Ton palier solidaire arrive à échéance 🌾', [
            'shop' => $subscription->getShop(),
            'producer' => $producer,
            'ends_at' => $subscription->getCurrentPeriodEnd(),
            'billing_url' => $this->links->proBilling(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderContext(Order $order, Actor $actor): array
    {
        $shop = $order->getShop();

        return [
            'order' => $order,
            'shop' => $shop,
            'customer' => $order->getCustomer(),
            'delivery' => $order->getSaleDelivery(),
            'actor' => $actor->value,
            'editable_until' => $order->isCustomerEditable($this->clock->now()) ? $order->getSale()->getEffectiveEditCutoffAt() : null,
            'order_url' => $this->links->order($order),
            'shop_url' => $this->links->shop($shop),
        ];
    }

    /**
     * An e-mail to a customer, sent on behalf of the shop.
     *
     * @param array<string, mixed> $context
     */
    private function customerEmail(Shop $shop, NotificationType $type, string $subject, array $context): TemplatedEmail
    {
        $email = $this->email($type, $subject, $context)
            ->from(new Address(Address::create($this->mailerFrom)->getAddress(), \sprintf('%s via %s', $shop->getName(), $this->instance->name)));

        // The shop's public address: its contact e-mail, or its owner's (never a member's own address).
        $replyTo = $shop->publicEmail();
        if (null !== $replyTo && '' !== $replyTo) {
            $email->replyTo(new Address($replyTo, $shop->getName()));
        }

        return $email;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function email(NotificationType $type, string $subject, array $context): TemplatedEmail
    {
        return new TemplatedEmail()
            ->subject($subject)
            ->htmlTemplate(\sprintf('notification/email/%s.html.twig', $type->value))
            ->textTemplate(\sprintf('notification/email/%s.txt.twig', $type->value))
            ->context($context + ['subject' => $subject]);
    }
}
