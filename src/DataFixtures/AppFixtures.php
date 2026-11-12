<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Billing\Entity\ShopSubscription;
use App\Catalog\Entity\CatalogPublication;
use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductKind;
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
use App\Shop\Enum\PaymentMethod;
use App\Shop\Enum\ShopCategory;
use App\Shop\Enum\ShopRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo data set: the "Fournil du Pont" bakery (SPEC running example), plus a few neighbours so
 * that the producer search of the home page has something to show ("Saoû", "Crest", "26400",
 * "Toulouse"). Pick-up points get their coordinates here: loading the demo never calls the API.
 *
 * Producer login: boulanger@example.test / password — public page slug: fournil-du-pont. Its team
 * member: mitron@example.test (same password, no access to the subscription). The bakery caps a
 * first order at 30 € and asks new customers a deposit; Paul missed two deliveries, Lucie and Jean
 * are regulars.
 * Other demo producers: maraicher@, fromager@, apiculteur@example.test (same password).
 */
final class AppFixtures extends Fixture
{
    public const string PRODUCER_EMAIL = 'boulanger@example.test';
    public const string PRODUCER_PASSWORD = 'password';
    public const string SHOP_SLUG = 'fournil-du-pont';
    public const string MEMBER_EMAIL = 'mitron@example.test';

    /** Fictional SIRET with a valid checksum. */
    public const string SHOP_SIRET = '12345678900015';

    /** Every demo shop: none may use a reserved slug (ReservedSlugsTest). */
    public const array DEMO_SHOP_SLUGS = [self::SHOP_SLUG, 'jardins-de-la-gervanne', 'fromagerie-du-capitole', 'rucher-des-coteaux'];

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

        // Shop, producer, subscription (free trial)
        $shop = new Shop('Fournil du Pont', self::SHOP_SLUG)
            ->setDescription("Pains au levain naturel, façonnés à la main et cuits au feu de bois.\nFarines bio du moulin voisin.")
            ->setSiret(self::SHOP_SIRET)
            ->setAddress("3 chemin du Pont\n26400 Saoû")
            ->setPhone('06 12 34 56 78')
            ->setLegalName('Camille Dupont EI')
            ->setCategories([ShopCategory::Bread])
            ->setListedInSearch(true)
            ->setPaymentMethods([PaymentMethod::Cash, PaymentMethod::Cheque, PaymentMethod::InstantTransfer])
            ->setPaymentInfo('Wero au 06 12 34 56 78, à la livraison.')
            // Protection of new customers (SPEC, lot D): a cap on a first order, a deposit outside the platform.
            ->setFirstOrderCapCents(3000)
            ->setNewCustomerDeposit('5 € par Wero à la commande, déduits au retrait');
        $manager->persist($shop);
        // Fictional SIRET (valid checksum): marked verified, the company register is never called.
        $manager->persist(ShopVerification::verified($shop, self::SHOP_SIRET, 'FOURNIL DU PONT', '10.71C', $now->modify('-30 days')));

        $producer = new User(self::PRODUCER_EMAIL)
            ->setFirstName('Camille')
            ->setLastName('Dupont')
            ->setShop($shop);
        $producer->setPassword($this->passwordHasher->hashPassword($producer, self::PRODUCER_PASSWORD));
        $producer->acceptTerms(LegalDocuments::TERMS_VERSION, $now->modify('-30 days'));
        $manager->persist($producer);
        $shop->addUser($producer);

        // A member of the team: runs the shop, cannot manage the subscription nor the team.
        $member = new User(self::MEMBER_EMAIL)
            ->setFirstName('Théo')
            ->setLastName('Vidal')
            ->setShopRole(ShopRole::Member);
        $member->setPassword($this->passwordHasher->hashPassword($member, self::PRODUCER_PASSWORD));
        $member->acceptTerms(LegalDocuments::TERMS_VERSION, $now->modify('-10 days'));
        $shop->addUser($member);
        $manager->persist($member);

        $manager->persist(ShopSubscription::startTrial($shop, $now));
        // Catalogue published in the Data Food Consortium format (/fournil-du-pont/catalogue.jsonld).
        $manager->persist(new CatalogPublication($shop)->setDfcPublic(true, $now));

        // Catalogue: permanent range + seasonal products
        $products = [];
        $catalogue = [
            // key => [name, price (cents), kind, default max quantity (thousandths), description, labels]
            'campagne' => ['Pain de campagne', 520, ProductKind::Permanent, null, 'Le classique au levain, 800 g, croûte épaisse.', ['Bio', 'Levain naturel']],
            'complet' => ['Pain complet', 480, ProductKind::Permanent, null, 'Farine complète T150, 600 g.', ['Bio']],
            'baguette' => ['Baguette tradition', 130, ProductKind::Permanent, null, 'Longue fermentation, 250 g.', []],
            'graines' => ['Pain aux graines', 390, ProductKind::Permanent, null, 'Lin, tournesol, sésame et courge, 500 g.', ['Bio']],
            'brioche' => ['Brioche au beurre', 800, ProductKind::Seasonal, Quantity::units(12), 'Pur beurre, tressée, 500 g. En petite quantité.', []],
            'noix' => ['Pain aux noix', 560, ProductKind::Seasonal, null, 'Aux noix de Grenoble de la saison, 500 g.', ['Bio']],
        ];
        $position = 0;
        foreach ($catalogue as $key => [$name, $priceCents, $kind, $maxQty, $description, $labels]) {
            $product = new Product($shop, $name, $priceCents)
                ->setDescription($description)
                ->setKind($kind)
                ->setMaxQtyDefault($maxQty)
                ->setLabels($labels)
                ->setAllergens('noix' === $key ? 'Gluten, fruits à coque' : ('brioche' === $key ? 'Gluten, lait, œufs' : 'Gluten'))
                ->setPosition($position++);
            if (ProductKind::Seasonal === $kind) {
                $product
                    ->setAvailableFrom($now->modify('-45 days')->setTime(0, 0))
                    ->setAvailableUntil($now->modify('+75 days')->setTime(0, 0));
            }
            $manager->persist($product);
            $products[$key] = $product;
        }

        // Delivery points
        $square = new DeliveryPoint($shop, "Place de l'église", DeliveryPointType::MeetingPoint)
            ->setAddress("Place de l'église\n26400 Saoû")
            ->setCoordinates(44.646423, 5.062418, $now)
            ->setInstructions('Je suis garé devant la fontaine, camionnette verte.');
        $home = new DeliveryPoint($shop, 'Livraison à domicile', DeliveryPointType::Home)
            ->setInstructions('Dans le village et les hameaux voisins, en fin de journée.');
        $manager->persist($square);
        $manager->persist($home);

        // Weekly recurrence: delivery on Monday 18:00–19:00, orders until Friday 20:00
        $schedule = new SaleSchedule($shop, 'Livraison du lundi')
            ->setWeekday(1)
            ->setDeliveryStartTime(new \DateTimeImmutable('1970-01-01 18:00'))
            ->setDeliveryEndTime(new \DateTimeImmutable('1970-01-01 19:00'))
            ->setDeadlineDaysBefore(3)
            ->setDeadlineTime(new \DateTimeImmutable('1970-01-01 20:00'))
            ->setOpensDaysBefore(7)
            ->addDeliveryPoint($square)
            ->addDeliveryPoint($home);
        $manager->persist($schedule);

        // Customers, all following the shop
        $customers = [];
        foreach ([
            ['lucie.martin@example.test', 'Lucie', 'Martin', true],
            ['paul.garnier@example.test', 'Paul', 'Garnier', true],
            ['nadia.benali@example.test', 'Nadia', 'Benali', false],
            ['jean.roche@example.test', 'Jean', 'Roche', true],
            ['emma.faure@example.test', 'Emma', 'Faure', true],
        ] as [$email, $firstName, $lastName, $consent]) {
            $customer = new Customer($email, $now->modify('-30 days'))->setFirstName($firstName)->setLastName($lastName)->setPhone('06 00 00 00 00');
            $manager->persist($customer);
            $manager->persist(new ShopFollower($customer, $shop, $consent, $now->modify('-20 days')));
            $customers[] = $customer;
        }
        $openDeliveryDate = array_find($schedule->nextDeliveryDates($now, 3), fn ($date): bool => $schedule->deadlineAtFor($date) > $now->modify('+1 day'));
        \assert(null !== $openDeliveryDate);

        $openSale = $this->createSale($manager, $schedule, $openDeliveryDate, $now->modify('-2 days')->setTime(8, 0), $products, [$square, $home]);
        $openSale->open();

        $this->createOrder($manager, $openSale, $customers[0], 0, ['campagne' => 2, 'brioche' => 1], $now->modify('-1 day'));
        $this->createOrder($manager, $openSale, $customers[1], 0, ['baguette' => 4, 'graines' => 1, 'noix' => 1], $now->modify('-1 day'), 'Bien cuites les baguettes, merci !');
        $this->createOrder($manager, $openSale, $customers[2], 1, ['complet' => 1, 'brioche' => 2], $now->modify('-5 hours'), null, "12 route des Écoliers\n26400 Saoû");
        $this->createOrder($manager, $openSale, $customers[3], 0, ['campagne' => 1, 'complet' => 1, 'baguette' => 2], $now->modify('-2 hours'));

        // A past sale, two Mondays before the open one, fully delivered
        $pastDeliveryDate = $openDeliveryDate->modify('-14 days');
        $pastSale = $this->createSale($manager, $schedule, $pastDeliveryDate, $schedule->opensAtFor($pastDeliveryDate), $products, [$square, $home]);
        $pastSale->open()->close()->markDone();

        foreach ([
            [$customers[0], ['campagne' => 2]],
            [$customers[1], ['baguette' => 3, 'graines' => 1]],
            [$customers[3], ['campagne' => 1, 'brioche' => 1]],
            [$customers[4], ['complet' => 2, 'noix' => 1]],
        ] as [$customer, $quantities]) {
            $order = $this->createOrder($manager, $pastSale, $customer, 0, $quantities, $pastSale->getDeadlineAt()->modify('-1 day'));
            $order->markDelivered();
            $order->recordEvent(OrderEventType::Delivered, Actor::Producer, [], $pastDeliveryDate->setTime(18, 30));
        }

        $this->loadClosedSaleWithLeftovers($manager, $now, $products, $square, $customers);

        // Two more past Mondays: Lucie and Jean come back (regular customers), Paul does not come
        // twice ("2 absences" next to his name on the open sale).
        foreach ([7, 21] as $weeksBack => $daysBack) {
            $deliveryDate = $openDeliveryDate->modify(\sprintf('-%d days', $daysBack));
            $sale = $this->createSale($manager, $schedule, $deliveryDate, $schedule->opensAtFor($deliveryDate), $products, [$square, $home]);
            $sale->open()->close()->markDone();

            foreach ([
                [$customers[0], ['campagne' => 1, 'baguette' => 2], true],
                [$customers[1], ['baguette' => 2], false],
                [$customers[3], [(0 === $weeksBack ? 'graines' : 'complet') => 1], true],
            ] as [$customer, $quantities, $came]) {
                $order = $this->createOrder($manager, $sale, $customer, 0, $quantities, $sale->getDeadlineAt()->modify('-1 day'));
                $came ? $order->markDelivered() : $order->markNotDelivered();
                $order->recordEvent($came ? OrderEventType::Delivered : OrderEventType::NotDelivered, Actor::Producer, [], $deliveryDate->setTime(18, 45));
            }
        }

        $this->loadNeighbours($manager, $now);

        $manager->flush();
    }

    /**
     * A one-off sale closed yesterday evening, delivered in two days, with leftovers of its limited
     * products: the producer can tick its delivery sheet and open a last-minute sale (SPEC §21).
     * Jean and Emma follow the shop, accept e-mails and have not ordered: they would be told.
     *
     * @param array<string, Product> $products
     * @param list<Customer>         $customers
     */
    private function loadClosedSaleWithLeftovers(ObjectManager $manager, \DateTimeImmutable $now, array $products, DeliveryPoint $square, array $customers): void
    {
        $deliveryDate = $now->modify('+2 days')->setTime(0, 0);
        $sale = new Sale(
            $square->getShop(),
            $this->saleCodeGenerator->generate(),
            $deliveryDate,
            $now->modify('-6 days')->setTime(8, 0),
            $now->modify('-1 day')->setTime(20, 0),
            $now->modify('-7 days'),
            'Fournée spéciale du '.$this->formatter->dateLong($deliveryDate),
        );
        new SaleDelivery($sale, $square, $deliveryDate->setTime(18, 0), $deliveryDate->setTime(19, 0));
        new SaleOffer($sale, $products['campagne'], maxQty: Quantity::units(20));
        new SaleOffer($sale, $products['brioche'], maxQty: Quantity::units(12));
        new SaleOffer($sale, $products['baguette']);
        $manager->persist($sale);
        $sale->open()->close();

        $this->createOrder($manager, $sale, $customers[0], 0, ['campagne' => 6, 'brioche' => 3, 'baguette' => 2], $now->modify('-3 days'));
        $this->createOrder($manager, $sale, $customers[1], 0, ['campagne' => 4, 'brioche' => 2], $now->modify('-2 days'), 'Je passerai vers 18 h 30.');
        $this->createOrder($manager, $sale, $customers[2], 0, ['campagne' => 4, 'brioche' => 2, 'baguette' => 4], $now->modify('-1 day')->setTime(18, 0));
    }

    /**
     * Three more producers for the producer search: one near Saoû with an open sale, two around
     * Toulouse (one open sale, one sale that opens soon).
     */
    private function loadNeighbours(ObjectManager $manager, \DateTimeImmutable $now): void
    {
        $neighbours = [
            [
                'slug' => 'jardins-de-la-gervanne', 'name' => 'Les Jardins de la Gervanne', 'email' => 'maraicher@example.test', 'siret' => '82345678900010', 'categories' => [ShopCategory::Vegetables],
                'description' => 'Légumes de saison cultivés sans pesticide au bord de la Gervanne.',
                'products' => [['Panier de légumes', 1500], ['Salade', 150], ['Œufs (6)', 280]],
                'points' => [['Marché de Crest', "Place de la Libération\n26400 Crest", 44.728307, 5.022284]],
                'sale' => 'open',
            ],
            [
                'slug' => 'fromagerie-du-capitole', 'name' => 'Fromagerie du Capitole', 'email' => 'fromager@example.test', 'siret' => '82345678900028', 'categories' => [ShopCategory::Dairy],
                'description' => 'Tommes et fromages frais de brebis, affinés à la ferme dans le Lauragais.',
                'products' => [['Tomme de brebis (250 g)', 650], ['Fromage frais', 320]],
                'points' => [
                    ['Marché Victor Hugo', "Place Victor Hugo\n31000 Toulouse", 43.606148, 1.447217],
                    ['Arrêt de Balma', "Place de la Libération\n31130 Balma", 43.611071, 1.499415],
                ],
                'sale' => 'open',
            ],
            [
                'slug' => 'rucher-des-coteaux', 'name' => 'Le Rucher des Coteaux', 'email' => 'apiculteur@example.test', 'siret' => '82345678900036', 'categories' => [ShopCategory::Honey],
                'description' => 'Miels de fleurs et de châtaignier, récoltés sur les coteaux de Colomiers.',
                'products' => [['Miel de fleurs (500 g)', 900], ['Miel de châtaignier (500 g)', 1000]],
                'points' => [['Place de la mairie', "1 place Alex Raymond\n31770 Colomiers", 43.611572, 1.335155]],
                'sale' => 'scheduled',
            ],
        ];

        foreach ($neighbours as $data) {
            $shop = new Shop($data['name'], $data['slug'])
                ->setDescription($data['description'])
                ->setSiret($data['siret'])
                ->setCategories($data['categories'])
                ->setListedInSearch(true)
                ->setPaymentMethods([PaymentMethod::Cash, PaymentMethod::Cheque])
                ->setPaymentInfo('Sur place.');
            $manager->persist($shop);
            $manager->persist(ShopVerification::verified($shop, $data['siret'], mb_strtoupper($data['name']), '01.13Z', $now->modify('-30 days')));

            $producer = new User($data['email'])->setFirstName('Demo')->setLastName('Producteur')->setShop($shop);
            $producer->setPassword($this->passwordHasher->hashPassword($producer, self::PRODUCER_PASSWORD));
            $producer->acceptTerms(LegalDocuments::TERMS_VERSION, $now->modify('-30 days'));
            $manager->persist($producer);
            $manager->persist(ShopSubscription::startTrial($shop, $now));

            $products = [];
            foreach ($data['products'] as $position => [$name, $priceCents]) {
                $products[] = $product = new Product($shop, $name, $priceCents)->setPosition($position);
                $manager->persist($product);
            }

            $points = [];
            foreach ($data['points'] as [$name, $address, $latitude, $longitude]) {
                $points[] = $point = new DeliveryPoint($shop, $name, DeliveryPointType::MeetingPoint)
                    ->setAddress($address)
                    ->setCoordinates($latitude, $longitude, $now);
                $manager->persist($point);
            }

            // Next Saturday morning (at least two days ahead), orders until the Thursday before at 20:00.
            $deliveryDate = $now->modify('+2 days')->modify('next saturday')->setTime(0, 0);
            $deadlineAt = $deliveryDate->modify('-2 days')->setTime(20, 0);
            $opensAt = 'open' === $data['sale'] ? $now->modify('-1 day')->setTime(8, 0) : $now->modify('+1 day')->setTime(8, 0);
            if ('scheduled' === $data['sale']) {
                $deliveryDate = $deliveryDate->modify('+7 days');
                $deadlineAt = $deadlineAt->modify('+7 days');
            }

            $sale = new Sale($shop, $this->saleCodeGenerator->generate(), $deliveryDate, $opensAt, $deadlineAt, $now->modify('-2 days'), 'Livraison du '.$this->formatter->dateLong($deliveryDate));
            foreach ($points as $position => $point) {
                new SaleDelivery($sale, $point, $deliveryDate->setTime(9, 0), $deliveryDate->setTime(12, 0), $position);
            }
            foreach ($products as $product) {
                new SaleOffer($sale, $product);
            }
            if ('open' === $data['sale']) {
                $sale->open();
            }
            $manager->persist($sale);
        }
    }

    /**
     * @param array<string, Product> $products
     * @param list<DeliveryPoint>    $deliveryPoints
     */
    private function createSale(
        ObjectManager $manager,
        SaleSchedule $schedule,
        \DateTimeImmutable $deliveryDate,
        \DateTimeImmutable $opensAt,
        array $products,
        array $deliveryPoints,
    ): Sale {
        $sale = new Sale(
            $schedule->getShop(),
            $this->saleCodeGenerator->generate(),
            $deliveryDate,
            $opensAt,
            $schedule->deadlineAtFor($deliveryDate),
            $opensAt->modify('-1 day'),
            'Livraison du '.$this->formatter->dateLong($deliveryDate),
        );
        $sale->setSchedule($schedule);

        foreach ($deliveryPoints as $position => $deliveryPoint) {
            new SaleDelivery($sale, $deliveryPoint, $schedule->deliveryStartsAtFor($deliveryDate), $schedule->deliveryEndsAtFor($deliveryDate), $position);
        }

        foreach ($products as $product) {
            if ($product->isAvailableOn($deliveryDate)) {
                new SaleOffer($sale, $product);
            }
        }

        // Deliveries and offers are persisted by cascade.
        $manager->persist($sale);

        return $sale;
    }

    /**
     * @param array<string, int> $quantities product name fragment => quantity, in whole pieces
     */
    private function createOrder(
        ObjectManager $manager,
        Sale $sale,
        Customer $customer,
        int $deliveryIndex,
        array $quantities,
        \DateTimeImmutable $placedAt,
        ?string $note = null,
        ?string $deliveryAddress = null,
    ): Order {
        $delivery = $sale->getDeliveries()->get($deliveryIndex);
        \assert($delivery instanceof SaleDelivery);

        $order = new Order($sale, $customer, $delivery, $this->orderReferenceGenerator->generate(), $placedAt);
        $order->setNote($note)->setDeliveryAddress($deliveryAddress);

        $names = [
            'campagne' => 'Pain de campagne',
            'complet' => 'Pain complet',
            'baguette' => 'Baguette tradition',
            'graines' => 'Pain aux graines',
            'brioche' => 'Brioche au beurre',
            'noix' => 'Pain aux noix',
        ];

        foreach ($quantities as $key => $quantity) {
            foreach ($sale->getOffers() as $offer) {
                if ($offer->getProduct()->getName() === $names[$key]) {
                    // Demo data is loaded by a single process: no lock needed here.
                    $offer->reserve(Quantity::units($quantity));
                    new OrderLine($order, $offer, Quantity::units($quantity));
                }
            }
        }

        $order->recordEvent(OrderEventType::Placed, Actor::Customer, ['total_cents' => $order->getTotalCents()], $placedAt);

        // Lines and events are persisted by cascade.
        $manager->persist($order);

        return $order;
    }
}
