<?php

declare(strict_types=1);

namespace App\Tests\Recurring;

use App\Ordering\Entity\Order;
use App\Ordering\Repository\OrderRepository;
use App\Recurring\Entity\RecurringOccurrence;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Enum\OccurrenceOutcome;
use App\Recurring\Enum\RecurringEndReason;
use App\Recurring\Enum\RecurringMode;
use App\Recurring\Scheduler\RecurringOrderTask;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Sale\Enum\SaleStatus;
use App\Shared\Service\Quantity;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The stock rule (reduce, else leave out, else no order), the cases without order, and robustness.
 */
final class GenerationRulesTest extends KernelTestCase
{
    use Factories;
    use RecurringScenario;

    public function testAShortProductIsReducedToWhatIsLeftAndBothSidesAreTold(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $basket = ProductFactory::createOne(['shop' => $shop, 'name' => 'Panier de légumes', 'priceCents' => 1800]);
        $tomatoes = ProductFactory::new()->byWeight(250)->create(['shop' => $shop, 'name' => 'Tomates', 'priceCents' => 450]);
        $plan = self::basketPlan($shop, [[$basket, Quantity::units(1)], [$tomatoes, 1000]]);
        $first = self::subscription($plan, CustomerFactory::createOne(['lastName' => 'Arnaud']));
        self::subscription($plan, CustomerFactory::createOne(['lastName' => 'Blanc', 'firstName' => 'Louise']));
        // 1,6 kg of tomatoes: 1 kg for the first subscriber, 0,6 kg left → 0,5 kg (step of 250 g) for the second.
        $sale = self::openSale($plan, [(int) $tomatoes->getId() => 1600]);

        self::generator()->generateForSale($sale);

        [$a, $b] = self::occurrencesOf($sale);
        self::assertSame($first->getId(), $a->getRecurringOrder()->getId());
        self::assertSame(OccurrenceOutcome::Placed, $a->getOutcome());
        self::assertSame(OccurrenceOutcome::Partial, $b->getOutcome());
        self::assertEquals([['label' => 'Tomates', 'wanted' => 1000, 'given' => 500, 'unit' => 'kg']], $b->getMissing());
        $order = $b->getOrder();
        self::assertInstanceOf(Order::class, $order);
        self::assertSame(1800 + 225, $order->getTotalCents());

        self::assertSame(['recurring_order_generated', 'recurring_order_generated', 'recurring_shortages'], self::sentTypes());
        $emails = self::emails();
        self::assertStringContainsString('est prête', (string) $emails[0]->getSubject());
        self::assertStringContainsString('est incomplète', (string) $emails[1]->getSubject());
        self::assertStringContainsString("Tomates : 0,5\u{a0}kg sur 1\u{a0}kg", (string) $emails[1]->getTextBody());
        self::assertStringContainsString('Louise', (string) $emails[2]->getTextBody());
        self::assertStringContainsString("Tomates : 0,5\u{a0}kg sur 1\u{a0}kg", (string) $emails[2]->getHtmlBody());
        self::assertSame(self::first($shop->getUsers())->getEmail(), $emails[2]->getTo()[0]->getAddress());
    }

    public function testNoOrderWhenNothingIsLeft(): void
    {
        $plan = self::basketPlan();
        $product = self::first($plan->getItems())->getProduct();
        self::subscription($plan);
        $sale = self::openSale($plan, [(int) $product->getId() => 0]);

        self::generator()->generateForSale($sale);

        $occurrence = self::onlyOccurrence($sale);
        self::assertSame(OccurrenceOutcome::OutOfStock, $occurrence->getOutcome());
        self::assertNull($occurrence->getOrder());
        self::assertEquals([['label' => 'Panier de légumes', 'wanted' => 1000, 'given' => 0, 'unit' => 'piece']], $occurrence->getMissing());
        self::assertSame([], self::getContainer()->get(OrderRepository::class)->findBy(['sale' => $sale->getId()]));
        self::assertSame(['recurring_order_generated', 'recurring_shortages'], self::sentTypes());
        self::assertStringContainsString('Pas de commande le', (string) self::emails()[0]->getSubject());
        self::assertStringContainsString('aucune commande n\'a été créée cette fois', (string) self::emails()[0]->getTextBody());
    }

    public function testAProductTheSaleDoesNotOfferIsLeftOut(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $bread = ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain', 'priceCents' => 500]);
        $cake = ProductFactory::createOne(['shop' => $shop, 'name' => 'Brioche', 'priceCents' => 700]);
        $plan = self::basketPlan($shop, [[$bread, Quantity::units(1)], [$cake, Quantity::units(1)]]);
        self::subscription($plan);
        $sale = self::openSale($plan);
        $offer = $sale->getOfferFor($cake);
        self::assertNotNull($offer);
        $sale->removeOffer($offer);
        self::entityManager()->remove($offer);
        self::entityManager()->flush();

        self::generator()->generateForSale($sale);

        $occurrence = self::onlyOccurrence($sale);
        self::assertSame(OccurrenceOutcome::Partial, $occurrence->getOutcome());
        self::assertEquals([['label' => 'Brioche', 'wanted' => 1000, 'given' => 0, 'unit' => 'piece']], $occurrence->getMissing());
        self::assertSame(500, $occurrence->getOrder()?->getTotalCents());
    }

    public function testAPickUpPointTheSaleDoesNotServeGivesNoOrder(): void
    {
        $plan = self::basketPlan();
        $sale = self::openSale($plan);
        $other = DeliveryPointFactory::createOne(['shop' => $plan->getShop(), 'name' => 'Marché couvert']);
        $plan->getSchedule()->addDeliveryPoint($other);
        self::entityManager()->flush();
        self::subscription($plan, point: $other);

        self::generator()->generateForSale($sale);

        $occurrence = self::onlyOccurrence($sale);
        self::assertSame(OccurrenceOutcome::Unavailable, $occurrence->getOutcome());
        self::assertNull($occurrence->getOrder());
        self::assertEquals([['label' => 'Panier de légumes', 'wanted' => 1000, 'given' => 0, 'unit' => 'piece']], $occurrence->getMissing());
        self::assertStringContainsString('ne passe pas par ton point de retrait (Marché couvert)', (string) self::emails()[0]->getTextBody());
    }

    public function testAPausedDeliveryGetsNoOrderAndNoEmail(): void
    {
        $plan = self::basketPlan();
        $recurringOrder = self::subscription($plan);
        $sale = self::openSale($plan);
        $recurringOrder->pause($sale->getDeliveryDate(), self::now());
        self::entityManager()->flush();

        self::generator()->generateForSale($sale);

        $occurrence = self::onlyOccurrence($sale);
        self::assertSame(OccurrenceOutcome::Paused, $occurrence->getOutcome());
        self::assertNull($occurrence->getOrder());
        self::assertSame([], self::sentTypes());
    }

    public function testAnOrderPlacedByHandIsKept(): void
    {
        $plan = self::basketPlan();
        $customer = CustomerFactory::createOne();
        self::subscription($plan, $customer);
        $sale = self::openSale($plan);
        $offer = $sale->getOffers()->first();
        self::assertInstanceOf(SaleOffer::class, $offer);
        $order = OrderFactory::createOne(['sale' => $sale, 'customer' => $customer, 'saleDelivery' => $sale->getDeliveries()->first()]);
        OrderLineFactory::createOne(['order' => $order, 'saleOffer' => $offer, 'quantity' => Quantity::units(3)]);

        self::generator()->generateForSale($sale);

        $occurrence = self::onlyOccurrence($sale);
        self::assertSame(OccurrenceOutcome::AlreadyOrdered, $occurrence->getOutcome());
        self::assertSame($order->getId(), $occurrence->getOrder()?->getId());
        self::assertCount(1, self::getContainer()->get(OrderRepository::class)->findBy(['sale' => $sale->getId()]));
        self::assertSame([], self::sentTypes());
    }

    public function testOnlyTheActiveSubscriptionsCoveringTheDayAreServed(): void
    {
        $plan = self::basketPlan();
        $sale = self::openSale($plan);
        $active = self::subscription($plan);
        self::subscription($plan)->suspend(self::now());
        self::subscription($plan)->end(RecurringEndReason::Producer, self::now());
        self::subscription($plan)->cancelOn($sale->getDeliveryDate()->modify('-1 day'), self::now());
        // Last day of the notice: still served.
        $notice = self::subscription($plan)->cancelOn($sale->getDeliveryDate(), self::now());
        $later = new RecurringOrder($plan, CustomerFactory::createOne(), $active->getDeliveryPoint(), $sale->getDeliveryDate()->modify('+1 day'), self::now());
        $fromThatDay = new RecurringOrder($plan, CustomerFactory::createOne(), $active->getDeliveryPoint(), $sale->getDeliveryDate(), self::now());
        self::entityManager()->persist($later);
        self::entityManager()->persist($fromThatDay);
        self::entityManager()->flush();

        self::generator()->generateForSale($sale);

        $served = array_map(static fn (RecurringOccurrence $occurrence): ?int => $occurrence->getRecurringOrder()->getId(), self::occurrencesOf($sale));
        self::assertSame([$active->getId(), $notice->getId(), $fromThatDay->getId()], $served);
    }

    public function testNothingIsGeneratedForASaleThatNoLongerTakesOrders(): void
    {
        $plan = self::basketPlan();
        self::subscription($plan);

        self::assertSame([], self::generator()->generateForSale(self::openSale($plan, attributes: ['status' => SaleStatus::Scheduled])));
        self::assertSame([], self::generator()->generateForSale(self::openSale($plan, attributes: ['deadlineAt' => self::now()->modify('-1 minute')])));

        $sale = SaleFactory::createOne(['shop' => $plan->getShop()]);
        self::assertSame([], self::generator()->generateForSale($sale), 'Not a sale of a recurrence.');
        $sale->setSchedule(self::schedule($plan->getShop(), []));
        self::assertSame([], self::generator()->generateForSale($sale), 'A recurrence without plan.');
    }

    public function testAFailingSubscriberDoesNotStopTheOthers(): void
    {
        $plan = self::basketPlan();
        $home = DeliveryPointFactory::new()->home()->create(['shop' => $plan->getShop()]);
        $plan->getSchedule()->addDeliveryPoint($home);
        self::entityManager()->flush();
        $sale = self::openSale($plan);
        // Delivered at home without an address: OrderManager refuses the order.
        self::subscription($plan, point: $home);
        $fine = self::subscription($plan);

        $created = self::generator()->generateForSale($sale);

        self::assertCount(1, $created);
        $occurrences = self::occurrencesOf($sale);
        self::assertCount(1, $occurrences, 'Nothing recorded for the failing one: the next run tries again.');
        self::assertSame($fine->getId(), $occurrences[0]->getRecurringOrder()->getId());
    }

    public function testAHomeDeliveryCarriesTheAddressOfTheSubscriber(): void
    {
        $plan = self::basketPlan();
        $home = DeliveryPointFactory::new()->home()->create(['shop' => $plan->getShop()]);
        $plan->getSchedule()->addDeliveryPoint($home);
        self::entityManager()->flush();
        $sale = self::openSale($plan);
        self::subscription($plan, point: $home)->setDeliveryAddress('3 rue des Alpes, Valence');
        self::entityManager()->flush();

        self::generator()->generateForSale($sale);

        $order = self::onlyOccurrence($sale)->getOrder();
        self::assertSame('3 rue des Alpes, Valence', $order?->getDeliveryAddress());
        self::assertInstanceOf(SaleDelivery::class, $order->getSaleDelivery());
        self::assertTrue($order->getSaleDelivery()->isHome());
    }

    public function testANewSubscriptionJoinsTheSalesAlreadyOpen(): void
    {
        $plan = self::basketPlan();
        $sale = self::openSale($plan);
        self::openSale($plan, attributes: ['status' => SaleStatus::Scheduled]);
        $recurringOrder = self::subscription($plan);

        $created = self::generator()->generateForRecurringOrder($recurringOrder);

        self::assertCount(1, $created);
        self::assertSame($sale->getId(), $created[0]->getSale()->getId());
    }

    public function testTheTaskCatchesUpTheOpenSales(): void
    {
        $plan = self::basketPlan();
        self::subscription($plan);
        self::subscription($plan);
        self::openSale($plan);
        self::openSale($plan, attributes: ['status' => SaleStatus::Closed]);

        self::assertSame(['ended' => 0, 'generated' => 2], self::getContainer()->get(RecurringOrderTask::class)());
        self::assertSame(['ended' => 0, 'generated' => 0], self::getContainer()->get(RecurringOrderTask::class)());
    }

    public function testChoiceModeOrdersTheQuantitiesOfTheSubscriberSoldByWeight(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $cheese = ProductFactory::new()->byWeight(250)->create(['shop' => $shop, 'name' => 'Tomme', 'priceCents' => 2600]);
        $picodon = ProductFactory::createOne(['shop' => $shop, 'name' => 'Picodon', 'priceCents' => 280]);
        $plan = self::basketPlan($shop, [[$cheese, 2000], [$picodon, Quantity::units(6)]], RecurringMode::Choice);
        self::subscription($plan, quantities: [(int) $cheese->getId() => 750]);
        $sale = self::openSale($plan);

        self::generator()->generateForSale($sale);

        $order = self::onlyOccurrence($sale)->getOrder();
        self::assertInstanceOf(Order::class, $order);
        self::assertCount(1, $order->getLines());
        self::assertSame(750, self::first($order->getLines())->getQuantity());
        self::assertSame(1950, $order->getTotalCents());
        self::assertTrue($order->isEstimated());
    }
}
