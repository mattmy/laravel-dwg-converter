<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Operations;

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgInfo;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Internal\Converter;

/** Reads basic drawing information from one bound DWG source. */
final readonly class InfoReading
{
    /**
     * Bind a source without performing I/O.
     *
     * @internal Create info readings through Dwg::info().
     */
    public function __construct(
        private Converter $converter,
        private UploadedFile|string|DwgBinary $source,
    ) {}

    /**
     * Read metadata from a fresh snapshot and attempt workspace cleanup before returning.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function read(): DwgInfo
    {
        return $this->converter->info($this->source);
    }
}
