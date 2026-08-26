<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Tests\Fakes;

use Closure;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use Override;

/**
 * Supplies deterministic LibreDWG process behavior to public Interface tests.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<string> */
    public array $inputSnapshots = [];

    /**
     * @param  Closure(list<string>, Workspace, float, int, string, ?string): void  $run
     */
    public function __construct(
        private readonly Closure $run,
        private readonly ?LibreDwgUnavailable $availabilityFailure = null,
    ) {}

    /**
     * Create a fake that writes one process-generated workspace file.
     */
    public static function writesFile(string $name, string $contents): self
    {
        return new self(static function (
            array $_command,
            Workspace $workspace,
            float $_timeout,
            int $_maxOutputBytes,
            string $_operation,
            ?string $_stdoutPath,
        ) use ($name, $contents): void {
            if (\file_put_contents($workspace->outputPath($name), $contents) === false) {
                throw new \RuntimeException('Unable to write a fake process output.');
            }
        });
    }

    /**
     * Create a fake that writes SVG bytes to the supplied stdout path.
     */
    public static function writesStdout(string $contents): self
    {
        return new self(static function (
            array $_command,
            Workspace $_workspace,
            float $_timeout,
            int $_maxOutputBytes,
            string $_operation,
            ?string $stdoutPath,
        ) use ($contents): void {
            if ($stdoutPath === null || \file_put_contents($stdoutPath, $contents) === false) {
                throw new \RuntimeException('Unable to write a fake stdout output.');
            }
        });
    }

    /**
     * Either accept the executable or emit the configured environment failure.
     *
     * @throws LibreDwgUnavailable
     */
    #[Override]
    public function assertAvailable(
        string $executable,
        string $operation,
        ?string $expectedTool = null,
        string $stage = 'convert',
    ): void {
        if ($this->availabilityFailure instanceof LibreDwgUnavailable) {
            throw $this->availabilityFailure;
        }
    }

    /**
     * Record argv and invoke the test's process behavior.
     *
     * @param  list<string>  $command
     */
    #[Override]
    public function run(
        array $command,
        Workspace $workspace,
        float $timeout,
        int $maxOutputBytes,
        string $operation,
        ?string $stdoutPath = null,
        string $stage = 'convert',
    ): void {
        $this->commands[] = $command;
        $snapshot = \file_get_contents($workspace->inputPath());
        if (! \is_string($snapshot)) {
            throw new \RuntimeException('Unable to read the package input snapshot.');
        }
        $this->inputSnapshots[] = $snapshot;
        ($this->run)(
            $command,
            $workspace,
            $timeout,
            $maxOutputBytes,
            $operation,
            $stdoutPath,
        );
    }
}
