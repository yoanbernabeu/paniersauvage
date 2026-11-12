<?php

declare(strict_types=1);

namespace App\Sale\Event;

use App\Sale\Entity\Sale;

/**
 * Dispatched inside the cancellation transaction, once the sale is marked cancelled and before the
 * commit: listeners make the dependent changes (the Ordering domain cancels the orders and
 * releases their stock) so that everything is committed, or rolled back, together.
 *
 * Listeners must only write to the database: no e-mail, no external call. Customers are warned
 * after the commit, on SaleCancelled.
 */
final readonly class SaleCancelling
{
    public function __construct(
        public Sale $sale,
    ) {
    }
}
