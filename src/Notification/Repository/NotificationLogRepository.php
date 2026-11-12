<?php

declare(strict_types=1);

namespace App\Notification\Repository;

use App\Customer\Entity\Customer;
use App\Notification\Entity\NotificationLog;
use App\Notification\Enum\NotificationStatus;
use App\Notification\Enum\NotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationLog>
 */
final class NotificationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationLog::class);
    }

    /**
     * Has this message already been logged for this object (and customer)? Useful to avoid double sends.
     */
    public function exists(string $type, string $relatedType, int $relatedId, ?Customer $customer = null): bool
    {
        $criteria = ['type' => $type, 'relatedType' => $relatedType, 'relatedId' => $relatedId];
        if ($customer instanceof Customer) {
            $criteria['customer'] = $customer;
        }

        return $this->count($criteria) > 0;
    }

    /**
     * Was this message successfully handed over for this object and this address? A failed attempt does
     * not count, so it can be retried.
     */
    public function wasSent(NotificationType $type, string $relatedType, int $relatedId, string $recipient): bool
    {
        return $this->count([
            'type' => $type->value,
            'relatedType' => $relatedType,
            'relatedId' => $relatedId,
            'recipient' => $recipient,
            'status' => NotificationStatus::Sent,
        ]) > 0;
    }
}
