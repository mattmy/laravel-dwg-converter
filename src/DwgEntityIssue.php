<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Holds immutable normalized query data. */
final readonly class DwgEntityIssue
{
    /** Preserve the normalized query data. */
    public function __construct(
        public DwgEntityIssueReason $reason,
        public ?string $field,
    ) {}
}
