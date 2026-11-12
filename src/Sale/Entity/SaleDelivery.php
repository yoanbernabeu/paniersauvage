<?php

declare(strict_types=1);

namespace App\Sale\Entity;

use App\Delivery\Entity\DeliveryPoint;
use App\Sale\Repository\SaleDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One delivery slot of a sale: a delivery point and a time window.
 */
#[ORM\Entity(repositoryClass: SaleDeliveryRepository::class)]
#[ORM\Index(name: 'idx_sale_delivery_sale', columns: ['sale_id', 'position'])]
final class SaleDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Sale::class, inversedBy: 'deliveries')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Sale $sale,
        #[ORM\ManyToOne(targetEntity: DeliveryPoint::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private DeliveryPoint $deliveryPoint,
        #[ORM\Column]
        private \DateTimeImmutable $startsAt,
        #[ORM\Column]
        #[Assert\GreaterThan(propertyPath: 'startsAt', message: 'La fin du créneau doit suivre le début.')]
        private \DateTimeImmutable $endsAt,
        #[ORM\Column(options: ['default' => 0])]
        private int $position = 0,
    ) {
        $this->sale->addDelivery($this);
    }

    public function isHome(): bool
    {
        return $this->deliveryPoint->isHome();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSale(): Sale
    {
        return $this->sale;
    }

    public function getDeliveryPoint(): DeliveryPoint
    {
        return $this->deliveryPoint;
    }

    public function setDeliveryPoint(DeliveryPoint $deliveryPoint): static
    {
        $this->deliveryPoint = $deliveryPoint;

        return $this;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
