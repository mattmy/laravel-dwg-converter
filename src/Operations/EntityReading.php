<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Operations;

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgEntityQueryResult;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Internal\Converter;

/** Reads stored entity records from one bound source. */
final readonly class EntityReading
{
    /** Bind a source without performing I/O. */
    public function __construct(
        private Converter $converter,
        private UploadedFile|string|DwgBinary $source,
    ) {}

    /**
     * Read reusable data and attempt cleanup before returning.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function read(): DwgEntityQueryResult
    {
        return $this->converter->entities($this->source);
    }
}
