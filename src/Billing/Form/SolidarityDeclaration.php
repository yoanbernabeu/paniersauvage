<?php

declare(strict_types=1);

namespace App\Billing\Form;

use App\Billing\Enum\SolidarityCriterion;
use App\Billing\Service\SolidarityPlan;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the producer declares on honour to join the solidarity plan. Nothing is checked beforehand
 * and no document is asked for.
 */
final class SolidarityDeclaration
{
    /** @var list<SolidarityCriterion> */
    #[Assert\Count(min: 1, minMessage: 'Coche au moins une situation qui te correspond.')]
    public array $criteria = [];

    #[Assert\NotNull(message: 'Choisis un montant entre 0 et 10 €.')]
    #[Assert\Range(notInRangeMessage: 'Choisis un montant entre {{ min }} et {{ max }} €.', min: SolidarityPlan::MIN_EUROS, max: SolidarityPlan::MAX_EUROS)]
    public ?int $amountEuros = SolidarityPlan::SUGGESTED_EUROS;

    #[Assert\IsTrue(message: 'Coche la déclaration sur l\'honneur pour continuer.')]
    public bool $honour = false;

    public function amountCents(): int
    {
        return 100 * ($this->amountEuros ?? throw new \LogicException('The amount is checked by the form first.'));
    }
}
