<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Identifies the format stored in a DWG file header. */
final readonly class DwgFileVersion
{
    /** Preserve the original header code and its known format generation, if any. */
    public function __construct(
        public string $code,
        public ?string $format,
    ) {}
}
