<?php

declare(strict_types=1);

namespace App\Sale\Form\Model;

use App\Catalog\Entity\Product;
use App\Sale\Entity\SaleOffer;
use App\Shared\Service\Quantity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One row of the sale form: a product of the shop, offered or not, with its price and quantity limit.
 */
final class SaleOfferData
{
    public bool $selected = false;

    /** Price including VAT, in cents. */
    public ?int $priceCents = null;

    /** Quantity limit for this sale, in thousandths of the unit (App\Shared\Service\Quantity); null = unlimited. */
    public ?int $maxQty = null;

    public function __construct(
        public readonly Product $product,
        /** The offer already saved for this row, when editing a sale. */
        public readonly ?SaleOffer $offer = null,
        /** At least one order line references this offer: it cannot be removed. */
        public readonly bool $ordered = false,
    ) {
    }

    public function reservedQty(): int
    {
        return $this->offer?->getReservedQty() ?? 0;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (!$this->selected) {
            if ($this->ordered || $this->reservedQty() > 0) {
                $context->buildViolation('Ce produit a déjà été commandé : tu ne peux pas le retirer de la vente.')
                    ->atPath('selected')
                    ->addViolation();
            }

            return;
        }

        if (null === $this->priceCents) {
            $context->buildViolation('Indique un prix.')->atPath('priceCents')->addViolation();
        } elseif ($this->priceCents < 0) {
            $context->buildViolation('Le prix ne peut pas être négatif.')->atPath('priceCents')->addViolation();
        }

        if (null === $this->maxQty) {
            return;
        }

        if ($this->maxQty < 0) {
            $context->buildViolation('La quantité maximale ne peut pas être négative.')->atPath('maxQty')->addViolation();
        } elseif (!$this->product->getUnit()->accepts($this->maxQty)) {
            $context->buildViolation('Ce produit se vend à l\'unité : indique un nombre entier.')->atPath('maxQty')->addViolation();
        } elseif ($this->maxQty < $this->reservedQty()) {
            $context->buildViolation('Déjà {{ reserved }} réservés : tu ne peux pas descendre en dessous.')
                ->setParameter('{{ reserved }}', Quantity::decimal($this->reservedQty()).($this->product->getUnit()->isWeighed() ? ' kg' : ''))
                ->atPath('maxQty')
                ->addViolation();
        }
    }
}
