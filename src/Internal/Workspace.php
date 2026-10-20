<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Internal;

use ErrorException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use RuntimeException;
use Throwable;

/**
 * Owns one isolated temporary DWG snapshot and its generated artifacts.
 */
final class Workspace
{
    private const COPY_CHUNK_BYTES = 1_048_576;

    private bool $cleaned = false;

    /**
     * Create a workspace rooted at a trusted temporary directory.
     */
    private function __construct(private readonly string $directory) {}

    /**
     * Create an isolated workspace and snapshot a caller-owned source.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public static function fromSource(
        UploadedFile|string|DwgBinary $source,
        string $temporaryDirectory,
        ?int $maxInputBytes,
        string $operation,
    ): self {
        if (! self::isAbsolutePath($temporaryDirectory)
            || \str_contains($temporaryDirectory, "\0")
            || (\file_exists($temporaryDirectory) && ! \is_dir($temporaryDirectory))) {
            throw new LibreDwgUnavailable('invalid_configuration', ['operation' => $operation]);
        }

        try {
            if (! \is_dir($temporaryDirectory) && ! \mkdir($temporaryDirectory, 0700, true) && ! \is_dir($temporaryDirectory)) {
                throw new LibreDwgUnavailable('invalid_configuration', ['operation' => $operation]);
            }
        } catch (ErrorException $exception) {
            throw new LibreDwgUnavailable('invalid_configuration', ['operation' => $operation], $exception);
        }

        try {
            $directory = $temporaryDirectory . \DIRECTORY_SEPARATOR . \bin2hex(\random_bytes(16));
        } catch (Throwable $exception) {
            throw new LibreDwgUnavailable('invalid_configuration', ['operation' => $operation], $exception);
        }

        try {
            if (! \mkdir($directory, 0700)) {
                throw new DwgOperationFailed('input_snapshot_failed', ['operation' => $operation]);
            }
        } catch (ErrorException $exception) {
            throw new DwgOperationFailed('input_snapshot_failed', ['operation' => $operation], $exception);
        }

        $workspace = new self($directory);

        try {
            $workspace->snapshot($source, $maxInputBytes, $operation);
        } catch (Throwable $exception) {
            $workspace->cleanup($operation, 'snapshot');

            if ($exception instanceof ErrorException) {
                throw new DwgOperationFailed('input_snapshot_failed', ['operation' => $operation], $exception);
            }

            throw $exception;
        }

        return $workspace;
    }

    /**
     * Return the private workspace directory.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Return the fixed path used by all external tools as input.
     */
    public function inputPath(): string
    {
        return $this->directory . \DIRECTORY_SEPARATOR . 'input.dwg';
    }

    /**
     * Return a package-owned artifact path.
     */
    public function outputPath(string $name): string
    {
        return $this->directory . \DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Determine whether an existing path is owned by this workspace.
     */
    public function owns(string $path): bool
    {
        $directory = \realpath($this->directory);
        $realPath = \realpath($path);

        return \is_string($directory)
            && \is_string($realPath)
            && \str_starts_with($realPath, $directory . \DIRECTORY_SEPARATOR);
    }

    /**
     * Attempt private cleanup without interrupting the caller; warn on failure.
     */
    public function cleanup(string $operation = 'unknown', string $phase = 'conversion'): void
    {
        if ($this->cleaned) {
            return;
        }

        \set_error_handler(static function (int $severity): never {
            throw new ErrorException('Temporary cleanup failed.', 0, $severity);
        });

        try {
            if (\is_link($this->directory)) {
                if (! \unlink($this->directory)) {
                    throw new RuntimeException('Temporary cleanup failed.');
                }
            } elseif (\is_dir($this->directory)) {
                $this->removeDirectory($this->directory);
            }

            $this->cleaned = true;
        } catch (Throwable $exception) {
            try {
                Log::warning('DWG converter temporary cleanup failed.', [
                    'operation' => $operation,
                    'phase' => $phase,
                    'exception_class' => \get_debug_type($exception),
                ]);
            } catch (Throwable) {
                // Logging failure must not turn optional cleanup into a caller failure.
            }
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * Determine whether a path is rooted on Windows or Unix.
     */
    public static function isAbsolutePath(string $path): bool
    {
        return \DIRECTORY_SEPARATOR === '\\'
            ? \preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            : \str_starts_with($path, '/');
    }

    /**
     * Remove a package-owned directory without following symbolic links.
     *
     * @throws RuntimeException
     */
    private function removeDirectory(string $directory): void
    {
        $items = \scandir($directory);
        if ($items === false) {
            throw new RuntimeException('Temporary cleanup failed.');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . \DIRECTORY_SEPARATOR . $item;
            if (\is_dir($path) && ! \is_link($path)) {
                $this->removeDirectory($path);
            } elseif (! \unlink($path)) {
                throw new RuntimeException('Temporary cleanup failed.');
            }
        }

        if (! \rmdir($directory)) {
            throw new RuntimeException('Temporary cleanup failed.');
        }
    }

    /**
     * Snapshot the selected input variant without taking ownership of it.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     */
    private function snapshot(UploadedFile|string|DwgBinary $source, ?int $maxInputBytes, string $operation): void
    {
        if ($source instanceof DwgBinary) {
            $this->writeBinary($source->contents(), $maxInputBytes, $operation);
        } else {
            $path = $source instanceof UploadedFile
                ? $this->uploadedPath($source, $operation)
                : $this->localPath($source, $operation);
            $this->copyPath($path, $maxInputBytes, $operation);
        }

        $header = \file_get_contents($this->inputPath(), false, null, 0, 6);
        if (! \is_string($header) || \preg_match('/^AC10[0-9]{2}$/', $header) !== 1) {
            throw new InvalidDwg('invalid_dwg_header', ['operation' => $operation]);
        }
    }

    /**
     * Validate and return an uploaded-file source path.
     *
     * @throws InvalidDwg
     */
    private function uploadedPath(UploadedFile $source, string $operation): string
    {
        if (! $source->isValid()) {
            throw new InvalidDwg('invalid_upload', ['operation' => $operation]);
        }

        $path = $source->getRealPath();
        if (! \is_string($path)) {
            throw new InvalidDwg('invalid_upload', ['operation' => $operation]);
        }

        return $this->localPath($path, $operation);
    }

    /**
     * Validate a local absolute regular readable file path.
     *
     * @throws InvalidDwg
     */
    private function localPath(string $path, string $operation): string
    {
        if (! self::isAbsolutePath($path)) {
            throw new InvalidDwg('input_not_absolute', ['operation' => $operation]);
        }

        if (\str_contains($path, '://') || \str_contains($path, "\0")) {
            throw new InvalidDwg('input_not_readable', ['operation' => $operation]);
        }

        $realPath = \realpath($path);
        if ($realPath === false) {
            throw new InvalidDwg('input_not_found', ['operation' => $operation]);
        }

        if (! \is_file($realPath) || ! \is_readable($realPath)) {
            throw new InvalidDwg('input_not_readable', ['operation' => $operation]);
        }

        return $realPath;
    }

    /**
     * Copy a local file while enforcing its enabled byte limit.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     */
    private function copyPath(string $path, ?int $maxInputBytes, string $operation): void
    {
        $input = \fopen($path, 'rb');
        if ($input === false) {
            throw new DwgOperationFailed('input_snapshot_failed', ['operation' => $operation]);
        }

        $output = false;

        try {
            $output = \fopen($this->inputPath(), 'xb');
            if ($output === false) {
                throw new DwgOperationFailed('input_snapshot_failed', ['operation' => $operation]);
            }

            $this->copyStream($input, $output, $maxInputBytes, $operation);
        } finally {
            \fclose($input);
            if (\is_resource($output)) {
                \fclose($output);
            }
        }
    }

    /**
     * Write explicitly supplied binary data through the same size boundary.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     */
    private function writeBinary(string $contents, ?int $maxInputBytes, string $operation): void
    {
        if ($maxInputBytes !== null && \strlen($contents) > $maxInputBytes) {
            throw new InvalidDwg('input_too_large', ['operation' => $operation]);
        }

        if (\file_put_contents($this->inputPath(), $contents, \LOCK_EX) !== \strlen($contents)) {
            throw new DwgOperationFailed('input_snapshot_failed', ['operation' => $operation]);
        }
    }

    /**
     * Copy a stream to the snapshot while enforcing its enabled maximum size.
     *
     * @param  resource  $input
     * @param  resource  $output
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     */
    private function copyStream($input, $output, ?int $maxInputBytes, string $operation): void
    {
        $bytes = 0;
        while (! \feof($input)) {
            $chunk = \fread($input, self::COPY_CHUNK_BYTES);
            if ($chunk === false) {
                throw new DwgOperationFailed('input_snapshot_failed', ['operation' => $operation]);
            }

            $bytes += \strlen($chunk);
            if ($maxInputBytes !== null && $bytes > $maxInputBytes) {
                throw new InvalidDwg('input_too_large', ['operation' => $operation]);
            }

            if ($chunk !== '' && \fwrite($output, $chunk) !== \strlen($chunk)) {
                throw new DwgOperationFailed('input_snapshot_failed', ['operation' => $operation]);
            }
        }
    }
}
