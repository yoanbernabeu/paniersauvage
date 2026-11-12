<?php

declare(strict_types=1);

namespace App\Customer\EventSubscriber;

use App\Customer\Entity\Customer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Records every customer sign-in. `lastLoginAt` is part of the magic-link signature: changing it
 * makes every link sent before unusable, the one just followed included (single-use links).
 */
final readonly class LastLoginSubscriber
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $customer = $event->getUser();

        if ('main' !== $event->getFirewallName() || !$customer instanceof Customer) {
            return;
        }

        // Truncated to the second, like the column: the signature must not change once reloaded.
        // Never the same value twice, even for two sign-ins within the same second.
        $now = $this->clock->now();
        $now = $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
        $previous = $customer->getLastLoginAt();

        if ($previous instanceof \DateTimeImmutable && $now <= $previous) {
            $now = $previous->modify('+1 second');
        }

        $customer->setLastLoginAt($now);
        $this->entityManager->flush();
    }
}
