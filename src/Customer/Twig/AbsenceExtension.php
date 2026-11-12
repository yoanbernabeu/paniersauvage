<?php

declare(strict_types=1);

namespace App\Customer\Twig;

use App\Customer\Service\AbsenceHistory;
use App\Ordering\Entity\Order;
use Twig\Attribute\AsTwigFunction;

final readonly class AbsenceExtension
{
    public function __construct(
        private AbsenceHistory $absences,
    ) {
    }

    /**
     * {{ recent_absences(order) }} — times the order's customer did not come at this producer lately.
     */
    #[AsTwigFunction('recent_absences')]
    public function recentAbsences(Order $order): int
    {
        return $this->absences->recentFor($order);
    }
}
