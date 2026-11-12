<?php

declare(strict_types=1);

namespace App\Notification\EventListener;

use App\Billing\Event\SolidarityPlanEnding;
use App\Notification\Enum\NotificationType;
use App\Notification\Service\NotificationComposer;
use App\Notification\Service\NotificationSender;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mime\Address;

/**
 * Subscription e-mails to producers: renewal reminder of a free solidarity period.
 */
final readonly class BillingNotificationListener
{
    public const string RELATED_TYPE = 'shop_subscription';

    public function __construct(
        private NotificationSender $sender,
        private NotificationComposer $composer,
    ) {
    }

    /**
     * One reminder per producer of the shop. Billing dispatches it once per free period.
     */
    #[AsEventListener]
    public function onSolidarityPlanEnding(SolidarityPlanEnding $event): void
    {
        $subscription = $event->subscription;

        $this->sender->guard(NotificationType::SolidarityRenewal, function () use ($subscription): void {
            foreach ($subscription->getShop()->getUsers() as $producer) {
                $this->sender->send(
                    NotificationType::SolidarityRenewal,
                    new Address($producer->getEmail(), $producer->getFullName()),
                    self::RELATED_TYPE,
                    $subscription->getId(),
                    fn (): TemplatedEmail => $this->composer->solidarityRenewal($subscription, $producer),
                );
            }
        });
    }
}
