<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Internal;

use Illuminate\Http\UploadedFile;
use JsonException;
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
    private const PNG_SIGNATURE_BYTES = 8;

    private const PNG_IHDR_BYTES = 24;

    private const MAX_IMAGE_DIMENSION = 32_768;

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
        $sourceStem = $this->sourceStem($source);
        $configuration = $this->configuration('dwgbmp', $operation);
        $workspace = $this->workspace($source, $operation, $configuration);

        try {
            $executable = $configuration['executable'];
            $this->processRunner->assertAvailable($executable, $operation);
            $this->processRunner->run(
                [$executable, $workspace->inputPath()],
                $workspace,
                $configuration['timeout'],
                $operation,
            );

            $thumbnail = $this->findThumbnail($workspace);
            [$extension, $mimeType] = $this->thumbnailType($thumbnail, $workspace, $configuration['max_output_bytes']);

            return new DwgOutput($workspace, $thumbnail, $extension, $mimeType, $configuration['max_output_bytes'], $operation, $sourceStem);
        } catch (\Throwable $exception) {
            $workspace->cleanup($operation, 'conversion');

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
        $sourceStem = $this->sourceStem($source);
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
                $operation,
            );
            $this->assertBoundedFile($output, $workspace, $operation, $configuration['max_output_bytes']);
            $this->assertDxf($output);

            return new DwgOutput($workspace, $output, 'dxf', 'image/vnd.dxf', $configuration['max_output_bytes'], $operation, $sourceStem);
        } catch (\Throwable $exception) {
            $workspace->cleanup($operation, 'conversion');

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
        $sourceStem = $this->sourceStem($source);
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
                $operation,
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
                $sourceStem,
            );
        } catch (\Throwable $exception) {
            $workspace->cleanup($operation, 'conversion');

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
        $sourceStem = $this->sourceStem($source);
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
                $operation,
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
                $operation,
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
                $operation,
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
                $sourceStem,
            );
        } catch (\Throwable $exception) {
            $workspace->cleanup($operation, 'conversion');

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
     * @throws LibreDwgUnavailable
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
     * Derive a non-authoritative storage stem without reading the source.
     */
    private function sourceStem(UploadedFile|string|DwgBinary $source): ?string
    {
        if ($source instanceof DwgBinary) {
            return null;
        }

        $name = $source instanceof UploadedFile ? $source->getClientOriginalName() : \basename($source);

        return \pathinfo($name, \PATHINFO_FILENAME);
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
            && Workspace::isAbsolutePath($temporaryDirectory);

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
     * @return array{executable: string, timeout: float, max_input_bytes: ?int, max_output_bytes: ?int, effective_output_limit: ?int, temporary_directory: string}
     *
     * @throws LibreDwgUnavailable
     */
    private function jsonConfiguration(string $operation): array
    {
        $configuration = $this->configuration('dwgread', $operation);
        $maxJsonOutputBytes = $this->normalizeByteLimit($this->configuration['max_json_output_bytes'] ?? null, $operation);

        return [
            ...$configuration,
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
     * Identify a thumbnail and validate its bounded container structure.
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
            \str_starts_with($header, 'BM') && $this->isBmpThumbnail($path, $size) => ['bmp', 'image/bmp'],
            $header === "\x89PNG\r\n\x1a\n" && $this->isPngThumbnail($path, $size) => ['png', 'image/png'],
            (\str_starts_with($header, "\xd7\xcd\xc6\x9a")
                || \str_starts_with($header, "\x01\x00\x09\x00")
                || \str_starts_with($header, "\x02\x00\x09\x00"))
                && $this->isWmfThumbnail($path, $size) => ['wmf', 'image/wmf'],
            default => throw new DwgOperationFailed('thumbnail_invalid', ['operation' => 'thumbnail']),
        };
    }

    /** Check BMP headers, palette/mask space and pixel-data bounds without decoding. */
    private function isBmpThumbnail(string $path, int $size): bool
    {
        if ($size < 26) {
            return false;
        }

        $header = \file_get_contents($path, false, null, 0, \min($size, 54));
        if (! \is_string($header) || \strlen($header) < 26) {
            return false;
        }
        $file = $this->unpackIntegers('Vsize/vreserved1/vreserved2/Voffset/Vdib', \substr($header, 2, 16));
        $offset = $file['offset'] ?? 0;
        $dib = $file['dib'] ?? 0;
        if (($file['size'] ?? 0) !== $size || ($file['reserved1'] ?? -1) !== 0
            || ($file['reserved2'] ?? -1) !== 0 || $offset < 14 + $dib || $offset >= $size) {
            return false;
        }

        if ($dib === 12) {
            $info = $this->unpackIntegers('vwidth/vheight/vplanes/vbits', \substr($header, 18, 8));
            $width = $info['width'] ?? 0;
            $height = $info['height'] ?? 0;
            $compression = 0;
            $imageBytes = 0;
            $colors = 0;
        } elseif ($dib >= 40 && \strlen($header) >= 54) {
            $info = $this->unpackIntegers('Vwidth/Vheight/vplanes/vbits/Vcompression/VimageBytes/Vx/Vy/Vcolors', \substr($header, 18, 32));
            $width = $info['width'] ?? 0;
            $height = $info['height'] ?? 0;
            if ($width > 0x7FFFFFFF) {
                return false;
            }
            $height = $height > 0x7FFFFFFF ? $height - 0x100000000 : $height;
            $compression = $info['compression'] ?? -1;
            $imageBytes = $info['imageBytes'] ?? 0;
            $colors = $info['colors'] ?? 0;
        } else {
            return false;
        }

        $bits = $info['bits'] ?? 0;
        if ($width < 1 || $height === 0 || ($info['planes'] ?? 0) !== 1) {
            return false;
        }
        $validEncoding = match ($compression) {
            0 => \in_array($bits, [1, 4, 8, 16, 24, 32], true),
            1 => $bits === 8 && $height > 0,
            2 => $bits === 4 && $height > 0,
            3, 6 => \in_array($bits, [16, 32], true),
            4, 5 => $bits === 0 && $height > 0,
            default => false,
        };
        if (! $validEncoding || ($dib === 12 && ! \in_array($bits, [1, 4, 8, 24], true))) {
            return false;
        }

        if ($bits > 0 && $bits <= 8) {
            if ($colors > 1 << $bits) {
                return false;
            }
            $colors = $colors === 0 ? 1 << $bits : $colors;
        }
        $maskBytes = \in_array($compression, [3, 6], true) ? ($compression === 6 ? 16 : 12) : 0;
        $minimumOffset = 14 + \max($dib, 40 + $maskBytes) + $colors * 4;
        if ($dib === 12) {
            $minimumOffset = 26 + $colors * 3;
        }
        $available = $size - $offset;
        if ($offset < $minimumOffset || $imageBytes > $available) {
            return false;
        }
        if (\in_array($compression, [0, 3, 6], true)) {
            $stride = \intdiv($width * $bits + 31, 32) * 4;

            return \abs($height) <= \intdiv($available, $stride)
                && ($imageBytes === 0 || \abs($height) <= \intdiv($imageBytes, $stride));
        }

        return $imageBytes > 0;
    }

    /** Walk PNG chunk boundaries and required headers without decompressing image data. */
    private function isPngThumbnail(string $path, int $size): bool
    {
        $offset = self::PNG_SIGNATURE_BYTES;
        $hasHeader = false;
        $hasData = false;
        $dataEnded = false;
        $hasPalette = false;
        $color = -1;
        $depth = 0;
        while ($offset <= $size - 12) {
            $chunk = \file_get_contents($path, false, null, $offset, 8);
            if (! \is_string($chunk) || \strlen($chunk) !== 8) {
                return false;
            }
            $length = $this->unpackIntegers('Nlength', $chunk)['length'] ?? -1;
            $type = \substr($chunk, 4, 4);
            if ($length < 0 || $length > 0x7FFFFFFF || $length > $size - $offset - 12
                || \preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
                return false;
            }
            if (! $hasHeader) {
                if ($type !== 'IHDR' || $length !== 13) {
                    return false;
                }
                $data = \file_get_contents($path, false, null, $offset + 8, 13);
                if (! \is_string($data) || \strlen($data) !== 13) {
                    return false;
                }
                $info = $this->unpackIntegers('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data);
                $color = $info['color'] ?? -1;
                $depth = $info['depth'] ?? 0;
                $width = $info['width'] ?? 0;
                $height = $info['height'] ?? 0;
                $depths = match ($color) {
                    0 => [1, 2, 4, 8, 16],
                    2, 4, 6 => [8, 16],
                    3 => [1, 2, 4, 8],
                    default => [],
                };
                if ($width < 1 || $width > 0x7FFFFFFF || $height < 1 || $height > 0x7FFFFFFF
                    || ! \in_array($depth, $depths, true) || ($info['compression'] ?? -1) !== 0
                    || ($info['filter'] ?? -1) !== 0 || ! \in_array($info['interlace'] ?? -1, [0, 1], true)) {
                    return false;
                }
                $hasHeader = true;
            } elseif ($type === 'IHDR') {
                return false;
            } elseif ($type === 'PLTE') {
                if ($hasPalette || $hasData || \in_array($color, [0, 4], true)
                    || $length < 3 || $length > 768 || $length % 3 !== 0
                    || ($color === 3 && \intdiv($length, 3) > 1 << $depth)) {
                    return false;
                }
                $hasPalette = true;
            } elseif ($type === 'IDAT') {
                if ($dataEnded || ($color === 3 && ! $hasPalette)) {
                    return false;
                }
                $hasData = true;
            } elseif ($type === 'IEND') {
                return $length === 0 && $hasData && $offset + 12 === $size && $this->hasPngEnd($path, $size);
            } elseif ($hasData) {
                $dataEnded = true;
            }
            $offset += $length + 12;
        }

        return false;
    }

    /** Check standard/placeable WMF headers, record bounds and terminal EOF without rendering. */
    private function isWmfThumbnail(string $path, int $size): bool
    {
        if ($size < 24) {
            return false;
        }
        $header = \file_get_contents($path, false, null, 0, \min($size, 40));
        if (! \is_string($header)) {
            return false;
        }
        $start = \str_starts_with($header, "\xd7\xcd\xc6\x9a") ? 22 : 0;
        if ($size < $start + 24 || \strlen($header) < $start + 18) {
            return false;
        }
        if ($start === 22) {
            $words = $this->unpackIntegers('v11', \substr($header, 0, 22));
            $checksum = 0;
            foreach ($words as $word) {
                $checksum ^= $word;
            }
            if ($checksum !== 0 || ($words[8] ?? 0) === 0 || ($words[9] ?? -1) !== 0 || ($words[10] ?? -1) !== 0) {
                return false;
            }
        }
        $info = $this->unpackIntegers('vtype/vheader/vversion/Vsize/vobjects/Vmax/vmembers', \substr($header, $start, 18));
        if (! \in_array($info['type'] ?? 0, [1, 2], true) || ($info['header'] ?? 0) !== 9
            || ! \in_array($info['version'] ?? 0, [0x0100, 0x0300], true)
            || ($info['size'] ?? 0) * 2 !== $size - $start) {
            return false;
        }
        $offset = $start + 18;
        $maximum = 0;
        while ($offset <= $size - 6) {
            $record = \file_get_contents($path, false, null, $offset, 6);
            if (! \is_string($record) || \strlen($record) !== 6) {
                return false;
            }
            $fields = $this->unpackIntegers('Vwords/vfunction', $record);
            $words = $fields['words'] ?? 0;
            if ($words < 3 || $words > \intdiv($size - $offset, 2)) {
                return false;
            }
            $maximum = \max($maximum, $words);
            $offset += $words * 2;
            if (($fields['function'] ?? -1) === 0) {
                return $words === 3 && $offset === $size && ($info['max'] ?? 0) === $maximum;
            }
        }

        return false;
    }

    /**
     * Narrow unsigned binary header fields at the native unpack boundary.
     *
     * @return array<array-key, int>
     *
     * @throws DwgOperationFailed
     */
    private function unpackIntegers(string $format, string $bytes): array
    {
        $values = \unpack($format, $bytes);
        if ($values === false) {
            throw new DwgOperationFailed('thumbnail_invalid', ['operation' => 'thumbnail']);
        }
        $integers = [];
        foreach ($values as $key => $value) {
            if (! \is_int($value)) {
                throw new DwgOperationFailed('thumbnail_invalid', ['operation' => 'thumbnail']);
            }
            $integers[$key] = $value;
        }

        return $integers;
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
     * Validate a bounded LibreDWG JSON artifact without changing its structure.
     *
     * @throws DwgOperationFailed
     */
    private function assertJson(string $path, Workspace $workspace, ?int $maxOutputBytes): void
    {
        $this->assertBoundedFile($path, $workspace, 'json', $maxOutputBytes);
        $contents = \file_get_contents($path);
        if (! \is_string($contents)) {
            throw new DwgOperationFailed('json_invalid', ['operation' => 'json']);
        }

        try {
            $decoded = \json_decode($contents, flags: \JSON_THROW_ON_ERROR);
            unset($decoded);
        } catch (JsonException $exception) {
            throw new DwgOperationFailed('json_invalid', ['operation' => 'json'], $exception);
        } finally {
            unset($contents);
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
            ImageFormat::PNG => \IMAGETYPE_PNG,
            ImageFormat::JPEG => \IMAGETYPE_JPEG,
            ImageFormat::WEBP => \IMAGETYPE_WEBP,
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
        if ($size < 20) {
            return false;
        }

        $header = \file_get_contents($path, false, null, 0, 12);
        $riff = \is_string($header) ? \unpack('Vsize', \substr($header, 4, 4)) : false;
        $riffSize = $riff['size'] ?? null;

        return \is_string($header)
            && \substr($header, 0, 4) === 'RIFF'
            && \substr($header, 8, 4) === 'WEBP'
            && \is_int($riffSize)
            && $riffSize + 8 === $size;
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
