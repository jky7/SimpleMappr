<?php
namespace SimpleMappr;

final class PreviewScale
{
    public static function configure(\mapObj $map): void
    {
        $bar=$map->scalebar;
        $bar->style=0;$bar->intervals=3;$bar->width=200;$bar->height=8;
        $bar->units=MS_KILOMETERS;
        $bar->color->setRGB(30,30,30);$bar->outlinecolor->setRGB(0,0,0);
        $bar->label->font='inter';$bar->label->size=8;
        $bar->label->encoding='UTF-8';$bar->label->color->setRGB(0,0,0);
    }

    public static function kilometres(\mapObj $map): float
    {
        $view=$map->cloneMap();
        $view->selectOutputFormat('png');
        self::configure($view);
        $image=imagecreatefromstring($view->drawScalebar()->getBytes());
        try {
            // Read the native bar itself so MapServer owns label fitting and rounding.
            $pixels=[];
            for ($x=0;$x<imagesx($image);$x++) {
                $colour=imagecolorsforindex($image,imagecolorat($image,$x,3));
                if ($colour['alpha']<127 && max($colour['red'],$colour['green'],$colour['blue'])<16) { $pixels[]=$x; }
            }
            if (count($pixels)<2) { throw new \RuntimeException('Cannot measure the preview scale bar.'); }
            $inches=$view->units===MS_DD?4374754:39.3701;
            $interval=(max($pixels)-min($pixels))/3*$view->cellsize*$inches/39370.1;
            // At the 50 km boundary the native three intervals have whole-kilometre labels.
            return $interval<1?3*$interval:3*round($interval);
        } finally { imagedestroy($image); }
    }
}
