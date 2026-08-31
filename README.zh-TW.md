# Laravel DWG Converter

[English](README.md) | [繁體中文](README.zh-TW.md)

Laravel DWG Converter 讓 Laravel 應用透過小型且具型別的 API，擷取 DWG 內嵌縮圖，或將 DWG
輸出為 DXF、結構 JSON、PNG、JPEG 或 WebP。

## 功能特色

- 擷取 DWG 內嵌縮圖。
- 將 DWG 轉換為 ASCII DXF。
- 將 DWG 輸出為結構 JSON。
- 建立 PNG、JPEG 或 WebP 預覽。
- 接受上傳檔案、local path 或 DWG bytes。
- 透過 Laravel Storage 儲存或串流結果。

## 系統需求

| 需求 | 支援範圍 |
|---|---|
| PHP | 8.3 或更新版本 |
| Laravel | 12 或 13 |
| CI 實測組合 | PHP 8.3–8.5 搭配 Laravel 12–13，包含 PHP 8.3／Laravel 12 lowest boundary |

外部 commands 依功能而異。只擷取縮圖、建立 DXF 或輸出 JSON 時，不需要安裝 LibreOffice 或
ImageMagick。

## 各操作需要的外部 commands

| 操作 | 必要 commands |
|---|---|
| `Dwg::thumbnail(...)->extract()` | LibreDWG `dwgbmp` |
| `Dwg::toDxf(...)->convert()` | LibreDWG `dwg2dxf` |
| `Dwg::toJson(...)->convert()` | 具 JSON output 能力的 LibreDWG `dwgread` |
| `Dwg::toImage(...)->convert()` | LibreDWG `dwg2dxf`、LibreOffice 7.4+ `soffice`、ImageMagick 7 `magick` |

本套件不會下載或附帶這些工具。LibreDWG 安裝方式請參考
[LibreDWG 官方 repository](https://github.com/libredwg/libredwg)。
[外部工具指南](https://mattmy.github.io/laravel-dwg-converter-doc/zh-TW/guide/external-tools)提供
Ubuntu／Debian、RHEL／Fedora 與 Windows 的 LibreOffice、ImageMagick 安裝方式。

## 安裝

透過 Composer 安裝套件：

```bash
composer require mattmy/laravel-dwg-converter
```

必要 command 不在 `PATH` 時，請發布設定並填入它的絕對路徑：

```bash
php artisan vendor:publish --tag=dwg-converter-config
```

## 快速開始

下方範例會擷取 DWG 的內嵌預覽，再串流至 Laravel 預設 disk。來源是已通過 request 驗證的
`UploadedFile`。

```php
use Mattmy\DwgConverter\Facades\Dwg;

$thumbnail = Dwg::thumbnail($request->file('drawing'))->extract();

$path = $thumbnail->storeAs(
    path: 'drawing-thumbnails',
    name: 'floor-plan.'.$thumbnail->extension(),
);
```

DWG 不一定包含內嵌縮圖。需要渲染預覽時，請使用圖片轉換：

```php
use Mattmy\DwgConverter\ImageFormat;
use Mattmy\DwgConverter\ImageResolution;

$image = Dwg::toImage(storage_path('app/private/floor-plan.dwg'))
    ->format(ImageFormat::WEBP)
    ->atResolution(ImageResolution::MEDIUM)
    ->convert();

$path = $image->storeAs('drawing-previews', 'floor-plan.webp', 's3');
```

## 輸入與輸出

每個操作都接受有效的 `UploadedFile`、local absolute path，或以 `DwgBinary::from($bytes)` 明確包裝的
bytes。普通 string 一律視為 path。

每次成功操作都回傳一次性的 `DwgOutput`：

- `extension()` 與 `mimeType()` 可在不消費結果的情況下讀取可信的輸出資訊。
- `storeAs()` 將產物串流至 Laravel Storage，之後清理暫存資源。
- `output()` 將全部 bytes 讀入 string，只適合能安全放入 PHP 記憶體的輸出。

## 錯誤與操作限制

失敗時會拋出 `LibreDwgUnavailable`、`InvalidDwg` 或 `DwgOperationFailed`。每個例外都提供穩定的
`reason()` 與去敏後的 scalar `context()`。

預設上限為 200 MiB 輸入、512 MiB 一般輸出及 64 MiB JSON 輸出。將 byte limit 設為 `null`、零或
負整數即可停用。處理不可信 DWG 時，應保留正整數上限，並在有限制資源的 worker 或 container
執行轉換。

轉換成功只代表設定的工具完成本次操作，而且產物通過套件的格式檢查；不代表 DWG 已通過安全認證、
完整 conformance 檢查或 AutoCAD 視覺保真驗證。

## 完整文件

完整英文及繁體中文文件位於
[mattmy.github.io/laravel-dwg-converter-doc](https://mattmy.github.io/laravel-dwg-converter-doc/zh-TW/)。

## Changelog

版本紀錄請參考 [CHANGELOG.md](CHANGELOG.md)。

## License

Laravel DWG Converter 使用 [MIT License](LICENSE) 發布。LibreDWG 是另外安裝與分發的 GPLv3+
軟體；LibreOffice 與 ImageMagick 也由使用者另外安裝，並適用各自的授權條款。
