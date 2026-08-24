<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DxfVersion;

it('keeps explicit binary bytes distinct from paths', function (): void {
    $binary = DwgBinary::from('AC1032 bytes');

    expect($binary->contents())->toBe('AC1032 bytes')
        ->and(DwgBinary::from('')->contents())->toBe('');
});

it('exposes only the approved DXF versions', function (): void {
    expect(DxfVersion::cases())->toHaveCount(8)
        ->and(DxfVersion::R2018->value)->toBe('r2018');
});
