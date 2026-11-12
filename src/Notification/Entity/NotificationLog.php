<?php

declare(strict_types=1);

namespace App\Notification\Entity;

use App\Customer\Entity\Customer;
use App\Notification\Enum\NotificationChannel;
use App\Notification\Enum\NotificationStatus;
use App\Notification\Repository\NotificationLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace of a notification: what was sent, to whom, about which object.
 */
#[ORM\Entity(repositoryClass: NotificationLogRepository::class)]
#[ORM\Index(name: 'idx_notification_log_related', columns: ['related_type', 'related_id', 'type'])]
#[ORM\Index(name: 'idx_notification_log_customer', columns: ['customer_id', 'sent_at'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_log_dedup_key', columns: ['dedup_key'])]
final class NotificationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Address the message was sent to. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $recipient;

    /**
     * Logs are written by NotificationSender through the connection; this constructor serves tests and tools.
     */
    public function __construct(
        /** Free identifier of the message, e.g. "order_placed" or "sale_opened". */
        #[ORM\Column(length: 60)]
        private string $type,
        #[ORM\Column]
        private \DateTimeImmutable $sentAt,
        /** Null when the recipient is a producer. */
        #[ORM\ManyToOne(targetEntity: Customer::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Customer $customer = null,
        /** Kind of object the message is about, e.g. "order" or "sale". */
        #[ORM\Column(length: 60, nullable: true)]
        private ?string $relatedType = null,
        #[ORM\Column(nullable: true)]
        private ?int $relatedId = null,
        #[ORM\Column(length: 20, enumType: NotificationStatus::class)]
        private NotificationStatus $status = NotificationStatus::Sent,
        #[ORM\Column(length: 20, enumType: NotificationChannel::class)]
        private NotificationChannel $channel = NotificationChannel::Email,
        ?string $recipient = null,
        /**
         * Set on a message sent once per object and address (NotificationSender::dedupKey()), cleared when
         * it fails: the unique constraint stops a second send of the same message.
         */
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $dedupKey = null,
    ) {
        $this->recipient = $recipient ?? $this->customer?->getEmail();
    }

    public function getDedupKey(): ?string
    {
        return $this->dedupKey;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getStatus(): NotificationStatus
    {
        return $this->status;
    }

    public function setStatus(NotificationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRecipient(): ?string
    {
        return $this->recipient;
    }

    public function getRelatedType(): ?string
    {
        return $this->relatedType;
    }

    public function getRelatedId(): ?int
    {
        return $this->relatedId;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
