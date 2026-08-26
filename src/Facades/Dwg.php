<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Facades;

use Illuminate\Support\Facades\Facade;
use Mattmy\DwgConverter\DwgManager;
use Override;

/**
 * @method static \Mattmy\DwgConverter\Operations\DxfConversion toDxf(\Illuminate\Http\UploadedFile|string|\Mattmy\DwgConverter\DwgBinary $source)
 * @method static \Mattmy\DwgConverter\Operations\ImageConversion toImage(\Illuminate\Http\UploadedFile|string|\Mattmy\DwgConverter\DwgBinary $source)
 * @method static \Mattmy\DwgConverter\Operations\ThumbnailExtraction thumbnail(\Illuminate\Http\UploadedFile|string|\Mattmy\DwgConverter\DwgBinary $source)
 *
 * @see DwgManager
 */
final class Dwg extends Facade
{
    /**
     * Return the container binding that backs this facade.
     */
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return DwgManager::class;
    }
}
