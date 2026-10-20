<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FailedCleanupStream;

it('maps snapshot I/O failures and closes an already opened source handle', function (string $sourceType, string $failure): void {
    \class_exists(Workspace::class);
    \class_exists(DwgOperationFailed::class);
    $path = \tempnam(\sys_get_temp_dir(), 'dwg-snapshot-source-');
    if ($path === false || \file_put_contents($path, 'AC1032 drawing') === false) {
        throw new RuntimeException('Unable to create the source fixture.');
    }
    $source = $sourceType === 'path' ? $path : DwgBinary::from('AC1032 drawing');
    $directory = config()->string('dwg-converter.temporary_directory');
    $previousExists = FailedCleanupStream::$exists;
    FailedCleanupStream::$exists = true;
    FailedCleanupStream::$sourcePath = \str_replace('\\', '/', $path);
    FailedCleanupStream::$closedInputs = 0;
    FailedCleanupStream::$snapshotFailure = $failure;
    $caught = null;
    \stream_wrapper_unregister('file');
    \stream_wrapper_register('file', FailedCleanupStream::class);

    try {
        Workspace::fromSource($source, $directory, 1024, 'dxf');
    } catch (Throwable $exception) {
        $caught = $exception;
    } finally {
        \stream_wrapper_restore('file');
        FailedCleanupStream::$exists = $previousExists;
        FailedCleanupStream::$sourcePath = null;
        FailedCleanupStream::$snapshotFailure = '';
        \clearstatcache();
        \unlink($path);
    }

    expect($caught)->toBeInstanceOf(DwgOperationFailed::class);
    if (! $caught instanceof DwgOperationFailed) {
        throw new RuntimeException('Expected a mapped snapshot failure.');
    }
    expect($caught->reason())->toBe('input_snapshot_failed')
        ->and(FailedCleanupStream::$closedInputs)->toBe($sourceType === 'path' ? 1 : 0);
    if ($failure === 'open') {
        expect($caught->getPrevious())->toBeInstanceOf(ErrorException::class);
    }
})->with([
    'path destination open' => ['path', 'open'],
    'binary destination open' => ['binary', 'open'],
    'short binary write' => ['binary', 'short'],
]);
