<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

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
    public function toDxf(): DxfConversion
    {
        return new DxfConversion($this->converter);
    }

    /**
     * Start a DWG to SVG conversion.
     */
    public function toSvg(): SvgConversion
    {
        return new SvgConversion($this->converter);
    }

    /**
     * Start an embedded DWG thumbnail extraction.
     */
    public function thumbnail(): ThumbnailExtraction
    {
        return new ThumbnailExtraction($this->converter);
    }
}
