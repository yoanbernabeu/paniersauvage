<?php

declare(strict_types=1);

namespace App\Tests\Recurring;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductUnit;
use App\Customer\Entity\Customer;
use App\Delivery\Entity\DeliveryPoint;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringOrderLine;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Entity\RecurringPlanItem;
use App\Recurring\Enum\OccurrenceOutcome;
use App\Recurring\Enum\RecurringEndReason;
use App\Recurring\Enum\RecurringMode;
use App\Recurring\Enum\RecurringOrderStatus;
use App\Recurring\Enum\RecurringTerm;
use App\Sale\Entity\SaleSchedule;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;

/**
 * Rules of the plans and subscriptions that need no database.
 */
final class RecurringModelTest extends TestCase
{
    private Shop $shop;
    private SaleSchedule $schedule;
    private DeliveryPoint $point;

    protected function setUp(): void
    {
        $this->shop = new Shop('Potager', 'potager');
        // Deliveries on Wednesdays.
        $this->schedule = new SaleSchedule($this->shop, 'Mercredi')->setWeekday(3);
        $this->point = new DeliveryPoint($this->shop, 'Parc');
    }

    public function testTheDeliveryDaysOfThePlan(): void
    {
        $plan = $this->plan();

        $dates = $plan->deliveryDatesBetween(new \DateTimeImmutable('2026-11-10 15:00'), new \DateTimeImmutable('2026-11-25'));

        self::assertSame(['2026-11-11', '2026-11-18', '2026-11-25'], $this->days($dates));
        self::assertSame('00:00', $dates[0]->format('H:i'));
        self::assertSame([], $plan->deliveryDatesBetween(new \DateTimeImmutable('2026-11-12'), new \DateTimeImmutable('2026-11-17')));
        self::assertSame('mercredi', $plan->weekdayName());
        self::assertSame('Mercredi', $plan->getTitle(), 'Named after the recurrence.');
        self::assertSame($this->shop, $plan->getShop());
    }

    public function testASeasonContract(): void
    {
        $plan = $this->plan()->setTerm(RecurringTerm::Season)
            ->setSeasonStartsOn(new \DateTimeImmutable('2026-11-11 10:00'))
            ->setSeasonEndsOn(new \DateTimeImmutable('2026-12-02 18:00'));

        self::assertTrue($plan->isSeason());
        self::assertSame('2026-11-11 00:00', $plan->getSeasonStartsOn()?->format('Y-m-d H:i'));
        self::assertSame(['2026-11-11', '2026-11-18', '2026-11-25', '2026-12-02'], $this->days($plan->remainingSeasonDates(new \DateTimeImmutable('2026-11-01'))));
        self::assertSame(['2026-11-25', '2026-12-02'], $this->days($plan->remainingSeasonDates(new \DateTimeImmutable('2026-11-25 20:00'))));
        self::assertTrue($plan->acceptsSubscriptions(new \DateTimeImmutable('2026-12-02')));
        self::assertFalse($plan->acceptsSubscriptions(new \DateTimeImmutable('2026-12-03')), 'Season over.');
        self::assertSame([], $this->plan()->remainingSeasonDates(new \DateTimeImmutable('2026-11-01')));
    }

    public function testSubscriptionsNeedAnOpenPlanOnAnActiveRecurrenceWithProducts(): void
    {
        $plan = $this->plan();
        $today = new \DateTimeImmutable('2026-11-10');
        self::assertTrue($plan->acceptsSubscriptions($today));

        self::assertFalse($plan->setEnabled(false)->acceptsSubscriptions($today));
        $plan->setEnabled(true);
        $this->schedule->setActive(false);
        self::assertFalse($plan->acceptsSubscriptions($today));
        $this->schedule->setActive(true);
        $plan->setItemQuantity($this->first($plan), 0, 0);
        self::assertFalse($plan->acceptsSubscriptions($today), 'Nothing to deliver.');
    }

    public function testTheItemsOfAPlan(): void
    {
        $plan = $this->plan();
        $bread = $this->first($plan);
        $tomatoes = new Product($this->shop, 'Tomates', 450)->setUnit(ProductUnit::Kg);

        $plan->setItemQuantity($tomatoes, 500, 1);
        $plan->setItemQuantity($bread, Quantity::units(2), 3);
        self::assertSame(2, $plan->getItems()->count());
        self::assertSame(Quantity::units(2), $plan->getItemFor($bread)?->getQuantity());
        self::assertSame(3, $plan->getItemFor($bread)->getPosition());
        self::assertSame(1800 * 2 + 225, $plan->basketPriceCents());
        self::assertSame(1800 * 4, $plan->getItemFor($bread)->priceCents(2));
        self::assertFalse($plan->containsAlcohol());
        $plan->setItemQuantity(new Product($this->shop, 'Cornas', 2500)->setAlcoholic(true), Quantity::units(1), 2);
        self::assertTrue($plan->containsAlcohol());

        $this->expectException(\InvalidArgumentException::class);
        $plan->setItemQuantity(new Product(new Shop('Autre', 'autre'), 'Pain', 500), Quantity::units(1), 0);
    }

    public function testAPieceIsNeverSplit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->plan()->setItemQuantity(new Product($this->shop, 'Radis', 180), 1500, 0);
    }

    public function testTheTextsAreTrimmed(): void
    {
        $plan = $this->plan()->setTitle('  Panier  ')->setDescription('   ')->setConditions(" Paiement au retrait \n");

        self::assertSame('Panier', $plan->getTitle());
        self::assertNull($plan->getDescription());
        self::assertSame('Paiement au retrait', $plan->getConditions());
        self::assertNull($plan->setConditions(null)->getConditions());
        self::assertSame('Les légumes', $plan->setDescription(' Les légumes ')->getDescription());
    }

    public function testWhatABasketSubscriptionReceives(): void
    {
        $plan = $this->plan();
        $plan->setItemQuantity(new Product($this->shop, 'Tomates', 450)->setUnit(ProductUnit::Kg), 500, 1);
        $recurringOrder = $this->subscribe($plan)->setShares(2);

        self::assertSame([['Panier', 2000], ['Tomates', 1000]], array_map(static fn (array $entry): array => [$entry[0]->getName(), $entry[1]], $recurringOrder->wantedQuantities()));
        self::assertSame(3600 + 450, $recurringOrder->estimatedPriceCents());
        self::assertTrue($recurringOrder->containsWeighedProducts());

        $this->expectException(\InvalidArgumentException::class);
        $recurringOrder->setShares(0);
    }

    public function testWhatAChoiceSubscriptionReceives(): void
    {
        $plan = $this->plan()->setMode(RecurringMode::Choice);
        $bread = $this->first($plan);
        $recurringOrder = $this->subscribe($plan);
        self::assertSame([], $recurringOrder->wantedQuantities());
        self::assertFalse($recurringOrder->containsWeighedProducts());

        $recurringOrder->setQuantityOf($bread, Quantity::units(3));
        self::assertSame(Quantity::units(3), $recurringOrder->getQuantityOf($bread));
        $recurringOrder->setQuantityOf($bread, Quantity::units(1));
        self::assertCount(1, $recurringOrder->getLines());
        self::assertSame(1800, $recurringOrder->estimatedPriceCents());
        $recurringOrder->setQuantityOf($bread, 0);
        self::assertCount(0, $recurringOrder->getLines());
        self::assertSame(0, $recurringOrder->getQuantityOf($bread));
        $recurringOrder->setQuantityOf($bread, 0);
        self::assertCount(0, $recurringOrder->getLines());
    }

    public function testPausedDays(): void
    {
        $plan = $this->plan()->setMaxPauses(2);
        $recurringOrder = $this->subscribe($plan);
        $now = new \DateTimeImmutable('2026-11-10 12:00');

        $recurringOrder->pause(new \DateTimeImmutable('2026-11-25 18:00'), $now)->pause(new \DateTimeImmutable('2026-11-18'), $now);
        $recurringOrder->pause(new \DateTimeImmutable('2026-11-18'), $now->modify('+1 hour'));

        self::assertSame(['2026-11-18', '2026-11-25'], $recurringOrder->getPausedDates());
        self::assertEquals($now, $recurringOrder->getUpdatedAt(), 'Pausing a paused day changes nothing.');
        self::assertTrue($recurringOrder->isPausedOn(new \DateTimeImmutable('2026-11-18 09:00')));
        self::assertFalse($recurringOrder->canPauseAgain());
        $recurringOrder->unpause(new \DateTimeImmutable('2026-11-18'), $now);
        self::assertSame(['2026-11-25'], $recurringOrder->getPausedDates());
        self::assertTrue($recurringOrder->canPauseAgain());
        self::assertTrue($this->subscribe($this->plan()->setMaxPauses(null))->canPauseAgain());
        self::assertFalse($this->subscribe($this->plan()->setMaxPauses(0))->canPauseAgain());
    }

    public function testTheDaysCoveredAndWhatIsLeftOfAContract(): void
    {
        $plan = $this->plan()->setTerm(RecurringTerm::Season)
            ->setSeasonStartsOn(new \DateTimeImmutable('2026-11-11'))
            ->setSeasonEndsOn(new \DateTimeImmutable('2026-12-02'));
        $recurringOrder = $this->subscribe($plan, new \DateTimeImmutable('2026-11-18 15:00'));

        self::assertSame('2026-11-18 00:00', $recurringOrder->getStartsOn()->format('Y-m-d H:i'));
        self::assertSame('2026-12-02', $recurringOrder->getEndsOn()?->format('Y-m-d'));
        self::assertFalse($recurringOrder->coversDate(new \DateTimeImmutable('2026-11-11')));
        self::assertTrue($recurringOrder->coversDate(new \DateTimeImmutable('2026-11-18 20:00')));
        self::assertTrue($recurringOrder->coversDate(new \DateTimeImmutable('2026-12-02 23:00')));
        self::assertFalse($recurringOrder->coversDate(new \DateTimeImmutable('2026-12-03')));

        $recurringOrder->pause(new \DateTimeImmutable('2026-11-25'), new \DateTimeImmutable());
        self::assertSame(2, $recurringOrder->remainingDeliveryCount(new \DateTimeImmutable('2026-11-01')));
        self::assertSame(3600, $recurringOrder->remainingTotalCents(new \DateTimeImmutable('2026-11-01')));
        self::assertSame(1, $recurringOrder->remainingDeliveryCount(new \DateTimeImmutable('2026-11-26')));

        $openEnded = $this->subscribe($this->plan());
        self::assertNull($openEnded->remainingDeliveryCount(new \DateTimeImmutable()));
        self::assertNull($openEnded->remainingTotalCents(new \DateTimeImmutable()));
        self::assertTrue($openEnded->coversDate(new \DateTimeImmutable('2030-01-01')));
    }

    public function testTheLifeOfASubscription(): void
    {
        $recurringOrder = $this->subscribe($this->plan());
        $now = new \DateTimeImmutable('2026-11-10 12:00');
        self::assertTrue($recurringOrder->isActive());

        $recurringOrder->suspend($now);
        self::assertSame(RecurringOrderStatus::Suspended, $recurringOrder->getStatus());
        self::assertEquals($now, $recurringOrder->getSuspendedAt());
        $recurringOrder->resume($now);
        self::assertTrue($recurringOrder->isActive());
        self::assertNull($recurringOrder->getSuspendedAt());

        $recurringOrder->cancelOn(new \DateTimeImmutable('2026-11-17 10:00'), $now);
        self::assertTrue($recurringOrder->isEnding());
        self::assertSame('2026-11-17 00:00', $recurringOrder->getEndsOn()?->format('Y-m-d H:i'));
        self::assertSame(RecurringEndReason::Customer, $recurringOrder->getEndReason());

        $recurringOrder->end(RecurringEndReason::ContractEnd, new \DateTimeImmutable('2026-11-18 03:00'));
        self::assertTrue($recurringOrder->isEnded());
        self::assertFalse($recurringOrder->isEnding());
        self::assertSame(RecurringEndReason::Customer, $recurringOrder->getEndReason(), 'The first reason stays.');
        self::assertSame('2026-11-17', $recurringOrder->getEndsOn()->format('Y-m-d'), 'A past last day is kept.');
        self::assertNotNull($recurringOrder->getEndedAt());

        $ended = $this->subscribe($this->plan())->end(RecurringEndReason::Producer, $now);
        self::assertSame('2026-11-10', $ended->getEndsOn()?->format('Y-m-d'));
        self::assertSame(RecurringEndReason::Producer, $ended->getEndReason());
    }

    public function testForbiddenTransitions(): void
    {
        $now = new \DateTimeImmutable();
        foreach ([
            fn (): RecurringOrder => $this->subscribe($this->plan())->resume($now),
            fn (): RecurringOrder => $this->subscribe($this->plan())->suspend($now)->suspend($now),
            fn (): RecurringOrder => $this->subscribe($this->plan())->end(RecurringEndReason::Producer, $now)->end(RecurringEndReason::Producer, $now),
            fn (): RecurringOrder => $this->subscribe($this->plan())->cancelOn($now, $now)->cancelOn($now, $now),
            fn (): RecurringOrder => $this->subscribe($this->plan())->end(RecurringEndReason::Producer, $now)->cancelOn($now, $now),
        ] as $index => $transition) {
            try {
                $transition();
                self::fail('Transition #'.$index.' should be refused.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testASubscriberHasAnEmailAndAPointOfTheShop(): void
    {
        $plan = $this->plan();
        $other = new DeliveryPoint(new Shop('Autre', 'autre'), 'Ailleurs');

        foreach ([
            fn (): RecurringOrder => new RecurringOrder($plan, Customer::withoutEmail($this->shop, new \DateTimeImmutable()), $this->point, new \DateTimeImmutable(), new \DateTimeImmutable()),
            fn (): RecurringOrder => new RecurringOrder($plan, new Customer('a@example.test'), $other, new \DateTimeImmutable(), new \DateTimeImmutable()),
            fn (): RecurringOrder => $this->subscribe($plan)->setDeliveryPoint($other),
        ] as $refused) {
            try {
                $refused();
                self::fail('Should be refused.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $recurringOrder = $this->subscribe($plan)->setDeliveryAddress('  ');
        self::assertNull($recurringOrder->getDeliveryAddress());
        self::assertSame('1 rue', $recurringOrder->setDeliveryAddress(' 1 rue ')->getDeliveryAddress());
        self::assertSame($this->shop, $recurringOrder->getShop());
        self::assertNull($recurringOrder->getAdultCertifiedAt());
        $at = new \DateTimeImmutable('2026-11-10');
        self::assertSame($at, $recurringOrder->certifyAdult($at)->certifyAdult(new \DateTimeImmutable())->getAdultCertifiedAt());
    }

    public function testTheQuantitiesOfItemsAndLinesAreChecked(): void
    {
        $plan = $this->plan();
        $item = $plan->getItems()->first();
        self::assertNotFalse($item);
        $radish = new Product($this->shop, 'Radis', 180);
        $recurringOrder = $this->subscribe($plan->setMode(RecurringMode::Choice));
        $recurringOrder->setQuantityOf($radish, Quantity::units(1));
        $line = $recurringOrder->getLines()->first();
        self::assertNotFalse($line);

        foreach ([
            static fn () => $item->setQuantity(0),
            static fn () => $item->setQuantity(1500),
            static fn (): RecurringPlanItem => new RecurringPlanItem($plan, $radish, 0, 0),
            static fn () => $line->setQuantity(0),
            static fn () => $line->setQuantity(500),
            static fn (): RecurringOrderLine => new RecurringOrderLine($recurringOrder, $radish, 1500),
            static fn (): RecurringOrderLine => new RecurringOrderLine($recurringOrder, $radish, 0),
        ] as $index => $refused) {
            try {
                $refused();
                self::fail('Quantity #'.$index.' should be refused.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(Quantity::units(3), $line->setQuantity(Quantity::units(3))->getQuantity());
        self::assertSame($recurringOrder, $line->getRecurringOrder());
        self::assertSame($plan, $item->getPlan());
    }

    public function testOnlyProductsSoldByWeightMakeAnEstimate(): void
    {
        self::assertFalse($this->subscribe($this->plan())->containsWeighedProducts());
    }

    public function testTheSeasonDatesAreBothNeeded(): void
    {
        $plan = $this->plan()->setTerm(RecurringTerm::Season)->setSeasonEndsOn(new \DateTimeImmutable('2026-12-02 18:30'));

        self::assertSame('00:00', $plan->getSeasonEndsOn()?->format('H:i'));
        self::assertSame([], $plan->remainingSeasonDates(new \DateTimeImmutable('2026-11-01')));
        self::assertSame([], $plan->setSeasonStartsOn(new \DateTimeImmutable('2026-11-11'))->setSeasonEndsOn(null)->remainingSeasonDates(new \DateTimeImmutable('2026-11-01')));

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $plan->setSeasonStartsOn(null)->setSeasonEndsOn(new \DateTimeImmutable('2026-11-12'));
        $messages = array_map(static fn (ConstraintViolationInterface $violation): string => (string) $violation->getMessage(), iterator_to_array($validator->validate($plan)));
        self::assertContains('Indique la première livraison du contrat.', $messages);
        self::assertNotContains('Le contrat doit compter au moins une livraison (le mercredi).', $messages);

        $plan->setSeasonStartsOn(new \DateTimeImmutable('2026-11-12'))->setSeasonEndsOn(new \DateTimeImmutable('2026-11-17'));
        $messages = array_map(static fn (ConstraintViolationInterface $violation): string => (string) $violation->getMessage(), iterator_to_array($validator->validate($plan)));
        self::assertSame(['Le contrat doit compter au moins une livraison (le mercredi).'], $messages);
        self::assertCount(0, $validator->validate($plan->setTerm(RecurringTerm::OpenEnded)));
    }

    public function testEndingKeepsTheDayAtMidnight(): void
    {
        $recurringOrder = $this->subscribe($this->plan())->end(RecurringEndReason::Producer, new \DateTimeImmutable('2026-11-10 15:30'));

        self::assertSame('2026-11-10 00:00', $recurringOrder->getEndsOn()?->format('Y-m-d H:i'));
    }

    public function testTheLabelsOfTheEnums(): void
    {
        self::assertSame(['Panier composé', 'Produits au choix'], array_map(static fn (RecurringMode $mode): string => $mode->label(), RecurringMode::cases()));
        self::assertStringContainsString('combien de paniers', RecurringMode::Basket->help());
        self::assertStringContainsString('jusqu\'à la quantité', RecurringMode::Choice->help());
        self::assertSame(['Sans engagement', 'Contrat de saison'], array_map(static fn (RecurringTerm $term): string => $term->label(), RecurringTerm::cases()));
        self::assertSame(['Actif', 'Suspendu', 'Terminé'], array_map(static fn (RecurringOrderStatus $status): string => $status->label(), RecurringOrderStatus::cases()));
        self::assertSame(['sage', 'default', 'muted'], array_map(static fn (RecurringOrderStatus $status): string => $status->badge(), RecurringOrderStatus::cases()));
        self::assertSame(['Résilié par l\'abonné', 'Arrêté par le producteur', 'Fin du contrat'], array_map(static fn (RecurringEndReason $reason): string => $reason->label(), RecurringEndReason::cases()));
        self::assertSame(
            [[false, 'sage'], [true, 'default'], [false, 'muted'], [true, 'destructive'], [true, 'destructive'], [false, 'sage']],
            array_map(static fn (OccurrenceOutcome $outcome): array => [$outcome->isShortage(), $outcome->badge()], OccurrenceOutcome::cases()),
        );
        self::assertSame('Commande incomplète', OccurrenceOutcome::Partial->label());
        self::assertCount(6, array_unique(array_map(static fn (OccurrenceOutcome $outcome): string => $outcome->label(), OccurrenceOutcome::cases())));
    }

    private function plan(): RecurringPlan
    {
        $plan = new RecurringPlan($this->schedule, new \DateTimeImmutable())->setEnabled(true);
        $plan->setItemQuantity(new Product($this->shop, 'Panier', 1800), Quantity::units(1), 0);

        return $plan;
    }

    private function subscribe(RecurringPlan $plan, ?\DateTimeImmutable $startsOn = null): RecurringOrder
    {
        return new RecurringOrder($plan, new Customer('claire@example.test'), $this->point, $startsOn ?? new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable());
    }

    private function first(RecurringPlan $plan): Product
    {
        $item = $plan->getItems()->first();
        self::assertNotFalse($item);

        return $item->getProduct();
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     *
     * @return list<string>
     */
    private function days(array $dates): array
    {
        return array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d'), $dates);
    }
}
