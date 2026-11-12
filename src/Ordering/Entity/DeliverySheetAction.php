<?php

declare(strict_types=1);

namespace App\Ordering\Entity;

use App\Ordering\Enum\SheetActionOutcome;
use App\Ordering\Enum\SheetActionType;
use App\Ordering\Repository\DeliverySheetActionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An action of the delivery sheet already processed, under the identifier the browser gave it
 * (SPEC §20, lot F). The sheet queues its actions while offline and replays them: a replay finds its
 * row here and gets the same outcome back, so it never changes the order twice. The primary key is
 * the last line of defence against two concurrent replays.
 */
#[ORM\Entity(repositoryClass: DeliverySheetActionRepository::class)]
#[ORM\Table(name: 'delivery_sheet_action')]
#[ORM\Index(name: 'idx_delivery_sheet_action_order', columns: ['order_id'])]
final readonly class DeliverySheetAction
{
    public function __construct(
        /** Identifier chosen by the browser (UUID). */
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\ManyToOne(targetEntity: Order::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Order $order,
        #[ORM\Column(length: 10, enumType: SheetActionType::class)]
        private SheetActionType $type,
        #[ORM\Column(length: 10, enumType: SheetActionOutcome::class)]
        private SheetActionOutcome $outcome,
        /** Why the action was refused (conflict only). */
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $message,
        #[ORM\Column]
        private \DateTimeImmutable $processedAt,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getType(): SheetActionType
    {
        return $this->type;
    }

    public function getOutcome(): SheetActionOutcome
    {
        return $this->outcome;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getProcessedAt(): \DateTimeImmutable
    {
        return $this->processedAt;
    }
}
