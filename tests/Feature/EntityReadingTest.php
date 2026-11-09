<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Mattmy\DwgConverter\DwgArcGeometry;
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgColorMode;
use Mattmy\DwgConverter\DwgDrawingUnitStatus;
use Mattmy\DwgConverter\DwgEntityIssueReason;
use Mattmy\DwgConverter\DwgEntityQueryResult;
use Mattmy\DwgConverter\DwgEntityType;
use Mattmy\DwgConverter\DwgInsertGeometry;
use Mattmy\DwgConverter\DwgLineGeometry;
use Mattmy\DwgConverter\DwgManager;
use Mattmy\DwgConverter\DwgOwnerKind;
use Mattmy\DwgConverter\DwgQueryDiagnosticReason;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\Converter;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;
use Symfony\Component\Process\Process;

/**
 * Supply one supported stored LINE; overrides represent individual native faults.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nativeEntity(array $overrides = []): array
{
    return \array_replace([
        'entity' => 'LINE', 'handle' => [0, 1, 3], 'entmode' => 2,
        'layer' => [5, 1, 1, 1], 'color' => 256,
        'start' => [0, 0, 0], 'end' => [1, 2, 3],
    ], $overrides);
}

/**
 * Encode classified records with independently resolvable layer and owner targets.
 *
 * @param  list<mixed>  $records
 * @param  array<string, mixed>|null  $header
 */
function nativeEntityDump(array $records, ?array $header = ['BLOCK_RECORD_MSPACE' => [5, 1, 2, 2], 'INSUNITS' => 4]): string
{
    return \json_encode([
        'FILEHEADER' => ['version' => 'r2018'], 'HEADER' => $header,
        'OBJECTS' => [
            (object) ['object' => 'LAYER', 'handle' => [0, 1, 1], 'name' => ' 圖層 '],
            (object) ['object' => 'BLOCK_HEADER', 'handle' => [0, 1, 2], 'name' => '*Model_Space'],
            ...\array_map(static fn (mixed $record): mixed => \is_array($record) && ! \array_is_list($record) ? (object) $record : $record, $records),
        ],
    ], \JSON_THROW_ON_ERROR);
}

it('matches each convenience type exactly like its original string', function (DwgEntityType $type): void {
    $runner = FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([nativeEntity(['entity' => $type->value])]));
    app()->instance(ProcessRunner::class, $runner);
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    expect($result->ofType($type)->all())->toBe($result->entities->ofType($type->value)->all())
        ->and($result->ofType($type)->sole()->type)->toBe($type->value)
        ->and($runner->commands)->toHaveCount(1);
})->with(\array_map(static fn (DwgEntityType $type): array => [$type], DwgEntityType::cases()));

it('keeps type queries open and does not normalize strings or match unknown types', function (string $type, ?int $key): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(), nativeEntity(['entity' => 'CUSTOM_ENTITY', 'handle' => [0, 1, 4]]),
        nativeEntity(['entity' => '', 'handle' => [0, 1, 5]]),
    ])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    expect($result->ofType($type)->keys()->all())->toBe($key === null ? [] : [$key])
        ->and($result->entities->last()?->type)->toBeNull();
})->with([['LINE', 0], ['CUSTOM_ENTITY', 1], ['line', null], [' LINE ', null], ['', null], ['*', null]]);

it('matches exact layer and target block names without confusing the owner', function (string $name, bool $layerMatches, bool $blockMatches): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        ['object' => 'BLOCK_HEADER', 'handle' => [0, 1, 6], 'name' => ' Target '],
        nativeEntity(['entity' => 'INSERT', 'block_header' => [5, 1, 6, 6], 'has_attribs' => 0]),
        nativeEntity(['entity' => 'MINSERT', 'handle' => [0, 1, 4], 'block_header' => [5, 1, 6, 6], 'has_attribs' => 0]),
        nativeEntity(['handle' => [0, 1, 5]]),
        nativeEntity(['entity' => 'INSERT', 'handle' => [0, 1, 7], 'layer' => null, 'block_header' => null]),
    ])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    expect($result->onLayer($name)->keys()->all())->toBe($layerMatches ? [0, 1, 2] : [])
        ->and($result->referencingBlock($name)->keys()->all())->toBe($blockMatches ? [0, 1] : [])
        ->and($result->onLayer($name)->all())->toBe($result->entities->onLayer($name)->all())
        ->and($result->referencingBlock($name)->all())->toBe($result->entities->referencingBlock($name)->all());
    expect($result->ofType(DwgEntityType::INSERT)->referencingBlock($name)->all())
        ->toBe($result->referencingBlock($name)->ofType('INSERT')->all());
})->with([
    [' 圖層 ', true, false], ['圖層', false, false], [' Target ', false, true],
    [' Target', false, false], [' target ', false, false], ['*Model_Space', false, false], ['', false, false], ['*', false, false],
]);

it('filters all owner classifications independently of the referenced block', function (DwgOwnerKind $kind, int $key): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        ['object' => 'BLOCK_HEADER', 'handle' => [0, 1, 6], 'name' => '*Paper_Space', 'layout' => [5, 1, 7, 7]],
        ['object' => 'LAYOUT', 'handle' => [0, 1, 7], 'layout_name' => 'Sheet'],
        ['object' => 'BLOCK_HEADER', 'handle' => [0, 1, 8], 'name' => 'Definition'],
        nativeEntity(['handle' => [0, 1, 10]]), nativeEntity(['handle' => [0, 1, 11], 'entmode' => 1]),
        nativeEntity(['handle' => [0, 1, 12], 'entmode' => 0, 'ownerhandle' => [4, 1, 8, 8]]),
        nativeEntity(['handle' => [0, 1, 13], 'entmode' => 0, 'ownerhandle' => [4, 1, 99, 99]]),
    ], ['BLOCK_RECORD_MSPACE' => [5, 1, 2, 2], 'BLOCK_RECORD_PSPACE' => [5, 1, 6, 6], 'INSUNITS' => 4])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    expect($result->withOwnerKind($kind)->keys()->all())->toBe([$key])
        ->and($result->withOwnerKind($kind)->all())->toBe($result->entities->withOwnerKind($kind)->all());
})->with([[DwgOwnerKind::MODEL_SPACE, 0], [DwgOwnerKind::PAPER_SPACE, 1], [DwgOwnerKind::BLOCK_DEFINITION, 2], [DwgOwnerKind::UNKNOWN, 3]]);

it('selects an entity once for any matching local issue without filtering diagnostics', function (): void {
    $runner = FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(['layer' => [5, 1, 90, 90], 'entmode' => 0, 'ownerhandle' => [4, 1, 91, 91]]),
        nativeEntity(['handle' => [0, 1, 4], 'entity' => 'HATCH']), nativeEntity(['handle' => [0, 1, 5]]),
    ]));
    app()->instance(ProcessRunner::class, $runner);
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    expect($result->withIssue(DwgEntityIssueReason::TARGET_NOT_FOUND)->keys()->all())->toBe([0])
        ->and($result->entities->first()?->issues)->toHaveCount(2)
        ->and($result->withIssue(DwgEntityIssueReason::TARGET_NOT_FOUND)->all())
        ->toBe($result->entities->withIssue(DwgEntityIssueReason::TARGET_NOT_FOUND)->all())
        ->and($result->withIssue(DwgEntityIssueReason::UNSUPPORTED_GEOMETRY)->keys()->all())->toBe([1])
        ->and($result->complete()->withIssue(DwgEntityIssueReason::TARGET_NOT_FOUND))->toBeEmpty()
        ->and($result->withIssue(DwgEntityIssueReason::REFERENCE_CYCLE)->incomplete())->toBeEmpty()
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($runner->commands)->toHaveCount(1);
});

it('composes entity filters in either order while preserving keys and snapshots', function (): void {
    $runner = FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(), nativeEntity(['handle' => [0, 1, 4], 'entity' => 'HATCH']),
        nativeEntity(['handle' => [0, 1, 5], 'color' => 7]),
    ]));
    app()->instance(ProcessRunner::class, $runner);
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    $filtered = $result->withColorMode(DwgColorMode::BY_LAYER)->complete();
    $earlierLines = $result->ofType(DwgEntityType::LINE);
    $wrapped = new DwgEntityQueryResult(collect($result->entities->all()), $result->diagnostics, $result->drawingUnits);
    expect($filtered->keys()->all())->toBe([0])
        ->and($wrapped->withColorMode(DwgColorMode::BY_LAYER)->complete()->all())->toBe($filtered->all())
        ->and($result->complete()->withColorMode(DwgColorMode::BY_LAYER)->all())->toBe($filtered->all())
        ->and($result->withColorMode(DwgColorMode::BY_LAYER)->incomplete()->keys()->all())->toBe([1])
        ->and($result->entities->whereStrict('color.mode', DwgColorMode::BY_LAYER)->complete()->all())->toBe($filtered->all())
        ->and($filtered->incomplete()->complete())->toBeEmpty()
        ->and($result->entities)->toHaveCount(3);
    expect($earlierLines->keys()->all())->toBe([0, 2]);
    expect($result->ofType(DwgEntityType::LINE)->onLayer(' 圖層 ')->withOwnerKind(DwgOwnerKind::MODEL_SPACE)->complete()->withColorMode(DwgColorMode::BY_LAYER)->all())
        ->toBe($filtered->all())
        ->and($result->withColorMode(DwgColorMode::BY_LAYER)->withOwnerKind(DwgOwnerKind::MODEL_SPACE)->onLayer(' 圖層 ')->ofType('LINE')->ofType('LINE')->complete()->all())
        ->toBe($filtered->all())
        ->and($filtered->sole())->toBe($result->entities->first())
        ->and($wrapped->drawingUnits)->toBe($result->drawingUnits)
        ->and($wrapped->diagnostics)->toBe($result->diagnostics);
    foreach (['completeEntities', 'incompleteEntities', 'entitiesWithColorMode'] as $oldName) {
        expect(\method_exists($result, $oldName))->toBeFalse()
            ->and(\method_exists($result->entities, $oldName))->toBeFalse();
    }
    $result->entities->forget(0);
    expect($filtered)->toHaveCount(1)
        ->and($earlierLines->keys()->all())->toBe([0, 2])
        ->and($result->ofType('LINE')->keys()->all())->toBe([2])
        ->and($result->withColorMode(DwgColorMode::BY_LAYER)->complete())->toBeEmpty()
        ->and($runner->commands)->toHaveCount(1);
});

it('reads reusable typed entities only when the bound operation executes', function (): void {
    $runner = FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([nativeEntity()]));
    app()->instance(ProcessRunner::class, $runner);
    $reading = Dwg::entities(DwgBinary::from('AC1032 fixture'));
    expect($runner->commands)->toBe([]);
    $result = $reading->read();
    $entity = $result->entities->sole();
    expect($entity->handle)->toBe('3')
        ->and($entity->layer->name)->toBe(' 圖層 ')
        ->and($entity->owner->kind)->toBe(DwgOwnerKind::MODEL_SPACE)
        ->and($entity->owner->directHandle)->toBeNull()
        ->and($entity->owner->containerHandle)->toBe('2')
        ->and($entity->geometry)->toBeInstanceOf(DwgLineGeometry::class)
        ->and($entity->isComplete)->toBeTrue()
        ->and($entity->attributes)->toBeNull()
        ->and($result->drawingUnits->code)->toBe(4)
        ->and($result->complete()->keys()->all())->toBe([0])
        ->and($result->incomplete())->toBeEmpty()
        ->and($result->withColorMode(DwgColorMode::BY_LAYER))->toHaveCount(1)
        ->and($runner->commands)->toHaveCount(1)
        ->and(\scandir(config()->string('dwg-converter.temporary_directory')))->toBe(['.', '..']);
    $result->entities->forget(0);
    expect($result->complete())->toBeEmpty()->and($runner->commands)->toHaveCount(1);
    expect($reading->read()->entities)->toHaveCount(1)->and($runner->commands)->toHaveCount(2);
});

it('retains partial geometry and counts each incomplete entity once', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(['entity' => 'ARC', 'center' => [0, 0], 'radius' => -1, 'start_angle' => -7, 'end_angle' => 9, 'extrusion' => [0, 0, 0]]),
    ])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    $entity = $result->entities->sole();
    if (! $entity->geometry instanceof DwgArcGeometry) {
        throw new RuntimeException('Expected ARC geometry.');
    }
    expect($entity->geometry)->toBeInstanceOf(DwgArcGeometry::class)
        ->and($entity->geometry->center?->z)->toBeNull()
        ->and($entity->geometry->radius)->toBeNull()
        ->and($entity->geometry->startAngle)->toBe(-7.0)
        ->and($entity->issues)->toHaveCount(3)
        ->and($result->incomplete())->toHaveCount(1);
});

it('separates non-entities, non-objects, unclassifiable records and unsupported entities', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(['entity' => 'HATCH']), null, [], (object) [],
        (object) ['entity' => 'LINE', 'object' => 'LAYER'],
        nativeEntity(['entity' => '']),
    ])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    expect($result->entities)->toHaveCount(2)
        ->and($result->entities->first()?->type)->toBe('HATCH')
        ->and($result->entities->last()?->type)->toBeNull()
        ->and($result->incomplete())->toHaveCount(2);
    $diagnostics = collect($result->diagnostics)->keyBy(static fn ($item): string => $item->reason->value);
    expect($diagnostics->get('non_entity_records')?->recordCount)->toBe(2)
        ->and($diagnostics->get('non_object_records')?->recordCount)->toBe(2)
        ->and($diagnostics->get('unclassifiable_records')?->recordCount)->toBe(2);
});

it('classifies ambiguous oversized integer markers as unclassifiable records', function (): void {
    $json = <<<'JSON'
        {"HEADER":{"INSUNITS":0},"OBJECTS":[
            {"entity":9223372036854775808},
            {"entity":"9223372036854775808"},
            {"object":9223372036854775808},
            {"object":"9223372036854775808"},
            {"entity":-9223372036854775809},
            {"entity":"-9223372036854775809"},
            {"object":-9223372036854775809},
            {"object":"-9223372036854775809"},
            {"entity":"42","handle":[0,1,1]},
            {"object":"42"}
        ]}
        JSON;
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));

    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    $entity = $result->entities->sole();
    $diagnostics = collect($result->diagnostics)->keyBy(static fn ($item): string => $item->reason->value);

    expect($entity->handle)->toBe('1')
        ->and($entity->type)->toBeNull()
        ->and($diagnostics->get('unclassifiable_records')?->recordCount)->toBe(8)
        ->and($diagnostics->get('non_entity_records')?->recordCount)->toBe(1);
});

it('rejects invalid whole-query structure without returning partial data', function (string $json): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));
    expect(fn () => Dwg::entities(DwgBinary::from('AC1032 fixture'))->read())->toThrow(DwgOperationFailed::class, 'entities_invalid');
})->with(['null', '[]', '{}', '{"OBJECTS":{}}', '{"OBJECTS":null}', '{"OBJECTS":[]', '{"OBJECTS":42}']);

it('accepts empty entities and preserves drawing-unit diagnostics independently', function (string $json, int $diagnostics): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    expect($result->entities)->toBeEmpty()->and($result->diagnostics)->toHaveCount($diagnostics)
        ->and($result->complete())->toBeEmpty()->and($result->incomplete())->toBeEmpty();
})->with([
    ['{"HEADER":{"INSUNITS":0},"OBJECTS":[]}', 0],
    ['{"OBJECTS":[]}', 1],
    ['{"HEADER":{"INSUNITS":0},"OBJECTS":[{}]}', 1],
]);

it('ignores every type of unused byte-limit setting', function (mixed $limit): void {
    foreach (['max_input_bytes', 'max_output_bytes', 'max_json_output_bytes'] as $key) {
        config()->set('dwg-converter.' . $key, $limit);
    }
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([nativeEntity()])));
    expect(Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities)->toHaveCount(1);
})->with([[1], [0], [-1], [null], ['invalid'], [true], [1.5], [[]], [(object) []]]);

it('retains duplicate identities and refuses ambiguous relation targets', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(), nativeEntity(), ['object' => 'LAYER', 'handle' => [0, 1, 1], 'name' => 'other'],
    ])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    expect($result->entities)->toHaveCount(2)->and($result->incomplete())->toHaveCount(2);
    foreach ($result->entities as $entity) {
        expect($entity->handle)->toBe('3')->and($entity->layer->handle)->toBe('1')->and($entity->layer->name)->toBeNull()
            ->and(collect($entity->issues)->pluck('reason')->all())->toContain(DwgEntityIssueReason::DUPLICATE_HANDLE, DwgEntityIssueReason::AMBIGUOUS_REFERENCE);
    }
});

it('preserves unsigned handles above PHP int range', function (): void {
    $json = nativeEntityDump([nativeEntity(['handle' => [0, 8, '18446744073709551615']])]);
    $json = \str_replace('"18446744073709551615"', '18446744073709551615', $json);
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));
    expect(Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->sole()->handle)->toBe('FFFFFFFFFFFFFFFF');
});

it('preserves unknown owner and differentiates missing targets and cycles', function (bool $cycle): void {
    $record = nativeEntity(['entmode' => 0, 'ownerhandle' => [4, 1, 3, $cycle ? 3 : 99]]);
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([$record])));
    $entity = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->sole();
    expect($entity->owner->kind)->toBe(DwgOwnerKind::UNKNOWN)
        ->and($entity->owner->directHandle)->toBe($cycle ? '3' : '63')
        ->and(collect($entity->issues)->pluck('reason')->all())->toContain($cycle ? DwgEntityIssueReason::REFERENCE_CYCLE : DwgEntityIssueReason::TARGET_NOT_FOUND);
})->with([[true], [false]]);

it('reads INSERT and MINSERT target/attributes without expanding instances or overwriting tags', function (string $type): void {
    $insert = nativeEntity(['entity' => $type, 'block_header' => [5, 1, 2, 2], 'has_attribs' => 1, 'attribs' => [[4, 1, 4, 4], [4, 1, 5, 5]], 'num_owned' => 2,
        'ins_pt' => [0, 0, 0], 'scale' => [-1, 0, 2], 'rotation' => -8, 'extrusion' => [0, 0, 2], 'num_rows' => 100, 'num_cols' => 100]);
    $attribute = ['entity' => 'ATTRIB', 'handle' => [0, 1, 4], 'tag' => ' SAME ', 'text_value' => '001', 'ownerhandle' => [4, 1, 3, 3]];
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        $insert, $attribute, \array_replace($attribute, ['handle' => [0, 1, 5], 'text_value' => '']),
    ])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    $entity = $result->entities->first();
    if ($entity === null || $entity->attributes === null || \count($entity->attributes) !== 2) {
        throw new RuntimeException('Expected two attributes.');
    }
    expect($result->entities)->toHaveCount(3)->and($entity->attributes)->toHaveCount(2)
        ->and($entity->attributes[0]->tag)->toBe(' SAME ')
        ->and($entity->attributes[0]->value)->toBe('001')
        ->and($entity->attributes[1]->value)->toBe('')
        ->and($entity->block?->name)->toBe('*Model_Space');
    if ($type === 'INSERT') {
        if (! $entity->geometry instanceof DwgInsertGeometry) {
            throw new RuntimeException('Expected INSERT geometry.');
        }
        expect($entity->geometry)->toBeInstanceOf(DwgInsertGeometry::class)
            ->and($entity->geometry->scale?->y)->toBe(0.0);
    } else {
        expect($entity->geometry)->toBeNull()->and($entity->isComplete)->toBeFalse();
    }
})->with(['INSERT', 'MINSERT']);

it('preserves color modes and black without guessing absent indices', function (mixed $color, DwgColorMode $mode, ?int $rgb): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([nativeEntity(['color' => $color])])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    expect($result->entities->sole()->color->mode)->toBe($mode)
        ->and($result->entities->sole()->color->rgb)->toBe($rgb)
        ->and($result->withColorMode($mode))->toHaveCount(1);
})->with([
    [256, DwgColorMode::BY_LAYER, null], [0, DwgColorMode::BY_BLOCK, null], [7, DwgColorMode::ACI, null],
    [(object) ['rgb' => 'c2000000', 'flag' => 128], DwgColorMode::TRUE_COLOR, 0],
    [(object) ['rgb' => '000000'], DwgColorMode::UNKNOWN, null],
    [(object) ['index' => 256, 'flag' => 64], DwgColorMode::UNKNOWN, null],
    [(object) ['index' => 256, 'flag' => -256], DwgColorMode::UNKNOWN, null],
    [(object) ['index' => 256, 'flag' => null], DwgColorMode::UNKNOWN, null],
]);

it('does not start the tool for invalid sources and preserves process failures', function (): void {
    $failure = new DwgOperationFailed('process_timed_out', ['operation' => 'entities']);
    $runner = new FakeProcessRunner(static fn (): never => throw $failure);
    app()->instance(ProcessRunner::class, $runner);
    expect(fn () => Dwg::entities('relative.dwg')->read())->toThrow(InvalidDwg::class)
        ->and($runner->commands)->toBe([])
        ->and(fn () => Dwg::entities(DwgBinary::from('AC1032 fixture'))->read())->toThrow($failure);
});

it('reads valid JSON above 64 MiB without inheriting file-operation limits', function (): void {
    $process = new Process([\PHP_BINARY, '-d', 'memory_limit=512M', 'tests/Fixtures/large-entity-query.php'], \dirname(__DIR__, 2));
    $process->setTimeout(30);
    $process->mustRun();
    expect($process->getOutput())->toStartWith('large_query_ok peak=');
});

it('snapshots all entity source types and preserves caller ownership', function (string $kind): void {
    $path = \tempnam(\sys_get_temp_dir(), 'dwg-entity-source');
    if ($path === false) {
        throw new RuntimeException('Cannot create source.');
    }
    \file_put_contents($path, 'AC1032 fixture');

    try {
        $source = match ($kind) {
            'path' => $path,
            'upload' => new UploadedFile($path, 'untrusted.txt', 'text/plain', null, true),
            'binary' => DwgBinary::from('AC1032 fixture'),
            default => throw new LogicException('Unknown source.'),
        };
        $runner = FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([nativeEntity()]));
        app()->instance(ProcessRunner::class, $runner);
        Dwg::entities($source)->read();
        expect($runner->inputSnapshots)->toBe(['AC1032 fixture'])->and(\file_get_contents($path))->toBe('AC1032 fixture');
    } finally {
        \unlink($path);
    }
})->with(['path', 'upload', 'binary']);

it('preserves an ATTRIB with missing text and partial links', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(['entity' => 'INSERT', 'block_header' => [5, 1, 2, 2], 'has_attribs' => 1, 'attribs' => [[4, 1, 4, 4], [4, 1, 9, 9]], 'num_owned' => 2]),
        ['entity' => 'ATTRIB', 'handle' => [0, 1, 4], 'ownerhandle' => [4, 1, 3, 3]],
    ])));
    $entity = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->first();
    expect($entity?->attributes)->toHaveCount(1)->and($entity?->isComplete)->toBeFalse();
});

it('retains malformed identities while keeping their other usable data', function (mixed $handle): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([nativeEntity(['handle' => $handle])])));
    $entity = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->sole();
    expect($entity->handle)->toBeNull()->and($entity->isComplete)->toBeFalse()
        ->and($entity->geometry)->toBeInstanceOf(DwgLineGeometry::class);
})->with([[null], [[0, 0, 3]], [[0, 1, 256]], [[0, 9, 1]], [[0, 8, '18446744073709551616']], [[1, 1, 3]], [[0, 1, 0]]]);

it('keeps filter keys, reflects mutations and exposes immutable entity properties', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(), nativeEntity(['handle' => [0, 1, 4], 'entity' => 'HATCH']),
    ])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    $earlier = $result->complete();
    expect($result->incomplete()->keys()->all())->toBe([1]);
    $entity = $result->entities->sole(static fn ($item): bool => $item->handle === '3');
    expect(fn () => (new ReflectionProperty($entity, 'type'))->setValue($entity, 'CHANGED'))->toThrow(Error::class);
    $result->entities->forget(0);
    expect($result->complete())->toBeEmpty()->and($earlier)->toHaveCount(1)
        ->and($result->withColorMode(DwgColorMode::BY_LAYER)->keys()->all())->toBe([1]);
});

it('distinguishes layout ownership, explicit owner and insertion target', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        ['object' => 'BLOCK_HEADER', 'handle' => [0, 1, 6], 'name' => '*Paper_Space1', 'layout' => [5, 1, 7, 7]],
        ['object' => 'LAYOUT', 'handle' => [0, 1, 7], 'layout_name' => ' Sheet '],
        nativeEntity(['entmode' => 0, 'ownerhandle' => [4, 1, 6, 6]]),
    ])));
    $owner = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->sole()->owner;
    expect($owner->kind)->toBe(DwgOwnerKind::PAPER_SPACE)->and($owner->directHandle)->toBe('6')
        ->and($owner->containerHandle)->toBe('6')->and($owner->layoutHandle)->toBe('7')->and($owner->layoutName)->toBe(' Sheet ');
});

it('does not mark a block definition incomplete for an explicit null layout', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        ['object' => 'BLOCK_HEADER', 'handle' => [0, 1, 6], 'name' => '*U1', 'layout' => [5, 0, 0, 0]],
        nativeEntity(['entmode' => 0, 'ownerhandle' => [4, 1, 6, 6]]),
    ])));
    $entity = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->sole();
    expect($entity->isComplete)->toBeTrue()->and($entity->owner->kind)->toBe(DwgOwnerKind::BLOCK_DEFINITION);
});

it('reports conflicting header ownership independently of the container name', function (array $name, bool $conflict): void {
    $header = ['BLOCK_RECORD_MSPACE' => [5, 1, 6, 6], 'INSUNITS' => 4];
    if ($conflict) {
        $header['BLOCK_RECORD_PSPACE'] = [5, 1, 6, 6];
    }
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        ['object' => 'BLOCK_HEADER', 'handle' => [0, 1, 6], ...$name], nativeEntity(),
    ], $header)));
    $entity = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->sole();
    expect($entity->owner->kind)->toBe($conflict ? DwgOwnerKind::UNKNOWN : DwgOwnerKind::MODEL_SPACE)
        ->and($entity->owner->containerHandle)->toBe('6')
        ->and(collect($entity->issues)->whereStrict('field', 'owner')->pluck('reason')->all())
        ->toBe($conflict ? [DwgEntityIssueReason::INVALID_REFERENCE] : []);
})->with([[[], true], [['name' => null], true], [['name' => 42], true], [['name' => '*Model_Space'], true], [[], false]]);

it('retains known unit codes and diagnoses unknown semantics separately', function (mixed $code, ?int $expected): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([nativeEntity()], ['INSUNITS' => $code])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    expect($result->drawingUnits->code)->toBe($expected)
        ->and($result->drawingUnits->status)->toBe(DwgDrawingUnitStatus::UNKNOWN)
        ->and(collect($result->diagnostics)->pluck('reason')->all())->toContain(DwgQueryDiagnosticReason::UNKNOWN_DRAWING_UNITS);
})->with([[999, 999], ['4', null], [null, null]]);

it('still validates settings used by the entity operation', function (): void {
    config()->set('dwg-converter.timeout', 'invalid');
    expect(fn () => Dwg::entities(DwgBinary::from('AC1032 fixture'))->read())->toThrow(LibreDwgUnavailable::class, 'invalid_configuration');
});

it('recognizes the documented drawing-unit code range without converting it', function (int $code): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([], ['INSUNITS' => $code])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    expect($result->drawingUnits->code)->toBe($code)
        ->and($result->drawingUnits->status)->toBe(DwgDrawingUnitStatus::SPECIFIED);
})->with([[1], [20], [21], [24]]);

it('does not follow an unclassifiable owner record', function (mixed $marker): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(['entmode' => 0, 'ownerhandle' => [4, 1, 4, 4]]),
        ['entity' => $marker, 'handle' => [0, 1, 4], 'entmode' => 2],
    ])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    $entity = $result->entities->sole();
    expect($entity->owner->kind)->toBe(DwgOwnerKind::UNKNOWN)
        ->and($entity->owner->directHandle)->toBe('4')
        ->and($entity->isComplete)->toBeFalse()
        ->and(collect($entity->issues)->pluck('reason')->all())->toContain(DwgEntityIssueReason::WRONG_TARGET_TYPE)
        ->and(collect($result->diagnostics)->pluck('reason')->all())->toContain(DwgQueryDiagnosticReason::UNCLASSIFIABLE_RECORDS);
})->with([[null], [42], [false], [[]], ['18446744073709551615']]);

it('requires the correct record kind for object relationships', function (string $field): void {
    $record = match ($field) {
        'layer' => nativeEntity(['layer' => [5, 1, 6, 6]]),
        'block' => nativeEntity(['entity' => 'INSERT', 'block_header' => [5, 1, 6, 6], 'has_attribs' => 0]),
        default => nativeEntity(['entmode' => 0, 'ownerhandle' => [4, 1, 7, 7]]),
    };
    $targetType = match ($field) {
        'layer' => 'LAYER',
        'block' => 'BLOCK_HEADER',
        default => 'LAYOUT',
    };
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        $record,
        ['entity' => $targetType, 'handle' => [0, 1, 6], 'name' => 'wrong kind', 'layout_name' => 'wrong kind'],
        ['object' => 'BLOCK_HEADER', 'handle' => [0, 1, 7], 'name' => '*Paper_Space', 'layout' => [5, 1, 6, 6]],
    ])));
    $entity = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->first();
    $name = match ($field) {
        'layer' => $entity?->layer->name,
        'block' => $entity?->block?->name,
        default => $entity?->owner->layoutName,
    };
    expect($name)->toBeNull()
        ->and(collect($entity?->issues)->pluck('reason')->all())->toContain(DwgEntityIssueReason::WRONG_TARGET_TYPE);
})->with(['layer', 'block', 'layout']);

it('requires an entity ATTRIB rather than an object with the same name', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(['entity' => 'INSERT', 'block_header' => [5, 1, 2, 2], 'has_attribs' => 1, 'attribs' => [[4, 1, 4, 4]], 'num_owned' => 1]),
        ['object' => 'ATTRIB', 'handle' => [0, 1, 4], 'ownerhandle' => [4, 1, 3, 3], 'tag' => 'wrong kind', 'text_value' => 'wrong kind'],
    ])));
    $entity = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->sole();
    expect($entity->attributes)->toBeNull()
        ->and(collect($entity->issues)->pluck('reason')->all())->toContain(DwgEntityIssueReason::WRONG_TARGET_TYPE);
});

it('distinguishes omitted legacy scale from explicit null', function (bool $omit): void {
    $record = nativeEntity(['entity' => 'INSERT', 'block_header' => [5, 1, 2, 2], 'has_attribs' => 0, 'ins_pt' => [1, 2]]);
    if (! $omit) {
        $record['scale'] = null;
    }
    $json = \str_replace('"r2018"', '"r12"', nativeEntityDump([$record]));
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));
    $entity = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->sole();
    expect($entity->isComplete)->toBe($omit)
        ->and(collect($entity->issues)->pluck('field')->all())->toBe($omit ? [] : ['geometry.scale.x', 'geometry.scale.y', 'geometry.scale.z']);
})->with([[true], [false]]);

it('validates empty attribute vector counts for every flag', function (string $type, int|bool $flag, string $count): void {
    $record = nativeEntity(['entity' => $type, 'block_header' => [5, 1, 2, 2], 'has_attribs' => $flag,
        'attribs' => [], 'ins_pt' => [0, 0, 0], 'scale' => [1, 1, 1], 'rotation' => 0, 'extrusion' => [0, 0, 1]]);
    if ($count !== 'missing') {
        $record['num_owned'] = match ($count) {
            'null' => null,
            'string' => '0',
            default => 0,
        };
    }
    $runner = FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([$record]));
    app()->instance(ProcessRunner::class, $runner);
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    $entity = $result->entities->sole();
    $invalid = $count !== 'zero';
    expect($entity->attributes)->toBe($invalid ? null : [])
        ->and(collect($entity->issues)->whereStrict('field', 'attributes')->pluck('reason')->all())
        ->toBe($invalid ? [DwgEntityIssueReason::INCOMPLETE_ATTRIBUTES] : [])
        ->and($result->withIssue(DwgEntityIssueReason::INCOMPLETE_ATTRIBUTES)->count())->toBe($invalid ? 1 : 0)
        ->and($result->complete()->count())->toBe($invalid || $type === 'MINSERT' ? 0 : 1)
        ->and($result->incomplete()->count())->toBe($invalid || $type === 'MINSERT' ? 1 : 0)
        ->and($runner->commands)->toHaveCount(1);
})->with(['INSERT', 'MINSERT'])->with([[0], [false], [1], [true]])->with(['missing', 'null', 'string', 'zero']);

it('does not confirm no attributes when the reported count contradicts it', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(['entity' => 'INSERT', 'block_header' => [5, 1, 2, 2], 'has_attribs' => 0, 'attribs' => [], 'num_owned' => 1]),
    ])));
    $entity = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->sole();
    expect($entity->attributes)->toBeNull()
        ->and(collect($entity->issues)->pluck('reason')->all())->toContain(DwgEntityIssueReason::INCOMPLETE_ATTRIBUTES);
});

it('diagnoses attribute links contradicting a no-attributes flag', function (string $link, bool $vector, int|bool $flag): void {
    $record = nativeEntity(['entity' => 'INSERT', 'block_header' => [5, 1, 2, 2], 'has_attribs' => $flag,
        $link => [4, 1, 4, 4]]);
    if ($vector) {
        $record['attribs'] = [];
        $record['num_owned'] = 0;
    }
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([$record])));
    $entity = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->sole();
    expect($entity->attributes)->toBe($vector ? [] : null)
        ->and($entity->isComplete)->toBeFalse()
        ->and(collect($entity->issues)->whereStrict('field', 'attributes')->pluck('reason')->all())
        ->toContain(DwgEntityIssueReason::INCOMPLETE_ATTRIBUTES);
})->with([
    ['first_attrib', false, 0], ['last_attrib', false, 0], ['first_attrib', true, 0], ['last_attrib', true, 0],
    ['first_attrib', false, false], ['last_attrib', false, false], ['first_attrib', true, false], ['last_attrib', true, false],
]);

it('preserves repeated attribute references and subsequent entries in source order', function (string $type): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(['entity' => $type, 'block_header' => [5, 1, 2, 2], 'has_attribs' => 1,
            'attribs' => [[4, 1, 4, 4], [4, 1, 4, 4], [4, 1, 5, 5]], 'num_owned' => 3,
            'ins_pt' => [0, 0, 0], 'scale' => [1, 1, 1], 'rotation' => 0, 'extrusion' => [0, 0, 1]]),
        ['entity' => 'ATTRIB', 'handle' => [0, 1, 4], 'ownerhandle' => [4, 1, 3, 3], 'tag' => 'A', 'text_value' => 'first'],
        ['entity' => 'ATTRIB', 'handle' => [0, 1, 5], 'ownerhandle' => [4, 1, 3, 3], 'tag' => 'B', 'text_value' => 'last'],
    ])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    $entity = $result->entities->first();
    $issues = collect($entity?->issues)->whereStrict('field', 'attributes');
    expect($result->entities)->toHaveCount(3)
        ->and(collect($entity?->attributes)->pluck('handle')->all())->toBe(['4', '4', '5'])
        ->and(collect($entity?->attributes)->pluck('tag')->all())->toBe(['A', 'A', 'B'])
        ->and(collect($entity?->attributes)->pluck('value')->all())->toBe(['first', 'first', 'last'])
        ->and($entity?->isComplete)->toBeFalse()
        ->and($issues->pluck('reason')->all())->toBe([DwgEntityIssueReason::INCOMPLETE_ATTRIBUTES]);
})->with(['INSERT', 'MINSERT']);

it('diagnoses mixed attribute sources for every flag without following ignored targets', function (string $type, int|bool $flag, string $link): void {
    $record = nativeEntity(['entity' => $type, 'block_header' => [5, 1, 2, 2], 'has_attribs' => $flag,
        'attribs' => [], 'num_owned' => 0, 'ins_pt' => [0, 0, 0], 'scale' => [1, 1, 1], 'rotation' => 0, 'extrusion' => [0, 0, 1]]);
    foreach (['first_attrib', 'last_attrib'] as $field) {
        if ($link === $field || $link === 'both') {
            $record[$field] = [4, 1, 99, 99];
        } elseif ($link === 'null') {
            $record[$field] = [5, 0, 0, 0];
        }
    }
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([$record])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    $entity = $result->entities->sole();
    $broken = ! \in_array($link, ['missing', 'null'], true);
    expect($entity->attributes)->toBe([])
        ->and($entity->isComplete)->toBe(! $broken && $type === 'INSERT')
        ->and(collect($entity->issues)->whereStrict('field', 'attributes')->pluck('reason')->all())->toBe($broken ? [DwgEntityIssueReason::INCOMPLETE_ATTRIBUTES] : [])
        ->and($result->withIssue(DwgEntityIssueReason::TARGET_NOT_FOUND))->toBeEmpty()
        ->and($result->complete()->count())->toBe($broken || $type === 'MINSERT' ? 0 : 1)
        ->and($result->incomplete()->count())->toBe($broken || $type === 'MINSERT' ? 1 : 0)
        ->and($result->withIssue(DwgEntityIssueReason::INCOMPLETE_ATTRIBUTES)->count())->toBe($broken ? 1 : 0);
})->with(['INSERT', 'MINSERT'])->with([[0], [false], [1], [true]])->with(['first_attrib', 'last_attrib', 'both', 'missing', 'null']);

it('validates direct owner syntax independently of the resolved header container', function (int $mode, bool $legacy, string $form): void {
    $record = nativeEntity(['entmode' => $mode]);
    if ($legacy) {
        $record['start'] = [0, 0];
        $record['end'] = [1, 2];
    }
    if ($form !== 'missing') {
        $record['ownerhandle'] = match ($form) {
            'null' => [5, 0, 0, 0],
            'invalid' => 'invalid reference',
            default => $legacy ? [1, 0, 99] : [4, 1, 99, 99],
        };
    }
    $json = nativeEntityDump([
        ...($mode === 1 && $form === 'valid' ? [
            ['object' => 'BLOCK_HEADER', 'handle' => [0, 1, 99], 'name' => 'Duplicate A'],
            ['object' => 'BLOCK_HEADER', 'handle' => [0, 1, 99], 'name' => 'Duplicate B'],
        ] : []),
        ['object' => 'BLOCK_HEADER', 'handle' => [0, 1, 6], 'name' => '*Paper_Space', 'layout' => [5, 1, 7, 7]],
        ['object' => 'LAYOUT', 'handle' => [0, 1, 7], 'layout_name' => 'Sheet'], $record,
    ], ['BLOCK_RECORD_MSPACE' => [5, 1, 2, 2], 'BLOCK_RECORD_PSPACE' => [5, 1, 6, 6], 'INSUNITS' => 4]);
    if ($legacy) {
        $json = \str_replace('"r2018"', '"r12"', $json);
    }
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    $entity = $result->entities->sole();
    expect($entity->owner->kind)->toBe($mode === 1 ? DwgOwnerKind::PAPER_SPACE : DwgOwnerKind::MODEL_SPACE)
        ->and($entity->owner->containerHandle)->toBe($mode === 1 ? '6' : '2')
        ->and($entity->owner->layoutName)->toBe($mode === 1 ? 'Sheet' : null)
        ->and($entity->owner->directHandle)->toBe($form === 'valid' ? '63' : null)
        ->and(collect($entity->issues)->pluck('reason')->all())->toBe($form === 'invalid' ? [DwgEntityIssueReason::INVALID_REFERENCE] : [])
        ->and($result->complete()->count())->toBe($form === 'invalid' ? 0 : 1)
        ->and($result->incomplete()->count())->toBe($form === 'invalid' ? 1 : 0)
        ->and($result->withIssue(DwgEntityIssueReason::INVALID_REFERENCE)->count())->toBe($form === 'invalid' ? 1 : 0);
})->with([[1], [2]])->with([[false], [true]])->with(['missing', 'null', 'invalid', 'valid']);

it('requires HEADER owners to reference a block container directly', function (int $mode, string $direct): void {
    $record = nativeEntity(['entmode' => $mode]);
    if ($direct !== 'missing') {
        $record['ownerhandle'] = $direct === 'valid' ? [4, 1, 99, 99] : 'invalid reference';
    }
    $runner = FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        $record,
        nativeEntity(['handle' => [0, 1, 4], 'entmode' => 0, 'ownerhandle' => [4, 1, 2, 2]]),
    ], ['BLOCK_RECORD_MSPACE' => [5, 1, 4, 4], 'BLOCK_RECORD_PSPACE' => [5, 1, 4, 4], 'INSUNITS' => 4]));
    app()->instance(ProcessRunner::class, $runner);
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    $entity = $result->entities->first();
    expect($result->entities->pluck('handle')->all())->toBe(['3', '4'])
        ->and($entity?->owner->kind)->toBe(DwgOwnerKind::UNKNOWN)
        ->and($entity?->owner->directHandle)->toBe($direct === 'valid' ? '63' : null)
        ->and($entity?->owner->containerHandle)->toBeNull()
        ->and($entity?->owner->containerName)->toBeNull()
        ->and($entity?->owner->layoutHandle)->toBeNull()
        ->and($entity?->owner->layoutName)->toBeNull()
        ->and($entity?->geometry)->toBeInstanceOf(DwgLineGeometry::class)
        ->and(collect($entity?->issues)->pluck('reason')->all())->toBe([DwgEntityIssueReason::WRONG_TARGET_TYPE])
        ->and(collect($entity?->issues)->pluck('field')->all())->toBe(['owner'])
        ->and($result->complete()->keys()->all())->toBe([1])
        ->and($result->incomplete()->keys()->all())->toBe([0])
        ->and($result->withIssue(DwgEntityIssueReason::WRONG_TARGET_TYPE)->keys()->all())->toBe([0])
        ->and($runner->commands)->toHaveCount(1);
})->with([[1], [2]])->with(['missing', 'invalid', 'valid']);

it('distinguishes ordinary owner chains from HEADER links on intermediate entities', function (bool $usesHeader): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(['entmode' => 0, 'ownerhandle' => [4, 1, 4, 4]]),
        nativeEntity(['handle' => [0, 1, 4], 'entmode' => $usesHeader ? 2 : 0, 'ownerhandle' => [4, 1, 2, 2]]),
        nativeEntity(['handle' => [0, 1, 5], 'entmode' => 0, 'ownerhandle' => [4, 1, 2, 2]]),
    ], ['BLOCK_RECORD_MSPACE' => [5, 1, 5, 5], 'INSUNITS' => 4])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    $entity = $result->entities->first();
    expect($entity?->owner->directHandle)->toBe('4')
        ->and($entity?->owner->kind)->toBe($usesHeader ? DwgOwnerKind::UNKNOWN : DwgOwnerKind::MODEL_SPACE)
        ->and($entity?->owner->containerHandle)->toBe($usesHeader ? null : '2')
        ->and($entity?->isComplete)->toBe(! $usesHeader)
        ->and(collect($entity?->issues)->pluck('reason')->all())->toBe($usesHeader ? [DwgEntityIssueReason::WRONG_TARGET_TYPE] : [])
        ->and($result->entities)->toHaveCount(3);
})->with([[false], [true]]);

it('retains attributes but diagnoses an ambiguous parent identity', function (string $duplicateKind, string $type): void {
    $insert = nativeEntity(['entity' => $type, 'block_header' => [5, 1, 2, 2], 'has_attribs' => 1,
        'attribs' => [[4, 1, 4, 4]], 'num_owned' => 1,
        'ins_pt' => [0, 0, 0], 'scale' => [1, 1, 1], 'rotation' => 0, 'extrusion' => [0, 0, 1]]);
    $duplicate = $duplicateKind === 'entity' ? $insert : ['object' => 'LAYER', 'handle' => [0, 1, 3], 'name' => 'Duplicate'];
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        $insert, $duplicate,
        nativeEntity(['entity' => 'ATTRIB', 'handle' => [0, 1, 4], 'entmode' => 0,
            'ownerhandle' => [4, 1, 3, 3], 'tag' => 'TAG', 'text_value' => '001']),
    ])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read();
    foreach ($result->ofType($type) as $entity) {
        expect(collect($entity->attributes)->pluck('value')->all())->toBe(['001'])
            ->and(collect($entity->issues)->whereStrict('field', 'attributes')->pluck('reason')->all())
            ->toBe([DwgEntityIssueReason::INCOMPLETE_ATTRIBUTES])
            ->and(collect($entity->issues)->pluck('reason')->all())->toContain(DwgEntityIssueReason::DUPLICATE_HANDLE);
    }
    expect($result->withIssue(DwgEntityIssueReason::INCOMPLETE_ATTRIBUTES)->count())->toBe($duplicateKind === 'entity' ? 2 : 1)
        ->and($result->ofType('ATTRIB')->sole()->owner->kind)->toBe(DwgOwnerKind::UNKNOWN)
        ->and($result->ofType('ATTRIB')->withIssue(DwgEntityIssueReason::AMBIGUOUS_REFERENCE))->toHaveCount(1);
})->with(['entity', 'object'])->with(['INSERT', 'MINSERT']);

it('stops actual linked attribute cycles while retaining the available prefix', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(['entity' => 'INSERT', 'block_header' => [5, 1, 2, 2], 'has_attribs' => 1,
            'first_attrib' => [4, 1, 4, 4], 'last_attrib' => [4, 1, 5, 5]]),
        ['entity' => 'ATTRIB', 'handle' => [0, 1, 4], 'ownerhandle' => [4, 1, 3, 3],
            'next_entity' => [4, 1, 4, 4], 'tag' => 'A', 'text_value' => 'first'],
        ['entity' => 'ATTRIB', 'handle' => [0, 1, 5], 'ownerhandle' => [4, 1, 3, 3], 'tag' => 'B', 'text_value' => 'last'],
    ])));
    $entity = Dwg::entities(DwgBinary::from('AC1032 fixture'))->read()->entities->first();
    expect(collect($entity?->attributes)->pluck('handle')->all())->toBe(['4'])
        ->and(collect($entity?->issues)->whereStrict('field', 'attributes')->pluck('reason')->all())
        ->toBe([DwgEntityIssueReason::REFERENCE_CYCLE]);
});

it('selects layers before materializing entities while retaining source diagnostics', function (array $arguments, array $handles): void {
    $runner = FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        nativeEntity(),
        ['object' => 'LAYER', 'handle' => [0, 1, 6], 'name' => 'Other'],
        nativeEntity(['handle' => [0, 1, 4], 'layer' => [5, 1, 6, 6], 'entity' => 'HATCH']),
        nativeEntity(['handle' => [0, 1, 5], 'layer' => null]), null, (object) [],
    ]));
    app()->instance(ProcessRunner::class, $runner);
    $reading = Dwg::entities(DwgBinary::from('AC1032 fixture'));
    $first = $arguments[0] ?? [];
    if (! \is_string($first) && ! \is_array($first)) {
        throw new RuntimeException('Invalid test input.');
    }
    $rest = [];
    foreach (\array_slice($arguments, 1) as $argument) {
        if (! \is_string($argument)) {
            throw new RuntimeException('Invalid additional layer.');
        }
        $rest[] = $argument;
    }
    $selected = $reading->onlyLayers($first, ...$rest);
    expect($runner->commands)->toBe([]);
    $full = $reading->read();
    $result = $selected->read();
    $names = [];
    foreach ($arguments as $argument) {
        $names = [...$names, ...(\is_array($argument) ? $argument : [$argument])];
    }
    $expected = $full->entities->filter(static fn ($entity): bool => \in_array($entity->layer->name, $names, true))->values();
    expect($result->entities->pluck('handle')->all())->toBe($handles)
        ->and($result->entities->all())->toEqual($expected->all())
        ->and($result->diagnostics)->toEqual($full->diagnostics)
        ->and($result->drawingUnits)->toEqual($full->drawingUnits)
        ->and($result->entities->keys()->all())->toBe($handles === [] ? [] : \range(0, \count($handles) - 1))
        ->and($result->complete()->count() + $result->incomplete()->count())->toBe(\count($handles))
        ->and($runner->commands)->toHaveCount(2);
    expect($selected->onlyLayers('Other')->read()->entities->pluck('handle')->all())->toBe(['4'])
        ->and($selected->read()->entities->pluck('handle')->all())->toBe($handles)
        ->and($reading->read()->entities)->toHaveCount(3);
})->with([
    [[' 圖層 '], ['3']], [[' 圖層 ', 'Other'], ['3', '4']],
    [[[' 圖層 ', 'Other']], ['3', '4']], [[[' 圖層 '], 'Other'], ['3', '4']],
    [['Other', 'Other'], ['4']], [[[]], []], [['圖層'], []], [['other'], []], [[''], []], [['*'], []],
]);

it('rejects invalid layer lists before I/O', function (array $layers): void {
    $runner = FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([]));
    app()->instance(ProcessRunner::class, $runner);
    expect(fn () => Dwg::entities(DwgBinary::from('invalid'))->onlyLayers($layers))->toThrow(InvalidArgumentException::class)
        ->and($runner->commands)->toBe([]);
})->with([[[1]], [[null]], [[['nested']]], [['name' => 'Other']], [[1 => 'Other']]]);

it('retains cross-layer attributes and owner chains without adding their entities', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        ['object' => 'LAYER', 'handle' => [0, 1, 6], 'name' => 'Other'],
        nativeEntity(['entity' => 'INSERT', 'entmode' => 0, 'ownerhandle' => [4, 1, 5, 5],
            'block_header' => [5, 1, 2, 2], 'has_attribs' => 1, 'num_owned' => 1, 'attribs' => [[4, 1, 4, 4]],
            'ins_pt' => [0, 0, 0], 'scale' => [1, 1, 1], 'rotation' => 0, 'extrusion' => [0, 0, 1]]),
        nativeEntity(['entity' => 'ATTRIB', 'handle' => [0, 1, 4], 'layer' => [5, 1, 6, 6],
            'ownerhandle' => [4, 1, 3, 3], 'tag' => 'TAG', 'text_value' => '001']),
        nativeEntity(['handle' => [0, 1, 5], 'layer' => [5, 1, 6, 6]]),
    ])));
    $entity = Dwg::entities(DwgBinary::from('AC1032 fixture'))->onlyLayers(' 圖層 ')->read()->entities->sole();
    expect(collect($entity->attributes)->pluck('value')->all())->toBe(['001'])->and($entity->owner->directHandle)->toBe('5')
        ->and($entity->owner->containerHandle)->toBe('2')->and($entity->isComplete)->toBeTrue();
});

it('retains excluded duplicate identities and selects distinct layers with equal names', function (): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([
        ['object' => 'LAYER', 'handle' => [0, 1, 6], 'name' => ' 圖層 '],
        nativeEntity(), nativeEntity(['layer' => null]),
        nativeEntity(['handle' => [0, 1, 4], 'layer' => [5, 1, 6, 6]]),
    ])));
    $result = Dwg::entities(DwgBinary::from('AC1032 fixture'))->onlyLayers(' 圖層 ')->read();
    expect($result->entities->pluck('handle')->all())->toBe(['3', '4'])
        ->and($result->withIssue(DwgEntityIssueReason::DUPLICATE_HANDLE))->toHaveCount(1);
});

it('validates the whole query even with an empty layer selection', function (string $json): void {
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', $json));
    expect(fn () => Dwg::entities(DwgBinary::from('AC1032 fixture'))->onlyLayers([])->read())->toThrow(DwgOperationFailed::class);
})->with(['{}', '{"OBJECTS":{}}', 'broken']);

it('does not select unresolved layer targets', function (string $fault): void {
    $target = ['object' => 'LAYER', 'handle' => [0, 1, 6], 'name' => 'Other'];
    $records = [nativeEntity(['layer' => [5, 1, 6, 6]])];
    if ($fault !== 'missing') {
        if ($fault === 'wrong') {
            $target['object'] = 'BLOCK_HEADER';
        }
        if ($fault === 'name') {
            $target['name'] = null;
        }
        $records[] = $target;
        if ($fault === 'ambiguous') {
            $records[] = $target;
        }
    }
    app()->instance(ProcessRunner::class, FakeProcessRunner::writesFile('drawing.json', nativeEntityDump($records)));
    expect(Dwg::entities(DwgBinary::from('AC1032 fixture'))->onlyLayers('Other')->read()->entities)->toBeEmpty();
})->with(['missing', 'wrong', 'name', 'ambiguous']);

it('still validates sources and process failures with an empty layer selection', function (): void {
    $runner = FakeProcessRunner::writesFile('drawing.json', nativeEntityDump([]));
    app()->instance(ProcessRunner::class, $runner);
    expect(fn () => Dwg::entities(DwgBinary::from('invalid'))->onlyLayers([])->read())->toThrow(InvalidDwg::class)
        ->and($runner->commands)->toBe([]);
    app()->instance(ProcessRunner::class, new FakeProcessRunner(static function (): never {
        throw new DwgOperationFailed('process_timed_out', ['operation' => 'entities']);
    }));
    app()->forgetInstance(DwgManager::class);
    app()->forgetInstance(Converter::class);
    Dwg::clearResolvedInstances();
    expect(fn () => Dwg::entities(DwgBinary::from('AC1032 fixture'))->onlyLayers([])->read())->toThrow(DwgOperationFailed::class, 'process_timed_out');
});
