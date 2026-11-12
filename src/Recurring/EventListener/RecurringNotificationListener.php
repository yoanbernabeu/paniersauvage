<?php

declare(strict_types=1);

namespace App\Recurring\EventListener;

use App\Notification\Enum\NotificationType;
use App\Notification\Service\NotificationSender;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Enum\RecurringEndReason;
use App\Recurring\Event\RecurringOrderEnded;
use App\Recurring\Event\RecurringOrderGenerated;
use App\Recurring\Event\RecurringOrderStarted;
use App\Recurring\Event\RecurringOrderSuspended;
use App\Recurring\Event\RecurringShortagesDetected;
use App\Recurring\Service\RecurringNotificationComposer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mime\Address;

/**
 * Subscription e-mails (SPEC §6 and « lot H »): confirmation, each generated order (or why there is
 * none), suspension and end by the producer, end of contract; summary of the shortages to the
 * producer. A subscriber always has an e-mail address (App\Recurring\Entity\RecurringOrder).
 * Nothing is sent when the subscriber cancels themselves: the page confirms it.
 */
final readonly class RecurringNotificationListener
{
    public const string RELATED_TYPE = 'recurring_order';
    public const string RELATED_TYPE_OCCURRENCE = 'recurring_occurrence';
    public const string RELATED_TYPE_SALE = 'sale';

    public function __construct(
        private NotificationSender $sender,
        private RecurringNotificationComposer $composer,
    ) {
    }

    #[AsEventListener]
    public function onStarted(RecurringOrderStarted $event): void
    {
        $recurringOrder = $event->recurringOrder;

        $this->notify(NotificationType::RecurringStarted, $recurringOrder, self::RELATED_TYPE, $recurringOrder->getId(), fn (): TemplatedEmail => $this->composer->started($recurringOrder), once: true);
    }

    /**
     * Once per occurrence, even if the event is dispatched again.
     */
    #[AsEventListener]
    public function onGenerated(RecurringOrderGenerated $event): void
    {
        $occurrence = $event->occurrence;

        $this->notify(NotificationType::RecurringOrderGenerated, $occurrence->getRecurringOrder(), self::RELATED_TYPE_OCCURRENCE, $occurrence->getId(), fn (): TemplatedEmail => $this->composer->generated($occurrence), once: true);
    }

    #[AsEventListener]
    public function onSuspended(RecurringOrderSuspended $event): void
    {
        $recurringOrder = $event->recurringOrder;

        $this->notify(NotificationType::RecurringSuspended, $recurringOrder, self::RELATED_TYPE, $recurringOrder->getId(), fn (): TemplatedEmail => $this->composer->suspended($recurringOrder));
    }

    #[AsEventListener]
    public function onEnded(RecurringOrderEnded $event): void
    {
        $recurringOrder = $event->recurringOrder;
        if (RecurringEndReason::Customer === $recurringOrder->getEndReason()) {
            return;
        }

        $this->notify(NotificationType::RecurringEnded, $recurringOrder, self::RELATED_TYPE, $recurringOrder->getId(), fn (): TemplatedEmail => $this->composer->ended($recurringOrder), once: true);
    }

    #[AsEventListener]
    public function onShortages(RecurringShortagesDetected $event): void
    {
        $sale = $event->sale;

        $this->sender->guard(NotificationType::RecurringShortages, function () use ($sale, $event): void {
            foreach ($sale->getShop()->getUsers() as $producer) {
                $this->sender->send(
                    NotificationType::RecurringShortages,
                    new Address($producer->getEmail(), $producer->getFullName()),
                    self::RELATED_TYPE_SALE,
                    $sale->getId(),
                    fn (): TemplatedEmail => $this->composer->shortages($sale, $producer, $event->occurrences),
                );
            }
        });
    }

    private function notify(NotificationType $type, RecurringOrder $recurringOrder, string $relatedType, ?int $relatedId, \Closure $compose, bool $once = false): void
    {
        $customer = $recurringOrder->getCustomer();
        $email = $customer->getEmail();
        if (null === $email) {
            return;
        }

        $this->sender->send($type, new Address($email, $customer->getDisplayName()), $relatedType, $relatedId, $compose, $customer, $once);
    }
}
