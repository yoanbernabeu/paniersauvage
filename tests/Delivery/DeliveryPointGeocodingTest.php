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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

final class DeliveryPointGeocodingTest extends WebTestCase
{
    use Factories;
    use FakeBanTrait;

    private const string ADDRESS = "Place de l'église\n26400 Saoû";
    private const string QUERY = "Place de l'église 26400 Saoû";

    public function testANewPointIsGeocodedWhenSaved(): void
    {
        FakeBan::knows(self::QUERY, 44.646423, 5.062418);
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/points-de-livraison/nouveau');
        $client->submitForm('Enregistrer', [
            'delivery_point[type]' => DeliveryPointType::MeetingPoint->value,
            'delivery_point[name]' => 'Place du village',
            'delivery_point[address]' => self::ADDRESS,
        ]);

        self::assertResponseRedirects('/pro/points-de-livraison');
        $point = $this->point('Place du village');
        self::assertEqualsWithDelta(44.646423, $point->getLatitude(), 0.000001);
        self::assertEqualsWithDelta(5.062418, $point->getLongitude(), 0.000001);
        self::assertNotNull($point->getGeocodedAt());
        self::assertSame([self::QUERY], FakeBan::queries(), 'Only the public address of the point is sent, on one line.');

        $client->followRedirect();
        self::assertSelectorExists('[data-geocoding="located"]');
    }

    public function testAPointIsSavedEvenWhenTheApiIsDown(): void
    {
        FakeBan::down();
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/points-de-livraison/nouveau');
        $client->submitForm('Enregistrer', [
            'delivery_point[type]' => DeliveryPointType::TourStop->value,
            'delivery_point[name]' => 'Arrêt de la mairie',
            'delivery_point[address]' => self::ADDRESS,
        ]);

        self::assertResponseRedirects('/pro/points-de-livraison');
        $point = $this->point('Arrêt de la mairie');
        self::assertFalse($point->hasCoordinates());

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Arrêt de la mairie');
        self::assertSelectorExists('[data-geocoding="missing"]');
    }

    public function testAPointIsSavedEvenWhenTheApiTimesOut(): void
    {
        FakeBan::timeout();
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/points-de-livraison/nouveau');
        $client->submitForm('Enregistrer', [
            'delivery_point[name]' => 'Place du marché',
            'delivery_point[address]' => self::ADDRESS,
        ]);

        self::assertResponseRedirects('/pro/points-de-livraison');
        self::assertFalse($this->point('Place du marché')->hasCoordinates());
    }

    public function testAHomeDeliveryIsNeverGeocoded(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/points-de-livraison/nouveau');
        $client->submitForm('Enregistrer', [
            'delivery_point[type]' => DeliveryPointType::Home->value,
            'delivery_point[name]' => 'À domicile',
            'delivery_point[address]' => self::ADDRESS,
        ]);

        self::assertResponseRedirects('/pro/points-de-livraison');
        self::assertFalse($this->point('À domicile')->hasCoordinates());
        self::assertSame([], FakeBan::queries());
    }

    public function testEditingOnlyTheNameKeepsTheCoordinatesAndChangingTheAddressLooksThemUpAgain(): void
    {
        FakeBan::knows('Place du Capitole 31000 Toulouse', 43.604468, 1.443382);
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $point = DeliveryPointFactory::new()->located(44.646423, 5.062418)->create(['shop' => $producer->getShop(), 'name' => 'Place', 'address' => self::ADDRESS]);
        $id = $point->getId();
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/points-de-livraison/'.$id.'/modifier');
        $client->submitForm('Enregistrer', ['delivery_point[name]' => 'Place de l\'église']);
        self::assertResponseRedirects('/pro/points-de-livraison');
        self::assertSame([], FakeBan::queries());
        self::assertEqualsWithDelta(44.646423, $this->point('Place de l\'église')->getLatitude(), 0.000001);

        $client->request('GET', '/pro/points-de-livraison/'.$id.'/modifier');
        $client->submitForm('Enregistrer', ['delivery_point[address]' => "Place du Capitole\n31000 Toulouse"]);
        self::assertResponseRedirects('/pro/points-de-livraison');
        self::assertSame(['Place du Capitole 31000 Toulouse'], FakeBan::queries());
        self::assertEqualsWithDelta(43.604468, $this->point('Place de l\'église')->getLatitude(), 0.000001);
    }

    public function testAnAddressThatCannotBeFoundClearsTheOldCoordinates(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $point = DeliveryPointFactory::new()->located(44.646423, 5.062418)->create(['shop' => $producer->getShop(), 'name' => 'Place', 'address' => self::ADDRESS]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/points-de-livraison/'.$point->getId().'/modifier');
        $client->submitForm('Enregistrer', ['delivery_point[address]' => 'Lieu-dit inconnu']);

        self::assertResponseRedirects('/pro/points-de-livraison');
        self::assertFalse($this->point('Place')->hasCoordinates());
    }

    public function testTheCatchUpCommandGeocodesThePointsWithoutCoordinates(): void
    {
        FakeBan::down();
        $missing = DeliveryPointFactory::createOne(['name' => 'Sans coordonnées', 'address' => self::ADDRESS]);
        $unknown = DeliveryPointFactory::createOne(['name' => 'Introuvable', 'address' => 'Lieu-dit inconnu']);
        $located = DeliveryPointFactory::new()->located(43.6, 1.44)->create(['name' => 'Déjà localisé', 'address' => 'Place du Capitole 31000 Toulouse']);
        $home = DeliveryPointFactory::new()->home()->create();
        self::assertFalse($missing->hasCoordinates());

        FakeBan::reset();
        FakeBan::knows(self::QUERY, 44.646423, 5.062418);
        $tester = $this->runGeocodeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 located, 1 not found', $tester->getDisplay());
        self::assertEqualsWithDelta(44.646423, $this->point('Sans coordonnées')->getLatitude(), 0.000001);
        self::assertFalse($this->point('Introuvable')->hasCoordinates());
        self::assertEqualsWithDelta(43.6, $this->point('Déjà localisé')->getLatitude(), 0.000001);
        self::assertNotContains('Place du Capitole 31000 Toulouse', FakeBan::queries());
        self::assertFalse($home->hasCoordinates());
        unset($unknown, $located);
    }

    public function testTheCatchUpCommandFailsWhenTheApiIsDown(): void
    {
        FakeBan::down();
        DeliveryPointFactory::createOne(['address' => self::ADDRESS]);

        $tester = $this->runGeocodeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('1 skipped because the API was unavailable', $tester->getDisplay());
    }

    private function runGeocodeCommand(): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:delivery-points:geocode'));
        $tester->execute([]);

        return $tester;
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
