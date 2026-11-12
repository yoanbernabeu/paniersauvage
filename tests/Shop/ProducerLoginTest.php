<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ProducerLoginTest extends WebTestCase
{
    use Factories;

    /** Headers of a form submitted from another site (what browsers send with it). */
    private const array CROSS_SITE = ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/'];

    public function testLoginPageRenders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pro/connexion');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="_username"]');
        self::assertSelectorExists('form input[name="_password"]');
        self::assertSelectorExists('form input[name="_csrf_token"]');
        self::assertSelectorTextContains('button[type="submit"]', 'Me connecter');
    }

    public function testProducerLogsInWithEmailAndPassword(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'boulanger@example.test']);

        $client->request('GET', '/pro/connexion');
        $client->submitForm('Me connecter', ['_username' => 'boulanger@example.test', '_password' => UserFactory::PASSWORD]);

        self::assertResponseRedirects('/pro');

        // Once logged in, the login page sends the producer back to their space.
        $client->request('GET', '/pro/connexion');
        self::assertResponseRedirects('/pro');
    }

    public function testWrongPasswordShowsAnErrorInFrench(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'boulanger@example.test']);

        $client->request('GET', '/pro/connexion');
        $client->submitForm('Me connecter', ['_username' => 'boulanger@example.test', '_password' => 'wrong']);

        self::assertResponseRedirects('/pro/connexion');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-slot="alert"]', 'Identifiants invalides');
        self::assertInputValueSame('_username', 'boulanger@example.test');
    }

    public function testACustomerAccountCannotLogInAsProducer(): void
    {
        $client = self::createClient();
        CustomerFactory::createOne(['email' => 'cliente@example.test']);

        $client->request('GET', '/pro/connexion');
        $client->submitForm('Me connecter', ['_username' => 'cliente@example.test', '_password' => UserFactory::PASSWORD]);

        self::assertResponseRedirects('/pro/connexion');
    }

    public function testLogoutEndsTheProducerSession(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/bienvenue');
        $client->submitForm('Se déconnecter');
        self::assertResponseRedirects('/pro/connexion');

        $client->request('GET', '/pro/connexion');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/pro/bienvenue');
        self::assertResponseRedirects('/pro/connexion');
    }

    public function testLogoutIsNeverAGetRequest(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/deconnexion');
        self::assertResponseStatusCodeSame(405);

        $client->request('GET', '/pro/bienvenue');
        self::assertResponseIsSuccessful();
    }

    public function testLogoutFromAnotherSiteIsRefused(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('POST', '/pro/deconnexion', ['_csrf_token' => 'csrf-token'], [], self::CROSS_SITE);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/pro/bienvenue');
        self::assertResponseIsSuccessful();
    }

    public function testProducerAreaRequiresAuthentication(): void
    {
        $client = self::createClient();

        // /pro/deconnexion is the only producer-only route that exists in the foundations.
        $client->request('POST', '/pro/deconnexion');
        self::assertResponseRedirects('/pro/connexion');
    }

    public function testProducerAndCustomerSessionsAreIndependent(): void
    {
        $client = self::createClient();
        $client->loginUser(CustomerFactory::createOne(), 'main');

        // Logged in as a customer only: the producer firewall still sees an anonymous visitor.
        $client->request('GET', '/pro/connexion');
        self::assertResponseIsSuccessful();

        // Both at once: the producer firewall recognises the producer, and the customer token survives.
        $client->loginUser(UserFactory::createOne(), 'producer');
        $client->request('GET', '/pro/connexion');
        self::assertResponseRedirects('/pro');

        $session = $client->getRequest()->getSession();
        self::assertTrue($session->has('_security_producer'));
        self::assertTrue($session->has('_security_main'));
    }
}
