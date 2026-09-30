<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

it('writes a JSON artifact through the public interface', function (): void {
    $contents = '{"created_by":"LibreDWG 0.14","FILEHEADER":{},"HEADER":{},"OBJECTS":{}}';
    $runner = FakeProcessRunner::writesFile('drawing.json', $contents);
    app()->instance(ProcessRunner::class, $runner);

    $output = Dwg::toJson(DwgBinary::from('AC1032 drawing'))->convert();

    expect($runner->commands)->toHaveCount(1)
        ->and($runner->commands[0][0])->toBe('dwgread')
        ->and($runner->commands[0][1])->toBe('-O')
        ->and($runner->commands[0][2])->toBe('JSON')
        ->and($runner->commands[0][3])->toBe('-o')
        ->and($runner->commands[0])->not->toContain('--as')
        ->and($output->extension())->toBe('json')
        ->and($output->mimeType())->toBe('application/json')
        ->and($output->output())->toBe($contents);
});

it('rejects an invalid JSON artifact', function (): void {
    $runner = FakeProcessRunner::writesFile(
        'drawing.json',
        '{"created_by":"LibreDWG 0.14","FILEHEADER":{},',
    );
    app()->instance(ProcessRunner::class, $runner);

    try {
        Dwg::toJson(DwgBinary::from('AC1032 drawing'))->convert();
    } catch (DwgOperationFailed $failure) {
        expect($failure->reason())->toBe('json_invalid')
            ->and($failure->getPrevious())->toBeInstanceOf(JsonException::class);

        return;
    }

    throw new RuntimeException('The invalid JSON artifact was not rejected.');
});

it('preserves any valid JSON structure returned by dwgread', function (string $contents): void {
    $runner = FakeProcessRunner::writesFile('drawing.json', $contents);
    app()->instance(ProcessRunner::class, $runner);

    $output = Dwg::toJson(DwgBinary::from('AC1032 drawing'))->convert();

    expect($output->output())->toBe($contents);
})->with([
    'nested object' => ['{"payload":{"FILEHEADER":{},"HEADER":{},"OBJECTS":{}}}'],
    'different key order' => ['{"HEADER":{},"FILEHEADER":{},"OBJECTS":{}}'],
    'array root' => ['[{"type":"LINE"}]'],
    'null root' => ['null'],
]);

it('rejects JSON output above its dedicated validation limit', function (): void {
    config()->set('dwg-converter.max_json_output_bytes', 64);
    $runner = FakeProcessRunner::writesFile(
        'drawing.json',
        '{"created_by":"LibreDWG 0.14","FILEHEADER":{},"HEADER":{},"OBJECTS":{},"padding":"' .
            \str_repeat('x', 64) . '"}',
    );
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::toJson(DwgBinary::from('AC1032 drawing'))->convert())
        ->toThrow(DwgOperationFailed::class, 'output_too_large');
});

it('rejects a non-integer JSON validation limit', function (): void {
    config()->set('dwg-converter.max_json_output_bytes', 'large');

    expect(fn () => Dwg::toJson(DwgBinary::from('AC1032 drawing'))->convert())
        ->toThrow(LibreDwgUnavailable::class, 'invalid_configuration');
});

it('uses the general output limit when it is lower than the JSON limit', function (): void {
    config()->set('dwg-converter.max_output_bytes', 64);
    config()->set('dwg-converter.max_json_output_bytes', 65);
    $runner = FakeProcessRunner::writesFile(
        'drawing.json',
        '{"created_by":"LibreDWG 0.14","FILEHEADER":{},"HEADER":{},"OBJECTS":{},"padding":"' .
            \str_repeat('x', 64) . '"}',
    );
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::toJson(DwgBinary::from('AC1032 drawing'))->convert())
        ->toThrow(DwgOperationFailed::class, 'output_too_large');
});

it('uses an uploaded source stem when no storage name is provided', function (): void {
    $source = \tempnam(\sys_get_temp_dir(), 'dwg-upload-');
    if ($source === false || \file_put_contents($source, 'AC1032 drawing') === false) {
        throw new RuntimeException('Unable to create the upload fixture.');
    }

    try {
        Storage::fake('exports');
        $runner = FakeProcessRunner::writesFile('drawing.json', '{"FILEHEADER":{}}');
        app()->instance(ProcessRunner::class, $runner);
        $upload = new UploadedFile($source, 'floor-plan.dwg', 'application/octet-stream', null, true);

        $stored = Dwg::toJson($upload)->convert()->storeAs('', disk: 'exports');

        expect($stored)->toBe('floor-plan.json')
            ->and(Storage::disk('exports')->exists($stored))->toBeTrue();
    } finally {
        if (\is_file($source)) {
            \unlink($source);
        }
    }
});
