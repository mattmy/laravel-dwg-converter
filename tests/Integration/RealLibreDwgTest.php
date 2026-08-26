<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Facades\Dwg;

$repository = \dirname(__DIR__, 4);
$source = $repository . '/public/dwg/2G.dwg';
$pngSource = $repository . '/public/dwg/112.05.31.dwg';
$imageMagick = 'C:/Program Files/ImageMagick-7.1.2-Q16-HDRI/magick.exe';
$executables = [
    'dwgbmp' => $repository . '/libredwg/dwgbmp.exe',
    'dwg2dxf' => $repository . '/libredwg/dwg2dxf.exe',
    'libreoffice' => 'C:/Program Files/LibreOffice/program/soffice.com',
    'imagemagick' => $imageMagick,
];
$missingIntegrationDependency = PHP_OS_FAMILY !== 'Windows'
    || ! \is_file($source)
    || ! \is_file($pngSource)
    || ! \is_file($imageMagick);
foreach ($executables as $executable) {
    $missingIntegrationDependency = $missingIntegrationDependency || ! \is_file($executable);
}
$missingLibreDwg = PHP_OS_FAMILY !== 'Windows'
    || ! \is_file($executables['dwgbmp'])
    || ! \is_file($executables['dwg2dxf']);

beforeEach(function () use ($executables): void {
    config()->set('dwg-converter.executables', $executables);
});

it('extracts a real embedded thumbnail', function () use ($source): void {
    $thumbnail = Dwg::thumbnail($source)->extract();
    $contents = $thumbnail->output();

    expect($thumbnail->extension())->toBe('bmp')
        ->and($thumbnail->mimeType())->toBe('image/bmp')
        ->and($contents)->toStartWith('BM');
})->skip($missingIntegrationDependency, 'Repository Windows fixtures are unavailable.');

it('converts a real DWG to the selected DXF version', function () use ($source): void {
    $contents = Dwg::toDxf($source)
        ->toVersion(DxfVersion::R2018)
        ->convert()
        ->output();

    expect($contents)->toContain('SECTION')
        ->and($contents)->toEndWith("0\r\nEOF\r\n");
})->skip($missingIntegrationDependency, 'Repository Windows fixtures are unavailable.');

it('converts a real DWG to a trimmed PNG preview', function () use ($pngSource): void {
    $temporaryDirectory = \sys_get_temp_dir() . '/dwg-converter-integration-' . \bin2hex(\random_bytes(8));
    config()->set('dwg-converter.temporary_directory', $temporaryDirectory);

    try {
        $contents = Dwg::toPng($pngSource)->convert()->output();

        expect($contents)->toStartWith("\x89PNG\r\n\x1a\n");
    } finally {
        if (\is_dir($temporaryDirectory)) {
            expect(\scandir($temporaryDirectory))->toBe(['.', '..']);
            expect(\rmdir($temporaryDirectory))->toBeTrue();
        }
    }
})->skip($missingIntegrationDependency, 'Repository Windows fixtures are unavailable.');

it('rejects forged DWG bytes through every public operation', function (string $operation): void {
    $source = DwgBinary::from('AC1032 forged bytes');

    $operationResult = match ($operation) {
        'dxf' => static fn () => Dwg::toDxf($source)->convert(),
        'png' => static fn () => Dwg::toPng($source)->convert(),
        'thumbnail' => static fn () => Dwg::thumbnail($source)->extract(),
        default => throw new RuntimeException('Unknown acceptance operation.'),
    };

    expect($operationResult)->toThrow(InvalidDwg::class, 'libredwg_rejected_input');
})->with([
    'DXF' => ['dxf'],
    'PNG' => ['png'],
    'thumbnail' => ['thumbnail'],
])->skip($missingLibreDwg, 'Repository LibreDWG binaries are unavailable.');
