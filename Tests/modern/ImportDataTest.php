<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\ImportData;
use SimpleMappr\GeometryExport;
use SimpleMappr\SavedMapDocument;

final class ImportDataTest extends TestCase
{
    public function testPublishedCrOnlyExamplesPreserveEveryGroupAndRow(): void
    {
        foreach (['demo.txt'=>[3,13], 'demo2.csv'=>[4,9]] as $file=>[$groups,$rows]) {
            $result = ImportData::parse(file_get_contents(ROOT.'/public/files/'.$file));
            self::assertCount($groups, $result['coords']);
            self::assertSame($rows, array_sum(array_map(static fn($g) => count(explode("\n", $g['data'])), $result['coords'])));
        }
    }

    public function testQuotedUtf8CsvAndUtf16BomKeepNamesAndCoordinates(): void
    {
        $text = "\xef\xbb\xbf\"Montréal, Québec\",45.5,-73.5\r\n\"Montréal, Québec\",invalid,200\r\n\"O\"\"Brien\",0,0\r\n";
        $result = ImportData::parse($text);
        self::assertSame('Montréal, Québec', $result['coords'][0]['title']);
        self::assertSame("45.5,-73.5\ninvalid,200", $result['coords'][0]['data']);
        self::assertSame('O"Brien', $result['coords'][1]['title']);
        $utf16 = ImportData::parse("\xff\xfe".mb_convert_encoding("Montréal,45.5,-73.5", 'UTF-16LE', 'UTF-8'));
        self::assertSame('Montréal', $utf16['coords'][0]['title']);
    }

    public function testGeometryImportsPreserveValidRowsAndRejectInvalidRows(): void
    {
        $parsed = ImportData::parse("POINT (0 0)\ninvalid\nLINESTRING (0 0, 1 1)", 'wkt');
        self::assertSame('0,0', $parsed['coords'][0]['data']);
        self::assertStringStartsWith('LINESTRING', $parsed['wkt'][0]['data']);
        self::assertSame(['invalid'], $parsed['bad_drawings']);
        $json = ImportData::parse('{"type":"FeatureCollection","features":[{"type":"Feature","properties":{"title":"Montréal"},"geometry":{"type":"Point","coordinates":[-73.5,45.5]}}]}');
        self::assertSame('Montréal', $json['coords'][0]['title']);
        foreach (['kml.kml','kml2.kml'] as $file) {
            $kml = ImportData::parse(file_get_contents(ROOT.'/Tests/files/'.$file));
            self::assertNotEmpty($kml['coords']);
        }
    }

    public function testMalformedInputsFailAtTheImportBoundary(): void
    {
        foreach (["bad\0data", "bad\xfftext", '"missing closing quote,12,34', '<!DOCTYPE root [<!ENTITY x SYSTEM "file:///etc/passwd">]><root>&x;</root>', '{bad json'] as $text) {
            try { ImportData::parse($text); self::fail('Malformed input was accepted.'); }
            catch (InvalidArgumentException|JsonException $e) { self::assertNotEmpty($e->getMessage()); }
        }
    }

    public function testGeometryGroupLimitNeverReturnsRejectedFeaturesAsAcceptedData(): void
    {
        $features=[];
        for($i=0;$i<MAXNUMTEXTAREA+2;$i++) { $features[]=['type'=>'Feature','properties'=>['title'=>'Group '.$i],'geometry'=>['type'=>'Point','coordinates'=>[$i,0]]]; }
        $result=ImportData::parse(json_encode(['type'=>'FeatureCollection','features'=>$features]));
        self::assertCount(MAXNUMTEXTAREA,$result['coords']);self::assertCount(2,$result['bad_drawings']);
    }

    public function testUtf16XmlCannotHideAnInternalEntity(): void
    {
        $xml=mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE root [<!ENTITY x "hello">]><root>&x;</root>', 'UTF-16', 'UTF-8');
        $this->expectException(InvalidArgumentException::class);
        ImportData::xml($xml);
    }

    public function testSettingsDocumentAndGeometryExportHaveDifferentContracts(): void
    {
        $document = SavedMapDocument::fromForm(['width'=>600,'height'=>400,'layers'=>['countries'=>'on'], 'coords'=>[['title'=>'Montréal & A <B> "quoted"','data'=>"0,0\ninvalid\n45.5,-73.5"]], 'wkt'=>[['title'=>'Drawing','data'=>"LINESTRING (0 0, 1 1)\ninvalid\nPOLYGON ((0 0, 1 0, 1 1, 0 0))",'color'=>'20 40 60']]]);
        $import = ImportData::parse(SavedMapDocument::encode($document));
        self::assertSame($document, $import['document']);
        $features = GeometryExport::features($document);
        self::assertCount(4, $features['features']);
        self::assertSame([0.0,0.0], $features['features'][0]['geometry']['coordinates']);
        self::assertCount(2, $features['rejected_rows']);
        $xml = simplexml_load_string(GeometryExport::kml($document));
        self::assertNotFalse($xml);
        self::assertCount(4, $xml->Document->Placemark);
        self::assertSame('Montréal & A <B> "quoted"', (string)$xml->Document->Placemark[0]->name);
    }
}
