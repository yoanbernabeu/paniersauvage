<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SolidarityCriterion;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductKind;
use App\Catalog\Enum\ProductUnit;
use App\Customer\Entity\Customer;
use App\Customer\Entity\ShopFollower;
use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Enum\DeliveryPointType;
use App\Legal\Entity\ShopVerification;
use App\Legal\LegalDocuments;
use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Service\OrderReferenceGenerator;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Sale\Entity\SaleSchedule;
use App\Sale\Service\SaleCodeGenerator;
use App\Shared\Enum\Actor;
use App\Shared\Service\FrenchFormatter;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use App\Shop\Entity\User;
use App\Shop\Enum\PaymentMethod as Pay;
use App\Shop\Enum\ShopCategory as Cat;
use App\Shop\Enum\VatRegime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo producers around Valence (Drôme), covering every case of the app: every kind of producer
 * and product unit, the three delivery point types (a tour included), every sale status
 * (scheduled, open, closed, done, cancelled), every order status, every subscription state, a
 * sold-out offer, active and paused recurrences, and the shops the producer directory must leave
 * out (hidden, blocked subscription, expired trial, too far away). Every shop is listed in the
 * directory with its categories and accepted means of payment, except the hidden one; the
 * home-delivery-only shop declares a zone around Tain-l'Hermitage (search "Tain").
 *
 * Search "Valence" (or /?lat=44.933&lng=4.892) to see them. Pick-up points carry their
 * coordinates: loading never calls the geocoding API. Producer logins: the e-mails below,
 * password "password".
 *
 * Quantities in this data set are whole units (int) or decimal strings for weights ('1,5' = 1.5 kg),
 * turned into thousandths by Quantity.
 *
 * @phpstan-type ProductData array{0: string, 1: int, 2?: ?ProductUnit, 3?: ?int, 4?: list<string>, 5?: int|string|null, 6?: bool, 7?: ?string, 8?: int, 9?: int}
 * @phpstan-type PointData array{0: DeliveryPointType, 1: string, 2?: ?string, 3?: ?float, 4?: ?float, 5?: string}
 * @phpstan-type ScheduleData array{name: string, weekday: int, start: string, end: string, deadline_days: int, active?: bool}
 * @phpstan-type OrderData array{0: int, 1: int, 2: array<int, int|string>, 3?: string, 4?: ?string, 5?: ?string, 6?: array<int, string>}
 * @phpstan-type SaleData array{status: string, max?: array<int, int|string>, orders?: list<OrderData>}
 * @phpstan-type ShopData array{slug: string, name: string, email: string, producer: array{string, string}, subscription: string, siret: string, phone: string, description: string, address: string, payment: string, listed?: bool, customer_edit?: bool, vat_franchise?: bool, offline_customers?: list<array{string, string, string}>, products: list<ProductData>, points: list<PointData>, schedule?: ScheduleData, followers?: array<int, bool>, sales: list<SaleData>}
 */
final class ValenceFixtures extends Fixture
{
    public const string PASSWORD = 'password';

    /** Every shop of this data set: none may use a reserved slug (ReservedSlugsTest). */
    public const array SHOP_SLUGS = [
        'potager-de-l-eperviere', 'chevrerie-des-monts-du-matin', 'ferme-rochas', 'domaine-des-granits',
        'vergers-de-la-veore', 'miellerie-du-vercors', 'fournil-de-romans', 'brasserie-du-confluent',
        'nougaterie-du-jabron', 'tisanes-de-la-raye', 'oeufs-de-portes', 'noix-de-l-isere',
        'paniers-de-tain', 'champignonniere-de-livron', 'huilerie-de-nyons',
    ];

    /**
     * Products that keep (wine, honey, oil, preserves...): 14-day right of withdrawal. Every other
     * product is a perishable food.
     */
    public const array NOT_PERISHABLE = [
        'Saint-Péray blanc 2024 (75 cl)', 'Cornas 2022 (75 cl)', 'Saint-Péray brut méthode traditionnelle', 'Jus de raisin (1 l)',
        'Blonde du Confluent (33 cl)', 'IPA du Vercors (33 cl)', 'Coffret découverte (6 bières)',
        'Confiture d’abricots (370 g)', 'Miel de lavande (500 g)', 'Miel de montagne (500 g)', 'Terrine de campagne (180 g)',
        'Nougat tendre (200 g)', 'Nougat noir (200 g)', 'Tisane du soir (30 g)', 'Thym séché (20 g)',
        'Noix AOP (1 kg)', 'Huile de noix (50 cl)', "Huile d'olive AOP (50 cl)", 'Olives noires de Nyons (250 g)',
    ];

    /** Alcoholic drinks: age certification on order, age check at pick-up. */
    public const array ALCOHOLIC = [
        'Saint-Péray blanc 2024 (75 cl)', 'Cornas 2022 (75 cl)', 'Saint-Péray brut méthode traditionnelle',
        'Blonde du Confluent (33 cl)', 'IPA du Vercors (33 cl)', 'Coffret découverte (6 bières)',
    ];

    /** Shops a 30 km search around Valence must show (in any order). */
    public const array SEARCHABLE_WITHIN_30_KM = [
        'potager-de-l-eperviere', 'chevrerie-des-monts-du-matin', 'ferme-rochas', 'domaine-des-granits',
        'vergers-de-la-veore', 'miellerie-du-vercors', 'fournil-de-romans', 'brasserie-du-confluent',
        'tisanes-de-la-raye',
    ];

    /**
     * What each shop sells and the means of payment it accepts (in addition to the free text).
     *
     * @var array<string, array{0: list<Cat>, 1: list<Pay>}>
     */
    private const array DIRECTORY = [
        'potager-de-l-eperviere' => [[Cat::Vegetables], [Pay::Cash, Pay::Cheque, Pay::InstantTransfer, Pay::FoodVoucher]],
        'chevrerie-des-monts-du-matin' => [[Cat::Dairy], [Pay::Cash, Pay::Cheque]],
        'ferme-rochas' => [[Cat::Meat], [Pay::Cheque, Pay::BankTransfer]],
        'domaine-des-granits' => [[Cat::Drinks], [Pay::Cheque, Pay::Cash, Pay::CardOnSite]],
        'vergers-de-la-veore' => [[Cat::Fruits], [Pay::Cash, Pay::Cheque, Pay::LocalCurrency]],
        'miellerie-du-vercors' => [[Cat::Honey], [Pay::Cash, Pay::Cheque]],
        'fournil-de-romans' => [[Cat::Bread], [Pay::Cash, Pay::InstantTransfer]],
        'brasserie-du-confluent' => [[Cat::Drinks], [Pay::CardOnSite, Pay::Cash]],
        'nougaterie-du-jabron' => [[Cat::Grocery], [Pay::Cash, Pay::Cheque]],
        'tisanes-de-la-raye' => [[Cat::Plants, Cat::Grocery], [Pay::Cash, Pay::Cheque]],
        'oeufs-de-portes' => [[Cat::EggsPoultry], [Pay::Cash, Pay::Cheque]],
        'noix-de-l-isere' => [[Cat::Grocery], [Pay::Cash, Pay::Cheque]],
        'paniers-de-tain' => [[Cat::Vegetables, Cat::Fruits], [Pay::Cash, Pay::Cheque, Pay::FoodVoucher]],
        'champignonniere-de-livron' => [[Cat::Vegetables], [Pay::Cash, Pay::Cheque]],
        'huilerie-de-nyons' => [[Cat::Grocery], [Pay::Cash, Pay::Cheque]],
    ];

    /**
     * INSEE codes of the towns of the pick-up points, as the geocoder gives them (`citycode`): a
     * territorial instance restricted to these towns (INSTANCE_TERRITORY, docs/INSTANCE.md) can be
     * tried on the demo data.
     *
     * @var array<string, string>
     */
    private const array INSEE_CODES = [
        'Valence' => '26362',
        'Chabeuil' => '26064',
        'Montélier' => '26197',
        'Alixan' => '26004',
        'Beaumont-lès-Valence' => '26037',
        'Étoile-sur-Rhône' => '26124',
        'Portes-lès-Valence' => '26252',
        'Bourg-lès-Valence' => '26058',
        'Romans-sur-Isère' => '26281',
        'Bourg-de-Péage' => '26057',
        'Montélimar' => '26198',
        'Saint-Marcel-lès-Valence' => '26313',
        'Châteauneuf-sur-Isère' => '26084',
        'Tain-l\'Hermitage' => '26347',
        'Livron-sur-Drôme' => '26165',
        'Nyons' => '26220',
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SaleCodeGenerator $saleCodeGenerator,
        private readonly OrderReferenceGenerator $orderReferenceGenerator,
        private readonly FrenchFormatter $formatter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();
        $customers = $this->loadCustomers($manager, $now);

        foreach ($this->shops() as $data) {
            $shop = new Shop($data['name'], $data['slug'])
                ->setDescription($data['description'])
                ->setAddress($data['address'])
                ->setPhone($data['phone'])
                ->setSiret($data['siret'])
                ->setPaymentInfo($data['payment'])
                ->setCategories(self::DIRECTORY[$data['slug']][0])
                ->setPaymentMethods(self::DIRECTORY[$data['slug']][1])
                ->setListedInSearch($data['listed'] ?? true)
                ->setAllowCustomerEdit($data['customer_edit'] ?? true)
                ->setVatRegime(($data['vat_franchise'] ?? false) ? VatRegime::Franchise : VatRegime::Normal);
            $manager->persist($shop);
            // Fictional SIRETs (valid checksum): marked verified, the company register is never called.
            $manager->persist(ShopVerification::verified($shop, $data['siret'], mb_strtoupper($data['name']), '01.13Z', $now->modify('-30 days')));

            [$firstName, $lastName] = $data['producer'];
            $producer = new User($data['email'])->setFirstName($firstName)->setLastName($lastName)->setShop($shop);
            $producer->setPassword($this->passwordHasher->hashPassword($producer, self::PASSWORD));
            $producer->acceptTerms(LegalDocuments::TERMS_VERSION, $now->modify('-30 days'));
            $manager->persist($producer);

            $manager->persist($this->subscription($shop, $data['subscription'], $now));

            $products = [];
            foreach ($data['products'] as $position => $product) {
                $products[] = $this->product($manager, $shop, $product, $position, $now);
            }

            $points = [];
            foreach ($data['points'] as $point) {
                $points[] = $this->deliveryPoint($manager, $shop, $point, $now);
            }

            if (\in_array(Pay::LocalCurrency, self::DIRECTORY[$data['slug']][1], true)) {
                $shop->setLocalCurrencyName('la Mesure');
            }
            if (\in_array(Pay::FoodVoucher, self::DIRECTORY[$data['slug']][1], true)) {
                $shop->setFoodVoucherDetails('chèques alimentaires du CCAS');
            }
            if ('paniers-de-tain' === $data['slug']) {
                // Home delivery zone, located like an address: the shop is found from Tain and around.
                $points[0]->setZoneTown('Tain-l\'Hermitage')->setZoneRadiusKm(12)->setCoordinates(45.0706, 4.8432, $now);
                $points[0]->setTown('Tain-l\'Hermitage', '26600', self::INSEE_CODES['Tain-l\'Hermitage']);
            }

            $schedule = null;
            if (isset($data['schedule'])) {
                $schedule = $this->schedule($manager, $shop, $data['schedule'], $points, $products);
            }

            foreach ($data['followers'] ?? [] as $index => $consent) {
                $manager->persist(new ShopFollower($customers[$index], $shop, $consent, $now->modify('-'.(10 + $index).' days')));
            }

            // Customers without e-mail of this shop (orders taken by phone) come after the shared ones.
            $shopCustomers = $customers;
            foreach ($data['offline_customers'] ?? [] as [$firstName, $lastName, $phone]) {
                $offline = Customer::withoutEmail($shop, $now->modify('-20 days'))->setFirstName($firstName)->setLastName($lastName)->setPhone($phone);
                $manager->persist($offline);
                $shopCustomers[] = $offline;
            }

            foreach ($data['sales'] as $sale) {
                $this->sale($manager, $shop, $sale, $points, $products, $shopCustomers, $schedule, $now);
            }
        }

        $manager->flush();
    }

    /**
     * @return list<Customer>
     */
    private function loadCustomers(ObjectManager $manager, \DateTimeImmutable $now): array
    {
        $customers = [];
        foreach ([
            ['claire.vincent@example.test', 'Claire', 'Vincent', '06 11 22 33 01'],
            ['hugo.bonnet@example.test', 'Hugo', 'Bonnet', '06 11 22 33 02'],
            ['sarah.meunier@example.test', 'Sarah', 'Meunier', '06 11 22 33 03'],
            ['karim.haddad@example.test', 'Karim', 'Haddad', '06 11 22 33 04'],
            ['ines.darnaud@example.test', 'Inès', "D'Arnaud", '06 11 22 33 05'],
            ['thomas.reynaud@example.test', 'Thomas', 'Reynaud', null],
            ['louise.blanc@example.test', 'Louise', 'Blanc', '06 11 22 33 07'],
            ['marc.perrin@example.test', 'Marc', 'Perrin', '06 11 22 33 08'],
            ['zoe.giraud@example.test', 'Zoé', 'Giraud', '06 11 22 33 09'],
            ['antoine.morel@example.test', null, null, null],
        ] as $index => [$email, $firstName, $lastName, $phone]) {
            $customer = new Customer($email, $now->modify('-'.(60 - $index * 3).' days'))
                ->setFirstName($firstName)
                ->setLastName($lastName)
                ->setPhone($phone);
            $manager->persist($customer);
            $customers[] = $customer;
        }

        return $customers;
    }

    private function subscription(Shop $shop, string $state, \DateTimeImmutable $now): ShopSubscription
    {
        return match ($state) {
            'trial' => ShopSubscription::startTrial($shop, $now),
            'trial_ending' => ShopSubscription::startTrial($shop, $now->modify('-27 days')),
            'trial_expired' => ShopSubscription::startTrial($shop, $now->modify('-45 days')),
            'monthly' => new ShopSubscription($shop)->setStatus(SubscriptionStatus::Active)->setPlan(SubscriptionPlan::Monthly)->setCurrentPeriodEnd($now->modify('+18 days')),
            'yearly' => new ShopSubscription($shop)->setStatus(SubscriptionStatus::Active)->setPlan(SubscriptionPlan::Yearly)->setCurrentPeriodEnd($now->modify('+200 days')),
            // Payment failed two days ago: still within the grace period, still searchable.
            'past_due' => new ShopSubscription($shop)->setStatus(SubscriptionStatus::PastDue)->setPlan(SubscriptionPlan::Monthly)->setCurrentPeriodEnd($now->modify('-2 days')),
            'canceled' => new ShopSubscription($shop)->setStatus(SubscriptionStatus::Canceled)->setPlan(SubscriptionPlan::Monthly)->setCurrentPeriodEnd($now->modify('-20 days')),
            // Solidarity plan at 5 € a month through Stripe (pay what you can, SPEC lot E).
            'solidarity_paid' => new ShopSubscription($shop)
                ->declareSolidarity([SolidarityCriterion::RecentInstallation], $now->modify('-2 months'))
                ->setStatus(SubscriptionStatus::Active)->setPlan(SubscriptionPlan::Solidarity)->setAmountCents(500)
                ->setStripeCustomerId('cus_demo_solidarity')->setStripeSubscriptionId('sub_demo_solidarity')
                ->setCurrentPeriodEnd($now->modify('+12 days')),
            // Free solidarity plan (0 €, no Stripe), declared 11 months ago: the renewal banner shows.
            'solidarity_free' => new ShopSubscription($shop)
                ->declareSolidarity([SolidarityCriterion::SmallVolume, SolidarityCriterion::FirstSeason], $now->modify('-11 months'))
                ->activateFreeSolidarity($now->modify('-11 months')),
            default => throw new \LogicException(\sprintf('Unknown subscription state "%s".', $state)),
        };
    }

    /**
     * @param ProductData $data name, price, unit, VAT (bp), labels, default max quantity, seasonal, allergens,
     *                          selling step and minimum by weight (thousandths of a kilogram)
     */
    private function product(ObjectManager $manager, Shop $shop, array $data, int $position, \DateTimeImmutable $now): Product
    {
        $product = new Product($shop, $data[0], $data[1])
            ->setUnit($data[2] ?? ProductUnit::Piece)
            ->setVatRateBp($data[3] ?? Product::DEFAULT_VAT_RATE_BP)
            ->setLabels($data[4] ?? [])
            ->setMaxQtyDefault($this->quantity($data[5] ?? null))
            ->setAllergens($data[7] ?? null)
            ->setPerishable(!\in_array($data[0], self::NOT_PERISHABLE, true))
            ->setAlcoholic(\in_array($data[0], self::ALCOHOLIC, true))
            ->setPosition($position);
        if (isset($data[8])) {
            $product->setQuantityStep($data[8])->setMinQuantity($data[9] ?? $data[8]);
        }

        if ($data[6] ?? false) {
            $product
                ->setKind(ProductKind::Seasonal)
                ->setAvailableFrom($now->modify('-30 days')->setTime(0, 0))
                ->setAvailableUntil($now->modify('+60 days')->setTime(0, 0));
        }

        $manager->persist($product);

        return $product;
    }

    /**
     * @param PointData $data type, name, address, latitude, longitude, instructions
     */
    private function deliveryPoint(ObjectManager $manager, Shop $shop, array $data, \DateTimeImmutable $now): DeliveryPoint
    {
        $point = new DeliveryPoint($shop, $data[1], $data[0])->setInstructions($data[5] ?? null);
        if (isset($data[2], $data[3], $data[4])) {
            $point->setAddress($data[2])->setCoordinates($data[3], $data[4], $now);
            $point->setTown($point->getCity(), $point->getPostcode(), self::INSEE_CODES[(string) $point->getCity()] ?? null);
        }
        $manager->persist($point);

        return $point;
    }

    /**
     * @param ScheduleData        $data
     * @param list<DeliveryPoint> $points
     * @param list<Product>       $products
     */
    private function schedule(ObjectManager $manager, Shop $shop, array $data, array $points, array $products): SaleSchedule
    {
        $schedule = new SaleSchedule($shop, $data['name'])
            ->setWeekday($data['weekday'])
            ->setDeliveryStartTime(new \DateTimeImmutable('1970-01-01 '.$data['start']))
            ->setDeliveryEndTime(new \DateTimeImmutable('1970-01-01 '.$data['end']))
            ->setDeadlineDaysBefore($data['deadline_days'])
            ->setDeadlineTime(new \DateTimeImmutable('1970-01-01 20:00'))
            ->setOpensDaysBefore(7)
            ->setActive($data['active'] ?? true);
        foreach ($points as $point) {
            $schedule->addDeliveryPoint($point);
        }
        foreach ($products as $product) {
            $schedule->addProduct($product);
        }
        $manager->persist($schedule);

        return $schedule;
    }

    /**
     * @param SaleData            $data
     * @param list<DeliveryPoint> $points
     * @param list<Product>       $products
     * @param list<Customer>      $customers
     */
    private function sale(ObjectManager $manager, Shop $shop, array $data, array $points, array $products, array $customers, ?SaleSchedule $schedule, \DateTimeImmutable $now): void
    {
        $today = $now->setTime(0, 0);
        // [delivery date, opens at, deadline at] for each status, relative to now.
        [$deliveryDate, $opensAt, $deadlineAt] = match ($data['status']) {
            'scheduled' => [$today->modify('+9 days'), $today->modify('+2 days')->setTime(8, 0), $today->modify('+6 days')->setTime(20, 0)],
            'open' => [$today->modify('+4 days'), $today->modify('-3 days')->setTime(8, 0), $today->modify('+2 days')->setTime(20, 0)],
            'closed' => [$today->modify('+1 day'), $today->modify('-6 days')->setTime(8, 0), $today->modify('-1 day')->setTime(20, 0)],
            'done' => [$today->modify('-6 days'), $today->modify('-13 days')->setTime(8, 0), $today->modify('-8 days')->setTime(20, 0)],
            'cancelled' => [$today->modify('+3 days'), $today->modify('-4 days')->setTime(8, 0), $today->modify('+1 day')->setTime(20, 0)],
            default => throw new \LogicException(\sprintf('Unknown sale status "%s".', $data['status'])),
        };

        $sale = new Sale($shop, $this->saleCodeGenerator->generate(), $deliveryDate, $opensAt, $deadlineAt, $opensAt->modify('-1 day'), 'Livraison du '.$this->formatter->dateLong($deliveryDate));
        $sale->setSchedule($schedule);

        foreach ($points as $position => $point) {
            // A tour: one stop after the other, half an hour each.
            $start = $deliveryDate->setTime(17, 0)->modify(\sprintf('+%d minutes', 30 * $position));
            new SaleDelivery($sale, $point, $start, $start->modify('+30 minutes'), $position);
        }

        $offers = [];
        foreach ($products as $index => $product) {
            if ($product->isAvailableOn($deliveryDate)) {
                $offers[$index] = new SaleOffer($sale, $product, null, null, $this->quantity($data['max'][$index] ?? null) ?? $product->getMaxQtyDefault());
            }
        }
        $manager->persist($sale);

        foreach ($data['orders'] ?? [] as $order) {
            $this->order($manager, $sale, $offers, $customers, $order, $opensAt->modify('+1 day'));
        }

        match ($data['status']) {
            'scheduled' => null,
            'open' => $sale->open(),
            'closed' => $sale->open()->close(),
            'done' => $sale->open()->close()->markDone(),
            'cancelled' => $sale->open()->cancel(),
        };
    }

    /**
     * @param array<int, SaleOffer> $offers
     * @param list<Customer>        $customers
     * @param OrderData             $data      customer, delivery index, product index => quantity, status, note, home address,
     *                                         product index => actual weight (products sold by weight, once handed over)
     */
    private function order(ObjectManager $manager, Sale $sale, array $offers, array $customers, array $data, \DateTimeImmutable $placedAt): void
    {
        $delivery = $sale->getDeliveries()->get($data[1]);
        \assert($delivery instanceof SaleDelivery);
        $status = $data[3] ?? 'confirmed';

        $order = new Order($sale, $customers[$data[0]], $delivery, $this->orderReferenceGenerator->generate(), $placedAt);
        $order->setNote($data[4] ?? null)->setDeliveryAddress($data[5] ?? null);

        foreach ($data[2] as $index => $typed) {
            $offer = $offers[$index] ?? throw new \LogicException(\sprintf('No offer #%d in sale "%s".', $index, $sale->getTitle()));
            $quantity = $this->quantity($typed) ?? 0;
            if ('cancelled' !== $status) {
                // A cancelled order has released its stock. Demo data is loaded by a single process: no lock.
                $offer->reserve($quantity);
            }
            $line = new OrderLine($order, $offer, $quantity);
            if (isset($data[6][$index])) {
                $line->setActualQuantity(self::quantity($data[6][$index]));
            }
        }
        $order->recomputeTotal();
        $order->recordEvent(OrderEventType::Placed, Actor::Customer, ['total_cents' => $order->getTotalCents()], $placedAt);
        if (isset($data[6])) {
            $order->recordEvent(OrderEventType::Weighed, Actor::Producer, ['total_cents' => $order->getTotalCents()], $delivery->getStartsAt()->modify('+5 minutes'));
        }

        $deliveredAt = $delivery->getStartsAt()->modify('+10 minutes');
        match ($status) {
            'confirmed' => null,
            'delivered' => $order->markDelivered()->recordEvent(OrderEventType::Delivered, Actor::Producer, [], $deliveredAt),
            'not_delivered' => $order->markNotDelivered()->recordEvent(OrderEventType::NotDelivered, Actor::Producer, [], $deliveredAt),
            'cancelled' => $order->cancel()->recordEvent(OrderEventType::Cancelled, Actor::Customer, ['total_cents' => $order->getTotalCents()], $placedAt->modify('+5 hours')),
            default => throw new \LogicException(\sprintf('Unknown order status "%s".', $status)),
        };

        $manager->persist($order);
    }

    /**
     * 2 → 2000 (whole units), '1,5' → 1500 (a weight), null → null.
     */
    private function quantity(int|string|null $value): ?int
    {
        return match (true) {
            null === $value => null,
            \is_int($value) => Quantity::units($value),
            default => Quantity::parse($value) ?? throw new \LogicException(\sprintf('Invalid quantity "%s".', $value)),
        };
    }

    /**
     * @return list<ShopData>
     */
    private function shops(): array
    {
        $cash = 'Espèces ou chèque, à la livraison.';
        $kg = ProductUnit::Kg;
        $bunch = ProductUnit::Bunch;
        $meeting = DeliveryPointType::MeetingPoint;
        $stop = DeliveryPointType::TourStop;
        $home = DeliveryPointType::Home;

        return [
            // Market gardener in Valence: meeting point + home delivery, weekly recurrence, open sale
            // with every order status, a past sale fully delivered.
            [
                'slug' => 'potager-de-l-eperviere', 'name' => "Le Potager de l'Épervière", 'email' => 'potager@example.test',
                'producer' => ['Julien', 'Arnaud'], 'subscription' => 'monthly', 'siret' => '81234567800013', 'phone' => '06 20 10 00 01',
                'description' => "Légumes de saison cultivés en agriculture biologique, à deux pas du Rhône.\nRécoltés la veille de la livraison.",
                'address' => "Chemin de l'Épervière\n26000 Valence", 'payment' => 'Espèces, chèque ou Wero au 06 20 10 00 01.',
                'products' => [
                    ['Panier de légumes de la semaine', 1800, null, null, ['Bio'], null, false, null],
                    ['Tomates anciennes', 450, $kg, null, ['Bio'], 20, true, null, 250, 500],
                    ['Courgettes', 280, $kg, null, ['Bio'], null, true, null, 500],
                    ['Botte de radis', 180, $bunch, null, ['Bio']],
                    ['Salade batavia', 150, null, null, ['Bio']],
                    ['Botte de basilic', 200, $bunch, null, ['Bio'], 10, true, null],
                ],
                'points' => [
                    [$meeting, 'Parc Jouvet, entrée avenue Gambetta', "Avenue Gambetta\n26000 Valence", 44.929537, 4.888227, 'Sous les platanes, à côté du kiosque.'],
                    [$home, 'Livraison à domicile dans Valence', null, null, null, 'Le mercredi entre 17 h et 19 h.'],
                ],
                'schedule' => ['name' => 'Livraison du mercredi', 'weekday' => 3, 'start' => '17:00', 'end' => '19:00', 'deadline_days' => 2],
                'followers' => [0 => true, 1 => true, 2 => false, 3 => true, 6 => true],
                // Customer #10: Gisèle orders by phone, without e-mail.
                'offline_customers' => [['Gisèle', 'Roux', '04 75 41 22 33']],
                'sales' => [
                    ['status' => 'open', 'orders' => [
                        [10, 0, [0 => 1, 2 => '1'], 'confirmed', 'Commande prise au téléphone.'],
                        [0, 0, [0 => 1, 1 => '1,5'], 'confirmed', 'Pas trop mûres les tomates, svp.'],
                        [1, 0, [0 => 1, 2 => '1,5', 3 => 2, 4 => 1]],
                        [2, 1, [0 => 1, 5 => 1], 'confirmed', null, "14 rue Madier de Montjau\n26000 Valence"],
                        [3, 0, [2 => 2, 4 => 2], 'cancelled'],
                    ]],
                    ['status' => 'done', 'orders' => [
                        [0, 0, [0 => 1], 'delivered'],
                        [1, 0, [1 => 1, 3 => 1], 'delivered', null, null, [1 => '1,12']],
                        [6, 0, [0 => 1], 'not_delivered', 'Je passerai peut-être en retard.'],
                        [2, 1, [0 => 1], 'delivered', null, "14 rue Madier de Montjau\n26000 Valence"],
                    ]],
                ],
            ],
            // Goat cheese (Picodon AOP) on a tour of three stops; a limited offer is sold out.
            [
                'slug' => 'chevrerie-des-monts-du-matin', 'name' => 'Chèvrerie des Monts du Matin', 'email' => 'chevrerie@example.test',
                'producer' => ['Mathilde', 'Faure'], 'subscription' => 'yearly', 'siret' => '81234567800021', 'phone' => '06 20 10 00 02',
                'description' => 'Picodons AOP et fromages frais au lait cru de nos chèvres, au pied du Vercors.',
                'address' => "Quartier Les Faures\n26120 Chabeuil", 'payment' => $cash,
                'products' => [
                    ['Picodon AOP affiné', 280, null, null, ['AOP', 'Lait cru'], null, false, 'Lait'],
                    ['Picodon frais', 240, null, null, ['AOP', 'Lait cru'], null, false, 'Lait'],
                    ['Faisselle (500 g)', 390, null, null, [], 8, false, 'Lait'],
                    ['Tomme de chèvre', 2600, $kg, null, ['Lait cru'], null, false, 'Lait', 250],
                ],
                'points' => [
                    [$stop, 'Chabeuil, place Génissieu', "Place Génissieu\n26120 Chabeuil", 44.898486, 5.019512],
                    [$stop, 'Montélier, parking de la mairie', "Place de la Mairie\n26120 Montélier", 44.935026, 5.030839],
                    [$stop, 'Alixan, devant la boulangerie', "Grande Rue\n26300 Alixan", 44.974388, 5.027982],
                ],
                'schedule' => ['name' => 'Tournée du vendredi', 'weekday' => 5, 'start' => '17:00', 'end' => '18:30', 'deadline_days' => 2],
                'followers' => [0 => true, 4 => true, 5 => true, 7 => false],
                'sales' => [
                    ['status' => 'open', 'max' => [2 => 6], 'orders' => [
                        [4, 0, [0 => 4, 2 => 3]],
                        [5, 1, [1 => 6, 2 => 3], 'confirmed', 'Je viendrai avec mes boîtes.'],
                        [7, 2, [3 => '0,5']],
                    ]],
                    ['status' => 'done', 'orders' => [
                        [4, 0, [0 => 6], 'delivered'],
                        [5, 1, [1 => 4, 3 => '0,25'], 'delivered', null, null, [3 => '0,268']],
                    ]],
                ],
            ],
            // Livestock farmer: meat boxes in limited quantity, a scheduled sale only, free trial.
            [
                'slug' => 'ferme-rochas', 'name' => 'Ferme Rochas', 'email' => 'eleveur@example.test',
                'producer' => ['Pierre', 'Rochas'], 'subscription' => 'trial', 'siret' => '81234567800039', 'phone' => '06 20 10 00 03',
                'description' => "Bœufs et porcs élevés en plein air sur nos prairies de Beaumont-lès-Valence.\nColis découpés à la ferme.",
                'address' => "Route de Montéléger\n26760 Beaumont-lès-Valence", 'payment' => 'Chèque ou virement, à la livraison.', 'customer_edit' => false,
                'products' => [
                    ['Colis de bœuf (5 kg)', 9500, null, null, ['Label Rouge'], 15],
                    ['Colis de porc (5 kg)', 6500, null, null, [], 15],
                    ['Saucisses de Toulouse', 1690, $kg, null, [], null, false, null, 500],
                    ['Terrine de campagne (180 g)', 650],
                ],
                'points' => [
                    [$meeting, 'À la ferme', "Route de Montéléger\n26760 Beaumont-lès-Valence", 44.862158, 4.942563, 'Suivre le panneau « Ferme Rochas », cour de la ferme.'],
                ],
                'followers' => [1 => true, 3 => true],
                'sales' => [['status' => 'scheduled']],
            ],
            // Winemaker across the Rhône (Ardèche side): 20 % VAT, two meeting points, open sale.
            [
                'slug' => 'domaine-des-granits', 'name' => 'Domaine des Granits', 'email' => 'vigneron@example.test',
                'producer' => ['Élodie', 'Chave'], 'subscription' => 'monthly', 'siret' => '81234567800047', 'phone' => '06 20 10 00 04',
                'description' => 'Saint-Péray et Cornas en biodynamie, vinifiés au domaine. À consommer avec modération.',
                'address' => "Chemin des Granits\n07130 Saint-Péray", 'payment' => 'Chèque, espèces ou carte au caveau.',
                'products' => [
                    ['Saint-Péray blanc 2024 (75 cl)', 1600, null, 2000, ['Bio', 'Biodynamie'], null, false, 'Sulfites'],
                    ['Cornas 2022 (75 cl)', 3200, null, 2000, ['Bio'], 24, false, 'Sulfites'],
                    ['Saint-Péray brut méthode traditionnelle', 1800, null, 2000, ['Bio'], null, false, 'Sulfites'],
                    ['Jus de raisin (1 l)', 550, null, 550],
                ],
                'points' => [
                    [$meeting, 'Caveau du domaine', "Chemin des Granits\n07130 Saint-Péray", 44.948613, 4.844724],
                    [$meeting, 'Guilherand-Granges, place de la mairie', "Place Paul Mauvais\n07500 Guilherand-Granges", 44.933045, 4.873521],
                ],
                'followers' => [1 => true, 7 => true, 8 => true],
                'sales' => [['status' => 'open', 'orders' => [
                    [7, 0, [0 => 6, 1 => 3]],
                    [8, 1, [2 => 2, 3 => 4]],
                ]]],
            ],
            // Fruit grower: the sale is closed (deadline passed), delivery tomorrow.
            [
                'slug' => 'vergers-de-la-veore', 'name' => 'Les Vergers de la Véore', 'email' => 'arboriculteur@example.test',
                'producer' => ['Nicolas', 'Brunel'], 'subscription' => 'monthly', 'siret' => '81234567800054', 'phone' => '06 20 10 00 05',
                'description' => 'Abricots, pêches et pommes de la vallée de la Véore, cueillis à maturité.',
                'address' => "Quartier Les Rioux\n26800 Étoile-sur-Rhône", 'payment' => $cash,
                'products' => [
                    ['Abricots Bergeron', 450, $kg, null, ['HVE'], null, true, null, 500, 1000],
                    ['Pêches de vigne', 520, $kg, null, ['HVE'], null, true, null, 500],
                    ['Pommes Golden', 280, $kg, null, ['HVE'], null, false, null, 1000],
                    ['Confiture d’abricots (370 g)', 590],
                ],
                'points' => [
                    [$meeting, 'Étoile-sur-Rhône, place de la mairie', "Place Léon Lérisse\n26800 Étoile-sur-Rhône", 44.838842, 4.890178],
                    [$meeting, 'Portes-lès-Valence, marché couvert', "Place de la République\n26800 Portes-lès-Valence", 44.876034, 4.877016],
                ],
                'followers' => [2 => true, 6 => true, 9 => true],
                'sales' => [['status' => 'closed', 'orders' => [
                    [2, 0, [0 => 3, 3 => 2]],
                    [6, 1, [1 => 2, 2 => 3]],
                    [9, 1, [0 => 5]],
                ]]],
            ],
            // Beekeeper under the franchise en base de TVA (art. 293 B du CGI): a done sale and the next one scheduled.
            [
                'slug' => 'miellerie-du-vercors', 'name' => 'Miellerie du Vercors', 'email' => 'miel@example.test',
                'producer' => ['Lucas', 'Moulin'], 'subscription' => 'yearly', 'siret' => '81234567800062', 'phone' => '06 20 10 00 06',
                'description' => 'Miels de lavande, de montagne et de châtaignier, extraits à froid.',
                'address' => "Les Chirouzes\n26120 Upie", 'payment' => $cash, 'vat_franchise' => true,
                'products' => [
                    ['Miel de lavande (500 g)', 1100, null, null, ['Bio']],
                    ['Miel de montagne (500 g)', 1000, null, null, ['Bio']],
                    ['Pain d’épices', 750, null, null, [], null, false, 'Gluten'],
                ],
                'points' => [
                    [$meeting, 'Marché de Bourg-lès-Valence', "Place Aristide Briand\n26500 Bourg-lès-Valence", 44.947514, 4.894545],
                ],
                'followers' => [3 => true, 8 => false],
                'sales' => [
                    ['status' => 'done', 'orders' => [[3, 0, [0 => 2], 'delivered'], [8, 0, [1 => 1, 2 => 1], 'delivered']]],
                    ['status' => 'scheduled'],
                ],
            ],
            // Baker in Romans (pognes!): the free trial ends in three days.
            [
                'slug' => 'fournil-de-romans', 'name' => 'Le Fournil de Romans', 'email' => 'pogne@example.test',
                'producer' => ['Sophie', 'Garnier'], 'subscription' => 'trial_ending', 'siret' => '81234567800070', 'phone' => '06 20 10 00 07',
                'description' => 'Pognes de Romans et pains au levain, cuits au feu de bois chaque samedi.',
                'address' => "Place Jacquemart\n26100 Romans-sur-Isère", 'payment' => 'Espèces ou Wero.',
                'products' => [
                    ['Pogne de Romans (500 g)', 950, null, null, [], null, false, 'Gluten, lait, œufs'],
                    ['Pain au levain (1 kg)', 650, null, null, ['Bio'], null, false, 'Gluten'],
                    ['Suisse de Valence', 350, null, null, [], 20, false, 'Gluten, lait, œufs'],
                ],
                'points' => [
                    [$meeting, 'Place Jacquemart', "Place Jacquemart\n26100 Romans-sur-Isère", 45.043524, 5.051108],
                    [$meeting, 'Bourg-de-Péage, devant la mairie', "Place de la Mairie\n26300 Bourg-de-Péage", 45.037913, 5.055921],
                ],
                'followers' => [0 => true, 5 => true],
                'sales' => [['status' => 'open', 'orders' => [[5, 0, [0 => 1, 2 => 4]]]]],
            ],
            // Craft brewery: payment failed two days ago, still within the grace period.
            [
                'slug' => 'brasserie-du-confluent', 'name' => 'Brasserie du Confluent', 'email' => 'brasseur@example.test',
                'producer' => ['Romain', 'Teyssier'], 'subscription' => 'past_due', 'siret' => '81234567800088', 'phone' => '06 20 10 00 08',
                'description' => "Bières artisanales brassées à Valence avec l'eau du Vercors. À consommer avec modération.",
                'address' => "Rue Denis Papin\n26000 Valence", 'payment' => 'Carte ou espèces à la brasserie.',
                'products' => [
                    ['Blonde du Confluent (33 cl)', 350, null, 2000, ['Bio'], null, false, 'Gluten (orge)'],
                    ['IPA du Vercors (33 cl)', 390, null, 2000, [], null, false, 'Gluten (orge)'],
                    ['Coffret découverte (6 bières)', 2100, null, 2000, [], 10, false, 'Gluten (orge)'],
                ],
                'points' => [
                    [$meeting, 'À la brasserie', "Rue Denis Papin\n26000 Valence", 44.920451, 4.909914, 'Entrée par le portail bleu.'],
                ],
                'followers' => [1 => true, 7 => true],
                'sales' => [['status' => 'open', 'orders' => [[1, 0, [2 => 1]]]]],
            ],
            // Nougat maker in Montélimar: about 45 km away, found only with the 50 km radius.
            [
                'slug' => 'nougaterie-du-jabron', 'name' => 'Nougaterie du Jabron', 'email' => 'nougat@example.test',
                'producer' => ['Anaïs', 'Roux'], 'subscription' => 'monthly', 'siret' => '81234567800096', 'phone' => '06 20 10 00 09',
                'description' => 'Nougat blanc de Montélimar au miel de lavande et aux amandes de Provence.',
                'address' => "Allées Provençales\n26200 Montélimar", 'payment' => $cash,
                'products' => [
                    ['Nougat tendre (200 g)', 780, null, 550, [], null, false, 'Fruits à coque, œufs'],
                    ['Nougat noir (200 g)', 820, null, 550, [], null, false, 'Fruits à coque'],
                ],
                'points' => [
                    [$meeting, 'Allées Provençales', "Allées Provençales\n26200 Montélimar", 44.558097, 4.750937],
                ],
                'sales' => [['status' => 'open']],
            ],
            // Herbal teas: listed, but the recurrence is paused and no sale is planned.
            [
                'slug' => 'tisanes-de-la-raye', 'name' => 'Les Tisanes de la Raye', 'email' => 'tisanes@example.test',
                'producer' => ['Camille', 'Bertrand'], 'subscription' => 'monthly', 'siret' => '81234567800104', 'phone' => '06 20 10 00 10',
                'description' => 'Plantes aromatiques et médicinales cueillies et séchées à la ferme.',
                'address' => "Chemin de la Raye\n26760 Beaumont-lès-Valence", 'payment' => $cash,
                'products' => [
                    ['Tisane du soir (30 g)', 650, null, null, ['Bio']],
                    ['Thym séché (20 g)', 450, null, null, ['Bio']],
                ],
                'points' => [
                    [$meeting, 'Marché de Saint-Marcel-lès-Valence', "Place de la Mairie\n26320 Saint-Marcel-lès-Valence", 44.972108, 4.955019],
                ],
                'schedule' => ['name' => 'Marché du samedi', 'weekday' => 6, 'start' => '09:00', 'end' => '12:00', 'deadline_days' => 2, 'active' => false],
                'sales' => [],
            ],
            // Left out of the search: subscription cancelled (new sales blocked). Past sale kept.
            [
                'slug' => 'oeufs-de-portes', 'name' => 'Les Œufs de Portes', 'email' => 'oeufs@example.test',
                'producer' => ['Bernard', 'Chapus'], 'subscription' => 'canceled', 'siret' => '81234567800112', 'phone' => '06 20 10 00 11',
                'description' => 'Œufs de poules élevées en plein air et volailles fermières.',
                'address' => "Route de Beauvallon\n26800 Portes-lès-Valence", 'payment' => $cash,
                'products' => [['Œufs plein air (12)', 480], ['Poulet fermier', 1450, $kg, null, ['Label Rouge']]],
                'points' => [[$meeting, 'À la ferme', "Route de Beauvallon\n26800 Portes-lès-Valence", 44.866224, 4.888461]],
                'followers' => [9 => true],
                'sales' => [['status' => 'done', 'orders' => [[9, 0, [0 => 2], 'delivered']]]],
            ],
            // Left out of the search: the producer chose to hide the shop.
            [
                'slug' => 'noix-de-l-isere', 'name' => "Les Noix de l'Isère", 'email' => 'noix@example.test',
                'producer' => ['Henri', 'Vial'], 'subscription' => 'solidarity_free', 'siret' => '81234567800120', 'phone' => '06 20 10 00 12',
                'description' => 'Noix AOP de Grenoble, cerneaux et huile de noix. Clientèle complète pour cette saison.',
                'address' => "Les Champs\n26300 Châteauneuf-sur-Isère", 'payment' => $cash, 'listed' => false,
                'products' => [['Noix AOP (1 kg)', 900, null, null, ['AOP']], ['Huile de noix (50 cl)', 1500]],
                'points' => [[$meeting, 'Au moulin', "Les Champs\n26300 Châteauneuf-sur-Isère", 45.016964, 4.940026]],
                'sales' => [['status' => 'open']],
            ],
            // Home delivery only: found through its delivery zone (12 km around Tain), not around Valence.
            [
                'slug' => 'paniers-de-tain', 'name' => 'Les Paniers de Tain', 'email' => 'paniers@example.test',
                'producer' => ['Laura', 'Michel'], 'subscription' => 'solidarity_paid', 'siret' => '81234567800138', 'phone' => '06 20 10 00 13',
                'description' => 'Paniers de fruits et légumes livrés chez toi, entre Tain et Tournon.',
                'address' => "Avenue Jean Jaurès\n26600 Tain-l'Hermitage", 'payment' => $cash,
                'products' => [['Panier familial', 2500], ['Panier solo', 1400]],
                'points' => [[$home, 'Livraison à domicile', null, null, null, 'Tain-l’Hermitage et Tournon, le jeudi soir.']],
                'followers' => [8 => true],
                'sales' => [['status' => 'open', 'orders' => [[8, 0, [1 => 1], 'confirmed', null, "3 quai Farconnet\n26600 Tain-l'Hermitage"]]]],
            ],
            // Left out of the search: trial expired. Its last sale was cancelled.
            [
                'slug' => 'champignonniere-de-livron', 'name' => 'Champignonnière de Livron', 'email' => 'champignons@example.test',
                'producer' => ['David', 'Lambert'], 'subscription' => 'trial_expired', 'siret' => '81234567800146', 'phone' => '06 20 10 00 14',
                'description' => 'Pleurotes et shiitakés cultivés dans nos caves.',
                'address' => "Quartier des Caves\n26250 Livron-sur-Drôme", 'payment' => $cash,
                'products' => [['Pleurotes', 1200, $kg], ['Shiitakés', 1800, $kg]],
                'points' => [[$meeting, 'Place de la mairie', "Place de la Mairie\n26250 Livron-sur-Drôme", 44.772357, 4.842845]],
                'followers' => [2 => true],
                'sales' => [['status' => 'cancelled', 'orders' => [[2, 0, [0 => 1], 'cancelled']]]],
            ],
            // Left out of a 50 km search: Nyons is about 65 km from Valence.
            [
                'slug' => 'huilerie-de-nyons', 'name' => 'Huilerie de Nyons', 'email' => 'huile@example.test',
                'producer' => ['Marion', 'Escoffier'], 'subscription' => 'monthly', 'siret' => '81234567800153', 'phone' => '06 20 10 00 15',
                'description' => "Huile d'olive AOP de Nyons et olives noires Tanche.",
                'address' => "Promenade de la Digue\n26110 Nyons", 'payment' => $cash,
                'products' => [["Huile d'olive AOP (50 cl)", 1650, null, null, ['AOP']], ['Olives noires de Nyons (250 g)', 750, null, null, ['AOP']]],
                'points' => [[$meeting, 'Place du Docteur Bourdongle', "Place du Docteur Bourdongle\n26110 Nyons", 44.360588, 5.140584]],
                'sales' => [['status' => 'open']],
            ],
        ];
    }
}
