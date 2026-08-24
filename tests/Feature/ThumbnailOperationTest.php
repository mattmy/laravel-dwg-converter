<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

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
        "\x89PNG\r\n\x1a\n" . \str_repeat("\0", 12) . "IEND\xaeB`\x82",
        'png',
        'image/png',
    ],
    'BMP' => [
        'BM' . \str_repeat("\0", 52),
        'bmp',
        'image/bmp',
    ],
    'placeable WMF' => [
        "\xd7\xcd\xc6\x9a" . \str_repeat("\0", 36),
        'wmf',
        'image/wmf',
    ],
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
        int $_maxOutputBytes,
        string $_operation,
        ?string $_stdoutPath,
    ): void {});
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract())
        ->toThrow(DwgOperationFailed::class, 'thumbnail_not_found');
});

it('rejects a thumbnail larger than the configured output limit', function (): void {
    config()->set('dwg-converter.max_output_bytes', 16);
    $runner = FakeProcessRunner::writesFile(
        'input.png',
        "\x89PNG\r\n\x1a\n" . \str_repeat("\0", 12) . "IEND\xaeB`\x82",
    );
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract())
        ->toThrow(DwgOperationFailed::class, 'output_too_large');
});
