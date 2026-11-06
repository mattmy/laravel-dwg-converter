<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Operations;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgEntityQueryResult;
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
     * @param  string|array<array-key, mixed>  $layers  Only lists of strings are accepted.
     *
     * @throws InvalidArgumentException
     */
    public function onlyLayers(string|array $layers, string ...$additionalLayers): self
    {
        $names = \is_string($layers) ? [$layers] : $layers;
        if (! \array_is_list($names)) {
            throw new InvalidArgumentException('Layer names must be a list of strings.');
        }
        $validated = [];
        foreach ($names as $name) {
            if (! \is_string($name)) {
                throw new InvalidArgumentException('Layer names must be strings.');
            }
            $validated[] = $name;
        }

        return new self($this->converter, $this->source, [...$validated, ...\array_values($additionalLayers)]);
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
