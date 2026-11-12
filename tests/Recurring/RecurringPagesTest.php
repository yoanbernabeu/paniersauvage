<?php

declare(strict_types=1);

namespace App\Tests\Recurring;

use App\Recurring\Entity\RecurringOccurrence;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Enum\OccurrenceOutcome;
use App\Sale\Enum\SaleStatus;
use App\Shop\Entity\User;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\ProductFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Messages, guards and lists of the pages.
 */
final class RecurringPagesTest extends WebTestCase
{
    use Factories;
    use RecurringScenario;

    private const array SAME_ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];
    private const array CROSS_SITE = ['HTTP_ORIGIN' => 'https://evil.example'];

    public function testTheSubscriberPageMessages(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $plan->setNoticeDays(7);
        $customer = CustomerFactory::createOne();
        $recurringOrder = self::subscription($plan, $customer);
        $client->loginUser($customer, 'main');
        $path = \sprintf('/mon-compte/paniers/%d', $recurringOrder->getId());

        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $client->submitForm('Enregistrer', ['recurring_order[shares]' => '2']);
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'C\'est noté : tes prochaines commandes suivront ces changements.');

        $client->submitForm('Résilier mon abonnement');
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Ta résiliation est enregistrée. Préavis oblige, les livraisons jusqu\'au '.self::now()->modify('+7 days')->format('d/m/Y').' restent prévues.');
    }

    public function testAnImmediateCancellationMessage(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne();
        $recurringOrder = self::subscription(self::basketPlan(), $customer);
        $client->loginUser($customer, 'main');

        $client->request('GET', \sprintf('/mon-compte/paniers/%d', $recurringOrder->getId()));
        $client->submitForm('Résilier mon abonnement');
        $client->followRedirect();

        self::assertSelectorTextContains('main', 'Ton abonnement est résilié : plus aucune commande ne sera créée.');
    }

    public function testForgedFormsAreRefused(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $customer = CustomerFactory::createOne();
        $recurringOrder = self::subscription($plan, $customer);
        $client->loginUser($customer, 'main');
        $day = $plan->getSchedule()->nextDeliveryDates(self::now())[0]->format('Y-m-d');

        foreach (['/pause', '/reprise', '/resilier'] as $suffix) {
            $client->request('POST', \sprintf('/mon-compte/paniers/%d%s', $recurringOrder->getId(), $suffix), ['_csrf_token' => 'forged', 'date' => $day], [], self::CROSS_SITE);
            self::assertResponseStatusCodeSame(403, $suffix);
        }

        $recurringOrder = self::reload(RecurringOrder::class, $recurringOrder->getId());
        self::assertSame([], $recurringOrder->getPausedDates());
        self::assertFalse($recurringOrder->isEnded());
    }

    public function testThePastDeliveriesAreListedAsHistory(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $customer = CustomerFactory::createOne();
        $recurringOrder = self::subscription($plan, $customer);
        $past = self::openSale($plan, attributes: ['status' => SaleStatus::Done, 'deliveryDate' => self::now()->modify('-6 days')->setTime(0, 0)]);
        $coming = self::openSale($plan);
        self::entityManager()->persist(new RecurringOccurrence($recurringOrder, $past, OccurrenceOutcome::Paused, self::now()));
        self::entityManager()->flush();
        self::generator()->generateForSale($coming);
        $client->loginUser($customer, 'main');

        $client->request('GET', \sprintf('/mon-compte/paniers/%d', $recurringOrder->getId()));

        self::assertSelectorCount(1, '[data-section="recurring-history"] li');
        self::assertSelectorTextContains('[data-section="recurring-history"]', 'En pause');
    }

    public function testAPlanOfAnotherShopIsNotFound(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $other = self::basketPlan();

        $client->request('GET', \sprintf('/%s/panier/%d', $plan->getShop()->getSlug(), $other->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheProducerListsTheRecurrencesWithoutPlan(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $plan = self::basketPlan($shop);
        $plan->getSchedule()->setName('Avec panier');
        $free = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])]);
        $free->setName('Sans panier');
        self::entityManager()->flush();
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/paniers');

        self::assertSelectorCount(1, '[data-schedules-without-plan] li');
        self::assertSelectorTextContains('[data-schedules-without-plan]', 'Sans panier');
        self::assertSelectorTextNotContains('[data-schedules-without-plan]', 'Avec panier');
    }

    public function testThePlanFormOffersTheActiveProductsOfTheRecurrence(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $bread = ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain']);
        $old = ProductFactory::new()->archived()->create(['shop' => $shop, 'name' => 'Ancien']);
        $other = ProductFactory::createOne(['shop' => $shop, 'name' => 'Hors récurrence']);
        $schedule = self::schedule($shop, [$bread, $old]);
        $wholeRange = self::schedule($shop, [], daysAhead: 3);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', \sprintf('/pro/recurrences/%d/panier', $schedule->getId()));
        self::assertCount(1, $crawler->filter('[data-plan-items] input'));
        self::assertSelectorExists(\sprintf('#recurring_plan_quantities_%d', $bread->getId()));

        $crawler = $client->request('GET', \sprintf('/pro/recurrences/%d/panier', $wholeRange->getId()));
        self::assertCount(2, $crawler->filter('[data-plan-items] input'), 'No product ticked: the whole active range.');
        self::assertSelectorExists(\sprintf('#recurring_plan_quantities_%d', $other->getId()));
    }

    public function testTheProducerMessagesWhenSaving(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $plan->setEnabled(false);
        self::entityManager()->flush();
        $producer = $this->producerOf($plan);
        $client->loginUser($producer, 'producer');
        $path = \sprintf('/pro/recurrences/%d/panier', $plan->getSchedule()->getId());
        $product = self::first($plan->getItems())->getProduct();

        $client->request('GET', $path);
        $client->submitForm('Enregistrer');
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Ton panier est enregistré. Coche « Ouvrir les inscriptions » quand tu es prêt.');

        $client->request('GET', $path);
        $client->submitForm('Enregistrer', ['recurring_plan[enabled]' => '1']);
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Ton panier est enregistré et ouvert aux inscriptions.');

        // An invalid form changes nothing.
        $client->request('GET', $path);
        $client->submitForm('Enregistrer', ['recurring_plan[title]' => 'Nouveau nom', \sprintf('recurring_plan[quantities][%d]', $product->getId()) => '0']);
        self::assertResponseStatusCodeSame(422);
        $plan = self::reload(RecurringPlan::class, $plan->getId());
        self::assertSame('Panier de la semaine', $plan->getTitle());
        self::assertCount(1, $plan->getItems());
    }

    public function testTheProducerMessagesOnASubscription(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $recurringOrder = self::subscription($plan);
        $client->loginUser($this->producerOf($plan), 'producer');
        $path = \sprintf('/pro/paniers/abonnes/%d', $recurringOrder->getId());

        $this->post($client, $path.'/suspendre');
        self::assertSelectorTextContains('main', 'L\'abonnement est suspendu : plus aucune commande ne sera créée. L\'abonné est prévenu par e-mail.');
        $this->post($client, $path.'/reprendre');
        self::assertSelectorTextContains('main', 'L\'abonnement reprend : une commande sera créée à chaque vente.');
        $this->post($client, $path.'/reprendre');
        self::assertSelectorTextContains('main', 'Cet abonnement n\'est pas suspendu.');
        $this->post($client, $path.'/terminer');
        self::assertSelectorTextContains('main', 'L\'abonnement est terminé. L\'abonné est prévenu par e-mail ; ses commandes déjà créées restent dans tes ventes.');
    }

    private function post(KernelBrowser $client, string $path): void
    {
        $client->request('POST', $path, ['_csrf_token' => 'csrf-token'], [], self::SAME_ORIGIN);
        self::assertResponseRedirects();
        $client->followRedirect();
    }

    private function producerOf(RecurringPlan $plan): User
    {
        return self::first($plan->getShop()->getUsers());
    }
}
