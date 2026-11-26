# Laravel DWG Converter

[English](README.md)

Laravel DWG Converter 在 Laravel 中呼叫 LibreDWG 命令列工具，擷取 DWG 的內嵌縮圖，並輸出 DXF 或結構 JSON。
建立 PNG、JPEG 或 WebP 預覽還需要 LibreOffice 與 ImageMagick。

## 功能特色

- 擷取 DWG 內嵌縮圖。
- 查詢圖層名稱與凍結、關閉、鎖定狀態。
- [讀取、篩選及計算已儲存的圖元](https://mattmy.github.io/laravel-dwg-converter-doc/zh-TW/guide/entities)。
- 辨識 DWG 儲存格式並讀取基本圖面資訊。
- 將 DWG 轉換為 ASCII DXF。
- 將 DWG 輸出為結構 JSON。
- 建立 PNG、JPEG 或 WebP 預覽。
- 接受上傳檔案、本機絕對路徑或 DWG 位元組。
- 將產出的檔案串流寫入 Laravel Storage，或直接在 PHP 讀取位元組。

## 系統需求

| 需求 | 支援範圍 |
|---|---|
| PHP | 8.2 或更新版本 |
| Laravel | 12 或 13 |
| CI 測試組合 | PHP 8.2 搭配 Laravel 12，以及 PHP 8.3 至 8.5 搭配 Laravel 12 或 13 |

Laravel 13 需要 PHP 8.3 或更新版本。CI 在 PHP 8.2 搭配 Laravel 12 時，會分別測試最低與目前可用的相依版本。

## 各操作需要的外部命令

| 操作 | 必要命令 |
|---|---|
| `Dwg::thumbnail(...)->extract()` | LibreDWG `dwgbmp` |
| `Dwg::toDxf(...)->convert()` | LibreDWG `dwg2dxf` |
| `Dwg::toJson(...)->convert()` | 支援 JSON 輸出的 LibreDWG `dwgread` |
| `Dwg::layers(...)->read()` | 支援 `--flags` 的 LibreDWG `dwglayers`，使用 `onlyOn()` 時也需要 `--on` |
| `Dwg::entities(...)->read()` | 支援 JSON 輸出的 LibreDWG `dwgread` |
| `Dwg::version(...)` | 無 |
| `Dwg::info(...)->read()` | 支援 JSON 輸出的 LibreDWG `dwgread` |
| `Dwg::toImage(...)->convert()` | LibreDWG `dwg2dxf`、LibreOffice 7.4+ `soffice`、ImageMagick 7 `magick` |

請使用仍受維護且包含最新安全修補的 LibreDWG 版本。本套件不會下載或附帶這些工具。LibreDWG 安裝方式請參考
[LibreDWG 官方儲存庫](https://github.com/libredwg/libredwg)。
[外部工具指南](https://mattmy.github.io/laravel-dwg-converter-doc/zh-TW/guide/external-tools)提供
Ubuntu／Debian、RHEL／Fedora 與 Windows 的 LibreOffice、ImageMagick 安裝方式。

CI 使用模擬的工具輸出驗證 PHP 與 Laravel 的相容性。完整轉換流程已在 Windows 本機驗證。

## 安裝

```bash
composer require mattmy/laravel-dwg-converter
```

命令不在 `PATH` 時，可用[工具環境變數](https://mattmy.github.io/laravel-dwg-converter-doc/zh-TW/guide/configuration#executables)
指定絕對路徑。要修改完整設定，可執行下列命令發布設定檔。

```bash
php artisan vendor:publish --tag=dwg-converter-config
```

## 快速開始

傳入已驗證的 `UploadedFile`，即可擷取內嵌預覽並儲存到 Laravel 預設磁碟。

```php
use Mattmy\DwgConverter\Facades\Dwg;

$thumbnail = Dwg::thumbnail($request->file('drawing'))->extract();

$path = $thumbnail->storeAs(
    path: 'drawing-thumbnails',
);
```

DWG 若沒有內嵌預覽，會回報 `thumbnail_not_found`。要渲染模型空間的預覽，可使用 `toImage()`。

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

每次成功的轉換會產出一張涵蓋整個模型空間的預覽。呈現上的限制見
[圖片預覽指南](https://mattmy.github.io/laravel-dwg-converter-doc/zh-TW/guide/image-previews#輸出限制)。

## 輸入與輸出

每個操作都接受有效的 `UploadedFile`、本機絕對路徑，或以 `DwgBinary::from($bytes)` 包裝的
DWG 位元組。直接傳入的字串會當成本機絕對路徑。

轉換與縮圖擷取會回傳 `DwgOutput`。每份結果只能從 `storeAs()` 和 `output()` 選一個呼叫一次。
若呼叫失敗，請重新執行原操作取得新結果。

- `extension()` 與 `mimeType()` 可重複讀取。
- `storeAs()` 將檔案串流寫入 Laravel Storage。
- `output()` 將整份檔案讀入 PHP 記憶體。
- 複製、序列化或反序列化 `DwgOutput` 會拋出 `LogicException`。

## 錯誤與操作限制

失敗時會拋出 `LibreDwgUnavailable`、`InvalidDwg` 或 `DwgOperationFailed`。每個例外都提供穩定的
`reason()` 與已移除敏感資訊的 `context()`。暫存清理失敗時，套件會保留原本的結果或例外，並嘗試記錄警告。
詳見[錯誤與疑難排解](https://mattmy.github.io/laravel-dwg-converter-doc/zh-TW/guide/errors-and-troubleshooting)。

預設大小上限為輸入 200 MiB、一般輸出 512 MiB、JSON 輸出 64 MiB。
各操作適用的上限見[執行設定](https://mattmy.github.io/laravel-dwg-converter-doc/zh-TW/guide/configuration#runtime-設定)。
將上限設為 `null`、零或負整數即可停用。

處理不可信的 DWG 時，請啟用適用的大小上限，並在有資源限制的工作程序或容器中轉換。
套件會檢查產物的基本結構。若需要確認轉換保真度，請用代表性的圖檔在目標 CAD 軟體中測試。

## 完整文件

- [繁體中文文件](https://mattmy.github.io/laravel-dwg-converter-doc/zh-TW/)
- [英文文件](https://mattmy.github.io/laravel-dwg-converter-doc/)

## Changelog

版本紀錄請參考 [CHANGELOG.md](CHANGELOG.md)。

## License

Laravel DWG Converter 採用 [MIT 授權](LICENSE)。LibreDWG 依 GPLv3+ 授權獨立散布。
LibreOffice 與 ImageMagick 也需另外安裝，並適用各自的授權條款。
