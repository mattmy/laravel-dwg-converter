<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Defines the published DwgCoordinateSystem values. */
enum DwgCoordinateSystem: string
{
    case WCS = 'wcs';
    case OCS = 'ocs';
    case UNKNOWN = 'unknown';
}
