<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

/**
 * Build a structurally complete PNG with the requested dimensions.
 */
function pngFixture(int $width = 16, int $height = 12): string
{
    return "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . \pack('N2', $width, $height) . "\x08\x02\0\0\0" . \str_repeat("\0", 4) . "IEND\xaeB`\x82";
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
            'png:draw_png_Export:PixelWidth=4096,PixelHeight=5792',
        )
        ->and($runner->commands[2])->toContain('-fuzz', '2%', '-trim', '+repage');
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
