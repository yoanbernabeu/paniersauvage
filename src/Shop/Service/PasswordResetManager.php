<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Shop\Entity\PasswordResetRequest;
use App\Shop\Entity\User;
use App\Shop\Repository\PasswordResetRequestRepository;
use App\Shop\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Forgotten password" for producers: a random token (only its hash is stored), valid one hour,
 * usable once. An authentication e-mail, so it is sent directly, like the customers' magic links.
 */
final readonly class PasswordResetManager
{
    public const int LIFETIME_MINUTES = 60;

    public function __construct(
        private UserRepository $users,
        private PasswordResetRequestRepository $requests,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private UrlGeneratorInterface $urlGenerator,
        private MailerInterface $mailer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Sends a reset link when the address belongs to a producer; does nothing otherwise, so that the
     * caller answers the same way in both cases.
     */
    public function request(string $email): void
    {
        $user = $this->users->findOneByEmail($email);

        if (!$user instanceof User) {
            return;
        }

        $now = $this->clock->now();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // Only the latest link works.
        $this->requests->invalidatePending($user, $now);
        $this->requests->deleteExpired($now->modify('-1 day'));
        $this->entityManager->persist(new PasswordResetRequest(
            $user,
            $this->hash($token),
            $now,
            $now->modify(\sprintf('+%d minutes', self::LIFETIME_MINUTES)),
        ));
        $this->entityManager->flush();

        $subject = 'Choisis un nouveau mot de passe';

        $this->mailer->send(
            new TemplatedEmail()
                ->to($user->getEmail())
                ->subject($subject)
                ->htmlTemplate('shop/email/password_reset.html.twig')
                ->textTemplate('shop/email/password_reset.txt.twig')
                ->context([
                    'subject' => $subject,
                    'url' => $this->urlGenerator->generate('pro_password_reset_token', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
                    'first_name' => $user->getFirstName(),
                    'minutes' => self::LIFETIME_MINUTES,
                ]),
        );
    }

    /**
     * The pending request matching this token, or null when it is unknown, expired or already used.
     */
    public function findUsable(string $token): ?PasswordResetRequest
    {
        if ('' === $token) {
            return null;
        }

        $request = $this->requests->findOneByTokenHash($this->hash($token));

        return $request instanceof PasswordResetRequest && $request->isUsable($this->clock->now()) ? $request : null;
    }

    public function reset(PasswordResetRequest $request, string $plainPassword): User
    {
        $now = $this->clock->now();

        if (!$request->isUsable($now)) {
            throw new \LogicException('This password reset request can no longer be used.');
        }

        $user = $request->getUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $request->markUsed($now);
        $this->entityManager->flush();
        $this->requests->invalidatePending($user, $now);

        return $user;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
