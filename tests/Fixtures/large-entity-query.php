<?php

declare(strict_types=1);

use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DwgManager;
use Mattmy\DwgConverter\Internal\Converter;
use Mattmy\DwgConverter\Internal\Workspace;
use Mattmy\DwgConverter\Tests\Fakes\FakeProcessRunner;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = \sys_get_temp_dir() . '/dwg-large-query-' . \bin2hex(\random_bytes(8));
$runner = new FakeProcessRunner(static function (array $_command, Workspace $workspace): void {
    $stream = \fopen($workspace->outputPath('drawing.json'), 'xb');
    if ($stream === false) {
        throw new RuntimeException('Cannot create synthetic output.');
    }

    try {
        $header = '{"HEADER":{"INSUNITS":0},"OBJECTS":[],"padding":"';
        if (\fwrite($stream, $header) !== \strlen($header)) {
            throw new RuntimeException('Cannot write synthetic header.');
        }
        $chunk = \str_repeat('x', 1024 * 1024);
        for ($i = 0; $i < 65; $i++) {
            if (\fwrite($stream, $chunk) !== \strlen($chunk)) {
                throw new RuntimeException('Cannot write synthetic padding.');
            }
        }
        if (\fwrite($stream, '"}') !== 2) {
            throw new RuntimeException('Cannot finish synthetic JSON.');
        }
    } finally {
        \fclose($stream);
    }
});

try {
    $converter = new Converter($runner, [
        'executables' => ['dwgread' => 'dwgread'], 'timeout' => 60, 'temporary_directory' => $root,
        'max_input_bytes' => 1, 'max_output_bytes' => 'invalid', 'max_json_output_bytes' => 1,
    ]);
    $result = (new DwgManager($converter))->entities(DwgBinary::from('AC1032 fixture'))->read();
    if (! $result->entities->isEmpty() || \scandir($root) !== ['.', '..']) {
        throw new RuntimeException('Large-query contract failed.');
    }
    echo 'large_query_ok peak=' . \memory_get_peak_usage(true);
} finally {
    if (\is_dir($root)) {
        \rmdir($root);
    }
}
