<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SolidarityCriterion;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Repository\ShopSubscriptionRepository;
use App\Billing\Stripe\BillingGateway;
use App\Billing\Stripe\FakeBillingGateway;
use App\Shop\Entity\User;
use App\Tests\Factory\ShopSubscriptionFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Solidarity plan on the subscription page, with the test double of the billing gateway: no call to Stripe.
 */
final class SolidarityPagesTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testTheDeclarationFormListsTheCriteriaAndSuggestsFiveEuros(): void
    {
        $this->login($this->producer());
        $crawler = $this->client->request('GET', '/pro/abonnement');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-solidarity] h2', 'Palier solidaire');
        self::assertSelectorTextContains('[data-solidarity]', 'Aucun justificatif');
        self::assertCount(4, $crawler->filter('[data-solidarity] input[type="checkbox"][name="solidarity_declaration[criteria][]"]'));
        self::assertSelectorTextContains('[data-solidarity]', 'date d\'immatriculation de ton SIRET');
        self::assertSame('5', $crawler->filter('#solidarity_declaration_amountEuros')->attr('value'));
        self::assertSame('10', $crawler->filter('#solidarity_declaration_amountEuros')->attr('max'));
        self::assertSelectorExists('#solidarity_declaration_honour[required]');
        self::assertSame('/pro/abonnement/solidaire', $crawler->filter('form[name="solidarity_declaration"]')->attr('action'));
        self::assertSame(['0', '1'], [$crawler->filter('#solidarity_declaration_amountEuros')->attr('min'), $crawler->filter('#solidarity_declaration_amountEuros')->attr('step')]);
        self::assertCount(1, $crawler->filter('input[data-criterion="food_aid"][value="food_aid"]'));
        self::assertSelectorTextContains('[data-solidarity] legend', 'Ta situation (une seule suffit)');
        self::assertSelectorTextContains('label[for="solidarity_declaration_amountEuros"]', 'Ton prix, en euros par mois');
        self::assertSelectorTextContains('label[for="solidarity_declaration_honour"]', 'Je déclare sur l\'honneur que ma situation correspond à ce que j\'ai coché.');
        self::assertSelectorTextContains('label[for="solidarity_declaration_criteria_0"]', 'Je me suis installé·e il y a moins de 3 ans');
        self::assertSelectorTextNotContains('[data-solidarity] fieldset', 'facultatif');
        self::assertSelectorTextContains('[data-solidarity] button[type="submit"]', 'Choisir le palier solidaire');
    }

    public function testZeroEurosActivatesTheFreePlanWithoutStripe(): void
    {
        $producer = $this->producer();
        $this->login($producer);

        $this->declare(0, ['small_volume', 'first_season']);

        self::assertResponseRedirects('/pro/abonnement', 303);
        self::assertSame([], $this->gateway()->checkoutRequests);
        self::assertSame([], $this->gateway()->cancellations);

        $subscription = $this->subscriptionOf($producer);
        self::assertSame(SubscriptionPlan::Solidarity, $subscription->getPlan());
        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        self::assertSame(0, $subscription->getAmountCents());
        self::assertSame([SolidarityCriterion::SmallVolume, SolidarityCriterion::FirstSeason], $subscription->getSolidarityCriteria());
        self::assertNotNull($subscription->getSolidarityDeclaredAt());
        self::assertTrue($subscription->allowsNewSales(new \DateTimeImmutable('+11 months')));
        self::assertFalse($subscription->allowsNewSales(new \DateTimeImmutable('+12 months +1 day')));

        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-flash], main', 'ton palier solidaire gratuit est actif pour 12 mois');
        self::assertSelectorTextContains('[data-billing-summary]', 'Ton palier solidaire gratuit est actif jusqu\'au');
        self::assertSelectorTextContains('[data-billing-status]', 'Actif · solidaire');
        self::assertSelectorTextContains('[data-solidarity] button[type="submit"]', 'Mettre à jour ma déclaration');
        self::assertSelectorExists('[data-solidarity] input[value="small_volume"][checked]');
        self::assertSame('0', $this->client->getCrawler()->filter('#solidarity_declaration_amountEuros')->attr('value'));
        // Free plan: the standard plans stay available (no running Stripe subscription).
        self::assertSelectorExists('[data-billing-plan="monthly"]');
    }

    public function testAPositiveAmountGoesThroughStripeCheckout(): void
    {
        $producer = $this->producer(['stripeCustomerId' => 'cus_OLD']);
        $this->login($producer);

        $this->declare(7, ['recent_installation']);

        self::assertResponseRedirects(FakeBillingGateway::CHECKOUT_URL, 303);
        $request = $this->gateway()->checkoutRequests[0] ?? null;
        self::assertNotNull($request);
        self::assertSame(SubscriptionPlan::Solidarity, $request->plan);
        self::assertSame(700, $request->amountCents);
        self::assertSame($producer->getShop()?->getId(), $request->shopId);
        self::assertSame($producer->getEmail(), $request->customerEmail);
        self::assertSame('cus_OLD', $request->stripeCustomerId);
        self::assertStringEndsWith('/pro/abonnement?retour=succes', $request->successUrl);
        self::assertStringEndsWith('/pro/abonnement?retour=abandon', $request->cancelUrl);

        // Nothing changes before Stripe confirms the subscription; the declaration is kept.
        $subscription = $this->subscriptionOf($producer);
        self::assertSame(SubscriptionStatus::Trialing, $subscription->getStatus());
        self::assertSame(SubscriptionPlan::Monthly, $subscription->getPlan());
        self::assertSame([SolidarityCriterion::RecentInstallation], $subscription->getSolidarityCriteria());
    }

    public function testARunningSubscriptionChangesItsAmountInStripe(): void
    {
        $producer = $this->producer(['status' => SubscriptionStatus::Active, 'trialEndsAt' => null, 'stripeCustomerId' => 'cus_A', 'stripeSubscriptionId' => 'sub_A']);
        $this->login($producer);

        $crawler = $this->client->request('GET', '/pro/abonnement');
        self::assertSelectorTextContains('[data-solidarity-running]', 'à ta prochaine échéance');
        self::assertCount(0, $crawler->filter('[data-billing-plan]'));

        $this->declare(3, ['food_aid']);

        self::assertResponseRedirects('/pro/abonnement', 303);
        self::assertSame([['subscriptionId' => 'sub_A', 'shopId' => (int) $producer->getShop()?->getId(), 'amountCents' => 300]], $this->gateway()->amountChanges);
        self::assertSame([], $this->gateway()->checkoutRequests);

        $subscription = $this->subscriptionOf($producer);
        self::assertSame(SubscriptionPlan::Solidarity, $subscription->getPlan());
        self::assertSame(300, $subscription->getAmountCents());
        self::assertSame('sub_A', $subscription->getStripeSubscriptionId());

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'ton abonnement solidaire passe à 3 € par mois');
        self::assertSelectorTextContains('[data-billing-summary]', 'Ton abonnement solidaire est actif, à 3,00');
    }

    public function testZeroEurosEndsARunningSubscription(): void
    {
        $producer = $this->producer(['status' => SubscriptionStatus::PastDue, 'trialEndsAt' => null, 'stripeCustomerId' => 'cus_A', 'stripeSubscriptionId' => 'sub_A']);
        $this->login($producer);

        $this->declare(0, ['small_volume']);

        self::assertResponseRedirects('/pro/abonnement', 303);
        self::assertSame(['sub_A'], $this->gateway()->cancellations);
        $subscription = $this->subscriptionOf($producer);
        self::assertTrue($subscription->isFreeSolidarity());
        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        self::assertSame('cus_A', $subscription->getStripeCustomerId());
    }

    public function testACanceledSubscriptionIsNotCancelledAgain(): void
    {
        $producer = $this->producer(['status' => SubscriptionStatus::Canceled, 'trialEndsAt' => null, 'stripeCustomerId' => 'cus_A', 'stripeSubscriptionId' => 'sub_A']);
        $this->login($producer);

        $this->declare(0, ['small_volume']);

        self::assertSame([], $this->gateway()->cancellations);
        self::assertTrue($this->subscriptionOf($producer)->isFreeSolidarity());
    }

    public function testRenewingTheFreePlanStartsTwelveNewMonths(): void
    {
        $producer = $this->producer([
            'plan' => SubscriptionPlan::Solidarity,
            'status' => SubscriptionStatus::Active,
            'amountCents' => 0,
            'trialEndsAt' => null,
            'currentPeriodEnd' => new \DateTimeImmutable('-3 days'),
            'solidarityReminderSentAt' => new \DateTimeImmutable('-40 days'),
        ]);
        $this->login($producer);

        $this->client->request('GET', '/pro/abonnement');
        self::assertSelectorTextContains('[data-billing-status]', 'Palier solidaire échu');
        self::assertSelectorTextContains('[data-billing-summary]', 'arrivé à échéance');
        self::assertSelectorTextContains('[data-billing-banner="destructive"]', 'Ton palier solidaire gratuit est arrivé à échéance.');

        $this->declare(0, ['first_season']);

        $subscription = $this->subscriptionOf($producer);
        self::assertTrue($subscription->allowsNewSales(new \DateTimeImmutable()));
        self::assertGreaterThan(new \DateTimeImmutable('+11 months'), $subscription->getCurrentPeriodEnd());
        self::assertNull($subscription->getSolidarityReminderSentAt());
    }

    public function testAnUnknownAmountSuggestsFiveEuros(): void
    {
        $this->login($this->producer(['plan' => SubscriptionPlan::Solidarity, 'status' => SubscriptionStatus::Active, 'trialEndsAt' => null, 'stripeSubscriptionId' => 'sub_A', 'amountCents' => null]));

        $crawler = $this->client->request('GET', '/pro/abonnement');

        self::assertSame('5', $crawler->filter('#solidarity_declaration_amountEuros')->attr('value'));
    }

    public function testAStripeOutageDuringAChangeOfAmountChangesNothing(): void
    {
        foreach ([3, 0] as $euros) {
            $producer = $this->producer(['status' => SubscriptionStatus::Active, 'trialEndsAt' => null, 'stripeCustomerId' => 'cus_A', 'stripeSubscriptionId' => 'sub_'.$euros]);
            $this->login($producer);
            $this->client->request('GET', '/pro/abonnement');
            $this->client->disableReboot();
            $this->gateway()->unavailable = true;

            $this->declare($euros, ['small_volume']);

            self::assertResponseRedirects('/pro/abonnement');
            self::assertSame([], $this->gateway()->amountChanges);
            self::assertSame([], $this->gateway()->cancellations);
            $subscription = $this->subscriptionOf($producer);
            self::assertSame(SubscriptionPlan::Monthly, $subscription->getPlan());
            self::assertSame('sub_'.$euros, $subscription->getStripeSubscriptionId());
            $this->gateway()->unavailable = false;
            $this->client->enableReboot();
        }
    }

    public function testAShopWithoutSubscriptionRowCanDeclare(): void
    {
        $producer = UserFactory::createOne();
        $this->login($producer);

        $this->declare(0, ['food_aid']);

        self::assertResponseRedirects('/pro/abonnement', 303);
        self::assertTrue($this->subscriptionOf($producer)->isFreeSolidarity());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidDeclarations(): iterable
    {
        yield 'no criterion' => [['amountEuros' => '5', 'honour' => '1'], 'Coche au moins une situation'];
        yield 'no honour' => [['criteria' => ['small_volume'], 'amountEuros' => '5'], 'Coche la déclaration sur l\'honneur'];
        yield 'more than 10 €' => [['criteria' => ['small_volume'], 'amountEuros' => '11', 'honour' => '1'], 'Choisis un montant entre 0 et 10 €.'];
        yield 'negative' => [['criteria' => ['small_volume'], 'amountEuros' => '-1', 'honour' => '1'], 'Choisis un montant entre 0 et 10 €.'];
        yield 'cents' => [['criteria' => ['small_volume'], 'amountEuros' => '2.5', 'honour' => '1'], 'nombre entier d\'euros'];
        yield 'empty amount' => [['criteria' => ['small_volume'], 'amountEuros' => '', 'honour' => '1'], 'Choisis un montant entre 0 et 10 €.'];
        yield 'unknown criterion' => [['criteria' => ['rich'], 'amountEuros' => '5', 'honour' => '1'], 'invalide'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('provideInvalidDeclarations')]
    public function testAnIncompleteDeclarationChangesNothing(array $fields, string $error): void
    {
        $producer = $this->producer();
        $this->login($producer);

        $crawler = $this->client->request('GET', '/pro/abonnement');
        $token = $crawler->filter('#solidarity_declaration__token')->attr('value');
        $this->client->request('POST', '/pro/abonnement/solidaire', ['solidarity_declaration' => $fields + ['_token' => $token]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-solidarity]', $error);
        self::assertSame([], $this->gateway()->checkoutRequests);
        $subscription = $this->subscriptionOf($producer);
        self::assertSame(SubscriptionStatus::Trialing, $subscription->getStatus());
        self::assertNull($subscription->getSolidarityDeclaredAt());
    }

    public function testRequiresAValidCsrfToken(): void
    {
        $producer = $this->producer();
        $this->login($producer);

        $this->client->request('POST', '/pro/abonnement/solidaire', ['solidarity_declaration' => ['criteria' => ['small_volume'], 'amountEuros' => '0', 'honour' => '1', '_token' => 'forged']]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->subscriptionOf($producer)->getSolidarityDeclaredAt());
    }

    public function testAStripeOutageIsExplained(): void
    {
        $producer = $this->producer();
        $this->login($producer);
        $this->client->request('GET', '/pro/abonnement');
        $this->client->disableReboot();
        $this->gateway()->unavailable = true;

        $this->declare(5, ['small_volume']);

        self::assertResponseRedirects('/pro/abonnement');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Impossible de joindre Stripe');
        self::assertNull($this->subscriptionOf($producer)->getSolidarityDeclaredAt());
    }

    public function testTheStandardCheckoutRefusesTheSolidarityPlan(): void
    {
        $this->login($this->producer());
        $crawler = $this->client->request('GET', '/pro/abonnement');
        $token = $crawler->filter('form[action="/pro/abonnement/souscrire"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/pro/abonnement/souscrire', ['_token' => $token, 'plan' => 'solidarity']);

        self::assertResponseRedirects('/pro/abonnement');
        self::assertSame([], $this->gateway()->checkoutRequests);
    }

    public function testEachProducerOnlyChangesTheirOwnSubscription(): void
    {
        $other = $this->producer(['status' => SubscriptionStatus::Active, 'trialEndsAt' => null, 'stripeCustomerId' => 'cus_OTHER', 'stripeSubscriptionId' => 'sub_OTHER']);
        $producer = $this->producer();
        $this->login($producer);

        $this->declare(0, ['small_volume'], extra: ['shop' => (string) $other->getShop()?->getId()]);

        self::assertSame([], $this->gateway()->cancellations);
        self::assertTrue($this->subscriptionOf($producer)->isFreeSolidarity());
        $untouched = $this->subscriptionOf($other);
        self::assertSame(SubscriptionPlan::Monthly, $untouched->getPlan());
        self::assertSame('sub_OTHER', $untouched->getStripeSubscriptionId());
        self::assertNull($untouched->getSolidarityDeclaredAt());
    }

    public function testRequiresAProducerWithAShop(): void
    {
        $this->client->request('POST', '/pro/abonnement/solidaire');
        self::assertResponseRedirects('/pro/connexion');

        $this->client->loginUser(UserFactory::new()->withoutShop()->create(), 'producer');
        $this->client->request('POST', '/pro/abonnement/solidaire');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheHomePageShowsTheSolidarityPlanAndItsCriteria(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-plan="solidarity"]', '0 à 10 €');
        self::assertSelectorTextContains('[data-plan="solidarity"]', '5 € suggérés');
        self::assertSelectorTextContains('[data-plan="solidarity"]', 'déclaration sur l\'honneur');
        self::assertCount(4, $crawler->filter('[data-solidarity-criteria] li'));
    }

    /**
     * @param list<string>          $criteria
     * @param array<string, string> $extra    fields added to the request (forgery attempts)
     */
    private function declare(int $euros, array $criteria, array $extra = []): void
    {
        $crawler = $this->client->request('GET', '/pro/abonnement');
        $token = $crawler->filter('#solidarity_declaration__token')->attr('value');
        $this->client->request('POST', '/pro/abonnement/solidaire', [
            'solidarity_declaration' => ['criteria' => $criteria, 'amountEuros' => (string) $euros, 'honour' => '1', '_token' => $token],
        ] + $extra);
    }

    private function login(User $producer): void
    {
        $this->client->loginUser($producer, 'producer');
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

    private function subscriptionOf(User $producer): ShopSubscription
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $subscription = self::getContainer()->get(ShopSubscriptionRepository::class)->findOneBy(['shop' => $producer->getShop()?->getId()]);
        self::assertNotNull($subscription);

        return $subscription;
    }

    private function gateway(): FakeBillingGateway
    {
        $gateway = self::getContainer()->get(BillingGateway::class);
        self::assertInstanceOf(FakeBillingGateway::class, $gateway);

        return $gateway;
    }
}
