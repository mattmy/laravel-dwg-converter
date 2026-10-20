<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;
use Mattmy\DwgConverter\Tests\Fakes\ThumbnailFixture;

it('maps invalid published configuration to an environment failure', function (
    string $key,
    mixed $value,
): void {
    config()->set("dwg-converter.{$key}", $value);

    expect(fn () => Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract())
        ->toThrow(LibreDwgUnavailable::class, 'invalid_configuration');
})->with([
    'timeout type' => ['timeout', 'fast'],
    'timeout range' => ['timeout', 0],
    'input limit type' => ['max_input_bytes', 'large'],
    'output limit type' => ['max_output_bytes', 1.5],
    'temporary root' => ['temporary_directory', 'relative/path'],
    'NUL temporary root' => ['temporary_directory', \sys_get_temp_dir() . "/dwg\0root"],
    'foreign absolute temporary root' => [
        'temporary_directory',
        \DIRECTORY_SEPARATOR === '\\' ? '/tmp/dwg-converter' : 'C:\\temp\\dwg-converter',
    ],
    'executables shape' => ['executables', 'dwgbmp'],
]);

it('checks only the executable needed by the selected operation', function (): void {
    config()->set('dwg-converter.executables.imagemagick', '');
    $runner = FakeProcessRunner::writesFile(
        'input.png',
        ThumbnailFixture::png(),
    );
    app()->instance(ProcessRunner::class, $runner);

    expect(Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract()->output())
        ->toStartWith("\x89PNG");
});

it('rejects an existing file used as the temporary directory before running a tool', function (): void {
    $path = \tempnam(\sys_get_temp_dir(), 'dwg-invalid-root-');
    if ($path === false) {
        throw new RuntimeException('Unable to create the configuration fixture.');
    }
    $runner = FakeProcessRunner::writesFile('output.dxf', 'unused');
    app()->instance(ProcessRunner::class, $runner);
    config()->set('dwg-converter.temporary_directory', $path);

    try {
        expect(fn () => Dwg::toDxf(DwgBinary::from('AC1032 drawing'))->convert())
            ->toThrow(LibreDwgUnavailable::class, 'invalid_configuration')
            ->and($runner->commands)->toBe([]);
    } finally {
        \unlink($path);
    }
});

it('maps a temporary root beneath an existing file to an environment failure', function (): void {
    $path = \tempnam(\sys_get_temp_dir(), 'dwg-root-parent-');
    if ($path === false) {
        throw new RuntimeException('Unable to create the configuration fixture.');
    }
    $runner = FakeProcessRunner::writesFile('output.dxf', 'unused');
    app()->instance(ProcessRunner::class, $runner);
    config()->set('dwg-converter.temporary_directory', $path . '/child');

    try {
        expect(fn () => Dwg::toDxf(DwgBinary::from('AC1032 drawing'))->convert())
            ->toThrow(LibreDwgUnavailable::class, 'invalid_configuration')
            ->and($runner->commands)->toBe([]);
    } finally {
        \unlink($path);
    }
});

it('preserves an executable availability failure', function (): void {
    $failure = new LibreDwgUnavailable('executable_not_found', ['operation' => 'thumbnail']);
    $runner = new FakeProcessRunner(
        static function (
            array $_command,
            Workspace $_workspace,
            float $_timeout,
            string $_operation,
        ): void {},
        $failure,
    );
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract())
        ->toThrow($failure);
});

it('preserves a stable process failure', function (): void {
    $runner = new FakeProcessRunner(static function (
        array $_command,
        Workspace $_workspace,
        float $_timeout,
        string $_operation,
    ): void {
        throw new DwgOperationFailed('process_failed', [
            'operation' => 'thumbnail',
            'exit_code' => 1,
        ]);
    });
    app()->instance(ProcessRunner::class, $runner);

    try {
        Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract();
    } catch (DwgOperationFailed $failure) {
        expect($failure->reason())->toBe('process_failed')
            ->and($failure->context())->toBe([
                'operation' => 'thumbnail',
                'exit_code' => 1,
            ]);

        return;
    }

    throw new RuntimeException('The process failure was not thrown.');
});

it('rejects a source larger than the configured input limit', function (): void {
    config()->set('dwg-converter.max_input_bytes', 6);

    expect(fn () => Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract())
        ->toThrow(InvalidDwg::class, 'input_too_large');
});
