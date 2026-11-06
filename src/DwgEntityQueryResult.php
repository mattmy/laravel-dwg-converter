<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

use Illuminate\Support\Collection;

/** Contains reusable stored entities, drawing units and query diagnostics. */
final readonly class DwgEntityQueryResult
{
    /** @var DwgEntityCollection<int, DwgEntity> */
    public DwgEntityCollection $entities;

    /**
     * Preserve query data without retaining temporary resource ownership.
     *
     * @param  Collection<int, DwgEntity>  $entities
     * @param  list<DwgQueryDiagnostic>  $diagnostics
     */
    public function __construct(
        Collection $entities,
        public array $diagnostics,
        public DwgDrawingUnits $drawingUnits,
    ) {
        $this->entities = $entities instanceof DwgEntityCollection ? $entities : new DwgEntityCollection($entities);
    }

    /**
     * Select complete entities from the current collection.
     *
     * @return DwgEntityCollection<int, DwgEntity>
     */
    public function complete(): DwgEntityCollection
    {
        return $this->entities->complete();
    }

    /**
     * Select incomplete entities from the current collection.
     *
     * @return DwgEntityCollection<int, DwgEntity>
     */
    public function incomplete(): DwgEntityCollection
    {
        return $this->entities->incomplete();
    }

    /**
     * Select entities with the given stored color mode.
     *
     * @return DwgEntityCollection<int, DwgEntity>
     */
    public function withColorMode(DwgColorMode $mode): DwgEntityCollection
    {
        return $this->entities->withColorMode($mode);
    }

    /**
     * Select an original type using a convenience enum or exact string.
     *
     * @return DwgEntityCollection<int, DwgEntity>
     */
    public function ofType(DwgEntityType|string $type): DwgEntityCollection
    {
        return $this->entities->ofType($type);
    }

    /**
     * Select entities on the exact layer name.
     *
     * @return DwgEntityCollection<int, DwgEntity>
     */
    public function onLayer(string $name): DwgEntityCollection
    {
        return $this->entities->onLayer($name);
    }

    /**
     * Select entities with the given owner classification.
     *
     * @return DwgEntityCollection<int, DwgEntity>
     */
    public function withOwnerKind(DwgOwnerKind $kind): DwgEntityCollection
    {
        return $this->entities->withOwnerKind($kind);
    }

    /**
     * Select entities referencing the exact target block name.
     *
     * @return DwgEntityCollection<int, DwgEntity>
     */
    public function referencingBlock(string $name): DwgEntityCollection
    {
        return $this->entities->referencingBlock($name);
    }

    /**
     * Select entities with any matching local issue reason.
     *
     * @return DwgEntityCollection<int, DwgEntity>
     */
    public function withIssue(DwgEntityIssueReason $reason): DwgEntityCollection
    {
        return $this->entities->withIssue($reason);
    }
}
