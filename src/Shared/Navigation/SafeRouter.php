<?php

declare(strict_types=1);

namespace App\Shared\Navigation;

use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Generates URLs for routes that may not exist yet (features built in parallel).
 */
final readonly class SafeRouter
{
    public function __construct(
        private RouterInterface $router,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return string|null the path, or null when the route is unknown or cannot be generated
     */
    public function path(string $route, array $parameters = []): ?string
    {
        try {
            return $this->router->generate($route, $parameters);
        } catch (ExceptionInterface) {
            return null;
        }
    }

    /**
     * Whether the route is declared, even when it needs parameters to be generated.
     */
    public function exists(string $route): bool
    {
        return $this->router->getRouteCollection()->get($route) instanceof Route;
    }
}
