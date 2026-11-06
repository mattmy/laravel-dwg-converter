<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Holds immutable normalized query data. */
final readonly class DwgEntity
{
    /**
     * Preserve one stored entity and its local issues.
     *
     * @param  list<DwgAttribute>|null  $attributes
     * @param  list<DwgEntityIssue>  $issues
     */
    public function __construct(
        public ?string $type,
        public ?string $handle,
        public DwgLayerReference $layer,
        public DwgEntityOwner $owner,
        public DwgLineGeometry|DwgCircleGeometry|DwgArcGeometry|DwgInsertGeometry|null $geometry,
        public DwgColor $color,
        public ?DwgBlockReference $block,
        public ?array $attributes,
        public bool $isComplete,
        public array $issues,
    ) {}
}
