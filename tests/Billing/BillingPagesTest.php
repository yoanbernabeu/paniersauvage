<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Stripe\BillingGateway;
use App\Billing\Stripe\FakeBillingGateway;
use App\Shop\Entity\User;
use App\Tests\Factory\ShopSubscriptionFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Subscription pages with the test double of the billing gateway: no call to Stripe.
 */
final class BillingPagesTest extends WebTestCase
{
    use Factories;

    private const string CONSEQUENCE = 'Sans abonnement actif, tes ventes en cours vont à leur terme, mais aucune nouvelle vente ne s\'ouvre ni ne se crée';

    public function testTrialShowsTheDaysLeftAndBothPlans(): void
    {
        $client = self::createClient();
        $producer = $this->producer(['trialEndsAt' => new \DateTimeImmutable('+12 days +1 hour')]);

        $client->loginUser($producer, 'producer');
        $crawler = $client->request('GET', '/pro/abonnement');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Abonnement');
        self::assertSelectorExists('[data-billing-status="trialing"]');
        self::assertSelectorTextContains('[data-billing-summary]', 'il te reste 13 jours');
        self::assertSelectorTextContains('[data-billing-consequence]', self::CONSEQUENCE);

        self::assertCount(2, $crawler->filter('form[action="/pro/abonnement/souscrire"]'));
        self::assertSelectorTextContains('[data-billing-plan="monthly"]', '10 €');
        self::assertSelectorTextContains('[data-billing-plan="monthly"]', 'par mois');
        self::assertSelectorTextContains('[data-billing-plan="yearly"]', 'Annuel');
        self::assertSelectorNotExists('form[action="/pro/abonnement/gerer"]');
        self::assertSelectorNotExists('[data-billing-banner]');
    }

    public function testExpiredTrial(): void
    {
        $client = self::createClient();
        $client->loginUser($this->producer(['trialEndsAt' => new \DateTimeImmutable('-2 days')]), 'producer');
        $client->request('GET', '/pro/abonnement');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-billing-summary]', 'Ton essai gratuit est terminé depuis le');
        self::assertSelectorTextContains('[data-billing-status]', 'Essai terminé');
        self::assertSelectorExists('[data-billing-plan="monthly"]');
        self::assertSelectorExists('[data-billing-banner="destructive"]');
    }

    public function testActiveSubscriptionShowsTheNextDueDateAndThePortal(): void
    {
        $client = self::createClient();
        $producer = $this->producer([
            'status' => SubscriptionStatus::Active,
            'plan' => SubscriptionPlan::Yearly,
            'trialEndsAt' => null,
            'currentPeriodEnd' => new \DateTimeImmutable('2027-03-15 10:00'),
            'stripeCustomerId' => 'cus_A',
            'stripeSubscriptionId' => 'sub_A',
        ]);

        $client->loginUser($producer, 'producer');
        $client->request('GET', '/pro/abonnement');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-billing-status="active"]');
        self::assertSelectorTextContains('[data-billing-summary]', 'Ton abonnement annuel est actif.');
        self::assertSelectorTextContains('[data-billing-summary]', 'Prochaine échéance le lundi 15 mars 2027');
        self::assertSelectorTextContains('[data-billing-consequence]', self::CONSEQUENCE);
        self::assertSelectorTextContains('form[action="/pro/abonnement/gerer"] button', 'Gérer mon abonnement');
        self::assertSelectorNotExists('form[action="/pro/abonnement/souscrire"]');
        self::assertSelectorNotExists('[data-billing-banner]');
    }

    public function testPastDueSubscription(): void
    {
        $client = self::createClient();
        $producer = $this->producer([
            'status' => SubscriptionStatus::PastDue,
            'trialEndsAt' => null,
            'currentPeriodEnd' => new \DateTimeImmutable('-2 days'),
            'stripeCustomerId' => 'cus_A',
            'stripeSubscriptionId' => 'sub_A',
        ]);

        $client->loginUser($producer, 'producer');
        $client->request('GET', '/pro/abonnement');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-billing-status]', 'En retard');
        self::assertSelectorTextContains('[data-billing-summary]', 'Le dernier prélèvement de ton abonnement n\'a pas abouti.');
        self::assertSelectorTextContains('[data-billing-summary]', 'Mets à jour ton moyen de paiement avant le');
        self::assertSelectorTextContains('form[action="/pro/abonnement/gerer"] button', 'Mettre à jour mon moyen de paiement');
        self::assertSelectorNotExists('form[action="/pro/abonnement/souscrire"]');
        // Still inside the grace period: the producer is warned on every page before sales stop opening.
        self::assertSelectorTextContains('[data-billing-banner="warning"]', 'Ton dernier paiement n\'est pas passé.');
    }

    public function testCanceledSubscriptionCanSubscribeAgain(): void
    {
        $client = self::createClient();
        $producer = $this->producer([
            'status' => SubscriptionStatus::Canceled,
            'trialEndsAt' => null,
            'currentPeriodEnd' => new \DateTimeImmutable('2026-05-04 10:00'),
            'stripeCustomerId' => 'cus_A',
            'stripeSubscriptionId' => 'sub_A',
        ]);

        $client->loginUser($producer, 'producer');
        $crawler = $client->request('GET', '/pro/abonnement');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-billing-status]', 'Résilié');
        self::assertSelectorTextContains('[data-billing-summary]', 'Ton abonnement est résilié depuis le lundi 4 mai 2026.');
        self::assertCount(2, $crawler->filter('form[action="/pro/abonnement/souscrire"]'));
        self::assertSelectorExists('form[action="/pro/abonnement/gerer"]');
    }

    public function testAShopWithoutSubscriptionRowCanSubscribe(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');
        $crawler = $client->request('GET', '/pro/abonnement');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-billing-summary]', 'Tu n\'as pas encore d\'abonnement.');
        self::assertCount(2, $crawler->filter('form[action="/pro/abonnement/souscrire"]'));
    }

    /**
     * @return iterable<string, array{string, SubscriptionPlan}>
     */
    public static function providePlans(): iterable
    {
        yield 'monthly' => ['Choisir le mensuel', SubscriptionPlan::Monthly];
        yield 'yearly' => ['Choisir l\'annuel', SubscriptionPlan::Yearly];
    }

    #[DataProvider('providePlans')]
    public function testCheckoutRedirectsToStripe(string $button, SubscriptionPlan $plan): void
    {
        $client = self::createClient();
        $producer = $this->producer();

        $client->loginUser($producer, 'producer');
        $client->request('GET', '/pro/abonnement');
        $client->submitForm($button);

        self::assertResponseRedirects(FakeBillingGateway::CHECKOUT_URL, 303);

        $requests = $this->gateway()->checkoutRequests;
        self::assertCount(1, $requests);
        self::assertSame($producer->getShop()?->getId(), $requests[0]->shopId);
        self::assertSame($plan, $requests[0]->plan);
        self::assertSame($producer->getEmail(), $requests[0]->customerEmail);
        self::assertNull($requests[0]->stripeCustomerId);
        self::assertSame('http://localhost/pro/abonnement?retour=succes', $requests[0]->successUrl);
        self::assertSame('http://localhost/pro/abonnement?retour=abandon', $requests[0]->cancelUrl);
    }

    public function testCheckoutReusesTheStripeCustomerOfACanceledSubscription(): void
    {
        $client = self::createClient();
        $client->loginUser($this->producer(['status' => SubscriptionStatus::Canceled, 'stripeCustomerId' => 'cus_A', 'stripeSubscriptionId' => 'sub_A']), 'producer');
        $client->request('GET', '/pro/abonnement');
        $client->submitForm('Choisir le mensuel');

        self::assertResponseRedirects(FakeBillingGateway::CHECKOUT_URL, 303);
        self::assertSame('cus_A', $this->gateway()->checkoutRequests[0]->stripeCustomerId);
    }

    public function testCheckoutIsRefusedWhenASubscriptionIsAlreadyRunning(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->loginUser($this->producer(), 'producer');
        $crawler = $client->request('GET', '/pro/abonnement');
        $form = $crawler->selectButton('Choisir le mensuel')->form();

        // The webhook activates the subscription while the page is still open.
        ShopSubscriptionFactory::repository()->findAll()[0]->setStatus(SubscriptionStatus::Active)->setStripeSubscriptionId('sub_A')->setStripeCustomerId('cus_A');
        self::getContainer()->get('doctrine')->getManager()->flush();

        $client->submit($form);

        self::assertResponseRedirects('/pro/abonnement');
        self::assertSame([], $this->gateway()->checkoutRequests);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="info"]', 'Tu as déjà un abonnement en cours');
    }

    public function testCheckoutRejectsAnUnknownPlan(): void
    {
        $client = self::createClient();
        $client->loginUser($this->producer(), 'producer');
        $crawler = $client->request('GET', '/pro/abonnement');

        $client->request('POST', '/pro/abonnement/souscrire', ['_token' => $this->token($crawler, '/pro/abonnement/souscrire'), 'plan' => 'lifetime']);

        self::assertResponseRedirects('/pro/abonnement');
        self::assertSame([], $this->gateway()->checkoutRequests);
    }

    public function testPortalRedirectsToStripe(): void
    {
        $client = self::createClient();
        $client->loginUser($this->producer(['status' => SubscriptionStatus::Active, 'stripeCustomerId' => 'cus_A', 'stripeSubscriptionId' => 'sub_A']), 'producer');
        $client->request('GET', '/pro/abonnement');
        $client->submitForm('Gérer mon abonnement');

        self::assertResponseRedirects(FakeBillingGateway::PORTAL_URL, 303);
        self::assertSame([['customerId' => 'cus_A', 'returnUrl' => 'http://localhost/pro/abonnement']], $this->gateway()->portalRequests);
    }

    public function testPortalNeedsAStripeCustomer(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->loginUser($this->producer(['status' => SubscriptionStatus::Active, 'stripeCustomerId' => 'cus_A', 'stripeSubscriptionId' => 'sub_A']), 'producer');
        $crawler = $client->request('GET', '/pro/abonnement');
        $form = $crawler->selectButton('Gérer mon abonnement')->form();

        ShopSubscriptionFactory::repository()->findAll()[0]->setStripeCustomerId(null);
        self::getContainer()->get('doctrine')->getManager()->flush();

        $client->submit($form);

        self::assertResponseRedirects('/pro/abonnement');
        self::assertSame([], $this->gateway()->portalRequests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePostRoutes(): iterable
    {
        yield 'pro_billing_checkout' => ['/pro/abonnement/souscrire'];
        yield 'pro_billing_portal' => ['/pro/abonnement/gerer'];
    }

    #[DataProvider('providePostRoutes')]
    public function testPostRoutesRequireAValidCsrfToken(string $url): void
    {
        $client = self::createClient();
        $client->loginUser($this->producer(['stripeCustomerId' => 'cus_A']), 'producer');

        foreach ([[], ['_token' => 'forged', 'plan' => 'monthly']] as $payload) {
            $client->request('POST', $url, $payload + ['plan' => 'monthly']);

            self::assertResponseStatusCodeSame(403);
            self::assertSame([], $this->gateway()->checkoutRequests);
            self::assertSame([], $this->gateway()->portalRequests);
        }

        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(405);
    }

    public function testAStripeOutageIsExplainedToTheProducer(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->loginUser($this->producer(['status' => SubscriptionStatus::Canceled, 'stripeCustomerId' => 'cus_A', 'stripeSubscriptionId' => 'sub_A']), 'producer');
        $this->gateway()->unavailable = true;

        foreach (['Choisir l\'annuel', 'Gérer mon abonnement'] as $button) {
            $client->request('GET', '/pro/abonnement');
            $client->submitForm($button);

            self::assertResponseRedirects('/pro/abonnement');
            $client->followRedirect();
            self::assertSelectorTextContains('[data-flash="error"]', 'Impossible de joindre Stripe pour le moment');
        }
    }

    public function testReturningFromCheckoutIsAcknowledged(): void
    {
        $client = self::createClient();
        $client->loginUser($this->producer(), 'producer');

        $client->request('GET', '/pro/abonnement?retour=succes');
        self::assertSelectorTextContains('[data-billing-return="succes"]', 'Ton abonnement s\'active dans quelques instants');

        $client->request('GET', '/pro/abonnement?retour=abandon');
        self::assertSelectorTextContains('[data-billing-return="abandon"]', 'rien n\'a changé');
    }

    public function testEachProducerOnlySeesAndManagesTheirOwnSubscription(): void
    {
        $client = self::createClient();
        $this->producer(['status' => SubscriptionStatus::Active, 'trialEndsAt' => null, 'currentPeriodEnd' => new \DateTimeImmutable('2027-03-15'), 'stripeCustomerId' => 'cus_OTHER', 'stripeSubscriptionId' => 'sub_OTHER']);
        $producer = $this->producer(['status' => SubscriptionStatus::Canceled, 'trialEndsAt' => null, 'stripeCustomerId' => 'cus_MINE', 'stripeSubscriptionId' => 'sub_MINE']);

        $client->loginUser($producer, 'producer');
        $client->request('GET', '/pro/abonnement');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-billing-status="canceled"]');
        self::assertSelectorTextNotContains('main', '15 mars 2027');

        // Even a forged shop or customer id in the request is ignored: everything comes from the session.
        $client->request('POST', '/pro/abonnement/gerer', ['_token' => $this->token($client->getCrawler(), '/pro/abonnement/gerer'), 'customer' => 'cus_OTHER', 'shop' => '1']);
        self::assertResponseRedirects(FakeBillingGateway::PORTAL_URL, 303);
        self::assertSame('cus_MINE', $this->gateway()->portalRequests[0]['customerId']);

        $client->request('GET', '/pro/abonnement');
        $client->submitForm('Choisir le mensuel', ['plan' => 'monthly']);
        self::assertResponseRedirects(FakeBillingGateway::CHECKOUT_URL, 303);
        self::assertSame($producer->getShop()?->getId(), $this->gateway()->checkoutRequests[0]->shopId);
        self::assertSame('cus_MINE', $this->gateway()->checkoutRequests[0]->stripeCustomerId);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRoutes(): iterable
    {
        yield 'pro_billing' => ['GET', '/pro/abonnement'];
        yield 'pro_billing_checkout' => ['POST', '/pro/abonnement/souscrire'];
        yield 'pro_billing_portal' => ['POST', '/pro/abonnement/gerer'];
    }

    #[DataProvider('provideRoutes')]
    public function testRequiresAProducerWithAShop(string $method, string $url): void
    {
        $client = self::createClient();
        $client->request($method, $url);
        self::assertResponseRedirects('/pro/connexion');

        $client->loginUser(UserFactory::new()->withoutShop()->create(), 'producer');
        $client->request($method, $url);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $subscription attributes of the shop's subscription (default: a 30-day trial)
     */
    private function producer(array $subscription = []): User
    {
        $producer = UserFactory::createOne();
        ShopSubscriptionFactory::createOne(['shop' => $producer->getShop()] + $subscription);

        return $producer;
    }

    private function token(Crawler $crawler, string $action): string
    {
        $token = $crawler->filter(\sprintf('form[action="%s"] input[name="_token"]', $action))->attr('value');
        self::assertNotNull($token);

        return $token;
    }

    private function gateway(): FakeBillingGateway
    {
        $gateway = self::getContainer()->get(BillingGateway::class);
        self::assertInstanceOf(FakeBillingGateway::class, $gateway);

        return $gateway;
    }
}
