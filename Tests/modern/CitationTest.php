<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use SimpleMappr\CitationDocument;
use SimpleMappr\HttpException;
use SimpleMappr\SiteUrl;
use SimpleMappr\Controller\CitationFeed;

final class CitationTest extends TestCase
{
    private function fixture(): array { return ['year'=>'2026','reference'=>'Montréal & A <B> 😀','first_author_surname'=>"D'Arcy",'doi'=>'10.1234/Example.1','link'=>'https://example.org/?a=1&b=2']; }
    public function testUnicodeTextAndOptionalFieldsRoundtrip(): void
    {
        $source=$this->fixture();$result=CitationDocument::parse($source);
        self::assertSame($source['reference'],$result['reference']);self::assertSame(2026,$result['year']);
        unset($source['doi'],$source['link']);$result=CitationDocument::parse($source);self::assertSame('',$result['doi']);self::assertSame('',$result['link']);
    }
    public static function malformed(): array
    {
        return array_map(static fn($value)=>[$value],[['year'=>'2026x'],['year'=>[]],['reference'=>''],['reference'=>str_repeat('a',16385)],['first_author_surname'=>str_repeat('a',256)],['doi'=>'bad'],['link'=>'javascript:alert(1)'],['link'=>'https://user@example.org'],['link'=>"https://example.org/\n"],['created'=>0],['id'=>'1'],['id'=>[]],['reference'=>"bad\xff"]]);
    }
    #[DataProvider('malformed')]
    public function testMalformedCitationIsRejectedAtBoundary(array $change): void
    {
        $this->expectException(HttpException::class);CitationDocument::parse(array_replace($this->fixture(),$change));
    }
    public function testIdentifierCannotBeChangedByPayload(): void
    {
        self::assertArrayNotHasKey('id',CitationDocument::parse($this->fixture()+['id'=>'12'],12));
        $this->expectException(HttpException::class);CitationDocument::parse($this->fixture()+['id'=>'13'],12);
    }
    public function testSiteOriginSupportsDefaultRelativeHttpsAndIpv6(): void
    {
        self::assertSame('http://localhost:8080',SiteUrl::base(['HTTP_HOST'=>'localhost:8080'],''));
        self::assertSame('https://example.org/maps',SiteUrl::base(['HTTP_HOST'=>'example.org','HTTPS'=>'on'],'/maps'));
        self::assertSame('http://[::1]:8080',SiteUrl::base(['HTTP_HOST'=>'[::1]:8080'],''));
        self::assertSame('https://example.org/maps',SiteUrl::base([], 'https://example.org/maps/'));
        $this->expectException(RuntimeException::class);SiteUrl::base([],'https://user@example.org');
    }
    public function testInvalidRequestHostIsRejected(): void
    {
        $this->expectException(HttpException::class);SiteUrl::base(['HTTP_HOST'=>"evil\r\nLocation: attack"],'');
    }
    public function testFeedKeepsRecentApprovedRecordsAndEscapesText(): void
    {
        $old=$_SERVER;$_SERVER['HTTP_HOST']='localhost:8080';
        try {
            $recent=(object)($this->fixture()+['id'=>1,'created'=>time()-10]);
            $oldEntry=clone $recent;$oldEntry->id=2;$oldEntry->created=time()-604801;
            $missing=clone $recent;$missing->id=3;$missing->doi='';$missing->link='';
            $unsafe=clone $recent;$unsafe->id=4;$unsafe->doi='';$unsafe->link='javascript:alert(1)';
            $feed=(string)(new CitationFeed())->makeChannel()->addItems([$recent,$oldEntry,$missing,$unsafe])->getFeed();
            $xml=simplexml_load_string($feed);self::assertNotFalse($xml);self::assertCount(1,$xml->channel->item);
            self::assertSame($recent->reference,(string)$xml->channel->item->title);
            self::assertSame('https://doi.org/10.1234/Example.1',(string)$xml->channel->item->link);
            self::assertSame('http://localhost:8080',(string)$xml->channel->link);
            self::assertStringNotContainsString('pubsubhubbub',$feed);
        } finally { $_SERVER=$old; }
    }
}
