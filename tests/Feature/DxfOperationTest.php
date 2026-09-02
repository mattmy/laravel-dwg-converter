<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

it('passes each approved DXF version as an isolated argv value', function (DxfVersion $version): void {
    $runner = FakeProcessRunner::writesFile(
        'output.dxf',
        "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n",
    );
    app()->instance(ProcessRunner::class, $runner);

    Dwg::toDxf(DwgBinary::from('AC1032 drawing'))->toVersion($version)->convert()->output();

    expect(\array_slice($runner->commands[0], 1, 2))->toBe(['--as', $version->value]);
})->with(\array_map(static fn (DxfVersion $version): array => [$version], DxfVersion::cases()));

it('uses the LibreDWG default version unless explicitly configured', function (): void {
    $runner = FakeProcessRunner::writesFile(
        'output.dxf',
        "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n",
    );
    app()->instance(ProcessRunner::class, $runner);
    $base = Dwg::toDxf(DwgBinary::from('AC1032 drawing'));

    $base->toVersion(DxfVersion::R2018)->convert()->output();
    $base->convert()->output();

    expect($runner->commands[0])->toContain('--as', 'r2018')
        ->and($runner->commands[1])->not->toContain('--as');
});

it('accepts a bounded ASCII DXF whose EOF follows a large entities section', function (): void {
    $contents = "0\nSECTION\n2\nENTITIES\n" . \str_repeat("0\nLINE\n", 10_000) . "0\nENDSEC\n0\nEOF\n";
    $runner = FakeProcessRunner::writesFile('output.dxf', $contents);
    app()->instance(ProcessRunner::class, $runner);

    $output = Dwg::toDxf(DwgBinary::from('AC1032 drawing'))->convert();

    expect($output->extension())->toBe('dxf')
        ->and($output->mimeType())->toBe('image/vnd.dxf')
        ->and($output->output())->toBe($contents);
});

it('rejects a malformed DXF artifact', function (): void {
    $runner = FakeProcessRunner::writesFile('output.dxf', "0\nSECTION\n");
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::toDxf(DwgBinary::from('AC1032 drawing'))->convert())
        ->toThrow(DwgOperationFailed::class, 'dxf_invalid');
});
