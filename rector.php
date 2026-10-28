<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Rector\StaticCall\DispatchToHelperFunctionsRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/bootstrap/cache',
        // Style preferences this project doesn't follow: it writes app(Foo::class) and Job::dispatch(), and Pint
        // owns how `new` is written.
        AppToResolveRector::class,
        DispatchToHelperFunctionsRector::class,
        NewMethodCallWithoutParenthesesRector::class,
        // Static helpers in Pest files are called through their class name from elsewhere.
        LocallyCalledStaticMethodToNonStaticRector::class,
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
    ])
    ->withImportNames(removeUnusedImports: true);
