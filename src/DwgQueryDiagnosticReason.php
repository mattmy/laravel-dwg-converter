<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Defines the published DwgQueryDiagnosticReason values. */
enum DwgQueryDiagnosticReason: string
{
    case NON_ENTITY_RECORDS = 'non_entity_records';
    case NON_OBJECT_RECORDS = 'non_object_records';
    case UNCLASSIFIABLE_RECORDS = 'unclassifiable_records';
    case UNKNOWN_DRAWING_UNITS = 'unknown_drawing_units';
}
