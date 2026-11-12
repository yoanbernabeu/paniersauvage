<?php

declare(strict_types=1);

namespace App\Customer\Event;

/**
 * Dispatched when a customer account is erased (GDPR), inside the erasure transaction and before
 * the customer row is deleted: listeners erase their own copies of the personal data (e.g. the
 * recipient address of the notification logs) so that both commit, or roll back, together.
 */
final readonly class CustomerErased
{
    public function __construct(
        public int $customerId,
        /** The real address, as it was before the erasure; null for a customer without e-mail. */
        public ?string $email,
        /** Placeholder to put in its place. */
        public string $anonymizedEmail,
    ) {
    }
}
