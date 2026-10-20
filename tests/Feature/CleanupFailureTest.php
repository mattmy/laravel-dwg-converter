<?php

declare(strict_types=1);

use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgOutput;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FailedCleanupStream;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;
use Mockery\MethodCall;
use Mockery\ReceivedMethodCalls;

beforeEach(function (): void {
    \class_exists(ReceivedMethodCalls::class);
    \class_exists(MethodCall::class);
    FailedCleanupStream::$exists = true;
    FailedCleanupStream::$attempts = 0;
    FailedCleanupStream::$failureMethod = 'scandir';
    FailedCleanupStream::$failureMode = 'exception';
    if (\in_array('cleanupfailure', \stream_get_wrappers(), true)) {
        \stream_wrapper_unregister('cleanupfailure');
    }
    \stream_wrapper_register('cleanupfailure', FailedCleanupStream::class);
});

afterEach(function (): void {
    \stream_wrapper_unregister('cleanupfailure');
    FailedCleanupStream::$exists = true;
    FailedCleanupStream::$failureMethod = 'scandir';
    FailedCleanupStream::$failureMode = 'exception';
    \clearstatcache();
});

/** Create a test-only workspace that cannot remove any actual files. */
function failingCleanupWorkspace(): Workspace
{
    $workspace = (new ReflectionClass(Workspace::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Workspace::class, 'directory'))->setValue($workspace, 'cleanupfailure://workspace');

    return $workspace;
}

it('reports each cleanup failure once without exposing private diagnostics', function (string $method, string $mode): void {
    FailedCleanupStream::$failureMethod = $method;
    FailedCleanupStream::$failureMode = $mode;
    Log::shouldReceive('warning')->once()->with('DWG converter temporary cleanup failed.', Mockery::on(
        static fn (array $context): bool => \array_keys($context) === ['operation', 'phase', 'exception_class']
            && $context['operation'] === 'dxf'
            && $context['phase'] === 'conversion'
            && \in_array($context['exception_class'], [RuntimeException::class, ErrorException::class, 'RuntimeException@anonymous'], true),
    ));

    $previous = static fn (): bool => true;
    \set_error_handler($previous);

    try {
        failingCleanupWorkspace()->cleanup('dxf', 'conversion');
        $restored = \set_error_handler($previous);
        \restore_error_handler();
        expect($restored)->toBe($previous)
            ->and(FailedCleanupStream::$attempts)->toBe(1);
    } finally {
        \restore_error_handler();
    }
})->with(['scandir', 'unlink', 'rmdir'])->with(['exception', 'false', 'warning', 'anonymous']);

it('preserves successful terminal delivery when cleanup fails', function (string $terminal, bool $loggerFails): void {
    $workspace = Workspace::fromSource(DwgBinary::from('AC1032 drawing'), config()->string('dwg-converter.temporary_directory'), 1024, 'dxf');
    $path = $workspace->outputPath('output.dxf');
    \file_put_contents($path, 'artifact');
    $output = new DwgOutput($workspace, $path, 'dxf', 'image/vnd.dxf', 1024, 'dxf');
    new File($path);
    $log = Log::shouldReceive('warning')->once()->with('DWG converter temporary cleanup failed.', [
        'operation' => 'dxf', 'phase' => 'terminal', 'exception_class' => RuntimeException::class,
    ]);
    if ($loggerFails) {
        $log->andThrow(new RuntimeException('Logger unavailable.'));
    }
    if ($terminal === 'storeAs') {
        Storage::shouldReceive('disk')->with('exports')->andReturnSelf();
        Storage::shouldReceive('putFileAs')->once()->andReturn('drawing.dxf');
    }

    // realpath still resolves the real fixture; only stream I/O and cleanup are replaced.
    \stream_wrapper_unregister('file');
    \stream_wrapper_register('file', FailedCleanupStream::class);

    try {
        $value = $terminal === 'output' ? $output->output() : $output->storeAs('', 'drawing', 'exports');
    } finally {
        \stream_wrapper_restore('file');
        \clearstatcache();
        $workspace->cleanup();
    }

    expect($value)->toBe($terminal === 'output' ? 'artifact' : 'drawing.dxf')
        ->and(fn () => $output->output())->toThrow(LogicException::class)
        ->and(FailedCleanupStream::$attempts)->toBe(1);
    unset($output);
    expect(FailedCleanupStream::$attempts)->toBe(1);
})->with(['output', 'storeAs'])->with([[false], [true]]);

it('does not replace a primary failure even when warning logging fails', function (): void {
    $primary = new RuntimeException('Primary failure.');
    Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('Logger unavailable.'));

    try {
        try {
            throw $primary;
        } finally {
            failingCleanupWorkspace()->cleanup('dxf', 'snapshot');
        }
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($primary);
    }
});

it('does not throw from an abandoned result destructor', function (bool $unwinding, bool $loggerFails): void {
    $log = Log::shouldReceive('warning')->once();
    if ($loggerFails) {
        $log->andThrow(new RuntimeException('Logger unavailable.'));
    } else {
        $log->with('DWG converter temporary cleanup failed.', [
            'operation' => 'dxf', 'phase' => 'destructor', 'exception_class' => RuntimeException::class,
        ]);
    }
    $primary = new RuntimeException('Application failure.');
    $abandon = static function () use ($unwinding, $primary): void {
        $workspace = failingCleanupWorkspace();
        $output = new DwgOutput($workspace, $workspace->outputPath('output.dxf'), 'dxf', 'image/vnd.dxf', null, 'dxf');
        if ($unwinding) {
            throw $primary;
        }
    };

    try {
        $abandon();
        expect($unwinding)->toBeFalse();
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($primary);
    }
    expect(FailedCleanupStream::$attempts)->toBe(1);
})->with([[false], [true]])->with([[false], [true]]);

it('preserves every conversion failure and its previous exception when cleanup fails', function (string $operation): void {
    $previous = new RuntimeException('Underlying process failure.');
    $primary = new DwgOperationFailed('process_failed', ['operation' => $operation], $previous);
    Log::shouldReceive('warning')->once()->with('DWG converter temporary cleanup failed.', [
        'operation' => $operation, 'phase' => 'conversion', 'exception_class' => RuntimeException::class,
    ]);
    $realWorkspace = null;
    $runner = new FakeProcessRunner(static function (array $_command, Workspace $workspace) use ($primary, &$realWorkspace): void {
        $realWorkspace = $workspace;
        \stream_wrapper_unregister('file');
        \stream_wrapper_register('file', FailedCleanupStream::class);

        throw $primary;
    });
    app()->instance(ProcessRunner::class, $runner);
    $source = DwgBinary::from('AC1032 drawing');
    $caught = null;

    try {
        match ($operation) {
            'dxf' => Dwg::toDxf($source)->convert(),
            'json' => Dwg::toJson($source)->convert(),
            'image' => Dwg::toImage($source)->convert(),
            'thumbnail' => Dwg::thumbnail($source)->extract(),
            default => throw new LogicException('Unknown operation.'),
        };

        throw new RuntimeException('Expected the original conversion failure.');
    } catch (DwgOperationFailed $exception) {
        $caught = $exception;
    } finally {
        if ($realWorkspace instanceof Workspace) {
            \stream_wrapper_restore('file');
            \clearstatcache();
            $realWorkspace->cleanup();
        }
    }

    expect($caught)->toBe($primary)->and($caught->getPrevious())->toBe($previous);
})->with(['dxf', 'json', 'image', 'thumbnail']);

it('preserves snapshot rejection when cleanup also fails', function (): void {
    \class_exists(Workspace::class);
    \class_exists(InvalidDwg::class);
    $source = DwgBinary::from('invalid drawing');
    $directory = config()->string('dwg-converter.temporary_directory');
    $caught = null;
    Log::shouldReceive('warning')->once()->with('DWG converter temporary cleanup failed.', [
        'operation' => 'dxf', 'phase' => 'snapshot', 'exception_class' => RuntimeException::class,
    ]);
    \stream_wrapper_unregister('file');
    \stream_wrapper_register('file', FailedCleanupStream::class);

    try {
        Workspace::fromSource($source, $directory, 1024, 'dxf');
    } catch (InvalidDwg $exception) {
        $caught = $exception;
    } finally {
        \stream_wrapper_restore('file');
        \clearstatcache();
    }

    expect($caught?->reason())->toBe('invalid_dwg_header')
        ->and(FailedCleanupStream::$attempts)->toBe(1);
});
