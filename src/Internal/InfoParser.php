<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Internal;

use DateTimeImmutable;
use JsonException;
use Mattmy\DwgConverter\DwgCustomProperty;
use Mattmy\DwgConverter\DwgDrawingUnits;
use Mattmy\DwgConverter\DwgDrawingUnitStatus;
use Mattmy\DwgConverter\DwgFileVersion;
use Mattmy\DwgConverter\DwgInfo;
use Mattmy\DwgConverter\DwgInfoTimestamps;
use Mattmy\DwgConverter\DwgSummaryInfo;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use stdClass;

/** Maps the drawing-wide fields available in one LibreDWG JSON artifact. */
final class InfoParser
{
    private const UNIX_EPOCH_DAY = 2440588;

    private const FIRST_RFC3339_DAY = 1721060;

    private const LAST_RFC3339_DAY = 5373484;

    private const MILLISECONDS_PER_DAY = 86400000;

    /**
     * Decode the artifact once and keep independently usable metadata.
     *
     * @throws DwgOperationFailed
     */
    public static function parse(string $contents, DwgFileVersion $version): DwgInfo
    {
        try {
            $data = \json_decode($contents, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DwgOperationFailed('info_invalid', ['operation' => 'info'], $exception);
        }
        unset($contents);

        if (! $data instanceof stdClass) {
            throw new DwgOperationFailed('info_invalid', ['operation' => 'info']);
        }

        $header = ($data->HEADER ?? null) instanceof stdClass ? $data->HEADER : null;
        $summary = ($data->SummaryInfo ?? null) instanceof stdClass ? self::summary($data->SummaryInfo) : null;
        $createdAt = self::timestamp($header->TDUCREATE ?? null);
        $lastSavedAt = self::timestamp($header->TDUUPDATE ?? null);

        return new DwgInfo(
            $version,
            self::drawingUnits($header),
            $summary,
            $createdAt === null && $lastSavedAt === null ? null : new DwgInfoTimestamps($createdAt, $lastSavedAt),
        );
    }

    /** Interpret the insertion unit without guessing a unit for an absent or unknown code. */
    public static function drawingUnits(?stdClass $header): DwgDrawingUnits
    {
        $code = $header->INSUNITS ?? null;
        $code = \is_int($code) ? $code : null;

        return new DwgDrawingUnits($code, match (true) {
            $code === 0 => DwgDrawingUnitStatus::UNSPECIFIED,
            $code !== null && $code >= 1 && $code <= 24 => DwgDrawingUnitStatus::SPECIFIED,
            default => DwgDrawingUnitStatus::UNKNOWN,
        });
    }

    /** Read summary fields directly from the writer's summary section. */
    private static function summary(stdClass $summary): DwgSummaryInfo
    {
        return new DwgSummaryInfo(
            title: self::text($summary->TITLE ?? null),
            subject: self::text($summary->SUBJECT ?? null),
            author: self::text($summary->AUTHOR ?? null),
            keywords: self::text($summary->KEYWORDS ?? null),
            comments: self::text($summary->COMMENTS ?? null),
            hyperlinkBase: self::text($summary->HYPERLINKBASE ?? null),
            lastSavedBy: self::text($summary->LASTSAVEDBY ?? null),
            revisionNumber: self::text($summary->REVISIONNUMBER ?? null),
            customProperties: self::customProperties($summary->props ?? null),
        );
    }

    /**
     * Map the emitted list in order, retaining duplicates and partial property text.
     *
     * @return list<DwgCustomProperty>|null
     */
    private static function customProperties(mixed $props): ?array
    {
        if (! \is_array($props)) {
            return null;
        }

        $properties = [];
        foreach ($props as $property) {
            if (! $property instanceof stdClass) {
                return null;
            }
            $properties[] = new DwgCustomProperty(
                self::text($property->tag ?? null),
                self::text($property->value ?? null),
            );
        }

        return $properties;
    }

    /** Treat the writer's ambiguous empty text as unknown and keep all other text verbatim. */
    private static function text(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /** Convert the native civil Julian day and milliseconds since UTC midnight. */
    private static function timestamp(mixed $value): ?string
    {
        if (! \is_array($value) || \count($value) !== 2 || ! \is_int($value[0]) || ! \is_int($value[1])) {
            return null;
        }
        [$day, $milliseconds] = $value;
        if ($day < self::FIRST_RFC3339_DAY || $day > self::LAST_RFC3339_DAY
            || $milliseconds < 0 || $milliseconds >= self::MILLISECONDS_PER_DAY) {
            return null;
        }

        $fraction = $milliseconds % 1000;
        $time = (new DateTimeImmutable('1970-01-01T00:00:00Z'))
            ->setDate(1970, 1, 1 + $day - self::UNIX_EPOCH_DAY)
            ->setTime(0, 0, \intdiv($milliseconds, 1000), $fraction * 1000);

        return $time->format($fraction === 0 ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s.v\Z');
    }
}
