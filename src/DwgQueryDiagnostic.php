<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Holds immutable normalized query data. */
final readonly class DwgQueryDiagnostic
{
    /** Preserve the normalized query data. */
    public function __construct(
        public DwgQueryDiagnosticReason $reason,
        public ?int $recordCount,
    ) {}
}
