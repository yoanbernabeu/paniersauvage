<?php

declare(strict_types=1);

namespace App\Ordering\Entity;

use App\Ordering\Enum\OrderEventType;
use App\Ordering\Repository\OrderEventRepository;
use App\Shared\Enum\Actor;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entry of an order's history: who did what, and when.
 */
#[ORM\Entity(repositoryClass: OrderEventRepository::class)]
#[ORM\Index(name: 'idx_order_event_order', columns: ['order_id', 'created_at'])]
final class OrderEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(#[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'events')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Order $order, #[ORM\Column(length: 30, enumType: OrderEventType::class)]
        private OrderEventType $type, #[ORM\Column(length: 20, enumType: Actor::class)]
        private Actor $actor, #[ORM\Column]
        private array $detail, #[ORM\Column]
        private \DateTimeImmutable $createdAt)
    {
        $this->order->addEvent($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getType(): OrderEventType
    {
        return $this->type;
    }

    public function getActor(): Actor
    {
        return $this->actor;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetail(): array
    {
        return $this->detail;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
