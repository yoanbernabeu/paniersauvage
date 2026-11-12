<?php

declare(strict_types=1);

namespace App\Legal\Verification;

/**
 * The company register did not answer (network, timeout, HTTP error, unexpected body).
 */
final class CompanyRegistryUnavailableException extends \RuntimeException
{
}
