<?php

declare(strict_types=1);

namespace App\Recurring\EventListener;

use App\Recurring\Service\RecurringOrderGenerator;
use App\Sale\Event\SaleOpened;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * As soon as a sale opens, its subscribers get their order: before the announcement to the
 * followers (higher priority than App\Notification\EventListener\SaleNotificationListener), so the
 * subscribers are served first. A failure is logged and never blocks the opening of the sale: the
 * periodic RecurringOrderTask catches up.
 */
final readonly class GenerateOnSaleOpenedListener
{
    public function __construct(
        private RecurringOrderGenerator $generator,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(priority: 10)]
    public function __invoke(SaleOpened $event): void
    {
        try {
            $this->generator->generateForSale($event->sale);
        } catch (\Throwable $exception) {
            $this->logger->error('Recurring orders of sale #{id} could not be generated at its opening: {message}', [
                'id' => $event->sale->getId(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
