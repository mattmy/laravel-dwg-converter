<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Holds immutable normalized query data. */
final readonly class DwgEntityOwner
{
    /** Preserve the normalized query data. */
    public function __construct(
        public DwgOwnerKind $kind,
        public ?string $directHandle,
        public ?string $containerHandle,
        public ?string $containerName,
        public ?string $layoutHandle,
        public ?string $layoutName,
    ) {}
}
