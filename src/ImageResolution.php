<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/**
 * Lists the supported LibreOffice export canvases for image previews.
 */
enum ImageResolution
{
    case HIGH;
    case MEDIUM;
    case LOW;
}
