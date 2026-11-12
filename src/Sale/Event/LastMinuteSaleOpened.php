<?php

declare(strict_types=1);

namespace App\Sale\Event;

use App\Sale\Entity\Sale;

/**
 * Dispatched when the producer opens the last-minute sale of a closed sale (SPEC §21, lot F): the
 * followers who consented to e-mails, and have not ordered in this sale, are told once.
 */
final readonly class LastMinuteSaleOpened
{
    public function __construct(
        public Sale $sale,
    ) {
    }
}
