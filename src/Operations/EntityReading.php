<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Operations;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgEntityQueryResult;
use Mattmy\DwgConverter\DwgLayer;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Internal\Converter;

/** Reads stored entity records from one bound source. */
final readonly class EntityReading
{
    /**
     * Bind a source without performing I/O.
     *
     * @param  list<string>|null  $layers
     */
    public function __construct(
        private Converter $converter,
        private UploadedFile|string|DwgBinary $source,
        private ?array $layers = null,
    ) {}

    /**
     * Replace the selected layer names without performing I/O.
     *
     * @param  string|DwgLayer|array<array-key, mixed>|Collection<array-key, mixed>  $layers  Arrays must be lists of names or layers.
     *
     * @throws InvalidArgumentException
     */
    public function onlyLayers(string|DwgLayer|array|Collection $layers, string|DwgLayer ...$additionalLayers): self
    {
        $names = $layers instanceof Collection ? $layers->values()->all() : (\is_array($layers) ? $layers : [$layers]);
        if (! \array_is_list($names)) {
            throw new InvalidArgumentException('Layers must be a list of names or DwgLayer objects.');
        }
        $validated = [];
        foreach ([...$names, ...\array_values($additionalLayers)] as $name) {
            $name = $name instanceof DwgLayer ? $name->name : $name;
            if (! \is_string($name)) {
                throw new InvalidArgumentException('Layers must be names or DwgLayer objects.');
            }
            $validated[] = $name;
        }

        return new self($this->converter, $this->source, $validated);
    }

    /**
     * Read reusable data and attempt cleanup before returning.
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     * @throws LibreDwgUnavailable
     */
    public function read(): DwgEntityQueryResult
    {
        return $this->converter->entities($this->source, $this->layers);
    }
}
