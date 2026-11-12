<?php

declare(strict_types=1);

namespace App\Tests\Recurring;

use App\Customer\Entity\Customer;
use App\Legal\LegalDocuments;
use App\Ordering\Enum\OrderStatus;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Enum\RecurringEndReason;
use App\Recurring\Enum\RecurringMode;
use App\Recurring\Enum\RecurringOrderStatus;
use App\Recurring\Enum\RecurringTerm;
use App\Recurring\Repository\RecurringOrderRepository;
use App\Shared\Service\Quantity;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\ProductFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Customer side: discover a plan, subscribe, follow the coming deliveries, pause, change, cancel.
 */
final class CustomerRecurringTest extends WebTestCase
{
    use Factories;
    use RecurringScenario;

    public function testTheShopAndSalePagesInviteToSubscribe(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $sale = self::openSale($plan);
        $shop = $plan->getShop();

        $client->request('GET', '/'.$shop->getSlug());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-recurring-plan]', 'Panier de la semaine');
        self::assertSelectorTextContains('[data-recurring-plan]', 'Sans engagement');
        self::assertSelectorExists(\sprintf('a[href="/%s/panier/%d"]', $shop->getSlug(), $plan->getId()));

        $client->request('GET', \sprintf('/%s/vente/%s', $shop->getSlug(), $sale->getCode()));
        self::assertSelectorExists(\sprintf('[data-sale-recurring-plan="%d"]', $plan->getId()));

        self::reload(RecurringPlan::class, $plan->getId())->setEnabled(false);
        self::entityManager()->flush();
        $client->request('GET', '/'.$shop->getSlug());
        self::assertSelectorNotExists('[data-recurring-plan]');
        $client->request('GET', \sprintf('/%s/vente/%s', $shop->getSlug(), $sale->getCode()));
        self::assertSelectorNotExists('[data-sale-recurring-plan]');
    }

    public function testAShopWithoutAnActiveSubscriptionTakesNoSubscriber(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan(self::shopOf(self::expiredProducer()));
        $client->loginUser(CustomerFactory::createOne(), 'main');

        $client->request('GET', '/'.$plan->getShop()->getSlug());
        self::assertSelectorNotExists('[data-recurring-plan]');

        $client->request('GET', $this->planPath($plan));
        self::assertSelectorExists('[data-plan-closed]');
        self::assertSelectorNotExists('form[name="recurring_order"]');
    }

    public function testAVisitorSignsInBeforeSubscribing(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $plan->setConditions('Panier oublié, panier perdu.')->setCapacity(10)->setMaxPauses(2)->setNoticeDays(7);
        self::entityManager()->flush();

        $client->request('GET', $this->planPath($plan));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Panier de la semaine');
        self::assertSelectorTextContains('[data-places-left]', '10 places restantes');
        self::assertSelectorTextContains('[data-plan-item]', 'Panier de légumes');
        self::assertSelectorTextContains('[data-basket-price]', '18,00');
        self::assertSelectorTextContains('[data-section="plan-rules"]', 'préavis de 7 jours');
        self::assertSelectorTextContains('[data-section="plan-rules"]', 'Jusqu\'à 2 absences');
        self::assertSelectorTextContains('[data-plan-conditions]', 'Panier oublié, panier perdu.');
        self::assertSelectorExists('[data-subscribe-login]');

        $client->clickLink('Je m\'abonne');
        self::assertResponseRedirects('/connexion');

        $client->request('POST', $this->planPath($plan));
        self::assertResponseRedirects($this->planPath($plan).'/connexion');
    }

    public function testACustomerSubscribesAndGetsTheOrderOfTheOpenSale(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $sale = self::openSale($plan);
        $customer = CustomerFactory::createOne(['firstName' => 'Claire']);
        $client->loginUser($customer, 'main');

        $client->request('GET', $this->planPath($plan));
        self::assertSelectorNotExists('input[name="recurring_order[acceptTerms]"]');
        self::assertSelectorNotExists('input[name="recurring_order[adultCertified]"]');
        $client->submitForm('Je m\'abonne', ['recurring_order[shares]' => '2', 'recurring_order[acceptConditions]' => '1']);

        $recurringOrder = $this->subscriptionOf($customer);
        self::assertResponseRedirects(\sprintf('/mon-compte/paniers/%d', $recurringOrder->getId()));
        self::assertSame(2, $recurringOrder->getShares());
        self::assertSame('Place du marché', $recurringOrder->getDeliveryPoint()->getName());
        self::assertSame(['recurring_started', 'recurring_order_generated'], self::sentTypes());
        self::assertStringContainsString('est enregistré', (string) self::emails()[0]->getSubject());

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash], [role="status"], main', 'Bienvenue parmi les abonnés');
        self::assertSelectorTextContains('[data-estimated-price]', '36,00');
        self::assertSelectorTextContains(\sprintf('[data-upcoming="%s"]', $sale->getDeliveryDate()->format('Y-m-d')), 'Commande créée');

        // Coming back to the plan page leads to the subscription.
        $client->request('GET', $this->planPath($plan));
        self::assertResponseRedirects(\sprintf('/mon-compte/paniers/%d', $recurringOrder->getId()));

        $client->request('GET', '/mon-compte');
        self::assertSelectorTextContains('[data-section="recurring-orders"]', 'Panier de la semaine');
    }

    public function testTheTermsAndTheAgeAreAskedWhenNeeded(): void
    {
        $client = self::createClient();
        $shop = self::shopOf(self::subscribedProducer());
        $wine = ProductFactory::new()->alcoholic()->create(['shop' => $shop, 'name' => 'Cornas']);
        $plan = self::basketPlan($shop, [[$wine, Quantity::units(1)]]);
        $customer = CustomerFactory::createOne(['termsVersion' => null, 'termsAcceptedAt' => null]);
        $client->loginUser($customer, 'main');

        $client->request('GET', $this->planPath($plan));
        $client->submitForm('Je m\'abonne', ['recurring_order[acceptConditions]' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('form', 'certifie avoir 18 ans ou plus');
        self::assertAnySelectorTextContains('form', 'Accepte les conditions d\'utilisation');
        self::assertNull(self::getContainer()->get(RecurringOrderRepository::class)->findOneBy(['customer' => $customer->getId()]));

        $client->submitForm('Je m\'abonne', [
            'recurring_order[acceptConditions]' => '1',
            'recurring_order[adultCertified]' => '1',
            'recurring_order[acceptTerms]' => '1',
        ]);
        $recurringOrder = $this->subscriptionOf($customer);
        self::assertNotNull($recurringOrder->getAdultCertifiedAt());
        self::assertTrue($recurringOrder->getCustomer()->hasAcceptedTerms(LegalDocuments::TERMS_VERSION));
    }

    public function testTheConditionsMustBeRead(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $client->loginUser(CustomerFactory::createOne(), 'main');

        $client->request('GET', $this->planPath($plan));
        $client->submitForm('Je m\'abonne', ['recurring_order[shares]' => '9']);

        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('form', 'Lis et accepte les conditions');
        self::assertAnySelectorTextContains('form', 'Choisis entre 1 et 3 paniers.');
    }

    public function testAFullPlanTakesNoMoreSubscriber(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $plan->setCapacity(1);
        self::entityManager()->flush();
        self::subscription($plan);
        $client->loginUser(CustomerFactory::createOne(), 'main');

        $client->request('GET', $this->planPath($plan));

        self::assertSelectorExists('[data-plan-full]');
        self::assertSelectorTextContains('[data-places-left]', 'Complet');
        self::assertSelectorNotExists('form[name="recurring_order"]');
    }

    public function testASeasonContractInChoiceModeSoldByWeight(): void
    {
        $client = self::createClient();
        $shop = self::shopOf(self::subscribedProducer());
        $cheese = ProductFactory::new()->byWeight(250)->create(['shop' => $shop, 'name' => 'Tomme', 'priceCents' => 2600]);
        $picodon = ProductFactory::createOne(['shop' => $shop, 'name' => 'Picodon', 'priceCents' => 280]);
        $plan = self::basketPlan($shop, [[$cheese, 1000], [$picodon, Quantity::units(6)]], RecurringMode::Choice);
        $dates = $plan->getSchedule()->nextDeliveryDates(self::now(), 4);
        $plan->setTerm(RecurringTerm::Season)->setSeasonStartsOn($dates[1])->setSeasonEndsOn($dates[3]);
        self::entityManager()->flush();
        $customer = CustomerFactory::createOne();
        $client->loginUser($customer, 'main');

        $client->request('GET', $this->planPath($plan));
        self::assertSelectorTextContains('[data-season]', '3 livraisons à venir');
        $client->submitForm('Je m\'abonne', [
            \sprintf('recurring_order[quantities][%d]', $cheese->getId()) => '0,3',
            \sprintf('recurring_order[quantities][%d]', $picodon->getId()) => '8',
            'recurring_order[acceptConditions]' => '1',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('form', 'Choisis un multiple de 0,25 kg');
        self::assertAnySelectorTextContains('form', '6 au plus.');

        $client->submitForm('Je m\'abonne', [
            \sprintf('recurring_order[quantities][%d]', $cheese->getId()) => '0,5',
            \sprintf('recurring_order[quantities][%d]', $picodon->getId()) => '0',
            'recurring_order[acceptConditions]' => '1',
        ]);
        $recurringOrder = $this->subscriptionOf($customer);
        self::assertSame([['Tomme', 500]], array_map(static fn (array $entry): array => [$entry[0]->getName(), $entry[1]], $recurringOrder->wantedQuantities()));
        self::assertSame($dates[1]->format('Y-m-d'), $recurringOrder->getStartsOn()->format('Y-m-d'));
        self::assertSame($dates[3]->format('Y-m-d'), $recurringOrder->getEndsOn()?->format('Y-m-d'));

        $client->followRedirect();
        self::assertSelectorTextContains('[data-contract-total]', '3 livraisons à venir');
        self::assertSelectorTextContains('[data-contract-total]', '39,00');
        // A season contract binds until its end.
        self::assertSelectorNotExists('form[action$="/resilier"]');
        $client->request('POST', \sprintf('/mon-compte/paniers/%d/resilier', $recurringOrder->getId()), ['_csrf_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-slot="alert"]', 'Ton contrat de saison t\'engage jusqu\'au');
        self::assertFalse(self::reload(RecurringOrder::class, $recurringOrder->getId())->isEnding());
    }

    public function testPauseAndResumeAComingDelivery(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $customer = CustomerFactory::createOne();
        $recurringOrder = self::subscription($plan, $customer);
        $client->loginUser($customer, 'main');
        $next = $plan->getSchedule()->nextDeliveryDates(self::now())[0];
        $day = $next->format('Y-m-d');

        $client->request('GET', $this->managePath($recurringOrder));
        self::assertSelectorTextContains(\sprintf('[data-upcoming="%s"]', $day), 'Commande créée à l\'ouverture de la vente');
        $client->submitForm('Mettre en pause', [], 'POST');
        self::assertResponseRedirects($this->managePath($recurringOrder).'#livraisons');
        self::assertSame([$day], self::reload(RecurringOrder::class, $recurringOrder->getId())->getPausedDates());

        $client->followRedirect();
        self::assertSelectorTextContains(\sprintf('[data-upcoming="%s"]', $day), 'En pause');
        $client->submitForm('Reprendre');
        self::assertSame([], self::reload(RecurringOrder::class, $recurringOrder->getId())->getPausedDates());
    }

    public function testPausingAGeneratedOrderCancelsIt(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $customer = CustomerFactory::createOne();
        $recurringOrder = self::subscription($plan, $customer);
        $sale = self::openSale($plan);
        self::generator()->generateForSale($sale);
        $client->loginUser($customer, 'main');

        $client->request('POST', $this->managePath($recurringOrder).'/pause', ['_csrf_token' => 'csrf-token', 'date' => $sale->getDeliveryDate()->format('Y-m-d')], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $occurrence = self::onlyOccurrence($sale);
        self::assertSame('paused', $occurrence->getOutcome()->value);
        self::assertSame(OrderStatus::Cancelled, $occurrence->getOrder()?->getStatus());
        self::assertSame(0, self::first($occurrence->getOrder()->getLines())->getSaleOffer()->getReservedQty());
    }

    public function testTheAbsencesOfThePlanAreLimited(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $plan->setMaxPauses(1);
        $customer = CustomerFactory::createOne();
        $recurringOrder = self::subscription($plan, $customer);
        [$first, $second] = $plan->getSchedule()->nextDeliveryDates(self::now(), 2);
        $recurringOrder->pause($first, self::now());
        self::entityManager()->flush();
        $client->loginUser($customer, 'main');

        $client->request('GET', $this->managePath($recurringOrder));
        self::assertSelectorTextContains('[data-section="upcoming-deliveries"]', 'Absences utilisées : 1 sur 1');
        self::assertSelectorNotExists('form[action$="/pause"]');

        $client->request('POST', $this->managePath($recurringOrder).'/pause', ['_csrf_token' => 'csrf-token', 'date' => $second->format('Y-m-d')], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-slot="alert"]', 'toutes les absences');

        $client->request('POST', $this->managePath($recurringOrder).'/pause', ['_csrf_token' => 'csrf-token', 'date' => 'demain'], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-slot="alert"]', 'ne fait pas partie de tes prochaines livraisons');
    }

    public function testChangeTheNextDeliveries(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $customer = CustomerFactory::createOne();
        $recurringOrder = self::subscription($plan, $customer);
        $client->loginUser($customer, 'main');

        $client->request('GET', $this->managePath($recurringOrder));
        $client->submitForm('Enregistrer', ['recurring_order[shares]' => '3']);
        self::assertResponseRedirects($this->managePath($recurringOrder));
        self::assertSame(3, self::reload(RecurringOrder::class, $recurringOrder->getId())->getShares());

        $client->followRedirect();
        $client->submitForm('Enregistrer', ['recurring_order[shares]' => '0']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(3, self::reload(RecurringOrder::class, $recurringOrder->getId())->getShares());
    }

    public function testCancelWithANotice(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $plan->setNoticeDays(7);
        $customer = CustomerFactory::createOne();
        $recurringOrder = self::subscription($plan, $customer);
        $client->loginUser($customer, 'main');

        $client->request('GET', $this->managePath($recurringOrder));
        self::assertSelectorTextContains('[data-section="recurring-cancel"]', 'Préavis de 7 jours');
        $client->submitForm('Résilier mon abonnement');

        $ending = self::reload(RecurringOrder::class, $recurringOrder->getId());
        self::assertTrue($ending->isEnding());
        self::assertSame(self::now()->modify('+7 days')->format('Y-m-d'), $ending->getEndsOn()?->format('Y-m-d'));
        self::assertSame([], self::sentTypes(), 'The page confirms it: no e-mail.');
        $client->followRedirect();
        self::assertSelectorExists('[data-recurring-ending]');
        self::assertSelectorNotExists('[data-section="recurring-cancel"]');
    }

    public function testCancelAtOnce(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $customer = CustomerFactory::createOne();
        $recurringOrder = self::subscription($plan, $customer);
        $client->loginUser($customer, 'main');

        $client->request('GET', $this->managePath($recurringOrder));
        $client->submitForm('Résilier mon abonnement');

        $ended = self::reload(RecurringOrder::class, $recurringOrder->getId());
        self::assertSame(RecurringOrderStatus::Ended, $ended->getStatus());
        self::assertSame(RecurringEndReason::Customer, $ended->getEndReason());
        $client->followRedirect();
        self::assertSelectorExists('[data-recurring-ended]');
        self::assertSelectorNotExists('[data-section="upcoming-deliveries"]');
    }

    public function testASubscriptionIsOnlyVisibleToItsSubscriber(): void
    {
        $client = self::createClient();
        $plan = self::basketPlan();
        $recurringOrder = self::subscription($plan);
        $client->loginUser(CustomerFactory::createOne(), 'main');

        foreach (['GET' => '', 'POST' => '/pause'] as $method => $suffix) {
            $client->request($method, $this->managePath($recurringOrder).$suffix, ['_csrf_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost']);
            self::assertResponseStatusCodeSame(404);
        }
        $client->request('POST', $this->managePath($recurringOrder).'/resilier', ['_csrf_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse(self::reload(RecurringOrder::class, $recurringOrder->getId())->isEnded());

        $client->request('GET', \sprintf('/autre-boutique/panier/%d', $plan->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheManagePageNeedsToSignIn(): void
    {
        $client = self::createClient();
        $recurringOrder = self::subscription(self::basketPlan());

        $client->request('GET', $this->managePath($recurringOrder));

        self::assertResponseRedirects('/connexion');
    }

    private function planPath(RecurringPlan $plan): string
    {
        return \sprintf('/%s/panier/%d', $plan->getShop()->getSlug(), $plan->getId());
    }

    private function managePath(RecurringOrder $recurringOrder): string
    {
        return \sprintf('/mon-compte/paniers/%d', $recurringOrder->getId());
    }

    private function subscriptionOf(Customer $customer): RecurringOrder
    {
        self::entityManager()->clear();
        $recurringOrder = self::getContainer()->get(RecurringOrderRepository::class)->findOneBy(['customer' => $customer->getId()]);
        self::assertInstanceOf(RecurringOrder::class, $recurringOrder);

        return $recurringOrder;
    }
}
