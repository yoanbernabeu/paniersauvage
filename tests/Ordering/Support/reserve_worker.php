<?php

declare(strict_types=1);

/*
 * Worker of ReservationConcurrencyTest: a separate PHP process, hence a separate PostgreSQL
 * connection, that tries to reserve stock at a given instant.
 *
 * Usage: php reserve_worker.php <saleOfferId> <quantity in thousandths> <startAtUnixTimestamp>
 * Prints "RESERVED" or "REFUSED" (anything else is a crash).
 */

use App\Kernel;
use App\Ordering\Exception\InsufficientStockException;
use App\Ordering\Service\ReservationService;
use App\Sale\Entity\SaleOffer;

require dirname(__DIR__, 2).'/bootstrap.php';

$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments) || !isset($arguments[1], $arguments[2], $arguments[3]) || !is_numeric($arguments[1]) || !is_numeric($arguments[2]) || !is_numeric($arguments[3])) {
    fwrite(\STDERR, "Usage: reserve_worker.php <saleOfferId> <quantity> <startAt>\n");
    exit(2);
}

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer();

$offer = $container->get('doctrine')->getManager()->find(SaleOffer::class, (int) $arguments[1])
    ?? throw new LogicException('Unknown sale offer.');

// Every worker fires at the same instant to maximise the overlap.
$startAt = (float) $arguments[3];
if ($startAt > microtime(true)) {
    time_sleep_until($startAt);
}

try {
    $container->get(ReservationService::class)->reserve($offer, (int) $arguments[2]);
    echo 'RESERVED';
} catch (InsufficientStockException) {
    echo 'REFUSED';
}
