<?php

declare(strict_types=1);

namespace App\Customer\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Sends anonymous visitors of a customer-only page to the magic-link request screen.
 */
final readonly class CustomerEntryPoint implements AuthenticationEntryPointInterface
{
    public const string LOGIN_PATH = '/connexion';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): RedirectResponse
    {
        try {
            $url = $this->urlGenerator->generate('customer_login');
        } catch (RouteNotFoundException) {
            $url = $request->getBaseUrl().self::LOGIN_PATH;
        }

        return new RedirectResponse($url);
    }
}
