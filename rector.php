<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodingStyle\Rector\ClassMethod\MakeInheritedMethodVisibilitySameAsParentRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withComposerBased(laravel: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
        codingStyle: true,
    )
    ->withPhpSets()
    ->withSkip([
        // Widening a protected parent method to public is intentional here;
        // the project's Pest arch "strict" preset forbids protected methods.
        MakeInheritedMethodVisibilitySameAsParentRector::class,
        // Filament pages keep private static `actor()` guard helpers so tests can
        // invoke them via ReflectionMethod::invoke(null) to assert the unauthenticated
        // LogicException without constructing a full page instance.
        LocallyCalledStaticMethodToNonStaticRector::class,
    ]);
