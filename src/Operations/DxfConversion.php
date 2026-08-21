<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Operations;

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgOutput;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Internal\Converter;

/**
 * Configures and performs one DWG to DXF conversion.
 */
final class DxfConversion
{
    /**
     * Create an unconfigured DXF conversion.
     */
    public function __construct(
        private readonly Converter $converter,
        private readonly ?DxfVersion $version = null,
    ) {}

    /**
     * Return a cloned conversion with the requested DXF target version.
     */
    public function toVersion(DxfVersion $version): self
    {
        return new self($this->converter, $version);
    }

    /**
     * Convert one DWG source to an ASCII DXF artifact.
     */
    public function convert(UploadedFile|string|DwgBinary $source): DwgOutput
    {
        return $this->converter->dxf($source, $this->version);
    }
}
