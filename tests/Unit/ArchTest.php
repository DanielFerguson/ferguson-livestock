<?php

/**
 * Classes that dev tools built on Laravel Zero, such as Pint and the Cloud CLI, put in the App namespace from vendor.
 *
 * The arch plugin can't inspect classes from vendor, so it counts them as breaking the Laravel preset and then
 * crashes reporting it. They're left out by name. The Cloud CLI's App\Providers\AppServiceProvider shares its name
 * with ours, so ours is left out of the preset too; its name and parent are Laravel's own.
 *
 * @return list<string>
 */
function appClassesFromVendor(): array
{
    /** @var array<string, list<string>> $prefixes */
    $prefixes = require __DIR__.'/../../vendor/composer/autoload_psr4.php';

    $classes = [];

    foreach ($prefixes['App\\'] ?? [] as $directory) {
        if (! str_contains($directory, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR) || ! is_dir($directory)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen($directory) + 1, -strlen('.php'));
                $classes[] = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            }
        }
    }

    return $classes;
}

arch()->preset()->php();

// Filament names panel providers `*PanelProvider`.
arch()->preset()->laravel()->ignoring(['App\Providers\Filament', ...appClassesFromVendor()]);

arch()->preset()->security();
