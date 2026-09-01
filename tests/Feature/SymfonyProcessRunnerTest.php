<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Internal\SymfonyProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;

/**
 * Create a platform-native executable that returns controlled probe output.
 */
function capabilityProbeExecutable(string $version, string $help): string
{
    $path = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dwg-capability-' . \bin2hex(\random_bytes(8));
    if (PHP_OS_FAMILY === 'Windows') {
        $path .= '.cmd';
        $contents = "@echo off\r\n"
            . "if \"%1\"==\"--version\" echo {$version}\r\n"
            . "if \"%1\"==\"--help\" echo {$help}\r\n";
    } else {
        $contents = "#!/bin/sh\n"
            . "if [ \"\$1\" = \"--version\" ]; then echo '{$version}'; fi\n"
            . "if [ \"\$1\" = \"--help\" ]; then echo '{$help}'; fi\n";
    }

    if (\file_put_contents($path, $contents) === false || (PHP_OS_FAMILY !== 'Windows' && ! \chmod($path, 0700))) {
        throw new RuntimeException('Unable to create the capability probe executable.');
    }

    return $path;
}

it('rejects an executable that is not the selected LibreDWG tool', function (): void {
    expect(fn () => (new SymfonyProcessRunner())->assertAvailable(PHP_BINARY, 'dxf'))
        ->toThrow(LibreDwgUnavailable::class, 'unsupported_tool_capability');
});

it('accepts a tool only when its required CLI shape is present', function (
    string $operation,
    string $tool,
    string $version,
    string $help,
): void {
    $executable = capabilityProbeExecutable($version, $help);

    try {
        (new SymfonyProcessRunner())->assertAvailable($executable, $operation, $tool, $tool);

        expect(true)->toBeTrue();
    } finally {
        \unlink($executable);
    }
})->with([
    'dwgbmp' => ['thumbnail', 'dwgbmp', 'dwgbmp 0.14', 'Usage: dwgbmp DWGFILE'],
    'dwg2dxf' => ['dxf', 'dwg2dxf', 'dwg2dxf 0.14', 'Usage: dwg2dxf --as version -o outfile DWGFILE'],
    'dwgread' => ['json', 'dwgread', 'dwgread 0.14', 'Usage: dwgread --format fmt JSON -o outfile DWGFILE'],
    'LibreOffice' => ['image', 'libreoffice', 'LibreOffice 7.4.0', 'Usage: soffice --headless --convert-to fmt --outdir dir'],
    'ImageMagick' => ['image', 'imagemagick', 'Version: ImageMagick 7.0.0', 'Usage: magick -fuzz value -trim -alpha value'],
]);

it('rejects a named tool that lacks its required CLI shape', function (): void {
    $executable = capabilityProbeExecutable('dwgread 0.14', 'Usage: dwgread DWGFILE');

    try {
        expect(fn () => (new SymfonyProcessRunner())->assertAvailable($executable, 'json', 'dwgread', 'dwgread'))
            ->toThrow(LibreDwgUnavailable::class, 'unsupported_tool_capability');
    } finally {
        \unlink($executable);
    }
});

it('rejects tools below the required capability version', function (
    string $tool,
    string $version,
    string $help,
): void {
    $executable = capabilityProbeExecutable($version, $help);

    try {
        expect(fn () => (new SymfonyProcessRunner())->assertAvailable($executable, 'image', $tool, $tool))
            ->toThrow(LibreDwgUnavailable::class, 'unsupported_tool_capability');
    } finally {
        \unlink($executable);
    }
})->with([
    'LibreOffice 7.3' => [
        'libreoffice',
        'LibreOffice 7.3.7',
        'Usage: soffice --headless --convert-to fmt --outdir dir',
    ],
    'ImageMagick 6' => [
        'imagemagick',
        'Version: ImageMagick 6.9.13',
        'Usage: magick -fuzz value -trim -alpha value',
    ],
]);

it('maps a LibreDWG decode failure to invalid input', function (): void {
    $workspace = Workspace::fromSource(
        DwgBinary::from('AC1032 drawing'),
        config()->string('dwg-converter.temporary_directory'),
        1024,
        'dxf',
    );

    try {
        $runner = new SymfonyProcessRunner();

        expect(fn () => $runner->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "ERROR: Failed to decode file\nREAD ERROR 0x800\n"); exit(1);'],
            $workspace,
            5.0,
            1024,
            'dxf',
        ))->toThrow(InvalidDwg::class, 'libredwg_rejected_input');
    } finally {
        $workspace->cleanup();
    }
});

it('maps a LibreDWG read failure to invalid input', function (): void {
    $workspace = Workspace::fromSource(
        DwgBinary::from('AC1032 drawing'),
        config()->string('dwg-converter.temporary_directory'),
        1024,
        'thumbnail',
    );

    try {
        expect(fn () => (new SymfonyProcessRunner())->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "Unable to read file input.dwg. ERROR 0x800"); exit(1);'],
            $workspace,
            5.0,
            1024,
            'thumbnail',
        ))->toThrow(InvalidDwg::class, 'libredwg_rejected_input');
    } finally {
        $workspace->cleanup();
    }
});

it('allows a recoverable diagnostic when the process succeeds', function (): void {
    $workspace = Workspace::fromSource(
        DwgBinary::from('AC1032 drawing'),
        config()->string('dwg-converter.temporary_directory'),
        1024,
        'dxf',
    );

    try {
        (new SymfonyProcessRunner())->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "warning: unsupported object");'],
            $workspace,
            5.0,
            1024,
            'dxf',
        );

        expect($workspace->directory())->toBeDirectory();
    } finally {
        $workspace->cleanup();
    }
});

it('maps process timeout without leaving a workspace', function (): void {
    $workspace = Workspace::fromSource(
        DwgBinary::from('AC1032 drawing'),
        config()->string('dwg-converter.temporary_directory'),
        1024,
        'png',
    );

    try {
        $runner = new SymfonyProcessRunner();

        expect(fn () => $runner->run(
            [PHP_BINARY, '-r', 'usleep(500000);'],
            $workspace,
            0.05,
            1024,
            'png',
        ))->toThrow(DwgOperationFailed::class, 'process_timed_out');
    } finally {
        $workspace->cleanup();
    }
});

it('stops stdout that exceeds the configured limit', function (): void {
    $workspace = Workspace::fromSource(
        DwgBinary::from('AC1032 drawing'),
        config()->string('dwg-converter.temporary_directory'),
        1024,
        'png',
    );

    try {
        $runner = new SymfonyProcessRunner();

        expect(fn () => $runner->run(
            [PHP_BINARY, '-r', 'echo str_repeat("x", 1024);'],
            $workspace,
            5.0,
            16,
            'png',
            $workspace->outputPath('output.png'),
        ))->toThrow(DwgOperationFailed::class, 'output_too_large');
    } finally {
        $workspace->cleanup();
    }
});

it('allows stdout of any size when its limit is disabled', function (): void {
    $workspace = Workspace::fromSource(
        DwgBinary::from('AC1032 drawing'),
        config()->string('dwg-converter.temporary_directory'),
        null,
        'png',
    );

    try {
        $output = $workspace->outputPath('output.png');
        (new SymfonyProcessRunner())->run(
            [PHP_BINARY, '-r', 'echo str_repeat("x", 1024);'],
            $workspace,
            5.0,
            null,
            'png',
            $output,
        );

        expect(\filesize($output))->toBe(1024);
    } finally {
        $workspace->cleanup();
    }
});

it('redacts paths from a generic process failure', function (): void {
    $workspace = Workspace::fromSource(
        DwgBinary::from('AC1032 drawing'),
        config()->string('dwg-converter.temporary_directory'),
        1024,
        'thumbnail',
    );

    try {
        $runner = new SymfonyProcessRunner();

        $runner->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "failed C:\\\\private\\\\drawing.dwg"); exit(7);'],
            $workspace,
            5.0,
            1024,
            'thumbnail',
        );
    } catch (DwgOperationFailed $failure) {
        expect($failure->reason())->toBe('process_failed')
            ->and($failure->context()['exit_code'] ?? null)->toBe(7)
            ->and($failure->context()['stderr'] ?? null)->not->toContain('private', 'drawing.dwg');

        $workspace->cleanup();

        return;
    }

    $workspace->cleanup();

    throw new RuntimeException('The process failure was not thrown.');
});
