<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Ordering\Repository\OrderRepository;
use App\Shared\Service\RandomCode;

/**
 * Generates the unique public reference of an order, e.g. "PS-7KQ3-M9TA".
 */
final readonly class OrderReferenceGenerator
{
    public const string PATTERN = '/^PS-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}$/';

    private const int MAX_ATTEMPTS = 20;

    public function __construct(
        private OrderRepository $orders,
    ) {
    }

    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $reference = \sprintf('PS-%s-%s', RandomCode::generate(4), RandomCode::generate(4));

            if (!$this->orders->referenceExists($reference)) {
                return $reference;
            }
        }

        throw new \RuntimeException('Unable to generate a unique order reference.');
    }
}
