<?php

declare(strict_types=1);

namespace App\Notification\EventListener;

use App\Customer\Event\CustomerErased;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An erased customer account leaves no real e-mail address in the notification logs (GDPR).
 *
 * Runs inside the erasure transaction, before the customer row is deleted (which sets the logs'
 * customer to null): the logs are still found by their customer.
 */
final readonly class AnonymizeNotificationLogsListener
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[AsEventListener]
    public function __invoke(CustomerErased $event): void
    {
        $this->connection->executeStatement(
            'UPDATE notification_log SET recipient = :anonymized WHERE customer_id = :customer',
            ['anonymized' => $event->anonymizedEmail, 'customer' => $event->customerId],
        );
    }
}
