<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Map\MapTiles;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Attribute\AsTwigFunction;

/**
 * Content-Security-Policy of every page. Set by the application rather than by Caddy because the
 * import map of AssetMapper is an inline script that changes with every build: it is allowed by a
 * per-request nonce (`importmap('app', {nonce: csp_nonce()})` in base.html.twig), never by
 * 'unsafe-inline'. Inline styles stay allowed: the kit and Turbo's progress bar use them, and they
 * cannot run code. Images may also come from the tile server of the maps (MapTiles), that origin only.
 *
 * The nonce lives in the request attributes, not in this service: FrankenPHP's worker mode keeps
 * services alive from one request to the next.
 */
final readonly class ContentSecurityPolicy
{
    public const string NONCE_ATTRIBUTE = '_csp_nonce';

    /** Stripe Checkout and the customer portal are reached by a form POST followed by a redirect. */
    private const array FORM_ACTIONS = ["'self'", 'https://checkout.stripe.com', 'https://billing.stripe.com'];

    public function __construct(
        private RequestStack $requestStack,
        private MapTiles $mapTiles,
    ) {
    }

    #[AsTwigFunction('csp_nonce')]
    public function nonce(): string
    {
        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return '';
        }

        $nonce = $request->attributes->get(self::NONCE_ATTRIBUTE);
        if (!\is_string($nonce)) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $request->attributes->set(self::NONCE_ATTRIBUTE, $nonce);
        }

        return $nonce;
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();

        // Symfony's debug exception page has its own inline scripts and styles.
        if (!$event->isMainRequest() || $response->headers->has('Content-Security-Policy') || $response->headers->has('X-Debug-Exception')) {
            return;
        }

        $response->headers->set('Content-Security-Policy', $this->policy($event->getRequest()));
    }

    public function policy(Request $request): string
    {
        $nonce = $request->attributes->get(self::NONCE_ATTRIBUTE);
        $scripts = \is_string($nonce) ? ["'self'", "'nonce-".$nonce."'"] : ["'self'"];

        $images = ["'self'", 'data:', 'blob:'];
        if (null !== $tiles = $this->mapTiles->origin()) {
            $images[] = $tiles;
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => $scripts,
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => $images,
            'font-src' => ["'self'"],
            'connect-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => self::FORM_ACTIONS,
            'frame-ancestors' => ["'self'"],
        ];

        $policy = [];
        foreach ($directives as $name => $sources) {
            $policy[] = $name.' '.implode(' ', $sources);
        }

        return implode('; ', $policy);
    }
}
