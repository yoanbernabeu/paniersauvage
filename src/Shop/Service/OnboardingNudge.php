<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Shop\Entity\Shop;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * After a first-step screen saves something, suggests the next step on the following page (as long as
 * the five steps are not all done). Rendered by templates/shop/onboarding/_nudge.html.twig.
 */
final readonly class OnboardingNudge
{
    /** Flash type picked up by templates/layout/_flashes.html.twig. */
    public const string FLASH_TYPE = 'onboarding';

    public function __construct(
        private OnboardingChecklist $checklist,
        private RequestStack $requestStack,
    ) {
    }

    public function suggestNextStep(Shop $shop): void
    {
        $next = $this->checklist->progress($shop)->nextStep();
        $session = $this->requestStack->getCurrentRequest()?->hasSession() ? $this->requestStack->getSession() : null;

        if (!$next instanceof OnboardingStep || !$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $flashBag = $session->getFlashBag();
        $flashBag->set(self::FLASH_TYPE, [$next->key]);
    }
}
