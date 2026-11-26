# Laravel DWG Converter

[繁體中文](README.zh-TW.md)

Laravel DWG Converter integrates LibreDWG command-line tools into Laravel applications
to extract embedded DWG thumbnails and convert DWG files to DXF or structural JSON. Creating PNG,
JPEG, or WebP previews also requires LibreOffice and ImageMagick.

## Features

- Extract embedded DWG thumbnails.
- Read layer names and frozen, off, and locked states.
- [Read, filter, and count stored entities](https://mattmy.github.io/laravel-dwg-converter-doc/guide/entities).
- Identify the stored DWG format and read basic drawing information.
- Convert DWG files to ASCII DXF.
- Export DWG files as structural JSON.
- Create PNG, JPEG, or WebP previews.
- Accept uploaded files, local absolute paths, or DWG bytes.
- Stream generated files to Laravel Storage or read their bytes in PHP.

## Requirements

| Requirement | Supported |
|---|---|
| PHP | 8.2 or later |
| Laravel | 12 or 13 |
| CI-tested combinations | PHP 8.2 with Laravel 12, and PHP 8.3 to 8.5 with Laravel 12 or 13 |

Laravel 13 requires PHP 8.3 or later. CI tests both the lowest and current dependencies on PHP 8.2 with Laravel 12.

## External commands by operation

| Operation | Required commands |
|---|---|
| `Dwg::thumbnail(...)->extract()` | LibreDWG `dwgbmp` |
| `Dwg::toDxf(...)->convert()` | LibreDWG `dwg2dxf` |
| `Dwg::toJson(...)->convert()` | LibreDWG `dwgread` with JSON output support |
| `Dwg::layers(...)->read()` | LibreDWG `dwglayers` with `--flags` and, for `onlyOn()`, `--on` |
| `Dwg::entities(...)->read()` | LibreDWG `dwgread` with JSON output support |
| `Dwg::version(...)` | None |
| `Dwg::info(...)->read()` | LibreDWG `dwgread` with JSON output support |
| `Dwg::toImage(...)->convert()` | LibreDWG `dwg2dxf`, LibreOffice 7.4+ `soffice`, ImageMagick 7 `magick` |

Use a maintained LibreDWG build with the latest security fixes. The package does not download or
bundle these tools. For LibreDWG installation, see the
[official LibreDWG repository](https://github.com/libredwg/libredwg). The
[external tools guide](https://mattmy.github.io/laravel-dwg-converter-doc/guide/external-tools) covers
LibreOffice and ImageMagick installation on Ubuntu/Debian, RHEL/Fedora, and Windows.

The CI matrix tests PHP and Laravel compatibility with simulated tool output. The full conversion pipeline
has been tested locally on Windows.

## Installation

```bash
composer require mattmy/laravel-dwg-converter
```

For commands outside `PATH`, set their absolute paths using the
[tool environment variables](https://mattmy.github.io/laravel-dwg-converter-doc/guide/configuration#executables).
To edit the full configuration, publish it with this command.

```bash
php artisan vendor:publish --tag=dwg-converter-config
```

## Quick start

Pass a validated `UploadedFile` to extract its embedded preview and save it to Laravel's default disk.

```php
use Mattmy\DwgConverter\Facades\Dwg;

$thumbnail = Dwg::thumbnail($request->file('drawing'))->extract();

$path = $thumbnail->storeAs(
    path: 'drawing-thumbnails',
);
```

A DWG without an embedded preview raises `thumbnail_not_found`. Use `toImage()` to render a preview of the model space.

```php
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\ImageFormat;
use Mattmy\DwgConverter\ImageResolution;

$image = Dwg::toImage(storage_path('app/private/floor-plan.dwg'))
    ->format(ImageFormat::WEBP)
    ->atResolution(ImageResolution::MEDIUM)
    ->convert();

$path = $image->storeAs('drawing-previews', 'floor-plan.webp', 's3');
```

Each conversion produces one best-effort preview of the entire model space. See the
[image preview guide](https://mattmy.github.io/laravel-dwg-converter-doc/guide/image-previews#output-limits)
for rendering limitations.

## Inputs and outputs

Every operation accepts a valid `UploadedFile`, a local absolute path, or bytes explicitly wrapped with
`DwgBinary::from($bytes)`. A plain string is always treated as a path.

File conversions and thumbnail extraction return a `DwgOutput`. Call either `storeAs()` or `output()`
once per result. If it fails, rerun the original operation to get a fresh result.

- `extension()` and `mimeType()` can be read repeatedly.
- `storeAs()` streams the file to Laravel Storage.
- `output()` loads the full file into PHP memory.
- Cloning, serializing, or unserializing a `DwgOutput` throws `LogicException`.

## Errors and operational limits

Failures use `LibreDwgUnavailable`, `InvalidDwg`, or `DwgOperationFailed`. Each exception provides a stable
`reason()` and sanitized `context()`. If temporary cleanup fails, the package keeps the original result or
exception and attempts to log a warning. See the
[troubleshooting guide](https://mattmy.github.io/laravel-dwg-converter-doc/guide/errors-and-troubleshooting).

The default byte limits are 200 MiB for input, 512 MiB for general output, and 64 MiB for JSON output.
See [which limits apply to each operation](https://mattmy.github.io/laravel-dwg-converter-doc/guide/configuration#runtime-settings).
Set a limit to `null`, zero, or a negative integer to disable it.

Keep applicable limits enabled for untrusted DWGs and run conversions in a resource-limited worker or
container. The package checks basic output structure. Test representative files in your target CAD software
if fidelity matters.

## Documentation

- [English documentation](https://mattmy.github.io/laravel-dwg-converter-doc/)
- [Traditional Chinese documentation](https://mattmy.github.io/laravel-dwg-converter-doc/zh-TW/)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the version history.

## License

Laravel DWG Converter is released under the [MIT License](LICENSE). LibreDWG is GPLv3+ software installed
and distributed separately. LibreOffice and ImageMagick are also installed separately under their own
licenses.
