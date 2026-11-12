<?php

declare(strict_types=1);

namespace App\Sale\Entity;

use App\Catalog\Entity\Product;
use App\Delivery\Entity\DeliveryPoint;
use App\Sale\Repository\SaleScheduleRepository;
use App\Shop\Entity\Shop;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A weekly recurrence that generates the sales of the coming weeks.
 */
#[ORM\Entity(repositoryClass: SaleScheduleRepository::class)]
#[ORM\Index(name: 'idx_sale_schedule_shop', columns: ['shop_id', 'active'])]
final class SaleSchedule implements \Stringable
{
    /** Hour of the day at which a generated sale opens. */
    public const int OPENING_HOUR = 8;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** ISO-8601 weekday of the delivery: 1 (Monday) to 7 (Sunday). */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 1, max: 7)]
    private int $weekday = 1;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $deliveryStartTime;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\GreaterThan(propertyPath: 'deliveryStartTime', message: 'La fin du créneau doit suivre le début.')]
    private \DateTimeImmutable $deliveryEndTime;

    /** Days between the order deadline and the delivery (3 = Friday for a Monday delivery). */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 0, max: 30)]
    private int $deadlineDaysBefore = 3;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $deadlineTime;

    /** Days before the delivery at which the sale opens to customers. */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 1, max: 60)]
    #[Assert\GreaterThan(propertyPath: 'deadlineDaysBefore', message: 'La vente doit ouvrir avant la date limite.')]
    private int $opensDaysBefore = 7;

    /** Hours before the deadline after which customers can no longer edit; null = until the deadline. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $editCutoffHours = null;

    /** @var Collection<int, DeliveryPoint> */
    #[ORM\ManyToMany(targetEntity: DeliveryPoint::class)]
    #[ORM\JoinTable(name: 'sale_schedule_delivery_point')]
    private Collection $deliveryPoints;

    /**
     * Extra products always offered by this recurrence, on top of the default range.
     *
     * @var Collection<int, Product>
     */
    #[ORM\ManyToMany(targetEntity: Product::class)]
    #[ORM\JoinTable(name: 'sale_schedule_product')]
    private Collection $products;

    public function __construct(#[ORM\ManyToOne(targetEntity: Shop::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Shop $shop, #[ORM\Column(length: 120)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        private string $name = '')
    {
        $this->deliveryStartTime = new \DateTimeImmutable('1970-01-01 18:00:00');
        $this->deliveryEndTime = new \DateTimeImmutable('1970-01-01 19:00:00');
        $this->deadlineTime = new \DateTimeImmutable('1970-01-01 20:00:00');
        $this->deliveryPoints = new ArrayCollection();
        $this->products = new ArrayCollection();
    }

    /**
     * The next delivery days of this recurrence, strictly after the given day.
     *
     * @return list<\DateTimeImmutable> dates at midnight
     */
    public function nextDeliveryDates(\DateTimeImmutable $after, int $count = 4): array
    {
        $date = $after->setTime(0, 0);
        $dates = [];

        while (\count($dates) < $count) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') === $this->weekday) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    public function deliveryStartsAtFor(\DateTimeImmutable $deliveryDate): \DateTimeImmutable
    {
        return $this->at($deliveryDate, $this->deliveryStartTime);
    }

    public function deliveryEndsAtFor(\DateTimeImmutable $deliveryDate): \DateTimeImmutable
    {
        return $this->at($deliveryDate, $this->deliveryEndTime);
    }

    public function deadlineAtFor(\DateTimeImmutable $deliveryDate): \DateTimeImmutable
    {
        return $this->at($deliveryDate->modify(\sprintf('-%d days', $this->deadlineDaysBefore)), $this->deadlineTime);
    }

    public function opensAtFor(\DateTimeImmutable $deliveryDate): \DateTimeImmutable
    {
        return $deliveryDate->modify(\sprintf('-%d days', $this->opensDaysBefore))->setTime(self::OPENING_HOUR, 0);
    }

    public function editCutoffAtFor(\DateTimeImmutable $deliveryDate): ?\DateTimeImmutable
    {
        if (null === $this->editCutoffHours) {
            return null;
        }

        return $this->deadlineAtFor($deliveryDate)->modify(\sprintf('-%d hours', $this->editCutoffHours));
    }

    private function at(\DateTimeImmutable $day, \DateTimeImmutable $time): \DateTimeImmutable
    {
        return $day->setTime((int) $time->format('H'), (int) $time->format('i'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShop(): Shop
    {
        return $this->shop;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getWeekday(): int
    {
        return $this->weekday;
    }

    public function setWeekday(int $weekday): static
    {
        $this->weekday = $weekday;

        return $this;
    }

    public function getDeliveryStartTime(): \DateTimeImmutable
    {
        return $this->deliveryStartTime;
    }

    public function setDeliveryStartTime(\DateTimeImmutable $deliveryStartTime): static
    {
        $this->deliveryStartTime = $deliveryStartTime;

        return $this;
    }

    public function getDeliveryEndTime(): \DateTimeImmutable
    {
        return $this->deliveryEndTime;
    }

    public function setDeliveryEndTime(\DateTimeImmutable $deliveryEndTime): static
    {
        $this->deliveryEndTime = $deliveryEndTime;

        return $this;
    }

    public function getDeadlineDaysBefore(): int
    {
        return $this->deadlineDaysBefore;
    }

    public function setDeadlineDaysBefore(int $deadlineDaysBefore): static
    {
        $this->deadlineDaysBefore = $deadlineDaysBefore;

        return $this;
    }

    public function getDeadlineTime(): \DateTimeImmutable
    {
        return $this->deadlineTime;
    }

    public function setDeadlineTime(\DateTimeImmutable $deadlineTime): static
    {
        $this->deadlineTime = $deadlineTime;

        return $this;
    }

    public function getOpensDaysBefore(): int
    {
        return $this->opensDaysBefore;
    }

    public function setOpensDaysBefore(int $opensDaysBefore): static
    {
        $this->opensDaysBefore = $opensDaysBefore;

        return $this;
    }

    public function getEditCutoffHours(): ?int
    {
        return $this->editCutoffHours;
    }

    public function setEditCutoffHours(?int $editCutoffHours): static
    {
        $this->editCutoffHours = $editCutoffHours;

        return $this;
    }

    /**
     * @return Collection<int, DeliveryPoint>
     */
    public function getDeliveryPoints(): Collection
    {
        return $this->deliveryPoints;
    }

    public function addDeliveryPoint(DeliveryPoint $deliveryPoint): static
    {
        if (!$this->deliveryPoints->contains($deliveryPoint)) {
            $this->deliveryPoints->add($deliveryPoint);
        }

        return $this;
    }

    public function removeDeliveryPoint(DeliveryPoint $deliveryPoint): static
    {
        $this->deliveryPoints->removeElement($deliveryPoint);

        return $this;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): static
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
        }

        return $this;
    }

    public function removeProduct(Product $product): static
    {
        $this->products->removeElement($product);

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
