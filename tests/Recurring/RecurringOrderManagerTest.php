<?php

declare(strict_types=1);

namespace App\Tests\Recurring;

use App\Customer\Entity\Customer;
use App\Customer\Service\CustomerEraser;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Enum\RecurringEndReason;
use App\Recurring\Enum\RecurringMode;
use App\Recurring\Enum\RecurringOrderStatus;
use App\Recurring\Enum\RecurringTerm;
use App\Recurring\Exception\RecurringOrderNotAllowedException;
use App\Recurring\Model\RecurringOrderRequest;
use App\Recurring\Scheduler\RecurringOrderTask;
use App\Recurring\Service\RecurringCalendar;
use App\Shared\Service\Quantity;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ProductFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class RecurringOrderManagerTest extends KernelTestCase
{
    use Factories;
    use RecurringScenario;

    public function testSubscribeStartsTodayAndIsConfirmed(): void
    {
        $plan = self::basketPlan();
        $point = self::first($plan->getSchedule()->getDeliveryPoints());

        $recurringOrder = self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point, 2), false);

        self::assertNotNull($recurringOrder->getId());
        self::assertSame(self::now()->format('Y-m-d'), $recurringOrder->getStartsOn()->format('Y-m-d'));
        self::assertNull($recurringOrder->getEndsOn());
        self::assertSame(2, $recurringOrder->getShares());
        self::assertNull($recurringOrder->getAdultCertifiedAt());
        self::assertSame(['recurring_started'], self::sentTypes());
        self::assertStringContainsString('2 × Panier de la semaine', (string) self::emails()[0]->getTextBody());
    }

    public function testTheRulesOfASubscription(): void
    {
        $plan = self::basketPlan();
        $point = self::first($plan->getSchedule()->getDeliveryPoints());
        $customer = CustomerFactory::createOne();
        self::manager()->subscribe($plan, $customer, new RecurringOrderRequest($point), false);

        $this->assertRefused('Tu es déjà abonné à ce panier.', static fn (): RecurringOrder => self::manager()->subscribe($plan, $customer, new RecurringOrderRequest($point), false));
        $this->assertRefused('Choisis entre 1 et 3 paniers.', static fn (): RecurringOrder => self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point, 4), false));
        $this->assertRefused('Choisis entre 1 et 3 paniers.', static fn (): RecurringOrder => self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point, 0), false));
        $foreign = DeliveryPointFactory::createOne(['shop' => $plan->getShop()]);
        $this->assertRefused('Ce point de retrait n\'est pas proposé pour ce panier.', static fn (): RecurringOrder => self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($foreign), false));
        $offline = CustomerFactory::new()->withoutEmail($plan->getShop())->create();
        $this->assertRefused('Un abonnement se prend depuis ton propre compte', static fn (): RecurringOrder => self::manager()->subscribe($plan, $offline, new RecurringOrderRequest($point), false));

        $plan->setCapacity(1);
        $this->assertRefused('Ce panier est complet', static fn (): RecurringOrder => self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point), false));
        $plan->setCapacity(2)->setEnabled(false);
        self::entityManager()->flush();
        $this->assertRefused('Les inscriptions à ce panier sont fermées.', static fn (): RecurringOrder => self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point), false));
    }

    public function testAHomeDeliveryNeedsAnAddress(): void
    {
        $plan = self::basketPlan();
        $home = DeliveryPointFactory::new()->home()->create(['shop' => $plan->getShop()]);
        $plan->getSchedule()->addDeliveryPoint($home);
        self::entityManager()->flush();

        $this->assertRefused('Indique ton adresse', static fn (): RecurringOrder => self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($home, 1, [], '  '), false));
        $recurringOrder = self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($home, 1, [], ' 3 rue des Alpes '), false);
        self::assertSame('3 rue des Alpes', $recurringOrder->getDeliveryAddress());

        $point = self::first($plan->getSchedule()->getDeliveryPoints());
        self::manager()->update($recurringOrder, new RecurringOrderRequest($point, 1, [], 'gardée ?'));
        self::assertNull($recurringOrder->getDeliveryAddress(), 'Only a home delivery keeps an address.');
    }

    public function testAnAlcoholicPlanNeedsTheAgeCertification(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $plan = self::basketPlan($shop, [[ProductFactory::new()->alcoholic()->create(['shop' => $shop]), Quantity::units(1)]]);
        $point = self::first($plan->getSchedule()->getDeliveryPoints());

        $this->assertRefused('interdite aux mineurs', static fn (): RecurringOrder => self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point), false));
        $recurringOrder = self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point), true);
        self::assertNotNull($recurringOrder->getAdultCertifiedAt());
    }

    public function testChoiceQuantitiesStayWithinWhatIsProposed(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $cheese = ProductFactory::new()->byWeight(250)->create(['shop' => $shop, 'name' => 'Tomme']);
        $plan = self::basketPlan($shop, [[$cheese, 1000]], RecurringMode::Choice);
        $point = self::first($plan->getSchedule()->getDeliveryPoints());
        $id = (int) $cheese->getId();

        $this->assertRefused('Choisis au moins un produit.', static fn (): RecurringOrder => self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point, 1, [$id => 0]), false));
        $this->assertRefused('Quantité de « Tomme » non proposée.', static fn (): RecurringOrder => self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point, 1, [$id => 1250]), false));
        $this->assertRefused('Quantité de « Tomme » non proposée.', static fn (): RecurringOrder => self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point, 1, [$id => 300]), false));
        $recurringOrder = self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point, 1, [$id => 1000]), false);
        self::assertSame(1000, $recurringOrder->getQuantityOf($cheese));

        self::manager()->update($recurringOrder, new RecurringOrderRequest($point, 1, [$id => 250]));
        self::assertSame(250, $recurringOrder->getQuantityOf($cheese));
    }

    public function testASeasonContractStartsWithTheSeasonAndEndsWithAnEmail(): void
    {
        $plan = self::basketPlan();
        $dates = $plan->getSchedule()->nextDeliveryDates(self::now(), 3);
        $plan->setTerm(RecurringTerm::Season)->setSeasonStartsOn($dates[1])->setSeasonEndsOn($dates[2]);
        self::entityManager()->flush();
        $point = self::first($plan->getSchedule()->getDeliveryPoints());

        $recurringOrder = self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point), false);

        self::assertSame($dates[1]->format('Y-m-d'), $recurringOrder->getStartsOn()->format('Y-m-d'));
        self::assertSame($dates[2]->format('Y-m-d'), $recurringOrder->getEndsOn()?->format('Y-m-d'));
        self::assertSame(2, $recurringOrder->remainingDeliveryCount(self::now()));
        self::assertSame(3600, $recurringOrder->remainingTotalCents(self::now()));
        $this->assertRefused('Ton contrat de saison t\'engage jusqu\'au', static fn (): ?\DateTimeImmutable => self::manager()->cancel($recurringOrder));

        // The day after its last delivery, the contract ends and the subscriber is told.
        $recurringOrder = self::subscription($plan);
        $recurringOrder->cancelOn(self::now()->modify('-2 days'), self::now());
        $season = self::subscription($plan);
        $season->end(RecurringEndReason::Producer, self::now());
        $due = new RecurringOrder($plan, CustomerFactory::createOne(), $point, self::now()->modify('-30 days'), self::now()->modify('-30 days'));
        self::entityManager()->persist($due);
        self::entityManager()->flush();
        $reflection = new \ReflectionProperty(RecurringOrder::class, 'endsOn');
        $reflection->setValue($due, self::now()->modify('-1 day')->setTime(0, 0));
        self::entityManager()->flush();

        self::assertSame(['ended' => 2, 'generated' => 0], self::getContainer()->get(RecurringOrderTask::class)());

        $due = self::reload(RecurringOrder::class, $due->getId());
        self::assertSame(RecurringOrderStatus::Ended, $due->getStatus());
        self::assertSame(RecurringEndReason::ContractEnd, $due->getEndReason());
        self::assertSame(RecurringEndReason::Customer, self::reload(RecurringOrder::class, $recurringOrder->getId())->getEndReason());
        // Started (subscribe) + end of contract; nothing for the cancellation by the subscriber.
        self::assertSame(['recurring_started', 'recurring_ended'], self::sentTypes());
        self::assertStringContainsString('est terminé', (string) self::emails()[1]->getSubject());
        self::assertSame(['ended' => 0, 'generated' => 0], self::getContainer()->get(RecurringOrderTask::class)());
    }

    public function testCancelTwiceIsRefused(): void
    {
        $plan = self::basketPlan();
        $plan->setNoticeDays(3);
        $recurringOrder = self::subscription($plan);

        $lastDay = self::manager()->cancel($recurringOrder);

        self::assertSame(self::now()->modify('+3 days')->format('Y-m-d'), $lastDay?->format('Y-m-d'));
        $this->assertRefused('déjà résilié', static fn (): ?\DateTimeImmutable => self::manager()->cancel($recurringOrder));
    }

    public function testTheProducerActionsCheckTheStatus(): void
    {
        $recurringOrder = self::subscription(self::basketPlan());

        $this->assertRefused('pas suspendu', static fn () => self::manager()->resumeSubscription($recurringOrder));
        self::manager()->suspend($recurringOrder);
        $this->assertRefused('Seul un abonnement actif', static fn () => self::manager()->suspend($recurringOrder));
        self::manager()->endByProducer($recurringOrder);
        $this->assertRefused('déjà terminé', static fn () => self::manager()->endByProducer($recurringOrder));
        $this->assertRefused('Cet abonnement est terminé.', static fn () => self::manager()->update($recurringOrder, new RecurringOrderRequest($recurringOrder->getDeliveryPoint())));
        self::assertSame(['recurring_suspended', 'recurring_ended'], self::sentTypes());
    }

    public function testResumingASuspendedSubscriptionJoinsTheOpenSale(): void
    {
        $plan = self::basketPlan();
        $recurringOrder = self::subscription($plan);
        $recurringOrder->suspend(self::now());
        self::entityManager()->flush();
        $sale = self::openSale($plan);
        self::assertSame([], self::generator()->generateForSale($sale));

        self::manager()->resumeSubscription($recurringOrder);

        self::assertCount(1, self::occurrencesOf($sale));
    }

    public function testTheCalendarListsTheComingDeliveriesAndTheirRules(): void
    {
        $plan = self::basketPlan();
        $plan->setMaxPauses(1);
        $recurringOrder = self::subscription($plan);
        $sale = self::openSale($plan);
        $calendar = self::getContainer()->get(RecurringCalendar::class);

        $upcoming = $calendar->upcoming($recurringOrder, self::now());
        self::assertCount(RecurringCalendar::WEEKS, $upcoming);
        self::assertSame($sale->getId(), $upcoming[0]->sale?->getId());
        self::assertEquals($sale->getEffectiveEditCutoffAt(), $upcoming[0]->cutoffAt);
        self::assertNull($upcoming[1]->sale);
        self::assertEquals($plan->getSchedule()->deadlineAtFor($upcoming[1]->date), $upcoming[1]->cutoffAt);
        self::assertTrue($upcoming[0]->canPause);
        self::assertFalse($upcoming[0]->canResume);

        self::manager()->pause($recurringOrder, $upcoming[1]->date);
        $upcoming = $calendar->upcoming($recurringOrder, self::now());
        self::assertTrue($upcoming[1]->paused);
        self::assertTrue($upcoming[1]->canResume);
        self::assertFalse($upcoming[0]->canPause, 'The only absence is used.');
        $this->assertRefused('toutes les absences', static fn () => self::manager()->pause($recurringOrder, $upcoming[0]->date));
        $this->assertRefused('ne fait pas partie', static fn () => self::manager()->pause($recurringOrder, $upcoming[1]->date));
        $this->assertRefused('ne peut plus être reprise', static fn () => self::manager()->resume($recurringOrder, $upcoming[0]->date));

        // A generated order can no longer be resumed once paused, and a cancelled sale cannot be paused.
        self::manager()->resume($recurringOrder, $upcoming[1]->date);
        self::generator()->generateForSale($sale);
        $recurringOrder = self::reload(RecurringOrder::class, $recurringOrder->getId());
        $upcoming = $calendar->upcoming($recurringOrder, self::now());
        self::assertNotNull($upcoming[0]->order);
        self::assertTrue($upcoming[0]->canPause);
        self::assertFalse($upcoming[0]->isCancelled());

        self::assertSame([], $calendar->upcoming($recurringOrder->end(RecurringEndReason::Producer, self::now()), self::now()));
        self::assertNull($calendar->find($recurringOrder, self::now(), self::now()));
    }

    public function testTooLateToPause(): void
    {
        $plan = self::basketPlan();
        $recurringOrder = self::subscription($plan);
        $sale = self::openSale($plan, attributes: ['editCutoffAt' => self::now()->modify('-1 hour')]);

        $delivery = self::getContainer()->get(RecurringCalendar::class)->find($recurringOrder, $sale->getDeliveryDate(), self::now());

        self::assertNotNull($delivery);
        self::assertFalse($delivery->canPause);
        $this->assertRefused('trop tard', static fn () => self::manager()->pause($recurringOrder, $sale->getDeliveryDate()));
    }

    public function testErasingTheAccountDeletesItsSubscriptions(): void
    {
        $customer = CustomerFactory::createOne();
        $recurringOrder = self::subscription(self::basketPlan(), $customer);
        $id = $recurringOrder->getId();
        $customerId = $customer->getId();

        self::getContainer()->get(CustomerEraser::class)->erase($customer);

        self::entityManager()->clear();
        self::assertNull(self::entityManager()->find(RecurringOrder::class, $id));
        self::assertNull(self::entityManager()->find(Customer::class, $customerId));
    }

    /**
     * @param \Closure(): mixed $action
     */
    private function assertRefused(string $message, \Closure $action): void
    {
        try {
            $action();
        } catch (RecurringOrderNotAllowedException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());

            return;
        }

        self::fail(\sprintf('Expected a refusal: %s', $message));
    }
}
