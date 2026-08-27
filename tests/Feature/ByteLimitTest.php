<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

it('does not enforce non-positive or null input limits', function (?int $limit): void {
    config()->set('dwg-converter.max_input_bytes', $limit);
    $runner = FakeProcessRunner::writesFile('input.png', "\x89PNG\r\n\x1a\n" . \str_repeat("\0", 12) . "IEND\xaeB`\x82");
    app()->instance(ProcessRunner::class, $runner);

    expect(Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract()->output())
        ->toStartWith("\x89PNG");
})->with([
    'null' => [null],
    'zero' => [0],
    'negative' => [-1],
]);

it('does not enforce a missing input limit', function (): void {
    $configuration = config('dwg-converter');
    if (! \is_array($configuration)) {
        throw new RuntimeException('Expected the package configuration array.');
    }

    unset($configuration['max_input_bytes']);
    config()->set('dwg-converter', $configuration);
    $runner = FakeProcessRunner::writesFile('input.png', "\x89PNG\r\n\x1a\n" . \str_repeat("\0", 12) . "IEND\xaeB`\x82");
    app()->instance(ProcessRunner::class, $runner);

    expect(Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract()->output())
        ->toStartWith("\x89PNG");
});

it('does not enforce non-positive or null output limits', function (?int $limit): void {
    config()->set('dwg-converter.max_output_bytes', $limit);
    $contents = "0\nSECTION\n2\nENTITIES\n" . \str_repeat("9\n", 64) . "0\nEOF\n";
    $runner = FakeProcessRunner::writesFile('output.dxf', $contents);
    app()->instance(ProcessRunner::class, $runner);

    expect(Dwg::toDxf(DwgBinary::from('AC1032 drawing'))->convert()->output())
        ->toBe($contents);
})->with([
    'null' => [null],
    'zero' => [0],
    'negative' => [-1],
]);

it('does not enforce a missing output limit', function (): void {
    $configuration = config('dwg-converter');
    if (! \is_array($configuration)) {
        throw new RuntimeException('Expected the package configuration array.');
    }

    unset($configuration['max_output_bytes']);
    config()->set('dwg-converter', $configuration);
    $contents = "0\nSECTION\n2\nENTITIES\n" . \str_repeat("9\n", 64) . "0\nEOF\n";
    $runner = FakeProcessRunner::writesFile('output.dxf', $contents);
    app()->instance(ProcessRunner::class, $runner);

    expect(Dwg::toDxf(DwgBinary::from('AC1032 drawing'))->convert()->output())
        ->toBe($contents);
});

it('combines only the active JSON output limits', function (?int $outputLimit, ?int $jsonLimit, bool $shouldFail): void {
    config()->set('dwg-converter.max_output_bytes', $outputLimit);
    config()->set('dwg-converter.max_json_output_bytes', $jsonLimit);
    $contents = '{"created_by":"LibreDWG 0.14","FILEHEADER":{},"HEADER":{},"OBJECTS":{},"padding":"' .
        \str_repeat('x', 64) . '"}';
    $runner = FakeProcessRunner::writesFile('drawing.json', $contents);
    app()->instance(ProcessRunner::class, $runner);

    if ($shouldFail) {
        expect(fn () => Dwg::toJson(DwgBinary::from('AC1032 drawing'))->convert())
            ->toThrow(DwgOperationFailed::class, 'output_too_large');

        return;
    }

    expect(Dwg::toJson(DwgBinary::from('AC1032 drawing'))->convert()->output())
        ->toBe($contents);
})->with([
    'general only' => [64, 0, true],
    'JSON only' => [0, 64, true],
    'both disabled' => [0, -1, false],
    'null JSON limit' => [0, null, false],
]);

it('does not enforce a missing JSON limit', function (): void {
    $configuration = config('dwg-converter');
    if (! \is_array($configuration)) {
        throw new RuntimeException('Expected the package configuration array.');
    }

    unset($configuration['max_json_output_bytes']);
    $configuration['max_output_bytes'] = 0;
    config()->set('dwg-converter', $configuration);
    $contents = '{"created_by":"LibreDWG 0.14","FILEHEADER":{},"HEADER":{},"OBJECTS":{},"padding":"' .
        \str_repeat('x', 64) . '"}';
    $runner = FakeProcessRunner::writesFile('drawing.json', $contents);
    app()->instance(ProcessRunner::class, $runner);

    expect(Dwg::toJson(DwgBinary::from('AC1032 drawing'))->convert()->output())
        ->toBe($contents);
});
