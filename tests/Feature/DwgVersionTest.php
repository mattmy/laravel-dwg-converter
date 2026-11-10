<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgFileVersion;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\Converter;
use Mattmy\DwgConverter\Internal\DwgHeader;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Tests\Fakes\FailedCleanupStream;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

it('preserves known and unknown format codes without using conversion settings or a tool', function (string $code, ?string $format): void {
    $runner = new FakeProcessRunner(
        static fn (): string => '',
        new LibreDwgUnavailable('unsupported_tool_capability'),
    );
    app()->instance(ProcessRunner::class, $runner);
    $directory = config()->string('dwg-converter.temporary_directory');
    config()->set('dwg-converter.executables', null);
    config()->set('dwg-converter.max_input_bytes', 1);
    config()->set('dwg-converter.timeout', false);

    expect(Dwg::version(DwgBinary::from($code . ' ignored')))->toEqual(new DwgFileVersion($code, $format))
        ->and($runner->commands)->toBe([])
        ->and(\is_dir($directory))->toBeFalse();
})->with([
    ['AC1002', 'R2.5'], ['AC1003', 'R2.6'], ['AC1004', 'R9'], ['AC1006', 'R10'],
    ['AC1009', 'R11/R12'], ['AC1012', 'R13'], ['AC1014', 'R14'], ['AC1015', 'R2000'],
    ['AC1018', 'R2004'], ['AC1021', 'R2007'], ['AC1024', 'R2010'], ['AC1027', 'R2013'],
    ['AC1032', 'R2018'], ['AC1099', null],
]);

it('leaves conversion settings available to change after reading the version', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', '{"HEADER":{}}'));
    $source = DwgBinary::from('AC1032');

    expect(Dwg::version($source)->code)->toBe('AC1032');
    $resolvedByVersion = app()->resolved(Converter::class);
    config()->set('dwg-converter.max_json_output_bytes', 2);

    expect(fn () => Dwg::info($source)->read())->toThrow(DwgOperationFailed::class, 'output_too_large')
        ->and($resolvedByVersion)->toBeFalse();

    config()->set('dwg-converter.max_json_output_bytes', 100);

    expect(fn () => Dwg::info($source)->read())->toThrow(DwgOperationFailed::class, 'output_too_large');
});

it('reads an absolute path and valid upload without modifying the original file', function (): void {
    $path = \tempnam(\sys_get_temp_dir(), 'dwg-version-');
    if ($path === false) {
        throw new RuntimeException('Unable to create the version fixture.');
    }

    try {
        \file_put_contents($path, 'AC1024 remaining bytes');
        $expected = new DwgFileVersion('AC1024', 'R2010');
        expect(Dwg::version($path))->toEqual($expected)
            ->and(Dwg::version(new UploadedFile($path, 'drawing.txt', 'text/plain', null, true)))->toEqual($expected)
            ->and(\file_get_contents($path))->toBe('AC1024 remaining bytes');
        \file_put_contents($path, 'AC102');
        expect(fn () => Dwg::version($path))->toThrow(InvalidDwg::class, 'invalid_dwg_header');
    } finally {
        \unlink($path);
    }
});

it('rejects a truncated or malformed header', function (string $contents): void {
    expect(fn () => Dwg::version(DwgBinary::from($contents)))->toThrow(InvalidDwg::class, 'invalid_dwg_header');
})->with([[''], ['AC103'], ['ac1032'], ['AC10X2'], ["AC103\n"]]);

it('preserves invalid upload and local path reasons', function (): void {
    $missing = \sys_get_temp_dir() . '/' . \bin2hex(\random_bytes(8)) . '.dwg';
    expect(fn () => Dwg::version(new UploadedFile('missing.dwg', 'drawing.dwg', null, \UPLOAD_ERR_PARTIAL, true)))
        ->toThrow(InvalidDwg::class, 'invalid_upload')
        ->and(fn () => Dwg::version('relative.dwg'))->toThrow(InvalidDwg::class, 'input_not_absolute')
        ->and(fn () => Dwg::version('https://example.com/drawing.dwg'))->toThrow(InvalidDwg::class, 'input_not_absolute')
        ->and(fn () => Dwg::version($missing))->toThrow(InvalidDwg::class, 'input_not_found')
        ->and(fn () => Dwg::version(\sys_get_temp_dir()))->toThrow(InvalidDwg::class, 'input_not_readable');
});

it('maps failure when opening or reading a validated file header', function (string $failure): void {
    \class_exists(DwgHeader::class);
    \class_exists(InvalidDwg::class);
    \class_exists(FailedCleanupStream::class);
    FailedCleanupStream::$headerReadFailure = $failure;
    \stream_wrapper_unregister('file');
    \stream_wrapper_register('file', FailedCleanupStream::class);
    $caught = null;

    try {
        DwgHeader::read(__FILE__, 'version');
    } catch (InvalidDwg $exception) {
        $caught = $exception;
    } finally {
        \stream_wrapper_restore('file');
        FailedCleanupStream::$headerReadFailure = '';
        \clearstatcache();
    }

    expect($caught?->reason())->toBe('input_not_readable')
        ->and($caught?->context())->toBe(['operation' => 'version'])
        ->and($caught?->getPrevious())->toBeInstanceOf(ErrorException::class);
})->with([['open'], ['read']]);

it('requests only six bytes and closes the header stream', function (): void {
    \class_exists(DwgHeader::class);
    \class_exists(DwgFileVersion::class);
    \class_exists(FailedCleanupStream::class);
    $previousContents = FailedCleanupStream::$readContents;
    $previousPath = FailedCleanupStream::$sourcePath;
    FailedCleanupStream::$readContents = 'AC1032' . \str_repeat('x', 8192);
    FailedCleanupStream::$sourcePath = \str_replace('\\', '/', __FILE__);
    FailedCleanupStream::$headerBytesRequested = 0;
    FailedCleanupStream::$closedInputs = 0;
    \stream_wrapper_unregister('file');
    \stream_wrapper_register('file', FailedCleanupStream::class);

    try {
        $version = DwgHeader::read(__FILE__, 'version');
    } finally {
        \stream_wrapper_restore('file');
        FailedCleanupStream::$readContents = $previousContents;
        FailedCleanupStream::$sourcePath = $previousPath;
        \clearstatcache();
    }

    expect($version->code)->toBe('AC1032')
        ->and(FailedCleanupStream::$headerBytesRequested)->toBe(6)
        ->and(FailedCleanupStream::$closedInputs)->toBe(1);
});
