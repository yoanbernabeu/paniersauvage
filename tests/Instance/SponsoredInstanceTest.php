<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use App\Billing\Stripe\BillingGateway;
use App\Billing\Stripe\FakeBillingGateway;
use App\Shop\Entity\User;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopSubscriptionFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * INSTANCE_PRICING=sponsored: the carrier pays the subscription of every producer of the instance.
 * No trial, no plan, no Stripe; every shop may open new sales and appear in the directory.
 */
final class SponsoredInstanceTest extends WebTestCase
{
    use Factories;
    use InstanceTrait;

    public function testTheHomePageSaysTheSubscriptionIsOffered(): void
    {
        $this->useTerritorialInstance(['INSTANCE_PRICING' => 'sponsored']);
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Abonnement offert par Valence Romans Agglo, zéro commission', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertStringNotContainsString('10 €', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame('Abonnement offert par Valence Romans Agglo, sans carte bancaire.', $crawler->filter('[data-hero-pricing]')->text());
        self::assertSelectorTextContains('[data-instance-welcome] [data-instance-sponsored]', 'Abonnement offert par Valence Romans Agglo : rien à payer pour toi');
        self::assertSame(
            ['Offert.', 'Zéro commission.', 'Tes clients restent les tiens.', 'Logiciel libre.'],
            $crawler->filter('[data-promises] li > p:first-child')->each(static fn ($node): string => $node->text()),
        );
        self::assertSelectorTextContains('[data-promises]', 'Valence Romans Agglo prend en charge ton abonnement');
        self::assertSelectorNotExists('[data-comparison]');
        self::assertSelectorNotExists('[data-plan="monthly"]');
        self::assertSelectorTextContains('[data-pricing="sponsored"] [data-plan="sponsored"]', 'Abonnement offert par Valence Romans Agglo');
        self::assertSelectorTextContains('[data-plan="sponsored"]', '0 €');
        self::assertSelectorExists('[data-pricing="sponsored"] a[href="/pro/inscription"]');
        self::assertSelectorTextContains('[data-faq]', 'Il faut une carte bancaire ? Non. Abonnement offert par Valence Romans Agglo : aucune carte, aucun essai qui se termine.');
        self::assertCount(5, $crawler->filter('[data-faq] details'));
        self::assertCount(1, $crawler->filter('[data-variant="default"][data-slot="button"]'), 'One wheat button per screen.');

        $client->request('GET', '/pro/inscription');
        self::assertSelectorTextContains('main', 'Abonnement offert par Valence Romans Agglo, sans carte bancaire. Deux minutes suffisent.');

        $crawler = $client->request('GET', '/cgu');
        self::assertSame('Abonnement offert par Valence Romans Agglo : les producteurs n\'ont rien à payer, et les clauses sur le prix, l\'essai et le paiement de l\'abonnement ne s\'appliquent pas.', trim((string) preg_replace('/\s+/', ' ', $crawler->filter('[data-instance-sponsored]')->text())));
        $crawler = $client->request('GET', '/mentions-legales');
        self::assertSame('Ce service est porté par Valence Romans Agglo, qui prend en charge l\'abonnement des producteurs de son territoire.', $crawler->filter('[data-legal-carrier]')->text());
    }

    public function testSponsorshipWithoutRenamingIsStillExplained(): void
    {
        $this->useInstance(['INSTANCE_PRICING' => 'sponsored', 'INSTANCE_CARRIER_NAME' => 'Département de la Drôme']);
        $client = self::createClient();
        $crawler = $client->request('GET', '/confidentialite');

        self::assertCount(1, $crawler->filter('[data-instance-legal]'));
        self::assertStringNotContainsString('est une instance du logiciel libre', $crawler->filter('[data-instance-legal]')->text());
        self::assertSelectorTextContains('[data-instance-sponsored]', 'Abonnement offert par Département de la Drôme');
        self::assertSelectorNotExists('[data-powered-by]');
    }

    public function testSigningUpNeedsNoTrial(): void
    {
        $this->useTerritorialInstance(['INSTANCE_PRICING' => 'sponsored']);
        $client = self::createClient();
        self::getContainer()->get('limiter.registration')->create('127.0.0.1')->reset();
        $client->request('GET', '/pro/inscription');
        $client->submitForm('Créer ma boutique', [
            'registration[firstName]' => 'Camille',
            'registration[lastName]' => 'Durand',
            'registration[shopName]' => 'Le Fournil du Pont',
            'registration[email]' => 'camille@example.test',
            'registration[plainPassword]' => 'pain-au-levain',
            'registration[acceptTerms]' => '1',
        ]);
        self::assertResponseRedirects('/pro/bienvenue');
        $client->followRedirect();

        self::assertSelectorTextContains('[data-flash="success"]', 'Bienvenue Camille ! Ta boutique est créée, ton abonnement est offert par Valence Romans Agglo.');
    }

    public function testTheSubscriptionPageNeverLeadsToStripe(): void
    {
        $this->useTerritorialInstance(['INSTANCE_PRICING' => 'sponsored', 'INSTANCE_TERRITORY_NAME' => 'l\'agglo']);
        $client = self::createClient();
        $client->loginUser($this->producer(), 'producer');

        $crawler = $client->request('GET', '/pro/abonnement');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-billing-status="sponsored"]');
        self::assertSelectorTextContains('[data-billing-summary]', 'Ton abonnement à Panier Toqué du local est offert par Valence Romans Agglo, pour les producteurs de l\'agglo.');
        self::assertSelectorTextContains('main', 'Abonnement offert par Valence Romans Agglo.');
        self::assertCount(0, $crawler->filter('form[action^="/pro/abonnement"]'));
        self::assertSelectorExists('a[href="https://www.valenceromansagglo.fr"]');
        self::assertSelectorNotExists('[data-billing-banner]', 'An expired trial means nothing here.');

        foreach (['/pro/abonnement/souscrire', '/pro/abonnement/gerer'] as $action) {
            $client->request('POST', $action, ['plan' => 'monthly']);
            self::assertResponseRedirects('/pro/abonnement');
            $client->followRedirect();
            self::assertSelectorTextContains('[data-flash="info"]', 'Abonnement offert par Valence Romans Agglo : tu n\'as rien à payer.');
        }

        $gateway = self::getContainer()->get(BillingGateway::class);
        self::assertInstanceOf(FakeBillingGateway::class, $gateway);
        self::assertSame([], $gateway->checkoutRequests);
        self::assertSame([], $gateway->portalRequests);

        $client->request('GET', '/pro');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-billing-banner]');
    }

    public function testTheStandardModeStillAsksToSubscribe(): void
    {
        $client = self::createClient();
        $client->loginUser($this->producer(), 'producer');

        $client->request('GET', '/pro');
        self::assertSelectorExists('[data-billing-banner="destructive"]');
        $client->request('GET', '/pro/abonnement');
        self::assertSelectorExists('[data-billing-plan="monthly"]');
    }

    public function testEveryShopOfTheInstanceMayAppearInTheDirectory(): void
    {
        $this->useTerritorialInstance(['INSTANCE_PRICING' => 'sponsored']);
        $client = self::createClient();
        foreach (['Résiliée' => 'canceled', 'Essai fini' => 'trialExpired', 'Sans abonnement' => null] as $name => $state) {
            $shop = ShopFactory::new()->listed()->verified()->create(['name' => $name]);
            if (null !== $state) {
                ShopSubscriptionFactory::new()->{$state}()->create(['shop' => $shop]);
            }
            DeliveryPointFactory::new()->located(44.9330, 4.8920)->create(['shop' => $shop, 'address' => "Place des Clercs\n26000 Valence"]);
        }

        $crawler = $client->request('GET', '/trouver-un-producteur?lat=44.9334&lng=4.8924&rayon=10');

        self::assertSame(['Essai fini', 'Résiliée', 'Sans abonnement'], $crawler->filter('[data-result] h3')->each(static fn ($node): string => $node->text()));
    }

    /**
     * A producer whose free trial ended (and who never subscribed).
     */
    private function producer(): User
    {
        $producer = UserFactory::createOne();
        $shop = $producer->getShop();
        self::assertNotNull($shop);
        ShopSubscriptionFactory::new()->trialExpired()->create(['shop' => $shop]);

        return $producer;
    }
}
