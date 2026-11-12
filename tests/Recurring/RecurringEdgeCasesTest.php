<?php

declare(strict_types=1);

namespace App\Tests\Recurring;

use App\Customer\Service\CustomerEraser;
use App\Ordering\Enum\OrderSource;
use App\Ordering\Enum\OrderStatus;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Service\OrderManager;
use App\Recurring\Entity\RecurringOccurrence;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Enum\OccurrenceOutcome;
use App\Recurring\Enum\RecurringEndReason;
use App\Recurring\Enum\RecurringMode;
use App\Recurring\Enum\RecurringTerm;
use App\Recurring\Event\RecurringOrderEnded;
use App\Recurring\Event\RecurringOrderGenerated;
use App\Recurring\Event\RecurringOrderStarted;
use App\Recurring\Event\RecurringOrderSuspended;
use App\Recurring\Exception\RecurringOrderNotAllowedException;
use App\Recurring\Model\RecurringOrderRequest;
use App\Recurring\Repository\RecurringOrderRepository;
use App\Recurring\Repository\RecurringPlanRepository;
use App\Recurring\Service\RecurringCalendar;
use App\Recurring\Service\RecurringLinks;
use App\Recurring\Service\RecurringPlanCatalog;
use App\Sale\Entity\SaleOffer;
use App\Sale\Enum\SaleStatus;
use App\Shared\Enum\Actor;
use App\Shared\Service\FrenchFormatter;
use App\Shared\Service\Quantity;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The details around the generation, the e-mails and the queries.
 */
final class RecurringEdgeCasesTest extends KernelTestCase
{
    use Factories;
    use RecurringScenario;

    public function testANewSubscriptionJoinsEveryOpenSale(): void
    {
        $plan = self::basketPlan();
        $first = self::openSale($plan);
        $second = self::openSale($plan);
        $recurringOrder = self::subscription($plan);

        $created = self::generator()->generateForRecurringOrder($recurringOrder);

        self::assertSame([$first->getId(), $second->getId()], array_map(static fn (RecurringOccurrence $occurrence): ?int => $occurrence->getSale()->getId(), $created));
        self::assertSame(2, self::generator()->generateAll() + 2, 'Nothing left to catch up.');
    }

    public function testTheCatchUpCountsEverySale(): void
    {
        $plan = self::basketPlan();
        self::subscription($plan);
        self::openSale($plan);
        self::openSale($plan);

        self::assertSame(2, self::generator()->generateAll());
    }

    public function testAnUnsavedSubscriptionCannotBeGenerated(): void
    {
        $plan = self::basketPlan();
        $recurringOrder = new RecurringOrder($plan, CustomerFactory::createOne(), self::first($plan->getSchedule()->getDeliveryPoints()), self::now(), self::now());

        $this->expectException(\LogicException::class);
        self::generator()->generateForRecurringOrder($recurringOrder);
    }

    public function testASuspendedSubscriptionJoinsNoOpenSale(): void
    {
        $plan = self::basketPlan();
        $sale = self::openSale($plan);
        $recurringOrder = self::subscription($plan);
        $recurringOrder->suspend(self::now());
        self::entityManager()->flush();

        self::assertSame([], self::generator()->generateForRecurringOrder($recurringOrder));
        self::assertSame([], self::occurrencesOf($sale));
    }

    public function testTheProducerSummaryOnlyListsTheShortSubscribers(): void
    {
        $plan = self::basketPlan();
        $product = self::first($plan->getItems())->getProduct();
        self::subscription($plan, CustomerFactory::createOne(['firstName' => 'Servie', 'lastName' => 'Complète']));
        self::subscription($plan, CustomerFactory::createOne(['firstName' => 'Arrivée', 'lastName' => 'Tard']));
        $sale = self::openSale($plan, [(int) $product->getId() => Quantity::units(1)]);

        self::generator()->generateForSale($sale);

        $summary = self::emails()[2];
        self::assertSame(['recurring_order_generated', 'recurring_order_generated', 'recurring_shortages'], self::sentTypes());
        self::assertStringContainsString('Arrivée Tard', (string) $summary->getTextBody());
        self::assertStringNotContainsString('Servie', (string) $summary->getTextBody());
    }

    public function testTheGenerationReadsTheLatestQuantities(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $cheese = ProductFactory::createOne(['shop' => $shop, 'name' => 'Picodon', 'priceCents' => 300]);
        $plan = self::basketPlan($shop, [[$cheese, Quantity::units(6)]], RecurringMode::Choice);
        $recurringOrder = self::subscription($plan, quantities: [(int) $cheese->getId() => Quantity::units(2)]);
        $sale = self::openSale($plan);
        // Changed meanwhile by another request.
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE recurring_order_line SET quantity = 4000 WHERE recurring_order_id = ?', [$recurringOrder->getId()]);

        self::generator()->generateForSale($sale);

        self::assertSame(1200, self::onlyOccurrence($sale)->getOrder()?->getTotalCents());
    }

    public function testTheMissingProductsOfAPointNotServed(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $plan = self::basketPlan($shop, [[ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain']), Quantity::units(1)], [ProductFactory::createOne(['shop' => $shop, 'name' => 'Brioche']), Quantity::units(2)]]);
        $sale = self::openSale($plan);
        $elsewhere = DeliveryPointFactory::createOne(['shop' => $shop]);
        $plan->getSchedule()->addDeliveryPoint($elsewhere);
        self::entityManager()->flush();
        self::subscription($plan, point: $elsewhere);

        self::generator()->generateForSale($sale);

        self::assertSame(['Pain', 'Brioche'], array_column(self::onlyOccurrence($sale)->getMissing(), 'label'));
    }

    public function testTheEmailsAreSentOnceEach(): void
    {
        $plan = self::basketPlan();
        $recurringOrder = self::subscription($plan);
        $sale = self::openSale($plan);
        self::generator()->generateForSale($sale);
        $occurrence = self::onlyOccurrence($sale);
        $recurringOrder = self::entityManager()->find(RecurringOrder::class, $recurringOrder->getId());
        self::assertInstanceOf(RecurringOrder::class, $recurringOrder);
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        $dispatcher->dispatch(new RecurringOrderGenerated($occurrence));
        $dispatcher->dispatch(new RecurringOrderStarted($recurringOrder));
        $dispatcher->dispatch(new RecurringOrderStarted($recurringOrder));
        $dispatcher->dispatch(new RecurringOrderSuspended($recurringOrder));
        $dispatcher->dispatch(new RecurringOrderSuspended($recurringOrder));
        $recurringOrder->end(RecurringEndReason::ContractEnd, self::now());
        $dispatcher->dispatch(new RecurringOrderEnded($recurringOrder));
        $dispatcher->dispatch(new RecurringOrderEnded($recurringOrder));

        self::assertSame(['recurring_order_generated', 'recurring_started', 'recurring_suspended', 'recurring_suspended', 'recurring_ended'], self::sentTypes());
    }

    public function testAnImmediateCancellationEndsTheSubscriptionWithoutEmail(): void
    {
        $recurringOrder = self::subscription(self::basketPlan());
        $ended = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(RecurringOrderEnded::class, static function (RecurringOrderEnded $event) use (&$ended): void {
            $ended[] = $event->recurringOrder->getEndReason();
        });

        self::assertNull(self::manager()->cancel($recurringOrder));

        self::assertSame([RecurringEndReason::Customer], $ended);
        self::assertSame([], self::sentTypes());
    }

    public function testTheEmailsOfAGeneratedOrder(): void
    {
        $plan = self::basketPlan();
        $plan->getShop()->setAllowCustomerEdit(false);
        $recurringOrder = self::subscription($plan);
        $sale = self::openSale($plan);
        $producer = self::first($plan->getShop()->getUsers());

        self::generator()->generateForSale($sale);

        $email = self::emails()[0];
        self::assertSame($producer->getEmail(), $email->getReplyTo()[0]->getAddress());
        self::assertStringContainsString('via Panier Sauvage', (string) $email->getFrom()[0]->getName());
        self::assertStringContainsString('Pour changer ta commande, contacte directement', (string) $email->getTextBody());
        $link = self::getContainer()->get(RecurringLinks::class)->subscription($recurringOrder);
        self::assertMatchesRegularExpression('#^http://[^/]+/connexion/verification\?user=[^&]+&expires=\d+&hash=[^&]+&_target_path=%2Fmon-compte%2Fpaniers%2F'.$recurringOrder->getId().'$#', $link);
    }

    public function testTheConfirmationListsTheNextThreeDeliveries(): void
    {
        $plan = self::basketPlan();
        $point = self::first($plan->getSchedule()->getDeliveryPoints());

        self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point), true);

        $dates = $plan->getSchedule()->nextDeliveryDates(self::now()->modify('-1 day'), 4);
        $text = (string) self::emails()[0]->getTextBody();
        foreach (array_slice($dates, 0, 3) as $date) {
            self::assertStringContainsString('- '.$this->formatter()->dateLong($date), $text);
        }
        self::assertStringNotContainsString('- '.$this->formatter()->dateLong($dates[3]), $text);
    }

    public function testAnAdultCertificationIsOnlyKeptForAlcohol(): void
    {
        $plan = self::basketPlan();
        $point = self::first($plan->getSchedule()->getDeliveryPoints());

        $recurringOrder = self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point), true);

        self::assertNull($recurringOrder->getAdultCertifiedAt());
    }

    public function testTheCapacityIsReadUnderTheLock(): void
    {
        $plan = self::basketPlan();
        $point = self::first($plan->getSchedule()->getDeliveryPoints());
        self::subscription($plan);
        // Lowered meanwhile by the producer.
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE recurring_plan SET capacity = 1 WHERE id = ?', [$plan->getId()]);

        try {
            self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point), false);
            self::fail('The plan is full.');
        } catch (RecurringOrderNotAllowedException $exception) {
            self::assertStringContainsString('complet', $exception->getMessage());
        }
        self::assertSame(0, self::getContainer()->get(RecurringPlanCatalog::class)->placesLeft($plan));
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE recurring_plan SET capacity = 0 WHERE id = ?', [$plan->getId()]);
        self::entityManager()->refresh($plan);
        self::assertSame(0, self::getContainer()->get(RecurringPlanCatalog::class)->placesLeft($plan), 'Never below zero.');
    }

    public function testUpdatingASubscription(): void
    {
        $plan = self::basketPlan();
        $other = DeliveryPointFactory::createOne(['shop' => $plan->getShop(), 'name' => 'Marché couvert']);
        $plan->getSchedule()->addDeliveryPoint($other);
        $recurringOrder = self::subscription($plan);
        $before = $recurringOrder->getUpdatedAt();
        $product = self::first($plan->getItems())->getProduct();

        self::manager()->update($recurringOrder, new RecurringOrderRequest($other, 3, [(int) $product->getId() => Quantity::units(2)]));

        self::assertSame($other, $recurringOrder->getDeliveryPoint());
        self::assertSame(3, $recurringOrder->getShares());
        self::assertCount(0, $recurringOrder->getLines(), 'A basket has no lines.');
        self::assertGreaterThan($before, $recurringOrder->getUpdatedAt());
        try {
            self::manager()->update($recurringOrder, new RecurringOrderRequest($other, 4));
            self::fail('4 baskets is more than the plan allows.');
        } catch (RecurringOrderNotAllowedException) {
            self::assertSame(3, $recurringOrder->getShares());
        }
        self::manager()->update($recurringOrder, new RecurringOrderRequest($other, 3));
        self::assertSame(3, $recurringOrder->getShares(), 'As many baskets as the plan allows.');
    }

    public function testAChoiceWithAProductLeftOut(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $bread = ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain']);
        $cake = ProductFactory::createOne(['shop' => $shop, 'name' => 'Brioche']);
        $plan = self::basketPlan($shop, [[$bread, Quantity::units(2)], [$cake, Quantity::units(2)]], RecurringMode::Choice);
        $point = self::first($plan->getSchedule()->getDeliveryPoints());

        $recurringOrder = self::manager()->subscribe($plan, CustomerFactory::createOne(), new RecurringOrderRequest($point, 1, [(int) $cake->getId() => Quantity::units(2)]), false);

        self::assertSame([['Brioche', 2000]], array_map(static fn (array $entry): array => [$entry[0]->getName(), $entry[1]], $recurringOrder->wantedQuantities()));
        self::manager()->update($recurringOrder, new RecurringOrderRequest($point, 1, [(int) $bread->getId() => Quantity::units(1), (int) $cake->getId() => Quantity::units(1)]));
        self::assertSame([['Pain', 1000], ['Brioche', 1000]], array_map(static fn (array $entry): array => [$entry[0]->getName(), $entry[1]], $recurringOrder->wantedQuantities()));
    }

    public function testABasketIgnoresTheLinesOfAFormerChoice(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $bread = ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain']);
        $plan = self::basketPlan($shop, [[$bread, Quantity::units(1)]], RecurringMode::Choice);
        $recurringOrder = self::subscription($plan, quantities: [(int) $bread->getId() => Quantity::units(1)])->setShares(3);

        $plan->setMode(RecurringMode::Basket);

        self::assertSame([[$bread, 3000]], $recurringOrder->wantedQuantities());
    }

    public function testResumingAPausedDeliveryOfAnOpenSaleGeneratesItsOrder(): void
    {
        $plan = self::basketPlan();
        $recurringOrder = self::subscription($plan);
        $sale = self::openSale($plan);
        $recurringOrder->pause($sale->getDeliveryDate(), self::now());
        self::entityManager()->flush();

        self::manager()->resume($recurringOrder, $sale->getDeliveryDate());

        self::assertSame(OccurrenceOutcome::Placed, self::onlyOccurrence($sale)->getOutcome());
    }

    public function testTheCalendarRules(): void
    {
        $plan = self::basketPlan();
        $schedule = $plan->getSchedule()->setEditCutoffHours(2);
        $recurringOrder = self::subscription($plan);
        $calendar = self::getContainer()->get(RecurringCalendar::class);

        $first = $calendar->upcoming($recurringOrder, self::now())[0];
        self::assertEquals($schedule->editCutoffAtFor($first->date), $first->cutoffAt);
        self::assertTrue($first->canPause);

        // No order generated for lack of stock: nothing to pause.
        $product = self::first($plan->getItems())->getProduct();
        $sale = self::openSale($plan, [(int) $product->getId() => 0]);
        self::generator()->generateForSale($sale);
        $recurringOrder = self::reload(RecurringOrder::class, $recurringOrder->getId());
        $delivery = $calendar->find($recurringOrder, $sale->getDeliveryDate(), self::now());
        self::assertSame(OccurrenceOutcome::OutOfStock, $delivery?->occurrence?->getOutcome());
        self::assertFalse($delivery->canPause);
        self::assertFalse($delivery->canResume);
    }

    public function testAGeneratedOrderCancelledByTheProducerCannotBePaused(): void
    {
        $plan = self::basketPlan();
        $recurringOrder = self::subscription($plan);
        $sale = self::openSale($plan);
        self::generator()->generateForSale($sale);
        $order = self::onlyOccurrence($sale)->getOrder();
        self::assertNotNull($order);
        self::getContainer()->get(OrderManager::class)->cancelOrder($order, Actor::Producer);
        $recurringOrder = self::reload(RecurringOrder::class, $recurringOrder->getId());

        $delivery = self::getContainer()->get(RecurringCalendar::class)->find($recurringOrder, $sale->getDeliveryDate(), self::now());

        self::assertNull($delivery?->order);
        self::assertFalse($delivery?->canPause);
    }

    public function testAnEndedSubscriptionHasNoComingDeliveryEvenToday(): void
    {
        $plan = self::basketPlan();
        $plan->getSchedule()->setWeekday((int) self::now()->format('N'))->setDeadlineDaysBefore(0)->setDeadlineTime(new \DateTimeImmutable('1970-01-01 23:59'));
        $recurringOrder = self::subscription($plan);
        $calendar = self::getContainer()->get(RecurringCalendar::class);
        self::assertSame(self::now()->format('Y-m-d'), $calendar->upcoming($recurringOrder, self::now()->setTime(0, 0))[0]->date->format('Y-m-d'));

        $recurringOrder->end(RecurringEndReason::Producer, self::now());

        self::assertSame([], $calendar->upcoming($recurringOrder, self::now()->setTime(0, 0)));
    }

    public function testErasingAnAccountWithOrdersDeletesOnlyItsSubscriptions(): void
    {
        $plan = self::basketPlan();
        $customer = CustomerFactory::createOne();
        $mine = self::subscription($plan, $customer);
        $other = self::subscription($plan);
        $sale = self::openSale($plan, attributes: ['status' => SaleStatus::Done, 'deliveryDate' => self::now()->modify('-7 days')]);
        $offer = self::first($sale->getOffers());
        self::assertInstanceOf(SaleOffer::class, $offer);
        OrderLineFactory::createOne(['order' => OrderFactory::createOne(['sale' => $sale, 'customer' => $customer, 'status' => OrderStatus::Delivered]), 'saleOffer' => $offer]);
        [$mineId, $otherId] = [$mine->getId(), $other->getId()];

        self::assertTrue(self::getContainer()->get(CustomerEraser::class)->erase($customer), 'Anonymised: the customer has orders.');

        self::entityManager()->clear();
        self::assertNull(self::entityManager()->find(RecurringOrder::class, $mineId));
        self::assertNotNull(self::entityManager()->find(RecurringOrder::class, $otherId));
    }

    public function testTheQueriesOfTheRepositories(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $first = self::basketPlan($shop);
        $second = self::basketPlan($shop);
        $customer = CustomerFactory::createOne();
        self::subscription($first, $customer);
        self::subscription($second, $customer);
        self::subscription($second);
        $first->setEnabled(false);
        self::entityManager()->flush();
        $plans = self::getContainer()->get(RecurringPlanRepository::class);
        $recurringOrders = self::getContainer()->get(RecurringOrderRepository::class);

        self::assertSame($second, $plans->findOneInShop((int) $second->getId(), $shop));
        self::assertNull($plans->findOneInShop((int) $second->getId(), self::shopOf(self::subscribedProducer())));
        self::assertSame([$first, $second], $plans->findByShop($shop) === [$second, $first] ? [$first, $second] : $plans->findByShop($shop));
        self::assertCount(2, $plans->findByShop($shop));
        self::assertSame([$second], $plans->findOpenByShop($shop, self::now()));
        self::assertCount(3, $recurringOrders->findByShop($shop));
        self::assertCount(2, $recurringOrders->findByCustomer($customer));
        self::openSale($second);
        self::openSale($second);
        self::assertCount(2, $plans->findSalesTakingOrders($second, self::now()));
        self::assertCount(2, $plans->findSaleIdsToGenerate(self::now()));
        self::assertSame([$second], self::getContainer()->get(RecurringPlanCatalog::class)->openPlans($shop));

        // A season over is no longer offered.
        $second->setTerm(RecurringTerm::Season)->setSeasonStartsOn(self::now()->modify('-30 days'))->setSeasonEndsOn(self::now()->modify('-1 day'));
        self::assertSame([], $plans->findOpenByShop($shop, self::now()));
    }

    public function testAnOrderOfACustomerTaggedAsSubscriptionStillGetsItsConfirmation(): void
    {
        $plan = self::basketPlan();
        $sale = self::openSale($plan);

        self::getContainer()->get(OrderManager::class)->placeOrder(
            $sale,
            CustomerFactory::createOne(),
            new OrderRequest(self::first($sale->getDeliveries()), [(int) self::first($sale->getOffers())->getId() => Quantity::units(1)]),
            Actor::Customer,
            OrderSource::Subscription,
        );

        self::assertSame(['order_placed'], self::sentTypes());
    }

    public function testAPlanBelongsToItsRecurrence(): void
    {
        $plan = self::basketPlan();

        self::assertSame($plan, self::getContainer()->get(RecurringPlanRepository::class)->findOneBySchedule($plan->getSchedule()));
        self::assertInstanceOf(RecurringPlan::class, self::schedulePlan($plan->getSchedule()));
    }

    private function formatter(): FrenchFormatter
    {
        return self::getContainer()->get(FrenchFormatter::class);
    }
}
