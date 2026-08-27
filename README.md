# Laravel DWG Converter

`mattmy/laravel-dwg-converter` is a Laravel-first wrapper around user-installed LibreDWG CLI tools. It requires PHP 8.3+ and Laravel 12 or 13.

```bash
composer require mattmy/laravel-dwg-converter
```

Install LibreDWG separately, then publish the configuration when its executables are not on `PATH`:

```bash
php artisan vendor:publish --tag=dwg-converter-config
```

Configure `LIBREDWG_DWGBMP`, `LIBREDWG_DWG2DXF`, `LIBREDWG_DWGREAD`, `DWG_CONVERTER_LIBREOFFICE`, and `DWG_CONVERTER_IMAGEMAGICK` with executable paths. The package neither downloads nor contains LibreDWG; use a maintained LibreDWG patch release.

On Windows, point `DWG_CONVERTER_LIBREOFFICE` to LibreOffice's console launcher, normally `C:/Program Files/LibreOffice/program/soffice.com`; using `soffice.exe` can exit successfully without creating a headless conversion output.
Also configure a short absolute `temporary_directory` path for image conversion. LibreOffice can crash when its isolated profile and intermediate files exceed Windows path limits.

```php
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Facades\Dwg;
use Mattmy\DwgConverter\ImageFormat;
use Mattmy\DwgConverter\ImageResolution;

$thumbnail = Dwg::thumbnail($request->file('drawing'))->extract();

$dxf = Dwg::toDxf(DwgBinary::from($bytes))
    ->toVersion(DxfVersion::R2018)
    ->convert();

$json = Dwg::toJson($request->file('drawing'))->convert();

$image = Dwg::toImage(storage_path('app/private/drawing.dwg'))
    ->format(ImageFormat::WEBP)
    ->usingDxfVersion(DxfVersion::R2018)
    ->atResolution(ImageResolution::MEDIUM)
    ->convert();
```

Sources may be a valid `UploadedFile`, a local absolute path, or bytes explicitly wrapped with `DwgBinary::from()`. A plain string is always treated as a path.

Source binding performs no I/O. `convert()` and `extract()` snapshot the source, reject obvious non-DWG candidates, then rely on the selected LibreDWG command and output validation. Success means the configured LibreDWG build completed this operation without a critical decode failure; it is not a safety certification, full DWG conformance check, or visual-fidelity guarantee. Run untrusted DWG conversions in a resource-limited worker or container with current external-tool security patches.

`thumbnail()` extracts an embedded preview rather than rendering the drawing. A DWG may have no preview, and the result may be BMP, PNG, or WMF. `toImage()` runs LibreDWG, then tells LibreOffice and ImageMagick to use the selected PNG (default), JPEG, or WebP format while ImageMagick removes white margins. It creates a best-effort whole-model-space preview; it does not split drawings or promise AutoCAD visual fidelity. JPEG output is flattened onto white because JPEG has no alpha channel, and is re-encoded after trimming. SVG is intentionally unsupported because the evaluated LibreDWG renderer produced excessive whitespace.

`toJson()` runs `dwgread` and returns LibreDWG's native structural JSON dump. It is an opaque artifact for inspection or downstream processing, not a stable application schema, GeoJSON, or a full-fidelity guarantee. It deliberately has no `usingDxfVersion()` because `dwgread` has no `--as` option and preserves the source DWG version. JSON can expand substantially beyond the DWG; its default `max_json_output_bytes` is 64 MiB, and JSON syntax is validated before return. Use `storeAs()` instead of `output()` for normal delivery.

The default byte limits are 200 MiB input, 512 MiB output, and 64 MiB JSON. Each limit is disabled when its runtime config key is absent or its value is `null`, zero, or negative; only positive integers enforce a limit. For JSON, active `max_output_bytes` and `max_json_output_bytes` are combined by taking the smaller value. Do not disable limits for untrusted drawings unless the worker separately has adequate memory, CPU, and disk limits.

`format()` accepts only `ImageFormat::PNG`, `ImageFormat::JPEG`, or `ImageFormat::WEBP`. `usingDxfVersion()` controls only the intermediate `dwg2dxf --as` target. `atResolution()` chooses the pre-trim LibreOffice export canvas: `HIGH` (default, 4096×5792), `MEDIUM` (2048×2896), or `LOW` (1024×1448). The trimmed image dimensions depend on drawing content.

Each operation returns a one-time `DwgOutput`. `output()` loads the complete artifact into PHP memory; prefer Laravel Storage streaming for normal file delivery:

```php
$path = $dxf->storeAs('drawings', 'floor-plan.dxf', 's3');
```

Failures use exactly three package exceptions: `LibreDwgUnavailable`, `InvalidDwg`, and `DwgOperationFailed`. Each exposes `reason()` and sanitized scalar `context()`.

LibreDWG is GPLv3+ software installed and distributed independently; this package is MIT. LibreOffice and ImageMagick are also installed independently. `output()` loads the complete artifact into PHP memory; use `storeAs()` for normal file delivery. Windows binaries are exercised by the repository integration tests. Linux remains the release CI target once a pinned toolchain and redistributable DWG corpus are configured.
