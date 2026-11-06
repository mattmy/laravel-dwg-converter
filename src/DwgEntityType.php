<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Provides common type names without restricting the original entity types. */
enum DwgEntityType: string
{
    case LINE = 'LINE';
    case CIRCLE = 'CIRCLE';
    case ARC = 'ARC';
    case INSERT = 'INSERT';
    case MINSERT = 'MINSERT';
    case HATCH = 'HATCH';
    case SPLINE = 'SPLINE';
    case LWPOLYLINE = 'LWPOLYLINE';
}
