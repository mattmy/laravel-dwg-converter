<?php

declare(strict_types=1);

return [
    'executables' => [
        'dwgbmp' => env('LIBREDWG_DWGBMP', 'dwgbmp'),
        'dwg2dxf' => env('LIBREDWG_DWG2DXF', 'dwg2dxf'),
        'dwgread' => env('LIBREDWG_DWGREAD', 'dwgread'),
        'libreoffice' => env('DWG_CONVERTER_LIBREOFFICE', 'soffice'),
        'imagemagick' => env('DWG_CONVERTER_IMAGEMAGICK', 'magick'),
    ],
    'timeout' => 60,
    'max_input_bytes' => 200 * 1024 * 1024,
    'max_output_bytes' => 512 * 1024 * 1024,
    'max_json_output_bytes' => 64 * 1024 * 1024,
    'temporary_directory' => storage_path('framework/laravel-dwg-converter'),
];
