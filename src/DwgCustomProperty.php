<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Represents one source-ordered drawing custom property. */
final readonly class DwgCustomProperty
{
    /** Preserve the available name and value without normalizing the text. */
    public function __construct(
        public ?string $name,
        public ?string $value,
    ) {}
}
