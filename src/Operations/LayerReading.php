<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Operations;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgLayer;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Internal\Converter;

/** Reads layer data from one bound DWG source. */
final class LayerReading
{
    /** Bind a source without performing I/O. */
    public function __construct(
        private readonly Converter $converter,
        private readonly UploadedFile|string|DwgBinary $source,
        private readonly bool $onlyOn = false,
    ) {}

    /** Select native ON layers without performing I/O or changing this operation. */
    public function onlyOn(): self
    {
        return new self($this->converter, $this->source, true);
    }

    /**
     * Read reusable layer data and attempt workspace cleanup before returning.
     *
     * @return Collection<int, DwgLayer>
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function read(): Collection
    {
        return $this->converter->layers($this->source, $this->onlyOn);
    }
}
