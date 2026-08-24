<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

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
     * Wrap bytes as an explicitly binary DWG source.
     */
    public static function from(string $contents): self
    {
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
