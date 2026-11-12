<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopFollowerFactory;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Zenstruck\Foundry\Test\Factories;

final class AccountTest extends WebTestCase
{
    use Factories;

    public function testAccountRequiresToBeSignedIn(): void
    {
        $client = self::createClient();

        $client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/connexion');

        $client->request('GET', '/mon-compte/supprimer');
        self::assertResponseRedirects('/connexion');
    }

    public function testTheNumberOfQueriesDoesNotGrowWithTheOrders(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne();
        $client->loginUser($customer, 'main');
        OrderFactory::createOne(['customer' => $customer]);
        OrderFactory::new()->delivered()->create(['customer' => $customer, 'sale' => SaleFactory::new()->done()]);

        // The first request shares the test's entity manager: measure fresh requests only.
        $client->request('GET', '/mon-compte');
        $withTwo = $this->countQueries($client, '/mon-compte');

        foreach (range(1, 4) as $ignored) {
            OrderFactory::createOne(['customer' => $customer]);
            OrderFactory::new()->delivered()->create(['customer' => $customer, 'sale' => SaleFactory::new()->done()]);
        }

        self::assertSame($withTwo, $this->countQueries($client, '/mon-compte'));
    }

    public function testEmptyAccountIsWelcoming(): void
    {
        $client = self::createClient();
        $client->loginUser(CustomerFactory::createOne(['firstName' => 'Camille']), 'main');

        $client->request('GET', '/mon-compte');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bonjour Camille');
        self::assertSelectorTextContains('[data-section="upcoming-orders"]', 'Rien de prévu pour l\'instant');
        self::assertSelectorNotExists('[data-section="past-orders"]');
        self::assertSelectorTextContains('[data-section="followed-shops"]', 'Tu ne suis encore personne');
    }

    public function testOrdersOfEveryProducerAreSplitBetweenUpcomingAndPast(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne();
        $bakery = ShopFactory::createOne(['name' => 'Le Fournil du Pont']);
        $farm = ShopFactory::createOne(['name' => 'La Ferme des Saules']);

        OrderFactory::createOne(['customer' => $customer, 'sale' => SaleFactory::createOne(['shop' => $bakery]), 'reference' => 'PS-NEXT-0001']);
        OrderFactory::createOne(['customer' => $customer, 'sale' => SaleFactory::new(['shop' => $farm])->done()->create(), 'reference' => 'PS-DONE-0002']);
        OrderFactory::new()->cancelled()->create(['customer' => $customer, 'sale' => SaleFactory::createOne(['shop' => $bakery]), 'reference' => 'PS-GONE-0003']);

        $client->loginUser($customer, 'main');
        $crawler = $client->request('GET', '/mon-compte');

        self::assertResponseIsSuccessful();

        $upcoming = $crawler->filter('[data-section="upcoming-orders"] [data-order]');
        self::assertSame(['PS-NEXT-0001'], $upcoming->extract(['data-order']));
        self::assertStringContainsString('Le Fournil du Pont', $upcoming->text());
        // Route of another feature set: the fallback path is used until it exists.
        self::assertSame('/mes-commandes/PS-NEXT-0001', $upcoming->attr('href'));

        $past = $crawler->filter('[data-section="past-orders"] [data-order]');
        self::assertEqualsCanonicalizing(['PS-DONE-0002', 'PS-GONE-0003'], $past->extract(['data-order']));
        self::assertSelectorTextContains('[data-section="past-orders"]', 'La Ferme des Saules');
        self::assertSelectorTextContains('[data-section="past-orders"]', 'Annulée');
    }

    public function testACustomerNeverSeesTheOrdersOfSomeoneElse(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne();
        $neighbour = CustomerFactory::createOne(['email' => 'voisin@example.test']);
        $sale = SaleFactory::createOne();

        OrderFactory::createOne(['customer' => $customer, 'sale' => $sale, 'reference' => 'PS-MINE-0001']);
        OrderFactory::createOne(['customer' => $neighbour, 'sale' => $sale, 'reference' => 'PS-THEIRS-01']);
        OrderFactory::createOne(['customer' => $neighbour, 'sale' => SaleFactory::new()->done()->create(), 'reference' => 'PS-THEIRS-02']);
        ShopFollowerFactory::createOne(['customer' => $neighbour, 'shop' => ShopFactory::createOne(['name' => 'Boutique du voisin'])]);

        $client->loginUser($customer, 'main');
        $crawler = $client->request('GET', '/mon-compte');

        self::assertResponseIsSuccessful();
        self::assertSame(['PS-MINE-0001'], $crawler->filter('[data-order]')->extract(['data-order']));

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('PS-THEIRS', $html);
        self::assertStringNotContainsString('voisin@example.test', $html);
        self::assertStringNotContainsString('Boutique du voisin', $html);
    }

    public function testAccountNeverMentionsARecordedPayment(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne();
        OrderFactory::new()->delivered()->create(['customer' => $customer, 'sale' => SaleFactory::new()->done()->create()]);

        $client->loginUser($customer, 'main');
        $client->request('GET', '/mon-compte');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsStringIgnoringCase('payé', (string) $client->getResponse()->getContent());
    }

    public function testFollowedShopsAreListedWithTheirEmailSetting(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne();
        ShopFollowerFactory::createOne(['customer' => $customer, 'shop' => ShopFactory::createOne(['name' => 'Le Fournil du Pont', 'slug' => 'fournil-du-pont']), 'emailConsent' => true]);
        ShopFollowerFactory::createOne(['customer' => $customer, 'shop' => ShopFactory::createOne(['name' => 'La Ferme des Saules', 'slug' => 'ferme-des-saules']), 'emailConsent' => false]);

        $client->loginUser($customer, 'main');
        $crawler = $client->request('GET', '/mon-compte');

        self::assertResponseIsSuccessful();
        self::assertSame(['ferme-des-saules', 'fournil-du-pont'], $crawler->filter('[data-followed-shop]')->extract(['data-followed-shop']));
        self::assertCount(1, $crawler->filter('[data-followed-shop="fournil-du-pont"] input[name="email_consent"][checked]'));
        self::assertCount(0, $crawler->filter('[data-followed-shop="ferme-des-saules"] input[name="email_consent"][checked]'));
        // Saved on change (Stimulus), with a submit button kept for visitors without JavaScript.
        self::assertCount(1, $crawler->filter('[data-followed-shop="fournil-du-pont"] form[data-controller="autosubmit"] button[type="submit"][data-autosubmit-target="fallback"]'));
        self::assertCount(1, $crawler->filter('[data-followed-shop="fournil-du-pont"] a[href="/fournil-du-pont"]'));
    }

    public function testCustomerUpdatesTheirProfile(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne(['email' => 'camille@example.test', 'firstName' => null, 'lastName' => null, 'phone' => null]);
        $client->loginUser($customer, 'main');

        $client->request('GET', '/mon-compte');
        $client->submitForm('Enregistrer mes infos', [
            'profile[firstName]' => ' Camille ',
            'profile[lastName]' => 'Martin',
            'profile[phone]' => '06 11 22 33 44',
        ]);

        self::assertResponseRedirects('/mon-compte');
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'C\'est enregistré');
        self::assertSelectorTextContains('h1', 'Bonjour Camille');

        $saved = CustomerFactory::repository()->findOneBy(['email' => 'camille@example.test']);
        self::assertNotNull($saved);
        self::assertSame('Camille', $saved->getFirstName());
        self::assertSame('Martin', $saved->getLastName());
        self::assertSame('06 11 22 33 44', $saved->getPhone());
    }

    public function testTooLongProfileValueIsRejected(): void
    {
        $client = self::createClient();
        $client->loginUser(CustomerFactory::createOne(['email' => 'camille@example.test', 'firstName' => 'Camille']), 'main');

        $client->request('GET', '/mon-compte');
        $client->submitForm('Enregistrer mes infos', ['profile[firstName]' => str_repeat('a', 81)]);

        self::assertResponseStatusCodeSame(422);

        $saved = CustomerFactory::repository()->findOneBy(['email' => 'camille@example.test']);
        self::assertNotNull($saved);
        self::assertSame('Camille', $saved->getFirstName());
    }

    private function countQueries(KernelBrowser $client, string $url): int
    {
        $client->enableProfiler();
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }
}
