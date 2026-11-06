<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Defines the published DwgColorMode values. */
enum DwgColorMode: string
{
    case ACI = 'aci';
    case TRUE_COLOR = 'true_color';
    case BY_LAYER = 'by_layer';
    case BY_BLOCK = 'by_block';
    case UNKNOWN = 'unknown';
}
