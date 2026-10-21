<?php
namespace SimpleMappr;

final class RenderedImage
{
    public static function bytes(\imageObj $image, \mapObj $map, string $format): string
    {
        if ($format !== 'tif') { return $image->getBytes(); }
        $path = tempnam(sys_get_temp_dir(), 'simplemappr-export-');
        if ($path === false) { throw new \RuntimeException('Export storage is unavailable.'); }
        try {
            $image->save($path, $map);
            $bytes = file_get_contents($path);
            if ($bytes === false) { throw new \RuntimeException('The export could not be read.'); }
            return $bytes;
        } finally { unlink($path); }
    }
}
