<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;
use Mattmy\DwgConverter\Tests\Fakes\ThumbnailFixture;

it('reports the thumbnail format from trusted bytes', function (
    string $contents,
    string $extension,
    string $mimeType,
): void {
    $runner = FakeProcessRunner::writesFile('misleading.bin', $contents);
    app()->instance(ProcessRunner::class, $runner);

    $thumbnail = Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract();

    expect($thumbnail->extension())->toBe($extension)
        ->and($thumbnail->mimeType())->toBe($mimeType)
        ->and($thumbnail->output())->toBe($contents);
})->with([
    'PNG' => [
        ThumbnailFixture::png(),
        'png',
        'image/png',
    ],
    'BMP' => [
        ThumbnailFixture::bmp(),
        'bmp',
        'image/bmp',
    ],
    'placeable WMF' => [
        ThumbnailFixture::wmf(true),
        'wmf',
        'image/wmf',
    ],
    'standard WMF' => [ThumbnailFixture::wmf(), 'wmf', 'image/wmf'],
    'top-down BMP' => [ThumbnailFixture::bmp(true), 'bmp', 'image/bmp'],
]);

it('uses each detected thumbnail extension when storing an explicit filename', function (string $contents, string $extension): void {
    Storage::fake('exports');
    $runner = FakeProcessRunner::writesFile('misleading.bin', $contents);
    app()->instance(ProcessRunner::class, $runner);

    $stored = Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract()->storeAs('', 'preview.jpg', 'exports');

    expect($stored)->toBe('preview.jpg.' . $extension)
        ->and(Storage::disk('exports')->exists($stored))->toBeTrue();
})->with([
    'PNG' => [ThumbnailFixture::png(), 'png'],
    'BMP' => [ThumbnailFixture::bmp(), 'bmp'],
    'placeable WMF' => [ThumbnailFixture::wmf(true), 'wmf'],
    'standard WMF' => [ThumbnailFixture::wmf(), 'wmf'],
]);

it('rejects truncated thumbnail signatures', function (string $contents): void {
    $runner = FakeProcessRunner::writesFile('input.bin', $contents);
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract())
        ->toThrow(DwgOperationFailed::class, 'thumbnail_invalid');
})->with([
    'PNG signature only' => "\x89PNG\r\n\x1a\n",
    'BMP signature only' => 'BM',
    'WMF signature only' => "\xd7\xcd\xc6\x9a",
    'unknown bytes' => 'not an image',
]);

it('fails when LibreDWG produces no thumbnail', function (): void {
    $runner = new FakeProcessRunner(static function (
        array $_command,
        Workspace $_workspace,
        float $_timeout,
        string $_operation,
    ): void {});
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract())
        ->toThrow(DwgOperationFailed::class, 'thumbnail_not_found');
});

it('rejects malformed thumbnail structures before creating a result', function (string $contents): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('preview.bin', $contents));

    expect(fn () => Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract())
        ->toThrow(DwgOperationFailed::class, 'thumbnail_invalid');
})->with([
    'empty BMP header' => ['BM' . \str_repeat("\0", 52)],
    'BMP declared size' => [\substr_replace(ThumbnailFixture::bmp(), \pack('V', 1000), 2, 4)],
    'BMP pixel offset outside file' => [\substr_replace(ThumbnailFixture::bmp(), \pack('V', 1000), 10, 4)],
    'BMP pixel offset in header' => [\substr_replace(ThumbnailFixture::bmp(), \pack('V', 20), 10, 4)],
    'BMP zero width' => [\substr_replace(ThumbnailFixture::bmp(), \pack('V', 0), 18, 4)],
    'BMP invalid planes' => [\substr_replace(ThumbnailFixture::bmp(), \pack('v', 0), 26, 2)],
    'BMP pixel rows truncated' => [\substr_replace(ThumbnailFixture::bmp(), \pack('V', 2), 22, 4)],
    'BMP incomplete DIB header' => [\substr_replace(ThumbnailFixture::bmp(), \pack('V', 124), 14, 4)],
    'BMP declared pixel size' => [\substr_replace(ThumbnailFixture::bmp(), \pack('V', 1000), 34, 4)],
    'BMP missing palette' => [\substr_replace(ThumbnailFixture::bmp(), \pack('v', 1), 28, 2)],
    'BMP missing bitfield masks' => [\substr_replace(ThumbnailFixture::bmp(), \pack('vV', 16, 3), 28, 6)],
    'BMP invalid compression' => [\substr_replace(ThumbnailFixture::bmp(), \pack('V', 99), 30, 4)],
    'PNG missing IHDR' => [\substr(ThumbnailFixture::png(), 0, 8) . \substr(ThumbnailFixture::png(), 33)],
    'PNG missing IDAT' => [\substr(ThumbnailFixture::png(), 0, 33) . \substr(ThumbnailFixture::png(), -12)],
    'PNG chunk length overflow' => [\substr_replace(ThumbnailFixture::png(), \pack('N', 0xFFFFFFFF), 33, 4)],
    'PNG zero width' => [\substr_replace(ThumbnailFixture::png(), \pack('N', 0), 16, 4)],
    'PNG invalid bit depth' => [\substr_replace(ThumbnailFixture::png(), "\x03", 24, 1)],
    'PNG duplicate IHDR' => [\substr(ThumbnailFixture::png(), 0, 33) . \substr(ThumbnailFixture::png(), 8)],
    'PNG invalid compression' => [\substr_replace(ThumbnailFixture::png(), "\x01", 26, 1)],
    'PNG invalid filter' => [\substr_replace(ThumbnailFixture::png(), "\x01", 27, 1)],
    'PNG invalid interlace' => [\substr_replace(ThumbnailFixture::png(), "\x02", 28, 1)],
    'PNG missing palette' => [\substr_replace(ThumbnailFixture::png(), "\x03", 25, 1)],
    'PNG trailing data' => [ThumbnailFixture::png() . 'trailing'],
    'PNG truncated chunk before IEND' => [\substr(ThumbnailFixture::png(), 0, 40) . \substr(ThumbnailFixture::png(), -12)],
    'placeable WMF missing standard header' => [\substr(ThumbnailFixture::wmf(true), 0, 22) . \str_repeat("\0", 18)],
    'WMF wrong declared size' => [\substr_replace(ThumbnailFixture::wmf(), \pack('V', 1000), 6, 4)],
    'WMF invalid version' => [\substr_replace(ThumbnailFixture::wmf(), \pack('v', 0), 4, 2)],
    'WMF wrong maximum record size' => [\substr_replace(ThumbnailFixture::wmf(), \pack('V', 4), 12, 4)],
    'placeable WMF checksum' => [\substr_replace(ThumbnailFixture::wmf(true), "\0\0", 20, 2)],
    'WMF record does not advance' => [\substr_replace(ThumbnailFixture::wmf(), \pack('V', 0), 18, 4)],
    'WMF record length overflow' => [\substr_replace(ThumbnailFixture::wmf(), \pack('V', 0xFFFFFFFF), 18, 4)],
    'WMF missing EOF' => [\substr_replace(ThumbnailFixture::wmf(), \pack('v', 0x0103), 22, 2)],
    'WMF truncated record' => [\substr(ThumbnailFixture::wmf(), 0, -1)],
]);

it('accepts a zero-length PNG chunk without losing parser progress', function (): void {
    $emptyIdat = \pack('N', 0) . 'IDAT' . \pack('N', \crc32('IDAT'));
    $contents = \substr_replace(ThumbnailFixture::png(), $emptyIdat, 33, 0);
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('preview.bin', $contents));

    expect(Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract()->output())->toBe($contents);
});

it('preserves structurally valid legacy headers and records', function (string $contents, string $extension): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('preview.bin', $contents));
    $thumbnail = Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract();

    expect($thumbnail->extension())->toBe($extension)->and($thumbnail->output())->toBe($contents);
})->with([
    'core BMP' => ['BM' . \pack('VvvV', 30, 0, 0, 26) . \pack('Vvvvv', 12, 1, 1, 1, 24) . "\0\0\0\0", 'bmp'],
    'zero optional BMP image size' => [\substr_replace(ThumbnailFixture::bmp(), \pack('V', 0), 34, 4), 'bmp'],
    'indexed BMP' => ['BM' . \pack('VvvVV', 66, 0, 0, 62, 40)
        . \pack('VVvvVVVVVV', 1, 1, 1, 1, 0, 4, 0, 0, 0, 0) . \str_repeat("\0", 12), 'bmp'],
    'bitfields BMP' => ['BM' . \pack('VvvVV', 70, 0, 0, 66, 40)
        . \pack('VVvvVVVVVV', 1, 1, 1, 16, 3, 4, 0, 0, 0, 0)
        . \pack('V3', 0xF800, 0x07E0, 0x001F) . "\0\0\0\0", 'bmp'],
    'disk WMF' => [\substr_replace(ThumbnailFixture::wmf(), \pack('v', 2), 0, 2), 'wmf'],
    'WMF drawing record followed by EOF' => [\pack('vvvVvVv', 1, 9, 0x0300, 16, 0, 4, 0)
        . \pack('Vvv', 4, 0x0103, 1) . \pack('Vv', 3, 0), 'wmf'],
]);

it('rejects a thumbnail larger than the configured output limit', function (): void {
    config()->set('dwg-converter.max_output_bytes', 16);
    $runner = FakeProcessRunner::writesFile(
        'input.png',
        ThumbnailFixture::png(),
    );
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract())
        ->toThrow(DwgOperationFailed::class, 'output_too_large');
});

it('uses the source stem with the detected thumbnail extension when no storage name is provided', function (): void {
    $temporary = \tempnam(\sys_get_temp_dir(), 'dwg-thumbnail-');
    if ($temporary === false) {
        throw new RuntimeException('Unable to create the source fixture.');
    }

    $source = $temporary . '.dwg';
    if (! \rename($temporary, $source)) {
        throw new RuntimeException('Unable to name the source fixture.');
    }

    if (\file_put_contents($source, 'AC1032 drawing') === false) {
        throw new RuntimeException('Unable to write the source fixture.');
    }

    try {
        Storage::fake('exports');
        $runner = FakeProcessRunner::writesFile(
            'input.png',
            ThumbnailFixture::png(),
        );
        app()->instance(ProcessRunner::class, $runner);

        $stored = Dwg::thumbnail($source)->extract()->storeAs('', disk: 'exports');

        expect($stored)->toBe(\pathinfo($source, \PATHINFO_FILENAME) . '.png')
            ->and(Storage::disk('exports')->exists($stored))->toBeTrue();
    } finally {
        if (\is_file($source)) {
            \unlink($source);
        }
    }
});
