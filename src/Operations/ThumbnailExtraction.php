<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Operations;

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgOutput;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Internal\Converter;

/**
 * Extracts one embedded DWG preview image.
 */
final class ThumbnailExtraction
{
    /**
     * Create a thumbnail extraction backed by the shared converter.
     */
    public function __construct(
        private readonly Converter $converter,
        private readonly UploadedFile|string|DwgBinary $source,
    ) {}

    /**
     * Extract an embedded thumbnail from one DWG source.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function extract(): DwgOutput
    {
        return $this->converter->thumbnail($this->source);
    }
}
