<?php

declare(strict_types=1);

namespace App\Sale\Form\Model;

use App\Sale\Entity\SaleOffer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the producer puts back on sale, and until when (App\Sale\Service\LastMinuteSale).
 */
final class LastMinuteSaleData
{
    #[Assert\NotNull(message: 'Indique jusqu\'à quand tes clients peuvent commander.')]
    public ?\DateTimeImmutable $until = null;

    /** @var array<int, int|null> sale offer id => quantity in thousandths (empty: not offered) */
    public array $quantities = [];

    /**
     * Prefilled with what is left of each limited offer.
     *
     * @param list<SaleOffer> $offers
     */
    public static function prefilled(array $offers, ?\DateTimeImmutable $until): self
    {
        $data = new self();
        $data->until = $until;
        foreach ($offers as $offer) {
            $remaining = $offer->remaining();
            $data->quantities[(int) $offer->getId()] = null !== $remaining && $remaining > 0 ? $remaining : null;
        }

        return $data;
    }
}
