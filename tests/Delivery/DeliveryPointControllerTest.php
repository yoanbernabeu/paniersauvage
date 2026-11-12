<?php

declare(strict_types=1);

namespace App\Tests\Delivery;

use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Enum\DeliveryPointType;
use App\Delivery\Repository\DeliveryPointRepository;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class DeliveryPointControllerTest extends WebTestCase
{
    use Factories;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function producerRoutes(): iterable
    {
        yield 'index' => ['GET', '/pro/points-de-livraison'];
        yield 'new' => ['GET', '/pro/points-de-livraison/nouveau'];
        yield 'edit' => ['GET', '/pro/points-de-livraison/1/modifier'];
        yield 'archive' => ['POST', '/pro/points-de-livraison/1/archiver'];
    }

    #[DataProvider('producerRoutes')]
    public function testRoutesRequireAuthentication(string $method, string $path): void
    {
        $client = self::createClient();
        $client->request($method, $path);

        self::assertResponseRedirects('/pro/connexion');
    }

    #[DataProvider('producerRoutes')]
    public function testAProducerWithoutShopIsDenied(string $method, string $path): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->withoutShop()->create(), 'producer');
        $client->request($method, $path);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNoPointShowsAnEmptyState(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');
        $client->request('GET', '/pro/points-de-livraison');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-slot="empty"]', 'Aucun point de livraison');
        self::assertSelectorExists('[data-slot="empty"] a[href="/pro/points-de-livraison/nouveau"]');
    }

    public function testIndexListsActiveAndArchivedPointsOfTheShop(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $shop = $producer->getShop();
        DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Place du village', 'address' => 'Place de la Mairie, Salles-la-Source', 'instructions' => 'Devant la fontaine']);
        DeliveryPointFactory::new()->home()->create(['shop' => $shop]);
        DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Ancien marché', 'archived' => true]);
        DeliveryPointFactory::createOne(['name' => 'Chez le voisin']);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/points-de-livraison');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-section="active"]', 'Place du village');
        self::assertSelectorTextContains('[data-section="active"]', 'Point de rendez-vous');
        self::assertSelectorTextContains('[data-section="active"]', 'Place de la Mairie, Salles-la-Source');
        self::assertSelectorTextContains('[data-section="active"]', 'Devant la fontaine');
        self::assertSelectorTextContains('[data-section="active"]', 'Livraison à domicile');
        self::assertSelectorTextNotContains('[data-section="active"]', 'Ancien marché');
        self::assertSelectorTextContains('[data-section="archived"]', 'Ancien marché');
        self::assertSelectorTextNotContains('main', 'Chez le voisin');
    }

    /**
     * @return iterable<string, array{DeliveryPointType}>
     */
    public static function types(): iterable
    {
        yield 'meeting point' => [DeliveryPointType::MeetingPoint];
        yield 'tour stop' => [DeliveryPointType::TourStop];
        yield 'home' => [DeliveryPointType::Home];
    }

    #[DataProvider('types')]
    public function testCreatesAPointOfEachType(DeliveryPointType $type): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/points-de-livraison/nouveau');
        self::assertResponseIsSuccessful();
        $client->submitForm('Enregistrer', [
            'delivery_point[type]' => $type->value,
            'delivery_point[name]' => 'Place du village',
            'delivery_point[address]' => 'Place de la Mairie',
            'delivery_point[instructions]' => 'Camionnette blanche',
        ]);

        self::assertResponseRedirects('/pro/points-de-livraison');
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Place du village');
        self::assertSelectorTextContains('[data-section="active"]', $type->label());

        $point = $this->point('Place du village');
        self::assertSame($producer->getShop()?->getId(), $point->getShop()->getId());
        self::assertSame($type, $point->getType());
        self::assertSame('Place de la Mairie', $point->getAddress());
        self::assertSame('Camionnette blanche', $point->getInstructions());
        self::assertFalse($point->isArchived());
    }

    public function testANameIsRequired(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/points-de-livraison/nouveau');
        $client->submitForm('Enregistrer', ['delivery_point[name]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[role="alert"]');
        self::assertSame(0, self::getContainer()->get(DeliveryPointRepository::class)->count([]));
    }

    public function testEditsAPoint(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $point = DeliveryPointFactory::createOne(['shop' => $producer->getShop(), 'name' => 'Place du village']);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/points-de-livraison/'.$point->getId().'/modifier');
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('delivery_point[name]', 'Place du village');

        $client->submitForm('Enregistrer', [
            'delivery_point[type]' => 'tour_stop',
            'delivery_point[name]' => 'Arrêt de Marcillac',
            'delivery_point[address]' => '',
        ]);

        self::assertResponseRedirects('/pro/points-de-livraison');
        $updated = $this->point('Arrêt de Marcillac');
        self::assertSame($point->getId(), $updated->getId());
        self::assertSame(DeliveryPointType::TourStop, $updated->getType());
        self::assertNull($updated->getAddress());
    }

    public function testArchivesAndRestoresAPoint(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $point = DeliveryPointFactory::createOne(['shop' => $producer->getShop(), 'name' => 'Place du village']);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/points-de-livraison');
        $client->submit($crawler->filter('[data-delivery-point="'.$point->getId().'"] form')->form());

        self::assertResponseRedirects('/pro/points-de-livraison');
        self::assertTrue($this->point('Place du village')->isArchived());

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'est archivé');
        self::assertSelectorTextContains('[data-section="archived"]', 'Place du village');

        $client->submit($crawler->filter('[data-section="archived"] [data-delivery-point="'.$point->getId().'"] form')->form());

        self::assertResponseRedirects('/pro/points-de-livraison');
        self::assertFalse($this->point('Place du village')->isArchived());
    }

    public function testArchiveRequiresAValidCsrfToken(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $point = DeliveryPointFactory::createOne(['shop' => $producer->getShop(), 'name' => 'Place du village']);
        $client->loginUser($producer, 'producer');

        $client->request('POST', '/pro/points-de-livraison/'.$point->getId().'/archiver', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/pro/points-de-livraison/'.$point->getId().'/archiver');
        self::assertResponseStatusCodeSame(405);

        self::assertFalse($this->point('Place du village')->isArchived());
    }

    public function testAnotherProducerCannotSeeEditOrArchiveAPoint(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne();
        $point = DeliveryPointFactory::createOne(['shop' => $owner->getShop(), 'name' => 'Place du village']);
        $client->loginUser(UserFactory::createOne(), 'producer');
        $id = $point->getId();

        $client->request('GET', '/pro/points-de-livraison');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Place du village');

        $client->request('GET', '/pro/points-de-livraison/'.$id.'/modifier');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/pro/points-de-livraison/'.$id.'/modifier', ['delivery_point' => ['name' => 'Piraté', 'type' => 'home']]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/pro/points-de-livraison/'.$id.'/archiver', ['_token' => 'whatever']);
        self::assertResponseStatusCodeSame(404);

        $untouched = $this->point('Place du village');
        self::assertSame(DeliveryPointType::MeetingPoint, $untouched->getType());
        self::assertFalse($untouched->isArchived());
    }

    public function testUnknownPointIsNotFound(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/points-de-livraison/999999/modifier');
        self::assertResponseStatusCodeSame(404);
    }

    private function point(string $name): DeliveryPoint
    {
        $point = self::getContainer()->get(DeliveryPointRepository::class)->findOneBy(['name' => $name]);
        self::assertNotNull($point);

        return $point;
    }
}
