<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use PHPUnit\Framework\Attributes\After;

/**
 * Runs a test on a territorial instance: sets INSTANCE_* (and LEGAL_*) environment variables,
 * read when the container builds InstanceSettings. Call `useInstance()` before `createClient()`
 * or `bootKernel()`; the previous values come back after the test.
 */
trait InstanceTrait
{
    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $savedEnvironment = [];

    /**
     * @param array<string, string> $variables
     */
    private function useInstance(array $variables): void
    {
        self::ensureKernelShutdown();
        foreach ($variables as $name => $value) {
            $this->savedEnvironment[$name] ??= [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    /**
     * Panier Toqué du local, carried by Valence Romans Agglo, in the Drôme, with the river accent.
     *
     * @param array<string, string> $overrides
     */
    private function useTerritorialInstance(array $overrides = []): void
    {
        $this->useInstance([
            'INSTANCE_NAME' => 'Panier Toqué du local',
            'INSTANCE_ACCENT' => 'riviere',
            'INSTANCE_TERRITORY' => '26',
            'INSTANCE_TERRITORY_NAME' => 'Valence Romans Agglo',
            'INSTANCE_WELCOME_TEXT' => "Les producteurs de l'agglo prennent leurs précommandes ici.\nSans commission.",
            'INSTANCE_CARRIER_NAME' => 'Valence Romans Agglo',
            'INSTANCE_CARRIER_URL' => 'https://www.valenceromansagglo.fr',
            'LEGAL_SOURCE_CODE_URL' => 'https://forge.example.org/agglo/panier-toque',
            ...$overrides,
        ]);
    }

    #[After]
    protected function restoreEnvironment(): void
    {
        foreach ($this->savedEnvironment as $name => [$env, $server]) {
            if (null === $env) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            if (null === $server) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
        $this->savedEnvironment = [];
    }
}
