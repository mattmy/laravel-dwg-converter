<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Mattmy\DwgConverter\DwgConverterServiceProvider;
use Mattmy\DwgConverter\DwgManager;

it('registers its manager, defaults, and publishable configuration', function (): void {
    $published = ServiceProvider::pathsToPublish(
        DwgConverterServiceProvider::class,
        'dwg-converter-config',
    );
    $source = \array_key_first($published);
    if (! \is_string($source)) {
        throw new RuntimeException('The package configuration was not registered for publishing.');
    }
    $destination = \array_values($published)[0] ?? null;
    if (! \is_string($destination)) {
        throw new RuntimeException('The package configuration publish destination is invalid.');
    }

    expect(app(DwgManager::class))->toBeInstanceOf(DwgManager::class)
        ->and(config('dwg-converter.timeout'))->toBe(60)
        ->and($published)->toHaveCount(1)
        ->and(\basename($source))->toBe('dwg-converter.php')
        ->and(\basename($destination))->toBe('dwg-converter.php');
});
