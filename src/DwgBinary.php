<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

use InvalidArgumentException;

/**
 * Distinguishes raw DWG bytes from a local path string.
 */
final readonly class DwgBinary
{
    /**
     * Store explicitly supplied DWG bytes.
     */
    private function __construct(private string $contents) {}

    /**
     * Wrap non-empty bytes as a DWG binary source.
     *
     * @throws InvalidArgumentException
     */
    public static function from(string $contents): self
    {
        if ($contents === '') {
            throw new InvalidArgumentException('DWG binary contents cannot be empty.');
        }

        return new self($contents);
    }

    /**
     * Return the unmodified binary contents.
     */
    public function contents(): string
    {
        return $this->contents;
    }
}
