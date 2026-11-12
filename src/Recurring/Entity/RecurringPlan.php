<?php

declare(strict_types=1);

namespace App\Recurring\Entity;

use App\Catalog\Entity\Product;
use App\Recurring\Enum\RecurringMode;
use App\Recurring\Enum\RecurringTerm;
use App\Recurring\Repository\RecurringPlanRepository;
use App\Sale\Entity\SaleSchedule;
use App\Shop\Entity\Shop;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Recurring orders offered on a recurrence (SPEC « lot H »): what a subscriber receives at each
 * delivery of the recurrence, how many places there are and the conditions shown before subscribing.
 * Always one producer: the products belong to the shop of the recurrence.
 */
#[ORM\Entity(repositoryClass: RecurringPlanRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_recurring_plan_schedule', columns: ['schedule_id'])]
#[ORM\Index(name: 'idx_recurring_plan_shop', columns: ['shop_id', 'enabled'])]
final class RecurringPlan
{
    /** Most baskets one subscriber may take at each delivery. */
    public const int MAX_SHARES = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Shop::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Shop $shop;

    /** New subscriptions accepted. Closing them never ends the subscriptions in progress. */
    #[ORM\Column(options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Donne un nom à ton panier.')]
    #[Assert\Length(max: 120)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: RecurringMode::class)]
    private RecurringMode $mode = RecurringMode::Basket;

    /** Most baskets per subscriber and per delivery (basket mode). */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    #[Assert\Range(min: 1, max: self::MAX_SHARES)]
    private int $maxShares = 1;

    /** Number of places (subscriptions in progress); null = no limit. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 1000)]
    private ?int $capacity = null;

    #[ORM\Column(length: 20, enumType: RecurringTerm::class)]
    private RecurringTerm $term = RecurringTerm::OpenEnded;

    /** First and last delivery days of a season contract. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $seasonStartsOn = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $seasonEndsOn = null;

    /** Notice of an open-ended subscription: the deliveries of the next N days stay due after cancelling. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    #[Assert\Range(min: 0, max: 60)]
    private int $noticeDays = 0;

    /** Deliveries a subscriber may pause (absences); null = as many as they want. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 0, max: 52)]
    private ?int $maxPauses = null;

    /** Free text shown before subscribing (commitment, what happens when you forget your basket…). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $conditions = null;

    /** @var Collection<int, RecurringPlanItem> */
    #[ORM\OneToMany(targetEntity: RecurringPlanItem::class, mappedBy: 'plan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $items;

    public function __construct(
        #[ORM\OneToOne(targetEntity: SaleSchedule::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private SaleSchedule $schedule,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->shop = $schedule->getShop();
        $this->title = $schedule->getName();
        $this->items = new ArrayCollection();
    }

    #[Assert\Callback]
    public function validateTerm(ExecutionContextInterface $context): void
    {
        if (!$this->isSeason()) {
            return;
        }

        if (!$this->seasonStartsOn instanceof \DateTimeImmutable) {
            $context->buildViolation('Indique la première livraison du contrat.')->atPath('seasonStartsOn')->addViolation();
        }
        if (!$this->seasonEndsOn instanceof \DateTimeImmutable) {
            $context->buildViolation('Indique la dernière livraison du contrat.')->atPath('seasonEndsOn')->addViolation();
        } elseif ($this->seasonStartsOn instanceof \DateTimeImmutable && [] === $this->deliveryDatesBetween($this->seasonStartsOn, $this->seasonEndsOn)) {
            $context->buildViolation(\sprintf('Le contrat doit compter au moins une livraison (le %s).', $this->weekdayName()))->atPath('seasonEndsOn')->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateItems(ExecutionContextInterface $context): void
    {
        if ($this->items->isEmpty()) {
            $context->buildViolation('Choisis au moins un produit et sa quantité.')->atPath('items')->addViolation();
        }
    }

    public function isSeason(): bool
    {
        return RecurringTerm::Season === $this->term;
    }

    public function isBasket(): bool
    {
        return RecurringMode::Basket === $this->mode;
    }

    /**
     * Can a customer subscribe on this day? Subscriptions open, recurrence active and, for a season
     * contract, at least one delivery left.
     */
    public function acceptsSubscriptions(\DateTimeImmutable $today): bool
    {
        if (!$this->enabled || !$this->schedule->isActive() || $this->items->isEmpty()) {
            return false;
        }

        return !$this->isSeason() || [] !== $this->remainingSeasonDates($today);
    }

    /**
     * Delivery days of the recurrence between two days, both included.
     *
     * @return list<\DateTimeImmutable> dates at midnight
     */
    public function deliveryDatesBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $dates = [];
        $day = $from->setTime(0, 0);
        while ($day <= $to) {
            if ((int) $day->format('N') === $this->schedule->getWeekday()) {
                $dates[] = $day;
                $day = $day->modify('+7 days');
            } else {
                $day = $day->modify('+1 day');
            }
        }

        return $dates;
    }

    /**
     * Season deliveries from this day on (all of them for an open-ended subscription: none).
     *
     * @return list<\DateTimeImmutable>
     */
    public function remainingSeasonDates(\DateTimeImmutable $today): array
    {
        if (!$this->seasonStartsOn instanceof \DateTimeImmutable || !$this->seasonEndsOn instanceof \DateTimeImmutable) {
            return [];
        }

        return $this->deliveryDatesBetween(max($today, $this->seasonStartsOn), $this->seasonEndsOn);
    }

    public function weekdayName(): string
    {
        return ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][$this->schedule->getWeekday() - 1];
    }

    public function getItemFor(Product $product): ?RecurringPlanItem
    {
        foreach ($this->items as $item) {
            if ($item->getProduct() === $product) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Puts the product in the plan with this quantity (thousandths), or takes it out with 0.
     */
    public function setItemQuantity(Product $product, int $quantity, int $position): void
    {
        if ($product->getShop() !== $this->shop) {
            throw new \InvalidArgumentException('A recurring plan only offers products of its own shop.');
        }

        $item = $this->getItemFor($product);
        if ($quantity <= 0) {
            if ($item instanceof RecurringPlanItem) {
                $this->items->removeElement($item);
            }

            return;
        }

        if ($item instanceof RecurringPlanItem) {
            $item->setQuantity($quantity)->setPosition($position);
        } else {
            $this->items->add(new RecurringPlanItem($this, $product, $quantity, $position));
        }
    }

    public function containsAlcohol(): bool
    {
        return $this->items->exists(static fn (int $key, RecurringPlanItem $item): bool => $item->getProduct()->isAlcoholic());
    }

    /**
     * Price of one basket at the current prices of the products (basket mode).
     */
    public function basketPriceCents(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->priceCents();
        }

        return $total;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShop(): Shop
    {
        return $this->shop;
    }

    public function getSchedule(): SaleSchedule
    {
        return $this->schedule;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = trim($title ?? '');

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = trim($description ?? '');
        $this->description = '' === $description ? null : $description;

        return $this;
    }

    public function getMode(): RecurringMode
    {
        return $this->mode;
    }

    public function setMode(RecurringMode $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function getMaxShares(): int
    {
        return $this->maxShares;
    }

    public function setMaxShares(int $maxShares): static
    {
        $this->maxShares = $maxShares;

        return $this;
    }

    public function getCapacity(): ?int
    {
        return $this->capacity;
    }

    public function setCapacity(?int $capacity): static
    {
        $this->capacity = $capacity;

        return $this;
    }

    public function getTerm(): RecurringTerm
    {
        return $this->term;
    }

    public function setTerm(RecurringTerm $term): static
    {
        $this->term = $term;

        return $this;
    }

    public function getSeasonStartsOn(): ?\DateTimeImmutable
    {
        return $this->seasonStartsOn;
    }

    public function setSeasonStartsOn(?\DateTimeImmutable $seasonStartsOn): static
    {
        $this->seasonStartsOn = $seasonStartsOn?->setTime(0, 0);

        return $this;
    }

    public function getSeasonEndsOn(): ?\DateTimeImmutable
    {
        return $this->seasonEndsOn;
    }

    public function setSeasonEndsOn(?\DateTimeImmutable $seasonEndsOn): static
    {
        $this->seasonEndsOn = $seasonEndsOn?->setTime(0, 0);

        return $this;
    }

    public function getNoticeDays(): int
    {
        return $this->noticeDays;
    }

    public function setNoticeDays(int $noticeDays): static
    {
        $this->noticeDays = $noticeDays;

        return $this;
    }

    public function getMaxPauses(): ?int
    {
        return $this->maxPauses;
    }

    public function setMaxPauses(?int $maxPauses): static
    {
        $this->maxPauses = $maxPauses;

        return $this;
    }

    public function getConditions(): ?string
    {
        return $this->conditions;
    }

    public function setConditions(?string $conditions): static
    {
        $conditions = trim($conditions ?? '');
        $this->conditions = '' === $conditions ? null : $conditions;

        return $this;
    }

    /**
     * @return Collection<int, RecurringPlanItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
