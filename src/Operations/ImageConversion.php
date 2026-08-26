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
use Mattmy\DwgConverter\ImageFormat;
use Mattmy\DwgConverter\ImageResolution;
use Mattmy\DwgConverter\Internal\Converter;

/**
 * Configures and performs one DWG to raster image conversion.
 */
final class ImageConversion
{
    /**
     * Create an image conversion backed by the shared converter.
     */
    public function __construct(
        private readonly Converter $converter,
        private readonly UploadedFile|string|DwgBinary $source,
        private readonly ImageFormat $format = ImageFormat::PNG,
        private readonly ?DxfVersion $dxfVersion = null,
        private readonly ImageResolution $resolution = ImageResolution::HIGH,
    ) {}

    /**
     * Return a cloned conversion with the selected final image format.
     */
    public function format(ImageFormat $format): self
    {
        return new self($this->converter, $this->source, $format, $this->dxfVersion, $this->resolution);
    }

    /**
     * Return a cloned conversion with the selected intermediate DXF version.
     */
    public function usingDxfVersion(DxfVersion $version): self
    {
        return new self($this->converter, $this->source, $this->format, $version, $this->resolution);
    }

    /**
     * Return a cloned conversion with the selected image preview resolution.
     */
    public function atResolution(ImageResolution $resolution): self
    {
        return new self($this->converter, $this->source, $this->format, $this->dxfVersion, $resolution);
    }

    /**
     * Convert one DWG source to a trimmed raster image artifact.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function convert(): DwgOutput
    {
        return $this->converter->image($this->source, $this->format, $this->dxfVersion, $this->resolution);
    }
}
