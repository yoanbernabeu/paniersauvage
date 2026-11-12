<?php

declare(strict_types=1);

namespace App\Tests\Legal\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Starts and ends each test with a FakeCompanyRegistry that knows no establishment and answers normally.
 */
trait FakeCompanyRegistryTrait
{
    #[Before]
    #[After]
    public function resetFakeCompanyRegistry(): void
    {
        FakeCompanyRegistry::reset();
    }
}
