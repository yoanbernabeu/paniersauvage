<?php

declare(strict_types=1);

namespace App\Customer\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * The magic link only opens a confirmation page; the sign-in itself is a POST from that page
 * (`check_post_only`). This adds a CSRF check to that POST, so another site cannot sign a visitor
 * into an account of its choosing (login CSRF) with a link it requested for itself.
 */
final readonly class LoginLinkCsrfSubscriber
{
    public const string TOKEN_ID = 'authenticate';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    // Runs before Symfony's CsrfProtectionListener (priority 512), which verifies the badge.
    #[AsEventListener(priority: 1024, dispatcher: 'security.event_dispatcher.main')]
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $request = $this->requestStack->getMainRequest();
        $passport = $event->getPassport();

        if (!$request instanceof Request || 'customer_login_check' !== $request->attributes->get('_route') || $passport->hasBadge(CsrfTokenBadge::class)) {
            return;
        }

        $token = $request->request->get('_csrf_token');

        $passport->addBadge(new CsrfTokenBadge(self::TOKEN_ID, \is_string($token) ? $token : null));
    }
}
