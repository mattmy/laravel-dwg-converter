<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/**
 * Lists the stable DXF target versions exposed by LibreDWG.
 */
enum DxfVersion: string
{
    case R12 = 'r12';
    case R14 = 'r14';
    case R2000 = 'r2000';
    case R2004 = 'r2004';
    case R2007 = 'r2007';
    case R2010 = 'r2010';
    case R2013 = 'r2013';
    case R2018 = 'r2018';
}
