<?php

declare(strict_types=1);

namespace App\Tests\Delivery;

use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Enum\DeliveryPointType;
use App\Delivery\Repository\DeliveryPointRepository;
use App\Tests\Delivery\Support\FakeBan;
use App\Tests\Delivery\Support\FakeBanTrait;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * The town of a pick-up point (directory pages) and the delivery zone of a home delivery.
 */
final class DeliveryZoneTest extends WebTestCase
{
    use Factories;
    use FakeBanTrait;

    public function testTheGeocoderGivesTheTownOfAPoint(): void
    {
        FakeBan::knows('Place de l\'église 26400 Saou', 44.646423, 5.062418, city: 'Saoû', postcode: '26400');
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/points-de-livraison/nouveau');
        $client->submitForm('Enregistrer', [
            'delivery_point[type]' => DeliveryPointType::MeetingPoint->value,
            'delivery_point[name]' => 'Place du village',
            'delivery_point[address]' => "Place de l'église\n26400 Saou",
        ]);

        $point = $this->point('Place du village');
        self::assertSame('Saoû', $point->getCity(), 'The spelling of the geocoder wins.');
        self::assertSame('saou-26', $point->getCommuneSlug());
    }

    public function testATownNotFoundKeepsTheOneOfTheAddress(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/points-de-livraison/nouveau');
        $client->submitForm('Enregistrer', [
            'delivery_point[type]' => DeliveryPointType::MeetingPoint->value,
            'delivery_point[name]' => 'Halle',
            'delivery_point[address]' => "Halle\n26400 Crest",
        ]);

        $point = $this->point('Halle');
        self::assertFalse($point->hasCoordinates());
        self::assertSame('crest-26', $point->getCommuneSlug());
    }

    public function testAHomeDeliveryDeclaresItsZone(): void
    {
        FakeBan::knows('Crest', 44.7283, 5.0222, city: 'Crest', postcode: '26400');
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/points-de-livraison/nouveau');
        self::assertSelectorTextContains('label[for="delivery_point_zoneTown"]', 'Commune au centre de ta zone');
        self::assertSelectorTextContains('label[for="delivery_point_zoneRadiusKm"]', 'Rayon de livraison (km)');
        self::assertSelectorExists('[data-delivery-zone] input[name="delivery_point[zoneTown]"][placeholder="Crest"][autocomplete="off"]:not([required])');
        self::assertSelectorExists('[data-delivery-zone] input[name="delivery_point[zoneRadiusKm]"][min="1"][max="50"][inputmode="numeric"]:not([required])');
        $client->submitForm('Enregistrer', [
            'delivery_point[type]' => DeliveryPointType::Home->value,
            'delivery_point[name]' => 'À domicile',
            'delivery_point[zoneTown]' => 'Crest',
            'delivery_point[zoneRadiusKm]' => '15',
        ]);

        self::assertResponseRedirects('/pro/points-de-livraison');
        $point = $this->point('À domicile');
        self::assertTrue($point->hasDeliveryZone());
        self::assertSame(15, $point->getZoneRadiusKm());
        self::assertEqualsWithDelta(44.7283, $point->getLatitude(), 0.000001);
        self::assertSame(['Crest'], FakeBan::queries(), 'Only the town of the zone is sent.');

        $client->followRedirect();
        self::assertSelectorTextContains('[data-geocoding="zone"]', 'Zone visible dans l\'annuaire : 15 km autour de Crest');
    }

    public function testAZoneNeedsBothItsTownAndItsRadius(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        foreach ([['Crest', ''], ['', '10'], ['Crest', '0'], ['Crest', '51']] as [$town, $radius]) {
            $client->request('GET', '/pro/points-de-livraison/nouveau');
            $client->submitForm('Enregistrer', [
                'delivery_point[type]' => DeliveryPointType::Home->value,
                'delivery_point[name]' => 'À domicile',
                'delivery_point[zoneTown]' => $town,
                'delivery_point[zoneRadiusKm]' => $radius,
            ]);
            self::assertResponseStatusCodeSame(422, $town.'/'.$radius);
        }
        self::assertSelectorTextContains('main', 'Indique un rayon entre 1 et 50 km.');
        self::assertSame([], FakeBan::queries());
    }

    public function testTheListSaysWhetherTheZoneIsVisible(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $shop = $producer->getShop();
        DeliveryPointFactory::new()->home()->create(['shop' => $shop, 'name' => 'Sans zone']);
        DeliveryPointFactory::new()->home()->create(['shop' => $shop, 'name' => 'Zone inconnue', 'zoneTown' => 'Nulle-part', 'zoneRadiusKm' => 5]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/points-de-livraison');

        self::assertSelectorExists('[data-geocoding="no-zone"]');
        self::assertSelectorTextContains('[data-geocoding="zone-missing"]', 'Commune de ta zone pas encore localisée');
    }

    public function testTheCatchUpCommandAlsoLocatesDeliveryZones(): void
    {
        FakeBan::knows('Crest', 44.7283, 5.0222, city: 'Crest', postcode: '26400');
        self::bootKernel();
        DeliveryPointFactory::new()->home()->create(['name' => 'À domicile', 'zoneTown' => 'Crest', 'zoneRadiusKm' => 10]);
        DeliveryPointFactory::new()->home()->create(['name' => 'Sans zone']);

        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('No kernel.'))->find('app:delivery-points:geocode'));
        $tester->execute([]);

        self::assertSame(['Crest'], FakeBan::queries());
        self::assertTrue($this->point('À domicile')->hasCoordinates());
    }

    private function point(string $name): DeliveryPoint
    {
        $repository = self::getContainer()->get(DeliveryPointRepository::class);
        $repository->createQueryBuilder('p')->getEntityManager()->clear();
        $point = $repository->findOneBy(['name' => $name]);
        self::assertNotNull($point);

        return $point;
    }
}
