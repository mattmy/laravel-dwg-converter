<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Internal\SymfonyProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;

it('rejects an executable that is not the selected LibreDWG tool', function (): void {
    expect(fn () => (new SymfonyProcessRunner())->assertAvailable(PHP_BINARY, 'dxf'))
        ->toThrow(LibreDwgUnavailable::class, 'unsupported_tool_capability');
});

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
