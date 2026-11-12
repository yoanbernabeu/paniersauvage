<?php

declare(strict_types=1);

namespace App\Ordering\Model;

/**
 * Who an order is for, as typed in the order form. Frozen on the order (never copied onto an
 * account someone else may own).
 */
final readonly class CustomerIdentity
{
    public function __construct(
        public ?string $firstName,
        public ?string $lastName,
        public ?string $phone = null,
    ) {
    }
}
