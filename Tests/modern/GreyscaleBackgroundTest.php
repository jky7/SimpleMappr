<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\AssetProfiles;
use SimpleMappr\Mappr\Application\Application;
use SimpleMappr\Mappr\Mappr;
use SimpleMappr\SavedMapDocument;

final class GreyscaleBackgroundTest extends TestCase
{
    public function testBothProfilesInstallTheSourcesForBothChoices(): void
    {
        foreach (['gray-relief-v1','full-map-v1'] as $profile) {
            $assets=[];
            foreach (AssetProfiles::selected($profile)['manifests'] as $entry) {
                foreach (json_decode(file_get_contents(ROOT.'/'.$entry['path']),true)['assets'] as $asset) { $assets[]=$asset['basename']; }
            }
            self::assertContains('HYP_HR_SR_OB_DR',$assets);self::assertContains('GRAY_HR_SR_OB_DR',$assets);
        }
    }

    public function testSavedDocumentsKeepBothGreyscaleChoices(): void
    {
        if (!isset(Mappr::getShapefileConfig()['reliefgreyalt'])) { self::markTestSkipped('Requires a greyscale raster profile.'); }
        $document=SavedMapDocument::fromForm(['layers'=>['reliefgrey'=>'on','reliefgreyalt'=>'on']]);
        self::assertArrayHasKey('reliefgrey',$document['layers']);self::assertArrayHasKey('reliefgreyalt',$document['layers']);
    }

    public function testPrimaryOverviewEqualsHypBandOneAndAlternateRetainsGray(): void
    {
        $shapes=Mappr::getShapefileConfig();
        if (!isset($shapes['reliefgreyalt'])) { self::markTestSkipped('Requires a greyscale raster profile.'); }
        $previous=$_REQUEST;$detail=getenv('SIMPLEMAPPR_DETAILED_RELIEF');putenv('SIMPLEMAPPR_DETAILED_RELIEF=0');
        try {
            $_REQUEST=['width'=>600,'height'=>400,'bbox_map'=>'2,40,17,50','projection'=>'epsg:4326','projection_map'=>'epsg:4326','layers'=>['reliefgrey'=>'on'],'download'=>1,'output'=>'png','options'=>['legend'=>0,'scalebar'=>0],'watermark'=>0];
            $primary=(new Application())->execute();$bytes=$primary->get_image()->getBytes();
            self::assertSame('relief (greyscale)',$shapes['reliefgrey']['name']);
            self::assertSame('relief (greyscale alt)',$shapes['reliefgreyalt']['name']);
            $layer=$primary->get_map_obj()->getLayerByName('reliefgrey');
            $layer->data=ROOT.'/mapserver/maps/HYP_HR_SR_OB_DR/HYP_HR_SR_OB_DR.tif';$layer->addProcessing('BANDS=1');
            self::assertSame($bytes,$primary->get_map_obj()->drawQuery()->getBytes(),'The main greyscale must preserve HYP red-channel pixels.');
            $_REQUEST['layers']=['reliefgreyalt'=>'on'];$alternate=(new Application())->execute();
            self::assertStringEndsWith('/GRAY_HR_SR_OB_DR/GRAY_HR_SR_OB_DR.tif',$alternate->get_map_obj()->getLayerByName('reliefgreyalt')->data);
            self::assertNotSame($bytes,$alternate->get_image()->getBytes());
        } finally { $_REQUEST=$previous;putenv($detail===false?'SIMPLEMAPPR_DETAILED_RELIEF':'SIMPLEMAPPR_DETAILED_RELIEF='.$detail); }
    }
}
