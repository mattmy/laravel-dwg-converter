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
use Mattmy\DwgConverter\PngResolution;

/**
 * Configures and performs one DWG to PNG preview conversion.
 */
final class PngConversion
{
    /**
     * Create a PNG preview conversion backed by the shared converter.
     */
    public function __construct(
        private readonly Converter $converter,
        private readonly UploadedFile|string|DwgBinary $source,
        private readonly ?DxfVersion $dxfVersion = null,
        private readonly PngResolution $resolution = PngResolution::HIGH,
    ) {}

    /**
     * Return a cloned conversion with the selected intermediate DXF version.
     */
    public function usingDxfVersion(DxfVersion $version): self
    {
        return new self($this->converter, $this->source, $version, $this->resolution);
    }

    /**
     * Return a cloned conversion with the selected PNG preview resolution.
     */
    public function atResolution(PngResolution $resolution): self
    {
        return new self($this->converter, $this->source, $this->dxfVersion, $resolution);
    }

    /**
     * Convert one DWG source to a trimmed PNG preview artifact.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function convert(): DwgOutput
    {
        return $this->converter->png($this->source, $this->dxfVersion, $this->resolution);
    }
}
