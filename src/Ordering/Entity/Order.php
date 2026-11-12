<?php

declare(strict_types=1);

namespace App\Ordering\Entity;

use App\Customer\Entity\Customer;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Enum\OrderSource;
use App\Ordering\Enum\OrderStatus;
use App\Ordering\Repository\OrderRepository;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Shared\Enum\Actor;
use App\Shop\Entity\Shop;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A customer's pre-order in a sale. No payment is ever recorded here (SPEC §1):
 * `totalCents` is only the amount the customer will settle directly with the producer.
 */
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'customer_order')]
#[ORM\UniqueConstraint(name: 'uniq_customer_order_reference', columns: ['reference'])]
#[ORM\Index(name: 'idx_customer_order_sale_status', columns: ['sale_id', 'status'])]
#[ORM\Index(name: 'idx_customer_order_customer', columns: ['customer_id', 'created_at'])]
#[ORM\Index(name: 'idx_customer_order_sale_delivery', columns: ['sale_delivery_id'])]
final class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::Confirmed;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalCents = 0;

    /**
     * The shop was under the franchise en base de TVA when the order was placed (art. 293 B du CGI):
     * its lines are frozen at 0 % and it carries the legal mention, whatever the regime later.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $vatExempt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $note = null;

    /** Typed by the customer when the delivery is a home delivery. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $deliveryAddress = null;

    /** Where the customer came from (`?via=` of the URL, never a cookie); null when unknown. */
    #[ORM\Column(length: 20, nullable: true, enumType: OrderSource::class)]
    private ?OrderSource $source = null;

    /*
     * Who the order is for, as typed when it was placed. Frozen on the order so that typing the
     * e-mail address of an existing account never reveals (nor changes) that account's profile.
     */
    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $customerFirstName = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $customerLastName = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $customerPhone = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, OrderLine> */
    #[ORM\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, OrderEvent> */
    #[ORM\OneToMany(targetEntity: OrderEvent::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $events;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Sale::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Sale $sale,
        #[ORM\ManyToOne(targetEntity: Customer::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Customer $customer,
        #[ORM\ManyToOne(targetEntity: SaleDelivery::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private SaleDelivery $saleDelivery,
        #[ORM\Column(length: 20)]
        private string $reference,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->vatExempt = $this->sale->getShop()->isVatExempt();
        $this->setCustomerIdentity($this->customer->getFirstName(), $this->customer->getLastName(), $this->customer->getPhone());
        $this->updatedAt = $this->createdAt;
        $this->lines = new ArrayCollection();
        $this->events = new ArrayCollection();
    }

    /**
     * Sums the lines into `totalCents`. Lines call it themselves when they change.
     */
    public function recomputeTotal(): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            $total += $line->getTotalCents();
        }

        return $this->totalCents = $total;
    }

    /**
     * Adds an entry to the order's history and returns it.
     *
     * @param array<string, mixed> $detail
     */
    public function recordEvent(OrderEventType $type, Actor $actor, array $detail, \DateTimeImmutable $at): OrderEvent
    {
        return new OrderEvent($this, $type, $actor, $detail, $at);
    }

    public function touch(\DateTimeImmutable $now): static
    {
        $this->updatedAt = $now;

        return $this;
    }

    public function isConfirmed(): bool
    {
        return OrderStatus::Confirmed === $this->status;
    }

    public function isDelivered(): bool
    {
        return OrderStatus::Delivered === $this->status;
    }

    public function isCancelled(): bool
    {
        return OrderStatus::Cancelled === $this->status;
    }

    /**
     * Not cancelled: counts in production sheets and holds reserved stock.
     */
    public function isActive(): bool
    {
        return OrderStatus::Cancelled !== $this->status;
    }

    public function isCustomerEditable(\DateTimeImmutable $now): bool
    {
        return $this->isConfirmed() && $this->sale->isCustomerEditable($now);
    }

    /**
     * confirmed or not delivered → delivered (ticked on the delivery sheet).
     *
     * @throws \DomainException when the order is cancelled or already delivered
     */
    public function markDelivered(): static
    {
        return $this->transition([OrderStatus::Confirmed, OrderStatus::NotDelivered], OrderStatus::Delivered);
    }

    /**
     * confirmed or delivered → not delivered (the customer did not come).
     *
     * @throws \DomainException when the order is cancelled or already marked not delivered
     */
    public function markNotDelivered(): static
    {
        return $this->transition([OrderStatus::Confirmed, OrderStatus::Delivered], OrderStatus::NotDelivered);
    }

    /**
     * delivered or not delivered → back to confirmed (a tick undone on the delivery sheet).
     *
     * @throws \DomainException when the order is not marked delivered or not delivered
     */
    public function reconfirm(): static
    {
        return $this->transition([OrderStatus::Delivered, OrderStatus::NotDelivered], OrderStatus::Confirmed);
    }

    /**
     * Any active status → cancelled. Use `isCancelled()` in Twig: `order.cancel` would call this method.
     *
     * @throws \DomainException when the order is already cancelled
     */
    public function cancel(): static
    {
        return $this->transition([OrderStatus::Confirmed, OrderStatus::Delivered, OrderStatus::NotDelivered], OrderStatus::Cancelled);
    }

    /**
     * @param list<OrderStatus> $from
     */
    private function transition(array $from, OrderStatus $to): static
    {
        if (!\in_array($this->status, $from, true)) {
            throw new \DomainException(\sprintf('Order "%s" cannot go from "%s" to "%s".', $this->reference, $this->status->value, $to->value));
        }

        $this->status = $to;

        return $this;
    }

    public function getLineFor(SaleOffer $offer): ?OrderLine
    {
        foreach ($this->lines as $line) {
            if ($line->getSaleOffer() === $offer) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Holds an alcoholic drink: the producer checks the customer's age at pick-up.
     */
    public function containsAlcohol(): bool
    {
        return $this->lines->exists(static fn (int $key, OrderLine $line): bool => $line->getSaleOffer()->getProduct()->isAlcoholic());
    }

    /**
     * Holds a product that is not perishable: the customer has a 14-day right of withdrawal on it.
     */
    public function hasWithdrawableItems(): bool
    {
        return $this->lines->exists(static fn (int $key, OrderLine $line): bool => !$line->getSaleOffer()->getProduct()->isPerishable());
    }

    /**
     * Total number of items, all lines together: pieces and bunches, one per product sold by weight.
     */
    public function getItemCount(): int
    {
        $count = 0;
        foreach ($this->lines as $line) {
            $count += $line->getItemCount();
        }

        return $count;
    }

    /**
     * At least one product sold by weight is not weighed yet: the total is an estimate ("environ").
     */
    public function isEstimated(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->isEstimated()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Placed under the franchise en base de TVA: no VAT, mention VatRegime::FRANCHISE_MENTION.
     */
    public function isVatExempt(): bool
    {
        return $this->vatExempt;
    }

    public function getShop(): Shop
    {
        return $this->sale->getShop();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getSale(): Sale
    {
        return $this->sale;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    /**
     * Freezes who the order is for. Defaults to the customer's profile when the order is built.
     */
    public function setCustomerIdentity(?string $firstName, ?string $lastName, ?string $phone): static
    {
        $this->customerFirstName = $this->clean($firstName);
        $this->customerLastName = $this->clean($lastName);
        $this->customerPhone = $this->clean($phone);

        return $this;
    }

    public function getCustomerFirstName(): ?string
    {
        return $this->customerFirstName;
    }

    public function getCustomerLastName(): ?string
    {
        return $this->customerLastName;
    }

    public function getCustomerPhone(): ?string
    {
        return $this->customerPhone;
    }

    /**
     * "Prénom Nom" as typed on the order, or the customer's display name (e-mail address, phone of
     * a customer without e-mail) when no name was given.
     */
    public function getCustomerDisplayName(): string
    {
        $name = trim(($this->customerFirstName ?? '').' '.($this->customerLastName ?? ''));

        return '' === $name ? $this->customer->getDisplayName() : $name;
    }

    /**
     * Hands the order over to another customer: a customer without e-mail merged into the account
     * of the address the producer added (App\Customer\Service\OfflineCustomerManager).
     */
    public function transferTo(Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getSaleDelivery(): SaleDelivery
    {
        return $this->saleDelivery;
    }

    public function setSaleDelivery(SaleDelivery $saleDelivery): static
    {
        $this->saleDelivery = $saleDelivery;

        return $this;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function getSource(): ?OrderSource
    {
        return $this->source;
    }

    public function setSource(?OrderSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getDeliveryAddress(): ?string
    {
        return $this->deliveryAddress;
    }

    public function setDeliveryAddress(?string $deliveryAddress): static
    {
        $this->deliveryAddress = $deliveryAddress;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, OrderLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(OrderLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }
        $this->recomputeTotal();

        return $this;
    }

    public function removeLine(OrderLine $line): static
    {
        $this->lines->removeElement($line);
        $this->recomputeTotal();

        return $this;
    }

    /**
     * @return Collection<int, OrderEvent>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    /**
     * @internal use recordEvent()
     */
    public function addEvent(OrderEvent $event): static
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
        }

        return $this;
    }

    private function clean(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }
}
