<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Operations;

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgOutput;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
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
        private readonly UploadedFile|string|DwgBinary $source,
        private readonly ?DxfVersion $version = null,
    ) {}

    /**
     * Return a cloned conversion with the requested DXF target version.
     */
    public function toVersion(DxfVersion $version): self
    {
        return new self($this->converter, $this->source, $version);
    }

    /**
     * Convert one DWG source to an ASCII DXF artifact.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function convert(): DwgOutput
    {
        return $this->converter->dxf($this->source, $this->version);
    }
}
