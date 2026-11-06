<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

use Illuminate\Support\Collection;

/**
 * Provides composable entity filters alongside Laravel collection operations.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @extends Collection<TKey, TValue>
 */
final class DwgEntityCollection extends Collection
{
    /** Select complete entities without changing this collection. */
    public function complete(): static
    {
        return $this->whereStrict('isComplete', true);
    }

    /** Select incomplete entities without changing this collection. */
    public function incomplete(): static
    {
        return $this->whereStrict('isComplete', false);
    }

    /** Select entities whose stored color mode strictly matches the given case. */
    public function withColorMode(DwgColorMode $mode): static
    {
        return $this->whereStrict('color.mode', $mode);
    }

    /** Select an original entity type, including types outside the convenience enum. */
    public function ofType(DwgEntityType|string $type): static
    {
        return $this->whereStrict('type', $type instanceof DwgEntityType ? $type->value : $type);
    }

    /** Select an exact layer name without normalizing its text. */
    public function onLayer(string $name): static
    {
        return $this->whereStrict('layer.name', $name);
    }

    /** Select the resolved owner classification, including unknown owners. */
    public function withOwnerKind(DwgOwnerKind $kind): static
    {
        return $this->whereStrict('owner.kind', $kind);
    }

    /** Select a referenced target block rather than the owning container. */
    public function referencingBlock(string $name): static
    {
        return $this->whereStrict('block.name', $name);
    }

    /** Select each entity once when any local issue has the given reason. */
    public function withIssue(DwgEntityIssueReason $reason): static
    {
        // Preserve the inherited generic return contract when filtering local issues.
        return parent::filter(static function (mixed $entity) use ($reason): bool {
            if (! $entity instanceof DwgEntity) {
                return false;
            }

            foreach ($entity->issues as $issue) {
                if ($issue->reason === $reason) {
                    return true;
                }
            }

            return false;
        });
    }
}
