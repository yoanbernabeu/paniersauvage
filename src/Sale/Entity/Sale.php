<?php

declare(strict_types=1);

namespace App\Sale\Entity;

use App\Catalog\Entity\Product;
use App\Sale\Enum\SaleStatus;
use App\Sale\Repository\SaleRepository;
use App\Shop\Entity\Shop;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One pre-order session: customers order until the deadline, the producer delivers on the delivery date.
 */
#[ORM\Entity(repositoryClass: SaleRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_sale_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_sale_schedule_delivery_date', columns: ['schedule_id', 'delivery_date'])]
#[ORM\Index(name: 'idx_sale_shop_status', columns: ['shop_id', 'status'])]
#[ORM\Index(name: 'idx_sale_shop_delivery_date', columns: ['shop_id', 'delivery_date'])]
#[ORM\Index(name: 'idx_sale_status_opens_at', columns: ['status', 'opens_at'])]
#[ORM\Index(name: 'idx_sale_status_deadline_at', columns: ['status', 'deadline_at'])]
final class Sale implements \Stringable
{
    public const int CODE_LENGTH = 6;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The recurrence that generated this sale; null for a one-off sale. */
    #[ORM\ManyToOne(targetEntity: SaleSchedule::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SaleSchedule $schedule = null;

    #[ORM\Column(length: 20, enumType: SaleStatus::class)]
    private SaleStatus $status = SaleStatus::Scheduled;

    /** Until when customers may edit or cancel; null = until the deadline. */
    #[ORM\Column(nullable: true)]
    #[Assert\LessThanOrEqual(propertyPath: 'deadlineAt', message: 'La limite de modification ne peut pas dépasser la date limite.')]
    private ?\DateTimeImmutable $editCutoffAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $allowCustomerEdit;

    /**
     * Last-minute sale (SPEC §21, lot F): once the sale is closed, the producer may take orders again
     * on what is left of some offers (`SaleOffer::isLastMinute()`), until this moment. The status stays
     * `closed`; null = never opened.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastMinuteUntil = null;

    /** When the producer opened the last-minute sale: it opens once, and its e-mail goes out once. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastMinuteOpenedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $deliveryDate;

    /** @var Collection<int, SaleDelivery> */
    #[ORM\OneToMany(targetEntity: SaleDelivery::class, mappedBy: 'sale', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'startsAt' => 'ASC'])]
    private Collection $deliveries;

    /** @var Collection<int, SaleOffer> */
    #[ORM\OneToMany(targetEntity: SaleOffer::class, mappedBy: 'sale', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $offers;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Shop::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Shop $shop,
        /** Short public code used in the sale URL. */
        #[ORM\Column(length: self::CODE_LENGTH)]
        private string $code,
        \DateTimeImmutable $deliveryDate,
        #[ORM\Column]
        private \DateTimeImmutable $opensAt,
        #[ORM\Column]
        #[Assert\GreaterThan(propertyPath: 'opensAt', message: 'La date limite doit suivre l\'ouverture.')]
        private \DateTimeImmutable $deadlineAt,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(length: 160)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 160)]
        private string $title = '',
    ) {
        $this->deliveryDate = $deliveryDate->setTime(0, 0);
        $this->allowCustomerEdit = $this->shop->isAllowCustomerEdit();
        $this->deliveries = new ArrayCollection();
        $this->offers = new ArrayCollection();
    }

    public function isScheduled(): bool
    {
        return SaleStatus::Scheduled === $this->status;
    }

    public function isOpen(): bool
    {
        return SaleStatus::Open === $this->status;
    }

    public function isClosed(): bool
    {
        return SaleStatus::Closed === $this->status;
    }

    public function isDone(): bool
    {
        return SaleStatus::Done === $this->status;
    }

    public function isCancelled(): bool
    {
        return SaleStatus::Cancelled === $this->status;
    }

    /**
     * Can a customer place a new order right now? While the sale is open, until the deadline; once
     * closed, during its last-minute sale only.
     */
    public function acceptsOrders(\DateTimeImmutable $now): bool
    {
        return ($this->isOpen() && $now < $this->deadlineAt) || $this->isLastMinuteOpen($now);
    }

    /**
     * A closed sale whose last-minute sale was opened (it may be over). Use it in Twig rather than
     * `sale.openLastMinute`, which would call the action.
     */
    public function isLastMinute(): bool
    {
        return $this->isClosed() && $this->lastMinuteOpenedAt instanceof \DateTimeImmutable;
    }

    /**
     * Does the last-minute sale take orders right now?
     */
    public function isLastMinuteOpen(\DateTimeImmutable $now): bool
    {
        return $this->isLastMinute() && $this->lastMinuteUntil instanceof \DateTimeImmutable && $now < $this->lastMinuteUntil;
    }

    /**
     * Until when customers order: the deadline, or the end of the last-minute sale.
     */
    public function getOrderDeadline(): \DateTimeImmutable
    {
        return $this->isLastMinute() && $this->lastMinuteUntil instanceof \DateTimeImmutable ? $this->lastMinuteUntil : $this->deadlineAt;
    }

    /**
     * The offers a customer may order: all of them, or during a last-minute sale only the leftovers
     * put back on sale.
     *
     * @return list<SaleOffer>
     */
    public function getOrderableOffers(): array
    {
        return $this->isLastMinute() ? $this->offers->filter(static fn (SaleOffer $offer): bool => $offer->isLastMinute())->getValues() : $this->offers->getValues();
    }

    /**
     * closed → closed, taking orders again until $until (App\Sale\Service\LastMinuteSale).
     *
     * @throws \DomainException when the sale is not closed, its last-minute sale was already opened
     *                          or $until is not in the future
     */
    public function openLastMinute(\DateTimeImmutable $until, \DateTimeImmutable $now): static
    {
        if (!$this->isClosed() || $this->lastMinuteOpenedAt instanceof \DateTimeImmutable) {
            throw new \DomainException(\sprintf('Sale "%s" cannot open a last-minute sale.', $this->code));
        }
        if ($until <= $now) {
            throw new \DomainException('A last-minute sale must end in the future.');
        }

        $this->lastMinuteOpenedAt = $now;
        $this->lastMinuteUntil = $until;

        return $this;
    }

    /**
     * Stops the last-minute sale now; nothing happens when it is not taking orders.
     */
    public function endLastMinute(\DateTimeImmutable $now): static
    {
        if ($this->isLastMinuteOpen($now)) {
            $this->lastMinuteUntil = $now;
        }

        return $this;
    }

    public function getLastMinuteUntil(): ?\DateTimeImmutable
    {
        return $this->lastMinuteUntil;
    }

    public function getLastMinuteOpenedAt(): ?\DateTimeImmutable
    {
        return $this->lastMinuteOpenedAt;
    }

    /**
     * Start of the earliest delivery of the sale, or null without delivery.
     */
    public function getFirstDeliveryStart(): ?\DateTimeImmutable
    {
        $starts = $this->deliveries->map(static fn (SaleDelivery $delivery): \DateTimeImmutable => $delivery->getStartsAt())->getValues();

        return [] === $starts ? null : min($starts);
    }

    /**
     * Can a customer still edit or cancel their own order? After that, only the producer can.
     */
    public function isCustomerEditable(\DateTimeImmutable $now): bool
    {
        return $this->allowCustomerEdit && $this->isOpen() && $now < $this->getEffectiveEditCutoffAt();
    }

    public function getEffectiveEditCutoffAt(): \DateTimeImmutable
    {
        return $this->editCutoffAt ?? $this->deadlineAt;
    }

    /**
     * Should the scheduler open this sale now?
     */
    public function isDueToOpen(\DateTimeImmutable $now): bool
    {
        return $this->isScheduled() && $now >= $this->opensAt && $now < $this->deadlineAt;
    }

    /**
     * Should the scheduler close this sale now?
     */
    public function isDueToClose(\DateTimeImmutable $now): bool
    {
        return $this->isOpen() && $now >= $this->deadlineAt;
    }

    /**
     * Should the scheduler mark this sale as done (the day after the delivery)?
     */
    public function isDueToFinish(\DateTimeImmutable $now): bool
    {
        return $this->isClosed() && $now >= $this->deliveryDate->modify('+1 day');
    }

    /**
     * scheduled → open. Use `isOpen()` in Twig: `sale.open` would call this method.
     *
     * @throws \DomainException when the sale is not scheduled
     */
    public function open(): static
    {
        return $this->transition([SaleStatus::Scheduled], SaleStatus::Open);
    }

    /**
     * open → closed (the deadline has passed).
     *
     * @throws \DomainException when the sale is not open
     */
    public function close(): static
    {
        return $this->transition([SaleStatus::Open], SaleStatus::Closed);
    }

    /**
     * closed → done (the day after the delivery).
     *
     * @throws \DomainException when the sale is not closed
     */
    public function markDone(): static
    {
        return $this->transition([SaleStatus::Closed], SaleStatus::Done);
    }

    /**
     * scheduled, open or closed → cancelled.
     *
     * @throws \DomainException when the sale is already done or cancelled
     */
    public function cancel(): static
    {
        return $this->transition([SaleStatus::Scheduled, SaleStatus::Open, SaleStatus::Closed], SaleStatus::Cancelled);
    }

    /**
     * @param list<SaleStatus> $from
     */
    private function transition(array $from, SaleStatus $to): static
    {
        if (!\in_array($this->status, $from, true)) {
            throw new \DomainException(\sprintf('Sale "%s" cannot go from "%s" to "%s".', $this->code, $this->status->value, $to->value));
        }

        $this->status = $to;

        return $this;
    }

    public function getOfferFor(Product $product): ?SaleOffer
    {
        foreach ($this->offers as $offer) {
            if ($offer->getProduct() === $product) {
                return $offer;
            }
        }

        return null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShop(): Shop
    {
        return $this->shop;
    }

    public function getSchedule(): ?SaleSchedule
    {
        return $this->schedule;
    }

    public function setSchedule(?SaleSchedule $schedule): static
    {
        $this->schedule = $schedule;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

        return $this;
    }

    public function getStatus(): SaleStatus
    {
        return $this->status;
    }

    public function getOpensAt(): \DateTimeImmutable
    {
        return $this->opensAt;
    }

    public function setOpensAt(\DateTimeImmutable $opensAt): static
    {
        $this->opensAt = $opensAt;

        return $this;
    }

    public function getDeadlineAt(): \DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function setDeadlineAt(\DateTimeImmutable $deadlineAt): static
    {
        $this->deadlineAt = $deadlineAt;

        return $this;
    }

    public function getEditCutoffAt(): ?\DateTimeImmutable
    {
        return $this->editCutoffAt;
    }

    public function setEditCutoffAt(?\DateTimeImmutable $editCutoffAt): static
    {
        $this->editCutoffAt = $editCutoffAt;

        return $this;
    }

    public function isAllowCustomerEdit(): bool
    {
        return $this->allowCustomerEdit;
    }

    public function setAllowCustomerEdit(bool $allowCustomerEdit): static
    {
        $this->allowCustomerEdit = $allowCustomerEdit;

        return $this;
    }

    public function getDeliveryDate(): \DateTimeImmutable
    {
        return $this->deliveryDate;
    }

    public function setDeliveryDate(\DateTimeImmutable $deliveryDate): static
    {
        $this->deliveryDate = $deliveryDate->setTime(0, 0);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, SaleDelivery>
     */
    public function getDeliveries(): Collection
    {
        return $this->deliveries;
    }

    public function addDelivery(SaleDelivery $delivery): static
    {
        if (!$this->deliveries->contains($delivery)) {
            $this->deliveries->add($delivery);
        }

        return $this;
    }

    public function removeDelivery(SaleDelivery $delivery): static
    {
        $this->deliveries->removeElement($delivery);

        return $this;
    }

    /**
     * @return Collection<int, SaleOffer>
     */
    public function getOffers(): Collection
    {
        return $this->offers;
    }

    public function addOffer(SaleOffer $offer): static
    {
        if (!$this->offers->contains($offer)) {
            $this->offers->add($offer);
        }

        return $this;
    }

    public function removeOffer(SaleOffer $offer): static
    {
        $this->offers->removeElement($offer);

        return $this;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
