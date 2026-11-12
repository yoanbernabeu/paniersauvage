<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Repository\StripeEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Stripe webhook event that was processed. Makes the webhook idempotent (Stripe may deliver
 * an event several times) and lets it drop events that arrive after a more recent one about
 * the same subscription.
 */
#[ORM\Entity(repositoryClass: StripeEventRepository::class)]
#[ORM\Index(name: 'idx_stripe_event_subscription', columns: ['stripe_subscription_id', 'event_created_at'])]
final class StripeEvent
{
    public function __construct(
        /** Stripe event id (`evt_…`). */
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private string $id,
        #[ORM\Column(length: 100)]
        private string $type,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $stripeSubscriptionId,
        /** Unix timestamp given by Stripe (`created`): when the event happened, not when it arrived. */
        #[ORM\Column]
        private int $eventCreatedAt,
        #[ORM\Column]
        private \DateTimeImmutable $processedAt,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function getEventCreatedAt(): int
    {
        return $this->eventCreatedAt;
    }

    public function getProcessedAt(): \DateTimeImmutable
    {
        return $this->processedAt;
    }
}
