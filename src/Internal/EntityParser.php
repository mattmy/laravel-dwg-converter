<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Internal;

use JsonException;
use Mattmy\DwgConverter\DwgAngleUnit;
use Mattmy\DwgConverter\DwgArcGeometry;
use Mattmy\DwgConverter\DwgAttribute;
use Mattmy\DwgConverter\DwgBlockReference;
use Mattmy\DwgConverter\DwgCircleGeometry;
use Mattmy\DwgConverter\DwgColor;
use Mattmy\DwgConverter\DwgColorMode;
use Mattmy\DwgConverter\DwgCoordinateSystem;
use Mattmy\DwgConverter\DwgDirection;
use Mattmy\DwgConverter\DwgDrawingUnits;
use Mattmy\DwgConverter\DwgDrawingUnitStatus;
use Mattmy\DwgConverter\DwgEntity;
use Mattmy\DwgConverter\DwgEntityCollection;
use Mattmy\DwgConverter\DwgEntityIssue;
use Mattmy\DwgConverter\DwgEntityIssueReason as Issue;
use Mattmy\DwgConverter\DwgEntityOwner;
use Mattmy\DwgConverter\DwgEntityQueryResult;
use Mattmy\DwgConverter\DwgInsertGeometry;
use Mattmy\DwgConverter\DwgLayerReference;
use Mattmy\DwgConverter\DwgLineGeometry;
use Mattmy\DwgConverter\DwgOwnerKind;
use Mattmy\DwgConverter\DwgPoint;
use Mattmy\DwgConverter\DwgQueryDiagnostic;
use Mattmy\DwgConverter\DwgQueryDiagnosticReason as Diagnostic;
use Mattmy\DwgConverter\DwgScale;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use stdClass;

/** Normalizes one native JSON dump without guessing missing CAD values. */
final class EntityParser
{
    /** @var array<string, list<stdClass>> */
    private array $index = [];

    /** @var list<DwgEntityIssue> */
    private array $issues = [];

    private ?stdClass $header = null;

    private ?string $version = null;

    /**
     * Decode once, preserving JSON object/array identity and unsigned handles.
     *
     * @param  list<string>|null  $layers
     *
     * @throws DwgOperationFailed
     */
    public function parse(string $contents, ?array $layers = null): DwgEntityQueryResult
    {
        try {
            $data = \json_decode($contents, flags: \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new DwgOperationFailed('entities_invalid', ['operation' => 'entities'], $exception);
        }
        unset($contents);
        if (! $data instanceof stdClass || ! \is_array($data->OBJECTS ?? null)) {
            throw new DwgOperationFailed('entities_invalid', ['operation' => 'entities']);
        }
        $this->header = ($data->HEADER ?? null) instanceof stdClass ? $data->HEADER : null;
        $this->version = ($data->FILEHEADER ?? null) instanceof stdClass ? $this->text($data->FILEHEADER->version ?? null) : null;
        $this->index = [];
        foreach ($data->OBJECTS as $record) {
            if ($record instanceof stdClass) {
                $handle = $this->handle($record->handle ?? null);
                if ($handle !== null) {
                    $this->index['h' . $handle][] = $record;
                }
            }
        }
        $entities = [];
        $counts = [];
        foreach ($data->OBJECTS as $record) {
            $kind = $record instanceof stdClass ? $this->classifyRecord($record) : null;
            if (! $record instanceof stdClass) {
                $reason = Diagnostic::NON_OBJECT_RECORDS;
            } elseif ($kind === 'entity') {
                $layer = null;
                if ($layers !== null) {
                    $this->issues = [];
                    [$layerHandle, $target] = $this->reference($record->layer ?? null, 'layer', 'LAYER');
                    $name = $target->name ?? null;
                    if (! \is_string($name) || $name === '' || ! \in_array($name, $layers, true)) {
                        continue;
                    }
                    $layer = new DwgLayerReference($layerHandle, $name);
                }
                $entities[] = $this->entity($record, $layer);

                continue;
            } elseif ($kind === 'object') {
                $reason = Diagnostic::NON_ENTITY_RECORDS;
            } else {
                $reason = Diagnostic::UNCLASSIFIABLE_RECORDS;
            }
            $counts[$reason->value] = ($counts[$reason->value] ?? 0) + 1;
        }
        $diagnostics = [];
        foreach ($counts as $reason => $count) {
            $diagnostics[] = new DwgQueryDiagnostic(Diagnostic::from($reason), $count);
        }
        $code = $this->header->INSUNITS ?? null;
        $code = \is_int($code) ? $code : null;
        $status = match (true) {
            $code === 0 => DwgDrawingUnitStatus::UNSPECIFIED,
            $code !== null && $code >= 1 && $code <= 24 => DwgDrawingUnitStatus::SPECIFIED,
            default => DwgDrawingUnitStatus::UNKNOWN,
        };
        if ($status === DwgDrawingUnitStatus::UNKNOWN) {
            $diagnostics[] = new DwgQueryDiagnostic(Diagnostic::UNKNOWN_DRAWING_UNITS, null);
        }
        $this->index = [];
        $this->header = null;
        $this->version = null;
        $this->issues = [];

        return new DwgEntityQueryResult(new DwgEntityCollection($entities), $diagnostics, new DwgDrawingUnits($code, $status));
    }

    /**
     * Apply the same exclusive native markers to query records and relation targets.
     *
     * @return 'entity'|'object'|null
     */
    private function classifyRecord(stdClass $record): ?string
    {
        return match (true) {
            ! \property_exists($record, 'object') && \is_string($record->entity ?? null)
                && ! $this->isBigIntegerMarker($record->entity) => 'entity',
            ! \property_exists($record, 'entity') && \is_string($record->object ?? null)
                && $record->object !== '' && ! $this->isBigIntegerMarker($record->object) => 'object',
            default => null,
        };
    }

    /** Detect strings indistinguishable from JSON integers outside PHP's int range. */
    private function isBigIntegerMarker(string $marker): bool
    {
        return \preg_match('/^-?[1-9][0-9]*$/D', $marker) === 1
            && (string) (int) $marker !== $marker;
    }

    /** Normalize one confirmed entity, preserving partial data and source order. */
    private function entity(stdClass $record, ?DwgLayerReference $layer = null): DwgEntity
    {
        $this->issues = [];
        $type = $this->requiredText($record, 'entity', 'type', false);
        if ($type !== null && \preg_match('/^(?=.*[A-Za-z])[A-Za-z0-9_]+$/D', $type) !== 1) {
            $this->issue(Issue::INVALID_VALUE, 'type');
            $type = null;
        }
        $handle = $this->handle($record->handle ?? null);
        if ($handle === null) {
            $this->issue(\property_exists($record, 'handle') ? Issue::INVALID_VALUE : Issue::MISSING_VALUE, 'handle');
        } elseif (\count($this->index['h' . $handle] ?? []) > 1) {
            $this->issue(Issue::DUPLICATE_HANDLE, 'handle');
        }
        if ($layer === null) {
            [$layerHandle, $target] = $this->reference($record->layer ?? null, 'layer', 'LAYER');
            $layer = new DwgLayerReference($layerHandle, $target === null ? null : $this->requiredText($target, 'name', 'layer.name', false));
        }
        $owner = $this->owner($record);
        $block = null;
        $attributes = null;
        if (\in_array($type, ['INSERT', 'MINSERT'], true)) {
            [$blockHandle, $target] = $this->reference($record->block_header ?? null, 'block', 'BLOCK_HEADER');
            $block = new DwgBlockReference($blockHandle, $target === null ? null : $this->requiredText($target, 'name', 'block.name', false));
            $attributes = $this->attributes($record, $handle);
        }
        $color = $this->color($record->color ?? null);
        $geometry = $this->geometry($record, $type);

        return new DwgEntity($type, $handle, $layer, $owner, $geometry, $color, $block, $attributes, $this->issues === [], $this->issues);
    }

    /** Follow explicit owner links without imposing an arbitrary chain depth. */
    private function owner(stdClass $record): DwgEntityOwner
    {
        $direct = $this->handle($record->ownerhandle ?? null, true);
        $seen = [];
        $cursor = $record;
        while (true) {
            $ref = match ($cursor->entmode ?? null) {
                1 => $this->header->BLOCK_RECORD_PSPACE ?? null,
                2 => $this->header->BLOCK_RECORD_MSPACE ?? null,
                0 => $cursor->ownerhandle ?? null,
                default => null,
            };
            [$id, $target] = $this->reference($ref, 'owner', \in_array($cursor->entmode ?? null, [1, 2], true) ? 'BLOCK_HEADER' : null);
            if ($id === null || $target === null) {
                break;
            }
            if (isset($seen['h' . $id])) {
                $this->issue(Issue::REFERENCE_CYCLE, 'owner');

                break;
            }
            $seen['h' . $id] = true;
            if (($target->object ?? null) === 'BLOCK_HEADER') {
                $name = $this->requiredText($target, 'name', 'owner.containerName', false);
                $model = $this->handle($this->header->BLOCK_RECORD_MSPACE ?? null, true);
                $paper = $this->handle($this->header->BLOCK_RECORD_PSPACE ?? null, true);
                $kind = match (true) {
                    $id === $model && $id === $paper => DwgOwnerKind::UNKNOWN,
                    $id === $model => DwgOwnerKind::MODEL_SPACE,
                    $id === $paper => DwgOwnerKind::PAPER_SPACE,
                    $name !== null && \strcasecmp($name, '*Model_Space') === 0 => DwgOwnerKind::MODEL_SPACE,
                    $name !== null && \preg_match('/^\*Paper_Space\d*$/iD', $name) === 1 => DwgOwnerKind::PAPER_SPACE,
                    $name !== null => DwgOwnerKind::BLOCK_DEFINITION,
                    default => DwgOwnerKind::UNKNOWN,
                };
                $layoutHandle = null;
                $layoutName = null;
                if ($id === $model && $id === $paper) {
                    $this->issue(Issue::INVALID_REFERENCE, 'owner');
                }
                if ($cursor === $record && \in_array($record->entmode ?? null, [1, 2], true)
                    && $direct === null && ! $this->isNullReference($record->ownerhandle ?? null)) {
                    $this->issue(Issue::INVALID_REFERENCE, 'owner');
                }
                if ($kind === DwgOwnerKind::PAPER_SPACE || ! $this->isNullReference($target->layout ?? null)) {
                    [$layoutHandle, $layout] = $this->reference($target->layout ?? null, 'owner.layoutHandle', 'LAYOUT');
                    $layoutName = $layout === null ? null : $this->requiredText($layout, 'layout_name', 'owner.layoutName', false);
                }

                return new DwgEntityOwner($kind, $direct, $id, $name, $layoutHandle, $layoutName);
            }
            if ($this->classifyRecord($target) !== 'entity') {
                $this->issue(Issue::WRONG_TARGET_TYPE, 'owner');

                break;
            }
            $cursor = $target;
        }

        return new DwgEntityOwner(DwgOwnerKind::UNKNOWN, $direct, null, null, null, null);
    }

    /**
     * Preserve ordered INSERT/MINSERT attributes, including partially broken lists.
     *
     * @return list<DwgAttribute>|null
     */
    private function attributes(stdClass $record, ?string $owner): ?array
    {
        $flag = $record->has_attribs ?? null;
        $hasChain = ! $this->isNullReference($record->first_attrib ?? null) || ! $this->isNullReference($record->last_attrib ?? null);
        $hasLinks = \property_exists($record, 'attribs') || $hasChain;
        if ($flag === 0 || $flag === false) {
            if (! $hasChain && (! $hasLinks || ($record->attribs ?? null) === [])
                && (($record->num_owned ?? null) === 0 || (! $hasLinks && ! \property_exists($record, 'num_owned')))) {
                return [];
            }
            $this->issue(Issue::INCOMPLETE_ATTRIBUTES, 'attributes');
        } elseif ($flag !== 1 && $flag !== true) {
            $this->issue(Issue::INCOMPLETE_ATTRIBUTES, 'attributes');
            if (! $hasLinks) {
                return null;
            }
        }
        $refs = $record->attribs ?? null;
        $linked = ! \property_exists($record, 'attribs');
        if ($linked) {
            $refs = [$record->first_attrib ?? null];
        } elseif (! \is_array($refs) || ! \array_is_list($refs)) {
            $this->issue(Issue::INCOMPLETE_ATTRIBUTES, 'attributes');

            return null;
        }
        $result = [];
        $seen = [];
        $last = $this->handle($record->last_attrib ?? null, true);
        if (! $linked && $refs === [] && ($record->num_owned ?? null) === 0) {
            if ($hasChain) {
                $this->issue(Issue::INCOMPLETE_ATTRIBUTES, 'attributes');
            }

            return [];
        }
        for ($position = 0; $position < \count($refs); $position++) {
            [$id, $target] = $this->reference($refs[$position], 'attributes', 'ATTRIB');
            if ($target === null || $id === null) {
                continue;
            }
            if (isset($seen['h' . $id])) {
                if ($linked) {
                    $this->issue(Issue::REFERENCE_CYCLE, 'attributes');

                    break;
                }
                $this->issue(Issue::INCOMPLETE_ATTRIBUTES, 'attributes');
            }
            $seen['h' . $id] = true;
            if ($owner === null || \count($this->index['h' . $owner] ?? []) !== 1
                || $this->handle($target->ownerhandle ?? null, true) !== $owner) {
                $this->issue(Issue::INCOMPLETE_ATTRIBUTES, 'attributes');
            }
            $result[] = new DwgAttribute($id, $this->requiredText($target, 'tag', 'attributes.tag'), $this->requiredText($target, 'text_value', 'attributes.value'));
            if ($linked) {
                if ($last === $id) {
                    break;
                }
                if ($last === null) {
                    $this->issue(Issue::INCOMPLETE_ATTRIBUTES, 'attributes');

                    break;
                }
                $refs[] = $target->next_entity ?? null;
                if ($target->next_entity ?? null) {
                    continue;
                }
                $this->issue(Issue::INCOMPLETE_ATTRIBUTES, 'attributes');

                break;
            }
        }
        if (! $linked && (! \is_int($record->num_owned ?? null) || $record->num_owned !== \count($refs))) {
            $this->issue(Issue::INCOMPLETE_ATTRIBUTES, 'attributes');
        }

        return $result === [] ? null : $result;
    }

    /**
     * Resolve only unique, classified targets while retaining the source handle.
     *
     * @return array{?string, ?stdClass}
     */
    private function reference(mixed $value, string $field, ?string $type = null): array
    {
        $id = $this->handle($value, true);
        if ($id === null) {
            $this->issue($value === null ? Issue::MISSING_REFERENCE : Issue::INVALID_REFERENCE, $field);

            return [null, null];
        }
        $targets = $this->index['h' . $id] ?? [];
        if (\count($targets) !== 1) {
            $this->issue($targets === [] ? Issue::TARGET_NOT_FOUND : Issue::AMBIGUOUS_REFERENCE, $field);

            return [$id, null];
        }
        $target = $targets[0];
        $kind = $this->classifyRecord($target);
        if ($kind === null || ($type !== null && ($kind !== ($type === 'ATTRIB' ? 'entity' : 'object') || $target->{$kind} !== $type))) {
            $this->issue(Issue::WRONG_TARGET_TYPE, $field);

            return [$id, null];
        }

        return [$id, $target];
    }

    /** Normalize unsigned 64-bit identity/reference tuples without integer overflow. */
    private function handle(mixed $value, bool $reference = false): ?string
    {
        if ($reference && $this->isLegacy() && \is_array($value) && \array_is_list($value) && \count($value) === 3
            && \is_int($value[0]) && $value[0] >= 0 && $value[0] <= 8 && \is_int($value[1])) {
            $value = [4, \max(1, $value[0]), 0, $value[2]];
        }
        if (! \is_array($value) || ! \array_is_list($value) || \count($value) !== ($reference ? 4 : 3)
            || ! \is_int($value[0]) || ! \is_int($value[1]) || $value[1] < 0 || $value[1] > 8
            || ($reference ? ! \in_array($value[0], [0, 2, 3, 4, 5, 6, 8, 10, 12], true) : ($value[0] !== 0 || $value[1] === 0))) {
            return null;
        }
        $number = $value[$reference ? 3 : 2];
        $stored = $value[2];
        if ((! \is_int($stored) && ! \is_string($stored)) || \preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $stored) !== 1) {
            return null;
        }
        $storedDecimal = (string) $stored;
        $maximum = ['0', '255', '65535', '16777215', '4294967295', '1099511627775', '281474976710655', '72057594037927935', '18446744073709551615'][$value[1]];
        if (\strlen($storedDecimal) > \strlen($maximum) || (\strlen($storedDecimal) === \strlen($maximum) && \strcmp($storedDecimal, $maximum) > 0)
            || ($reference && $value[0] <= 5 && $value[1] === 0)) {
            return null;
        }
        if ((! \is_int($number) && ! \is_string($number)) || \preg_match('/^[1-9][0-9]*$/D', (string) $number) !== 1) {
            return null;
        }
        $decimal = (string) $number;
        if (\strlen($decimal) > 20 || (\strlen($decimal) === 20 && \strcmp($decimal, '18446744073709551615') > 0)) {
            return null;
        }
        $hex = '';
        while ($decimal !== '') {
            $quotient = '';
            $carry = 0;
            foreach (\str_split($decimal) as $digit) {
                $carry = $carry * 10 + (int) $digit;
                $quotient .= (string) \intdiv($carry, 16);
                $carry %= 16;
            }
            $hex = '0123456789ABCDEF'[$carry] . $hex;
            $decimal = \ltrim($quotient, '0');
        }

        return $hex;
    }

    /** Normalize scalar or ENC color without resolving display inheritance. */
    private function color(mixed $value): DwgColor
    {
        $index = $value instanceof stdClass ? ($value->index ?? null) : $value;
        $aci = \is_int($index) && $index >= 1 && $index <= 255 ? $index : null;
        $mode = match (true) {
            $index === 256 => DwgColorMode::BY_LAYER,
            $index === 0 => DwgColorMode::BY_BLOCK,
            $aci !== null => DwgColorMode::ACI,
            default => DwgColorMode::UNKNOWN,
        };
        $rgb = null;
        if ($value instanceof stdClass) {
            $raw = $value->rgb ?? null;
            $flag = \property_exists($value, 'flag') ? $value->flag : 0;
            if (\is_int($flag) && $flag >= 0 && $flag <= 255 && ($flag & 0x40) === 0 && ($flag & 0x80) !== 0
                && \is_string($raw) && \preg_match('/^(?:[cC][0-9a-fA-F])?([0-9a-fA-F]{6})$/D', $raw, $parts) === 1) {
                $rgb = (int) \hexdec($parts[1]);
                $mode = DwgColorMode::TRUE_COLOR;
            } elseif (! \is_int($flag) || $flag < 0 || $flag > 255 || ($flag & 0xC0) !== 0 || ($raw !== null && (! \is_string($raw) || \preg_match('/^(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/D', $raw) !== 1))) {
                $mode = DwgColorMode::UNKNOWN;
            }
        }
        if ($index !== null && $aci === null && $index !== 0 && $index !== 256) {
            $mode = DwgColorMode::UNKNOWN;
        }
        if ($mode === DwgColorMode::UNKNOWN) {
            $this->issue(Issue::UNKNOWN_COLOR, 'color');
        }

        return new DwgColor($mode, $aci, $rgb);
    }

    /** Normalize only the four published geometry types. */
    private function geometry(stdClass $record, ?string $type): DwgLineGeometry|DwgCircleGeometry|DwgArcGeometry|DwgInsertGeometry|null
    {
        if (! \in_array($type, ['LINE', 'CIRCLE', 'ARC', 'INSERT'], true)) {
            $this->issue(Issue::UNSUPPORTED_GEOMETRY, 'geometry');

            return null;
        }
        if ($type === 'LINE') {
            $start = $this->point($record->start ?? null, 'geometry.start');
            $end = $this->point($record->end ?? null, 'geometry.end');

            return $start === null && $end === null ? null : new DwgLineGeometry($start, $end, DwgCoordinateSystem::WCS);
        }
        $extrusion = $this->isLegacy() && ! \property_exists($record, 'extrusion') ? null : $this->direction($record->extrusion ?? null);
        if ($type === 'INSERT') {
            $point = $this->point($record->ins_pt ?? null, 'geometry.insertionPoint');
            $scaleValues = $record->scale ?? null;
            $axes = [];
            foreach (['x', 'y', 'z'] as $i => $axis) {
                $axes[] = $this->isLegacy() && ! \property_exists($record, 'scale') ? null : $this->number(\is_array($scaleValues) ? ($scaleValues[$i] ?? null) : null, 'geometry.scale.' . $axis);
            }
            $scale = $axes === [null, null, null] ? null : new DwgScale(...$axes);
            $rotation = $this->isLegacy() && ! \property_exists($record, 'rotation') ? null : $this->number($record->rotation ?? null, 'geometry.rotation');

            return $point === null && $scale === null && $rotation === null && $extrusion === null ? null : new DwgInsertGeometry($point, $scale, $rotation, $extrusion, DwgCoordinateSystem::OCS, DwgAngleUnit::RADIANS);
        }
        $center = $this->point($record->center ?? null, 'geometry.center');
        $radius = $this->number($record->radius ?? null, 'geometry.radius', true);
        if ($type === 'CIRCLE') {
            return $center === null && $radius === null && $extrusion === null ? null : new DwgCircleGeometry($center, $radius, $extrusion, DwgCoordinateSystem::OCS);
        }
        $start = $this->number($record->start_angle ?? null, 'geometry.startAngle');
        $end = $this->number($record->end_angle ?? null, 'geometry.endAngle');

        return $center === null && $radius === null && $extrusion === null && $start === null && $end === null ? null : new DwgArcGeometry($center, $radius, $start, $end, $extrusion, DwgCoordinateSystem::OCS, DwgAngleUnit::RADIANS);
    }

    /** Preserve usable XY and distinguish a missing Z from a known zero. */
    private function point(mixed $value, string $field): ?DwgPoint
    {
        if (! \is_array($value) || ! \array_is_list($value) || ! \in_array(\count($value), [2, 3], true)) {
            $this->issue($value === null ? Issue::MISSING_VALUE : Issue::INVALID_VALUE, $field);

            return null;
        }
        $x = $this->number($value[0], $field . '.x');
        $y = $this->number($value[1], $field . '.y');
        $z = \count($value) === 3 ? $this->number($value[2], $field . '.z') : null;
        if (\count($value) === 2) {
            // Modern native point fields contain Z even when its value is zero.
            if (! $this->isLegacy()) {
                $this->issue(Issue::MISSING_VALUE, $field . '.z');
            }
        }

        return $x === null || $y === null ? null : new DwgPoint($x, $y, $z);
    }

    /** Reject an incomplete or zero direction without normalizing its length. */
    private function direction(mixed $value): ?DwgDirection
    {
        if (! \is_array($value) || ! \array_is_list($value) || \count($value) !== 3) {
            $this->issue($value === null ? Issue::MISSING_VALUE : Issue::INVALID_VALUE, 'geometry.extrusion');

            return null;
        }
        $axes = [];
        foreach ($value as $axis) {
            $axes[] = $this->number($axis, 'geometry.extrusion');
        }
        if (\in_array(null, $axes, true) || $axes === [0.0, 0.0, 0.0]) {
            $this->issue(Issue::INVALID_VALUE, 'geometry.extrusion');

            return null;
        }

        return new DwgDirection($axes[0], $axes[1], $axes[2]);
    }

    /** Preserve a finite native numeric value without coercing strings. */
    private function number(mixed $value, string $field, bool $positive = false): ?float
    {
        if ((! \is_int($value) && ! \is_float($value)) || ! \is_finite((float) $value) || ($positive && $value <= 0)) {
            $this->issue($value === null ? Issue::MISSING_VALUE : Issue::INVALID_VALUE, $field);

            return null;
        }

        return (float) $value;
    }

    /** Read original UTF-8 text without trimming or converting numeric text. */
    private function requiredText(stdClass $record, string $key, string $field, bool $empty = true): ?string
    {
        $text = $this->text($record->{$key} ?? null);
        if ($text === null || (! $empty && $text === '')) {
            $this->issue(\property_exists($record, $key) ? Issue::INVALID_VALUE : Issue::MISSING_VALUE, $field);

            return null;
        }

        return $text;
    }

    /** Narrow a native string without changing its contents. */
    private function text(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    /** Record a stable public field problem once per reason/path pair. */
    private function issue(Issue $reason, string $field): void
    {
        foreach ($this->issues as $issue) {
            if ($issue->reason === $reason && $issue->field === $field) {
                return;
            }
        }
        $this->issues[] = new DwgEntityIssue($reason, $field);
    }

    /** Identify source generations whose native JSON legitimately omits geometric values. */
    private function isLegacy(): bool
    {
        return \in_array($this->version, ['r10', 'r11', 'r12'], true);
    }

    /** Recognize the writer's explicit null-reference forms without guessing a target. */
    private function isNullReference(mixed $value): bool
    {
        if ($value === null || \in_array($value, [[0, 0], [0, 0, 0]], true)) {
            return true;
        }

        return \is_array($value) && \array_is_list($value) && \count($value) === 4
            && \in_array($value[0], [0, 2, 3, 4, 5], true)
            && $value[1] === 0 && $value[2] === 0 && $value[3] === 0;
    }
}
