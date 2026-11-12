<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Service\BillingBannerProvider;
use App\Shop\Entity\Shop;
use App\Tests\Factory\ShopSubscriptionFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class BillingBannerTest extends WebTestCase
{
    use Factories;

    public function testBannerRules(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get(BillingBannerProvider::class);
        $now = new \DateTimeImmutable('2026-11-09 10:00');

        // Trial: silent until less than 7 days are left.
        self::assertNull($provider->for($this->subscription(SubscriptionStatus::Trialing, trialEndsAt: $now->modify('+30 days')), $now));
        self::assertNull($provider->for($this->subscription(SubscriptionStatus::Trialing, trialEndsAt: $now->modify('+7 days')), $now));

        $ending = $provider->for($this->subscription(SubscriptionStatus::Trialing, trialEndsAt: $now->modify('+7 days -1 minute')), $now);
        self::assertSame(['warning', 'Ton essai gratuit se termine dans 7 jours.'], [$ending?->variant, $ending?->title]);

        $lastDay = $provider->for($this->subscription(SubscriptionStatus::Trialing, trialEndsAt: $now->modify('+5 hours')), $now);
        self::assertSame(['warning', 'Ton essai gratuit se termine dans moins de 24 heures.'], [$lastDay?->variant, $lastDay?->title]);

        // New sales blocked: always shown.
        $expired = $provider->for($this->subscription(SubscriptionStatus::Trialing, trialEndsAt: $now), $now);
        self::assertSame(['destructive', 'Ton essai gratuit est terminé.', 'M\'abonner'], [$expired?->variant, $expired?->title, $expired?->action]);
        self::assertSame(BillingBannerProvider::INACTIVE_CONSEQUENCE, $expired?->message);

        $canceled = $provider->for($this->subscription(SubscriptionStatus::Canceled), $now);
        self::assertSame(['destructive', 'Ton abonnement est résilié.'], [$canceled?->variant, $canceled?->title]);

        $suspended = $provider->for($this->subscription(SubscriptionStatus::PastDue, currentPeriodEnd: $now->modify('-8 days')), $now);
        self::assertSame(['destructive', 'Régulariser'], [$suspended?->variant, $suspended?->action]);
        self::assertStringContainsString('suspendu', (string) $suspended?->title);

        // Subscriptions that still allow new sales: nothing.
        self::assertNull($provider->for($this->subscription(SubscriptionStatus::Active, currentPeriodEnd: $now->modify('+3 days')), $now));
        // Failed payment, grace period still running: warned, with the date sales stop opening.
        $late = $provider->for($this->subscription(SubscriptionStatus::PastDue, currentPeriodEnd: $now->modify('-2 days')), $now);
        self::assertSame(['warning', 'Ton dernier paiement n\'est pas passé.', 'Mettre à jour'], [$late?->variant, $late?->title, $late?->action]);
        self::assertStringContainsString('avant le samedi 14 novembre', (string) $late?->message);
    }

    public function testTheProLayoutShowsTheBannerOnEveryPage(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        ShopSubscriptionFactory::new()->canceled()->create(['shop' => $producer->getShop()]);
        // Another shop in good standing must not hide it, and vice versa.
        ShopSubscriptionFactory::new()->active()->create();

        $client->loginUser($producer, 'producer');
        $crawler = $client->request('GET', '/pro/comptabilite');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-billing-banner="destructive"]', 'Ton abonnement est résilié.');
        self::assertSelectorTextContains('[data-billing-banner]', 'aucune nouvelle vente ne peut s\'ouvrir ni se créer');
        self::assertSame('/pro/abonnement', $crawler->filter('[data-billing-banner] a')->attr('href'));

        // On the subscription page itself, the banner does not link to the page you are on.
        $crawler = $client->request('GET', '/pro/abonnement');
        self::assertSelectorExists('[data-billing-banner="destructive"]');
        self::assertCount(0, $crawler->filter('[data-billing-banner] a'));
    }

    public function testTheBannerWarnsWhenTheTrialIsAboutToEnd(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        ShopSubscriptionFactory::createOne(['shop' => $producer->getShop(), 'trialEndsAt' => new \DateTimeImmutable('+2 days +1 hour')]);

        $client->loginUser($producer, 'producer');
        $client->request('GET', '/pro/comptabilite');

        self::assertSelectorTextContains('[data-billing-banner="warning"]', 'Ton essai gratuit se termine dans 3 jours.');
    }

    public function testNoBannerWhenEverythingIsFine(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        ShopSubscriptionFactory::new()->active()->create(['shop' => $producer->getShop()]);
        ShopSubscriptionFactory::new()->canceled()->create();

        $client->loginUser($producer, 'producer');
        $client->request('GET', '/pro/comptabilite');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-billing-banner]');

        // A shop without subscription row gets no banner either.
        $client->loginUser(UserFactory::createOne(), 'producer');
        $client->request('GET', '/pro/comptabilite');
        self::assertSelectorNotExists('[data-billing-banner]');
    }

    private function subscription(SubscriptionStatus $status, ?\DateTimeImmutable $trialEndsAt = null, ?\DateTimeImmutable $currentPeriodEnd = null): ShopSubscription
    {
        return new ShopSubscription(new Shop('Fournil', 'fournil'))
            ->setStatus($status)
            ->setTrialEndsAt($trialEndsAt)
            ->setCurrentPeriodEnd($currentPeriodEnd);
    }
}
