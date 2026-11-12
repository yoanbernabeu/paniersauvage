<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DoctrineMappingTest extends KernelTestCase
{
    public function testMappingIsValidAndDatabaseIsInSync(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('doctrine:schema:validate'));

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
    }
}
