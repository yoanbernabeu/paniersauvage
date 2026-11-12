<?php

declare(strict_types=1);

namespace App\Recurring\EventListener;

use App\Customer\Event\CustomerErased;
use App\Recurring\Entity\RecurringOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An erased account (GDPR) loses its subscriptions, with their address and history (lines and
 * occurrences are deleted by the database cascade). The orders already generated stay, anonymised
 * by App\Customer\Service\CustomerEraser.
 */
final readonly class EraseRecurringOrdersListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[AsEventListener]
    public function __invoke(CustomerErased $event): void
    {
        foreach ($this->entityManager->getRepository(RecurringOrder::class)->findBy(['customer' => $event->customerId]) as $recurringOrder) {
            $this->entityManager->remove($recurringOrder);
        }
    }
}
