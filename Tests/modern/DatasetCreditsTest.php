<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\DatasetCredits;

final class DatasetCreditsTest extends TestCase
{
    public function testCreditsFollowOnlyTheirBackgroundLayersAndDeduplicateLabels(): void
    {
        self::assertSame([],DatasetCredits::selected(['countries','blueMarble']));
        self::assertSame(['ecoregions'],array_keys(DatasetCredits::selected(['ecoregions','ecoregionLabels'])));
        self::assertSame(['hotspots'],array_keys(DatasetCredits::selected(['hotspotLabels'])));
        self::assertCount(2,DatasetCredits::selected(['ecoregions','ecoregionLabels','conservation','hotspotLabels']));
        $lines=DatasetCredits::lines(DatasetCredits::DATASETS,600);
        $notice=implode(' ',$lines);
        self::assertStringContainsString('CC BY 4.0',$notice);
        self::assertStringContainsString('CC BY-SA 4.0',$notice);
        self::assertStringContainsString('hotspots filtered',$notice);
        self::assertStringContainsString('/data-credits',$notice);
        foreach ($lines as $line) { self::assertLessThanOrEqual(97,strlen($line)); }
    }

    public function testEcoregionStyleHasUniqueNumericIdentifiersAndExplicitColors(): void
    {
        $xml=simplexml_load_file(ROOT.'/config/ecoregions-2017.sld');
        $xml->registerXPathNamespace('sld','http://www.opengis.net/sld');
        $rules=$xml->xpath('//sld:Rule');self::assertCount(847,$rules);
        $identifiers=[];
        foreach ($rules as $rule) {
            $identifier=(string)$rule->xpath('.//ogc:Literal')[0];
            self::assertMatchesRegularExpression('/^[0-9]+$/D',$identifier);
            $identifiers[]=$identifier;
            self::assertMatchesRegularExpression('/^#[A-Fa-f0-9]{6}$/D',(string)$rule->xpath('.//sld:CssParameter[@name="fill"]')[0]);
        }
        self::assertCount(847,array_unique($identifiers));
        self::assertContains('0',$identifiers);
    }
}
