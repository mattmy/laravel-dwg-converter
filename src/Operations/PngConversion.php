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
 * Performs one fixed DWG to PNG preview conversion.
 */
final class PngConversion
{
    /**
     * Create a PNG preview conversion backed by the shared converter.
     */
    public function __construct(
        private readonly Converter $converter,
        private readonly UploadedFile|string|DwgBinary $source,
    ) {}

    /**
     * Convert one DWG source to a trimmed PNG preview artifact.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function convert(): DwgOutput
    {
        return $this->converter->png($this->source);
    }
}
