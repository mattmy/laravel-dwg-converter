<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgOutput;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

it('allows exactly one terminal consumption', function (): void {
    $contents = "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n";
    $runner = FakeProcessRunner::writesFile('output.dxf', $contents);
    app()->instance(ProcessRunner::class, $runner);
    $output = Dwg::toDxf(DwgBinary::from('AC1032 drawing'))->convert();

    expect($output->extension())->toBe('dxf')
        ->and($output->extension())->toBe('dxf')
        ->and($output->output())->toBe($contents)
        ->and(fn () => $output->output())->toThrow(LogicException::class);
});

it('streams an output to a named Laravel disk', function (): void {
    Storage::fake('drawings');
    $contents = "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n";
    $runner = FakeProcessRunner::writesFile('output.dxf', $contents);
    app()->instance(ProcessRunner::class, $runner);
    $output = Dwg::toDxf(DwgBinary::from('AC1032 drawing'))->convert();

    $path = $output->storeAs('converted', 'floor-plan.DXF', 'drawings');

    expect($path)->toBe('converted/floor-plan.dxf')
        ->and(Storage::disk('drawings')->get($path))->toBe($contents)
        ->and(fn () => $output->output())->toThrow(LogicException::class);
});

it('appends or normalizes the trusted output extension for an explicit storage name', function (string $name, string $expected): void {
    Storage::fake('exports');
    $workspace = Workspace::fromSource(
        DwgBinary::from('AC1032 drawing'),
        config()->string('dwg-converter.temporary_directory'),
        1024,
        'image',
    );
    $artifact = $workspace->outputPath('output.webp');
    if (\file_put_contents($artifact, 'artifact') === false) {
        throw new RuntimeException('Unable to create the output fixture.');
    }

    $output = new DwgOutput($workspace, $artifact, 'webp', 'image/webp', 1024, 'image');

    $path = $output->storeAs('', $name, 'exports');

    expect($path)->toBe($expected)
        ->and(Storage::disk('exports')->get($path))->toBe('artifact');
})->with([
    'matching lowercase extension' => ['test1.webp', 'test1.webp'],
    'different extension is preserved' => ['test1.png', 'test1.png.webp'],
    'matching uppercase extension is canonicalized' => ['test1.WEBP', 'test1.webp'],
]);

it('uses the default Laravel disk when no disk is named', function (): void {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $contents = "0\nSECTION\n0\nEOF\n";
    $runner = FakeProcessRunner::writesFile('output.dxf', $contents);
    app()->instance(ProcessRunner::class, $runner);

    $path = Dwg::toDxf(DwgBinary::from('AC1032 drawing'))
        ->convert()
        ->storeAs('converted', 'floor-plan.dxf');

    expect(Storage::disk('local')->get($path))->toBe($contents);
});

it('cleans an output abandoned before consumption', function (): void {
    $runner = FakeProcessRunner::writesFile('output.dxf', "0\nSECTION\n0\nEOF\n");
    app()->instance(ProcessRunner::class, $runner);

    $output = Dwg::toDxf(DwgBinary::from('AC1032 drawing'))->convert();
    unset($output);
    gc_collect_cycles();

    expect(\scandir(config()->string('dwg-converter.temporary_directory')))->toBe(['.', '..']);
});

it('consumes and cleans the result when a storage destination is invalid', function (
    string $path,
    string $name,
): void {
    $runner = FakeProcessRunner::writesFile(
        'output.dxf',
        "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n",
    );
    app()->instance(ProcessRunner::class, $runner);
    $output = Dwg::toDxf(DwgBinary::from('AC1032 drawing'))->convert();

    expect(fn () => $output->storeAs($path, $name))
        ->toThrow(DwgOperationFailed::class, 'storage_failed')
        ->and(fn () => $output->output())
        ->toThrow(LogicException::class);
})->with([
    'parent traversal' => ['../outside', 'drawing.dxf'],
    'absolute directory' => ['/outside', 'drawing.dxf'],
    'empty directory segment' => ['safe//outside', 'drawing.dxf'],
    'current directory segment' => ['safe/./outside', 'drawing.dxf'],
    'control character directory' => ["safe\u{0085}outside", 'drawing.dxf'],
    'nested filename' => ['drawings', 'nested/drawing.dxf'],
    'empty filename' => ['drawings', ''],
    'current directory filename' => ['drawings', '.'],
    'parent directory filename' => ['drawings', '..'],
    'null byte filename' => ['drawings', "drawing\0.dxf"],
    'control character filename' => ['drawings', "drawing\n.dxf"],
    'C1 control character filename' => ['drawings', "drawing\u{0085}.dxf"],
]);

it('consumes and cleans the result when Laravel Storage throws', function (): void {
    $runner = FakeProcessRunner::writesFile(
        'output.dxf',
        "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n",
    );
    app()->instance(ProcessRunner::class, $runner);
    $output = Dwg::toDxf(DwgBinary::from('AC1032 drawing'))->convert();
    Storage::shouldReceive('disk')->andThrow(new RuntimeException('disk unavailable'));

    expect(fn () => $output->storeAs('drawings', 'drawing.dxf'))
        ->toThrow(DwgOperationFailed::class, 'storage_failed')
        ->and(fn () => $output->output())
        ->toThrow(LogicException::class);
});

it('refuses to consume an artifact outside its workspace', function (): void {
    $outside = \tempnam(\sys_get_temp_dir(), 'dwg-output-');
    if ($outside === false || \file_put_contents($outside, 'outside') === false) {
        throw new RuntimeException('Unable to create the outside fixture.');
    }

    $workspace = Workspace::fromSource(
        DwgBinary::from('AC1032 drawing'),
        config()->string('dwg-converter.temporary_directory'),
        1024,
        'dxf',
    );
    $output = new DwgOutput($workspace, $outside, 'dxf', 'image/vnd.dxf', 1024, 'dxf');

    try {
        expect(fn () => $output->output())
            ->toThrow(DwgOperationFailed::class, 'output_missing')
            ->and(\file_get_contents($outside))->toBe('outside');
    } finally {
        \unlink($outside);
    }
});
