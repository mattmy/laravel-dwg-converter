# Laravel DWG Converter

`mattmy/laravel-dwg-converter` is a Laravel-first wrapper around user-installed LibreDWG CLI tools. It requires PHP 8.3+ and Laravel 12 or 13.

```bash
composer require mattmy/laravel-dwg-converter
```

Install LibreDWG separately, then publish the configuration when its executables are not on `PATH`:

```bash
php artisan vendor:publish --tag=dwg-converter-config
```

Configure `LIBREDWG_DWGBMP`, `LIBREDWG_DWG2DXF`, and `LIBREDWG_DWG2SVG` with executable paths. The package neither downloads nor contains LibreDWG; use a maintained LibreDWG patch release.

```php
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Facades\Dwg;

$thumbnail = Dwg::thumbnail($request->file('drawing'))->extract();

$dxf = Dwg::toDxf(DwgBinary::from($bytes))
    ->toVersion(DxfVersion::R2018)
    ->convert();

$svg = Dwg::toSvg(storage_path('app/private/drawing.dwg'))->convert();
```

Sources may be a valid `UploadedFile`, a local absolute path, or bytes explicitly wrapped with `DwgBinary::from()`. A plain string is always treated as a path.

`thumbnail()` extracts an embedded preview rather than rendering the drawing. A DWG may have no preview, and the result may be BMP, PNG, or WMF. `dwg2SVG` converts only the portions of 2D drawings supported by LibreDWG and does not promise complete CAD visual fidelity.

Each operation returns a one-time `DwgOutput`. `output()` loads the complete artifact into PHP memory; prefer Laravel Storage streaming for normal file delivery:

```php
$path = $dxf->storeAs('drawings', 'floor-plan.dxf', 's3');
```

Failures use exactly three package exceptions: `LibreDwgUnavailable`, `InvalidDwg`, and `DwgOperationFailed`. Each exposes `reason()` and sanitized scalar `context()`.

LibreDWG is GPLv3+ software installed and distributed independently; this package is MIT. Windows binaries are exercised by the repository integration tests. Linux remains the release CI target once a pinned LibreDWG build and redistributable DWG corpus are configured.
