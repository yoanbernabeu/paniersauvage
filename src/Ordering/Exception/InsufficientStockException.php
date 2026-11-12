<?php

declare(strict_types=1);

namespace App\Ordering\Exception;

/**
 * Thrown by ReservationService when a requested quantity exceeds what is left. Nothing has been
 * reserved when it is thrown: the whole request is refused.
 */
final class InsufficientStockException extends \DomainException
{
    /**
     * @param non-empty-list<StockShortage> $shortages
     */
    public function __construct(
        private readonly array $shortages,
    ) {
        $parts = array_map(
            static fn (StockShortage $shortage): string => \sprintf('"%s" (%d requested, %d left)', $shortage->label, $shortage->requested, $shortage->remaining),
            $shortages,
        );

        parent::__construct('Not enough stock: '.implode(', ', $parts).'.');
    }

    /**
     * @return non-empty-list<StockShortage>
     */
    public function getShortages(): array
    {
        return $this->shortages;
    }

    public function getShortageFor(int $offerId): ?StockShortage
    {
        foreach ($this->shortages as $shortage) {
            if ($shortage->offerId === $offerId) {
                return $shortage;
            }
        }

        return null;
    }
}
