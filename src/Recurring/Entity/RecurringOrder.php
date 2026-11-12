<?php

declare(strict_types=1);

namespace App\Recurring\Entity;

use App\Catalog\Entity\Product;
use App\Customer\Entity\Customer;
use App\Delivery\Entity\DeliveryPoint;
use App\Recurring\Enum\RecurringEndReason;
use App\Recurring\Enum\RecurringOrderStatus;
use App\Recurring\Repository\RecurringOrderRepository;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer's subscription to a recurring plan: at each delivery of the recurrence, an order is
 * generated for them (App\Recurring\Service\RecurringOrderGenerator). No payment is ever recorded
 * (SPEC §1): the amount is settled with the producer at each pick-up.
 *
 * Paused deliveries are kept as days (`Y-m-d`), so that a subscriber can plan their holidays before
 * the sales of those weeks exist.
 */
#[ORM\Entity(repositoryClass: RecurringOrderRepository::class)]
#[ORM\Table(name: 'recurring_order')]
// One subscription in progress per customer and plan; ended ones are kept as history.
#[ORM\UniqueConstraint(name: 'uniq_recurring_order_in_progress', columns: ['plan_id', 'customer_id'], options: ['where' => "((status)::text <> 'ended'::text)"])]
#[ORM\Index(name: 'idx_recurring_order_plan_status', columns: ['plan_id', 'status'])]
#[ORM\Index(name: 'idx_recurring_order_customer', columns: ['customer_id'])]
final class RecurringOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: RecurringOrderStatus::class)]
    private RecurringOrderStatus $status = RecurringOrderStatus::Active;

    /** Baskets at each delivery (basket mode). */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $shares = 1;

    /** Typed by the subscriber when the pick-up point is a home delivery. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $deliveryAddress = null;

    /** Last delivery day covered; null = until someone stops it. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsOn;

    /** Set as soon as the end is decided (cancellation with notice, end of contract, producer). */
    #[ORM\Column(length: 20, nullable: true, enumType: RecurringEndReason::class)]
    private ?RecurringEndReason $endReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    /** "I am 18 or over", ticked when the plan offers an alcoholic drink (SPEC §12). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $adultCertifiedAt = null;

    /** @var list<string> paused delivery days, `Y-m-d`, sorted */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $pausedDates = [];

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, RecurringOrderLine> */
    #[ORM\OneToMany(targetEntity: RecurringOrderLine::class, mappedBy: 'recurringOrder', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, RecurringOccurrence> */
    #[ORM\OneToMany(targetEntity: RecurringOccurrence::class, mappedBy: 'recurringOrder')]
    #[ORM\OrderBy(['deliveryDate' => 'DESC', 'id' => 'DESC'])]
    private Collection $occurrences;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: RecurringPlan::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private RecurringPlan $plan,
        #[ORM\ManyToOne(targetEntity: Customer::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Customer $customer,
        #[ORM\ManyToOne(targetEntity: DeliveryPoint::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private DeliveryPoint $deliveryPoint,
        /** First delivery day covered. */
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $startsOn,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        if (!$customer->hasEmail()) {
            throw new \InvalidArgumentException('Only a customer with an e-mail address can subscribe.');
        }
        if ($deliveryPoint->getShop() !== $plan->getShop()) {
            throw new \InvalidArgumentException('The pick-up point must belong to the shop of the plan.');
        }

        $this->startsOn = $startsOn->setTime(0, 0);
        $this->endsOn = $plan->getSeasonEndsOn();
        $this->updatedAt = $createdAt;
        $this->lines = new ArrayCollection();
        $this->occurrences = new ArrayCollection();
    }

    /**
     * Is this delivery day within the subscription (from its first to its last day)?
     */
    public function coversDate(\DateTimeImmutable $day): bool
    {
        $date = $day->format('Y-m-d');

        return $date >= $this->startsOn->format('Y-m-d') && (!$this->endsOn instanceof \DateTimeImmutable || $date <= $this->endsOn->format('Y-m-d'));
    }

    public function isPausedOn(\DateTimeImmutable $day): bool
    {
        return \in_array($day->format('Y-m-d'), $this->pausedDates, true);
    }

    public function pause(\DateTimeImmutable $day, \DateTimeImmutable $now): static
    {
        if (!$this->isPausedOn($day)) {
            $this->pausedDates[] = $day->format('Y-m-d');
            sort($this->pausedDates);
            $this->updatedAt = $now;
        }

        return $this;
    }

    public function unpause(\DateTimeImmutable $day, \DateTimeImmutable $now): static
    {
        $this->pausedDates = array_values(array_diff($this->pausedDates, [$day->format('Y-m-d')]));
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * Is there room for one more pause (rule of the plan)?
     */
    public function canPauseAgain(): bool
    {
        $max = $this->plan->getMaxPauses();

        return null === $max || \count($this->pausedDates) < $max;
    }

    /**
     * What one delivery holds: product and quantity (thousandths), in the order of the plan.
     *
     * @return list<array{Product, int}>
     */
    public function wantedQuantities(): array
    {
        if ($this->plan->isBasket()) {
            return array_values(array_map(fn (RecurringPlanItem $item): array => [$item->getProduct(), $item->getQuantity() * $this->shares], $this->plan->getItems()->toArray()));
        }

        $wanted = [];
        foreach ($this->plan->getItems() as $item) {
            $quantity = $this->getQuantityOf($item->getProduct());
            if ($quantity > 0) {
                $wanted[] = [$item->getProduct(), $quantity];
            }
        }

        return $wanted;
    }

    /**
     * Amount of one delivery at the current prices of the products: indicative only, the order
     * freezes the prices of its sale.
     */
    public function estimatedPriceCents(): int
    {
        $total = 0;
        foreach ($this->wantedQuantities() as [$product, $quantity]) {
            $total += Quantity::priceCents($quantity, $product->getPriceCents());
        }

        return $total;
    }

    /**
     * Deliveries still to come until the last day (paused ones left out); null when open-ended.
     */
    public function remainingDeliveryCount(\DateTimeImmutable $today): ?int
    {
        if (!$this->endsOn instanceof \DateTimeImmutable) {
            return null;
        }

        $dates = $this->plan->deliveryDatesBetween(max($today, $this->startsOn), $this->endsOn);

        return \count(array_filter($dates, fn (\DateTimeImmutable $date): bool => !$this->isPausedOn($date)));
    }

    /**
     * Total of the deliveries still to come, at the current prices: shown for information only
     * (each delivery is settled with the producer at the pick-up, nothing is paid in advance).
     */
    public function remainingTotalCents(\DateTimeImmutable $today): ?int
    {
        $count = $this->remainingDeliveryCount($today);

        return null === $count ? null : $count * $this->estimatedPriceCents();
    }

    public function containsWeighedProducts(): bool
    {
        foreach ($this->wantedQuantities() as [$product]) {
            if ($product->getUnit()->isWeighed()) {
                return true;
            }
        }

        return false;
    }

    public function getQuantityOf(Product $product): int
    {
        foreach ($this->lines as $line) {
            if ($line->getProduct() === $product) {
                return $line->getQuantity();
            }
        }

        return 0;
    }

    /**
     * Quantity of a product for the next deliveries (choice mode); 0 takes it out.
     */
    public function setQuantityOf(Product $product, int $quantity): static
    {
        foreach ($this->lines as $line) {
            if ($line->getProduct() === $product) {
                if ($quantity > 0) {
                    $line->setQuantity($quantity);
                } else {
                    $this->lines->removeElement($line);
                }

                return $this;
            }
        }

        if ($quantity > 0) {
            $this->lines->add(new RecurringOrderLine($this, $product, $quantity));
        }

        return $this;
    }

    public function isActive(): bool
    {
        return RecurringOrderStatus::Active === $this->status;
    }

    public function isSuspended(): bool
    {
        return RecurringOrderStatus::Suspended === $this->status;
    }

    public function isEnded(): bool
    {
        return RecurringOrderStatus::Ended === $this->status;
    }

    /** The subscriber cancelled; the deliveries of the notice are still due. */
    public function isEnding(): bool
    {
        return !$this->isEnded() && RecurringEndReason::Customer === $this->endReason;
    }

    /**
     * active → suspended (by the producer): no order is generated until resumed.
     *
     * @throws \DomainException when the subscription is not active
     */
    public function suspend(\DateTimeImmutable $now): static
    {
        if (!$this->isActive()) {
            throw new \DomainException('Only an active subscription can be suspended.');
        }

        $this->status = RecurringOrderStatus::Suspended;
        $this->suspendedAt = $now;
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * @throws \DomainException when the subscription is not suspended
     */
    public function resume(\DateTimeImmutable $now): static
    {
        if (!$this->isSuspended()) {
            throw new \DomainException('Only a suspended subscription can be resumed.');
        }

        $this->status = RecurringOrderStatus::Active;
        $this->suspendedAt = null;
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * Decides the last delivery day, the subscription stays in progress until then (notice).
     *
     * @throws \DomainException when it is already ended or already cancelled
     */
    public function cancelOn(\DateTimeImmutable $lastDay, \DateTimeImmutable $now): static
    {
        if ($this->isEnded() || $this->endReason instanceof RecurringEndReason) {
            throw new \DomainException('This subscription is already ending.');
        }

        $this->endsOn = $lastDay->setTime(0, 0);
        $this->endReason = RecurringEndReason::Customer;
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * Ends the subscription now: nothing more is generated.
     *
     * @throws \DomainException when it is already ended
     */
    public function end(RecurringEndReason $reason, \DateTimeImmutable $now): static
    {
        if ($this->isEnded()) {
            throw new \DomainException('This subscription is already ended.');
        }

        $this->status = RecurringOrderStatus::Ended;
        $this->endReason ??= $reason;
        $today = $now->setTime(0, 0);
        if (!$this->endsOn instanceof \DateTimeImmutable || $this->endsOn > $today) {
            $this->endsOn = $today;
        }
        $this->endedAt = $now;
        $this->updatedAt = $now;

        return $this;
    }

    public function getShop(): Shop
    {
        return $this->plan->getShop();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlan(): RecurringPlan
    {
        return $this->plan;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getDeliveryPoint(): DeliveryPoint
    {
        return $this->deliveryPoint;
    }

    public function setDeliveryPoint(DeliveryPoint $deliveryPoint): static
    {
        if ($deliveryPoint->getShop() !== $this->plan->getShop()) {
            throw new \InvalidArgumentException('The pick-up point must belong to the shop of the plan.');
        }
        $this->deliveryPoint = $deliveryPoint;

        return $this;
    }

    public function getStatus(): RecurringOrderStatus
    {
        return $this->status;
    }

    public function getShares(): int
    {
        return $this->shares;
    }

    public function setShares(int $shares): static
    {
        if ($shares < 1) {
            throw new \InvalidArgumentException('At least one basket.');
        }
        $this->shares = $shares;

        return $this;
    }

    public function getDeliveryAddress(): ?string
    {
        return $this->deliveryAddress;
    }

    public function setDeliveryAddress(?string $deliveryAddress): static
    {
        $deliveryAddress = trim($deliveryAddress ?? '');
        $this->deliveryAddress = '' === $deliveryAddress ? null : $deliveryAddress;

        return $this;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): ?\DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function getEndReason(): ?RecurringEndReason
    {
        return $this->endReason;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getSuspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function getAdultCertifiedAt(): ?\DateTimeImmutable
    {
        return $this->adultCertifiedAt;
    }

    public function certifyAdult(\DateTimeImmutable $at): static
    {
        $this->adultCertifiedAt ??= $at;

        return $this;
    }

    /**
     * @return list<string> `Y-m-d`
     */
    public function getPausedDates(): array
    {
        return $this->pausedDates;
    }

    public function touch(\DateTimeImmutable $now): static
    {
        $this->updatedAt = $now;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, RecurringOrderLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    /**
     * Newest first.
     *
     * @return Collection<int, RecurringOccurrence>
     */
    public function getOccurrences(): Collection
    {
        return $this->occurrences;
    }
}
