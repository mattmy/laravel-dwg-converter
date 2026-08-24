<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Tests;

use Illuminate\Foundation\Application;
use Mattmy\DwgConverter\DwgConverterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;

/**
 * Boots a minimal Laravel application with the package provider.
 */
abstract class TestCase extends Orchestra
{
    private ?string $temporaryDirectory = null;

    /**
     * Give each test an isolated package temporary root.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = storage_path(
            'framework/dwg-converter-tests/' . \bin2hex(\random_bytes(8)),
        );
        config()->set('dwg-converter.temporary_directory', $this->temporaryDirectory);
    }

    /**
     * Assert that every package-owned workspace was removed.
     */
    #[Override]
    protected function tearDown(): void
    {
        try {
            if ($this->temporaryDirectory !== null && \is_dir($this->temporaryDirectory)) {
                $entries = \scandir($this->temporaryDirectory);

                self::assertSame(['.', '..'], $entries, 'Temporary DWG workspace residue remains.');
                self::assertTrue(\rmdir($this->temporaryDirectory));
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Register the package provider for feature tests.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [DwgConverterServiceProvider::class];
    }
}
