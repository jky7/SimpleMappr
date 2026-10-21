<?php
declare(strict_types=1);

function nativeRuntimeReport(): array
{
    if (!extension_loaded('mapscript') || !class_exists('mapObj') || !function_exists('msGetVersion')) {
        throw new RuntimeException('The SWIG MapScript extension is not loaded');
    }
    $map = msLoadMapFromString('MAP SIZE 16 16 EXTENT -180 -90 180 90 IMAGETYPE "png" OUTPUTFORMAT NAME "png" DRIVER "AGG/PNG" IMAGEMODE RGB END END', __DIR__, null);
    $bytes = $map->draw()->getBytes();
    if (!str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        throw new RuntimeException('Native PNG rendering failed');
    }
    return [
        'status' => 'ok',
        'phase' => 'Independent PHP and native MapScript health',
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'mapserver' => msGetVersion(),
        'binding' => 'SWIG MapScript',
        'assetProfile' => 'minimal-vector-v1',
        'nativePng' => true,
    ];
}
