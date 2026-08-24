<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

it('returns validated SVG stdout as a one-time artifact', function (): void {
    $contents = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>';
    $runner = FakeProcessRunner::writesStdout($contents);
    app()->instance(ProcessRunner::class, $runner);

    $svg = Dwg::toSvg(DwgBinary::from('AC1032 drawing'))->convert();

    expect($svg->extension())->toBe('svg')
        ->and($svg->mimeType())->toBe('image/svg+xml')
        ->and($svg->output())->toBe($contents);
});

it('rejects unsafe or malformed SVG output', function (string $contents): void {
    $runner = FakeProcessRunner::writesStdout($contents);
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::toSvg(DwgBinary::from('AC1032 drawing'))->convert())
        ->toThrow(DwgOperationFailed::class, 'svg_invalid');
})->with([
    'malformed XML' => '<svg>',
    'wrong root' => '<html></html>',
    'document type' => '<!DOCTYPE svg><svg></svg>',
    'external entity' => '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg>&xxe;</svg>',
    'late document type' => \str_repeat(' ', 70000) . '<!DOCTYPE svg><svg></svg>',
]);

it('rejects SVG stdout larger than the configured output limit', function (): void {
    config()->set('dwg-converter.max_output_bytes', 16);
    $runner = FakeProcessRunner::writesStdout('<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Dwg::toSvg(DwgBinary::from('AC1032 drawing'))->convert())
        ->toThrow(DwgOperationFailed::class, 'output_too_large');
});
