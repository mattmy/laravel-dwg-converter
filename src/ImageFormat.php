<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/**
 * Lists the supported final raster image encodings.
 */
enum ImageFormat: string
{
    case PNG = 'png';
    case JPEG = 'jpg';
    case WEBP = 'webp';

    /**
     * Return the trusted MIME type for this format.
     */
    public function mimeType(): string
    {
        return match ($this) {
            self::PNG => 'image/png',
            self::JPEG => 'image/jpeg',
            self::WEBP => 'image/webp',
        };
    }
}
