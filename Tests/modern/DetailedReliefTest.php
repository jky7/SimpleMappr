<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\DetailedRelief;
use SimpleMappr\ReliefTileCache;

final class DetailedReliefTest extends TestCase
{
    public function testOverviewNeedsNoTilesAndLocalViewsGainResolution(): void
    {
        self::assertNull(DetailedRelief::plan([-20000000,-10000000,20000000,10000000],600,300));
        $bounds=[0,0,200000,100000];
        $preview=DetailedRelief::plan($bounds,600,300);
        $download=DetailedRelief::plan($bounds,3000,1500);
        self::assertGreaterThan($preview['zoom'],$download['zoom']);
        self::assertLessThanOrEqual(12,$download['zoom']);
        self::assertLessThanOrEqual(256,count(DetailedRelief::tileNames($download)));
        self::assertNull(DetailedRelief::plan([0,18500000,21000,18521000],600,300));
    }

    public function testAntimeridianTilesWrapWithoutChangingMosaicPlacement(): void
    {
        $plan=DetailedRelief::plan([DetailedRelief::HALF_WORLD-10000,0,DetailedRelief::HALF_WORLD+10000,10000],600,300);
        $tiles=DetailedRelief::tileNames($plan);
        self::assertContains('12-4095-2047.jpeg',$tiles);
        self::assertContains('12-0-2047.jpeg',$tiles);
        self::assertArrayHasKey('4096,2047',$tiles);
    }

    public function testMalformedOrOversizedPlansCannotReachNetwork(): void
    {
        foreach ([['zoom'=>13,'x0'=>0,'x1'=>0,'y0'=>0,'y1'=>0],['zoom'=>12,'x0'=>0,'x1'=>256,'y0'=>0,'y1'=>1],['zoom'=>12,'x0'=>0,'x1'=>0,'y0'=>-1,'y1'=>0]] as $plan) {
            try { DetailedRelief::validatePlan($plan);self::fail('The invalid plan was accepted.'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testTileValidationDecodesCompleteRgbJpegs(): void
    {
        $bytes=$this->jpeg();
        self::assertTrue(ReliefTileCache::valid($bytes));
        self::assertFalse(ReliefTileCache::valid(substr($bytes,0,300)));
        self::assertFalse(ReliefTileCache::valid(substr($bytes,0,-2)));
        self::assertFalse(ReliefTileCache::valid(str_repeat('a',ReliefTileCache::MAX_BYTES+1)));
        self::assertFalse(ReliefTileCache::valid('<ServiceException/>'));
        $image=imagecreatetruecolor(10,10);ob_start();imagejpeg($image);$small=ob_get_clean();imagedestroy($image);
        self::assertFalse(ReliefTileCache::valid($small));
    }

    public function testValidatedTilesPublishAtomicallyAndWarmCacheDoesNotFetch(): void
    {
        $directory=sys_get_temp_dir().'/relief-test-'.bin2hex(random_bytes(8));mkdir($directory);
        try {
            $cache=new class extends ReliefTileCache {
                protected function download(array $names,string $directory,array $protected,float $deadline): void {
                    if ($names) { throw new RuntimeException('Unexpected network request.'); }
                }
            };
            $cache->publish($directory,'0-0-0.jpeg',$this->jpeg(),[]);
            $cache->fill(['zoom'=>0,'x0'=>0,'x1'=>0,'y0'=>0,'y1'=>0],$directory);
            self::assertSame([],glob($directory.'/.partial-*'));
            self::assertTrue(ReliefTileCache::valid(file_get_contents($directory.'/0-0-0.jpeg')));
            self::assertTrue(DetailedRelief::emptyTile($directory.'/0-0-0.jpeg'));
        } finally {
            foreach (glob($directory.'/*') as $path) { unlink($path); }
            if (is_file($directory.'/.lock')) { unlink($directory.'/.lock'); }rmdir($directory);
        }
    }

    public function testCachePrunesOldTilesBeforePublishingWithinItsByteBudget(): void
    {
        $directory=sys_get_temp_dir().'/relief-prune-'.bin2hex(random_bytes(8));mkdir($directory);
        try {
            $old=$directory.'/0-0-0.jpeg';$file=fopen($old,'w');ftruncate($file,ReliefTileCache::BUDGET);fclose($file);
            (new ReliefTileCache())->publish($directory,'1-0-0.jpeg',$this->jpeg(),[]);
            self::assertFileDoesNotExist($old);
            self::assertSame(strlen($this->jpeg()),filesize($directory.'/1-0-0.jpeg'));
        } finally { foreach (glob($directory.'/*') as $path) { unlink($path); }rmdir($directory); }
    }

    private function jpeg(): string
    {
        $image=imagecreatetruecolor(256,256);imagefill($image,0,0,imagecolorallocate($image,88,88,88));
        ob_start();imagejpeg($image);$bytes=ob_get_clean();imagedestroy($image);return $bytes;
    }
}
