<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Mattmy\DwgConverter\Internal\Converter;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\SymfonyProcessRunner;
use Override;

/**
 * Registers the DWG converter's configuration and stateless services.
 */
final class DwgConverterServiceProvider extends ServiceProvider
{
    /**
     * Register package configuration and container bindings.
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/dwg-converter.php', 'dwg-converter');
        $this->app->singleton(ProcessRunner::class, SymfonyProcessRunner::class);
        $this->app->singleton(Converter::class, function (Application $app): Converter {
            $configured = config('dwg-converter');
            $configuration = [];
            if (\is_array($configured)) {
                foreach ($configured as $key => $value) {
                    if (\is_string($key)) {
                        $configuration[$key] = $value;
                    }
                }
            }

            return new Converter(
                $app->make(ProcessRunner::class),
                $configuration,
            );
        });
        $this->app->singleton(DwgManager::class);
    }

    /**
     * Register the publishable package configuration.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/dwg-converter.php' => config_path('dwg-converter.php'),
        ], 'dwg-converter-config');
    }
}
