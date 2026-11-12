<?php

declare(strict_types=1);

namespace App\Instance\Command;

use App\Instance\InstanceSettings;
use App\Instance\InvalidInstanceSettingException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Checks the INSTANCE_* settings and prints what the instance looks like. The production
 * entrypoint runs it before starting: a wrong value stops the deployment instead of every page.
 */
#[AsCommand(name: 'app:instance:check', description: 'Checks the INSTANCE_* settings of this deployment and prints them.')]
final readonly class CheckInstanceCommand
{
    /**
     * @param \Closure(): InstanceSettings $settings
     */
    public function __construct(
        #[AutowireServiceClosure(InstanceSettings::class)]
        private \Closure $settings,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $instance = ($this->settings)();
        } catch (InvalidInstanceSettingException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $territory = $instance->territory;
        $io->definitionList(
            ['Nom' => $instance->name.($instance->isRenamed() ? ' (propulsé par '.InstanceSettings::SOFTWARE_NAME.')' : '')],
            ['Logo' => $instance->logoPath ?? 'logo de Panier Sauvage'],
            ['Couleur d\'accent' => $instance->accent->label()],
            ['Territoire' => $territory->isRestricted() ? implode(', ', [...$territory->departments, ...$territory->communes]).('' !== $territory->name ? ' ('.$territory->name.')' : '') : 'toute la France'],
            ['Centre de la carte' => $territory->hasCenter() ? \sprintf('%s, %s (%d km)', $territory->centerLatitude, $territory->centerLongitude, $territory->radiusKm) : 'aucun'],
            ['Texte d\'accueil' => '' !== $instance->welcomeText ? 'oui' : 'non'],
            ['Organisme porteur' => $instance->hasCarrier() ? $instance->carrierName.(null !== $instance->carrierUrl ? ' ('.$instance->carrierUrl.')' : '') : 'aucun'],
            ['Abonnement' => $instance->pricing->label()],
        );
        $io->success('Instance settings are valid.');

        return Command::SUCCESS;
    }
}
