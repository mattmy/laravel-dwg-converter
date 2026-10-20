<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Tests\Fakes;

/** Supplies small, structurally valid embedded preview fixtures. */
final class ThumbnailFixture
{
    /** Build an uncompressed one-pixel Windows bitmap, including row padding. */
    public static function bmp(bool $topDown = false): string
    {
        return 'BM' . \pack('VvvVV', 58, 0, 0, 54, 40)
            . \pack('VVvvVVVVVV', 1, $topDown ? 0xFFFFFFFF : 1, 1, 24, 0, 4, 0, 0, 0, 0)
            . "\0\0\0\0";
    }

    /** Build a one-pixel RGB PNG with valid chunk checksums and compressed data. */
    public static function png(): string
    {
        $chunk = static fn (string $type, string $data): string => \pack('N', \strlen($data))
            . $type . $data . \pack('N', \crc32($type . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', \pack('N2', 1, 1) . "\x08\x02\0\0\0")
            . $chunk('IDAT', "\x78\x9c\x63\x60\x60\x60\x00\x00\x00\x04\x00\x01")
            . $chunk('IEND', '');
    }

    /** Build a standard WMF containing EOF, optionally with a placeable header. */
    public static function wmf(bool $placeable = false): string
    {
        $standard = \pack('vvvVvVv', 1, 9, 0x0300, 12, 0, 3, 0) . \pack('Vv', 3, 0);
        if (! $placeable) {
            return $standard;
        }

        $words = [0xCDD7, 0x9AC6, 0, 0, 0, 1, 1, 1440, 0, 0];
        $checksum = 0;
        foreach ($words as $word) {
            $checksum ^= $word;
        }

        return \pack('v*', ...[...$words, $checksum]) . $standard;
    }
}
