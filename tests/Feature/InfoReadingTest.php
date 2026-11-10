<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgCustomProperty;
use Mattmy\DwgConverter\DwgDrawingUnitStatus;
use Mattmy\DwgConverter\DwgInfoTimestamps;
use Mattmy\DwgConverter\DwgManager;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\Converter;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

it('reads the same snapshot once per execution for every source variant', function (string $kind): void {
    $path = \tempnam(\sys_get_temp_dir(), 'dwg-info-');
    if ($path === false) {
        throw new RuntimeException('Unable to create the info fixture.');
    }

    $bytes = 'AC1032 drawing';

    try {
        \file_put_contents($path, $bytes);
        $source = match ($kind) {
            'path' => $path,
            'upload' => new UploadedFile($path, 'untrusted.txt', 'text/plain', null, true),
            default => DwgBinary::from($bytes),
        };
        $runner = FakeProcessRunner::writesFile('drawing.json', '{"FILEHEADER":{"version":"other"},"HEADER":{"INSUNITS":0}}');
        app()->instance(ProcessRunner::class, $runner);
        $directory = config()->string('dwg-converter.temporary_directory');
        $reading = Dwg::info($source);
        expect($runner->commands)->toBe([])->and(\is_dir($directory))->toBeFalse();

        $info = $reading->read();
        expect($info->version)->toEqual(Dwg::version($source))
            ->and($info->drawingUnits->status)->toBe(DwgDrawingUnitStatus::UNSPECIFIED)
            ->and($info->drawingUnits->code)->toBe(0)
            ->and($info->summary)->toBeNull()
            ->and($info->timestamps)->toBeNull()
            ->and($reading->read())->toEqual($info)
            ->and($runner->inputSnapshots)->toBe([$bytes, $bytes])
            ->and(\file_get_contents($path))->toBe($bytes)
            ->and($runner->commands)->toHaveCount(2);
        [$first, $second] = $runner->commands;
        expect(\array_slice($first, 1, 3))->toBe(['-O', 'JSON', '-o'])
            ->and(\basename($first[4]))->toBe('drawing.json')
            ->and(\basename($first[5]))->toBe('input.dwg')
            ->and($first[5])->not->toBe($second[5])
            ->and(\is_dir(\dirname($first[5])))->toBeFalse()
            ->and(\is_dir(\dirname($second[5])))->toBeFalse();
    } finally {
        \unlink($path);
    }
})->with([['path'], ['upload'], ['binary']]);

it('maps all summary fields and preserves valid custom property text and order', function (): void {
    $json = <<<'JSON'
        {"SummaryInfo":{"TITLE":"繁體中文","SUBJECT":" ","AUTHOR":"","KEYWORDS":"one,two",
          "COMMENTS":" A-101 ","HYPERLINKBASE":"relative/","LASTSAVEDBY":"drafter","REVISIONNUMBER":"0017",
          "props":[{"tag":"KEY","value":"0017"},{"tag":"KEY","value":" A-101 "},{"tag":"","value":" "},{"tag":17},{"value":"ok"}]}}
        JSON;
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));

    $info = Dwg::info(DwgBinary::from('AC1099'))->read();
    expect($info->version->format)->toBeNull()
        ->and($info->drawingUnits->status)->toBe(DwgDrawingUnitStatus::UNKNOWN)
        ->and($info->drawingUnits->code)->toBeNull()
        ->and($info->summary?->title)->toBe('繁體中文')
        ->and($info->summary?->subject)->toBe(' ')
        ->and($info->summary?->author)->toBeNull()
        ->and($info->summary?->keywords)->toBe('one,two')
        ->and($info->summary?->comments)->toBe(' A-101 ')
        ->and($info->summary?->hyperlinkBase)->toBe('relative/')
        ->and($info->summary?->lastSavedBy)->toBe('drafter')
        ->and($info->summary?->revisionNumber)->toBe('0017')
        ->and($info->summary?->customProperties)->toEqual([
            new DwgCustomProperty('KEY', '0017'),
            new DwgCustomProperty('KEY', ' A-101 '),
            new DwgCustomProperty(null, ' '),
            new DwgCustomProperty(null, null),
            new DwgCustomProperty(null, 'ok'),
        ]);
});

it('distinguishes explicit empty props from missing or malformed props', function (string $fields, bool $empty): void {
    $json = '{"SummaryInfo":{"TITLE":"known","SUBJECT":42' . $fields . '}}';
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));

    $summary = Dwg::info(DwgBinary::from('AC1032'))->read()->summary;
    expect($summary?->title)->toBe('known')
        ->and($summary?->subject)->toBeNull()
        ->and($summary?->author)->toBeNull()
        ->and($summary?->customProperties)->toBe($empty ? [] : null);
})->with([
    'empty' => [',"props":[]', true],
    'missing' => ['', false],
    'object' => [',"props":{}', false],
    'null' => [',"props":null', false],
    'mixed list' => [',"props":[{"tag":"A"},[]]', false],
]);

it('maps insertion units without coercion', function (string $value, ?int $code, DwgDrawingUnitStatus $status): void {
    $json = '{"HEADER":{"INSUNITS":' . $value . '},"SummaryInfo":[]}';
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));

    $info = Dwg::info(DwgBinary::from('AC1032'))->read();
    expect($info->drawingUnits->code)->toBe($code)
        ->and($info->drawingUnits->status)->toBe($status)
        ->and($info->summary)->toBeNull();
})->with([
    ['0', 0, DwgDrawingUnitStatus::UNSPECIFIED],
    ['24', 24, DwgDrawingUnitStatus::SPECIFIED],
    ['25', 25, DwgDrawingUnitStatus::UNKNOWN],
    ['"0"', null, DwgDrawingUnitStatus::UNKNOWN],
    ['0.0', null, DwgDrawingUnitStatus::UNKNOWN],
    ['null', null, DwgDrawingUnitStatus::UNKNOWN],
]);

it('uses only UTC header timestamps and preserves milliseconds independently of server timezone', function (): void {
    $json = '{"HEADER":{"TDUCREATE":[2440588,45296123],"TDUUPDATE":[2451545,86399999]},"SummaryInfo":{"TDCREATE":[2440588,0]}}';
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));
    $timezone = \date_default_timezone_get();
    \date_default_timezone_set('Asia/Taipei');

    try {
        $info = Dwg::info(DwgBinary::from('AC1032'))->read();
        expect($info->timestamps)->toEqual(new DwgInfoTimestamps('1970-01-01T12:34:56.123Z', '2000-01-01T23:59:59.999Z'));
    } finally {
        \date_default_timezone_set($timezone);
    }
});

it('preserves independently valid times and omits a zero fraction', function (string $invalid): void {
    $json = '{"HEADER":{"TDUCREATE":' . $invalid . ',"TDUUPDATE":[2440587,0]}}';
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));

    expect(Dwg::info(DwgBinary::from('AC1032'))->read()->timestamps)
        ->toEqual(new DwgInfoTimestamps(null, '1969-12-31T00:00:00Z'));
})->with([['[0,0]'], ['[5373485,0]'], ['[2440588,-1]'], ['[2440588,86400000]'], ['[2440588,0,0]'], ['[2440588,"0"]'], ['{"0":2440588,"1":0}'], ['null']]);

it('rejects invalid JSON and non-object roots with the expected previous exception', function (string $json, bool $malformed): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));

    try {
        Dwg::info(DwgBinary::from('AC1032'))->read();

        throw new RuntimeException('Expected an invalid info artifact.');
    } catch (DwgOperationFailed $exception) {
        expect($exception->reason())->toBe('info_invalid')
            ->and($exception->context())->toBe(['operation' => 'info'])
            ->and($exception->getPrevious() instanceof JsonException)->toBe($malformed);
    }
})->with([
    ['[]', false], ['null', false], ['{', true], ["{\"SummaryInfo\":{\"TITLE\":\"\xED\xA0\xBD\"}}", true],
]);

it('retains source, capability and process failures', function (): void {
    $runner = new FakeProcessRunner(static function (): never {
        throw new DwgOperationFailed('process_failed', ['operation' => 'info']);
    });
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::info(DwgBinary::from('bad'))->read())->toThrow(InvalidDwg::class, 'invalid_dwg_header')
        ->and($runner->commands)->toBe([])
        ->and(fn () => Dwg::info(DwgBinary::from('AC1032'))->read())->toThrow(DwgOperationFailed::class, 'process_failed');
    Dwg::clearResolvedInstances();
    app()->forgetInstance(DwgManager::class);
    app()->forgetInstance(Converter::class);
    app()->instance(ProcessRunner::class, new FakeProcessRunner(
        static fn (): string => '',
        new LibreDwgUnavailable('unsupported_tool_capability'),
    ));
    expect(fn () => Dwg::info(DwgBinary::from('AC1032'))->read())->toThrow(LibreDwgUnavailable::class, 'unsupported_tool_capability');
});

it('enforces the same input and output limits as JSON export', function (int $input, int $general, int $json, string $reason): void {
    config()->set('dwg-converter.max_input_bytes', $input);
    config()->set('dwg-converter.max_output_bytes', $general);
    config()->set('dwg-converter.max_json_output_bytes', $json);
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', '{}'));

    expect(fn () => Dwg::info(DwgBinary::from('AC1032'))->read())
        ->toThrow($reason === 'input_too_large' ? InvalidDwg::class : DwgOperationFailed::class, $reason);
})->with([[5, 10, 10, 'input_too_large'], [6, 1, 10, 'output_too_large'], [6, 10, 1, 'output_too_large']]);

it('rejects an empty artifact as missing', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', ''));

    expect(fn () => Dwg::info(DwgBinary::from('AC1032'))->read())->toThrow(DwgOperationFailed::class, 'output_missing');
});

it('checks the final artifact size when a smaller size was cached', function (): void {
    config()->set('dwg-converter.max_json_output_bytes', 2);
    app()->instance(ProcessRunner::class, new FakeProcessRunner(static function (array $_command, Workspace $workspace): void {
        $output = $workspace->outputPath('drawing.json');
        \file_put_contents($output, '{}');
        expect(\is_link($output))->toBeFalse()
            ->and(\filesize($output))->toBe(2);
        \file_put_contents($output, '{"HEADER":{}}');
    }));

    expect(fn () => Dwg::info(DwgBinary::from('AC1032'))->read())->toThrow(DwgOperationFailed::class, 'output_too_large');
});
