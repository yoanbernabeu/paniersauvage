<?php

declare(strict_types=1);

namespace App\Legal\Verification;

/**
 * An establishment as the public company register knows it.
 */
final readonly class CompanyRecord
{
    public function __construct(
        public string $siret,
        public string $companyName,
        /** NAF rev. 2 code of the establishment ("10.71C"), or of the company when it has none. */
        public ?string $nafCode,
        /** Both the company and the establishment are active. */
        public bool $active,
    ) {
    }
}
