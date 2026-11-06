<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Defines the published DwgEntityIssueReason values. */
enum DwgEntityIssueReason: string
{
    case MISSING_VALUE = 'missing_value';
    case INVALID_VALUE = 'invalid_value';
    case DUPLICATE_HANDLE = 'duplicate_handle';
    case MISSING_REFERENCE = 'missing_reference';
    case INVALID_REFERENCE = 'invalid_reference';
    case TARGET_NOT_FOUND = 'target_not_found';
    case AMBIGUOUS_REFERENCE = 'ambiguous_reference';
    case WRONG_TARGET_TYPE = 'wrong_target_type';
    case REFERENCE_CYCLE = 'reference_cycle';
    case UNSUPPORTED_GEOMETRY = 'unsupported_geometry';
    case UNKNOWN_COLOR = 'unknown_color';
    case INCOMPLETE_ATTRIBUTES = 'incomplete_attributes';
}
