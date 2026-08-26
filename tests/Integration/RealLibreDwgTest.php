<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\ImageFormat;
use Mattmy\DwgConverter\ImageResolution;

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

/**
 * Read dimensions from a structurally validated PNG artifact.
 *
 * @return array{width: int, height: int}
 */
function pngDimensions(string $contents): array
{
    $dimensions = \unpack('Nwidth/Nheight', \substr($contents, 16, 8));
    $width = $dimensions['width'] ?? null;
    $height = $dimensions['height'] ?? null;
    if (! \is_int($width) || ! \is_int($height)) {
        throw new RuntimeException('Unable to read PNG dimensions.');
    }

    return [
        'width' => $width,
        'height' => $height,
    ];
}

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

it('converts a real DWG to each trimmed image format', function (ImageFormat $format) use ($pngSource): void {
    $temporaryDirectory = \sys_get_temp_dir() . '/dwg-converter-integration-' . \bin2hex(\random_bytes(8));
    config()->set('dwg-converter.temporary_directory', $temporaryDirectory);

    try {
        $image = Dwg::toImage($pngSource)->format($format)->convert();
        $contents = $image->output();
        $signature = match ($format) {
            ImageFormat::PNG => "\x89PNG\r\n\x1a\n",
            ImageFormat::JPEG => "\xff\xd8",
            ImageFormat::WEBP => 'RIFF',
        };

        expect($image->extension())->toBe($format->value)
            ->and($image->mimeType())->toBe($format->mimeType())
            ->and($contents)->toStartWith($signature);
    } finally {
        if (\is_dir($temporaryDirectory)) {
            expect(\scandir($temporaryDirectory))->toBe(['.', '..']);
            expect(\rmdir($temporaryDirectory))->toBeTrue();
        }
    }
})->with([
    'PNG' => [ImageFormat::PNG],
    'JPEG' => [ImageFormat::JPEG],
    'WebP' => [ImageFormat::WEBP],
])->skip($missingIntegrationDependency, 'Repository Windows fixtures are unavailable.');

it('applies real image resolutions and an intermediate DXF version', function () use ($pngSource): void {
    $temporaryDirectory = \sys_get_temp_dir() . '/dwg-converter-integration-' . \bin2hex(\random_bytes(8));
    config()->set('dwg-converter.temporary_directory', $temporaryDirectory);

    try {
        $base = Dwg::toImage($pngSource);
        $high = pngDimensions($base->convert()->output());
        $medium = pngDimensions($base->atResolution(ImageResolution::MEDIUM)->convert()->output());
        $low = pngDimensions(
            $base->usingDxfVersion(DxfVersion::R2018)
                ->atResolution(ImageResolution::LOW)
                ->convert()
                ->output(),
        );

        expect($high['width'])->toBeGreaterThan($medium['width'])
            ->and($medium['width'])->toBeGreaterThan($low['width'])
            ->and($high['height'])->toBeGreaterThan($medium['height'])
            ->and($medium['height'])->toBeGreaterThan($low['height']);
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
        'image' => static fn () => Dwg::toImage($source)->convert(),
        'thumbnail' => static fn () => Dwg::thumbnail($source)->extract(),
        default => throw new RuntimeException('Unknown acceptance operation.'),
    };

    expect($operationResult)->toThrow(InvalidDwg::class, 'libredwg_rejected_input');
})->with([
    'DXF' => ['dxf'],
    'image' => ['image'],
    'thumbnail' => ['thumbnail'],
])->skip($missingLibreDwg, 'Repository LibreDWG binaries are unavailable.');
