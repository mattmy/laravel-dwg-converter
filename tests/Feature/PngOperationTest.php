<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\PngResolution;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

/**
 * Build a structurally complete PNG with the requested dimensions.
 */
function pngFixture(int $width = 16, int $height = 12): string
{
    return "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . \pack('N2', $width, $height) . "\x08\x02\0\0\0" . \str_repeat("\0", 4) . "IEND\xaeB`\x82";
}

/**
 * Create a fake that completes every PNG conversion stage.
 */
function successfulPngRunner(): FakeProcessRunner
{
    return new FakeProcessRunner(static function (
        array $command,
        Workspace $workspace,
        float $_timeout,
        int $_maxOutputBytes,
        string $_operation,
        ?string $_stdoutPath,
    ): void {
        match ($command[0]) {
            'dwg2dxf' => \file_put_contents($workspace->outputPath('drawing.dxf'), "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n"),
            'soffice' => \file_put_contents($workspace->outputPath('drawing.png'), pngFixture()),
            'magick' => \file_put_contents($workspace->outputPath('output.png'), pngFixture()),
            default => null,
        };
    });
}

it('converts a DWG through the fixed PNG preview pipeline', function (): void {
    $runner = new FakeProcessRunner(static function (
        array $command,
        Workspace $workspace,
        float $_timeout,
        int $_maxOutputBytes,
        string $_operation,
        ?string $_stdoutPath,
    ): void {
        match ($command[0]) {
            'dwg2dxf' => \file_put_contents($workspace->outputPath('drawing.dxf'), "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n"),
            'soffice' => \file_put_contents($workspace->outputPath('drawing.png'), pngFixture()),
            'magick' => \file_put_contents($workspace->outputPath('output.png'), pngFixture()),
            default => null,
        };
    });
    app()->instance(ProcessRunner::class, $runner);

    $png = Dwg::toPng(DwgBinary::from('AC1032 drawing'))->convert();

    expect($png->extension())->toBe('png')
        ->and($png->mimeType())->toBe('image/png')
        ->and($png->output())->toStartWith("\x89PNG\r\n\x1a\n")
        ->and($runner->commands)->toHaveCount(3)
        ->and($runner->commands[0])->toBe([
            'dwg2dxf',
            '-o',
            $runner->commands[0][2],
            $runner->commands[0][3],
        ])
        ->and($runner->commands[1])->toContain(
            '--headless',
            '--nofirststartwizard',
            'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"5792"},"PixelWidth":{"type":"long","value":"4096"}}',
        )
        ->and($runner->commands[2])->toContain('-fuzz', '2%', '-trim', '+repage');
});

it('passes each approved DXF version to the PNG intermediate conversion', function (DxfVersion $version): void {
    $runner = successfulPngRunner();
    app()->instance(ProcessRunner::class, $runner);

    Dwg::toPng(DwgBinary::from('AC1032 drawing'))
        ->usingDxfVersion($version)
        ->convert()
        ->output();

    expect(\array_slice($runner->commands[0], 1, 2))->toBe(['--as', $version->value]);
})->with(\array_map(static fn (DxfVersion $version): array => [$version], DxfVersion::cases()));

it('passes each approved preview resolution to LibreOffice', function (PngResolution $resolution, string $filter): void {
    $runner = successfulPngRunner();
    app()->instance(ProcessRunner::class, $runner);

    Dwg::toPng(DwgBinary::from('AC1032 drawing'))
        ->atResolution($resolution)
        ->convert()
        ->output();

    expect($runner->commands[0])->not->toContain('--as')
        ->and($runner->commands[1])->toContain($filter);
})->with([
    'high' => [PngResolution::HIGH, 'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"5792"},"PixelWidth":{"type":"long","value":"4096"}}'],
    'medium' => [PngResolution::MEDIUM, 'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"2896"},"PixelWidth":{"type":"long","value":"2048"}}'],
    'low' => [PngResolution::LOW, 'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"1448"},"PixelWidth":{"type":"long","value":"1024"}}'],
]);

it('keeps PNG options isolated and order-independent', function (): void {
    $runner = successfulPngRunner();
    app()->instance(ProcessRunner::class, $runner);
    $base = Dwg::toPng(DwgBinary::from('AC1032 drawing'));

    $base->usingDxfVersion(DxfVersion::R2018)
        ->atResolution(PngResolution::MEDIUM)
        ->convert()
        ->output();
    $base->atResolution(PngResolution::LOW)
        ->usingDxfVersion(DxfVersion::R2007)
        ->convert()
        ->output();
    $base->convert()->output();

    expect($runner->commands[0])->toContain('--as', 'r2018')
        ->and($runner->commands[1])->toContain(
            'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"2896"},"PixelWidth":{"type":"long","value":"2048"}}',
        )
        ->and($runner->commands[3])->toContain('--as', 'r2007')
        ->and($runner->commands[4])->toContain(
            'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"1448"},"PixelWidth":{"type":"long","value":"1024"}}',
        )
        ->and($runner->commands[6])->not->toContain('--as')
        ->and($runner->commands[7])->toContain(
            'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"5792"},"PixelWidth":{"type":"long","value":"4096"}}',
        );
});

it('stops the PNG pipeline when LibreOffice produces no preview', function (): void {
    $runner = new FakeProcessRunner(static function (
        array $command,
        Workspace $workspace,
        float $_timeout,
        int $_maxOutputBytes,
        string $_operation,
        ?string $_stdoutPath,
    ): void {
        if ($command[0] === 'dwg2dxf') {
            \file_put_contents($workspace->outputPath('drawing.dxf'), "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n");
        }
    });
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::toPng(DwgBinary::from('AC1032 drawing'))->convert())
        ->toThrow(DwgOperationFailed::class, 'output_missing')
        ->and($runner->commands)->toHaveCount(2);
});

it('rejects a PNG preview with unsafe dimensions', function (): void {
    $runner = new FakeProcessRunner(static function (
        array $command,
        Workspace $workspace,
        float $_timeout,
        int $_maxOutputBytes,
        string $_operation,
        ?string $_stdoutPath,
    ): void {
        match ($command[0]) {
            'dwg2dxf' => \file_put_contents($workspace->outputPath('drawing.dxf'), "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n"),
            'soffice' => \file_put_contents($workspace->outputPath('drawing.png'), pngFixture(0, 12)),
            default => null,
        };
    });
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::toPng(DwgBinary::from('AC1032 drawing'))->convert())
        ->toThrow(DwgOperationFailed::class, 'png_invalid')
        ->and($runner->commands)->toHaveCount(2);
});
