# Laravel DWG Converter

[English](README.md) | [繁體中文](README.zh-TW.md)

Laravel DWG Converter gives Laravel applications a small, typed API for extracting an embedded DWG
thumbnail or exporting a DWG as DXF, structural JSON, PNG, JPEG, or WebP. Each operation uses only the
external command-line tools it needs.

## Features

- Extract an embedded BMP, PNG, or WMF preview without pretending to render the drawing.
- Export ASCII DXF with an optional target DXF version.
- Export LibreDWG structural JSON for inspection or downstream processing.
- Create whole-model-space PNG, JPEG, or WebP previews at three preset resolutions.
- Accept an uploaded file, a local absolute path, or explicitly wrapped DWG bytes.
- Return a one-time output that can be streamed directly to Laravel Storage.

## Requirements

| Requirement | Supported |
|---|---|
| PHP | 8.3 or later |
| Laravel | 12 or 13 |
| CI-tested combinations | PHP 8.3–8.5 with Laravel 12–13, including the PHP 8.3 / Laravel 12 lowest boundary |

External commands are feature-specific. You do not need LibreOffice or ImageMagick when you only extract
thumbnails, create DXF, or export JSON.

## External commands by operation

| Operation | Required commands |
|---|---|
| `Dwg::thumbnail(...)->extract()` | LibreDWG `dwgbmp` |
| `Dwg::toDxf(...)->convert()` | LibreDWG `dwg2dxf` |
| `Dwg::toJson(...)->convert()` | LibreDWG `dwgread` with JSON output support |
| `Dwg::toImage(...)->convert()` | LibreDWG `dwg2dxf`, LibreOffice 7.4+ `soffice`, ImageMagick 7 `magick` |

The package does not download or bundle these tools. For LibreDWG installation, see the
[official LibreDWG repository](https://github.com/libredwg/libredwg). The
[external tools guide](https://mattmy.github.io/laravel-dwg-converter-doc/guide/external-tools) covers
LibreOffice and ImageMagick installation on Ubuntu/Debian, RHEL/Fedora, and Windows.

## Installation

Install the package with Composer:

```bash
composer require mattmy/laravel-dwg-converter
```

If a required command is not on `PATH`, publish the configuration and provide its absolute path:

```bash
php artisan vendor:publish --tag=dwg-converter-config
```

## Quick start

This example extracts the DWG's embedded preview and streams it to Laravel's default disk. The source is
an `UploadedFile` from a validated request.

```php
use Mattmy\DwgConverter\Facades\Dwg;

$thumbnail = Dwg::thumbnail($request->file('drawing'))->extract();

$path = $thumbnail->storeAs(
    path: 'drawing-thumbnails',
    name: 'floor-plan.'.$thumbnail->extension(),
);
```

A DWG may not contain an embedded thumbnail. Use image conversion when you need a rendered preview:

```php
use Mattmy\DwgConverter\ImageFormat;
use Mattmy\DwgConverter\ImageResolution;

$image = Dwg::toImage(storage_path('app/private/floor-plan.dwg'))
    ->format(ImageFormat::WEBP)
    ->atResolution(ImageResolution::MEDIUM)
    ->convert();

$path = $image->storeAs('drawing-previews', 'floor-plan.webp', 's3');
```

## Inputs and outputs

Every operation accepts a valid `UploadedFile`, a local absolute path, or bytes explicitly wrapped with
`DwgBinary::from($bytes)`. A plain string is always treated as a path.

Each successful operation returns a one-time `DwgOutput`:

- `extension()` and `mimeType()` inspect trusted output metadata without consuming it.
- `storeAs()` streams the artifact to Laravel Storage and cleans up temporary resources.
- `output()` returns all bytes as a string and should only be used for outputs that safely fit in PHP memory.

## Errors and operational limits

Failures use `LibreDwgUnavailable`, `InvalidDwg`, or `DwgOperationFailed`. Each exception provides a stable
`reason()` and sanitized scalar `context()`.

The default limits are 200 MiB input, 512 MiB general output, and 64 MiB JSON output. Set a byte limit to
`null`, zero, or a negative integer to disable it. Keep positive limits and run untrusted DWG conversions in
a resource-limited worker or container.

Successful conversion means the configured tools completed the requested operation and the artifact passed
the package's format checks. It is not a DWG safety certification, a full conformance check, or an AutoCAD
visual-fidelity guarantee.

## Documentation

Read the complete English and Traditional Chinese documentation at
[mattmy.github.io/laravel-dwg-converter-doc](https://mattmy.github.io/laravel-dwg-converter-doc/).

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the version history.

## License

Laravel DWG Converter is released under the [MIT License](LICENSE). LibreDWG is GPLv3+ software installed
and distributed separately. LibreOffice and ImageMagick are also installed separately under their own
licenses.
