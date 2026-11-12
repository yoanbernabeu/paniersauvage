<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Customer\Entity\Customer;
use App\Legal\LegalDocuments;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Service\OrderManager;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Shared\Enum\Actor;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use App\Shop\Entity\ShopInvitation;
use App\Shop\Entity\User;
use App\Shop\Enum\ShopRole;
use App\Shop\Repository\ShopInvitationRepository;
use App\Shop\Repository\UserRepository;
use App\Shop\Team\TeamException;
use App\Shop\Team\TeamManager;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * Several accounts per shop: the owner invites, the member joins, only the owner manages the
 * subscription and the team; one account serves one shop.
 */
final class TeamTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;
    private User $owner;
    private Shop $shop;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->owner = UserFactory::createOne(['firstName' => 'Marie', 'lastName' => 'Brun', 'email' => 'marie@fournil.test']);
        $this->shop = $this->owner->getShop() ?? throw new \LogicException();
        $this->shop->setName('Fournil du Pont');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    public function testTheOwnerInvitesAPersonWhoJoinsAsAMember(): void
    {
        $this->client->loginUser($this->owner, 'producer');
        $crawler = $this->client->request('GET', '/pro/equipe');
        self::assertResponseIsSuccessful();
        self::assertSame(['marie@fournil.test'], $this->members($crawler));
        self::assertSelectorTextContains('[data-team-member="marie@fournil.test"]', 'Propriétaire');

        $this->client->submitForm('Envoyer l\'invitation', ['invitation[email]' => '  Paul@Example.TEST ']);
        self::assertResponseRedirects('/pro/equipe');
        $email = $this->onlyEmail();
        self::assertSame('Marie t\'invite à gérer Fournil du Pont sur Panier Sauvage', $email->getSubject());
        self::assertSame('paul@example.test', $email->getTo()[0]->getAddress());
        self::assertSame('marie@fournil.test', $email->getReplyTo()[0]->getAddress());
        self::assertEmailTextBodyContains($email, 'Ce lien est valable 7 jours et ne sert qu\'une fois.');
        $link = $this->link($email);

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Invitation envoyée à paul@example.test');
        self::assertSelectorTextContains('[data-team-invitation="paul@example.test"]', 'Lien valable jusqu\'au');

        // The invited person, in another browser.
        $guest = $this->guestBrowser();
        $guest->request('GET', $link);
        self::assertResponseRedirects('/pro/invitation');
        $guest->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Rejoins Fournil du Pont');
        self::assertSelectorTextContains('main', 'Marie Brun t\'invite');

        $guest->submitForm('Rejoindre l\'équipe', [
            'invitation_acceptance[firstName]' => 'Paul',
            'invitation_acceptance[lastName]' => 'Arnaud',
            'invitation_acceptance[plainPassword]' => 'pain-chaud-26',
            'invitation_acceptance[acceptTerms]' => '1',
        ]);
        self::assertResponseRedirects('/pro');
        $guest->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Bienvenue dans l\'équipe de Fournil du Pont !');

        $paul = self::getContainer()->get(UserRepository::class)->findOneByEmail('paul@example.test');
        self::assertInstanceOf(User::class, $paul);
        self::assertSame(ShopRole::Member, $paul->getShopRole());
        self::assertSame($this->shop->getId(), $paul->getShop()?->getId());
        self::assertTrue($paul->hasAcceptedTerms(LegalDocuments::TERMS_VERSION));
        self::assertNotContains(User::ROLE_SHOP_OWNER, $paul->getRoles());
        self::assertContains(User::ROLE_SHOP_OWNER, $this->owner->getRoles());

        // The link was used: it never works again.
        $other = $this->guestBrowser();
        $other->request('GET', $link);
        $other->followRedirect();
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('[data-invitation-invalid]', 'valable 7 jours');

        $this->client->loginUser($this->owner, 'producer');
        $crawler = $this->client->request('GET', '/pro/equipe');
        self::assertSame(['marie@fournil.test', 'paul@example.test'], $this->members($crawler));
        self::assertSelectorNotExists('[data-team-invitations]');
    }

    public function testAMemberRunsTheShopButNeitherTheSubscriptionNorTheTeam(): void
    {
        $member = $this->member('paul@example.test');
        $this->client->loginUser($member, 'producer');

        $crawler = $this->client->request('GET', '/pro/ventes');
        self::assertResponseIsSuccessful();
        $labels = $crawler->filter('header nav a')->each(static fn (Crawler $link): string => trim($link->text()));
        self::assertContains('Équipe', $labels);
        self::assertContains('Clients', $labels);
        self::assertNotContains('Abonnement', $labels);

        $this->client->request('GET', '/pro/abonnement');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/pro/abonnement/souscrire');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/pro/abonnement/gerer');
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/pro/equipe');
        self::assertResponseIsSuccessful();
        self::assertSame(['marie@fournil.test', 'paul@example.test'], $this->members($crawler));
        self::assertSelectorNotExists('[data-team-invite]');
        self::assertSelectorNotExists('form[action*="/retirer"]');

        $this->client->request('POST', '/pro/equipe/inviter', ['invitation' => ['email' => 'x@example.test']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/pro/equipe/membres/'.$this->owner->getId().'/retirer');
        self::assertResponseStatusCodeSame(403);
        self::assertEmailCount(0);
    }

    public function testAMemberSeesTheOwnersSubscriptionNotice(): void
    {
        $this->client->loginUser($this->member('paul@example.test'), 'producer');

        $this->client->request('GET', '/pro/ventes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-subscription-alert]', 'Seul le propriétaire de la boutique peut gérer l\'abonnement.');
        self::assertSelectorNotExists('[data-subscription-alert] a[href="/pro/abonnement"]');
    }

    public function testCustomersStillSeeTheOwnerAsTheSeller(): void
    {
        $this->member('paul@example.test', 'Paul', 'Arnaud');
        $sale = SaleFactory::createOne(['shop' => $this->shop]);
        $delivery = SaleDeliveryFactory::createOne(['sale' => $sale]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale]);
        $customer = CustomerFactory::createOne(['email' => 'lea@example.test']);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $crawler = $this->client->request('GET', '/'.$this->shop->getSlug().'/vente/'.$sale->getCode());
        self::assertStringContainsString('Fournil du Pont (Marie Brun)', $crawler->filter('[data-seller-identity]')->text());
        self::assertStringNotContainsString('paul@example.test', $crawler->filter('main')->text());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(OrderManager::class)->placeOrder(
            $entityManager->find(Sale::class, $sale->getId()) ?? throw new \LogicException(),
            $entityManager->find(Customer::class, $customer->getId()) ?? throw new \LogicException(),
            new OrderRequest($entityManager->find(SaleDelivery::class, $delivery->getId()) ?? throw new \LogicException(), [(int) $offer->getId() => Quantity::UNIT]),
            Actor::Producer,
        );
        $email = $this->onlyEmail();
        self::assertSame('marie@fournil.test', $email->getReplyTo()[0]->getAddress());
    }

    public function testAnAddressWithAProducerAccountCannotJoinAnotherShop(): void
    {
        UserFactory::createOne(['email' => 'busy@example.test']);
        $link = $this->invite('busy@example.test');

        $guest = $this->guestBrowser();
        $guest->request('GET', $link);
        $guest->followRedirect();

        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('h1', 'Cette adresse a déjà son espace producteur');
        self::assertSelectorTextContains('[data-invitation-taken]', 'Un compte ne sert qu\'une seule boutique.');
        self::assertSelectorNotExists('form[name="invitation_acceptance"]');
    }

    public function testTheTeamCannotInviteItsOwnMembers(): void
    {
        $this->member('paul@example.test');
        $this->client->loginUser($this->owner, 'producer');
        $this->client->request('GET', '/pro/equipe');

        $this->client->submitForm('Envoyer l\'invitation', ['invitation[email]' => 'PAUL@example.test']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-flash="error"]', 'Cette personne fait déjà partie de ton équipe.');
        self::assertEmailCount(0);

        $this->client->submitForm('Envoyer l\'invitation', ['invitation[email]' => 'pas-une-adresse']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-team-invite]', 'Cette adresse e-mail ne semble pas valide.');
    }

    public function testAnInvitationExpiresAfterSevenDays(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-10 09:00'));
        $link = $this->invite('paul@example.test');

        self::mockTime(new \DateTimeImmutable('2026-11-17 08:59'));
        $guest = $this->guestBrowser();
        $guest->request('GET', $link);
        $guest->followRedirect();
        self::assertResponseIsSuccessful();

        self::mockTime(new \DateTimeImmutable('2026-11-17 09:00'));
        $guest->request('GET', '/pro/invitation');
        self::assertResponseStatusCodeSame(410);
    }

    public function testANewInvitationReplacesThePreviousOne(): void
    {
        $first = $this->invite('paul@example.test');
        $second = $this->invite('paul@example.test');

        self::assertNotSame($first, $second);
        $guest = $this->guestBrowser();
        $guest->request('GET', $first);
        $guest->followRedirect();
        self::assertResponseStatusCodeSame(410);
        $guest->request('GET', $second);
        $guest->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testWithoutTokenOrWithAWrongOneTheLinkIsInvalid(): void
    {
        $this->client->request('GET', '/pro/invitation');
        self::assertResponseStatusCodeSame(410);

        $this->client->request('GET', '/pro/invitation/'.str_repeat('a', 43));
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(410);
    }

    public function testTheAcceptanceFormChecksTheTermsAndThePassword(): void
    {
        $guest = $this->guestBrowser();
        $guest->request('GET', $this->invite('paul@example.test'));
        $guest->followRedirect();

        $guest->submitForm('Rejoindre l\'équipe', [
            'invitation_acceptance[firstName]' => 'Paul',
            'invitation_acceptance[lastName]' => 'Arnaud',
            'invitation_acceptance[plainPassword]' => 'court',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Ton mot de passe doit faire au moins 8 caractères.');
        self::assertSelectorTextContains('main', 'Accepte les conditions d\'utilisation pour continuer.');
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('paul@example.test'));
    }

    public function testTheOwnerCancelsAnInvitation(): void
    {
        $link = $this->invite('paul@example.test');
        $invitation = self::getContainer()->get(ShopInvitationRepository::class)->findOneBy(['email' => 'paul@example.test']);
        self::assertInstanceOf(ShopInvitation::class, $invitation);
        $this->client->loginUser($this->owner, 'producer');
        $this->client->request('GET', '/pro/equipe');

        $this->client->submitForm('Annuler l\'invitation de paul@example.test');

        self::assertResponseRedirects('/pro/equipe');
        $guest = $this->guestBrowser();
        $guest->request('GET', $link);
        $guest->followRedirect();
        self::assertResponseStatusCodeSame(410);
    }

    public function testTheOwnerRemovesAMemberWhoLosesAccessAtOnce(): void
    {
        $paul = $this->member('paul@example.test', 'Paul', 'Arnaud');
        $paulId = (int) $paul->getId();
        $this->client->loginUser($this->owner, 'producer');
        $this->client->request('GET', '/pro/equipe');

        $this->client->submitForm('Retirer Paul Arnaud');

        self::assertResponseRedirects('/pro/equipe');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Paul Arnaud ne fait plus partie de ton équipe');
        self::assertSame(['marie@fournil.test'], $this->members($crawler));
        self::assertNull(self::getContainer()->get(UserRepository::class)->find($paulId));
    }

    public function testTheOwnerCannotBeRemoved(): void
    {
        $this->client->loginUser($this->owner, 'producer');
        $crawler = $this->client->request('GET', '/pro/equipe');
        self::assertCount(0, $crawler->filter('form[action*="/retirer"]'));

        $team = self::getContainer()->get(TeamManager::class);
        try {
            $team->remove($this->shop, $this->owner);
            self::fail('The owner was removed.');
        } catch (TeamException $exception) {
            self::assertSame('Le propriétaire de la boutique ne peut pas être retiré.', $exception->getMessage());
        }

        $this->expectExceptionObject(new TeamException('Cette personne ne fait pas partie de ton équipe.'));
        $team->remove($this->shop, UserFactory::createOne());
    }

    public function testAnotherOwnerReachesNeitherTheMembersNorTheInvitations(): void
    {
        $paul = $this->member('paul@example.test');
        $this->invite('zoe@example.test');
        $invitation = self::getContainer()->get(ShopInvitationRepository::class)->findOneBy(['email' => 'zoe@example.test']);
        $intruder = UserFactory::createOne();
        $this->client->loginUser($intruder, 'producer');

        $crawler = $this->client->request('GET', '/pro/equipe');
        self::assertSame([$intruder->getEmail()], $this->members($crawler));
        self::assertSelectorNotExists('[data-team-invitations]');

        $this->client->request('POST', '/pro/equipe/membres/'.$paul->getId().'/retirer', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/pro/equipe/invitations/'.$invitation?->getId().'/annuler', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(404);
        self::assertNotNull(self::getContainer()->get(UserRepository::class)->find((int) $paul->getId()));
    }

    public function testActionsNeedAValidToken(): void
    {
        $paul = $this->member('paul@example.test');
        $this->client->loginUser($this->owner, 'producer');

        $this->client->request('POST', '/pro/equipe/membres/'.$paul->getId().'/retirer', ['_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheNumberOfPendingInvitationsIsLimited(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->invite(\sprintf('personne%d@example.test', $i));
        }
        $this->client->loginUser($this->owner, 'producer');
        $this->client->request('GET', '/pro/equipe');

        $this->client->submitForm('Envoyer l\'invitation', ['invitation[email]' => 'encore@example.test']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-flash="error"]', 'Tu as déjà 10 invitations en attente');
    }

    public function testTheTeamPagesNeedAProducerExceptTheInvitationLink(): void
    {
        $this->client->request('GET', '/pro/equipe');
        self::assertResponseRedirects('/pro/connexion');

        $this->client->request('GET', '/pro/invitation');
        self::assertResponseStatusCodeSame(410);
    }

    private function invite(string $email): string
    {
        $this->client->loginUser($this->owner, 'producer');
        $this->client->request('GET', '/pro/equipe');
        $this->client->submitForm('Envoyer l\'invitation', ['invitation[email]' => $email]);
        self::assertResponseRedirects('/pro/equipe');

        return $this->link($this->onlyEmail());
    }

    private function member(string $email, string $firstName = 'Paul', string $lastName = 'Arnaud'): User
    {
        return UserFactory::createOne(['email' => $email, 'firstName' => $firstName, 'lastName' => $lastName, 'shop' => $this->shop, 'shopRole' => ShopRole::Member]);
    }

    /**
     * The same browser, signed out (cookies and history forgotten): the invited person's.
     */
    private function guestBrowser(): KernelBrowser
    {
        $this->client->restart();

        return $this->client;
    }

    private function onlyEmail(): Email
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function link(Email $email): string
    {
        self::assertSame(1, preg_match('#http://localhost(/pro/invitation/[A-Za-z0-9_-]+)#', (string) $email->getTextBody(), $matches));

        return $matches[1] ?? throw new \LogicException('No invitation link.');
    }

    /**
     * @return list<string>
     */
    private function members(Crawler $crawler): array
    {
        return $crawler->filter('[data-team-member]')->each(static fn (Crawler $node): string => (string) $node->attr('data-team-member'));
    }
}
