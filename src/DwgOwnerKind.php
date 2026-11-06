<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Defines the published DwgOwnerKind values. */
enum DwgOwnerKind: string
{
    case MODEL_SPACE = 'model_space';
    case PAPER_SPACE = 'paper_space';
    case BLOCK_DEFINITION = 'block_definition';
    case UNKNOWN = 'unknown';
}
