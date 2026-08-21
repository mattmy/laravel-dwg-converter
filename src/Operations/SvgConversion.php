<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Operations;

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgOutput;
use Mattmy\DwgConverter\Internal\Converter;

/**
 * Performs one best-effort DWG to SVG conversion.
 */
final class SvgConversion
{
    /**
     * Create an SVG conversion backed by the shared converter.
     */
    public function __construct(private readonly Converter $converter) {}

    /**
     * Convert one DWG source to an SVG artifact.
     */
    public function convert(UploadedFile|string|DwgBinary $source): DwgOutput
    {
        return $this->converter->svg($source);
    }
}
