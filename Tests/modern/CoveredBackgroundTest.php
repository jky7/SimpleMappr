<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\Mappr\Application\Application;
use SimpleMappr\Mappr\Mappr;
use SimpleMappr\Mappr\WebServices\Wms;

final class CoveredBackgroundTest extends TestCase
{
    public function testOnlyOpaqueGlobalBackgroundsHideEarlierBackgrounds(): void
    {
        $renderer=new class extends Application {
            public function visible(array $layers): array { return array_values($this->visibleBackgroundLayers($layers)); }
        };
        $shapes=[];
        foreach (['relief','reliefalt','reliefgrey','blueMarble','regionalRaster'] as $name) { $shapes[$name]=['type'=>MS_LAYER_RASTER]; }
        $renderer->set_shapes($shapes);
        self::assertSame(['countries','blueMarble','regionalRaster'],$renderer->visible(['relief','countries','reliefalt','reliefgrey','blueMarble','regionalRaster']));
        $shapes['reliefgrey']['opacity']=75;$renderer->set_shapes($shapes);
        self::assertSame(['relief','reliefgrey'],$renderer->visible(['blueMarble','relief','reliefgrey']));
        self::assertSame(['blueMarble'],$renderer->visible(['relief','reliefgrey','blueMarble']));
    }

    private function document(array $layers): array
    {
        foreach (['relief','reliefalt','reliefgrey','blueMarble'] as $name) {
            if (!isset(Mappr::getShapefileConfig()[$name])) { self::markTestSkipped('Requires the full-map profile.'); }
        }
        return ['width'=>600,'height'=>300,'bbox_map'=>'-100,30,-60,50','projection'=>'epsg:4326','projection_map'=>'epsg:4326','layers'=>array_fill_keys($layers,'on'),'options'=>['legend'=>0,'scalebar'=>0],'watermark'=>0];
    }

    private function application(array $layers,array $options=[]): Application
    {
        $previous=$_REQUEST;
        try { $_REQUEST=array_replace($this->document($layers),$options);return (new Application())->execute(); }
        finally { $_REQUEST=$previous; }
    }

    public function testPreviewsAndDownloadsKeepOnlyTheTopBackgroundAndPreserveSelections(): void
    {
        foreach ([0,1] as $download) {
            foreach (['relief','reliefalt','reliefgrey','blueMarble'] as $top) {
                $layers=array_values(array_diff(['relief','reliefalt','reliefgrey','blueMarble'],[$top]));$layers[]=$top;$layers[]='countries';
                $combined=$this->application($layers,['download'=>$download]);$single=$this->application([$top,'countries'],['download'=>$download]);
                self::assertSame($single->get_image()->getBytes(),$combined->get_image()->getBytes());
                self::assertSame([$top,'countries','base'],$combined->get_renderLayers());
                foreach ($layers as $name) { self::assertArrayHasKey($name,$combined->request->layers); }
                foreach (array_diff(['relief','reliefalt','reliefgrey','blueMarble'],[$top]) as $hidden) {
                    self::assertNull($combined->get_map_obj()->getLayerByName($hidden),'Covered imagery must never enter the native map.');
                }
            }
        }
    }

    public function testTransparentEcologyAndVectorOverlaysRemainVisible(): void
    {
        $overlays=['ecoregions','conservation','rivers','countries'];
        $combined=$this->application(['relief','reliefgrey',...$overlays]);$single=$this->application(['reliefgrey',...$overlays]);
        self::assertSame($single->get_image()->getBytes(),$combined->get_image()->getBytes());
        foreach ($overlays as $name) { self::assertContains($name,$combined->get_renderLayers()); }
    }

    public function testWmsUsesClientOrderAndDoesNotReadTheHiddenRaster(): void
    {
        $this->document([]);$previous=$_REQUEST;
        try {
            foreach (['1.1.1','1.3.0'] as $version) {
                foreach ([['relief','reliefgrey'],['reliefgrey','relief']] as [$hidden,$top]) {
                    $_REQUEST=['SERVICE'=>'WMS','REQUEST'=>'GetMap','VERSION'=>$version,'LAYERS'=>$hidden.','.$top,'SRS'=>'EPSG:4326','CRS'=>'EPSG:4326','BBOX'=>$version==='1.1.1'?'-100,30,-60,50':'30,-100,50,-60','WIDTH'=>600,'HEIGHT'=>300,'FORMAT'=>'image/png'];
                    $combined=(new Wms())->makeService()->execute();
                    $combined->get_map_obj()->getLayerByName($hidden)->data='/does-not-exist/covered.tif';
                    $bytes=$combined->createOutput();self::assertNotFalse(@imagecreatefromstring($bytes),substr($bytes,0,300));
                    $_REQUEST['LAYERS']=$top;$single=(new Wms())->makeService()->execute();
                    self::assertSame($single->createOutput(),$bytes);
                }
            }
            $_REQUEST['LAYERS']='simplemappr';
            $group=(new Wms(['relief','reliefgrey']))->makeService()->execute()->createOutput();
            $_REQUEST['LAYERS']='reliefgrey';
            self::assertSame((new Wms(['relief','reliefgrey']))->makeService()->execute()->createOutput(),$group);
        } finally { $_REQUEST=$previous; }
    }

    public function testServiceCapabilitiesStillAdvertiseEveryBackground(): void
    {
        $this->document([]);$previous=$_REQUEST;
        try {
            $_REQUEST=['SERVICE'=>'WMS','REQUEST'=>'GetCapabilities'];
            $xml=(new Wms())->makeService()->execute()->createOutput();
            foreach (['relief','reliefalt','reliefgrey','blueMarble'] as $name) { self::assertStringContainsString('<Name>'.$name.'</Name>',$xml); }
        } finally { $_REQUEST=$previous; }
    }

    public function testHiddenLayersDoNotHideInvalidWmsRequests(): void
    {
        $this->document([]);$previous=$_REQUEST;
        try {
            $query=['SERVICE'=>'WMS','REQUEST'=>'GetMap','VERSION'=>'1.1.1','LAYERS'=>'relief,reliefgrey','SRS'=>'EPSG:4326','BBOX'=>'-100,30,-60,50','WIDTH'=>600,'HEIGHT'=>300,'FORMAT'=>'image/png'];
            foreach ([['LAYERS'=>'relief,missing,reliefgrey'],['STYLES'=>'missing,']] as $invalid) {
                $_REQUEST=array_replace($query,$invalid);
                self::assertTrue(str_contains((new Wms())->makeService()->execute()->createOutput(),'ServiceException'),json_encode($invalid));
            }
        } finally { $_REQUEST=$previous; }
    }

    public function testDetailedTerrainIsPreparedOnlyForTheVisibleBackground(): void
    {
        if (!\SimpleMappr\DetailedRelief::enabled()) { self::markTestSkipped('Requires enabled detailed relief.'); }
        $options=['bbox_map'=>'6.5,45.8,6.9,46.0'];
        foreach ([['relief','reliefgrey'],['reliefgrey','relief']] as [$hidden,$top]) {
            $combined=$this->application(['blueMarble',$hidden,$top],$options);
            self::assertSame([$top],array_keys($combined->get_detailedReliefLayers()));
            self::assertTrue($combined->get_detailedReliefUsed());
            self::assertSame($this->application([$top],$options)->get_image()->getBytes(),$combined->get_image()->getBytes());
        }
    }

    public function testDetailedWmsUsesOnlyTheVisibleBackground(): void
    {
        if (!\SimpleMappr\DetailedRelief::enabled()) { self::markTestSkipped('Requires enabled detailed relief.'); }
        $this->document([]);$previous=$_REQUEST;
        try {
            foreach ([['relief','reliefgrey'],['reliefgrey','relief']] as [$hidden,$top]) {
                $_REQUEST=['SERVICE'=>'WMS','REQUEST'=>'GetMap','VERSION'=>'1.1.1','LAYERS'=>$hidden.','.$top,'SRS'=>'EPSG:4326','BBOX'=>'6.5,45.8,6.9,46.0','WIDTH'=>600,'HEIGHT'=>300,'FORMAT'=>'image/png'];
                $combined=(new Wms())->makeService()->execute();
                self::assertSame([$top],array_keys($combined->get_detailedReliefLayers()));
                self::assertTrue($combined->get_detailedReliefUsed());
                $bytes=$combined->createOutput();self::assertNotFalse(@imagecreatefromstring($bytes),substr($bytes,0,300));
                $_REQUEST['LAYERS']=$top;
                self::assertSame((new Wms())->makeService()->execute()->createOutput(),$bytes);
            }
        } finally { $_REQUEST=$previous; }
    }
}
