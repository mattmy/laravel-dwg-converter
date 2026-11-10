<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Contains the available DWG timestamps in RFC 3339 UTC form. */
final readonly class DwgInfoTimestamps
{
    /** Preserve the available drawing creation and last-save times. */
    public function __construct(
        public ?string $createdAt,
        public ?string $lastSavedAt,
    ) {}
}
