<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Catalog\Entity\Product;
use App\Customer\Entity\Customer;
use App\Delivery\Entity\DeliveryPoint;
use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderSource;
use App\Recurring\Entity\RecurringOccurrence;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Enum\OccurrenceOutcome;
use App\Recurring\Enum\RecurringMode;
use App\Recurring\Enum\RecurringTerm;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleSchedule;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;

/**
 * Recurring orders of the Valence data set (SPEC « lot H »):
 *
 * - Le Potager de l'Épervière: a weekly composed basket, open-ended with a week of notice, four
 *   subscribers (one delivered at home, one who paused the coming delivery);
 * - Chèvrerie des Monts du Matin: a season contract (cheese picked one by one, two absences),
 *   three subscribers, one of whom had already ordered by hand, and a delivery that lacked faisselle.
 *
 * The orders of the open and past sales loaded by ValenceFixtures stand for the generated ones.
 */
final class RecurringFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function getDependencies(): array
    {
        return [ValenceFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();
        $today = $now->setTime(0, 0);

        $this->loadVegetableBasket($manager, $now, $today);
        $this->loadSeasonContract($manager, $now, $today);

        $manager->flush();
    }

    private function loadVegetableBasket(ObjectManager $manager, \DateTimeImmutable $now, \DateTimeImmutable $today): void
    {
        $shop = $this->shop($manager, 'potager-de-l-eperviere');
        $schedule = $this->schedule($manager, $shop);
        [$park, $home] = $this->points($manager, $shop);

        $plan = new RecurringPlan($schedule, $now->modify('-60 days'))
            ->setTitle('Panier de légumes du mercredi')
            ->setDescription('Les légumes de la semaine, une salade et une botte de radis, récoltés la veille.')
            ->setMode(RecurringMode::Basket)
            ->setMaxShares(2)
            ->setCapacity(12)
            ->setTerm(RecurringTerm::OpenEnded)
            ->setNoticeDays(7)
            ->setConditions("Panier oublié : il n'est ni reporté ni remboursé. Absent ? Mets la livraison en pause avant le lundi 20 h.\nRèglement à chaque retrait, en espèces, chèque ou Wero.")
            ->setEnabled(true);
        foreach (['Panier de légumes de la semaine', 'Salade batavia', 'Botte de radis'] as $position => $name) {
            $plan->setItemQuantity($this->product($manager, $shop, $name), Quantity::units(1), $position);
        }
        $manager->persist($plan);

        [$open, $done] = $this->sales($manager, $schedule);

        $claire = $this->subscribe($manager, $plan, 'claire.vincent@example.test', $park, $today->modify('-45 days'), $now);
        $hugo = $this->subscribe($manager, $plan, 'hugo.bonnet@example.test', $park, $today->modify('-30 days'), $now);
        $hugo->setShares(2);
        $sarah = $this->subscribe($manager, $plan, 'sarah.meunier@example.test', $home, $today->modify('-20 days'), $now);
        $sarah->setDeliveryAddress("14 rue Madier de Montjau\n26000 Valence");
        $karim = $this->subscribe($manager, $plan, 'karim.haddad@example.test', $park, $today->modify('-10 days'), $now);
        // Away this week: the delivery is paused and its order was cancelled.
        $karim->pause($open->getDeliveryDate(), $now->modify('-2 days'));

        foreach ([$claire, $hugo, $sarah] as $recurringOrder) {
            $this->occurrence($manager, $recurringOrder, $open, OccurrenceOutcome::Placed, $now);
            $this->occurrence($manager, $recurringOrder, $done, OccurrenceOutcome::Placed, $now);
        }
        $this->occurrence($manager, $karim, $open, OccurrenceOutcome::Paused, $now);
    }

    private function loadSeasonContract(ObjectManager $manager, \DateTimeImmutable $now, \DateTimeImmutable $today): void
    {
        $shop = $this->shop($manager, 'chevrerie-des-monts-du-matin');
        $schedule = $this->schedule($manager, $shop);
        [$chabeuil, $montelier, $alixan] = $this->points($manager, $shop);

        $plan = new RecurringPlan($schedule, $now->modify('-40 days'))
            ->setTitle('Contrat fromages d\'automne')
            ->setDescription('Tes picodons et ta faisselle chaque vendredi, de la rentrée jusqu\'à l\'hiver.')
            ->setMode(RecurringMode::Choice)
            ->setCapacity(10)
            ->setTerm(RecurringTerm::Season)
            // Fridays, like the deliveries of the recurrence.
            ->setSeasonStartsOn($schedule->nextDeliveryDates($today->modify('-22 days'), 1)[0])
            ->setSeasonEndsOn($schedule->nextDeliveryDates($today->modify('+11 weeks'), 1)[0])
            ->setMaxPauses(2)
            ->setConditions("Le contrat t'engage pour toute la saison : je prévois la traite et l'affinage en fonction des contrats.\nDeux absences possibles, à signaler avant le mercredi 20 h. Règlement à chaque retrait.")
            ->setEnabled(true);
        foreach ([['Picodon AOP affiné', 6], ['Picodon frais', 6], ['Faisselle (500 g)', 2]] as $position => [$name, $max]) {
            $plan->setItemQuantity($this->product($manager, $shop, $name), Quantity::units($max), $position);
        }
        $manager->persist($plan);

        [$open, $done] = $this->sales($manager, $schedule);
        [$affine, $frais, $faisselle] = array_map(fn (string $name): Product => $this->product($manager, $shop, $name), ['Picodon AOP affiné', 'Picodon frais', 'Faisselle (500 g)']);

        $ines = $this->subscribe($manager, $plan, 'ines.darnaud@example.test', $chabeuil, $plan->getSeasonStartsOn() ?? $today, $now);
        $ines->setQuantityOf($affine, Quantity::units(4))->setQuantityOf($faisselle, Quantity::units(1));
        $thomas = $this->subscribe($manager, $plan, 'thomas.reynaud@example.test', $montelier, $plan->getSeasonStartsOn() ?? $today, $now);
        $thomas->setQuantityOf($frais, Quantity::units(6))->setQuantityOf($faisselle, Quantity::units(2));
        $marc = $this->subscribe($manager, $plan, 'marc.perrin@example.test', $alixan, $today->modify('-14 days'), $now);
        $marc->setQuantityOf($affine, Quantity::units(2));

        $this->occurrence($manager, $ines, $open, OccurrenceOutcome::Placed, $now);
        $this->occurrence($manager, $thomas, $open, OccurrenceOutcome::Placed, $now);
        // Marc had ordered some tomme by hand before his first generated order: his order is kept.
        $this->occurrence($manager, $marc, $open, OccurrenceOutcome::AlreadyOrdered, $now);
        $this->occurrence($manager, $ines, $done, OccurrenceOutcome::Placed, $now);
        $this->occurrence($manager, $thomas, $done, OccurrenceOutcome::Partial, $now)
            ->setMissing([['label' => 'Faisselle (500 g)', 'wanted' => Quantity::units(2), 'given' => 0, 'unit' => 'piece']]);
    }

    private function subscribe(ObjectManager $manager, RecurringPlan $plan, string $email, DeliveryPoint $point, \DateTimeImmutable $startsOn, \DateTimeImmutable $now): RecurringOrder
    {
        $customer = $manager->getRepository(Customer::class)->findOneBy(['email' => $email]) ?? throw new \LogicException(\sprintf('No customer "%s".', $email));
        $recurringOrder = new RecurringOrder($plan, $customer, $point, $startsOn, $startsOn->setTime(19, 30));
        $recurringOrder->touch($now);
        $manager->persist($recurringOrder);

        return $recurringOrder;
    }

    /**
     * Links the order of the subscriber in that sale (cancelled ones too, for a paused delivery).
     */
    private function occurrence(ObjectManager $manager, RecurringOrder $recurringOrder, Sale $sale, OccurrenceOutcome $outcome, \DateTimeImmutable $now): RecurringOccurrence
    {
        $order = $manager->getRepository(Order::class)->findOneBy(['sale' => $sale, 'customer' => $recurringOrder->getCustomer()]);
        if ($order instanceof Order && \in_array($outcome, [OccurrenceOutcome::Placed, OccurrenceOutcome::Partial], true)) {
            $order->setSource(OrderSource::Subscription);
        }

        $occurrence = new RecurringOccurrence($recurringOrder, $sale, $outcome, min($now, $sale->getOpensAt()))->setOrder($order);
        $manager->persist($occurrence);

        return $occurrence;
    }

    private function shop(ObjectManager $manager, string $slug): Shop
    {
        return $manager->getRepository(Shop::class)->findOneBy(['slug' => $slug]) ?? throw new \LogicException(\sprintf('No shop "%s".', $slug));
    }

    private function schedule(ObjectManager $manager, Shop $shop): SaleSchedule
    {
        return $manager->getRepository(SaleSchedule::class)->findOneBy(['shop' => $shop]) ?? throw new \LogicException('No recurrence.');
    }

    /**
     * @return list<DeliveryPoint>
     */
    private function points(ObjectManager $manager, Shop $shop): array
    {
        return $manager->getRepository(DeliveryPoint::class)->findBy(['shop' => $shop], ['id' => 'ASC']);
    }

    private function product(ObjectManager $manager, Shop $shop, string $name): Product
    {
        return $manager->getRepository(Product::class)->findOneBy(['shop' => $shop, 'name' => $name]) ?? throw new \LogicException(\sprintf('No product "%s".', $name));
    }

    /**
     * The open sale and the past one of the recurrence.
     *
     * @return array{Sale, Sale}
     */
    private function sales(ObjectManager $manager, SaleSchedule $schedule): array
    {
        $sales = $manager->getRepository(Sale::class)->findBy(['schedule' => $schedule], ['deliveryDate' => 'DESC']);
        $open = array_values(array_filter($sales, static fn (Sale $sale): bool => $sale->isOpen()))[0] ?? throw new \LogicException('No open sale.');
        $done = array_values(array_filter($sales, static fn (Sale $sale): bool => $sale->isDone()))[0] ?? throw new \LogicException('No past sale.');

        return [$open, $done];
    }
}
