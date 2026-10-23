<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\AssetProfiles;

final class EcologyHttpTest extends TestCase
{
    protected function setUp(): void
    {
        if (AssetProfiles::selected()['name']!=='full-map-v1') { self::markTestSkipped('Requires the installed full-map-v1 ecological profile.'); }
    }

    private function request(string $path,array|string|null $data=null): array
    {
        $base=rtrim(getenv('SIMPLEMAPPR_TEST_URL') ?: 'http://127.0.0.1','/');$headers=[];
        $curl=curl_init($base.$path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HEADERFUNCTION=>function($curl,$line)use(&$headers){$headers[]=$line;return strlen($line);}]);
        if (is_array($data)) { curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]); }
        elseif (is_string($data)) { curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_HTTPHEADER=>['Content-Type: application/xml']]); }
        $body=curl_exec($curl);self::assertNotFalse($body,curl_error($curl));
        return [curl_getinfo($curl,CURLINFO_RESPONSE_CODE),$body,implode('',$headers)];
    }

    public function testHotspotBoundarySurvivesKvpAndXmlFeatureRequests(): void
    {
        $xml='<wfs:GetFeature xmlns:wfs="http://www.opengis.net/wfs/2.0" service="WFS" version="2.0.0" count="1000"><wfs:Query typeNames="conservation"/></wfs:GetFeature>';
        foreach ([['/wfs?SERVICE=WFS&VERSION=2.0.0&REQUEST=GetFeature&TYPENAMES=conservation&COUNT=1000',null],['/wfs',$xml]] as [$path,$post]) {
            [$status,$body,$headers]=$this->request($path,$post);self::assertSame(200,$status,substr($body,0,300));
            $document=simplexml_load_string($body);self::assertNotFalse($document);
            self::assertCount(36,$document->xpath('//*[local-name()="member"]'));
            $types=$document->xpath('//*[local-name()="Type"]');self::assertCount(36,$types);
            foreach ($types as $type) { self::assertSame('hotspot area',(string)$type); }
            self::assertStringContainsString('/data-credits',$headers);
            self::assertStringContainsString('https://creativecommons.org/licenses/by-sa/4.0/',$headers);
            self::assertStringNotContainsString('outer limit',$body);
            self::assertCount(36,$document->xpath('//*[local-name()="mappr_license"]'));
            self::assertCount(36,$document->xpath('//*[local-name()="mappr_source"]'));
        }
    }

    public function testHotspotSqlBoundaryAndLicenseSurviveClientFiltersAndPropertySelection(): void
    {
        $filter='<fes:Filter xmlns:fes="http://www.opengis.net/fes/2.0"><fes:PropertyIsEqualTo><fes:ValueReference>Type</fes:ValueReference><fes:Literal>outer limit</fes:Literal></fes:PropertyIsEqualTo></fes:Filter>';
        $query=['SERVICE'=>'WFS','VERSION'=>'2.0.0','REQUEST'=>'GetFeature','TYPENAMES'=>'conservation','COUNT'=>1000,'FILTER'=>$filter];
        [$status,$body]=$this->request('/wfs?'.http_build_query($query));self::assertSame(200,$status,substr($body,0,300));
        self::assertCount(0,simplexml_load_string($body)->xpath('//*[local-name()="member"]'));
        foreach (['outer limit'=>0,'nonexistent type'=>36] as $value=>$expected) {
            $xml='<wfs:GetFeature xmlns:wfs="http://www.opengis.net/wfs/2.0" xmlns:fes="http://www.opengis.net/fes/2.0" service="WFS" version="2.0.0" count="1000"><wfs:Query typeNames="conservation"><fes:Filter><fes:PropertyIsEqualTo><fes:ValueReference>Type</fes:ValueReference><fes:Literal>'.$value.'</fes:Literal></fes:PropertyIsEqualTo></fes:Filter></wfs:Query></wfs:GetFeature>';
            if ($expected===36) { $xml=str_replace('PropertyIsEqualTo','PropertyIsNotEqualTo',$xml); }
            [$status,$body]=$this->request('/wfs',$xml);self::assertSame(200,$status,substr($body,0,300));self::assertCount($expected,simplexml_load_string($body)->xpath('//*[local-name()="member"]'));
        }
        unset($query['FILTER']);$query['PROPERTYNAME']='NAME';$query['COUNT']=1;
        [$status,$body,$headers]=$this->request('/wfs?'.http_build_query($query));self::assertSame(200,$status);self::assertStringContainsString('rel="license"',$headers);self::assertStringContainsString('/data-credits',$headers);
        self::assertCount(1,simplexml_load_string($body)->xpath('//*[local-name()="member"]'));
    }

    public function testEcoregionServicesRetainAccentedSourceNames(): void
    {
        [$status,$body]=$this->request('/wfs?SERVICE=WFS&VERSION=2.0.0&REQUEST=GetFeature&TYPENAMES=ecoregions&COUNT=1000&PROPERTYNAME=ECO_NAME');self::assertSame(200,$status);
        self::assertStringContainsString('Alto Paraná Atlantic forests',$body);
        self::assertStringContainsString('Chocó-Darién moist forests',$body);
        self::assertCount(847,simplexml_load_string($body)->xpath('//*[local-name()="member"]'));
    }

    public function testEcologicalPublicationExportsKeepCreditsWithoutOptionalLegendOrWatermark(): void
    {
        $fixture=['width'=>600,'height'=>300,'bbox_map'=>'-180,-90,180,90','layers'=>['ecoregions'=>'on','conservation'=>'on'],'options'=>['legend'=>0,'scalebar'=>0],'watermark'=>0,'download'=>1];
        foreach (['png','tif','svg','docx','pptx'] as $format) {
            $path=in_array($format,['docx','pptx'],true)?'/'.$format:'/application';
            [$status,$body,$headers]=$this->request($path,$fixture+['output'=>$format]);self::assertSame(200,$status,$format.' '.substr($body,0,200));
            self::assertStringContainsString('/data-credits',$headers,$format);self::assertStringContainsString('/licenses/by-sa/4.0/',$headers,$format);
            if ($format==='svg') { self::assertSame('svg',simplexml_load_string($body,SimpleXMLElement::class,LIBXML_NONET|LIBXML_PARSEHUGE)->getName()); }
            elseif (in_array($format,['png','tif'],true)) { self::assertSame([600,300],array_slice(getimagesizefromstring($body),0,2),$format); }
            else {
                $temporary=tempnam(sys_get_temp_dir(),'mappr-eco-office-');file_put_contents($temporary,$body);
                try {
                    $zip=new ZipArchive();self::assertTrue($zip->open($temporary,ZipArchive::CHECKCONS),$format);$images=0;
                    for ($index=0;$index<$zip->numFiles;$index++) { $name=$zip->getNameIndex($index);if (str_contains($name,'/media/') && str_ends_with($name,'.png')) { $dimensions=getimagesizefromstring($zip->getFromIndex($index));if ($dimensions && $dimensions[0]===600 && $dimensions[1]===300) { $images++; } } }
                    self::assertGreaterThanOrEqual(1,$images,$format);$zip->close();
                } finally { unlink($temporary); }
            }
        }
        [$status,$body]=$this->request('/application',$fixture+['output'=>'png','download_factor'=>3]);self::assertSame(200,$status);self::assertSame([1800,900],array_slice(getimagesizefromstring($body),0,2));
        $geometry=$fixture+['coords'=>[['title'=>'Independent point','data'=>'45,-75','color'=>'200 30 30','size'=>12]]];
        [$status,$body,$headers]=$this->request('/geojson',$geometry);self::assertSame(200,$status);self::assertCount(1,json_decode($body,true)['features']);self::assertStringNotContainsString('rel="license"',$headers);
    }

    public function testServicesDescribeEcologyLicensesAndExcludeRasterFeatureTypes(): void
    {
        foreach (['wms','wfs'] as $service) {
            [$status,$body]=$this->request('/'.$service.'?SERVICE='.strtoupper($service).'&REQUEST=GetCapabilities');
            self::assertSame(200,$status);self::assertStringContainsString('CC BY-SA 4.0',$body);self::assertStringContainsString('CC BY 4.0',$body);self::assertStringContainsString('/data-credits',$body);
            if ($service==='wfs') {
                $document=simplexml_load_string($body);
                foreach ($document->xpath('//*[local-name()="FeatureType"]/*[local-name()="Name"]') as $name) { self::assertNotContains(preg_replace('/^.*:/','',(string)$name),['relief','reliefalt','reliefgrey','blueMarble']); }
            }
        }
        [$status,$body]=$this->request('/data-credits');self::assertSame(200,$status);self::assertStringContainsString('17 outer-limit records omitted',$body);self::assertStringContainsString('independent coordinates',$body);
    }

    public function testWmsRootLayerGroupCannotBypassEcologicalCredits(): void
    {
        foreach (['simplemappr','SIMPLEMAPPR'] as $name) {
            $query=['SERVICE'=>'WMS','VERSION'=>'1.1.1','REQUEST'=>'GetMap','LAYERS'=>$name,'SRS'=>'EPSG:4326','BBOX'=>'-180,-90,180,90','WIDTH'=>120,'HEIGHT'=>80,'FORMAT'=>'image/png'];
            $xml='<sld:GetMap xmlns:sld="http://www.opengis.net/sld" xmlns:ogc="http://www.opengis.net/ogc" service="WMS" version="1.1.1"><sld:StyledLayerDescriptor version="1.0.0"><sld:NamedLayer><sld:Name>'.$name.'</sld:Name><sld:NamedStyle><sld:Name>default</sld:Name></sld:NamedStyle></sld:NamedLayer></sld:StyledLayerDescriptor><sld:BoundingBox srsName="EPSG:4326"><ogc:LowerCorner>-180 -90</ogc:LowerCorner><ogc:UpperCorner>180 90</ogc:UpperCorner></sld:BoundingBox><sld:Output><sld:Size><sld:Width>120</sld:Width><sld:Height>80</sld:Height></sld:Size><sld:Format>image/png</sld:Format></sld:Output></sld:GetMap>';
            foreach ([['/wms?'.http_build_query($query),null],['/wms',$xml]] as [$path,$post]) {
                [$status,$body,$headers]=$this->request($path,$post);self::assertSame(400,$status,$name);self::assertStringContainsString('data credits',$body);self::assertStringContainsString('xml',$headers);
            }
            foreach ([256,600] as $width) {
                $query['WIDTH']=$width;$query['HEIGHT']=$width;
                $positiveXml=str_replace(['<sld:Width>120','<sld:Height>80'],['<sld:Width>'.$width,'<sld:Height>'.$width],$xml);
                foreach ([['/wms?'.http_build_query($query),null],['/wms',$positiveXml]] as [$path,$post]) {
                    [$status,$body,$headers]=$this->request($path,$post);self::assertSame(200,$status,$name);self::assertSame([$width,$width],array_slice(getimagesizefromstring($body),0,2));self::assertStringContainsString('/licenses/by-sa/4.0/',$headers);self::assertStringContainsString('/licenses/by/4.0/',$headers);
                }
            }
        }
        foreach (['all','"simplemappr"'] as $name) {
            $query=['SERVICE'=>'WMS','VERSION'=>'1.1.1','REQUEST'=>'GetMap','LAYERS'=>$name,'SRS'=>'EPSG:4326','BBOX'=>'-180,-90,180,90','WIDTH'=>120,'HEIGHT'=>80,'FORMAT'=>'image/png'];
            [$status,$body]=$this->request('/wms?'.http_build_query($query));self::assertSame(400,$status,$name);self::assertNotFalse(simplexml_load_string($body));
        }
    }

    public function testOwsLayerCasingCannotBypassRequiredCredits(): void
    {
        foreach (['CONSERVATION','Conservation','ECOREGIONS','Ecoregions'] as $name) {
            $parameters=['SERVICE'=>'WMS','VERSION'=>'1.1.1','REQUEST'=>'GetMap','LAYERS'=>$name,'SRS'=>'EPSG:4326','BBOX'=>'-180,-90,180,90','WIDTH'=>120,'HEIGHT'=>80,'FORMAT'=>'image/png'];
            $xml='<sld:GetMap xmlns:sld="http://www.opengis.net/sld" xmlns:ogc="http://www.opengis.net/ogc" service="WMS" version="1.1.1"><sld:StyledLayerDescriptor version="1.0.0"><sld:NamedLayer><sld:Name>'.$name.'</sld:Name><sld:NamedStyle><sld:Name>default</sld:Name></sld:NamedStyle></sld:NamedLayer></sld:StyledLayerDescriptor><sld:BoundingBox srsName="EPSG:4326"><ogc:LowerCorner>-180 -90</ogc:LowerCorner><ogc:UpperCorner>180 90</ogc:UpperCorner></sld:BoundingBox><sld:Output><sld:Size><sld:Width>120</sld:Width><sld:Height>80</sld:Height></sld:Size><sld:Format>image/png</sld:Format></sld:Output></sld:GetMap>';
            foreach ([['/wms?'.http_build_query($parameters),null],['/wms',$xml]] as [$path,$post]) {
                [$status,$body,$headers]=$this->request($path,$post);self::assertSame(400,$status,$name);self::assertStringContainsString('xml',$headers,$name);self::assertStringContainsString('data credits',$body,$name);
            }
            $parameters['WIDTH']=256;$parameters['HEIGHT']=256;
            [$status,$body,$headers]=$this->request('/wms?'.http_build_query($parameters));self::assertSame(200,$status,$name);self::assertNotFalse(imagecreatefromstring($body));self::assertStringContainsString('rel="license"',$headers,$name);self::assertStringContainsString('/data-credits',$headers,$name);
            $query=['SERVICE'=>'WFS','VERSION'=>'2.0.0','REQUEST'=>'GetFeature','TYPENAMES'=>$name,'COUNT'=>1,'PROPERTYNAME'=>'NAME'];
            $property=strtolower($name)==='ecoregions'?'ECO_NAME':'NAME';$query['PROPERTYNAME']=$property;
            $featureXml='<wfs:GetFeature xmlns:wfs="http://www.opengis.net/wfs/2.0" service="WFS" version="2.0.0" count="1"><wfs:Query typeNames="'.$name.'"><wfs:PropertyName>'.$property.'</wfs:PropertyName></wfs:Query></wfs:GetFeature>';
            foreach ([['/wfs?'.http_build_query($query),null],['/wfs',$featureXml]] as [$path,$post]) {
                [$status,$body,$headers]=$this->request($path,$post);self::assertSame(200,$status,$name.' '.substr($body,0,200));self::assertStringContainsString('rel="license"',$headers,$name);self::assertStringContainsString('/data-credits',$headers,$name);self::assertCount(1,simplexml_load_string($body)->xpath('//*[local-name()="member"]'));
            }
        }
    }

    public function testEcologicalMapsKeepAReadableMinimumAndOrdinaryMapsKeepTheirExistingRange(): void
    {
        $parameters=['SERVICE'=>'WMS','VERSION'=>'1.1.1','REQUEST'=>'GetMap','LAYERS'=>'conservation','SRS'=>'EPSG:4326','BBOX'=>'-180,-90,180,90','WIDTH'=>120,'HEIGHT'=>80,'FORMAT'=>'image/png'];
        [$status,$body,$headers]=$this->request('/wms?'.http_build_query($parameters));self::assertSame(400,$status);self::assertStringContainsString('xml',$headers);self::assertStringContainsString('data credits',$body);
        $parameters['WIDTH']=256;$parameters['HEIGHT']=256;
        foreach (['conservation','ecoregions','conservation,ecoregions'] as $layers) {
            $parameters['LAYERS']=$layers;[$status,$body]=$this->request('/wms?'.http_build_query($parameters));self::assertSame(200,$status,substr($body,0,200));self::assertSame([256,256],array_slice(getimagesizefromstring($body),0,2));
        }
        $parameters['WIDTH']=120;$parameters['HEIGHT']=80;
        $parameters['LAYERS']='countries';[$status,$body,$headers]=$this->request('/wms?'.http_build_query($parameters));self::assertSame(200,$status);self::assertNotFalse(imagecreatefromstring($body));self::assertStringNotContainsString('rel="license"',$headers);
    }
}
