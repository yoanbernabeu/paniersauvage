<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

require __DIR__.'/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__.'/../.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Same timezone as App\Kernel::boot(), for unit tests that never boot the kernel.
date_default_timezone_set('Europe/Paris');

// The "app" cache pool lives on disk and outlives a test run: the rate limiters keep their counters
// there (login throttling, sign-ups…), so one run could block the producer logins of the next.
// Each run starts from an empty pool — except under Infection, whose processes share the cache.
if (false === getenv('INFECTION') && false === getenv('TEST_TOKEN')) {
    new Filesystem()->remove(__DIR__.'/../var/cache/test/pools/app');
}
