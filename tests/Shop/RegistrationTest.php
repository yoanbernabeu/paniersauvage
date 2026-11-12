<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Repository\ShopSubscriptionRepository;
use App\Shop\Entity\User;
use App\Shop\Repository\UserRepository;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;

final class RegistrationTest extends WebTestCase
{
    use Factories;

    private const array VALID = [
        'registration[firstName]' => 'Camille',
        'registration[lastName]' => 'Durand',
        'registration[shopName]' => 'Le Fournil du Pont',
        'registration[email]' => 'Camille@Example.test',
        'registration[plainPassword]' => 'pain-au-levain',
        'registration[acceptTerms]' => '1',
    ];

    public function testSignUpPageRenders(): void
    {
        $client = $this->createFreshClient();
        $client->request('GET', '/pro/inscription');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Crée ta boutique');
        self::assertSelectorExists('input[name="registration[firstName]"]');
        self::assertSelectorExists('input[name="registration[lastName]"]');
        self::assertSelectorExists('input[name="registration[shopName]"]');
        self::assertSelectorExists('input[type="email"][name="registration[email]"]');
        self::assertSelectorExists('input[type="password"][name="registration[plainPassword]"]');
    }

    public function testSignUpCreatesAccountShopAndTrialThenLogsIn(): void
    {
        $client = $this->createFreshClient();
        $client->request('GET', '/pro/inscription');
        $client->submitForm('Créer ma boutique', self::VALID);

        self::assertResponseRedirects('/pro/bienvenue');

        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'camille@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Camille', $user->getFirstName());
        self::assertSame('Durand', $user->getLastName());
        self::assertContains(User::ROLE_PRODUCER, $user->getRoles());
        self::assertNotSame('pain-au-levain', $user->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'pain-au-levain'));

        $shop = $user->getShop();
        self::assertNotNull($shop);
        self::assertSame('Le Fournil du Pont', $shop->getName());
        self::assertSame('le-fournil-du-pont', $shop->getSlug());

        $subscription = self::getContainer()->get(ShopSubscriptionRepository::class)->findOneBy(['shop' => $shop]);
        self::assertNotNull($subscription);
        self::assertSame(SubscriptionStatus::Trialing, $subscription->getStatus());
        self::assertNotNull($subscription->getTrialEndsAt());
        $now = new \DateTimeImmutable();
        self::assertSame(30, $subscription->trialDaysLeft($now));
        self::assertTrue($subscription->allowsNewSales($now));

        // The producer is logged in on the "producer" firewall: the welcome page opens directly.
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bienvenue, Camille');
        self::assertSelectorTextContains('[data-flash="success"]', '30 jours d\'essai gratuit');

        // And the sign-up page now sends them back to their space.
        $client->request('GET', '/pro/inscription');
        self::assertResponseRedirects('/pro/bienvenue');
    }

    public function testTheNewProducerCanLogInAgainWithTheirPassword(): void
    {
        $client = $this->createFreshClient();
        $client->request('GET', '/pro/inscription');
        $client->submitForm('Créer ma boutique', self::VALID);
        $client->request('GET', '/pro/bienvenue');
        $client->submitForm('Se déconnecter');

        $client->request('GET', '/pro/connexion');
        $client->submitForm('Me connecter', ['_username' => 'camille@example.test', '_password' => 'pain-au-levain']);

        self::assertResponseRedirects('/pro');
    }

    public function testSlugIsUniqueAndNeverReserved(): void
    {
        $client = $this->createFreshClient();
        ShopFactory::createOne(['name' => 'Le Fournil du Pont', 'slug' => 'le-fournil-du-pont']);

        $client->request('GET', '/pro/inscription');
        $client->submitForm('Créer ma boutique', self::VALID);
        self::assertResponseRedirects('/pro/bienvenue');
        self::assertSame('le-fournil-du-pont-2', $this->shopSlugOf('camille@example.test'));

        $client->request('GET', '/pro/bienvenue');
        $client->submitForm('Se déconnecter');
        $client->request('GET', '/pro/inscription');
        $client->submitForm('Créer ma boutique', [
            'registration[shopName]' => 'Pro',
            'registration[email]' => 'autre@example.test',
        ] + self::VALID);
        self::assertResponseRedirects('/pro/bienvenue');
        self::assertSame('chez-pro', $this->shopSlugOf('autre@example.test'));
    }

    public function testAnExistingEmailIsRejected(): void
    {
        $client = $this->createFreshClient();
        UserFactory::createOne(['email' => 'camille@example.test']);

        $client->request('GET', '/pro/inscription');
        $client->submitForm('Créer ma boutique', self::VALID);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Un compte existe déjà avec cette adresse e-mail.');
        self::assertSame(1, self::getContainer()->get(UserRepository::class)->count([]));
    }

    public function testInvalidDataShowsErrorsAndCreatesNothing(): void
    {
        $client = $this->createFreshClient();
        $client->request('GET', '/pro/inscription');
        $client->submitForm('Créer ma boutique', [
            'registration[firstName]' => '',
            'registration[lastName]' => 'Durand',
            'registration[shopName]' => '',
            'registration[email]' => 'pas-un-email',
            'registration[plainPassword]' => 'court',
        ]);

        self::assertResponseStatusCodeSame(422);
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Indique ton prénom.', $page);
        self::assertStringContainsString('Indique le nom de ton activité.', $page);
        self::assertStringContainsString('Cette adresse e-mail ne semble pas valide.', $page);
        self::assertStringContainsString('au moins 8 caractères', $page);
        self::assertSame(0, self::getContainer()->get(UserRepository::class)->count([]));

        // Still anonymous.
        $client->request('GET', '/pro/bienvenue');
        self::assertResponseRedirects('/pro/connexion');
    }

    public function testSignUpIsRateLimited(): void
    {
        $client = $this->createFreshClient();

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $client->request('GET', '/pro/inscription');
            $client->submitForm('Créer ma boutique', ['registration[email]' => 'pas-un-email'] + self::VALID);
            self::assertResponseStatusCodeSame(422, \sprintf('Attempt %d should be validated normally.', $attempt));
        }

        $client->request('GET', '/pro/inscription');
        $client->submitForm('Créer ma boutique', self::VALID);

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('[role="alert"]', 'Trop de tentatives');
        self::assertSame(0, self::getContainer()->get(UserRepository::class)->count([]));
    }

    public function testProbingExistingEmailsIsThrottledLikeAnySignUp(): void
    {
        $client = $this->createFreshClient();
        UserFactory::createOne(['email' => 'camille@example.test']);

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $client->request('GET', '/pro/inscription');
            $client->submitForm('Créer ma boutique', self::VALID);
            self::assertSelectorTextContains('[role="alert"]', 'Un compte existe déjà');
        }

        // Past the limit, the answer no longer says whether the address is taken.
        $client->request('GET', '/pro/inscription');
        $client->submitForm('Créer ma boutique', self::VALID);
        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextNotContains('form', 'Un compte existe déjà');
    }

    /**
     * The rate limiter lives in the cache, which outlasts a test: start each test with a clean slate.
     */
    private function createFreshClient(): KernelBrowser
    {
        $client = self::createClient();
        self::getContainer()->get('limiter.registration')->create('127.0.0.1')->reset();

        return $client;
    }

    private function shopSlugOf(string $email): ?string
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email])?->getShop()?->getSlug();
    }
}
