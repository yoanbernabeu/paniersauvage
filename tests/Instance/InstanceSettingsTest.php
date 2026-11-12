<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use App\Instance\Enum\AccentColor;
use App\Instance\Enum\PricingMode;
use App\Instance\InstanceSettings;
use App\Instance\InvalidInstanceSettingException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstanceSettingsTest extends KernelTestCase
{
    public function testTheCommittedDefaultsArePanierSauvageItself(): void
    {
        self::bootKernel();
        $instance = self::getContainer()->get(InstanceSettings::class);

        self::assertSame('Panier Sauvage', $instance->name);
        self::assertFalse($instance->isRenamed());
        self::assertNull($instance->logoPath);
        self::assertSame(AccentColor::Sauge, $instance->accent);
        self::assertTrue($instance->accent->isDefault());
        self::assertFalse($instance->territory->isRestricted());
        self::assertFalse($instance->territory->hasCenter());
        self::assertSame(30, $instance->territory->radiusKm);
        self::assertSame('', $instance->welcomeText);
        self::assertFalse($instance->hasCarrier());
        self::assertNull($instance->carrierUrl);
        self::assertSame(PricingMode::Standard, $instance->pricing);
        self::assertFalse($instance->isSponsored());
        self::assertSame('', $instance->sponsorshipMention());
    }

    public function testATerritorialInstance(): void
    {
        $instance = new InstanceSettings(
            name: '  Panier Toqué du local ',
            accent: ' Terre ',
            territory: '26, 07',
            territoryName: 'Drôme-Ardèche',
            welcomeText: "  Bonjour.\n  ",
            carrierName: ' Valence Romans Agglo ',
            carrierUrl: ' https://www.valenceromansagglo.fr ',
            pricing: 'SPONSORED',
        );

        self::assertSame('Panier Toqué du local', $instance->name);
        self::assertTrue($instance->isRenamed());
        self::assertSame(AccentColor::Terre, $instance->accent);
        self::assertSame(['26', '07'], $instance->territory->departments);
        self::assertSame('Drôme-Ardèche', $instance->territory->name);
        self::assertSame('Bonjour.', $instance->welcomeText);
        self::assertSame('Valence Romans Agglo', $instance->carrierName);
        self::assertTrue($instance->hasCarrier());
        self::assertSame('https://www.valenceromansagglo.fr', $instance->carrierUrl);
        self::assertTrue($instance->isSponsored());
        self::assertSame('Abonnement offert par Valence Romans Agglo', $instance->sponsorshipMention());
    }

    public function testTheLogoIsAFileOfPublic(): void
    {
        $public = sys_get_temp_dir().'/instance-'.bin2hex(random_bytes(4));
        mkdir($public.'/images/instance', 0o777, true);
        touch($public.'/images/instance/logo.min.svg');

        try {
            self::assertSame('images/instance/logo.min.svg', new InstanceSettings(logo: '/images/instance/logo.min.svg', publicDir: $public)->logoPath);

            $this->expectExceptionObject(new InvalidInstanceSettingException('INSTANCE_LOGO: no such file in public/ (value: "images/instance/autre.svg"). See docs/INSTANCE.md.'));
            new InstanceSettings(logo: 'images/instance/autre.svg', publicDir: $public);
        } finally {
            unlink($public.'/images/instance/logo.min.svg');
            rmdir($public.'/images/instance');
            rmdir($public.'/images');
            rmdir($public);
        }
    }

    public function testTheShippedPublicDirectoryIsChecked(): void
    {
        self::bootKernel();
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');

        $this->expectException(InvalidInstanceSettingException::class);
        new InstanceSettings(logo: 'logo-absent.svg', publicDir: $projectDir.'/public');
    }

    /**
     * @param array<string, string> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testAnUnusableValueIsRefusedWithItsVariable(array $settings, string $message): void
    {
        $this->expectException(InvalidInstanceSettingException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new InstanceSettings(...$settings);
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'empty name' => [['name' => '  '], 'INSTANCE_NAME: a name of 1 to 60 characters'];
        yield 'name too long' => [['name' => str_repeat('a', 61)], 'INSTANCE_NAME'];
        yield 'logo outside public' => [['logo' => '../config/secrets.svg'], 'INSTANCE_LOGO: a path inside public/'];
        yield 'logo not an image' => [['logo' => 'images/logo.php'], 'INSTANCE_LOGO'];
        yield 'logo with a query' => [['logo' => 'logo.svg?x=1'], 'INSTANCE_LOGO'];
        yield 'free colour' => [['accent' => '#ff0000'], 'INSTANCE_ACCENT: one of sauge, riviere, terre, prune, ardoise (value: "#ff0000")'];
        yield 'territory' => [['territory' => '26, Drôme'], 'INSTANCE_TERRITORY'];
        yield 'centre' => [['territoryCenter' => 'Valence'], 'INSTANCE_TERRITORY_CENTER'];
        yield 'radius' => [['territoryRadiusKm' => '20'], 'INSTANCE_TERRITORY_RADIUS_KM: one of 10, 30, 50'];
        yield 'carrier URL' => [['carrierUrl' => 'javascript:alert(1)'], 'INSTANCE_CARRIER_URL: an http(s) URL'];
        yield 'pricing' => [['pricing' => 'free'], 'INSTANCE_PRICING: standard or sponsored'];
        yield 'sponsored without carrier' => [['pricing' => 'sponsored'], 'INSTANCE_CARRIER_NAME: required with INSTANCE_PRICING=sponsored'];
    }

    public function testANameOfSixtyCharactersIsFine(): void
    {
        self::assertSame(60, mb_strlen(new InstanceSettings(name: str_repeat('é', 60))->name));
    }

    public function testAnHttpCarrierUrlIsFine(): void
    {
        self::assertSame('http://agglo.example', new InstanceSettings(carrierUrl: 'http://agglo.example')->carrierUrl);
    }

    public function testThePricingModesAreLabelled(): void
    {
        self::assertSame('Abonnement payé par chaque producteur', PricingMode::Standard->label());
        self::assertSame('Abonnement offert par l\'organisme porteur', PricingMode::Sponsored->label());
    }
}
