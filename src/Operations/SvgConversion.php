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
 * Performs one best-effort DWG to SVG conversion.
 */
final class SvgConversion
{
    /**
     * Create an SVG conversion backed by the shared converter.
     */
    public function __construct(
        private readonly Converter $converter,
        private readonly UploadedFile|string|DwgBinary $source,
    ) {}

    /**
     * Convert one DWG source to an SVG artifact.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function convert(): DwgOutput
    {
        return $this->converter->svg($this->source);
    }
}
