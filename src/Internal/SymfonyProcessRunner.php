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
    private const int STDERR_LIMIT = 4096;

    /**
     * Verify that an executable starts and identifies itself as LibreDWG.
     *
     * @throws LibreDwgUnavailable
     */
    #[Override]
    public function assertAvailable(string $executable, string $operation): void
    {
        if ($executable === '') {
            throw new LibreDwgUnavailable('executable_not_found', ['operation' => $operation]);
        }

        try {
            $process = new Process([$executable, '--version']);
            $process->setTimeout(10.0);
            $process->run();
        } catch (\Throwable $exception) {
            throw new LibreDwgUnavailable('executable_not_found', ['operation' => $operation], $exception);
        }

        $reportedVersion = \trim($process->getOutput() . $process->getErrorOutput());
        $expectedTool = match ($operation) {
            'thumbnail' => 'dwgbmp',
            'dxf' => 'dwg2dxf',
            'svg' => 'dwg2svg',
            default => '',
        };
        if (! $process->isSuccessful() || ! \str_contains(\strtolower($reportedVersion), $expectedTool)) {
            throw new LibreDwgUnavailable('unsupported_tool_capability', [
                'operation' => $operation,
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
        int $maxOutputBytes,
        string $operation,
        ?string $stdoutPath = null,
    ): void {
        $stderr = '';
        $tooLarge = false;
        $stream = null;
        if ($stdoutPath !== null) {
            $stream = \fopen($stdoutPath, 'xb');
            if ($stream === false) {
                throw new DwgOperationFailed('output_missing', ['operation' => $operation]);
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
                if ($position === false || $position > $maxOutputBytes) {
                    $tooLarge = true;
                    $process->stop();
                }
            });
        } catch (ProcessTimedOutException $exception) {
            throw new DwgOperationFailed('process_timed_out', ['operation' => $operation], $exception);
        } catch (\Throwable $exception) {
            throw new DwgOperationFailed('process_failed', ['operation' => $operation], $exception);
        } finally {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }

        if ($tooLarge) {
            throw new DwgOperationFailed('output_too_large', ['operation' => $operation]);
        }

        if (! $process->isSuccessful()) {
            $context = [
                'operation' => $operation,
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

        foreach (['read error', 'failed to decode', 'dwg too small', 'invalid dwg'] as $indicator) {
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
}
