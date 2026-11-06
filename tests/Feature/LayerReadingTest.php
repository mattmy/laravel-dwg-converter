<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgLayer;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

it('selects ON layers natively without changing the original operation', function (): void {
    $runner = new FakeProcessRunner(static function (array $command): string {
        return \in_array('--on', $command, true) ? " + \t 圖層 \n +l\t 圖層 \n" : " + \t 圖層 \n +l\t 圖層 \nf+ \tFrozen\nf+l\tFrozenLocked\n - \tOff\n -l\tOffLocked\nf- \tBoth\nf-l\tBothLocked\n";
    });
    app()->instance(ProcessRunner::class, $runner);
    $reading = Dwg::layers(DwgBinary::from('AC1032 fixture'));
    $selected = $reading->onlyOn()->onlyOn();
    expect($runner->commands)->toBe([]);
    $layers = $selected->read();
    expect($layers->pluck('name')->all())->toBe([' 圖層 ', ' 圖層 '])
        ->and($layers->pluck('isLocked')->all())->toBe([false, true])
        ->and($layers->keys()->all())->toBe([0, 1])
        ->and(\array_slice($runner->commands[0], 1, 2))->toBe(['--flags', '--on'])
        ->and($reading->read())->toHaveCount(8)
        ->and($selected->read())->toHaveCount(2)
        ->and($runner->commands)->toHaveCount(3)
        ->and(\in_array('--on', $runner->commands[1], true))->toBeFalse();
});

it('reads every flag combination without trimming names or sharing operation state', function (): void {
    $stdout = '';
    foreach ([' ', 'f'] as $frozen) {
        foreach (['+', '-'] as $off) {
            foreach ([' ', 'l'] as $locked) {
                $stdout .= "{$frozen}{$off}{$locked}\t 圖層\t名稱 \r\n";
            }
        }
    }
    $runner = new FakeProcessRunner(static fn (): string => $stdout);
    app()->instance(ProcessRunner::class, $runner);
    $reading = Dwg::layers(DwgBinary::from('AC1032 fixture'));
    expect($runner->commands)->toBe([]);

    $layers = $reading->read();
    expect($layers)->toBeInstanceOf(Collection::class)
        ->and($layers->keys()->all())->toBe(\range(0, 7))
        ->and($layers->pluck('name')->unique()->all())->toBe([" 圖層\t名稱 "])
        ->and($layers->where('isFrozen', false))->toHaveCount(4)
        ->and($layers->where('isFrozen', false)->where('isOff', true))->toHaveCount(2)
        ->and($layers->pluck('isLocked')->all())->toBe([false, true, false, true, false, true, false, true])
        ->and($runner->commands)->toHaveCount(1)
        ->and($runner->commands[0][0])->toBe('dwglayers')
        ->and($runner->commands[0][1])->toBe('--flags')
        ->and(\scandir(config()->string('dwg-converter.temporary_directory')))->toBe(['.', '..']);
    $layer = $layers->first();
    if (! $layer instanceof DwgLayer) {
        throw new RuntimeException('Expected a layer record.');
    }
    expect($layer)->toBeInstanceOf(DwgLayer::class)
        ->and(fn () => (new ReflectionProperty(DwgLayer::class, 'name'))->setValue($layer, 'changed'))->toThrow(Error::class);
    $reading->read();
    expect($runner->commands)->toHaveCount(2);
});

it('reads LF records and zero-byte empty results', function (string $stdout, int $count, bool $onlyOn): void {
    app()->instance(ProcessRunner::class, new FakeProcessRunner(static fn (): string => $stdout));
    expect(($onlyOn ? Dwg::layers(DwgBinary::from('AC1032 fixture'))->onlyOn() : Dwg::layers(DwgBinary::from('AC1032 fixture')))->read())->toHaveCount($count);
})->with([['', 0], [" + \t0\n", 1], ["f-l\tLayer\n + \tlayer\n", 2]])->with([[false], [true]]);

it('rejects the entire malformed layer result', function (string $stdout, bool $onlyOn): void {
    app()->instance(ProcessRunner::class, new FakeProcessRunner(static fn (): string => $stdout));
    expect(fn () => ($onlyOn ? Dwg::layers(DwgBinary::from('AC1032 fixture'))->onlyOn() : Dwg::layers(DwgBinary::from('AC1032 fixture')))->read())
        ->toThrow(DwgOperationFailed::class, 'layers_invalid');
})->with([
    [" + \tvalid\ninvalid\n"], ["x+ \tname\n"], [" +x\tname\n"],
    [" ? \tname\n"], [" + \t\n"], [" + \tpartial"], [" + \t\xff\n"],
    [" + \tname\n\n"], [" + \tname\r"], [" + \tdiagnostic\nNo layers found\n"],
])->with([[false], [true]]);

it('snapshots all layer source types without taking ownership', function (string $type, bool $onlyOn): void {
    $path = \tempnam(\sys_get_temp_dir(), 'dwg-layers-');
    if ($path === false) {
        throw new RuntimeException('Unable to create source fixture.');
    }
    \file_put_contents($path, 'AC1032 fixture');

    try {
        $source = match ($type) {
            'path' => $path,
            'upload' => new UploadedFile($path, 'untrusted.txt', 'text/plain', null, true),
            'binary' => DwgBinary::from('AC1032 fixture'),
            default => throw new LogicException('Unknown source type.'),
        };
        $runner = new FakeProcessRunner(static fn (): string => " + \t0\n");
        app()->instance(ProcessRunner::class, $runner);
        ($onlyOn ? Dwg::layers($source)->onlyOn() : Dwg::layers($source))->read();
        expect($runner->inputSnapshots)->toBe(['AC1032 fixture'])
            ->and(\file_get_contents($path))->toBe('AC1032 fixture');
    } finally {
        \unlink($path);
    }
})->with(['path', 'upload', 'binary'])->with([[false], [true]]);

it('does not start a layer tool for an invalid source', function (): void {
    $runner = new FakeProcessRunner(static fn (): string => '');
    app()->instance(ProcessRunner::class, $runner);
    $reading = Dwg::layers('relative.dwg');
    expect(fn () => $reading->read())->toThrow(InvalidDwg::class, 'input_not_absolute')
        ->and($runner->commands)->toBe([]);
});

it('preserves layer process and capability failures', function (string $reason, bool $onlyOn): void {
    $previous = new RuntimeException('Underlying failure.');
    $failure = $reason === 'unsupported_tool_capability'
        ? new LibreDwgUnavailable($reason, ['operation' => 'layers'], $previous)
        : ($reason === 'libredwg_rejected_input'
            ? new InvalidDwg($reason, ['operation' => 'layers'], $previous)
            : new DwgOperationFailed($reason, ['operation' => 'layers'], $previous));
    app()->instance(ProcessRunner::class, new FakeProcessRunner(
        static fn (): never => throw $failure,
        $failure instanceof LibreDwgUnavailable ? $failure : null,
    ));

    try {
        ($onlyOn ? Dwg::layers(DwgBinary::from('AC1032 fixture'))->onlyOn() : Dwg::layers(DwgBinary::from('AC1032 fixture')))->read();
    } catch (Throwable $caught) {
        expect($caught)->toBe($failure)->and($caught->getPrevious())->toBe($previous);

        return;
    }

    throw new RuntimeException('Expected the original failure.');
})->with(['unsupported_tool_capability', 'process_failed', 'process_timed_out', 'output_too_large', 'libredwg_rejected_input'])->with([[false], [true]]);

it('passes the configured timeout and stdout limit to capture', function (?int $limit, ?int $expected, bool $onlyOn): void {
    config()->set('dwg-converter.timeout', 2.5);
    config()->set('dwg-converter.max_output_bytes', $limit);
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('assertAvailable')->once()->with('dwglayers', 'layers', 'dwglayers', 'dwglayers', $onlyOn ? '--on' : null);
    $runner->shouldReceive('capture')->once()->with(
        Mockery::type('array'),
        Mockery::type(Workspace::class),
        2.5,
        'layers',
        'dwglayers',
        $expected,
    )->andReturn('');
    app()->instance(ProcessRunner::class, $runner);
    expect(($onlyOn ? Dwg::layers(DwgBinary::from('AC1032 fixture'))->onlyOn() : Dwg::layers(DwgBinary::from('AC1032 fixture')))->read())->toBeEmpty();
})->with([[8, 8], [null, null], [0, null], [-1, null]])->with([[false], [true]]);
