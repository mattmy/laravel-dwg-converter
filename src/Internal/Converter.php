<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Internal;

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgOutput;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\PngResolution;

/**
 * Implements the three fixed LibreDWG operations behind the public builders.
 */
final class Converter
{
    private const int PNG_SIGNATURE_BYTES = 8;

    private const int PNG_IHDR_BYTES = 24;

    private const int MAX_PNG_DIMENSION = 32_768;

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
     * Convert a DWG to a trimmed whole-model-space PNG preview.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function png(
        UploadedFile|string|DwgBinary $source,
        ?DxfVersion $version,
        PngResolution $resolution,
    ): DwgOutput {
        $operation = 'png';
        $dxfConfiguration = $this->configuration('dwg2dxf', $operation);
        $libreOfficeConfiguration = $this->configuration('libreoffice', $operation);
        $imageMagickConfiguration = $this->configuration('imagemagick', $operation);
        $workspace = $this->workspace($source, $operation, $dxfConfiguration);
        $dxf = $workspace->outputPath('drawing.dxf');
        $rawPng = $workspace->outputPath('drawing.png');
        $output = $workspace->outputPath('output.png');
        $profile = $workspace->outputPath('libreoffice-profile');

        try {
            if (! \mkdir($profile, 0700)) {
                throw new DwgOperationFailed('output_missing', ['operation' => $operation, 'stage' => 'libreoffice']);
            }

            $this->processRunner->assertAvailable($dxfConfiguration['executable'], $operation, 'dwg2dxf', 'dwg2dxf');
            $dxfCommand = [$dxfConfiguration['executable']];
            if ($version instanceof DxfVersion) {
                $dxfCommand[] = '--as';
                $dxfCommand[] = $version->value;
            }
            $dxfCommand[] = '-o';
            $dxfCommand[] = $dxf;
            $dxfCommand[] = $workspace->inputPath();
            $this->processRunner->run(
                $dxfCommand,
                $workspace,
                $dxfConfiguration['timeout'],
                $dxfConfiguration['max_output_bytes'],
                $operation,
                null,
                'dwg2dxf',
            );
            $this->assertBoundedFile($dxf, $operation, $dxfConfiguration['max_output_bytes']);
            $this->assertDxf($dxf, $operation);

            $this->processRunner->assertAvailable($libreOfficeConfiguration['executable'], $operation, 'libreoffice', 'libreoffice');
            $this->processRunner->run(
                [
                    $libreOfficeConfiguration['executable'],
                    '-env:UserInstallation=' . $this->fileUrl($profile),
                    '--headless',
                    '--nologo',
                    '--nodefault',
                    '--nofirststartwizard',
                    '--norestore',
                    '--convert-to',
                    $this->pngFilter($resolution),
                    '--outdir',
                    $workspace->directory(),
                    $dxf,
                ],
                $workspace,
                $libreOfficeConfiguration['timeout'],
                $libreOfficeConfiguration['max_output_bytes'],
                $operation,
                null,
                'libreoffice',
            );
            $this->assertPng($rawPng, $operation, $libreOfficeConfiguration['max_output_bytes']);

            $this->processRunner->assertAvailable($imageMagickConfiguration['executable'], $operation, 'imagemagick', 'imagemagick');
            $this->processRunner->run(
                [
                    $imageMagickConfiguration['executable'],
                    $rawPng,
                    '-fuzz',
                    '2%',
                    '-trim',
                    '+repage',
                    $output,
                ],
                $workspace,
                $imageMagickConfiguration['timeout'],
                $imageMagickConfiguration['max_output_bytes'],
                $operation,
                null,
                'imagemagick',
            );
            $this->assertPng($output, $operation, $imageMagickConfiguration['max_output_bytes']);

            return new DwgOutput(
                $workspace,
                $output,
                'png',
                'image/png',
                $imageMagickConfiguration['max_output_bytes'],
                $operation,
            );
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
    private function assertDxf(string $path, string $operation = 'dxf'): void
    {
        $size = \filesize($path);
        if (! \is_int($size)) {
            throw new DwgOperationFailed('dxf_invalid', ['operation' => $operation]);
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
            throw new DwgOperationFailed('dxf_invalid', ['operation' => $operation]);
        }
    }

    /**
     * Validate a bounded PNG signature, IHDR dimensions, and IEND trailer.
     *
     * @throws DwgOperationFailed
     */
    private function assertPng(string $path, string $operation, int $maxOutputBytes): void
    {
        $this->assertBoundedFile($path, $operation, $maxOutputBytes);
        $size = \filesize($path);
        $header = \file_get_contents($path, false, null, 0, self::PNG_IHDR_BYTES);
        if (
            ! \is_int($size)
            || ! \is_string($header)
            || $size < self::PNG_IHDR_BYTES + 12
            || \substr($header, 0, self::PNG_SIGNATURE_BYTES) !== "\x89PNG\r\n\x1a\n"
            || \substr($header, 8, 8) !== "\0\0\0\rIHDR"
            || ! $this->hasPngEnd($path, $size)
        ) {
            throw new DwgOperationFailed('png_invalid', ['operation' => $operation]);
        }

        $dimensions = \unpack('Nwidth/Nheight', \substr($header, 16, 8));
        $width = $dimensions['width'] ?? 0;
        $height = $dimensions['height'] ?? 0;
        if ($width < 1 || $height < 1 || $width > self::MAX_PNG_DIMENSION || $height > self::MAX_PNG_DIMENSION) {
            throw new DwgOperationFailed('png_invalid', ['operation' => $operation]);
        }
    }

    /**
     * Return the fixed LibreOffice PNG filter for one supported resolution.
     */
    private function pngFilter(PngResolution $resolution): string
    {
        return match ($resolution) {
            PngResolution::HIGH => 'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"5792"},"PixelWidth":{"type":"long","value":"4096"}}',
            PngResolution::MEDIUM => 'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"2896"},"PixelWidth":{"type":"long","value":"2048"}}',
            PngResolution::LOW => 'png:draw_png_Export:{"PixelHeight":{"type":"long","value":"1448"},"PixelWidth":{"type":"long","value":"1024"}}',
        };
    }

    /**
     * Convert an absolute filesystem path into the LibreOffice file URL form.
     */
    private function fileUrl(string $path): string
    {
        $segments = \explode('/', \ltrim(\str_replace('\\', '/', $path), '/'));
        if (\preg_match('/^[A-Za-z]:$/', $segments[0]) === 1) {
            $drive = \array_shift($segments);

            return 'file:///' . $drive . '/' . \implode('/', \array_map(\rawurlencode(...), $segments));
        }

        return 'file:///' . \implode('/', \array_map(\rawurlencode(...), $segments));
    }
}
