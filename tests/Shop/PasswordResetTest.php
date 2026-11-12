<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Shop\Entity\PasswordResetRequest;
use App\Shop\Entity\User;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;

final class PasswordResetTest extends WebTestCase
{
    use Factories;

    public function testTheLoginPageLinksToTheForgottenPasswordForm(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pro/connexion');

        self::assertSelectorExists('a[href="/pro/mot-de-passe-oublie"]');
        self::assertSelectorTextContains('a[href="/pro/mot-de-passe-oublie"]', 'Mot de passe oublié ?');
    }

    public function testAKnownAddressReceivesAResetLink(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'boulanger@example.test', 'firstName' => 'Paul']);

        $this->requestReset($client, 'Boulanger@Example.test');

        self::assertResponseRedirects('/pro/mot-de-passe-oublie/envoye');
        self::assertQueuedEmailCount(1);
        $email = $this->lastEmail();
        self::assertSame('boulanger@example.test', $email->getTo()[0]->getAddress());
        self::assertSame('Choisis un nouveau mot de passe', $email->getSubject());
        self::assertStringContainsString('Bonjour Paul', (string) $email->getHtmlBody());
        self::assertStringContainsString('60 minutes', (string) $email->getTextBody());

        // Only the hash of the token is stored.
        $token = $this->tokenOf($email);
        $request = $this->em()->getRepository(PasswordResetRequest::class)->findOneBy([]);
        self::assertNotNull($request);
        self::assertSame(hash('sha256', $token), $request->getTokenHash());
        self::assertNotSame($token, $request->getTokenHash());
        self::assertEqualsWithDelta(3600, $request->getExpiresAt()->getTimestamp() - $request->getRequestedAt()->getTimestamp(), 1);

        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Vérifie ta boîte mail');
    }

    public function testAnUnknownAddressGetsTheSameAnswerAndNoEmail(): void
    {
        $client = self::createClient();

        $this->requestReset($client, 'inconnu@example.test');

        self::assertResponseRedirects('/pro/mot-de-passe-oublie/envoye');
        self::assertQueuedEmailCount(0);
    }

    public function testTheLinkLetsTheProducerChooseANewPasswordAndLogsThemIn(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['email' => 'boulanger@example.test']);

        $this->requestReset($client, 'boulanger@example.test');
        $client->request('GET', $this->pathOf($this->lastEmail()));

        // The token leaves the address bar right away.
        self::assertResponseRedirects('/pro/mot-de-passe/nouveau');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Choisis un nouveau mot de passe');
        self::assertAnySelectorTextContains('p', 'boulanger@example.test');

        $client->submitForm('Enregistrer mon mot de passe', ['new_password[plainPassword]' => 'nouveau-secret']);

        self::assertResponseRedirects('/pro');
        $this->em()->clear();
        $refreshed = $this->em()->find(User::class, $user->getId());
        self::assertNotNull($refreshed);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($refreshed, 'nouveau-secret'));

        $client->request('GET', '/pro');
        self::assertResponseIsSuccessful();
    }

    public function testTheNewPasswordFollowsTheSignUpRules(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'boulanger@example.test']);

        $this->requestReset($client, 'boulanger@example.test');
        $client->request('GET', $this->pathOf($this->lastEmail()));
        $client->followRedirect();
        $client->submitForm('Enregistrer mon mot de passe', ['new_password[plainPassword]' => 'court']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'au moins 8 caractères');
    }

    public function testALinkWorksOnlyOnce(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'boulanger@example.test']);

        $this->requestReset($client, 'boulanger@example.test');
        $path = $this->pathOf($this->lastEmail());
        $client->request('GET', $path);
        $client->followRedirect();
        $client->submitForm('Enregistrer mon mot de passe', ['new_password[plainPassword]' => 'nouveau-secret']);
        self::assertResponseRedirects('/pro');

        $client->request('GET', '/pro/deconnexion');
        $client->request('GET', $path);
        $client->followRedirect();

        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('h1', 'Ce lien ne marche plus');
        self::assertSelectorNotExists('input[type="password"]');
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'boulanger@example.test']);

        $this->requestReset($client, 'boulanger@example.test');
        $path = $this->pathOf($this->lastEmail());

        $request = $this->em()->getRepository(PasswordResetRequest::class)->findOneBy([]);
        self::assertNotNull($request);
        $this->em()->getConnection()->executeStatement(
            'UPDATE password_reset_request SET expires_at = :past WHERE id = :id',
            ['past' => new \DateTimeImmutable('-1 minute')->format('Y-m-d H:i:s'), 'id' => $request->getId()],
        );

        $client->request('GET', $path);
        $client->followRedirect();

        self::assertResponseStatusCodeSame(410);

        // Posting the form anyway changes nothing.
        $client->request('POST', '/pro/mot-de-passe/nouveau', ['new_password' => ['plainPassword' => 'nouveau-secret']]);
        self::assertResponseStatusCodeSame(410);
    }

    public function testANewerRequestReplacesTheOlderLink(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'boulanger@example.test']);

        $this->requestReset($client, 'boulanger@example.test');
        $first = $this->pathOf($this->lastEmail());
        $this->requestReset($client, 'boulanger@example.test');

        $client->request('GET', $first);
        $client->followRedirect();

        self::assertResponseStatusCodeSame(410);
    }

    public function testAForgedTokenIsRefused(): void
    {
        $client = self::createClient();

        $client->request('GET', '/pro/mot-de-passe/nouveau/'.str_repeat('a', 43));
        $client->followRedirect();
        self::assertResponseStatusCodeSame(410);

        $client->request('GET', '/pro/mot-de-passe/nouveau');
        self::assertResponseStatusCodeSame(410);
    }

    public function testRequestsAreRateLimited(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'boulanger@example.test']);
        // The test limiter storage lives in memory, as long as one kernel.
        $client->disableReboot();

        for ($i = 0; $i < 3; ++$i) {
            $this->requestReset($client, 'boulanger@example.test');
            self::assertResponseRedirects('/pro/mot-de-passe-oublie/envoye');
        }

        $this->requestReset($client, 'boulanger@example.test');

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('[role="alert"]', 'beaucoup de demandes');
    }

    private function requestReset(KernelBrowser $client, string $email): void
    {
        $client->request('GET', '/pro/mot-de-passe-oublie');
        $client->submitForm('Recevoir le lien', ['password_forgotten[email]' => $email]);
    }

    private function lastEmail(): Email
    {
        $messages = self::getMailerMessages();
        $email = end($messages);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function pathOf(Email $email): string
    {
        if (1 !== preg_match('#https?://[^/\s]+(/pro/mot-de-passe/nouveau/[A-Za-z0-9_-]+)#', (string) $email->getTextBody(), $matches)) {
            self::fail('The e-mail contains no reset link.');
        }

        return $matches[1];
    }

    private function tokenOf(Email $email): string
    {
        return basename($this->pathOf($email));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
