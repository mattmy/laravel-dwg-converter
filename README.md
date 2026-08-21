# Laravel DWG Converter

`mattmy/laravel-dwg-converter` is a Laravel-first wrapper around user-installed LibreDWG CLI tools.

Install LibreDWG yourself, then publish the configuration and set the three executable paths when they are not on `PATH`. Use the latest maintained LibreDWG patch release; this package neither downloads nor contains LibreDWG.

```php
use Mattmy\DwgConverter\Facades\Dwg;

$thumbnail = Dwg::thumbnail()->extract($request->file('drawing'));
$bytes = $thumbnail->output();
```

`thumbnail()` extracts an already embedded preview, rather than rendering the drawing. A DWG can have no thumbnail, and an extracted preview can be BMP, PNG, or WMF.

```php
use Mattmy\DwgConverter\DwgBinary;
use Mattmy\DwgConverter\DxfVersion;
use Mattmy\DwgConverter\Facades\Dwg;

$dxf = Dwg::toDxf()
    ->toVersion(DxfVersion::R2018)
    ->convert(DwgBinary::from($bytes));

$svg = Dwg::toSvg()->convert(storage_path('app/private/drawing.dwg'));
```

`dwg2SVG` converts only portions of LibreDWG-supported 2D drawings; it does not promise complete CAD visual fidelity. `output()` loads the whole artifact into PHP memory. Prefer `storeAs()` for normal file delivery:

```php
$path = $dxf->storeAs('drawings', 'floor-plan.dxf', 's3');
```

The package exposes `LibreDwgUnavailable`, `InvalidDwg`, and `DwgOperationFailed`. LibreDWG is GPLv3+ software installed and distributed independently; this package is MIT. Linux is the v1 CI target. Windows binaries are supported for development verification only until Windows CI is added.
