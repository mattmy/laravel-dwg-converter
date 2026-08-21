<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Operations;

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgOutput;
use Mattmy\DwgConverter\Internal\Converter;

/**
 * Extracts one embedded DWG preview image.
 */
final class ThumbnailExtraction
{
    /**
     * Create a thumbnail extraction backed by the shared converter.
     */
    public function __construct(private readonly Converter $converter) {}

    /**
     * Extract an embedded thumbnail from one DWG source.
     */
    public function extract(UploadedFile|string|DwgBinary $source): DwgOutput
    {
        return $this->converter->thumbnail($source);
    }
}
