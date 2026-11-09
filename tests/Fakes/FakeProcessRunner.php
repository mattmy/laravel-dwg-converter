<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Tests\Fakes;

use Closure;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;
use RuntimeException;

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
     * Configure the process callback and any capability failure.
     *
     * @param  Closure(list<string>, Workspace, float, string): (void|string)  $run
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
            string $_operation,
        ) use ($name, $contents): void {
            if (\file_put_contents($workspace->outputPath($name), $contents) === false) {
                throw new RuntimeException('Unable to write a fake process output.');
            }
        });
    }

    /**
     * Either accept the executable or emit the configured environment failure.
     *
     * @throws LibreDwgUnavailable
     */
    public function assertAvailable(
        string $executable,
        string $operation,
        ?string $expectedTool = null,
        string $stage = 'convert',
        ?string $requiredOption = null,
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
    public function run(
        array $command,
        Workspace $workspace,
        float $timeout,
        string $operation,
        string $stage = 'convert',
    ): void {
        $this->recordAndRun($command, $workspace, $timeout, $operation);
    }

    /**
     * Record a query and return the configured stdout without creating a file.
     *
     * @param  list<string>  $command
     */
    public function capture(
        array $command,
        Workspace $workspace,
        float $timeout,
        string $operation,
        string $stage = 'convert',
        ?int $maxOutputBytes = null,
    ): string {
        $stdout = $this->recordAndRun($command, $workspace, $timeout, $operation);
        if ($stdout === null) {
            throw new RuntimeException('The fake query must return stdout.');
        }

        return $stdout;
    }

    /**
     * Record the command and its input snapshot before running the callback.
     *
     * @param  list<string>  $command
     */
    private function recordAndRun(array $command, Workspace $workspace, float $timeout, string $operation): ?string
    {
        $this->commands[] = $command;
        $snapshot = \file_get_contents($workspace->inputPath());
        if (! \is_string($snapshot)) {
            throw new RuntimeException('Unable to read the package input snapshot.');
        }
        $this->inputSnapshots[] = $snapshot;
        $stdout = ($this->run)($command, $workspace, $timeout, $operation);

        return \is_string($stdout) ? $stdout : null;
    }
}
