<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\Internal\Converter;
use Mattmy\DwgConverter\Operations\DxfConversion;
use Mattmy\DwgConverter\Operations\SvgConversion;
use Mattmy\DwgConverter\Operations\ThumbnailExtraction;

/**
 * Creates stateless operation objects for the DWG facade.
 */
final class DwgManager
{
    /**
     * Create a manager backed by the shared internal converter.
     */
    public function __construct(private readonly Converter $converter) {}

    /**
     * Start an immutable DWG to DXF conversion.
     */
    public function toDxf(UploadedFile|string|DwgBinary $source): DxfConversion
    {
        return new DxfConversion($this->converter, $source);
    }

    /**
     * Start a DWG to SVG conversion.
     */
    public function toSvg(UploadedFile|string|DwgBinary $source): SvgConversion
    {
        return new SvgConversion($this->converter, $source);
    }

    /**
     * Start an embedded DWG thumbnail extraction.
     */
    public function thumbnail(UploadedFile|string|DwgBinary $source): ThumbnailExtraction
    {
        return new ThumbnailExtraction($this->converter, $source);
    }
}
