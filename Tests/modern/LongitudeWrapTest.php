<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\Mappr\Application\Application;
use SimpleMappr\Mappr\Mappr;

final class LongitudeWrapTest extends TestCase
{
    public function testLambertIdenticalDateLineLocationsProjectTogether(): void
    {
        $source=new projectionObj('EPSG:4326');
        foreach (['esri:102009'=>60,'esri:102015'=>-30,'esri:102014'=>50,'esri:102012'=>40,'esri:102024'=>0,'epsg:3112'=>-30] as $name=>$latitude) {
            $target=new projectionObj(Mappr::getProjection($name));
            $east=new pointObj(180,$latitude);$west=new pointObj(-180,$latitude);
            self::assertSame(MS_SUCCESS,$east->project($source,$target));
            self::assertSame(MS_SUCCESS,$west->project($source,$target));
            self::assertEqualsWithDelta($west->x,$east->x,0.001,$name.' must join equivalent date-line longitudes.');
            self::assertEqualsWithDelta($west->y,$east->y,0.001,$name.' must join equivalent date-line longitudes.');
        }
    }

    public function testNorthAmericaReliefCoversOceanAtTheDateLine(): void
    {
        if (!isset(Mappr::getShapefileConfig()['reliefgrey'])) { self::markTestSkipped('Requires installed Gray Earth relief.'); }
        $previous=$_REQUEST;
        $_REQUEST=['width'=>900,'height'=>450,'bbox_map'=>'-170,5,-40,85','projection'=>'esri:102009','projection_map'=>'epsg:4326','origin'=>-96,'layers'=>['reliefgrey'=>'on','countries'=>'on'],'download'=>1,'output'=>'png','options'=>['legend'=>0,'scalebar'=>0],'watermark'=>0];
        try {
            $application=new Application();$application->execute();$map=$application->get_map_obj();
            $image=imagecreatefromstring($application->createOutput());
            self::assertFalse($application->get_detailedReliefUsed());
            $source=new projectionObj('EPSG:4326');$target=new projectionObj($map->getProjection());$bounds=$map->extent;
            foreach ([20,40,60] as $latitude) {
                $point=new pointObj(-180,$latitude);self::assertSame(MS_SUCCESS,$point->project($source,$target));
                $x=(int)round(($point->x-$bounds->minx)/($bounds->maxx-$bounds->minx)*900);
                $y=(int)round(($bounds->maxy-$point->y)/($bounds->maxy-$bounds->miny)*450);
                $color=imagecolorsforindex($image,imagecolorat($image,$x,$y));
                self::assertLessThan(240,min($color['red'],$color['green'],$color['blue']),'Relief must cover ocean at 180 degrees, latitude '.$latitude.'.');
            }
            imagedestroy($image);
        } finally { $_REQUEST=$previous; }
    }
}
