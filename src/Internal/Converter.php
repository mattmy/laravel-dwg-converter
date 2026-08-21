<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Internal;

use DOMDocument;
use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgOutput;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;

/**
 * Implements the three fixed LibreDWG operations behind the public builders.
 */
final class Converter
{
    /**
     * @param array{executables: array{dwgbmp: string, dwg2dxf: string, dwg2svg: string}, timeout: int|float, max_input_bytes: int, max_output_bytes: int, temporary_directory: string} $configuration
     */
    public function __construct(
        private readonly ProcessRunner $processRunner,
        private readonly array $configuration,
    ) {}

    /**
     * Extract a DWG preview image using dwgbmp.
     *
     * @throws DwgOperationFailed
     */
    public function thumbnail(UploadedFile|string|DwgBinary $source): DwgOutput
    {
        $operation = 'thumbnail';
        $workspace = $this->workspace($source, $operation);
        try {
            $executable = $this->configuration['executables']['dwgbmp'];
            $this->processRunner->assertAvailable($executable, $operation);
            $this->processRunner->run(
                [$executable, $workspace->inputPath()],
                $workspace,
                (float) $this->configuration['timeout'],
                $this->configuration['max_output_bytes'],
                $operation,
            );

            $thumbnail = $this->findThumbnail($workspace);
            [$extension, $mimeType] = $this->thumbnailType($thumbnail);

            return new DwgOutput($workspace, $thumbnail, $extension, $mimeType, $this->configuration['max_output_bytes'], $operation);
        } catch (\Throwable $exception) {
            $workspace->cleanup();

            throw $exception;
        }
    }

    /**
     * Convert a DWG to an ASCII DXF file.
     *
     * @throws DwgOperationFailed
     */
    public function dxf(UploadedFile|string|DwgBinary $source, ?DxfVersion $version): DwgOutput
    {
        $operation = 'dxf';
        $workspace = $this->workspace($source, $operation);
        $output = $workspace->outputPath('output.dxf');
        try {
            $executable = $this->configuration['executables']['dwg2dxf'];
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
                (float) $this->configuration['timeout'],
                $this->configuration['max_output_bytes'],
                $operation,
            );
            $this->assertBoundedFile($output, $operation);
            $this->assertDxf($output);

            return new DwgOutput($workspace, $output, 'dxf', 'application/dxf', $this->configuration['max_output_bytes'], $operation);
        } catch (\Throwable $exception) {
            $workspace->cleanup();

            throw $exception;
        }
    }

    /**
     * Convert the supported 2D portions of a DWG to SVG stdout.
     *
     * @throws DwgOperationFailed
     */
    public function svg(UploadedFile|string|DwgBinary $source): DwgOutput
    {
        $operation = 'svg';
        $workspace = $this->workspace($source, $operation);
        $output = $workspace->outputPath('output.svg');
        try {
            $executable = $this->configuration['executables']['dwg2svg'];
            $this->processRunner->assertAvailable($executable, $operation);
            $this->processRunner->run(
                [$executable, $workspace->inputPath()],
                $workspace,
                (float) $this->configuration['timeout'],
                $this->configuration['max_output_bytes'],
                $operation,
                $output,
            );
            $this->assertBoundedFile($output, $operation);
            $this->assertSvg($output);

            return new DwgOutput($workspace, $output, 'svg', 'image/svg+xml', $this->configuration['max_output_bytes'], $operation);
        } catch (\Throwable $exception) {
            $workspace->cleanup();

            throw $exception;
        }
    }

    /**
     * Normalize settings and prepare a source snapshot for one operation.
     *
     * @throws DwgOperationFailed
     */
    private function workspace(UploadedFile|string|DwgBinary $source, string $operation): Workspace
    {
        $this->assertConfiguration($operation);

        return Workspace::fromSource(
            $source,
            $this->configuration['temporary_directory'],
            $this->configuration['max_input_bytes'],
            $operation,
        );
    }

    /**
     * Reject invalid published configuration before writing temporary data.
     *
     * @throws DwgOperationFailed
     */
    private function assertConfiguration(string $operation): void
    {
        if ($this->configuration['timeout'] <= 0 || $this->configuration['max_input_bytes'] < 1 || $this->configuration['max_output_bytes'] < 1) {
            throw new DwgOperationFailed('invalid_configuration', ['operation' => $operation]);
        }
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
    private function thumbnailType(string $path): array
    {
        $this->assertBoundedFile($path, 'thumbnail');
        $header = \file_get_contents($path, false, null, 0, 8);
        if (!\is_string($header)) {
            throw new DwgOperationFailed('thumbnail_invalid', ['operation' => 'thumbnail']);
        }

        return match (true) {
            \str_starts_with($header, "BM") => ['bmp', 'image/bmp'],
            $header === "\x89PNG\r\n\x1a\n" => ['png', 'image/png'],
            \str_starts_with($header, "\xd7\xcd\xc6\x9a") || \str_starts_with($header, "\x01\x00\x09\x00") => ['wmf', 'image/wmf'],
            default => throw new DwgOperationFailed('thumbnail_invalid', ['operation' => 'thumbnail']),
        };
    }

    /**
     * Confirm that LibreDWG produced a bounded regular file.
     *
     * @throws DwgOperationFailed
     */
    private function assertBoundedFile(string $path, string $operation): void
    {
        if (!\is_file($path)) {
            throw new DwgOperationFailed('output_missing', ['operation' => $operation]);
        }

        $size = \filesize($path);
        if ($size === false || $size < 1) {
            throw new DwgOperationFailed('output_missing', ['operation' => $operation]);
        }

        if ($size > $this->configuration['max_output_bytes']) {
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
        $contents = \file_get_contents($path, false, null, 0, 65536);
        if (!\is_string($contents) || !\str_contains($contents, 'SECTION') || !\str_contains($contents, 'EOF')) {
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
        $head = \file_get_contents($path, false, null, 0, 65536);
        if (!\is_string($head) || \stripos($head, '<!doctype') !== false || \stripos($head, '<!entity') !== false) {
            throw new DwgOperationFailed('svg_invalid', ['operation' => 'svg']);
        }

        $document = new DOMDocument();
        if (!$document->load($path, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING) || $document->documentElement?->localName !== 'svg') {
            throw new DwgOperationFailed('svg_invalid', ['operation' => 'svg']);
        }
    }
}
