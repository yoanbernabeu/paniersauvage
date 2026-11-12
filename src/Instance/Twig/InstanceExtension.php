<?php

declare(strict_types=1);

namespace App\Instance\Twig;

use App\Instance\InstanceSettings;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * `instance` in every template: the settings of this deployment (App\Instance\InstanceSettings),
 * e.g. `instance.name`, `instance.isRenamed()`, `instance.accent`, `instance.isSponsored()`.
 */
final class InstanceExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly InstanceSettings $instance,
    ) {
    }

    /**
     * @return array{instance: InstanceSettings}
     */
    public function getGlobals(): array
    {
        return ['instance' => $this->instance];
    }
}
