<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\RemoteImport;

final class RemoteImportTest extends TestCase
{
    public function testPrivateSpecialAndAmbiguousAddressesAreRejected(): void
    {
        foreach (['127.0.0.1','10.0.0.1','172.16.0.1','192.168.0.1','169.254.169.254','100.100.100.100','0.0.0.0','192.0.2.1','198.18.0.1','224.0.0.1','::1','fc00::1','fe80::1','::ffff:127.0.0.1','64:ff9b::a00:1','2002:7f00:1::','2001::1'] as $ip) {
            self::assertFalse(RemoteImport::publicAddress($ip), $ip);
        }
        foreach (['8.8.8.8','1.1.1.1','2606:4700:4700::1111','2001:4860:4860::8888'] as $ip) { self::assertTrue(RemoteImport::publicAddress($ip), $ip); }
        foreach (['file:///etc/passwd','php://input','http://user:pass@example.org','http://example.org:8080/a','https://example.org/#fragment',"http://example.org/\r\nX:foo",'http://example.org\\@127.0.0.1/','http:///a','http://','http://2130706433/','http://0177.0.0.1/','http://127.1/','http://0x7f000001/','http://0x7f.0.0.1/','http://0x7f.1/','http://0x08080808/'] as $url) {
            try { RemoteImport::target($url); self::fail('Accepted '.$url); }
            catch (InvalidArgumentException $e) { self::assertNotEmpty($e->getMessage()); }
        }
    }

    public function testEachRedirectAndEveryDnsAddressAreValidated(): void
    {
        $client = new ImportTransportDouble(['public.test'=>['8.8.8.8']], [['status'=>302,'location'=>'/next','body'=>''], ['status'=>200,'location'=>'','body'=>'45,-75']]);
        self::assertSame('45,-75', $client->fetch('https://public.test/data'));
        self::assertSame(['https://public.test/data','https://public.test/next'], array_column($client->requests, 'url'));
        self::assertSame(['8.8.8.8','8.8.8.8'], array_column($client->requests, 'ip'));
        foreach ([
            new ImportTransportDouble(['public.test'=>['8.8.8.8'],'private.test'=>['127.0.0.1']], [['status'=>302,'location'=>'https://private.test/secret','body'=>'']]),
            new ImportTransportDouble(['public.test'=>['8.8.8.8','127.0.0.1']], []),
            new ImportTransportDouble(['public.test'=>['8.8.8.8']], [['status'=>302,'location'=>'http://public.test/plain','body'=>'']]),
            new ImportTransportDouble(['public.test'=>['8.8.8.8']], array_fill(0,4,['status'=>302,'location'=>'/again','body'=>''])),
        ] as $client) {
            try { $client->fetch('https://public.test/data'); self::fail('Unsafe fetch accepted.'); }
            catch (InvalidArgumentException $e) { self::assertNotEmpty($e->getMessage()); }
        }
    }

    public function testStalledDnsProcessIsTerminatedWithinTheDeadline(): void
    {
        $client = new class extends RemoteImport {
            public function stalled(): array { return $this->resolveProcess([PHP_BINDIR.'/php','-r','sleep(10); echo "[]";'], 'unused', microtime(true)+0.1); }
        };
        $start = microtime(true);
        try { $client->stalled(); self::fail('Stalled DNS should time out.'); }
        catch (InvalidArgumentException $e) { self::assertStringContainsString('timed out', $e->getMessage()); }
        self::assertLessThan(1.0, microtime(true)-$start);
    }
}

final class ImportTransportDouble extends RemoteImport
{
    public array $requests = [];
    public function __construct(private array $addresses, private array $responses) {}
    protected function resolve(string $host, float $deadline): array { return $this->addresses[$host] ?? []; }
    protected function transfer(array $target, string $ip, float $remaining): array {
        $this->requests[] = ['url'=>$target['url'],'ip'=>$ip];
        return array_shift($this->responses);
    }
}
