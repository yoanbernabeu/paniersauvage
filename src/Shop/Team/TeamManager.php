<?php

declare(strict_types=1);

namespace App\Shop\Team;

use App\Legal\LegalDocuments;
use App\Shop\Entity\Shop;
use App\Shop\Entity\ShopInvitation;
use App\Shop\Entity\User;
use App\Shop\Enum\ShopRole;
use App\Shop\Repository\ShopInvitationRepository;
use App\Shop\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Several accounts per shop: the owner invites a person by e-mail (a random token, only its hash
 * is stored, valid ShopInvitation::LIFETIME_DAYS days, usable once), the person creates their
 * password and joins as a member. One account serves one shop: an address that already has a
 * producer account cannot join another shop.
 *
 * The invitation is an authentication e-mail, sent directly like the password reset links.
 */
final readonly class TeamManager
{
    /** Unanswered invitations a shop may have at once (each one sends an e-mail). */
    public const int MAX_PENDING = 10;

    public function __construct(
        private ShopInvitationRepository $invitations,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private UrlGeneratorInterface $urlGenerator,
        private MailerInterface $mailer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Sends a fresh link to the address; an older pending link to the same address stops working.
     *
     * @throws TeamException when the person is already in the team, or too many invitations wait
     */
    public function invite(Shop $shop, User $owner, string $email): ShopInvitation
    {
        $now = $this->clock->now();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        // The invitation normalises the address (lower case, no spaces): every lookup uses its copy.
        $invitation = new ShopInvitation($shop, $email, $this->hash($token), $now, $now->modify(\sprintf('+%d days', ShopInvitation::LIFETIME_DAYS)), $owner);
        $email = $invitation->getEmail();

        if ($this->users->findOneByEmail($email)?->getShop() === $shop) {
            throw new TeamException('Cette personne fait déjà partie de ton équipe.');
        }

        $this->invitations->deletePendingFor($shop, $email);
        $this->invitations->deleteExpired($now->modify('-1 day'));
        if (\count($this->invitations->findPending($shop, $now)) >= self::MAX_PENDING) {
            throw new TeamException(\sprintf('Tu as déjà %d invitations en attente : annule celles qui ne servent plus.', self::MAX_PENDING));
        }

        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $subject = \sprintf('%s t\'invite à gérer %s sur Panier Sauvage', $owner->getFirstName(), $shop->getName());
        $this->mailer->send(
            new TemplatedEmail()
                ->to($email)
                ->replyTo($owner->getEmail())
                ->subject($subject)
                ->htmlTemplate('shop/email/team_invitation.html.twig')
                ->textTemplate('shop/email/team_invitation.txt.twig')
                ->context([
                    'subject' => $subject,
                    'url' => $this->urlGenerator->generate('pro_team_invitation_token', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
                    'shop_name' => $shop->getName(),
                    'owner_name' => $owner->getFullName(),
                    'days' => ShopInvitation::LIFETIME_DAYS,
                ]),
        );

        return $invitation;
    }

    /**
     * The invitation matching this token, or null when it is unknown, expired or already used.
     */
    public function findUsable(string $token): ?ShopInvitation
    {
        if ('' === $token) {
            return null;
        }

        $invitation = $this->invitations->findOneBy(['tokenHash' => $this->hash($token)]);

        return $invitation instanceof ShopInvitation && $invitation->isUsable($this->clock->now()) ? $invitation : null;
    }

    /**
     * The producer account that already uses the invited address, if any (one account, one shop).
     */
    public function existingAccount(ShopInvitation $invitation): ?User
    {
        return $this->users->findOneByEmail($invitation->getEmail());
    }

    /**
     * Creates the member's account, with the terms accepted, and burns the invitation.
     *
     * @throws TeamException when the address got an account meanwhile, or the link was used
     */
    public function accept(ShopInvitation $invitation, string $firstName, string $lastName, string $plainPassword): User
    {
        $now = $this->clock->now();

        if (!$invitation->isUsable($now)) {
            throw new TeamException('Ce lien d\'invitation ne marche plus.');
        }
        if ($this->existingAccount($invitation) instanceof User) {
            throw new TeamException('Un espace producteur existe déjà avec cette adresse e-mail.');
        }

        $user = new User($invitation->getEmail())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setShopRole(ShopRole::Member)
            ->acceptTerms(LegalDocuments::TERMS_VERSION, $now);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $invitation->getShop()->addUser($user);
        $invitation->markAccepted($now);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function cancel(ShopInvitation $invitation): void
    {
        $this->entityManager->remove($invitation);
        $this->entityManager->flush();
    }

    /**
     * Deletes the member's account: they lose access at once (their session no longer finds them).
     * The owner cannot be removed.
     *
     * @throws TeamException when the account is the owner, or not in this shop
     */
    public function remove(Shop $shop, User $member): void
    {
        if ($member->getShop() !== $shop) {
            throw new TeamException('Cette personne ne fait pas partie de ton équipe.');
        }
        if ($member->isShopOwner()) {
            throw new TeamException('Le propriétaire de la boutique ne peut pas être retiré.');
        }

        $shop->getUsers()->removeElement($member);
        $this->entityManager->remove($member);
        $this->entityManager->flush();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
