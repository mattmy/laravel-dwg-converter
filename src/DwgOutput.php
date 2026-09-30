<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Internal\Workspace;
use Throwable;

/**
 * Represents a one-time readable or storable package-owned output artifact.
 */
final class DwgOutput
{
    private const READY = 'ready';

    private const CONSUMING = 'consuming';

    private const CONSUMED = 'consumed';

    private string $state = self::READY;

    /**
     * Create an output that assumes ownership of its workspace.
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $path,
        private readonly string $extension,
        private readonly string $mimeType,
        private readonly ?int $maxOutputBytes,
        private readonly string $operation,
        private readonly ?string $sourceStem = null,
    ) {}

    /**
     * Clean up a result that was abandoned before terminal consumption.
     */
    public function __destruct()
    {
        $this->workspace->cleanup();
    }

    /**
     * Return the trusted filename extension without consuming the output.
     */
    public function extension(): string
    {
        return $this->extension;
    }

    /**
     * Return the trusted MIME type without consuming the output.
     */
    public function mimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Read all output bytes and then remove the private artifact.
     *
     * @throws DwgOperationFailed
     * @throws LogicException
     */
    public function output(): string
    {
        $this->beginConsumption();

        try {
            $this->assertOutputFile();
            $contents = \file_get_contents($this->path);
            if (! \is_string($contents)) {
                throw new DwgOperationFailed('output_missing', ['operation' => $this->operation]);
            }

            return $contents;
        } finally {
            $this->finishConsumption();
        }
    }

    /**
     * Stream the output to a Laravel disk and then remove the private artifact.
     *
     * @throws DwgOperationFailed
     * @throws LogicException
     */
    public function storeAs(string $path, ?string $name = null, ?string $disk = null): string
    {
        $this->beginConsumption();

        try {
            [$directory, $name] = $this->validateStorageDestination($path, $name);
            $this->assertOutputFile();
            $stored = Storage::disk($disk)->putFileAs($directory, new File($this->path), $name);
            if ($stored === false) {
                throw new DwgOperationFailed('storage_failed', ['operation' => $this->operation]);
            }

            return $stored;
        } catch (DwgOperationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DwgOperationFailed('storage_failed', ['operation' => $this->operation], $exception);
        } finally {
            $this->finishConsumption();
        }
    }

    /**
     * Transition from ready to the terminal consumption phase.
     *
     * @throws LogicException
     */
    private function beginConsumption(): void
    {
        if ($this->state !== self::READY) {
            throw new LogicException('This DWG output has already been consumed.');
        }

        $this->state = self::CONSUMING;
    }

    /**
     * Check that the output remains a bounded regular file in its workspace.
     *
     * @throws DwgOperationFailed
     */
    private function assertOutputFile(): void
    {
        if (! $this->workspace->owns($this->path) || ! \is_file($this->path) || ! \is_readable($this->path)) {
            throw new DwgOperationFailed('output_missing', ['operation' => $this->operation]);
        }

        $size = \filesize($this->path);
        if ($size === false || $size < 1) {
            throw new DwgOperationFailed('output_missing', ['operation' => $this->operation]);
        }

        if ($this->maxOutputBytes !== null && $size > $this->maxOutputBytes) {
            throw new DwgOperationFailed('output_too_large', ['operation' => $this->operation]);
        }
    }

    /**
     * Validate a disk-relative directory and resolve its trusted output filename.
     *
     * @return array{string, string}
     *
     * @throws DwgOperationFailed
     */
    private function validateStorageDestination(string $path, ?string $name): array
    {
        if (\str_contains($path, "\0")
            || \str_contains($path, '\\')
            || $this->hasControlCharacter($path)
            || \str_starts_with($path, '/')
            || \preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new DwgOperationFailed('storage_failed', ['operation' => $this->operation]);
        }

        $directory = \rtrim($path, '/');
        if ($directory !== '') {
            foreach (\explode('/', $directory) as $segment) {
                if (\in_array($segment, ['', '.', '..'], true)) {
                    throw new DwgOperationFailed('storage_failed', ['operation' => $this->operation]);
                }
            }
        }

        if ($name !== null) {
            $name = $this->validatedFilename($name);
        } else {
            try {
                $name = $this->sourceStem === null ? null : $this->validatedFilename($this->sourceStem);
            } catch (DwgOperationFailed) {
                $name = null;
            }

            if ($name === null) {
                try {
                    $name = 'converted-' . \bin2hex(\random_bytes(8));
                } catch (Throwable $exception) {
                    throw new DwgOperationFailed('storage_failed', ['operation' => $this->operation], $exception);
                }
            }
        }

        return [$directory, $this->filenameWithExtension($name)];
    }

    /**
     * Return a safe non-empty user-provided filename.
     *
     * @throws DwgOperationFailed
     */
    private function validatedFilename(string $filename): string
    {
        if (\in_array($filename, ['', '.', '..'], true)
            || \str_contains($filename, '/')
            || \str_contains($filename, '\\')
            || $this->hasControlCharacter($filename)) {
            throw new DwgOperationFailed('storage_failed', ['operation' => $this->operation]);
        }

        return $filename;
    }

    /**
     * Reject C0, C1, and Unicode control characters, including invalid UTF-8.
     */
    private function hasControlCharacter(string $value): bool
    {
        return \preg_match('/[\p{Cc}]/u', $value) !== 0;
    }

    /**
     * Append the trusted output extension without discarding user filename text.
     *
     * @return non-empty-string
     */
    private function filenameWithExtension(string $filename): string
    {
        $suffix = '.' . $this->extension;
        if (\str_ends_with(\strtolower($filename), $suffix)) {
            return \substr($filename, 0, -\strlen($suffix)) . $suffix;
        }

        return $filename . $suffix;
    }

    /**
     * Remove temporary resources after every terminal path.
     */
    private function finishConsumption(): void
    {
        try {
            $this->workspace->cleanup();
        } finally {
            $this->state = self::CONSUMED;
        }
    }
}
