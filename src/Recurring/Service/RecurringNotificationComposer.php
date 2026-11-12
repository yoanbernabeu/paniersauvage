<?php

declare(strict_types=1);

namespace App\Recurring\Service;

use App\Notification\Enum\NotificationType;
use App\Notification\Service\NotificationLinks;
use App\Ordering\Entity\Order;
use App\Recurring\Entity\RecurringOccurrence;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Enum\OccurrenceOutcome;
use App\Recurring\Enum\RecurringEndReason;
use App\Sale\Entity\Sale;
use App\Shared\Service\FrenchFormatter;
use App\Shop\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;

/**
 * Builds the subscription e-mails (templates `notification/email/recurring_*.{html,txt}.twig`).
 * Like every e-mail to a customer, sent on behalf of the shop: « <Boutique> via Panier Sauvage »,
 * replies to the producer.
 */
final readonly class RecurringNotificationComposer
{
    public function __construct(
        private RecurringLinks $links,
        private NotificationLinks $notificationLinks,
        private RecurringCalendar $calendar,
        private FrenchFormatter $formatter,
        private ClockInterface $clock,
        #[Autowire(env: 'MAILER_FROM')]
        private string $mailerFrom,
    ) {
    }

    public function started(RecurringOrder $recurringOrder): TemplatedEmail
    {
        $plan = $recurringOrder->getPlan();
        $subject = \sprintf('Ton abonnement « %s » chez %s est enregistré 🧺', $plan->getTitle(), $plan->getShop()->getName());

        return $this->customerEmail($recurringOrder, NotificationType::RecurringStarted, $subject, [
            'upcoming' => \array_slice($this->calendar->upcoming($recurringOrder, $this->clock->now()), 0, 3),
        ]);
    }

    public function generated(RecurringOccurrence $occurrence): TemplatedEmail
    {
        $recurringOrder = $occurrence->getRecurringOrder();
        $shopName = $recurringOrder->getShop()->getName();
        $date = $this->formatter->dateLong($occurrence->getDeliveryDate());
        $subject = match ($occurrence->getOutcome()) {
            OccurrenceOutcome::Placed => \sprintf('Ta commande du %s chez %s est prête 🧺', $date, $shopName),
            OccurrenceOutcome::Partial => \sprintf('Ta commande du %s chez %s est incomplète', $date, $shopName),
            default => \sprintf('Pas de commande le %s chez %s', $date, $shopName),
        };

        $order = $occurrence->getOrder();
        $now = $this->clock->now();

        return $this->customerEmail($recurringOrder, NotificationType::RecurringOrderGenerated, $subject, [
            'occurrence' => $occurrence,
            'outcome' => $occurrence->getOutcome()->value,
            'order' => $order,
            'delivery' => $order?->getSaleDelivery(),
            'editable_until' => $order instanceof Order && $order->isCustomerEditable($now) ? $order->getSale()->getEffectiveEditCutoffAt() : null,
            'order_url' => $order instanceof Order ? $this->notificationLinks->order($order) : null,
        ]);
    }

    public function suspended(RecurringOrder $recurringOrder): TemplatedEmail
    {
        $subject = \sprintf('%s a suspendu ton abonnement', $recurringOrder->getShop()->getName());

        return $this->customerEmail($recurringOrder, NotificationType::RecurringSuspended, $subject, []);
    }

    public function ended(RecurringOrder $recurringOrder): TemplatedEmail
    {
        $plan = $recurringOrder->getPlan();
        $subject = RecurringEndReason::Producer === $recurringOrder->getEndReason()
            ? \sprintf('%s a mis fin à ton abonnement', $plan->getShop()->getName())
            : \sprintf('Ton abonnement « %s » chez %s est terminé', $plan->getTitle(), $plan->getShop()->getName());

        return $this->customerEmail($recurringOrder, NotificationType::RecurringEnded, $subject, [
            'reason' => $recurringOrder->getEndReason()?->value,
        ]);
    }

    /**
     * @param non-empty-list<RecurringOccurrence> $occurrences
     */
    public function shortages(Sale $sale, User $producer, array $occurrences): TemplatedEmail
    {
        $subject = \sprintf('Abonnés : commandes incomplètes pour le %s', $this->formatter->dateLong($sale->getDeliveryDate()));

        return $this->email(NotificationType::RecurringShortages, $subject, [
            'sale' => $sale,
            'shop' => $sale->getShop(),
            'producer' => $producer,
            'occurrences' => $occurrences,
            'pro_url' => $this->links->proIndex(),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function customerEmail(RecurringOrder $recurringOrder, NotificationType $type, string $subject, array $context): TemplatedEmail
    {
        $shop = $recurringOrder->getShop();
        $email = $this->email($type, $subject, $context + [
            'recurring_order' => $recurringOrder,
            'plan' => $recurringOrder->getPlan(),
            'shop' => $shop,
            'today' => $this->clock->now(),
            'customer' => $recurringOrder->getCustomer(),
            'recurring_url' => $this->links->subscription($recurringOrder),
            'shop_url' => $this->notificationLinks->shop($shop),
        ])->from(new Address(Address::create($this->mailerFrom)->getAddress(), \sprintf('%s via Panier Sauvage', $shop->getName())));

        $producer = $shop->getUsers()->first();
        if ($producer instanceof User && '' !== $producer->getEmail()) {
            $email->replyTo(new Address($producer->getEmail(), $shop->getName()));
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
