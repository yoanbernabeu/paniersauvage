<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Sale\Entity\SaleSchedule;
use App\Sale\Enum\SaleStatus;
use App\Sale\Repository\SaleRepository;
use App\Sale\Repository\SaleScheduleRepository;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ProductFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ScheduleControllerTest extends WebTestCase
{
    use Factories;
    use SaleScenario;

    public function testIndexShowsAnEmptyState(): void
    {
        $client = self::createClient();
        $client->loginUser(self::subscribedProducer(), 'producer');

        $client->request('GET', '/pro/recurrences');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Récurrences');
        self::assertSelectorTextContains('[data-slot="empty"]', 'Pas encore de récurrence');
    }

    public function testTheFormPreviewsTheNextSaleLive(): void
    {
        $client = self::createClient();
        $client->loginUser(self::subscribedProducer(), 'producer');

        $crawler = $client->request('GET', '/pro/recurrences/nouvelle');

        self::assertSelectorExists('form[data-controller="schedule-preview"]');
        foreach (['weekday' => 'select', 'start' => 'input', 'end' => 'input', 'opens' => 'input', 'deadlineDays' => 'input', 'deadlineTime' => 'input'] as $target => $tag) {
            self::assertCount(1, $crawler->filter(\sprintf('%s[data-schedule-preview-target="%s"]', $tag, $target)), $target);
        }
        self::assertSelectorExists('[data-schedule-preview-target="output"][aria-live="polite"][hidden]');
    }

    public function testIndexListsTheRecurrencesOfTheShop(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])]);
        $paused = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])], active: false);
        $paused->setName('Marché du samedi');
        self::entityManager()->flush();
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/recurrences');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '[data-schedule]');
        self::assertAnySelectorTextContains('[data-schedule]', 'Fournée de la semaine');
        self::assertAnySelectorTextContains('[data-schedule]', 'En pause');
        self::assertAnySelectorTextContains('[data-schedule] button', 'Réactiver');
    }

    public function testCreatingARecurrenceGeneratesTheComingSales(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $square = DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Place du village']);
        $bread = ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne']);
        $seasonal = ProductFactory::new()->seasonal()->create(['shop' => $shop, 'name' => 'Galette']);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/recurrences/nouvelle');
        self::assertResponseIsSuccessful();

        // Prefilled: the only delivery point and the permanent range are ticked.
        self::assertSame([(string) $square->getId()], $crawler->filter('input[name^="sale_schedule[deliveryPoints]"][checked]')->extract(['value']));
        self::assertSame([(string) $bread->getId()], $crawler->filter('input[name^="sale_schedule[products]"][checked]')->extract(['value']));
        self::assertCount(2, $crawler->filter('input[name^="sale_schedule[products]"]'));
        $form = $crawler->selectButton('Créer la récurrence')->form();
        self::assertStringContainsString('Galette (du moment)', $crawler->text());
        self::assertNotNull($seasonal->getId());

        $weekday = (int) new \DateTimeImmutable('+5 days')->format('N');
        $client->submit($form, [
            'sale_schedule[name]' => 'Livraison de la semaine',
            'sale_schedule[weekday]' => (string) $weekday,
            'sale_schedule[deliveryStartTime]' => '17:30',
            'sale_schedule[deliveryEndTime]' => '19:00',
            'sale_schedule[deadlineDaysBefore]' => '2',
            'sale_schedule[deadlineTime]' => '20:00',
            'sale_schedule[opensDaysBefore]' => '10',
            'sale_schedule[editCutoffHours]' => '12',
        ]);

        self::assertResponseRedirects('/pro/recurrences');
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', '4 ventes à venir ont été créées');

        $schedules = self::getContainer()->get(SaleScheduleRepository::class)->findByShop($shop);
        self::assertCount(1, $schedules);
        $schedule = $schedules[0];
        self::assertSame('Livraison de la semaine', $schedule->getName());
        self::assertSame($weekday, $schedule->getWeekday());
        self::assertSame('17:30', $schedule->getDeliveryStartTime()->format('H:i'));
        self::assertSame(2, $schedule->getDeadlineDaysBefore());
        self::assertSame(10, $schedule->getOpensDaysBefore());
        self::assertSame(12, $schedule->getEditCutoffHours());
        self::assertTrue($schedule->isActive());
        self::assertCount(1, $schedule->getDeliveryPoints());
        self::assertCount(1, $schedule->getProducts());

        $sales = self::getContainer()->get(SaleRepository::class)->findBySchedule($schedule);
        self::assertCount(4, $sales);
        // The first one opens 10 days before a delivery in 5 days: it is opened right away.
        self::assertSame(SaleStatus::Open, $sales[0]->getStatus());
        self::assertSame(SaleStatus::Scheduled, $sales[3]->getStatus());
        self::assertSame('17:30', $sales[0]->getDeliveries()->first() ? $sales[0]->getDeliveries()->first()->getStartsAt()->format('H:i') : null);
        self::assertEquals($sales[0]->getDeadlineAt()->modify('-12 hours'), $sales[0]->getEditCutoffAt());
    }

    public function testARecurrenceNeedsADeliveryPoint(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        DeliveryPointFactory::createOne(['shop' => $shop]);
        DeliveryPointFactory::createOne(['shop' => $shop]);
        ProductFactory::new()->seasonal()->create(['shop' => $shop]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/recurrences/nouvelle');
        $client->submitForm('Créer la récurrence', [
            'sale_schedule[name]' => '',
            'sale_schedule[deadlineDaysBefore]' => '7',
            'sale_schedule[opensDaysBefore]' => '7',
        ]);

        self::assertResponseStatusCodeSame(422);
        $text = $client->getCrawler()->text();
        self::assertStringContainsString('Choisis au moins un point de livraison.', $text);
        self::assertStringContainsString('La vente doit ouvrir avant la date limite.', $text);
        self::assertSame(0, self::getContainer()->get(SaleScheduleRepository::class)->count([]));
    }

    public function testCreatingARecurrenceWithoutSubscriptionGeneratesNoSale(): void
    {
        $client = self::createClient();
        $producer = self::expiredProducer();
        $shop = self::shopOf($producer);
        DeliveryPointFactory::createOne(['shop' => $shop]);
        ProductFactory::createOne(['shop' => $shop]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/recurrences/nouvelle');
        self::assertSelectorExists('[data-subscription-alert]');
        $client->submitForm('Créer la récurrence', ['sale_schedule[name]' => 'Livraison du lundi']);

        self::assertResponseRedirects('/pro/recurrences');
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="warning"]', 'aucune vente n\'a été générée');
        self::assertSame(1, self::getContainer()->get(SaleScheduleRepository::class)->count([]));
        self::assertSame(0, self::getContainer()->get(SaleRepository::class)->count([]));
    }

    public function testEditingARecurrence(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/recurrences/'.$schedule->getId().'/modifier');
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('sale_schedule[name]', 'Fournée de la semaine');

        $client->submitForm('Enregistrer', ['sale_schedule[name]' => 'Fournée du vendredi', 'sale_schedule[deadlineTime]' => '12:00']);

        self::assertResponseRedirects('/pro/recurrences');
        $reloaded = self::reload(SaleSchedule::class, $schedule->getId());
        self::assertSame('Fournée du vendredi', $reloaded->getName());
        self::assertSame('12:00', $reloaded->getDeadlineTime()->format('H:i'));
        // Saving an active recurrence also generates its missing sales.
        self::assertCount(4, self::getContainer()->get(SaleRepository::class)->findBySchedule($reloaded));
    }

    public function testTogglePausesThenReactivatesAndGenerates(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/recurrences');
        $client->submitForm('Mettre en pause');

        self::assertResponseRedirects('/pro/recurrences');
        self::assertFalse(self::reload(SaleSchedule::class, $schedule->getId())->isActive());
        self::assertSame(0, self::getContainer()->get(SaleRepository::class)->count([]));

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'en pause');
        $client->submitForm('Réactiver');

        self::assertResponseRedirects('/pro/recurrences');
        $reloaded = self::reload(SaleSchedule::class, $schedule->getId());
        self::assertTrue($reloaded->isActive());
        self::assertCount(4, self::getContainer()->get(SaleRepository::class)->findBySchedule($reloaded));
    }

    public function testToggleRequiresAValidCsrfToken(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])]);
        $client->loginUser($producer, 'producer');

        $client->request('POST', '/pro/recurrences/'.$schedule->getId().'/activation');

        self::assertResponseStatusCodeSame(403);
        self::assertTrue(self::reload(SaleSchedule::class, $schedule->getId())->isActive());
    }

    public function testToggleIsPostOnly(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/recurrences/'.$schedule->getId().'/activation');

        self::assertResponseStatusCodeSame(405);
    }
}
