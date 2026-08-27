<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

it('rejects a forged DWG candidate through every public operation', function (string $operation): void {
    $runner = new FakeProcessRunner(static function (
        array $_command,
        Workspace $_workspace,
        float $_timeout,
        ?int $_maxOutputBytes,
        string $operation,
        ?string $_stdoutPath,
    ): void {
        throw new InvalidDwg('libredwg_rejected_input', ['operation' => $operation]);
    });
    app()->instance(ProcessRunner::class, $runner);

    $source = DwgBinary::from('AC1032 forged bytes');

    $operationResult = match ($operation) {
        'dxf' => static fn () => Dwg::toDxf($source)->convert(),
        'image' => static fn () => Dwg::toImage($source)->convert(),
        'json' => static fn () => Dwg::toJson($source)->convert(),
        'thumbnail' => static fn () => Dwg::thumbnail($source)->extract(),
        default => throw new RuntimeException('Unknown acceptance operation.'),
    };

    expect($operationResult)->toThrow(InvalidDwg::class, 'libredwg_rejected_input')
        ->and($runner->commands)->toHaveCount(1)
        ->and($runner->inputSnapshots)->toBe(['AC1032 forged bytes']);
})->with([
    'DXF' => ['dxf'],
    'image' => ['image'],
    'JSON' => ['json'],
    'thumbnail' => ['thumbnail'],
]);
