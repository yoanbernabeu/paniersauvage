<?php

declare(strict_types=1);

namespace App\Sale\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Data of the sale form (creation and edition), applied to the entities by SaleEditor.
 */
final class SaleData
{
    #[Assert\NotBlank(message: 'Donne un titre à ta vente.')]
    #[Assert\Length(max: 160)]
    public string $title = '';

    #[Assert\NotNull(message: 'Indique la date de livraison.')]
    public ?\DateTimeImmutable $deliveryDate = null;

    #[Assert\NotNull(message: 'Indique quand la vente ouvre.')]
    public ?\DateTimeImmutable $opensAt = null;

    #[Assert\NotNull(message: 'Indique la date limite de commande.')]
    public ?\DateTimeImmutable $deadlineAt = null;

    public bool $allowCustomerEdit = true;

    /** Hours before the deadline after which customers can no longer edit; null = until the deadline. */
    #[Assert\PositiveOrZero]
    public ?int $editCutoffHours = null;

    /** @var list<SaleDeliveryData> */
    #[Assert\Valid]
    public array $deliveries = [];

    /** @var list<SaleOfferData> */
    #[Assert\Valid]
    public array $offers = [];

    /** The sale already has orders: its delivery date no longer changes. */
    public bool $deliveryDateLocked = false;

    /** Set for a new sale: its deadline must still be ahead. */
    public ?\DateTimeImmutable $now = null;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->opensAt instanceof \DateTimeImmutable && $this->deadlineAt instanceof \DateTimeImmutable && $this->deadlineAt <= $this->opensAt) {
            $context->buildViolation('La date limite doit suivre l\'ouverture.')->atPath('deadlineAt')->addViolation();
        }

        if ($this->deadlineAt instanceof \DateTimeImmutable && $this->now instanceof \DateTimeImmutable && $this->deadlineAt <= $this->now) {
            $context->buildViolation('La date limite est déjà passée.')->atPath('deadlineAt')->addViolation();
        }

        if ($this->deadlineAt instanceof \DateTimeImmutable && $this->deliveryDate instanceof \DateTimeImmutable && $this->deadlineAt->format('Y-m-d') > $this->deliveryDate->format('Y-m-d')) {
            $context->buildViolation('La livraison ne peut pas avoir lieu avant la date limite de commande.')->atPath('deliveryDate')->addViolation();
        }

        if ([] === array_filter($this->deliveries, static fn (SaleDeliveryData $row): bool => $row->selected)) {
            $context->buildViolation('Choisis au moins un point de livraison.')->atPath('deliveries')->addViolation();
        }

        if ([] === array_filter($this->offers, static fn (SaleOfferData $row): bool => $row->selected)) {
            $context->buildViolation('Propose au moins un produit.')->atPath('offers')->addViolation();
        }
    }
}
