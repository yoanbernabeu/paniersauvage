<?php

declare(strict_types=1);

namespace App\Sale\Service;

use App\Sale\Entity\Sale;
use App\Sale\Repository\SaleRepository;
use App\Shared\Service\RandomCode;

/**
 * Generates the short, unique, easy-to-dictate code of a sale (6 characters, no 0/O, 1/I/L).
 */
final readonly class SaleCodeGenerator
{
    private const int MAX_ATTEMPTS = 20;

    public function __construct(
        private SaleRepository $sales,
    ) {
    }

    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $code = RandomCode::generate(Sale::CODE_LENGTH);

            if (!$this->sales->codeExists($code)) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique sale code.');
    }
}
