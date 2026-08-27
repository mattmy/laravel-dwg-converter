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
use Mattmy\DwgConverter\ImageFormat;
use Mattmy\DwgConverter\ImageResolution;

/**
 * Implements the fixed LibreDWG operations behind the public builders.
 */
final class Converter
{
    private const int PNG_SIGNATURE_BYTES = 8;

    private const int PNG_IHDR_BYTES = 24;

    private const int MAX_IMAGE_DIMENSION = 32_768;

    /** @var list<string> */
    private const array JSON_REQUIRED_MARKERS = ['"FILEHEADER"', '"HEADER"', '"OBJECTS"'];

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
            [$extension, $mimeType] = $this->thumbnailType($thumbnail, $workspace, $configuration['max_output_bytes']);

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
            $this->assertBoundedFile($output, $workspace, $operation, $configuration['max_output_bytes']);
            $this->assertDxf($output);

            return new DwgOutput($workspace, $output, 'dxf', 'application/dxf', $configuration['max_output_bytes'], $operation);
        } catch (\Throwable $exception) {
            $workspace->cleanup();

            throw $exception;
        }
    }

    /**
     * Convert a DWG to LibreDWG's native structural JSON representation.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function json(UploadedFile|string|DwgBinary $source): DwgOutput
    {
        $operation = 'json';
        $configuration = $this->jsonConfiguration($operation);
        $workspace = $this->workspace($source, $operation, $configuration);
        $output = $workspace->outputPath('drawing.json');

        try {
            $executable = $configuration['executable'];
            $this->processRunner->assertAvailable($executable, $operation, 'dwgread', 'dwgread');
            $this->processRunner->run(
                [$executable, '-O', 'JSON', '-o', $output, $workspace->inputPath()],
                $workspace,
                $configuration['timeout'],
                $configuration['effective_output_limit'],
                $operation,
                null,
                'dwgread',
            );
            $this->assertJson($output, $workspace, $configuration['effective_output_limit']);

            return new DwgOutput(
                $workspace,
                $output,
                'json',
                'application/json',
                $configuration['effective_output_limit'],
                $operation,
            );
        } catch (\Throwable $exception) {
            $workspace->cleanup();

            throw $exception;
        }
    }

    /**
     * Convert a DWG to a trimmed whole-model-space raster image.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function image(
        UploadedFile|string|DwgBinary $source,
        ImageFormat $format,
        ?DxfVersion $version,
        ImageResolution $resolution,
    ): DwgOutput {
        $operation = 'image';
        $dxfConfiguration = $this->configuration('dwg2dxf', $operation);
        $libreOfficeConfiguration = $this->configuration('libreoffice', $operation);
        $imageMagickConfiguration = $this->configuration('imagemagick', $operation);
        $workspace = $this->workspace($source, $operation, $dxfConfiguration);
        $dxf = $workspace->outputPath('drawing.dxf');
        $intermediateImage = $workspace->outputPath('drawing.' . $format->value);
        $output = $workspace->outputPath('output.' . $format->value);
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
            $this->assertBoundedFile($dxf, $workspace, $operation, $dxfConfiguration['max_output_bytes']);
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
                    $this->imageFilter($format, $resolution),
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
            $this->assertImage($intermediateImage, $format, $workspace, $operation, $libreOfficeConfiguration['max_output_bytes']);

            $this->processRunner->assertAvailable($imageMagickConfiguration['executable'], $operation, 'imagemagick', 'imagemagick');
            $command = [
                $imageMagickConfiguration['executable'],
                $intermediateImage,
                '-fuzz',
                '2%',
                '-trim',
                '+repage',
            ];
            if ($format === ImageFormat::JPEG) {
                \array_push($command, '-background', 'white', '-alpha', 'remove', '-alpha', 'off');
            }
            $command[] = $output;
            $this->processRunner->run(
                $command,
                $workspace,
                $imageMagickConfiguration['timeout'],
                $imageMagickConfiguration['max_output_bytes'],
                $operation,
                null,
                'imagemagick',
            );
            $this->assertImage($output, $format, $workspace, $operation, $imageMagickConfiguration['max_output_bytes']);

            return new DwgOutput(
                $workspace,
                $output,
                $format->value,
                $format->mimeType(),
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
     * @param  array{executable: string, timeout: float, max_input_bytes: ?int, max_output_bytes: ?int, temporary_directory: string}  $configuration
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
     * @return array{executable: string, timeout: float, max_input_bytes: ?int, max_output_bytes: ?int, temporary_directory: string}
     *
     * @throws LibreDwgUnavailable
     */
    private function configuration(string $executableKey, string $operation): array
    {
        $executables = $this->configuration['executables'] ?? null;
        $executable = \is_array($executables) ? ($executables[$executableKey] ?? null) : null;
        $timeout = $this->configuration['timeout'] ?? null;
        $maxInputBytes = $this->normalizeByteLimit($this->configuration['max_input_bytes'] ?? null, $operation);
        $maxOutputBytes = $this->normalizeByteLimit($this->configuration['max_output_bytes'] ?? null, $operation);
        $temporaryDirectory = $this->configuration['temporary_directory'] ?? null;
        $validTimeout = (\is_int($timeout) || \is_float($timeout))
            && \is_finite((float) $timeout)
            && $timeout > 0;
        $validDirectory = \is_string($temporaryDirectory)
            && \preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $temporaryDirectory) === 1;

        if (
            ! \is_string($executable)
            || ! $validTimeout
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
     * Normalize the JSON-specific limit and derive its effective output limit.
     *
     * @return array{executable: string, timeout: float, max_input_bytes: ?int, max_output_bytes: ?int, max_json_output_bytes: ?int, effective_output_limit: ?int, temporary_directory: string}
     *
     * @throws LibreDwgUnavailable
     */
    private function jsonConfiguration(string $operation): array
    {
        $configuration = $this->configuration('dwgread', $operation);
        $maxJsonOutputBytes = $this->normalizeByteLimit($this->configuration['max_json_output_bytes'] ?? null, $operation);

        return [
            ...$configuration,
            'max_json_output_bytes' => $maxJsonOutputBytes,
            'effective_output_limit' => $this->effectiveOutputLimit(
                $configuration['max_output_bytes'],
                $maxJsonOutputBytes,
            ),
        ];
    }

    /**
     * Normalize one nullable byte limit from the published configuration boundary.
     *
     * @throws LibreDwgUnavailable
     */
    private function normalizeByteLimit(mixed $value, string $operation): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! \is_int($value)) {
            throw new LibreDwgUnavailable('invalid_configuration', ['operation' => $operation]);
        }

        return $value > 0 ? $value : null;
    }

    /**
     * Return the stricter active output limit, if either limit is enabled.
     */
    private function effectiveOutputLimit(?int $maxOutputBytes, ?int $maxJsonOutputBytes): ?int
    {
        if ($maxOutputBytes === null) {
            return $maxJsonOutputBytes;
        }

        if ($maxJsonOutputBytes === null) {
            return $maxOutputBytes;
        }

        return \min($maxOutputBytes, $maxJsonOutputBytes);
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
    private function thumbnailType(string $path, Workspace $workspace, ?int $maxOutputBytes): array
    {
        $this->assertBoundedFile($path, $workspace, 'thumbnail', $maxOutputBytes);
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
    private function assertBoundedFile(
        string $path,
        Workspace $workspace,
        string $operation,
        ?int $maxOutputBytes,
    ): void {
        if (\is_link($path) || ! \is_file($path) || ! $workspace->owns($path)) {
            throw new DwgOperationFailed('output_missing', ['operation' => $operation]);
        }

        $size = \filesize($path);
        if ($size === false || $size < 1) {
            throw new DwgOperationFailed('output_missing', ['operation' => $operation]);
        }

        if ($maxOutputBytes !== null && $size > $maxOutputBytes) {
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
     * Validate a bounded LibreDWG JSON artifact without decoding its full structure.
     *
     * @throws DwgOperationFailed
     */
    private function assertJson(string $path, Workspace $workspace, ?int $maxOutputBytes): void
    {
        $this->assertBoundedFile($path, $workspace, 'json', $maxOutputBytes);
        $contents = \file_get_contents($path);
        $hasRequiredMarkers = \is_string($contents);
        if ($hasRequiredMarkers) {
            foreach (self::JSON_REQUIRED_MARKERS as $marker) {
                if (! \str_contains($contents, $marker)) {
                    $hasRequiredMarkers = false;

                    break;
                }
            }
        }
        $isValid = \is_string($contents) && \json_validate($contents) && $hasRequiredMarkers;
        unset($contents);

        if (! $isValid) {
            throw new DwgOperationFailed('json_invalid', ['operation' => 'json']);
        }
    }

    /**
     * Validate a bounded image signature, dimensions, and required container markers.
     *
     * @throws DwgOperationFailed
     */
    private function assertImage(
        string $path,
        ImageFormat $format,
        Workspace $workspace,
        string $operation,
        ?int $maxOutputBytes,
    ): void {
        $this->assertBoundedFile($path, $workspace, $operation, $maxOutputBytes);
        $size = \filesize($path);
        $structureIsValid = \is_int($size) && match ($format) {
            ImageFormat::PNG => $this->isPng($path, $size),
            ImageFormat::JPEG => $this->isJpeg($path, $size),
            ImageFormat::WEBP => $this->isWebp($path, $size),
        };
        $image = $this->imageInfo($path);
        $expectedType = match ($format) {
            ImageFormat::PNG => IMAGETYPE_PNG,
            ImageFormat::JPEG => IMAGETYPE_JPEG,
            ImageFormat::WEBP => IMAGETYPE_WEBP,
        };
        $width = $image['width'] ?? 0;
        $height = $image['height'] ?? 0;
        if (
            ! $structureIsValid
            || ($image['type'] ?? 0) !== $expectedType
            || $width < 1
            || $height < 1
            || $width > self::MAX_IMAGE_DIMENSION
            || $height > self::MAX_IMAGE_DIMENSION
        ) {
            throw new DwgOperationFailed('image_invalid', ['operation' => $operation]);
        }
    }

    /**
     * Confirm a PNG signature, IHDR chunk, and IEND trailer.
     */
    private function isPng(string $path, int $size): bool
    {
        $header = \file_get_contents($path, false, null, 0, self::PNG_IHDR_BYTES);

        return $size >= self::PNG_IHDR_BYTES + 12
            && \is_string($header)
            && \substr($header, 0, self::PNG_SIGNATURE_BYTES) === "\x89PNG\r\n\x1a\n"
            && \substr($header, 8, 8) === "\0\0\0\rIHDR"
            && $this->hasPngEnd($path, $size);
    }

    /**
     * Confirm a JPEG start-of-image and end-of-image marker.
     */
    private function isJpeg(string $path, int $size): bool
    {
        $header = \file_get_contents($path, false, null, 0, 2);
        $trailer = $size >= 2 ? \file_get_contents($path, false, null, $size - 2, 2) : false;

        return $size >= 4 && $header === "\xff\xd8" && $trailer === "\xff\xd9";
    }

    /**
     * Confirm a complete RIFF WebP container header.
     */
    private function isWebp(string $path, int $size): bool
    {
        $header = \file_get_contents($path, false, null, 0, 12);
        $riff = \is_string($header) ? \unpack('Vsize', \substr($header, 4, 4)) : false;

        return $size >= 20
            && \is_string($header)
            && \substr($header, 0, 4) === 'RIFF'
            && \substr($header, 8, 4) === 'WEBP'
            && $this->unpackedInteger($riff['size'] ?? null, -1) + 8 === $size;
    }

    /**
     * Read an image's native dimensions and detected type without surfacing parser warnings.
     *
     * @return array{width: int, height: int, type: int}|null
     */
    private function imageInfo(string $path): ?array
    {
        \set_error_handler(static fn (): bool => true);

        try {
            $image = \getimagesize($path);
        } finally {
            \restore_error_handler();
        }

        return \is_array($image)
            ? ['width' => $image[0], 'height' => $image[1], 'type' => $image[2]]
            : null;
    }

    /**
     * Narrow one value returned by unpack to an integer.
     */
    private function unpackedInteger(mixed $value, int $default = 0): int
    {
        return \is_int($value) ? $value : $default;
    }

    /**
     * Return the LibreOffice filter for one image format and supported resolution.
     */
    private function imageFilter(ImageFormat $format, ImageResolution $resolution): string
    {
        $filter = match ($format) {
            ImageFormat::PNG => 'png:draw_png_Export',
            ImageFormat::JPEG => 'jpg:draw_jpg_Export',
            ImageFormat::WEBP => 'webp:draw_webp_Export',
        };
        $dimensions = match ($resolution) {
            ImageResolution::HIGH => '{"PixelHeight":{"type":"long","value":"5792"},"PixelWidth":{"type":"long","value":"4096"}}',
            ImageResolution::MEDIUM => '{"PixelHeight":{"type":"long","value":"2896"},"PixelWidth":{"type":"long","value":"2048"}}',
            ImageResolution::LOW => '{"PixelHeight":{"type":"long","value":"1448"},"PixelWidth":{"type":"long","value":"1024"}}',
        };

        return $filter . ':' . $dimensions;
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
