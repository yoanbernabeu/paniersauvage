<?php

declare(strict_types=1);

namespace App\Legal\EventSubscriber;

use App\Legal\Controller\TermsAcceptanceController;
use App\Legal\LegalDocuments;
use App\Shop\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A producer who has not accepted the current terms of use (LegalDocuments::TERMS_VERSION) is sent
 * to the acceptance page before any other /pro page. Runs after the firewall (priority 8), which
 * loads the producer from the session.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final readonly class TermsAcceptanceSubscriber
{
    /** Pages a producer can still reach before accepting. */
    private const array ALLOWED_ROUTES = [TermsAcceptanceController::ROUTE, 'pro_logout'];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/pro/') && '/pro' !== $request->getPathInfo()) {
            return;
        }

        $route = $request->attributes->get('_route');
        $user = $this->security->getUser();

        if (!$user instanceof User || \in_array($route, self::ALLOWED_ROUTES, true) || $user->hasAcceptedTerms(LegalDocuments::TERMS_VERSION)) {
            return;
        }

        $parameters = $request->isMethod('GET') ? ['retour' => $request->getRequestUri()] : [];
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate(TermsAcceptanceController::ROUTE, $parameters), Response::HTTP_SEE_OTHER));
    }
}
