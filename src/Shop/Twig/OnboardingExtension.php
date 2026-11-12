<?php

declare(strict_types=1);

namespace App\Shop\Twig;

use App\Shop\Entity\Shop;
use App\Shop\Service\CurrentShop;
use App\Shop\Service\OnboardingChecklist;
use App\Shop\Service\OnboardingProgress;
use Twig\Attribute\AsTwigFunction;

final readonly class OnboardingExtension
{
    public function __construct(
        private CurrentShop $currentShop,
        private OnboardingChecklist $checklist,
    ) {
    }

    /**
     * {% set progress = onboarding_progress() %} — first steps of the current shop, or null outside /pro.
     */
    #[AsTwigFunction('onboarding_progress')]
    public function progress(): ?OnboardingProgress
    {
        $shop = $this->currentShop->find();

        return $shop instanceof Shop ? $this->checklist->progress($shop) : null;
    }
}
