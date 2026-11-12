<?php

declare(strict_types=1);

namespace App\Tests\Recurring;

use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Entity\RecurringPlanItem;
use App\Recurring\Enum\OccurrenceOutcome;
use App\Recurring\Enum\RecurringEndReason;
use App\Recurring\Enum\RecurringMode;
use App\Recurring\Enum\RecurringOrderStatus;
use App\Recurring\Enum\RecurringTerm;
use App\Shared\Service\Quantity;
use App\Shop\Entity\User;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Producer side: plan settings, subscribers, suspension and end, isolation between shops (SPEC §1.3).
 */
final class ProRecurringTest extends WebTestCase
{
    use Factories;
    use RecurringScenario;

    public function testTheProducerOffersABasketOnARecurrence(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $basket = ProductFactory::createOne(['shop' => $shop, 'name' => 'Panier de légumes', 'priceCents' => 1800]);
        $radish = ProductFactory::createOne(['shop' => $shop, 'name' => 'Radis']);
        $tomatoes = ProductFactory::new()->byWeight(250)->create(['shop' => $shop, 'name' => 'Tomates']);
        $schedule = self::schedule($shop, [$basket, $radish, $tomatoes]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/recurrences');
        self::assertSelectorExists(\sprintf('a[href="/pro/recurrences/%d/panier"]', $schedule->getId()));
        $client->request('GET', '/pro/paniers');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-schedules-without-plan]', 'Fournée de la semaine');
        self::assertSelectorTextContains('nav', 'Paniers récurrents');

        $client->request('GET', \sprintf('/pro/recurrences/%d/panier', $schedule->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Proposer un panier récurrent');
        $client->submitForm('Enregistrer', [
            'recurring_plan[enabled]' => '1',
            'recurring_plan[title]' => 'Panier du mardi',
            'recurring_plan[mode]' => 'basket',
            'recurring_plan[maxShares]' => '2',
            'recurring_plan[capacity]' => '12',
            'recurring_plan[term]' => 'open_ended',
            'recurring_plan[noticeDays]' => '7',
            'recurring_plan[maxPauses]' => '3',
            'recurring_plan[conditions]' => 'Panier oublié, panier perdu.',
            \sprintf('recurring_plan[quantities][%d]', $basket->getId()) => '1',
            \sprintf('recurring_plan[quantities][%d]', $radish->getId()) => '0',
            \sprintf('recurring_plan[quantities][%d]', $tomatoes->getId()) => '0,5',
        ]);

        self::assertResponseRedirects('/pro/paniers');
        $plan = self::schedulePlan($schedule);
        self::assertInstanceOf(RecurringPlan::class, $plan);
        self::assertTrue($plan->isEnabled());
        self::assertSame('Panier du mardi', $plan->getTitle());
        self::assertSame(RecurringMode::Basket, $plan->getMode());
        self::assertSame([2, 12, 7, 3], [$plan->getMaxShares(), $plan->getCapacity(), $plan->getNoticeDays(), $plan->getMaxPauses()]);
        self::assertSame('Panier oublié, panier perdu.', $plan->getConditions());
        self::assertSame([Quantity::units(1), 500], array_map(static fn (RecurringPlanItem $item): int => $item->getQuantity(), $plan->getItems()->toArray()));
        self::assertNull($plan->getItemFor($radish));

        $client->followRedirect();
        self::assertSelectorTextContains('[data-plan]', 'Panier du mardi');
        self::assertSelectorTextContains('[data-plan]', 'Inscriptions ouvertes');
        self::assertSelectorTextContains('[data-plan]', '0 / 12 abonné');

        // Editing: the quantities are prefilled, a product taken out leaves the plan.
        $crawler = $client->request('GET', \sprintf('/pro/recurrences/%d/panier', $schedule->getId()));
        self::assertSame('0,5', $crawler->filter(\sprintf('#recurring_plan_quantities_%d', $tomatoes->getId()))->attr('value'));
        $client->submitForm('Enregistrer', [\sprintf('recurring_plan[quantities][%d]', $tomatoes->getId()) => '0']);
        self::assertResponseRedirects('/pro/paniers');
        $plan = self::reload(RecurringPlan::class, $plan->getId());
        self::assertCount(1, $plan->getItems());
    }

    public function testASeasonContractNeedsItsDatesAndAProduct(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $cheese = ProductFactory::createOne(['shop' => $shop, 'name' => 'Picodon']);
        $schedule = self::schedule($shop, [$cheese]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', \sprintf('/pro/recurrences/%d/panier', $schedule->getId()));
        $client->submitForm('Enregistrer', [
            'recurring_plan[title]' => 'Contrat',
            'recurring_plan[term]' => 'season',
            \sprintf('recurring_plan[quantities][%d]', $cheese->getId()) => '0',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-form-errors]', 'Choisis au moins un produit et sa quantité.');
        self::assertAnySelectorTextContains('form', 'Indique la première livraison du contrat.');
        self::assertAnySelectorTextContains('form', 'Indique la dernière livraison du contrat.');
        self::assertNull(self::schedulePlan($schedule));

        $first = $schedule->nextDeliveryDates(self::now())[0];
        $client->submitForm('Enregistrer', [
            'recurring_plan[title]' => 'Contrat',
            'recurring_plan[mode]' => 'choice',
            'recurring_plan[term]' => 'season',
            'recurring_plan[seasonStartsOn]' => $first->format('Y-m-d'),
            'recurring_plan[seasonEndsOn]' => $first->modify('+3 weeks')->format('Y-m-d'),
            \sprintf('recurring_plan[quantities][%d]', $cheese->getId()) => '6',
        ]);
        self::assertResponseRedirects('/pro/paniers');
        $plan = self::schedulePlan($schedule);
        self::assertInstanceOf(RecurringPlan::class, $plan);
        self::assertSame(RecurringTerm::Season, $plan->getTerm());
        self::assertFalse($plan->isEnabled());
        self::assertCount(4, $plan->remainingSeasonDates(self::now()));
    }

    public function testTheProducerSeesTheSubscribersAndTheirGeneratedOrders(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $customer = CustomerFactory::createOne(['firstName' => 'Claire', 'lastName' => 'Vincent', 'phone' => '06 11 22 33 01']);
        $recurringOrder = self::subscription($plan, $customer, shares: 2);
        $recurringOrder->pause(self::now()->modify('+30 days'), self::now());
        self::entityManager()->flush();
        $sale = self::openSale($plan);
        self::generator()->generateForSale($sale);
        $client->loginUser($this->producerOf($plan), 'producer');

        $client->request('GET', '/pro/paniers');
        self::assertSelectorTextContains('[data-subscribers]', 'Claire Vincent');
        self::assertSelectorTextContains('[data-subscribers]', '2 paniers');
        self::assertSelectorTextContains('[data-subscribers]', '36,00');

        $client->clickLink('Claire Vincent');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Claire Vincent');
        self::assertSelectorTextContains('[data-occurrences]', OccurrenceOutcome::Placed->label());
        self::assertSelectorExists(\sprintf('[data-occurrences] a[href="/pro/ventes/%d"]', $sale->getId()));
        self::assertSelectorTextContains('[data-paused-dates]', self::now()->modify('+30 days')->format('d/m'));
        self::assertAnySelectorTextContains('a[href^="tel:"]', '06 11 22 33 01');
    }

    public function testSuspendResumeAndEndASubscription(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $recurringOrder = self::subscription($plan);
        $client->loginUser($this->producerOf($plan), 'producer');
        $path = \sprintf('/pro/paniers/abonnes/%d', $recurringOrder->getId());

        $client->request('GET', $path);
        $client->submitForm('Suspendre');
        self::assertResponseRedirects($path);
        self::assertSame(RecurringOrderStatus::Suspended, self::reload(RecurringOrder::class, $recurringOrder->getId())->getStatus());
        self::assertSame(['recurring_suspended'], self::sentTypes());

        $client->followRedirect();
        $client->submitForm('Reprendre l\'abonnement');
        self::assertSame(RecurringOrderStatus::Active, self::reload(RecurringOrder::class, $recurringOrder->getId())->getStatus());

        $client->followRedirect();
        $client->submitForm('Mettre fin à l\'abonnement');
        $ended = self::reload(RecurringOrder::class, $recurringOrder->getId());
        self::assertSame(RecurringOrderStatus::Ended, $ended->getStatus());
        self::assertSame(RecurringEndReason::Producer, $ended->getEndReason());
        self::assertSame(['recurring_ended'], self::sentTypes());
        self::assertStringContainsString('a mis fin à ton abonnement', (string) self::emails()[0]->getSubject());

        $client->followRedirect();
        self::assertSelectorNotExists('form[action$="/terminer"]');
        $client->request('POST', $path.'/terminer', ['_csrf_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-slot="alert"]', 'déjà terminé');
    }

    public function testAForgedFormIsRefused(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $recurringOrder = self::subscription($plan);
        $client->loginUser($this->producerOf($plan), 'producer');

        $client->request('POST', \sprintf('/pro/paniers/abonnes/%d/suspendre', $recurringOrder->getId()), ['_csrf_token' => 'forged'], [], ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(RecurringOrderStatus::Active, self::reload(RecurringOrder::class, $recurringOrder->getId())->getStatus());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function foreignRoutes(): iterable
    {
        yield 'pro_recurring_plan_edit' => ['GET', '/pro/recurrences/{schedule}/panier'];
        yield 'pro_recurring_plan_edit (POST)' => ['POST', '/pro/recurrences/{schedule}/panier'];
        yield 'pro_recurring_order_show' => ['GET', '/pro/paniers/abonnes/{subscription}'];
        yield 'pro_recurring_order_suspend' => ['POST', '/pro/paniers/abonnes/{subscription}/suspendre'];
        yield 'pro_recurring_order_resume' => ['POST', '/pro/paniers/abonnes/{subscription}/reprendre'];
        yield 'pro_recurring_order_end' => ['POST', '/pro/paniers/abonnes/{subscription}/terminer'];
    }

    #[DataProvider('foreignRoutes')]
    public function testAnotherProducerGetsNotFound(string $method, string $path): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $recurringOrder = self::subscription($plan);
        $intruder = self::subscribedProducer();
        $client->loginUser($intruder, 'producer');

        $client->request($method, strtr($path, ['{schedule}' => (string) $plan->getSchedule()->getId(), '{subscription}' => (string) $recurringOrder->getId()]), ['_csrf_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(RecurringOrderStatus::Active, self::reload(RecurringOrder::class, $recurringOrder->getId())->getStatus());
    }

    public function testTheListOnlyShowsTheSubscribersOfTheShop(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        self::subscription($plan, CustomerFactory::createOne(['lastName' => 'Étrangère']));
        $intruder = UserFactory::createOne();
        $client->loginUser($intruder, 'producer');

        $client->request('GET', '/pro/paniers');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-plan]');
        self::assertSelectorTextNotContains('main', 'Étrangère');
    }

    public function testAHomeDeliveryAndAnArchivedProductKeptInThePlan(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $plan = self::basketPlan($shop);
        $product = self::first($plan->getItems())->getProduct();
        $product->setArchived(true);
        DeliveryPointFactory::new()->home()->create(['shop' => $shop]);
        self::entityManager()->flush();
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', \sprintf('/pro/recurrences/%d/panier', $plan->getSchedule()->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Modifier le panier récurrent');
        self::assertCount(1, $crawler->filter(\sprintf('#recurring_plan_quantities_%d', $product->getId())));
    }

    private function producerOf(RecurringPlan $plan): User
    {
        $producer = $plan->getShop()->getUsers()->first();
        self::assertInstanceOf(User::class, $producer);

        return $producer;
    }
}
