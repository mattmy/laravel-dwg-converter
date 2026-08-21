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
    private const string READY = 'ready';
    private const string CONSUMED = 'consumed';

    private string $state = self::READY;

    /**
     * Create an output that assumes ownership of its workspace.
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $path,
        private readonly string $extension,
        private readonly string $mimeType,
        private readonly int $maxOutputBytes,
        private readonly string $operation,
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
     */
    public function output(): string
    {
        $this->beginConsumption();
        try {
            $this->assertOutputFile();
            $contents = \file_get_contents($this->path);
            if (!\is_string($contents)) {
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
     */
    public function storeAs(string $path, string $name, ?string $disk = null): string
    {
        $this->validateStorageDestination($path, $name);
        $this->beginConsumption();
        try {
            $this->assertOutputFile();
            $stored = Storage::disk($disk)->putFileAs($path, new File($this->path), $name);
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
     */
    private function beginConsumption(): void
    {
        if ($this->state !== self::READY) {
            throw new LogicException('This DWG output has already been consumed.');
        }

        $this->state = self::CONSUMED;
    }

    /**
     * Check that the output remains a bounded regular file in its workspace.
     *
     * @throws DwgOperationFailed
     */
    private function assertOutputFile(): void
    {
        if (!\is_file($this->path) || !\is_readable($this->path)) {
            throw new DwgOperationFailed('output_missing', ['operation' => $this->operation]);
        }

        $size = \filesize($this->path);
        if ($size === false || $size < 1) {
            throw new DwgOperationFailed('output_missing', ['operation' => $this->operation]);
        }

        if ($size > $this->maxOutputBytes) {
            throw new DwgOperationFailed('output_too_large', ['operation' => $this->operation]);
        }
    }

    /**
     * Reject unsafe disk paths and an extension that disagrees with the artifact.
     *
     * @throws DwgOperationFailed
     */
    private function validateStorageDestination(string $path, string $name): void
    {
        $hasUnsafePath = \str_contains($path, "\0") || \str_starts_with($path, '/') || \str_starts_with($path, '\\')
            || \str_contains($path, '\\') || \in_array('..', \explode('/', $path), true);
        if ($hasUnsafePath || \str_contains($name, "\0") || \basename($name) !== $name) {
            throw new DwgOperationFailed('storage_failed', ['operation' => $this->operation]);
        }

        if (\strtolower(\pathinfo($name, PATHINFO_EXTENSION)) !== $this->extension) {
            throw new DwgOperationFailed('storage_failed', ['operation' => $this->operation]);
        }
    }

    /**
     * Remove temporary resources after every terminal path.
     */
    private function finishConsumption(): void
    {
        $this->workspace->cleanup();
    }
}
