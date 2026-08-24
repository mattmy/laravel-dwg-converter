<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Internal;

use DOMDocument;
use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgOutput;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;

/**
 * Implements the three fixed LibreDWG operations behind the public builders.
 */
final class Converter
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public function __construct(
        private readonly ProcessRunner $processRunner,
        private readonly array $configuration,
    ) {}

    /**
     * Extract a DWG preview image using dwgbmp.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function thumbnail(UploadedFile|string|DwgBinary $source): DwgOutput
    {
        $operation = 'thumbnail';
        $configuration = $this->configuration('dwgbmp', $operation);
        $workspace = $this->workspace($source, $operation, $configuration);

        try {
            $executable = $configuration['executable'];
            $this->processRunner->assertAvailable($executable, $operation);
            $this->processRunner->run(
                [$executable, $workspace->inputPath()],
                $workspace,
                $configuration['timeout'],
                $configuration['max_output_bytes'],
                $operation,
            );

            $thumbnail = $this->findThumbnail($workspace);
            [$extension, $mimeType] = $this->thumbnailType($thumbnail, $configuration['max_output_bytes']);

            return new DwgOutput($workspace, $thumbnail, $extension, $mimeType, $configuration['max_output_bytes'], $operation);
        } catch (\Throwable $exception) {
            $workspace->cleanup();

            throw $exception;
        }
    }

    /**
     * Convert a DWG to an ASCII DXF file.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function dxf(UploadedFile|string|DwgBinary $source, ?DxfVersion $version): DwgOutput
    {
        $operation = 'dxf';
        $configuration = $this->configuration('dwg2dxf', $operation);
        $workspace = $this->workspace($source, $operation, $configuration);
        $output = $workspace->outputPath('output.dxf');

        try {
            $executable = $configuration['executable'];
            $this->processRunner->assertAvailable($executable, $operation);
            $command = [$executable];
            if ($version instanceof DxfVersion) {
                $command[] = '--as';
                $command[] = $version->value;
            }
            $command[] = '-o';
            $command[] = $output;
            $command[] = $workspace->inputPath();
            $this->processRunner->run(
                $command,
                $workspace,
                $configuration['timeout'],
                $configuration['max_output_bytes'],
                $operation,
            );
            $this->assertBoundedFile($output, $operation, $configuration['max_output_bytes']);
            $this->assertDxf($output);

            return new DwgOutput($workspace, $output, 'dxf', 'application/dxf', $configuration['max_output_bytes'], $operation);
        } catch (\Throwable $exception) {
            $workspace->cleanup();

            throw $exception;
        }
    }

    /**
     * Convert the supported 2D portions of a DWG to SVG stdout.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function svg(UploadedFile|string|DwgBinary $source): DwgOutput
    {
        $operation = 'svg';
        $configuration = $this->configuration('dwg2svg', $operation);
        $workspace = $this->workspace($source, $operation, $configuration);
        $output = $workspace->outputPath('output.svg');

        try {
            $executable = $configuration['executable'];
            $this->processRunner->assertAvailable($executable, $operation);
            $this->processRunner->run(
                [$executable, $workspace->inputPath()],
                $workspace,
                $configuration['timeout'],
                $configuration['max_output_bytes'],
                $operation,
                $output,
            );
            $this->assertBoundedFile($output, $operation, $configuration['max_output_bytes']);
            $this->assertSvg($output);

            return new DwgOutput($workspace, $output, 'svg', 'image/svg+xml', $configuration['max_output_bytes'], $operation);
        } catch (\Throwable $exception) {
            $workspace->cleanup();

            throw $exception;
        }
    }

    /**
     * Normalize settings and prepare a source snapshot for one operation.
     *
     * @param  array{executable: string, timeout: float, max_input_bytes: int, max_output_bytes: int, temporary_directory: string}  $configuration
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     */
    private function workspace(
        UploadedFile|string|DwgBinary $source,
        string $operation,
        array $configuration,
    ): Workspace {
        return Workspace::fromSource(
            $source,
            $configuration['temporary_directory'],
            $configuration['max_input_bytes'],
            $operation,
        );
    }

    /**
     * Validate and normalize the settings needed by one operation.
     *
     * @return array{executable: string, timeout: float, max_input_bytes: int, max_output_bytes: int, temporary_directory: string}
     *
     * @throws LibreDwgUnavailable
     */
    private function configuration(string $executableKey, string $operation): array
    {
        $executables = $this->configuration['executables'] ?? null;
        $executable = \is_array($executables) ? ($executables[$executableKey] ?? null) : null;
        $timeout = $this->configuration['timeout'] ?? null;
        $maxInputBytes = $this->configuration['max_input_bytes'] ?? null;
        $maxOutputBytes = $this->configuration['max_output_bytes'] ?? null;
        $temporaryDirectory = $this->configuration['temporary_directory'] ?? null;
        $validTimeout = (\is_int($timeout) || \is_float($timeout))
            && \is_finite((float) $timeout)
            && $timeout > 0;
        $validDirectory = \is_string($temporaryDirectory)
            && \preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $temporaryDirectory) === 1;

        if (
            ! \is_string($executable)
            || ! $validTimeout
            || ! \is_int($maxInputBytes)
            || $maxInputBytes < 1
            || ! \is_int($maxOutputBytes)
            || $maxOutputBytes < 1
            || ! $validDirectory
        ) {
            throw new LibreDwgUnavailable('invalid_configuration', ['operation' => $operation]);
        }

        return [
            'executable' => $executable,
            'timeout' => (float) $timeout,
            'max_input_bytes' => $maxInputBytes,
            'max_output_bytes' => $maxOutputBytes,
            'temporary_directory' => $temporaryDirectory,
        ];
    }

    /**
     * Locate exactly one generated preview file without trusting its suffix.
     *
     * @throws DwgOperationFailed
     */
    private function findThumbnail(Workspace $workspace): string
    {
        $files = [];
        $entries = \scandir($workspace->directory());
        if ($entries === false) {
            throw new DwgOperationFailed('thumbnail_not_found', ['operation' => 'thumbnail']);
        }

        foreach ($entries as $entry) {
            $path = $workspace->outputPath($entry);
            if ($entry !== 'input.dwg' && \is_file($path)) {
                $files[] = $path;
            }
        }

        if (\count($files) !== 1) {
            throw new DwgOperationFailed('thumbnail_not_found', ['operation' => 'thumbnail']);
        }

        return $files[0];
    }

    /**
     * Identify a thumbnail through its file signature.
     *
     * @return array{string, string}
     *
     * @throws DwgOperationFailed
     */
    private function thumbnailType(string $path, int $maxOutputBytes): array
    {
        $this->assertBoundedFile($path, 'thumbnail', $maxOutputBytes);
        $size = \filesize($path);
        $header = \file_get_contents($path, false, null, 0, 8);
        if (! \is_int($size) || ! \is_string($header)) {
            throw new DwgOperationFailed('thumbnail_invalid', ['operation' => 'thumbnail']);
        }

        return match (true) {
            \str_starts_with($header, 'BM') && $size >= 54 => ['bmp', 'image/bmp'],
            $header === "\x89PNG\r\n\x1a\n" && $size >= 28 && $this->hasPngEnd($path, $size) => ['png', 'image/png'],
            \str_starts_with($header, "\xd7\xcd\xc6\x9a") && $size >= 40 => ['wmf', 'image/wmf'],
            \str_starts_with($header, "\x01\x00\x09\x00") && $size >= 18 => ['wmf', 'image/wmf'],
            default => throw new DwgOperationFailed('thumbnail_invalid', ['operation' => 'thumbnail']),
        };
    }

    /**
     * Confirm that a PNG ends with its mandatory IEND chunk.
     */
    private function hasPngEnd(string $path, int $size): bool
    {
        return \file_get_contents($path, false, null, $size - 12, 12)
            === "\0\0\0\0IEND\xaeB`\x82";
    }

    /**
     * Confirm that LibreDWG produced a bounded regular file.
     *
     * @throws DwgOperationFailed
     */
    private function assertBoundedFile(string $path, string $operation, int $maxOutputBytes): void
    {
        if (! \is_file($path)) {
            throw new DwgOperationFailed('output_missing', ['operation' => $operation]);
        }

        $size = \filesize($path);
        if ($size === false || $size < 1) {
            throw new DwgOperationFailed('output_missing', ['operation' => $operation]);
        }

        if ($size > $maxOutputBytes) {
            throw new DwgOperationFailed('output_too_large', ['operation' => $operation]);
        }
    }

    /**
     * Perform the minimum structural check for an ASCII DXF artifact.
     *
     * @throws DwgOperationFailed
     */
    private function assertDxf(string $path): void
    {
        $size = \filesize($path);
        if (! \is_int($size)) {
            throw new DwgOperationFailed('dxf_invalid', ['operation' => 'dxf']);
        }

        $head = \file_get_contents($path, false, null, 0, \min($size, 4096));
        $tailOffset = \max(0, $size - 4096);
        $tail = \file_get_contents($path, false, null, $tailOffset);
        $isDxf = \is_string($head)
            && \is_string($tail)
            && ! \str_contains($head, "\0")
            && \preg_match('/(?:^|\R)SECTION(?:\R|$)/', $head) === 1
            && \preg_match('/(?:^|\R)\s*0\REOF\s*$/', $tail) === 1;

        if (! $isDxf) {
            throw new DwgOperationFailed('dxf_invalid', ['operation' => 'dxf']);
        }
    }

    /**
     * Parse a local SVG document while rejecting document type declarations.
     *
     * @throws DwgOperationFailed
     */
    private function assertSvg(string $path): void
    {
        if ($this->hasUnsafeXmlDeclaration($path)) {
            throw new DwgOperationFailed('svg_invalid', ['operation' => 'svg']);
        }

        $document = new DOMDocument();
        if (! $document->load($path, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING) || $document->documentElement?->localName !== 'svg') {
            throw new DwgOperationFailed('svg_invalid', ['operation' => 'svg']);
        }
    }

    /**
     * Scan the complete SVG stream for declarations that enable entities.
     *
     * @throws DwgOperationFailed
     */
    private function hasUnsafeXmlDeclaration(string $path): bool
    {
        $stream = \fopen($path, 'rb');
        if ($stream === false) {
            throw new DwgOperationFailed('svg_invalid', ['operation' => 'svg']);
        }

        $tail = '';

        try {
            while (! \feof($stream)) {
                $chunk = \fread($stream, 65536);
                if ($chunk === false) {
                    throw new DwgOperationFailed('svg_invalid', ['operation' => 'svg']);
                }

                $value = \strtolower($tail . $chunk);
                if (\str_contains($value, '<!doctype') || \str_contains($value, '<!entity')) {
                    return true;
                }

                $tail = \substr($value, -16);
            }

            return false;
        } finally {
            \fclose($stream);
        }
    }
}
