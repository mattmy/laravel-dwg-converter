<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/**
 * Lists the supported LibreOffice export canvases for PNG previews.
 */
enum PngResolution
{
    case HIGH;
    case MEDIUM;
    case LOW;
}
