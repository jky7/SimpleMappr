<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\DetailedRelief;
use SimpleMappr\Mappr\Mappr;

final class DetailedReliefHttpTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DetailedRelief::enabled() || !isset(Mappr::getShapefileConfig()['reliefgrey'])) { self::markTestSkipped('Requires enabled detailed relief and installed Gray Earth overview.'); }
    }

    private function request(string $path,array|string|null $data=null): array
    {
        $base=rtrim(getenv('SIMPLEMAPPR_TEST_URL') ?: 'http://127.0.0.1','/');$headers=[];
        $curl=curl_init($base.$path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>22,CURLOPT_HEADERFUNCTION=>static function($handle,$line) use(&$headers){$headers[]=$line;return strlen($line);}]);
        if (is_array($data)) { curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]); }
        elseif (is_string($data)) { curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_HTTPHEADER=>['Content-Type: application/xml']]); }
        $body=curl_exec($curl);self::assertNotFalse($body,curl_error($curl));
        return [curl_getinfo($curl,CURLINFO_RESPONSE_CODE),$body,implode('',$headers)];
    }

    private function document(): array
    {
        return ['width'=>600,'height'=>300,'bbox_map'=>'6.5,45.8,6.9,46.0','projection'=>'epsg:4326','projection_map'=>'epsg:4326','layers'=>['reliefgrey'=>'on'],'options'=>['legend'=>0,'scalebar'=>0],'watermark'=>0];
    }

    public function testPreviewAndWarmRequestsUseRealDetailedTerrainWithoutChangingSavedLayerKeys(): void
    {
        $images=[];
        for ($i=0;$i<2;$i++) {
            [$status,$body]=$this->request('/application.json',$this->document());self::assertSame(200,$status,$body);
            $map=json_decode($body,true,512,JSON_THROW_ON_ERROR);
            self::assertSame('NASA/METI ASTER GDEM via NASA GIBS',$map['relief_source']['name']);
            self::assertSame(11,$map['relief_source']['zoom']);self::assertEqualsWithDelta(76.44,$map['relief_source']['pixel_metres_at_equator'],0.01);
            [$status,$bytes]=$this->request($map['mapOutputImage']);self::assertSame(200,$status);
            $this->assertDetailedPixels($bytes,600,300);$images[]=hash('sha256',$bytes);
        }
        self::assertSame($images[0],$images[1]);
        [$status,$body]=$this->request('/application.json',array_replace($this->document(),['bbox_map'=>'-180,-90,180,90']));
        self::assertSame(200,$status,$body);self::assertNull(json_decode($body,true)['relief_source']);
    }

    public function testPreviewSwitchesOnTheDisplayedScaleBar(): void
    {
        foreach ([310000=>false,300000=>true,220000=>true] as $span=>$detailed) {
            $x=1001875;$y=5740000;
            $document=array_replace($this->document(),[
                'width'=>1000,'height'=>500,'projection'=>'epsg:3395','projection_map'=>'epsg:3395',
                'bbox_map'=>implode(',',[$x-$span/2,$y-$span/4,$x+$span/2,$y+$span/4])
            ]);
            [$status,$body]=$this->request('/application.json',$document);
            self::assertSame(200,$status,$body);
            $map=json_decode($body,true,512,JSON_THROW_ON_ERROR);
            self::assertSame($detailed,$map['relief_source']!==null);
        }
    }

    public function testColourReliefGainsDetailedTerrainAndWater(): void
    {
        if (!isset(Mappr::getShapefileConfig()['relief'])) { self::markTestSkipped('Requires the installed colour relief overview.'); }
        $document=array_replace($this->document(),['layers'=>['relief'=>'on']]);
        [$status,$body]=$this->request('/application.json',$document);self::assertSame(200,$status,$body);
        $map=json_decode($body,true,512,JSON_THROW_ON_ERROR);self::assertSame(11,$map['relief_source']['zoom']);
        [$status,$bytes]=$this->request($map['mapOutputImage']);self::assertSame(200,$status);
        $this->assertDetailedPixels($bytes,600,300);
        $image=imagecreatefromstring($bytes);$colour=imagecolorsforindex($image,imagecolorat($image,300,120));imagedestroy($image);
        self::assertNotTrue($colour['red']===$colour['green'] && $colour['green']===$colour['blue'],'Colour relief must stay tinted.');
        $query=['SERVICE'=>'WMS','REQUEST'=>'GetMap','VERSION'=>'1.1.1','LAYERS'=>'relief','SRS'=>'EPSG:4326','BBOX'=>'6.5,45.8,6.9,46.0','WIDTH'=>600,'HEIGHT'=>300,'FORMAT'=>'image/png'];
        [$status,$wms,$headers]=$this->request('/wms?'.http_build_query($query));self::assertSame(200,$status,substr($wms,0,300));
        $this->assertDetailedPixels($wms,600,300);self::assertStringContainsString('/data-credits',$headers);
    }

    public function testBlueMarbleGainsLandsatLandAndFlatWater(): void
    {
        if (!isset(Mappr::getShapefileConfig()['blueMarble'])) { self::markTestSkipped('Requires the installed Blue Marble overview.'); }
        $document=array_replace($this->document(),['bbox_map'=>'-122.6,37.7,-122.2,37.9','layers'=>['blueMarble'=>'on']]);
        [$status,$body]=$this->request('/application.json',$document);self::assertSame(200,$status,$body);
        $map=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        self::assertSame('NASA/USGS Global WELD Landsat via NASA GIBS',$map['relief_source']['name']);
        [$status,$bytes]=$this->request($map['mapOutputImage']);self::assertSame(200,$status);
        $this->assertDetailedPixels($bytes,600,300);
        $image=imagecreatefromstring($bytes);$water=0;
        for ($y=20;$y<220;$y+=4) { for ($x=20;$x<580;$x+=4) { if ((imagecolorat($image,$x,$y)&0xffffff)===(DetailedRelief::WATER[0]<<16|DetailedRelief::WATER[1]<<8|DetailedRelief::WATER[2])) { $water++; } } }
        imagedestroy($image);self::assertGreaterThan(500,$water,'Bay and ocean use the flat Blue Marble water colour.');
        $query=['SERVICE'=>'WMS','REQUEST'=>'GetMap','VERSION'=>'1.1.1','LAYERS'=>'blueMarble','SRS'=>'EPSG:4326','BBOX'=>'-122.6,37.7,-122.2,37.9','WIDTH'=>600,'HEIGHT'=>300,'FORMAT'=>'image/png'];
        [$status,$wms,$headers]=$this->request('/wms?'.http_build_query($query));self::assertSame(200,$status,substr($wms,0,300));
        $this->assertDetailedPixels($wms,600,300);self::assertStringContainsString('/data-credits',$headers);
    }

    public function testRasterAndOfficeDownloadsKeepDetailedPixelsAndSourceCredits(): void
    {
        foreach (['png','tif','docx','pptx'] as $format) {
            [$status,$bytes,$headers]=$this->request(in_array($format,['docx','pptx'],true)?'/'.$format:'/application',$this->document()+['download'=>1,'output'=>$format]);
            self::assertSame(200,$status,$format.' '.substr($bytes,0,200));self::assertStringContainsString('/data-credits',$headers,$format);
            if ($format==='png') { $this->assertDetailedPixels($bytes,600,300); }
            elseif ($format==='tif') { self::assertSame([600,300],array_slice(getimagesizefromstring($bytes),0,2)); }
            else {
                $path=tempnam(sys_get_temp_dir(),'mappr-detail-office-');file_put_contents($path,$bytes);
                try {
                    $zip=new ZipArchive();self::assertTrue($zip->open($path,ZipArchive::CHECKCONS));$found=false;
                    for ($index=0;$index<$zip->numFiles;$index++) {
                        if (str_contains($zip->getNameIndex($index),'/media/') && str_ends_with($zip->getNameIndex($index),'.png')) { $this->assertDetailedPixels($zip->getFromIndex($index),600,300);$found=true; }
                    }
                    self::assertTrue($found);$zip->close();
                } finally { unlink($path); }
            }
        }
        [$status,$bytes]=$this->request('/application',$this->document()+['download'=>1,'output'=>'png','download_factor'=>5]);
        self::assertSame(200,$status);self::assertSame([3000,1500],array_slice(getimagesizefromstring($bytes),0,2));
    }

    public function testProjectedAndRotatedViewsKeepDetailedLandPixels(): void
    {
        foreach ([['projection'=>'esri:102014'],['projection'=>'epsg:3395'],['projection'=>'esri:102014','rotation'=>30]] as $settings) {
            [$status,$body]=$this->request('/application.json',array_replace($this->document(),['bbox_map'=>'6.6,45.85,6.8,45.95'],$settings));
            self::assertSame(200,$status,$body);$map=json_decode($body,true,512,JSON_THROW_ON_ERROR);
            self::assertNotNull($map['relief_source']);
            [$status,$bytes]=$this->request($map['mapOutputImage']);self::assertSame(200,$status);
            $this->assertDetailedPixels($bytes,600,300);
        }
    }

    public function testWmsAxisOrderXmlAndCaseSelectTheSameDetailedSource(): void
    {
        $query=['SERVICE'=>'WMS','REQUEST'=>'GetMap','VERSION'=>'1.1.1','LAYERS'=>'RELIEFGREY','SRS'=>'EPSG:4326','CRS'=>'EPSG:3857','BBOX'=>'6.5,45.8,6.9,46.0','WIDTH'=>600,'HEIGHT'=>300,'FORMAT'=>'image/png'];
        [$status,$v11,$headers]=$this->request('/wms?'.http_build_query($query));self::assertSame(200,$status,substr($v11,0,300));$this->assertDetailedPixels($v11,600,300);self::assertStringContainsString('/data-credits',$headers);
        $query['VERSION']='1.3.0';$query['BBOX']='45.8,6.5,46.0,6.9';$query['CRS']='EPSG:4326';$query['SRS']='EPSG:3857';
        [$status,$v13]=$this->request('/wms?'.http_build_query($query));self::assertSame(200,$status,substr($v13,0,300));self::assertSame(hash('sha256',$v11),hash('sha256',$v13));
        $xml='<sld:GetMap xmlns:sld="http://www.opengis.net/sld" xmlns:ogc="http://www.opengis.net/ogc" service="WMS" version="1.1.1"><sld:StyledLayerDescriptor version="1.0.0"><sld:NamedLayer><sld:Name>reliefgrey</sld:Name><sld:NamedStyle><sld:Name>default</sld:Name></sld:NamedStyle></sld:NamedLayer></sld:StyledLayerDescriptor><sld:BoundingBox srsName="EPSG:4326"><ogc:LowerCorner>6.5 45.8</ogc:LowerCorner><ogc:UpperCorner>6.9 46.0</ogc:UpperCorner></sld:BoundingBox><sld:Output><sld:Size><sld:Width>600</sld:Width><sld:Height>300</sld:Height></sld:Size><sld:Format>image/png</sld:Format></sld:Output></sld:GetMap>';
        [$status,$body]=$this->request('/wms',$xml);self::assertSame(200,$status,substr($body,0,300));self::assertSame(hash('sha256',$v11),hash('sha256',$body));
        $query['CRS']='EPSG:3857';
        foreach (['-1e100,-100,1e100,100','-1e309,-100,1e309,100'] as $bounds) {
            $query['BBOX']=$bounds;
            [$status,$body,$headers]=$this->request('/wms?'.http_build_query($query));self::assertSame(400,$status);self::assertStringContainsString('xml',$headers);self::assertStringContainsString('supported Earth coordinates',$body);
        }
    }

    public function testInternalTerrainAndMaskCannotBeRequestedOrAdvertisedAsPublicLayers(): void
    {
        foreach (['wms','wfs'] as $service) {
            [$status,$body]=$this->request('/'.$service.'?REQUEST=GetCapabilities');self::assertSame(200,$status);self::assertStringNotContainsString('internal_detailed_relief',$body);
        }
        [$status,$body]=$this->request('/wms?REQUEST=GetMap&SERVICE=WMS&VERSION=1.1.1&LAYERS=internal_detailed_relief&SRS=EPSG:4326&BBOX=6.5,45.8,6.9,46&WIDTH=600&HEIGHT=300&FORMAT=image/png');
        self::assertSame(400,$status);self::assertStringContainsString('LayerNotDefined',$body);
        [$status,$body]=$this->request('/wms?REQUEST=GetMap&SERVICE=WMS&VERSION=1.1.1&LAYERS=internal_detailed_relief,reliefgrey&SRS=EPSG:4326&BBOX=6.5,45.8,6.9,46&WIDTH=600&HEIGHT=300&FORMAT=image/png');
        self::assertSame(400,$status);self::assertStringContainsString('LayerNotDefined',$body);
    }

    private function assertDetailedPixels(string $bytes,int $width,int $height): void
    {
        $image=@imagecreatefromstring($bytes);self::assertNotFalse($image);self::assertSame([$width,$height],[imagesx($image),imagesy($image)]);$levels=[];$changes=0;
        for ($y=20;$y<$height-75;$y++) {
            for ($x=20;$x<$width-20;$x++) {
                $color=imagecolorat($image,$x,$y)&0xffffff;$next=imagecolorat($image,$x+1,$y)&0xffffff;
                if ($color!==$next) { $changes++; }
                $levels[$color]=true;
            }
        }
        self::assertGreaterThan(40,count($levels));self::assertGreaterThan(10000,$changes,'Close terrain must contain detail across neighboring pixels.');imagedestroy($image);
    }
}
