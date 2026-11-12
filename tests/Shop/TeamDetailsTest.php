<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Shop\Entity\Shop;
use App\Shop\Entity\ShopInvitation;
use App\Shop\Entity\User;
use App\Shop\Enum\ShopRole;
use App\Shop\Form\InvitationAcceptanceType;
use App\Shop\Form\InvitationType;
use App\Shop\Form\Model\InvitationAcceptanceData;
use App\Shop\Form\Model\InvitationData;
use App\Shop\Form\ShopSettingsType;
use App\Shop\Repository\ShopInvitationRepository;
use App\Shop\Team\TeamManager;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * The details of the team: invitations stored safely, forms, housekeeping.
 */
final class TeamDetailsTest extends WebTestCase
{
    use Factories;

    public function testAnInvitationKeepsANormalisedAddressAndSafeColumns(): void
    {
        self::bootKernel();
        $shop = new Shop('Fournil', 'fournil');
        $invitation = new ShopInvitation($shop, '  Paul.Émile@Example.TEST ', 'hash', new \DateTimeImmutable('2026-11-10 09:00'), new \DateTimeImmutable('2026-11-17 09:00'));

        self::assertSame('paul.émile@example.test', $invitation->getEmail());
        self::assertNull($invitation->getInvitedBy());
        self::assertTrue($invitation->isUsable(new \DateTimeImmutable('2026-11-17 08:59')));
        self::assertFalse($invitation->isUsable(new \DateTimeImmutable('2026-11-17 09:00')));
        self::assertFalse($invitation->markAccepted(new \DateTimeImmutable('2026-11-11'))->isUsable(new \DateTimeImmutable('2026-11-12')));

        $metadata = self::getContainer()->get(EntityManagerInterface::class)->getClassMetadata(ShopInvitation::class);
        self::assertInstanceOf(ClassMetadata::class, $metadata);
        self::assertSame(64, $metadata->getFieldMapping('tokenHash')->length);
        $shop = $metadata->getAssociationMapping('shop');
        self::assertInstanceOf(ManyToOneAssociationMapping::class, $shop);
        self::assertFalse($shop->joinColumns[0]->nullable);
        self::assertSame('CASCADE', $shop->joinColumns[0]->onDelete);
        $invitedBy = $metadata->getAssociationMapping('invitedBy');
        self::assertInstanceOf(ManyToOneAssociationMapping::class, $invitedBy);
        self::assertTrue($invitedBy->joinColumns[0]->nullable);
        self::assertSame('SET NULL', $invitedBy->joinColumns[0]->onDelete);
    }

    public function testTheLinkCarriesALongRandomTokenAndOnlyItsHashIsStored(): void
    {
        self::bootKernel();
        $owner = UserFactory::createOne();
        $team = self::getContainer()->get(TeamManager::class);

        $invitation = $team->invite($this->shopOf($owner), $owner, 'Paul@Example.test');

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(1, preg_match('#/pro/invitation/([A-Za-z0-9_-]+)#', (string) $email->getTextBody(), $matches));
        $token = $matches[1] ?? '';
        self::assertSame(43, \strlen($token), '32 random bytes, base64url.');
        self::assertSame(hash('sha256', $token), $invitation->getTokenHash());
        self::assertSame('paul@example.test', $invitation->getEmail());
        self::assertSame($invitation, $team->findUsable($token));
        self::assertNull($team->findUsable(''));
        self::assertNull($team->findUsable('x'.$token));
    }

    public function testInvitingCleansUpTheLongExpiredInvitations(): void
    {
        self::bootKernel();
        $owner = UserFactory::createOne();
        $shop = $this->shopOf($owner);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $old = new ShopInvitation($shop, 'old@example.test', str_repeat('a', 64), new \DateTimeImmutable('-20 days'), new \DateTimeImmutable('-13 days'), $owner);
        $recent = new ShopInvitation($shop, 'recent@example.test', str_repeat('b', 64), new \DateTimeImmutable('-7 days 12 hours'), new \DateTimeImmutable('-12 hours'), $owner);
        $entityManager->persist($old);
        $entityManager->persist($recent);
        $entityManager->flush();

        self::getContainer()->get(TeamManager::class)->invite($shop, $owner, 'new@example.test');

        $emails = array_map(static fn (ShopInvitation $invitation): string => $invitation->getEmail(), self::getContainer()->get(ShopInvitationRepository::class)->findBy(['shop' => $shop]));
        sort($emails);
        self::assertSame(['new@example.test', 'recent@example.test'], $emails, 'Expired for more than a day: deleted.');
    }

    public function testAnAcceptedInvitationCannotBeCancelled(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne();
        $shop = $this->shopOf($owner);
        $accepted = new ShopInvitation($shop, 'paul@example.test', str_repeat('c', 64), new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+6 days'), $owner);
        $accepted->markAccepted(new \DateTimeImmutable());
        $pending = new ShopInvitation($shop, 'zoe@example.test', str_repeat('d', 64), new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+6 days'), $owner);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($accepted);
        $entityManager->persist($pending);
        $entityManager->flush();
        $client->loginUser($owner, 'producer');

        $client->request('POST', '/pro/equipe/invitations/'.$accepted->getId().'/annuler', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/pro/equipe/invitations/'.$pending->getId().'/annuler', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403, 'Its own pending invitation: the token is checked.');

        $client->request('GET', '/pro/equipe');
        $client->submitForm('Annuler l\'invitation de zoe@example.test');
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Invitation annulée : le lien ne marche plus.');
        self::assertSelectorNotExists('[data-team-invitations]');
    }

    public function testTheMemberSignsInWithThePasswordTheyChose(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne();
        $client->loginUser($owner, 'producer');
        $client->request('GET', '/pro/equipe');
        $client->submitForm('Envoyer l\'invitation', ['invitation[email]' => 'paul@example.test']);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(1, preg_match('#http://localhost(/pro/invitation/[A-Za-z0-9_-]+)#', (string) $email->getTextBody(), $matches));

        $client->restart();
        $client->request('GET', $matches[1] ?? '');
        $crawler = $client->followRedirect();
        self::assertSame('given-name', $crawler->filter('#invitation_acceptance_firstName')->attr('autocomplete'));
        self::assertSame('autofocus', $crawler->filter('#invitation_acceptance_firstName')->attr('autofocus'));
        self::assertSame('family-name', $crawler->filter('#invitation_acceptance_lastName')->attr('autocomplete'));
        self::assertSame('new-password', $crawler->filter('#invitation_acceptance_plainPassword')->attr('autocomplete'));
        self::assertSame(['Prénom', 'Nom', 'Mot de passe'], $crawler->filter('label[for^="invitation_acceptance_"]')->slice(0, 3)->each(static fn ($label): string => trim($label->text())));
        $client->submitForm('Rejoindre l\'équipe', [
            'invitation_acceptance[firstName]' => 'Paul',
            'invitation_acceptance[lastName]' => 'Arnaud',
            'invitation_acceptance[plainPassword]' => 'pain-chaud-26',
            'invitation_acceptance[acceptTerms]' => '1',
        ]);

        $client->restart();
        $client->request('GET', '/pro/connexion');
        $client->submitForm('Me connecter', ['_username' => 'paul@example.test', '_password' => 'pain-chaud-26']);
        self::assertResponseRedirects('/pro');
    }

    public function testRemovingAMemberTakesThemOutOfTheShop(): void
    {
        self::bootKernel();
        $owner = UserFactory::createOne();
        $shop = $this->shopOf($owner);
        $member = UserFactory::createOne(['shop' => $shop, 'shopRole' => ShopRole::Member]);
        $shop->addUser($owner)->addUser($member);

        self::getContainer()->get(TeamManager::class)->remove($shop, $member);

        self::assertSame([$owner], $shop->getUsers()->getValues());
        self::assertSame($owner, $shop->getOwner());
    }

    public function testTheOwnerIsTheSellerEvenWhenAMemberIsOlder(): void
    {
        $shop = new Shop('Fournil', 'fournil');
        self::assertNull($shop->getOwner());

        $member = new User('member@example.test')->setShopRole(ShopRole::Member);
        $owner = new User('owner@example.test');
        $shop->addUser($member);
        self::assertSame($member, $shop->getOwner(), 'Without owner: the oldest account.');
        self::assertSame('member@example.test', $shop->publicEmail());

        $shop->addUser($owner);
        self::assertSame($owner, $shop->getOwner());
        self::assertSame('owner@example.test', $shop->publicEmail());
        self::assertSame('contact@example.test', $shop->setContactEmail('contact@example.test')->publicEmail());
        self::assertContains(User::ROLE_SHOP_OWNER, $owner->getRoles());
        self::assertSame([User::ROLE_PRODUCER], $member->getRoles());
    }

    public function testTheFormsOfTheTeamAndOfTheNewSettings(): void
    {
        self::bootKernel();
        $forms = self::getContainer()->get(FormFactoryInterface::class);

        $invitation = $forms->create(InvitationType::class);
        self::assertSame(InvitationData::class, $invitation->getConfig()->getDataClass());
        self::assertSame('Adresse e-mail', $invitation->get('email')->getConfig()->getOption('label'));
        self::assertSame(['autocomplete' => 'off', 'inputmode' => 'email'], $invitation->get('email')->getConfig()->getOption('attr'));

        self::assertSame(InvitationAcceptanceData::class, $forms->create(InvitationAcceptanceType::class)->getConfig()->getDataClass());

        $settings = $forms->create(ShopSettingsType::class, new Shop('Fournil', 'fournil'));
        self::assertSame('Montrer d\'autres producteurs près de mes points de retrait', $settings->get('crossDiscoveryEnabled')->getConfig()->getOption('label'));
        $cap = $settings->get('firstOrderCapCents')->getConfig();
        self::assertSame('Plafond d\'une première commande (€)', $cap->getOption('label'));
        self::assertFalse($cap->getOption('required'));
        self::assertFalse($cap->getOption('currency'));
        self::assertSame(['inputmode' => 'decimal', 'placeholder' => '30'], $cap->getOption('attr'));
        $deposit = $settings->get('newCustomerDeposit')->getConfig();
        self::assertFalse($deposit->getOption('required'));
        self::assertSame(['placeholder' => '5 € par Wero à la commande', 'maxlength' => 200], $deposit->getOption('attr'));
    }

    private function shopOf(User $producer): Shop
    {
        return $producer->getShop() ?? throw new \LogicException('No shop.');
    }
}
