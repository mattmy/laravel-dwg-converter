<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

use Closure;
use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Internal\Converter;
use Mattmy\DwgConverter\Internal\DwgHeader;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Operations\DxfConversion;
use Mattmy\DwgConverter\Operations\EntityReading;
use Mattmy\DwgConverter\Operations\ImageConversion;
use Mattmy\DwgConverter\Operations\InfoReading;
use Mattmy\DwgConverter\Operations\JsonConversion;
use Mattmy\DwgConverter\Operations\LayerReading;
use Mattmy\DwgConverter\Operations\ThumbnailExtraction;

/**
 * Creates stateless operation objects for the DWG facade.
 */
final readonly class DwgManager
{
    /**
     * Accept a converter or defer its resolution until an operation needs it.
     *
     * @param  Converter|Closure(): Converter  $converter
     */
    public function __construct(
        private Converter|Closure $converter,
    ) {}

    /**
     * Start an immutable DWG to DXF conversion.
     */
    public function toDxf(UploadedFile|string|DwgBinary $source): DxfConversion
    {
        return new DxfConversion($this->converter(), $source);
    }

    /**
     * Start a DWG to raster image conversion.
     */
    public function toImage(UploadedFile|string|DwgBinary $source): ImageConversion
    {
        return new ImageConversion($this->converter(), $source);
    }

    /**
     * Start a DWG to LibreDWG structural JSON conversion.
     */
    public function toJson(UploadedFile|string|DwgBinary $source): JsonConversion
    {
        return new JsonConversion($this->converter(), $source);
    }

    /**
     * Start an embedded DWG thumbnail extraction.
     */
    public function thumbnail(UploadedFile|string|DwgBinary $source): ThumbnailExtraction
    {
        return new ThumbnailExtraction($this->converter(), $source);
    }

    /** Bind a source for an independent layer-data query. */
    public function layers(UploadedFile|string|DwgBinary $source): LayerReading
    {
        return new LayerReading($this->converter(), $source);
    }

    /** Bind a source for an independent stored-entity query. */
    public function entities(UploadedFile|string|DwgBinary $source): EntityReading
    {
        return new EntityReading($this->converter(), $source);
    }

    /**
     * Read the source format code without taking a snapshot or starting a tool.
     *
     * @throws InvalidDwg
     */
    public function version(UploadedFile|string|DwgBinary $source): DwgFileVersion
    {
        if ($source instanceof DwgBinary) {
            return DwgHeader::version(\substr($source->contents(), 0, DwgHeader::LENGTH), 'version');
        }

        return DwgHeader::read(Workspace::sourcePath($source, 'version'), 'version');
    }

    /** Bind a source for an independent drawing information query. */
    public function info(UploadedFile|string|DwgBinary $source): InfoReading
    {
        return new InfoReading($this->converter(), $source);
    }

    /** Resolve the converter only for operations that use it. */
    private function converter(): Converter
    {
        return $this->converter instanceof Closure ? ($this->converter)() : $this->converter;
    }
}
