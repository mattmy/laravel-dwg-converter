<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\ImageFormat;
use Mattmy\DwgConverter\ImageResolution;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

/** Build a structurally complete PNG with the requested dimensions. */
function pngFixture(int $width = 16, int $height = 12): string
{
    return "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . \pack('N2', $width, $height) . "\x08\x02\0\0\0" . \str_repeat("\0", 4) . "IEND\xaeB`\x82";
}

/** Build a minimal JPEG carrying a baseline frame size. */
function jpegFixture(int $width = 16, int $height = 12): string
{
    return "\xff\xd8\xff\xc0\0\x0b\x08" . \pack('n2', $height, $width) . "\x01\x01\x11\0\xff\xd9";
}

/** Build a minimal extended WebP carrying canvas dimensions. */
function webpFixture(int $width = 16, int $height = 12): string
{
    $dimension = static fn (int $value): string => \substr(\pack('V', $value - 1), 0, 3);
    $payload = \str_repeat("\0", 4) . $dimension($width) . $dimension($height);

    return 'RIFF' . \pack('V', 22) . 'WEBPVP8X' . \pack('V', 10) . $payload;
}

/** Return representative bytes for one supported output format. */
function imageFixture(ImageFormat $format): string
{
    return match ($format) {
        ImageFormat::PNG => pngFixture(),
        ImageFormat::JPEG => jpegFixture(),
        ImageFormat::WEBP => webpFixture(),
    };
}

/**
 * Return the image format selected by a LibreOffice conversion command.
 *
 * @param  list<string>  $command
 */
function libreOfficeImageFormat(array $command): ImageFormat
{
    $convertTo = \array_search('--convert-to', $command, true);
    if (! \is_int($convertTo)) {
        throw new RuntimeException('Unknown fake LibreOffice filter.');
    }

    $filter = $command[$convertTo + 1] ?? null;

    return match (true) {
        \is_string($filter) && \str_starts_with($filter, 'png:draw_png_Export:') => ImageFormat::PNG,
        \is_string($filter) && \str_starts_with($filter, 'jpg:draw_jpg_Export:') => ImageFormat::JPEG,
        \is_string($filter) && \str_starts_with($filter, 'webp:draw_webp_Export:') => ImageFormat::WEBP,
        default => throw new RuntimeException('Unknown fake LibreOffice filter.'),
    };
}

/**
 * Return the final argument from a process command recorded by the fake.
 *
 * @param  list<string>  $command
 */
function lastCommandArgument(array $command): string
{
    $argument = \array_pop($command);
    if (! \is_string($argument)) {
        throw new RuntimeException('Fake process command is unexpectedly empty.');
    }

    return $argument;
}

/** Create a fake that completes every image conversion stage. */
function successfulImageRunner(): FakeProcessRunner
{
    return new FakeProcessRunner(static function (
        array $command,
        Workspace $workspace,
        float $_timeout,
        ?int $_maxOutputBytes,
        string $_operation,
        ?string $_stdoutPath,
    ): void {
        if ($command[0] === 'dwg2dxf') {
            \file_put_contents($workspace->outputPath('drawing.dxf'), "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n");
        } elseif ($command[0] === 'soffice') {
            $format = libreOfficeImageFormat($command);
            \file_put_contents($workspace->outputPath('drawing.' . $format->value), imageFixture($format));
        } elseif ($command[0] === 'magick') {
            $output = lastCommandArgument($command);
            $format = match (\pathinfo($output, PATHINFO_EXTENSION)) {
                'png' => ImageFormat::PNG,
                'jpg' => ImageFormat::JPEG,
                'webp' => ImageFormat::WEBP,
                default => throw new RuntimeException('Unknown fake image extension.'),
            };
            \file_put_contents($output, imageFixture($format));
        }
    });
}

it('converts a DWG through the default PNG image pipeline', function (): void {
    $runner = successfulImageRunner();
    app()->instance(ProcessRunner::class, $runner);

    $image = Dwg::toImage(DwgBinary::from('AC1032 drawing'))->convert();

    expect($image->extension())->toBe('png')
        ->and($image->mimeType())->toBe('image/png')
        ->and($image->output())->toStartWith("\x89PNG\r\n\x1a\n")
        ->and($runner->commands)->toHaveCount(3)
        ->and($runner->commands[0])->toBe(['dwg2dxf', '-o', $runner->commands[0][2], $runner->commands[0][3]])
        ->and($runner->commands[1])->toContain(
            '--headless',
            '--nofirststartwizard',
            'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"5792"},"PixelWidth":{"type":"long","value":"4096"}}',
        )
        ->and($runner->commands[2])->toContain('-fuzz', '2%', '-trim', '+repage');
});

it('uses each supported image format throughout the raster pipeline', function (ImageFormat $format, string $extension, string $mimeType, string $filter): void {
    $runner = successfulImageRunner();
    app()->instance(ProcessRunner::class, $runner);

    $image = Dwg::toImage(DwgBinary::from('AC1032 drawing'))->format($format)->convert();

    expect($image->extension())->toBe($extension)
        ->and($image->mimeType())->toBe($mimeType)
        ->and($runner->commands[1])->toContain($filter)
        ->and($runner->commands[2][1])->toEndWith('drawing.' . $extension)
        ->and(lastCommandArgument($runner->commands[2]))->toEndWith('output.' . $extension);

    if ($format === ImageFormat::JPEG) {
        expect($runner->commands[2])->toContain('-background', 'white', '-alpha', 'remove', 'off');
    }
})->with([
    'png' => [ImageFormat::PNG, 'png', 'image/png', 'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"5792"},"PixelWidth":{"type":"long","value":"4096"}}'],
    'jpeg' => [ImageFormat::JPEG, 'jpg', 'image/jpeg', 'jpg:draw_jpg_Export:{"PixelHeight":{"type":"long","value":"5792"},"PixelWidth":{"type":"long","value":"4096"}}'],
    'webp' => [ImageFormat::WEBP, 'webp', 'image/webp', 'webp:draw_webp_Export:{"PixelHeight":{"type":"long","value":"5792"},"PixelWidth":{"type":"long","value":"4096"}}'],
]);

it('passes each approved DXF version to the image intermediate conversion', function (DxfVersion $version): void {
    $runner = successfulImageRunner();
    app()->instance(ProcessRunner::class, $runner);

    Dwg::toImage(DwgBinary::from('AC1032 drawing'))->usingDxfVersion($version)->convert()->output();

    expect(\array_slice($runner->commands[0], 1, 2))->toBe(['--as', $version->value]);
})->with(\array_map(static fn (DxfVersion $version): array => [$version], DxfVersion::cases()));

it('passes each approved image resolution to LibreOffice', function (ImageResolution $resolution, string $filter): void {
    $runner = successfulImageRunner();
    app()->instance(ProcessRunner::class, $runner);

    Dwg::toImage(DwgBinary::from('AC1032 drawing'))->atResolution($resolution)->convert()->output();

    expect($runner->commands[0])->not->toContain('--as')
        ->and($runner->commands[1])->toContain($filter);
})->with([
    'high' => [ImageResolution::HIGH, 'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"5792"},"PixelWidth":{"type":"long","value":"4096"}}'],
    'medium' => [ImageResolution::MEDIUM, 'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"2896"},"PixelWidth":{"type":"long","value":"2048"}}'],
    'low' => [ImageResolution::LOW, 'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"1448"},"PixelWidth":{"type":"long","value":"1024"}}'],
]);

it('keeps image options isolated and order-independent', function (): void {
    $runner = successfulImageRunner();
    app()->instance(ProcessRunner::class, $runner);
    $base = Dwg::toImage(DwgBinary::from('AC1032 drawing'));

    $base->format(ImageFormat::WEBP)->usingDxfVersion(DxfVersion::R2018)->atResolution(ImageResolution::MEDIUM)->convert()->output();
    $base->atResolution(ImageResolution::LOW)->usingDxfVersion(DxfVersion::R2007)->format(ImageFormat::JPEG)->convert()->output();
    $base->convert()->output();

    expect($runner->commands[0])->toContain('--as', 'r2018')
        ->and($runner->commands[1])->toContain('webp:draw_webp_Export:{"PixelHeight":{"type":"long","value":"2896"},"PixelWidth":{"type":"long","value":"2048"}}')
        ->and(lastCommandArgument($runner->commands[2]))->toEndWith('output.webp')
        ->and($runner->commands[3])->toContain('--as', 'r2007')
        ->and($runner->commands[4])->toContain('jpg:draw_jpg_Export:{"PixelHeight":{"type":"long","value":"1448"},"PixelWidth":{"type":"long","value":"1024"}}')
        ->and(lastCommandArgument($runner->commands[5]))->toEndWith('output.jpg')
        ->and($runner->commands[6])->not->toContain('--as')
        ->and($runner->commands[7])->toContain('png:draw_png_Export:{"PixelHeight":{"type":"long","value":"5792"},"PixelWidth":{"type":"long","value":"4096"}}')
        ->and(lastCommandArgument($runner->commands[8]))->toEndWith('output.png');
});

it('stops the image pipeline when LibreOffice produces no preview', function (): void {
    $runner = new FakeProcessRunner(static function (array $command, Workspace $workspace): void {
        if ($command[0] === 'dwg2dxf') {
            \file_put_contents($workspace->outputPath('drawing.dxf'), "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n");
        }
    });
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::toImage(DwgBinary::from('AC1032 drawing'))->convert())
        ->toThrow(DwgOperationFailed::class, 'output_missing')
        ->and($runner->commands)->toHaveCount(2);
});

it('rejects an image preview with unsafe dimensions', function (): void {
    $runner = new FakeProcessRunner(static function (array $command, Workspace $workspace): void {
        match ($command[0]) {
            'dwg2dxf' => \file_put_contents($workspace->outputPath('drawing.dxf'), "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n"),
            'soffice' => \file_put_contents($workspace->outputPath('drawing.png'), pngFixture(0, 12)),
            default => null,
        };
    });
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::toImage(DwgBinary::from('AC1032 drawing'))->convert())
        ->toThrow(DwgOperationFailed::class, 'image_invalid')
        ->and($runner->commands)->toHaveCount(2);
});

it('rejects malformed final images', function (ImageFormat $format): void {
    $runner = new FakeProcessRunner(static function (array $command, Workspace $workspace): void {
        if ($command[0] === 'dwg2dxf') {
            \file_put_contents($workspace->outputPath('drawing.dxf'), "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n");
        } elseif ($command[0] === 'soffice') {
            $format = libreOfficeImageFormat($command);
            \file_put_contents($workspace->outputPath('drawing.' . $format->value), imageFixture($format));
        } elseif ($command[0] === 'magick') {
            \file_put_contents(lastCommandArgument($command), 'not an image');
        }
    });
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::toImage(DwgBinary::from('AC1032 drawing'))->format($format)->convert())
        ->toThrow(DwgOperationFailed::class, 'image_invalid');
})->with(\array_map(static fn (ImageFormat $format): array => [$format], ImageFormat::cases()));
