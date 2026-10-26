<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\PreviewScale;

final class PreviewScaleTest extends TestCase
{
    public function testNativePreviewBarUsesItsLabelRatherThanTheViewportSpan(): void
    {
        foreach ([220000=>36,290000=>48,300000=>48,310000=>51,1570000=>249,1580000=>252] as $span=>$label) {
            $map=msLoadMapFromString('MAP SIZE 1000 500 EXTENT 0 0 '.$span.' '.($span/2).' UNITS METERS FONTSET "'.ROOT.'/mapserver/fonts/fonts.list" OUTPUTFORMAT NAME png DRIVER AGG/PNG IMAGEMODE RGBA END END',ROOT,null);
            $before=$map->extent->toString();
            self::assertSame((float)$label,PreviewScale::kilometres($map));
            self::assertSame($before,$map->extent->toString());
        }
    }

    public function testGeographicAndProjectedBarsUseTheirNativeUnits(): void
    {
        $map=msLoadMapFromString('MAP SIZE 1000 500 EXTENT 0 0 220000 110000 UNITS METERS FONTSET "'.ROOT.'/mapserver/fonts/fonts.list" OUTPUTFORMAT NAME png DRIVER AGG/PNG IMAGEMODE RGBA END END',ROOT,null);
        $map->units=MS_DD;$degrees=220000*39.3701/4374754;
        $map->setExtent(6,45,6+$degrees,45+$degrees/2);
        self::assertSame(36.0,PreviewScale::kilometres($map));
    }
}
