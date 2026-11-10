<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Internal;

use ErrorException;
use Mattmy\DwgConverter\DwgFileVersion;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;

/** Reads and interprets the six-byte DWG format code. */
final class DwgHeader
{
    public const LENGTH = 6;

    /**
     * Read only the format code from a validated local file path.
     *
     * @throws InvalidDwg
     */
    public static function read(string $path, string $operation): DwgFileVersion
    {
        try {
            $stream = \fopen($path, 'rb');
            if ($stream === false) {
                throw new InvalidDwg('input_not_readable', ['operation' => $operation]);
            }

            try {
                // Cap the stream's read-ahead as well as the returned string.
                \stream_set_chunk_size($stream, self::LENGTH);
                $header = \fread($stream, self::LENGTH);
            } finally {
                \fclose($stream);
            }
        } catch (ErrorException $exception) {
            throw new InvalidDwg('input_not_readable', ['operation' => $operation], $exception);
        }
        if ($header === false) {
            throw new InvalidDwg('input_not_readable', ['operation' => $operation]);
        }

        return self::version($header, $operation);
    }

    /**
     * Interpret a candidate header without attempting to decode the DWG.
     *
     * @throws InvalidDwg
     */
    public static function version(string $header, string $operation): DwgFileVersion
    {
        if (! self::isCandidate($header)) {
            throw new InvalidDwg('invalid_dwg_header', ['operation' => $operation]);
        }

        return new DwgFileVersion($header, match ($header) {
            'AC1002' => 'R2.5',
            'AC1003' => 'R2.6',
            'AC1004' => 'R9',
            'AC1006' => 'R10',
            'AC1009' => 'R11/R12',
            'AC1012' => 'R13',
            'AC1014' => 'R14',
            'AC1015' => 'R2000',
            'AC1018' => 'R2004',
            'AC1021' => 'R2007',
            'AC1024' => 'R2010',
            'AC1027' => 'R2013',
            'AC1032' => 'R2018',
            default => null,
        });
    }

    /** Check the format signature used by both direct reads and snapshot validation. */
    public static function isCandidate(string $header): bool
    {
        return \preg_match('/^AC10[0-9]{2}$/D', $header) === 1;
    }
}
