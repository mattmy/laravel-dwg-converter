<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\Internal\ProcessRunner;
use Mattmy\DwgConverter\Internal\Workspace;

use function Pest\Laravel\mock;

it('binds the source before thumbnail extraction', function (): void {
    expect(fn () => Dwg::thumbnail('relative.dwg')->extract())
        ->toThrow(InvalidDwg::class, 'input_not_absolute');
});

it('extracts a thumbnail through the public interface', function (): void {
    config()->set('dwg-converter.temporary_directory', storage_path('framework/dwg-converter-tests'));

    $runner = mock(ProcessRunner::class);
    $runner->shouldReceive('assertAvailable')->andReturnNull();
    $runner->shouldReceive('run')->andReturnUsing(function (array $_command, Workspace $workspace): void {
        $written = \file_put_contents(
            $workspace->outputPath('input.png'),
            "\x89PNG\r\n\x1a\n" . \str_repeat("\0", 12) . "\0\0\0\0IEND\xaeB`\x82",
        );

        if ($written === false) {
            throw new RuntimeException('Unable to write the thumbnail fixture.');
        }
    });
    app()->instance(ProcessRunner::class, $runner);

    $thumbnail = Dwg::thumbnail(DwgBinary::from('AC1032 fixture'))->extract();

    expect($thumbnail->extension())->toBe('png')
        ->and($thumbnail->mimeType())->toBe('image/png')
        ->and($thumbnail->output())->toStartWith("\x89PNG\r\n\x1a\n");
});
