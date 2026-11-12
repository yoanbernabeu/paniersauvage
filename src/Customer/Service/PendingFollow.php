<?php

declare(strict_types=1);

namespace App\Customer\Service;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Remembers, in the visitor's session, the shop an anonymous visitor asked to follow; the follow is
 * applied once they are signed in (same browser only: the e-mail consent never leaves the session
 * of the person who gave it).
 */
final class PendingFollow
{
    private const string SESSION_KEY = 'customer.pending_follow';

    public function remember(SessionInterface $session, string $slug, bool $emailConsent): void
    {
        $session->set(self::SESSION_KEY, ['slug' => $slug, 'consent' => $emailConsent]);
    }

    /**
     * Returns the pending follow and forgets it.
     *
     * @return array{slug: string, consent: bool}|null
     */
    public function take(SessionInterface $session): ?array
    {
        $pending = $session->remove(self::SESSION_KEY);

        if (!\is_array($pending) || !\is_string($pending['slug'] ?? null)) {
            return null;
        }

        return ['slug' => $pending['slug'], 'consent' => true === ($pending['consent'] ?? false)];
    }
}
