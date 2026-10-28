<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\SavedMapDocument;

final class SavedMapDocumentTest extends TestCase
{
    public function testRetiredBlueMarbleSelectionDoesNotPreventLoadingOlderMaps(): void
    {
        $source=['coords'=>[['title'=>'Montréal','data'=>'45.5,-73.5']], 'layers'=>['countries'=>'on','blueMarble'=>'on']];
        $document=SavedMapDocument::decode(json_encode($source));
        self::assertSame(['countries'=>'on'],$document['layers']);
        self::assertSame($source['coords'],$document['coords']);
        SavedMapDocument::validateForWrite($document);
    }

    public function testSettingsRoundtripPreservesUnicodeDataAndSourceViewport(): void
    {
        $source = ['save'=>['title'=>'Montréal 😀'], 'coords'=>[['title'=>'Café','data'=>"43.6,-79.3\n45.5,-73.5",'color'=>'210 30 35']], 'projection'=>'epsg:4326', 'projection_map'=>'epsg:4326', 'origin_map'=>'-100', 'bbox_map'=>'-180,-90,180,90', 'bbox_rubberband'=>[], 'layers'=>['countries'=>'on','lakesOutline'=>'on','lakes'=>'on','stateprovinces'=>'on','countrynames'=>'on','stateprovnames'=>'on','grid'=>'on']];
        $document=SavedMapDocument::fromForm($source);
        self::assertSame(1,$document['schema_version']);
        self::assertSame($source['coords'],$document['coords']);
        self::assertSame('-100',$document['origin_map']);
        self::assertSame($source['layers'],$document['layers']);
        self::assertSame('',$document['bbox_rubberband']);
        self::assertSame($document,SavedMapDocument::decode(SavedMapDocument::encode($document)));
    }
    public function testUnversionedEnvelopeLoadsWithoutRendererPropertyInjection(): void
    {
        $document=SavedMapDocument::decode(json_encode(['map'=>['projection'=>'epsg:4326','map_obj'=>'malicious','uid'=>12,'save'=>['title'=>'Legacy']]]));
        self::assertArrayNotHasKey('map_obj',$document);
        self::assertArrayNotHasKey('uid',$document);
        self::assertArrayNotHasKey('schema_version',SavedMapDocument::renderingFields($document));
        self::assertSame('Legacy',$document['save']['title']);
    }
    public function testBlankUiControlsUseRenderingDefaultsAndZeroOriginSurvives(): void
    {
        $document=SavedMapDocument::fromForm(['projection'=>'epsg:4326','projection_map'=>'epsg:4326','origin'=>'','origin_map'=>'','width'=>'','height'=>'','rotation'=>'','border_thickness'=>'','bbox_rubberband'=>[]]);
        SavedMapDocument::validateForWrite($document);
        $request=\SimpleMappr\Request::fromDocument(SavedMapDocument::renderingFields($document));
        self::assertFalse($request->origin);
        self::assertFalse($request->origin_map);
        self::assertSame(900,$request->width);
        self::assertSame(450.0,$request->height);
        self::assertSame('0',\SimpleMappr\Request::fromDocument(['origin'=>'0','origin_map'=>'0'])->origin_map);
    }
    public function testNewWritesRejectInvalidRenderingSettings(): void
    {
        $document=SavedMapDocument::fromForm(['projection'=>'epsg:4326','width'=>'999999']);
        $this->expectException(InvalidArgumentException::class);
        SavedMapDocument::validateForWrite($document);
    }
    public function testLineThicknessAcceptsTheSliderRangeAndTheDefault(): void
    {
        foreach (['', '0', '0.5', '1.25', '2'] as $value) {
            SavedMapDocument::validateForWrite(SavedMapDocument::fromForm(['projection'=>'epsg:4326','border_thickness'=>$value]));
        }
        foreach (['0.25', '2.5', '-1'] as $value) {
            try {
                SavedMapDocument::validateForWrite(SavedMapDocument::fromForm(['projection'=>'epsg:4326','border_thickness'=>$value]));
                self::fail('Accepted line thickness '.$value);
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('Line thickness', $e->getMessage());
            }
        }
    }
    public function testNewWritesRejectUnsupportedProjections(): void
    {
        $document=SavedMapDocument::fromForm(['projection'=>'invalid']);
        $this->expectException(InvalidArgumentException::class);
        SavedMapDocument::validateForWrite($document);
    }
    public function testGeoJsonIsRejectedAsSettings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SavedMapDocument::decode('{"type":"FeatureCollection","features":[]}');
    }
    public function testUnknownVersionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SavedMapDocument::decode('{"schema_version":2,"projection":"epsg:4326"}');
    }
    public function testNestedRendererFieldIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SavedMapDocument::fromForm(['projection'=>['epsg:4326']]);
    }
    public function testTooManyDataGroupsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SavedMapDocument::fromForm(['coords'=>array_fill(0,MAXNUMTEXTAREA+1,['data'=>'0,0'])]);
    }
}
