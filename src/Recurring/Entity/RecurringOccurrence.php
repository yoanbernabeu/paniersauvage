<?php

declare(strict_types=1);

namespace App\Recurring\Entity;

use App\Catalog\Enum\ProductUnit;
use App\Ordering\Entity\Order;
use App\Recurring\Enum\OccurrenceOutcome;
use App\Recurring\Repository\RecurringOccurrenceRepository;
use App\Sale\Entity\Sale;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the generation did for one subscription in one sale. The unique constraint makes the
 * generation idempotent: one row (hence at most one order) per subscription and per sale, whatever
 * the number of runs, even concurrent ones.
 */
#[ORM\Entity(repositoryClass: RecurringOccurrenceRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_recurring_occurrence_sale', columns: ['recurring_order_id', 'sale_id'])]
#[ORM\Index(name: 'idx_recurring_occurrence_sale', columns: ['sale_id'])]
#[ORM\Index(name: 'idx_recurring_occurrence_order', columns: ['order_id'])]
final class RecurringOccurrence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Copy of the delivery day of the sale, to sort and match the paused days. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $deliveryDate;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Order $order = null;

    /**
     * What could not be given: product label, quantity wanted and quantity given (thousandths), unit.
     *
     * @var list<array{label: string, wanted: int, given: int, unit: string}>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $missing = [];

    public function __construct(
        #[ORM\ManyToOne(targetEntity: RecurringOrder::class, inversedBy: 'occurrences')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private RecurringOrder $recurringOrder,
        #[ORM\ManyToOne(targetEntity: Sale::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Sale $sale,
        #[ORM\Column(length: 20, enumType: OccurrenceOutcome::class)]
        private OccurrenceOutcome $outcome,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->deliveryDate = $sale->getDeliveryDate();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecurringOrder(): RecurringOrder
    {
        return $this->recurringOrder;
    }

    public function getSale(): Sale
    {
        return $this->sale;
    }

    public function getDeliveryDate(): \DateTimeImmutable
    {
        return $this->deliveryDate;
    }

    public function getOutcome(): OccurrenceOutcome
    {
        return $this->outcome;
    }

    public function setOutcome(OccurrenceOutcome $outcome): static
    {
        $this->outcome = $outcome;

        return $this;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): static
    {
        $this->order = $order;

        return $this;
    }

    /**
     * @return list<array{label: string, wanted: int, given: int, unit: string}>
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    /**
     * The missing products with their unit, for display.
     *
     * @return list<array{label: string, wanted: int, given: int, unit: ProductUnit}>
     */
    public function getMissingItems(): array
    {
        return array_map(static fn (array $item): array => ['unit' => ProductUnit::tryFrom($item['unit']) ?? ProductUnit::Piece] + $item, $this->missing);
    }

    /**
     * @param list<array{label: string, wanted: int, given: int, unit: string}> $missing
     */
    public function setMissing(array $missing): static
    {
        $this->missing = $missing;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
