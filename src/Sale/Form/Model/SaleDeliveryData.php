<?php

declare(strict_types=1);

namespace App\Sale\Form\Model;

use App\Delivery\Entity\DeliveryPoint;
use App\Sale\Entity\SaleDelivery;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One row of the sale form: a delivery point of the shop, ticked or not, with its time window.
 */
final class SaleDeliveryData
{
    public bool $selected = false;

    public ?\DateTimeImmutable $startTime = null;

    public ?\DateTimeImmutable $endTime = null;

    public function __construct(
        public readonly DeliveryPoint $point,
        /** The delivery already saved for this row, when editing a sale. */
        public readonly ?SaleDelivery $delivery = null,
        /** At least one order chose this delivery: it cannot be removed. */
        public readonly bool $ordered = false,
        /** Active orders chose this delivery: its time window no longer changes. */
        public readonly bool $timesLocked = false,
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (!$this->selected) {
            if ($this->ordered) {
                $context->buildViolation('Des commandes sont déjà prévues ici : tu ne peux pas retirer cette livraison.')
                    ->atPath('selected')
                    ->addViolation();
            }

            return;
        }

        if (!$this->startTime instanceof \DateTimeImmutable || !$this->endTime instanceof \DateTimeImmutable) {
            $context->buildViolation('Indique le début et la fin du créneau.')->atPath('startTime')->addViolation();

            return;
        }

        if ($this->endTime->format('H:i') <= $this->startTime->format('H:i')) {
            $context->buildViolation('La fin du créneau doit suivre le début.')->atPath('endTime')->addViolation();
        }
    }
}
