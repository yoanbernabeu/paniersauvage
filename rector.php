<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Review the proposed changes first: vendor/bin/rector process --dry-run
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/config/bundles.php',
        __DIR__.'/config/reference.php',
    ])
    ->withCache(__DIR__.'/var/cache/rector')
    ->withPHPStanConfigs([__DIR__.'/phpstan-rector.neon'])
    ->withParallel()
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    // PHP >= 8.4 is the minimum supported version (production runs 8.5).
    ->withPhpSets(php84: true)
    // Rules picked from the versions installed in composer.lock.
    ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
    ->withAttributesSets(symfony: true, doctrine: true, phpunit: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
    )
    // Needs a dev container: run any bin/console command once beforehand.
    ->withSymfonyContainerXml(__DIR__.'/var/cache/dev/App_KernelDevDebugContainer.xml')
;
