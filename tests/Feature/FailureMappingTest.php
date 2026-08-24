<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

it('maps invalid published configuration to an environment failure', function (
    string $key,
    mixed $value,
): void {
    config()->set("dwg-converter.{$key}", $value);

    expect(fn () => Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract())
        ->toThrow(LibreDwgUnavailable::class, 'invalid_configuration');
})->with([
    'timeout type' => ['timeout', 'fast'],
    'timeout range' => ['timeout', 0],
    'input limit' => ['max_input_bytes', 0],
    'output limit' => ['max_output_bytes', -1],
    'temporary root' => ['temporary_directory', 'relative/path'],
    'executables shape' => ['executables', 'dwgbmp'],
]);

it('checks only the executable needed by the selected operation', function (): void {
    config()->set('dwg-converter.executables.dwg2svg', '');
    $runner = FakeProcessRunner::writesFile(
        'input.png',
        "\x89PNG\r\n\x1a\n" . \str_repeat("\0", 12) . "IEND\xaeB`\x82",
    );
    app()->instance(ProcessRunner::class, $runner);

    expect(Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract()->output())
        ->toStartWith("\x89PNG");
});

it('preserves an executable availability failure', function (): void {
    $failure = new LibreDwgUnavailable('executable_not_found', ['operation' => 'thumbnail']);
    $runner = new FakeProcessRunner(
        static function (
            array $_command,
            Workspace $_workspace,
            float $_timeout,
            int $_maxOutputBytes,
            string $_operation,
            ?string $_stdoutPath,
        ): void {},
        $failure,
    );
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract())
        ->toThrow($failure);
});

it('preserves a stable process failure', function (): void {
    $runner = new FakeProcessRunner(static function (
        array $_command,
        Workspace $_workspace,
        float $_timeout,
        int $_maxOutputBytes,
        string $_operation,
        ?string $_stdoutPath,
    ): void {
        throw new DwgOperationFailed('process_failed', [
            'operation' => 'thumbnail',
            'exit_code' => 1,
        ]);
    });
    app()->instance(ProcessRunner::class, $runner);

    try {
        Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract();
    } catch (DwgOperationFailed $failure) {
        expect($failure->reason())->toBe('process_failed')
            ->and($failure->context())->toBe([
                'operation' => 'thumbnail',
                'exit_code' => 1,
            ]);

        return;
    }

    throw new RuntimeException('The process failure was not thrown.');
});

it('rejects a source larger than the configured input limit', function (): void {
    config()->set('dwg-converter.max_input_bytes', 6);

    expect(fn () => Dwg::thumbnail(DwgBinary::from('AC1032 drawing'))->extract())
        ->toThrow(InvalidDwg::class, 'input_too_large');
});
