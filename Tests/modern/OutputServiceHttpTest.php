<?php
use PHPUnit\Framework\TestCase;

final class OutputServiceHttpTest extends TestCase
{
    private function request(string $path, ?array $data = null, ?string $xml = null): array
    {
        $base = rtrim(getenv('SIMPLEMAPPR_TEST_URL') ?: 'http://127.0.0.1', '/');
        $curl = curl_init($base.$path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
        if ($data !== null) { curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]); }
        if ($xml !== null) { curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$xml,CURLOPT_HTTPHEADER=>['Content-Type: application/xml']]); }
        $body = curl_exec($curl);
        self::assertNotFalse($body, curl_error($curl));
        return [curl_getinfo($curl,CURLINFO_RESPONSE_CODE),curl_getinfo($curl,CURLINFO_CONTENT_TYPE),$body];
    }

    private function fixture(): array
    {
        return ['width'=>600,'height'=>400,'projection'=>'esri:102009','bbox_map'=>'-130,20,-50,70','layers'=>['countries'=>'on'], 'file_name'=>'Montréal & A <B> "quoted"', 'coords'=>[['title'=>'Montréal & A <B> "quoted"','data'=>"45.5,-73.5\n0,0\ninvalid",'color'=>'210 30 35','size'=>14]], 'wkt'=>[['title'=>'Drawing','data'=>'POLYGON((-70 63,-70 48,-106 48,-106 63,-70 63))','color'=>'20 40 60','border'=>1]], 'options'=>['legend'=>1,'scalebar'=>1]];
    }

    public function testApiDocumentationLoadsThroughTheProductionAutoloader(): void
    {
        [$status,$type,$body]=$this->request('/openapi.json');
        self::assertSame(200,$status,substr($body,0,300));
        self::assertStringStartsWith('application/json',$type);
        $spec=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        self::assertSame('3.0.0',$spec['openapi']);
        self::assertArrayHasKey('multipart/form-data',$spec['paths']['/api']['post']['requestBody']['content']);
        self::assertArrayHasKey('image/png',$spec['paths']['/api']['get']['responses'][200]['content']);
        [$status,$type,$body]=$this->request('/apidoc');
        self::assertSame(200,$status,substr($body,0,300));
        self::assertStringStartsWith('text/html',$type);
        self::assertStringContainsString('Remote imports accept public HTTP',$body);
    }

    public function testAllRasterDownloadsHaveRequestedBytesAndAlphaPolicy(): void
    {
        foreach (['png'=>'image/png','pnga'=>'image/png','jpg'=>'image/jpeg','tif'=>'image/tiff'] as $format=>$mime) {
            [$status,$type,$body] = $this->request('/application', $this->fixture()+['output'=>$format,'download'=>1]);
            self::assertSame(200,$status,substr($body,0,200));
            self::assertStringStartsWith($mime,$type);
            self::assertSame([600,400],array_slice(getimagesizefromstring($body),0,2));
            if ($format === 'pnga') { self::assertSame(6,ord($body[25]),'Transparent PNG must use RGBA color mode.'); }
        }
    }

    public function testPolygonOpacityIsAppliedByTheNativeRenderer(): void
    {
        $fixture = ['width'=>800,'height'=>500,'bbox_map'=>'-120,30,-40,80','output'=>'png','download'=>1, 'wkt'=>[['title'=>'Fill','data'=>'POLYGON((-70 63,-70 48,-106 48,-106 63,-70 63))','color'=>'20 40 60']]];
        [$status,,$body] = $this->request('/application', $fixture);
        self::assertSame(200,$status,substr($body,0,100));
        $image=imagecreatefromstring($body);$pixel=imagecolorat($image,400,250);
        foreach ([$pixel>>16&255,$pixel>>8&255,$pixel&255] as $i=>$channel) { self::assertEqualsWithDelta([79,94,109][$i],$channel,2,'Polygon fill must be 75 percent over a white background.'); }
        [$status,,$svg] = $this->request('/application', array_replace($fixture,['output'=>'svg']));
        self::assertSame(200,$status);preg_match_all('/fill-opacity[=:]\"?([0-9.]+)/',$svg,$matches);self::assertNotEmpty(array_filter($matches[1],static fn($value)=>abs((float)$value-0.75)<0.01));
    }

    public function testOfficePackagesHaveValidXmlAndEmbeddedMapRelationships(): void
    {
        foreach (['docx'=>['word/document.xml','word/_rels/document.xml.rels'], 'pptx'=>['ppt/slides/slide1.xml','ppt/slides/_rels/slide1.xml.rels']] as $format=>$parts) {
            [$status,,$body] = $this->request('/'.$format, $this->fixture()+['output'=>$format]);
            self::assertSame(200,$status,substr($body,0,200));
            $path=tempnam(sys_get_temp_dir(),'mappr-office-test-');
            try {
                file_put_contents($path,$body);$zip=new ZipArchive();self::assertTrue($zip->open($path,ZipArchive::CHECKCONS));
                foreach ($parts as $part) { self::assertNotFalse($zip->getFromName($part)); }
                self::assertStringContainsString('/image', $zip->getFromName($parts[1]));
                $images=0;
                for($i=0;$i<$zip->numFiles;$i++) {
                    $name=$zip->getNameIndex($i);$bytes=$zip->getFromIndex($i);
                    if (str_ends_with($name,'.xml') || str_ends_with($name,'.rels')) { self::assertNotFalse(simplexml_load_string($bytes),$name); }
                    if (str_contains($name,'/media/')) { self::assertNotFalse(getimagesizefromstring($bytes));$images++; }
                }
                self::assertGreaterThanOrEqual(1,$images);$zip->close();
            } finally { unlink($path); }
        }
    }

    public function testKmlAndGeojsonKeepZeroCoordinatesAndMixedValidDrawings(): void
    {
        $fixture=$this->fixture();$fixture['coords'][0]['data']="0,0\n45.5,-73.5\ninvalid";$fixture['wkt'][0]['data'].="\nLINESTRING(0 0,1 1)\ninvalid";
        [$status,$type,$body]=$this->request('/kml',$fixture);
        self::assertSame(200,$status,$body);self::assertStringStartsWith('application/vnd.google-earth.kml+xml;',$type);
        $xml=simplexml_load_string($body);self::assertNotFalse($xml);self::assertCount(4,$xml->Document->Placemark);
        self::assertSame('Montréal & A <B> "quoted"',(string)$xml->Document->Placemark[0]->name);
        [$status,,$body]=$this->request('/geojson',$fixture);
        self::assertSame(200,$status,$body);$json=json_decode($body,true,512,JSON_THROW_ON_ERROR);self::assertCount(4,$json['features']);self::assertCount(2,$json['rejected_rows']);
    }

    public function testWmsVersionsHonorAxisOrderAndReturnProtocolExceptions(): void
    {
        $images=[];
        foreach (['1.1.1'=>['SRS'=>'EPSG:4326','BBOX'=>'-100,30,-60,55'], '1.3.0'=>['CRS'=>'EPSG:4326','BBOX'=>'30,-100,55,-60'], 'CRS84'=>['CRS'=>'CRS:84','BBOX'=>'-100,30,-60,55']] as $version=>$crs) {
            $params=['SERVICE'=>'WMS','REQUEST'=>'GetMap','VERSION'=>$version==='CRS84'?'1.3.0':$version,'LAYERS'=>'countries','STYLES'=>'','WIDTH'=>600,'HEIGHT'=>300,'FORMAT'=>'image/png']+$crs;
            [$status,$type,$body]=$this->request('/wms?'.http_build_query($params));
            self::assertSame(200,$status,substr($body,0,400));self::assertStringStartsWith('image/png',$type);self::assertSame([600,300],array_slice(getimagesizefromstring($body),0,2));$images[]=hash('sha256',$body);
        }
        self::assertCount(1,array_unique($images),'WMS 1.1 and 1.3 axis-order fixtures must depict the same area.');
        foreach (['REQUEST=InvalidOperation','REQUEST=GetMap&WIDTH=90000','REQUEST=GetMap&WIDTH=5000&HEIGHT=5000'] as $query) {
            [$status,$type,$body]=$this->request('/wms?'.$query);self::assertSame(400,$status,$body);self::assertNotFalse(simplexml_load_string($body));self::assertStringContainsString('xml',$type);
        }
    }

    public function testWmsPostMapXmlHasTheSameImageAndDimensionLimitAsGet(): void
    {
        $xml='<sld:GetMap xmlns:sld="http://www.opengis.net/sld" xmlns:ogc="http://www.opengis.net/ogc" service="WMS" version="1.1.1"><sld:StyledLayerDescriptor version="1.0.0"><sld:NamedLayer><sld:Name>countries</sld:Name><sld:NamedStyle><sld:Name>default</sld:Name></sld:NamedStyle></sld:NamedLayer></sld:StyledLayerDescriptor><sld:BoundingBox srsName="EPSG:4326"><ogc:LowerCorner>-100 30</ogc:LowerCorner><ogc:UpperCorner>-60 55</ogc:UpperCorner></sld:BoundingBox><sld:Output><sld:Size><sld:Width>600</sld:Width><sld:Height>300</sld:Height></sld:Size><sld:Format>image/png</sld:Format><sld:Transparent>true</sld:Transparent></sld:Output></sld:GetMap>';
        [$status,$type,$body]=$this->request('/wms',null,$xml);self::assertSame(200,$status,substr($body,0,300));self::assertStringStartsWith('image/png',$type);self::assertSame([600,300],array_slice(getimagesizefromstring($body),0,2));
        [$status,$type,$body]=$this->request('/wms',null,str_replace(['<sld:Width>600','<sld:Height>300'],['<sld:Width>5000','<sld:Height>5000'],$xml));self::assertSame(400,$status,$body);self::assertStringContainsString('xml',$type);self::assertNotFalse(simplexml_load_string($body));
    }

    public function testWfsVersionsRespectCountAndDescribeTheirFeatureType(): void
    {
        foreach (['1.0.0','1.1.0','2.0.0'] as $version) {
            foreach (['GetCapabilities','DescribeFeatureType','GetFeature'] as $operation) {
                $params=['SERVICE'=>'WFS','VERSION'=>$version,'REQUEST'=>$operation, 'TYPENAME'=>'countries','TYPENAMES'=>'countries','MAXFEATURES'=>1,'COUNT'=>1];
                [$status,$type,$body]=$this->request('/wfs?'.http_build_query($params));
                self::assertSame(200,$status,substr($body,0,400));self::assertStringContainsString('xml',$type);$xml=simplexml_load_string($body);self::assertNotFalse($xml);
                if ($operation==='GetFeature') { self::assertCount(1,$xml->xpath('//*[local-name()="featureMember" or local-name()="member"]')); }
            }
        }
    }

    public function testWfsZeroCountAndServerMaximumApplyToGetAndPost(): void
    {
        [$status,,$body]=$this->request('/wfs?SERVICE=WFS&VERSION=2.0.0&REQUEST=GetFeature&TYPENAMES=countries&COUNT=0');
        self::assertSame(200,$status,substr($body,0,300));$xml=simplexml_load_string($body);self::assertNotFalse($xml);self::assertCount(0,$xml->xpath('//*[local-name()="member"]'));self::assertSame('0',(string)$xml['numberReturned']);
        $post='<wfs:GetFeature xmlns:wfs="http://www.opengis.net/wfs/2.0" service="WFS" version="2.0.0" count="0"><wfs:Query typeNames="countries"/></wfs:GetFeature>';
        [$status,,$body]=$this->request('/wfs',null,$post);self::assertSame(200,$status,substr($body,0,300));$xml=simplexml_load_string($body);self::assertNotFalse($xml);self::assertCount(0,$xml->xpath('//*[local-name()="member"]'));
        [$status,,$body]=$this->request('/wfs?SERVICE=WFS&VERSION=2.0.0&REQUEST=GetFeature&TYPENAMES=stateprovinces_polygon&COUNT=100000&PROPERTYNAME=name');
        self::assertSame(200,$status,substr($body,0,300));$xml=simplexml_load_string($body);self::assertNotFalse($xml);self::assertCount(1000,$xml->xpath('//*[local-name()="member"]'));
    }

    public function testEveryWfsVectorLayerHasStableValidFeatureAndGeometryIds(): void
    {
        $layers=array_filter(\SimpleMappr\Mappr\Mappr::getShapefileConfig(),fn(array $layer): bool=>(int)$layer['type']!==MS_LAYER_RASTER);
        self::assertNotEmpty($layers);
        foreach(array_keys($layers) as $layer) {
            $path='/wfs?'.http_build_query(['SERVICE'=>'WFS','VERSION'=>'2.0.0','REQUEST'=>'GetFeature','TYPENAMES'=>$layer,'COUNT'=>2]);
            $previous=null;
            for($sample=0;$sample<2;$sample++) {
                [$status,,$body]=$this->request($path);self::assertSame(200,$status,$layer.' '.substr($body,0,300));self::assertStringNotContainsString('WARNING:',$body,$layer);
                $xml=simplexml_load_string($body);self::assertNotFalse($xml,$layer);$members=$xml->xpath('//*[local-name()="member"]/*');
                $count=count($members);self::assertGreaterThanOrEqual(1,$count,$layer);self::assertLessThanOrEqual(2,$count,$layer);
                $ids=[];
                foreach($members as $feature) { $id=(string)$feature->attributes('http://www.opengis.net/gml/3.2')->id;self::assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_.-]*$/D',$id,$layer);$ids[]=$id; }
                self::assertCount($count,array_unique($ids),$layer);if($previous!==null){self::assertSame($previous,$ids,$layer);}$previous=$ids;
                foreach($xml->xpath('//@*[local-name()="id"]') as $id) { self::assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_.-]*$/D',(string)$id,$layer); }
            }
        }
    }

    public function testApiGraticulesAndMidpointZoomHaveVisibleEffects(): void
    {
        $hashes=[];
        foreach([[],['graticules'=>1],['zoom'=>5]] as $changes) {
            [$status,,$body]=$this->request('/api?'.http_build_query($changes+['points'=>['45,-73'],'width'=>600,'height'=>300]));self::assertSame(200,$status,substr($body,0,300));self::assertNotFalse(imagecreatefromstring($body));$hashes[]=hash('sha256',$body);
        }
        self::assertCount(3,array_unique($hashes));
    }

    public function testOgcPostXmlEntitiesAndUnboundedDimensionsFailAsXml(): void
    {
        foreach (['<!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><a>&x;</a>', '<GetMap width="5000" height="5000"/>'] as $xml) {
            [$status,$type,$body]=$this->request('/wms',null,$xml);self::assertSame(400,$status,$body);self::assertStringContainsString('xml',$type);self::assertNotFalse(simplexml_load_string($body));self::assertStringNotContainsString('root:',$body);
        }
        $xml='<wfs:GetFeature xmlns:wfs="http://www.opengis.net/wfs/2.0" service="WFS" version="2.0.0" count="1"><wfs:Query typeNames="countries"/></wfs:GetFeature>';
        [$status,,$body]=$this->request('/wfs',null,$xml);self::assertSame(200,$status,substr($body,0,400));$xml=simplexml_load_string($body);self::assertNotFalse($xml);self::assertCount(1,$xml->xpath('//*[local-name()="member"]'));
    }

    public function testCoordinateUniquenessKeepsPairsThatHaveTheSameConcatenatedDigits(): void
    {
        [$status,,$body]=$this->request('/api?'.http_build_query(['bbox'=>'0,0,15,30','width'=>450,'height'=>900,'points'=>["23,1\n3,12"],'color'=>['210,30,35'],'size'=>[14]]));
        self::assertSame(200,$status,substr($body,0,200));$image=imagecreatefromstring($body);
        foreach ([[30,210],[360,810]] as [$x,$y]) {
            $pixel=imagecolorat($image,$x,$y);self::assertGreaterThan(180,$pixel>>16&255);self::assertLessThan(80,$pixel>>8&255);self::assertLessThan(80,$pixel&255);
        }
    }

    public function testLegacyApiGroupLabelsAndMixedPointRowsStillWork(): void
    {
        [$status,,$body]=$this->request('/api?'.http_build_query(['points'=>["45.5,-73.5\ninvalid",'0,0'],'legend'=>['Montréal & A','Origin'],'color'=>['200,0,0','0,0,200'],'width'=>600,'height'=>300]));
        self::assertSame(200,$status,substr($body,0,400));self::assertSame([600,300],array_slice(getimagesizefromstring($body),0,2));
        foreach (['file:///etc/passwd',ROOT.'/public/uploads/../config/defaults.php','http://127.0.0.1/_runtime','https://[::1]/'] as $url) {
            [$status,,$body]=$this->request('/api?'.http_build_query(['url'=>$url]));self::assertSame(400,$status,$body);self::assertArrayHasKey('error',json_decode($body,true));
        }
    }

    public function testRejectedLocalImportNeverDeletesAnExistingForeignFile(): void
    {
        $path=tempnam(ROOT.'/public/uploads','mappr-foreign-test-');
        self::assertNotFalse($path);
        $bytes="This file belongs to another request.\n";
        file_put_contents($path,$bytes);
        try {
            foreach (['file'=>$path,'url'=>'file://'.$path] as $parameter=>$value) {
                [$status,,$body]=$this->request('/api?'.http_build_query([$parameter=>$value]));
                self::assertSame(400,$status,$body);
                self::assertSame($bytes,file_get_contents($path));
            }
        } finally { if(is_file($path)){unlink($path);} }
    }
}
