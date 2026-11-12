<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckInstanceCommandTest extends KernelTestCase
{
    use InstanceTrait;

    public function testItPrintsPanierSauvageByDefault(): void
    {
        $tester = $this->check();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Nom Panier Sauvage Logo', $output);
        self::assertStringContainsString('Logo logo de Panier Sauvage', $output);
        self::assertStringContainsString('Couleur d\'accent Sauge (Panier Sauvage)', $output);
        self::assertStringContainsString('Territoire toute la France', $output);
        self::assertStringContainsString('Centre de la carte aucun', $output);
        self::assertStringContainsString('Texte d\'accueil non', $output);
        self::assertStringContainsString('Organisme porteur aucun', $output);
        self::assertStringContainsString('Abonnement Abonnement payé par chaque producteur', $output);
        self::assertStringContainsString('Instance settings are valid.', $output);
    }

    public function testItPrintsATerritorialInstance(): void
    {
        $this->useTerritorialInstance(['INSTANCE_PRICING' => 'sponsored', 'INSTANCE_TERRITORY' => '26 07 07186', 'INSTANCE_TERRITORY_CENTER' => '44.9334,4.8924', 'INSTANCE_TERRITORY_RADIUS_KM' => '50']);
        $tester = $this->check();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Nom Panier Toqué du local (propulsé par Panier Sauvage)', $output);
        self::assertStringContainsString('Couleur d\'accent Rivière (bleu)', $output);
        self::assertStringContainsString('Territoire 26, 07, 07186 (Valence Romans Agglo)', $output);
        self::assertStringContainsString('Centre de la carte 44.9334, 4.8924 (50 km)', $output);
        self::assertStringContainsString('Texte d\'accueil oui', $output);
        self::assertStringContainsString('Organisme porteur Valence Romans Agglo (https://www.valenceromansagglo.fr)', $output);
        self::assertStringContainsString('Abonnement Abonnement offert par l\'organisme porteur', $output);
    }

    public function testACarrierWithoutUrlAndATerritoryWithoutName(): void
    {
        $this->useTerritorialInstance(['INSTANCE_CARRIER_URL' => '', 'INSTANCE_TERRITORY_NAME' => '']);
        $output = (string) preg_replace('/\s+/', ' ', $this->check()->getDisplay());

        self::assertStringContainsString('Territoire 26 Centre', $output);
        self::assertStringContainsString('Organisme porteur Valence Romans Agglo Abonnement', $output);
    }

    public function testItFailsOnAnUnusableValue(): void
    {
        $this->useInstance(['INSTANCE_ACCENT' => 'fuchsia']);
        $tester = $this->check();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('INSTANCE_ACCENT: one of sauge', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    private function check(): CommandTester
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:instance:check'));
        $tester->execute([]);

        return $tester;
    }
}
