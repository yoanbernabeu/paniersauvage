<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Navigation\NavItem;
use App\Shared\Navigation\ProNavigation;
use App\Shared\Navigation\SafeRouter;
use Twig\Attribute\AsTwigFunction;

final readonly class NavigationExtension
{
    public function __construct(
        private ProNavigation $proNavigation,
        private SafeRouter $router,
    ) {
    }

    /**
     * {% for item in pro_nav() %} — only the entries whose route exists.
     *
     * @return list<NavItem>
     */
    #[AsTwigFunction('pro_nav')]
    public function proNav(): array
    {
        return $this->proNavigation->items();
    }

    /** {% if route_exists('pro_register') %}. */
    #[AsTwigFunction('route_exists')]
    public function routeExists(string $route): bool
    {
        return $this->router->exists($route);
    }

    /**
     * {{ safe_path('pro_register', '/pro/inscription') }} — the route's path, or the fallback while
     * the route does not exist.
     *
     * @param array<string, mixed> $parameters
     */
    #[AsTwigFunction('safe_path')]
    public function safePath(string $route, string $fallback, array $parameters = []): string
    {
        return $this->router->path($route, $parameters) ?? $fallback;
    }
}
