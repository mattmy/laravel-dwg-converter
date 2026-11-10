<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Contains the available DWG summary fields and custom properties. */
final readonly class DwgSummaryInfo
{
    /**
     * Preserve source text and distinguish an absent property list from an empty one.
     *
     * @param  list<DwgCustomProperty>|null  $customProperties
     */
    public function __construct(
        public ?string $title,
        public ?string $subject,
        public ?string $author,
        public ?string $keywords,
        public ?string $comments,
        public ?string $hyperlinkBase,
        public ?string $lastSavedBy,
        public ?string $revisionNumber,
        public ?array $customProperties,
    ) {}
}
