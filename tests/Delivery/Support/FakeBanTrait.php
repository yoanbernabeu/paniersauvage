<?php

declare(strict_types=1);

namespace App\Tests\Delivery\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Starts and ends each test with a FakeBan that knows no place and answers normally.
 */
trait FakeBanTrait
{
    #[Before]
    #[After]
    public function resetFakeBan(): void
    {
        FakeBan::reset();
    }
}
