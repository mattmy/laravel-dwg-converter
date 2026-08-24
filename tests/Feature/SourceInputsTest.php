<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

it('snapshots explicit binary bytes without changing them', function (): void {
    $runner = FakeProcessRunner::writesFile('input.png', "\x89PNG\r\n\x1a\n" . \str_repeat("\0", 12) . "\0\0\0\0IEND\xaeB`\x82");
    app()->instance(ProcessRunner::class, $runner);

    Dwg::thumbnail(DwgBinary::from('AC1032 binary'))->extract()->output();

    expect($runner->inputSnapshots)->toBe(['AC1032 binary']);
});

it('snapshots an absolute path without taking ownership of it', function (): void {
    $path = \tempnam(\sys_get_temp_dir(), 'dwg-source-');
    if ($path === false || \file_put_contents($path, 'AC1032 path') === false) {
        throw new RuntimeException('Unable to create the path fixture.');
    }

    try {
        $runner = FakeProcessRunner::writesFile('input.png', "\x89PNG\r\n\x1a\n" . \str_repeat("\0", 12) . "\0\0\0\0IEND\xaeB`\x82");
        app()->instance(ProcessRunner::class, $runner);

        Dwg::thumbnail($path)->extract()->output();

        expect($runner->inputSnapshots)->toBe(['AC1032 path'])
            ->and(\file_get_contents($path))->toBe('AC1032 path');
    } finally {
        if (\is_file($path)) {
            \unlink($path);
        }
    }
});

it('snapshots a valid upload without trusting its metadata', function (): void {
    $path = \tempnam(\sys_get_temp_dir(), 'dwg-upload-');
    if ($path === false || \file_put_contents($path, 'AC1032 upload') === false) {
        throw new RuntimeException('Unable to create the upload fixture.');
    }

    try {
        $runner = FakeProcessRunner::writesFile('input.png', "\x89PNG\r\n\x1a\n" . \str_repeat("\0", 12) . "\0\0\0\0IEND\xaeB`\x82");
        app()->instance(ProcessRunner::class, $runner);
        $upload = new UploadedFile($path, 'not-a-drawing.txt', 'text/plain', null, true);

        Dwg::thumbnail($upload)->extract()->output();

        expect($runner->inputSnapshots)->toBe(['AC1032 upload'])
            ->and(\file_get_contents($path))->toBe('AC1032 upload');
    } finally {
        if (\is_file($path)) {
            \unlink($path);
        }
    }
});

it('defers empty binary validation until execution', function (): void {
    $source = DwgBinary::from('');

    expect(fn () => Dwg::thumbnail($source)->extract())
        ->toThrow(InvalidDwg::class, 'invalid_dwg_header');
});

it('rejects a UNC source because only local paths are supported', function (): void {
    expect(fn () => Dwg::thumbnail('\\\\server\\share\\drawing.dwg')->extract())
        ->toThrow(InvalidDwg::class, 'input_not_absolute');
});

it('rejects an invalid upload before starting LibreDWG', function (): void {
    $upload = new UploadedFile('missing.dwg', 'drawing.dwg', null, UPLOAD_ERR_PARTIAL, true);

    expect(fn () => Dwg::thumbnail($upload)->extract())
        ->toThrow(InvalidDwg::class, 'invalid_upload');
});
