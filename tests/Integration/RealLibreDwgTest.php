<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Facades\Dwg;

$repository = \dirname(__DIR__, 4);
$source = $repository . '/public/dwg/2G.dwg';
$executables = [
    'dwgbmp' => $repository . '/libredwg/dwgbmp.exe',
    'dwg2dxf' => $repository . '/libredwg/dwg2dxf.exe',
    'dwg2svg' => $repository . '/libredwg/dwg2SVG.exe',
];
$missingIntegrationDependency = PHP_OS_FAMILY !== 'Windows' || ! \is_file($source);
foreach ($executables as $executable) {
    $missingIntegrationDependency = $missingIntegrationDependency || ! \is_file($executable);
}

beforeEach(function () use ($executables): void {
    config()->set('dwg-converter.executables', $executables);
});

it('extracts a real embedded thumbnail', function () use ($source): void {
    $thumbnail = Dwg::thumbnail($source)->extract();
    $contents = $thumbnail->output();

    expect($thumbnail->extension())->toBe('bmp')
        ->and($thumbnail->mimeType())->toBe('image/bmp')
        ->and($contents)->toStartWith('BM');
})->skip($missingIntegrationDependency, 'Repository Windows fixtures are unavailable.');

it('converts a real DWG to the selected DXF version', function () use ($source): void {
    $contents = Dwg::toDxf($source)
        ->toVersion(DxfVersion::R2018)
        ->convert()
        ->output();

    expect($contents)->toContain('SECTION')
        ->and($contents)->toEndWith("0\r\nEOF\r\n");
})->skip($missingIntegrationDependency, 'Repository Windows fixtures are unavailable.');

it('converts a real DWG to parseable SVG', function () use ($source): void {
    $contents = Dwg::toSvg($source)->convert()->output();

    expect($contents)->toContain('<svg')
        ->and($contents)->toContain('</svg>');
})->skip($missingIntegrationDependency, 'Repository Windows fixtures are unavailable.');
