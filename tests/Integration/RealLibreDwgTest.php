<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\ImageFormat;
use Mattmy\DwgConverter\ImageResolution;

$environment = static function (string $name): string {
    $value = \getenv($name);

    return \is_string($value) ? $value : '';
};
$source = $environment('DWG_CONVERTER_TEST_DWG');
$imageSource = $environment('DWG_CONVERTER_TEST_IMAGE_DWG');
$executables = [
    'dwgbmp' => $environment('LIBREDWG_DWGBMP'),
    'dwg2dxf' => $environment('LIBREDWG_DWG2DXF'),
    'dwgread' => $environment('LIBREDWG_DWGREAD'),
    'libreoffice' => $environment('DWG_CONVERTER_LIBREOFFICE'),
    'imagemagick' => $environment('DWG_CONVERTER_IMAGEMAGICK'),
];
$missingIntegrationDependency = $source === ''
    || $imageSource === ''
    || ! \is_file($source)
    || ! \is_file($imageSource);
foreach ($executables as $executable) {
    $missingIntegrationDependency = $missingIntegrationDependency || $executable === '' || ! \is_file($executable);
}
$missingLibreDwg = $executables['dwgbmp'] === ''
    || $executables['dwg2dxf'] === ''
    || $executables['dwgread'] === ''
    || ! \is_file($executables['dwgbmp'])
    || ! \is_file($executables['dwg2dxf'])
    || ! \is_file($executables['dwgread']);
$missingJsonDependency = $source === ''
    || ! \is_file($source)
    || $executables['dwgread'] === ''
    || ! \is_file($executables['dwgread']);
$integrationSkipReason = 'Set DWG_CONVERTER_TEST_DWG, DWG_CONVERTER_TEST_IMAGE_DWG, and all executable environment variables.';

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
})->skip($missingIntegrationDependency, $integrationSkipReason);

it('converts a real DWG to the selected DXF version', function () use ($source): void {
    $contents = Dwg::toDxf($source)
        ->toVersion(DxfVersion::R2018)
        ->convert()
        ->output();

    expect($contents)->toContain('SECTION')
        ->and(\preg_match('/(?:^|\R)\h*0\REOF\R?$/', $contents))->toBe(1);
})->skip($missingIntegrationDependency, $integrationSkipReason);

it('converts a real DWG to valid structural JSON', function () use ($source): void {
    $contents = Dwg::toJson($source)->convert()->output();

    expect(\json_validate($contents))->toBeTrue()
        ->and($contents)->toContain('"FILEHEADER"')
        ->and($contents)->toContain('"HEADER"')
        ->and($contents)->toContain('"OBJECTS"');
})->skip($missingJsonDependency, $integrationSkipReason);

it('converts a real DWG with all byte limits disabled', function () use ($source): void {
    config()->set('dwg-converter.max_input_bytes', 0);
    config()->set('dwg-converter.max_output_bytes', 0);
    config()->set('dwg-converter.max_json_output_bytes', 0);

    $contents = Dwg::toJson($source)->convert()->output();

    expect(\json_validate($contents))->toBeTrue()
        ->and($contents)->toContain('"FILEHEADER"')
        ->and($contents)->toContain('"HEADER"')
        ->and($contents)->toContain('"OBJECTS"');
})->skip($missingJsonDependency, $integrationSkipReason);

it('converts a real DWG to each trimmed image format', function (ImageFormat $format) use ($imageSource): void {
    $temporaryDirectory = \sys_get_temp_dir() . '/dwg-converter-integration-' . \bin2hex(\random_bytes(8));
    config()->set('dwg-converter.temporary_directory', $temporaryDirectory);

    try {
        $image = Dwg::toImage($imageSource)->format($format)->convert();
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
])->skip($missingIntegrationDependency, $integrationSkipReason);

it('applies real image resolutions and an intermediate DXF version', function () use ($imageSource): void {
    $temporaryDirectory = \sys_get_temp_dir() . '/dwg-converter-integration-' . \bin2hex(\random_bytes(8));
    config()->set('dwg-converter.temporary_directory', $temporaryDirectory);

    try {
        $base = Dwg::toImage($imageSource);
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
})->skip($missingIntegrationDependency, $integrationSkipReason);

it('rejects forged DWG bytes through every public operation', function (string $operation): void {
    $source = DwgBinary::from('AC1032 forged bytes');

    $operationResult = match ($operation) {
        'dxf' => static fn () => Dwg::toDxf($source)->convert(),
        'image' => static fn () => Dwg::toImage($source)->convert(),
        'json' => static fn () => Dwg::toJson($source)->convert(),
        'thumbnail' => static fn () => Dwg::thumbnail($source)->extract(),
        default => throw new RuntimeException('Unknown acceptance operation.'),
    };

    expect($operationResult)->toThrow(InvalidDwg::class, 'libredwg_rejected_input');
})->with([
    'DXF' => ['dxf'],
    'image' => ['image'],
    'JSON' => ['json'],
    'thumbnail' => ['thumbnail'],
])->skip($missingLibreDwg, $integrationSkipReason);
