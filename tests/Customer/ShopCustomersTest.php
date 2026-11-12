<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Accounting\Csv\CsvWriter;
use App\Customer\Entity\Customer;
use App\Customer\Model\ShopCustomer;
use App\Customer\Service\ShopCustomers;
use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderStatus;
use App\Sale\Entity\Sale;
use App\Sale\Enum\SaleStatus;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use App\Shop\Entity\User;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\ShopFollowerFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Mes clients": the customers of the shop, their figures here, the CSV export, and nothing of
 * another shop (SPEC §2).
 */
final class ShopCustomersTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private User $producer;
    private Shop $shop;
    private Customer $lea;
    private Customer $paul;
    private Customer $follower;
    private Customer $gisele;
    private Customer $stranger;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->producer = UserFactory::createOne();
        $this->shop = $this->producer->getShop() ?? throw new \LogicException();
        $other = UserFactory::createOne()->getShop() ?? throw new \LogicException();

        $this->lea = CustomerFactory::createOne(['email' => 'lea@example.test', 'firstName' => 'Léa', 'lastName' => 'Martin', 'phone' => '06 11 22 33 44']);
        $this->paul = CustomerFactory::createOne(['email' => 'paul@example.test', 'firstName' => 'Paul', 'lastName' => 'Arnaud']);
        $this->follower = CustomerFactory::createOne(['email' => 'zoe@example.test', 'firstName' => 'Zoé', 'lastName' => 'Vial']);
        $this->gisele = CustomerFactory::new()->withoutEmail($this->shop)->create(['firstName' => 'Gisèle', 'lastName' => 'Roux', 'phone' => '04 75 41 22 33']);
        $this->stranger = CustomerFactory::createOne(['email' => 'stranger@example.test', 'firstName' => 'Inconnu', 'lastName' => 'Ailleurs']);

        $september = $this->sale($this->shop, '-60 days');
        $october = $this->sale($this->shop, '-30 days');
        $this->order($september, $this->lea, OrderStatus::Delivered, 1250);
        $this->order($october, $this->lea, OrderStatus::Delivered, 830);
        $this->order($this->sale($this->shop, '-10 days'), $this->lea, OrderStatus::Cancelled, 9900);
        $this->order($october, $this->paul, OrderStatus::NotDelivered, 520);
        $this->order($september, $this->gisele, OrderStatus::Delivered, 400);
        ShopFollowerFactory::createOne(['customer' => $this->follower, 'shop' => $this->shop, 'emailConsent' => true, 'createdAt' => new \DateTimeImmutable('2026-05-04 10:00')]);
        ShopFollowerFactory::createOne(['customer' => $this->paul, 'shop' => $this->shop, 'emailConsent' => false]);

        // Léa also buys elsewhere, and a stranger is only another shop's customer.
        $elsewhere = $this->sale($other, '-5 days');
        $this->order($elsewhere, $this->lea, OrderStatus::Delivered, 77700);
        $this->order($elsewhere, $this->stranger, OrderStatus::Delivered, 1000);
        ShopFollowerFactory::createOne(['customer' => $this->stranger, 'shop' => $other]);
    }

    public function testTheListShowsEveryCustomerOfTheShopAndNobodyElse(): void
    {
        $this->client->loginUser($this->producer, 'producer');

        $crawler = $this->client->request('GET', '/pro/clients');

        self::assertResponseIsSuccessful();
        self::assertSame(['Paul Arnaud', 'Léa Martin', 'Gisèle Roux', 'Zoé Vial'], $this->names($crawler));
        self::assertSelectorTextContains('[data-customer-count]', '4 clients, dont 2 abonnés à ta boutique');

        $lea = $crawler->filter(\sprintf('[data-customer="%d"]', $this->lea->getId()));
        self::assertStringContainsString('lea@example.test', $lea->text());
        self::assertMatchesRegularExpression('/Commandes\s*2/', $lea->text(), 'The cancelled order does not count.');
        self::assertStringContainsString('20,80', $lea->text(), 'Only the orders picked up here: 12,50 + 8,30.');
        self::assertStringNotContainsString('777', $crawler->filter('main')->text());

        $paul = $crawler->filter(\sprintf('[data-customer="%d"]', $this->paul->getId()))->text();
        self::assertStringContainsString('1 absence', $paul);
        self::assertStringContainsString('Abonné, sans annonces', $paul);
        $zoe = $crawler->filter(\sprintf('[data-customer="%d"]', $this->follower->getId()))->text();
        self::assertStringContainsString('Abonné, reçoit les annonces', $zoe);
        self::assertMatchesRegularExpression('#Abonné depuis le\s*04/05/2026#', $zoe);
        self::assertMatchesRegularExpression('/Commandes\s*0/', $zoe);
        self::assertStringContainsString('Sans e-mail', $crawler->filter(\sprintf('[data-customer="%d"]', $this->gisele->getId()))->text());
        self::assertSelectorExists('a[href="/pro/clients"][aria-current="page"]');
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideSearches(): iterable
    {
        yield 'first name, any case' => ['léa', ['Léa Martin']];
        yield 'accented capitals and spaces' => ['  LÉA  ', ['Léa Martin']];
        yield 'exactly three digits of a phone' => ['22 3', ['Léa Martin', 'Gisèle Roux']];
        yield 'digits in the middle of a phone' => ['41 22', ['Gisèle Roux']];
        yield 'last name then first name' => ['martin léa', ['Léa Martin']];
        yield 'part of an e-mail' => ['zoe@', ['Zoé Vial']];
        yield 'digits of a phone, any spacing' => ['0475 41', ['Gisèle Roux']];
        yield 'too few digits for a phone' => ['06', []];
        yield 'wildcards are literal' => ['%', []];
        yield 'another shop\'s customer' => ['Inconnu', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideSearches')]
    public function testSearch(string $query, array $expected): void
    {
        $this->client->loginUser($this->producer, 'producer');

        $crawler = $this->client->request('GET', '/pro/clients', ['q' => $query]);

        self::assertResponseIsSuccessful();
        self::assertSame($expected, $this->names($crawler));
        if ([] === $expected) {
            self::assertSelectorTextContains('main', 'Personne ne correspond');
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideSorts(): iterable
    {
        yield 'name' => ['nom', ['Paul Arnaud', 'Léa Martin', 'Gisèle Roux', 'Zoé Vial']];
        yield 'latest order, then name' => ['derniere-commande', ['Paul Arnaud', 'Léa Martin', 'Gisèle Roux', 'Zoé Vial']];
        yield 'number of orders' => ['commandes', ['Léa Martin', 'Paul Arnaud', 'Gisèle Roux', 'Zoé Vial']];
        yield 'picked up' => ['total', ['Léa Martin', 'Gisèle Roux', 'Paul Arnaud', 'Zoé Vial']];
        yield 'absences' => ['absences', ['Paul Arnaud', 'Léa Martin', 'Gisèle Roux', 'Zoé Vial']];
        yield 'latest follower' => ['abonnement', ['Paul Arnaud', 'Zoé Vial', 'Léa Martin', 'Gisèle Roux']];
        yield 'unknown sort' => ['n-importe', ['Paul Arnaud', 'Léa Martin', 'Gisèle Roux', 'Zoé Vial']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideSorts')]
    public function testSort(string $sort, array $expected): void
    {
        $this->client->loginUser($this->producer, 'producer');

        $crawler = $this->client->request('GET', '/pro/clients', ['tri' => $sort]);

        self::assertSame($expected, $this->names($crawler));
    }

    public function testTheCustomerPageShowsTheirOrdersAtThisShopOnly(): void
    {
        $this->client->loginUser($this->producer, 'producer');

        $crawler = $this->client->request('GET', '/pro/clients/'.$this->lea->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Léa Martin');
        self::assertSelectorTextContains('[data-customer-contact]', '06 11 22 33 44');
        self::assertSelectorTextContains('[data-customer-follow]', 'Ne suit pas ta boutique');
        self::assertCount(3, $crawler->filter('[data-customer-order]'), 'Her cancelled order is listed, the other shop\'s is not.');
        self::assertSelectorTextContains('[data-customer-figures]', '20,80');
        self::assertStringNotContainsString('777', $crawler->filter('main')->text());
        self::assertSelectorNotExists('[data-offline-edit]');
    }

    public function testACustomerWithoutEmailKeepsTheirActions(): void
    {
        $this->client->loginUser($this->producer, 'producer');

        $crawler = $this->client->request('GET', '/pro/clients/'.$this->gisele->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-customer-contact]', 'Aucun : ce client ne reçoit aucun e-mail');
        self::assertSame('/pro/clients-sans-email/'.$this->gisele->getId(), $crawler->filter('[data-offline-edit]')->attr('href'));
        self::assertCount(1, $crawler->filter(\sprintf('form[action="/pro/clients-sans-email/%d/effacer"]', $this->gisele->getId())));
    }

    public function testAFollowerWithoutOrder(): void
    {
        $this->client->loginUser($this->producer, 'producer');

        $this->client->request('GET', '/pro/clients/'.$this->follower->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-customer-follow]', 'Suit ta boutique depuis le 04/05/2026');
        self::assertSelectorTextContains('[data-customer-follow]', 'reçoit l\'annonce de tes ventes par e-mail');
        self::assertSelectorTextContains('[data-customer-orders]', 'Aucune commande pour l\'instant.');
    }

    public function testAnotherShopsCustomerAnswers404(): void
    {
        $this->client->loginUser($this->producer, 'producer');

        $this->client->request('GET', '/pro/clients/'.$this->stranger->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/pro/clients/999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnotherProducerSeesNoneOfTheseCustomers(): void
    {
        $intruder = UserFactory::createOne();
        $this->client->loginUser($intruder, 'producer');

        foreach ([$this->lea, $this->paul, $this->gisele, $this->follower] as $customer) {
            $this->client->request('GET', '/pro/clients/'.$customer->getId());
            self::assertResponseStatusCodeSame(404);
        }

        $crawler = $this->client->request('GET', '/pro/clients');
        self::assertSame([], $this->names($crawler));
        self::assertSelectorTextContains('main', 'Pas encore de client');

        $this->client->request('GET', '/pro/clients.csv');
        self::assertSame(1, substr_count((string) $this->client->getResponse()->getContent(), "\r\n"), 'Only the header line.');
    }

    public function testTheCsvExportListsTheCustomersOfTheShop(): void
    {
        $this->client->loginUser($this->producer, 'producer');

        $this->client->request('GET', '/pro/clients.csv');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/attachment; filename=clients-.+-\d{4}-\d{2}-\d{2}\.csv/', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $lines = explode("\r\n", trim(substr((string) $response->getContent(), \strlen(CsvWriter::BOM))));
        self::assertSame('Nom;Prénom;E-mail;Téléphone;"Sans e-mail";Abonné;"Annonces par e-mail";"Abonné depuis";Commandes;"Dernière commande";"Total retiré (€)";Absences', $lines[0]);
        self::assertCount(5, $lines);
        self::assertStringStartsWith('Martin;Léa;lea@example.test;"06 11 22 33 44";non;non;non;;2;', $lines[2]);
        self::assertStringEndsWith(';20,80;0', $lines[2]);
        self::assertStringStartsWith('Roux;Gisèle;;"04 75 41 22 33";oui;non;non;;1;', $lines[3]);
        self::assertStringStartsWith('Vial;Zoé;zoe@example.test;', $lines[4]);
        self::assertStringContainsString(';oui;oui;04/05/2026;0;;0,00;0', $lines[4]);
        self::assertStringNotContainsString('stranger', (string) $response->getContent());
    }

    public function testTheCsvNeutralisesFormulas(): void
    {
        $this->lea->setFirstName('=HYPERLINK("http://evil.test")')->setLastName('+Martin');
        $order = OrderFactory::createOne(['sale' => $this->sale($this->shop, '-2 days'), 'customer' => $this->lea, 'status' => OrderStatus::Delivered]);
        $order->setCustomerIdentity('@Léa', '-Martin', '=1+1');
        self::getContainer()->get('doctrine')->getManager()->flush();
        $this->client->loginUser($this->producer, 'producer');

        $this->client->request('GET', '/pro/clients.csv');

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("'-Martin;'@Léa;lea@example.test;'=1+1;", $content);
        self::assertStringNotContainsString(';=', $content);
    }

    public function testAnErasedAccountShowsNoPersonalData(): void
    {
        $this->paul->setEmail(Customer::anonymizedEmailFor((int) $this->paul->getId()));
        self::getContainer()->get('doctrine')->getManager()->flush();
        $this->client->loginUser($this->producer, 'producer');

        $crawler = $this->client->request('GET', '/pro/clients');

        self::assertContains('Client effacé', $this->names($crawler));
        self::assertStringNotContainsString('compte-supprime', $crawler->filter('main')->text());
        self::assertStringNotContainsString('Arnaud', $crawler->filter('main')->text());
    }

    public function testTheServiceTrimsTheSearchAndTheSearchFieldKeepsIt(): void
    {
        $names = array_map(static fn (ShopCustomer $customer): string => $customer->displayName(), self::getContainer()->get(ShopCustomers::class)->list($this->shop, "  vial\t"));
        self::assertSame(['Zoé Vial'], $names);

        $this->client->loginUser($this->producer, 'producer');
        $crawler = $this->client->request('GET', '/pro/clients', ['q' => '  vial ']);
        self::assertSame('vial', $crawler->filter('#customer-search')->attr('value'));
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string, ?string, string}>
     */
    public static function provideDisplayNames(): iterable
    {
        yield 'first and last name' => ['Léa', 'Martin', 'lea@example.test', null, 'Léa Martin'];
        yield 'first name only' => ['Léa', null, 'lea@example.test', null, 'Léa'];
        yield 'last name only' => [null, 'Martin', null, null, 'Martin'];
        yield 'blank names: the e-mail' => ['  ', null, 'lea@example.test', '06 00', 'lea@example.test'];
        yield 'no name nor e-mail: the phone' => [null, null, null, '06 00', '06 00'];
        yield 'nothing at all' => [null, null, null, null, 'Client sans nom'];
        yield 'an erased account' => ['Léa', 'Martin', 'client-4@compte-supprime.invalid', '06 00', 'Client effacé'];
    }

    #[DataProvider('provideDisplayNames')]
    public function testDisplayName(?string $firstName, ?string $lastName, ?string $email, ?string $phone, string $expected): void
    {
        $customer = ShopCustomer::fromRow([
            'id' => 4, 'email' => $email, 'without_email' => false, 'first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone,
            'follower' => false, 'email_consent' => false, 'followed_at' => null, 'order_count' => 0, 'last_order_date' => null, 'delivered_cents' => 0, 'absences' => 0,
        ]);

        self::assertSame($expected, $customer->displayName());
        self::assertSame(4, $customer->id);
    }

    public function testTheCustomerPagesNeedAProducer(): void
    {
        foreach (['/pro/clients', '/pro/clients.csv', '/pro/clients/'.$this->lea->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/pro/connexion');
        }
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('[data-customer] p span.truncate')->each(static fn (Crawler $node): string => trim($node->text()));
    }

    private function sale(Shop $shop, string $delivery): Sale
    {
        $date = new \DateTimeImmutable($delivery);

        return SaleFactory::createOne([
            'shop' => $shop,
            'status' => SaleStatus::Done,
            'deliveryDate' => $date,
            'opensAt' => $date->modify('-7 days 08:00'),
            'deadlineAt' => $date->modify('-3 days 20:00'),
        ]);
    }

    private function order(Sale $sale, Customer $customer, OrderStatus $status, int $priceCents): Order
    {
        $order = OrderFactory::createOne(['sale' => $sale, 'customer' => $customer, 'status' => $status]);
        OrderLineFactory::createOne(['order' => $order, 'saleOffer' => SaleOfferFactory::new(['sale' => $sale, 'priceCents' => $priceCents]), 'quantity' => Quantity::UNIT]);

        return $order;
    }
}
