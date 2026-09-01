<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Internal;

use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Override;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs LibreDWG commands through Symfony Process.
 */
final class SymfonyProcessRunner implements ProcessRunner
{
    private const float CAPABILITY_PROBE_TIMEOUT = 10.0;

    private const int STDERR_LIMIT = 4096;

    /** @var array<string, list<string>> */
    private const array REQUIRED_CAPABILITIES = [
        'dwgbmp' => ['dwgfile'],
        'dwg2dxf' => ['--as', '-o'],
        'dwgread' => ['--format', 'json', '-o'],
        'libreoffice' => ['--headless', '--convert-to', '--outdir'],
        'imagemagick' => ['-fuzz', '-trim', '-alpha'],
    ];

    /**
     * Verify that an executable starts, identifies itself, and exposes the required CLI shape.
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
        if ($executable === '') {
            throw new LibreDwgUnavailable('executable_not_found', $this->context($operation, $stage));
        }

        $probeStartedAt = \microtime(true);

        try {
            $process = $this->probe($executable, '--version', self::CAPABILITY_PROBE_TIMEOUT);
        } catch (\Throwable $exception) {
            throw new LibreDwgUnavailable('executable_not_found', $this->context($operation, $stage), $exception);
        }

        $reportedVersion = \trim($process->getOutput() . $process->getErrorOutput());
        $expectedTool ??= match ($operation) {
            'thumbnail' => 'dwgbmp',
            'dxf' => 'dwg2dxf',
            'json' => 'dwgread',
            default => '',
        };
        if (
            $expectedTool === ''
            || ! $process->isSuccessful()
            || ! \str_contains(\strtolower($reportedVersion), $expectedTool)
            || ! $this->supportsRequiredVersion($expectedTool, $reportedVersion)
        ) {
            throw new LibreDwgUnavailable('unsupported_tool_capability', [
                ...$this->context($operation, $stage),
                'reported_version' => $this->summary($reportedVersion),
            ]);
        }

        $remainingTimeout = self::CAPABILITY_PROBE_TIMEOUT - (\microtime(true) - $probeStartedAt);

        try {
            if ($remainingTimeout <= 0) {
                throw new \RuntimeException('Capability probe timeout exhausted.');
            }

            $helpProcess = $this->probe($executable, '--help', $remainingTimeout);
        } catch (\Throwable $exception) {
            throw new LibreDwgUnavailable('unsupported_tool_capability', [
                ...$this->context($operation, $stage),
                'reported_version' => $this->summary($reportedVersion),
            ], $exception);
        }

        $help = \strtolower($helpProcess->getOutput() . $helpProcess->getErrorOutput());
        if (! $helpProcess->isSuccessful() || ! $this->hasRequiredCapabilities($expectedTool, $help)) {
            throw new LibreDwgUnavailable('unsupported_tool_capability', [
                ...$this->context($operation, $stage),
                'reported_version' => $this->summary($reportedVersion),
            ]);
        }
    }

    /**
     * Execute argv and optionally stream stdout into a private file.
     *
     * @param  list<string>  $command
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     */
    #[Override]
    public function run(
        array $command,
        Workspace $workspace,
        float $timeout,
        ?int $maxOutputBytes,
        string $operation,
        ?string $stdoutPath = null,
        string $stage = 'convert',
    ): void {
        $stderr = '';
        $tooLarge = false;
        $stream = null;
        if ($stdoutPath !== null) {
            $stream = \fopen($stdoutPath, 'xb');
            if ($stream === false) {
                throw new DwgOperationFailed('output_missing', $this->context($operation, $stage));
            }
        }

        try {
            $process = new Process($command, $workspace->directory());
            $process->setTimeout($timeout);
            $process->run(function (string $type, string $buffer) use (&$stderr, &$tooLarge, $stream, $maxOutputBytes, &$process): void {
                if ($type === Process::ERR) {
                    $stderr = \substr($stderr . $buffer, 0, self::STDERR_LIMIT);

                    return;
                }

                if ($stream === null || $tooLarge) {
                    return;
                }

                if (\fwrite($stream, $buffer) !== \strlen($buffer)) {
                    $tooLarge = true;
                    $process->stop();

                    return;
                }

                $position = \ftell($stream);
                if ($position === false || ($maxOutputBytes !== null && $position > $maxOutputBytes)) {
                    $tooLarge = true;
                    $process->stop();
                }
            });
        } catch (ProcessTimedOutException $exception) {
            throw new DwgOperationFailed('process_timed_out', $this->context($operation, $stage), $exception);
        } catch (\Throwable $exception) {
            throw new DwgOperationFailed('process_failed', $this->context($operation, $stage), $exception);
        } finally {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }

        if ($tooLarge) {
            throw new DwgOperationFailed('output_too_large', $this->context($operation, $stage));
        }

        if (! $process->isSuccessful()) {
            $context = [
                ...$this->context($operation, $stage),
                'exit_code' => $process->getExitCode(),
                'stderr' => $this->summary($stderr),
            ];
            if ($this->isRejectedInput($stderr)) {
                throw new InvalidDwg('libredwg_rejected_input', $context);
            }

            throw new DwgOperationFailed('process_failed', $context);
        }
    }

    /**
     * Recognize LibreDWG diagnostics that specifically reject the input file.
     */
    private function isRejectedInput(string $stderr): bool
    {
        $stderr = \strtolower($stderr);

        foreach (['unable to read file', 'read error', 'failed to decode', 'dwg too small', 'invalid dwg'] as $indicator) {
            if (\str_contains($stderr, $indicator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return a small path-free diagnostic fragment.
     */
    private function summary(string $value): string
    {
        $value = \preg_replace('/(?:[A-Za-z]:)?[\\\\\/][^\s]*/', '[path]', $value) ?? '';

        return \substr(\trim($value), 0, self::STDERR_LIMIT);
    }

    /**
     * Execute one bounded executable probe.
     */
    private function probe(string $executable, string $argument, float $timeout): Process
    {
        $process = new Process([$executable, $argument]);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    /**
     * Confirm that the tool's help output lists every option used by the package.
     */
    private function hasRequiredCapabilities(string $tool, string $help): bool
    {
        foreach (self::REQUIRED_CAPABILITIES[$tool] ?? [] as $capability) {
            if (! \str_contains($help, $capability)) {
                return false;
            }
        }

        return isset(self::REQUIRED_CAPABILITIES[$tool]);
    }

    /**
     * Enforce version floors for capabilities that cannot be proven from help output alone.
     */
    private function supportsRequiredVersion(string $tool, string $reportedVersion): bool
    {
        if ($tool === 'libreoffice') {
            return \preg_match('/libreoffice\s+(\d+\.\d+)/i', $reportedVersion, $matches) === 1
                && \version_compare($matches[1], '7.4', '>=');
        }

        if ($tool === 'imagemagick') {
            return \preg_match('/imagemagick\s+(\d+)/i', $reportedVersion, $matches) === 1
                && (int) $matches[1] >= 7;
        }

        return true;
    }

    /**
     * Return diagnostics for a process stage without changing legacy contexts.
     *
     * @return array<string, string>
     */
    private function context(string $operation, string $stage): array
    {
        return $stage === 'convert'
            ? ['operation' => $operation]
            : ['operation' => $operation, 'stage' => $stage];
    }
}
