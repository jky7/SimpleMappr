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

    public function testLandClipsSplitTheAntimeridianAndStayWithinGeographicBounds(): void
    {
        $clips=DetailedRelief::geographicClips(['zoom'=>12,'x0'=>4095,'x1'=>4096,'y0'=>2047,'y1'=>2048]);
        self::assertCount(2,$clips);
        foreach ($clips as [$west,$south,$east,$north]) {
            self::assertGreaterThanOrEqual(-180,$west);self::assertLessThanOrEqual(180,$east);
            self::assertLessThan(0,$south);self::assertGreaterThan(0,$north);
            self::assertEqualsWithDelta(360/4096,$east-$west,1e-10);
        }
        $world=DetailedRelief::geographicClips(['zoom'=>0,'x0'=>0,'x1'=>0,'y0'=>0,'y1'=>0]);
        self::assertCount(1,$world);
        foreach ([-180,-85.05112878,180,85.05112878] as $index=>$value) { self::assertEqualsWithDelta($value,$world[0][$index],1e-8); }
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

    public function testNativeMosaicKeepsBlackTerrainOpaqueAndEmptyTilesTransparent(): void
    {
        $directory=sys_get_temp_dir().'/relief-alpha-'.bin2hex(random_bytes(8));mkdir($directory);
        try {
            $image=imagecreatetruecolor(256,256);imagesetpixel($image,255,255,imagecolorallocate($image,128,128,128));
            imagejpeg($image,$directory.'/1-0-0.jpeg');imagedestroy($image);
            file_put_contents($directory.'/1-1-0.jpeg',$this->jpeg());
            $plan=['zoom'=>1,'x0'=>0,'x1'=>1,'y0'=>0,'y1'=>0];
            file_put_contents($directory.'/mosaic.vrt',DetailedRelief::mosaic($plan,$directory,hrtime(true)/1e9+5));
            $process=proc_open(['gdal_translate','-q','-of','PNG',$directory.'/mosaic.vrt',$directory.'/mosaic.png'],[['file','/dev/null','r'],['file','/dev/null','a'],['file','/dev/null','a']],$pipes);
            self::assertIsResource($process);self::assertSame(0,proc_close($process));
            $image=imagecreatefrompng($directory.'/mosaic.png');self::assertSame(512,imagesx($image));
            $land=imagecolorsforindex($image,imagecolorat($image,10,10));$empty=imagecolorsforindex($image,imagecolorat($image,300,10));
            self::assertSame(['red'=>0,'green'=>0,'blue'=>0,'alpha'=>0],$land);
            self::assertSame(127,$empty['alpha']);imagedestroy($image);
        } finally { foreach (glob($directory.'/*') as $path) { unlink($path); }rmdir($directory); }
    }

    public function testIndexTilesMustCarryThePublishedKeyUnlessBlank(): void
    {
        self::assertTrue(ReliefTileCache::validIndex($this->indexPng(fn($image)=>imagefilledrectangle($image,0,0,127,255,40))));
        self::assertTrue(ReliefTileCache::validIndex($this->indexPng(null,array_fill(0,256,[0,0,0]))),'Open-ocean tiles are blank whatever their palette.');
        $wrong=array_column(DetailedRelief::colorIndex(),'rgb');$wrong[40]=[1,2,3];
        self::assertFalse(ReliefTileCache::validIndex($this->indexPng(fn($image)=>imagesetpixel($image,0,0,40),$wrong)));
        $image=imagecreatetruecolor(256,256);ob_start();imagepng($image);$truecolor=ob_get_clean();imagedestroy($image);
        self::assertFalse(ReliefTileCache::validIndex($truecolor));
        self::assertFalse(ReliefTileCache::validIndex($this->jpeg()));
        self::assertFalse(ReliefTileCache::validTile('index-0-0-0.png',$this->jpeg()));
        self::assertTrue(ReliefTileCache::validTile('0-0-0.jpeg',$this->jpeg()));
    }

    public function testTintTableLeavesNoDataTransparentAndRampsWithElevation(): void
    {
        $key=DetailedRelief::colorIndex();$table=DetailedRelief::tintTable();
        self::assertCount(256,$table);self::assertTrue($key[0]['nodata']);self::assertSame(0,$table[0][3]);
        $land=array_filter(array_keys($key),fn($index)=>!$key[$index]['nodata']);
        foreach ($land as $index) { self::assertSame(255,$table[$index][3]); }
        usort($land,fn($a,$b)=>$key[$a]['low']<=>$key[$b]['low']);
        self::assertSame(DetailedRelief::TINTS[count(DetailedRelief::TINTS)-1][1],array_slice($table[end($land)],0,3));
        self::assertSame(DetailedRelief::tint(1500),[222,206,177]);
    }

    public function testTintMosaicPaintsLandFromThePaletteAndSkipsBlankTiles(): void
    {
        $directory=sys_get_temp_dir().'/relief-tint-'.bin2hex(random_bytes(8));mkdir($directory);
        try {
            $plan=['zoom'=>1,'x0'=>0,'x1'=>1,'y0'=>0,'y1'=>0];
            file_put_contents($directory.'/index-1-1-0.png',$this->indexPng());
            self::assertNull(DetailedRelief::tintMosaic(['zoom'=>1,'x0'=>1,'x1'=>1,'y0'=>0,'y1'=>0],$directory,hrtime(true)/1e9+5));
            file_put_contents($directory.'/index-1-0-0.png',$this->indexPng(fn($image)=>imagefilledrectangle($image,0,0,127,255,40)));
            $vrt=DetailedRelief::tintMosaic($plan,$directory,hrtime(true)/1e9+5);
            self::assertStringNotContainsString('index-1-1-0.png',$vrt);
            file_put_contents($directory.'/tint.vrt',$vrt);
            $process=proc_open(['gdal_translate','-q','-of','PNG',$directory.'/tint.vrt',$directory.'/tint.png'],[['file','/dev/null','r'],['file','/dev/null','a'],['file','/dev/null','a']],$pipes);
            self::assertIsResource($process);self::assertSame(0,proc_close($process));
            $image=imagecreatefrompng($directory.'/tint.png');self::assertSame([512,256],[imagesx($image),imagesy($image)]);
            $land=imagecolorsforindex($image,imagecolorat($image,10,10));
            self::assertSame(array_slice(DetailedRelief::tintTable()[40],0,3),[$land['red'],$land['green'],$land['blue']]);self::assertSame(0,$land['alpha']);
            self::assertSame(127,imagecolorsforindex($image,imagecolorat($image,200,10))['alpha']);
            self::assertSame(127,imagecolorsforindex($image,imagecolorat($image,300,10))['alpha']);imagedestroy($image);
        } finally { foreach (glob($directory.'/*') as $path) { unlink($path); }rmdir($directory); }
    }

    public function testWarmCacheServesBothSourcesWithoutFetching(): void
    {
        $directory=sys_get_temp_dir().'/relief-sources-'.bin2hex(random_bytes(8));mkdir($directory);
        try {
            $cache=new class extends ReliefTileCache {
                public array $requested=[];
                protected function download(array $names,string $directory,array $protected,float $deadline): void { $this->requested=$names; }
            };
            $cache->publish($directory,'index-0-0-0.png',$this->indexPng(),[]);
            $cache->fill(['zoom'=>0,'x0'=>0,'x1'=>0,'y0'=>0,'y1'=>0],$directory,['grey','index']);
            self::assertSame(['0-0-0.jpeg'],$cache->requested);
            try { $cache->publish($directory,'index-0-0-0.jpeg',$this->jpeg(),[]);self::fail('A mislabelled tile was published.'); }
            catch (InvalidArgumentException) { self::assertFileDoesNotExist($directory.'/index-0-0-0.jpeg'); }
        } finally {
            foreach (glob($directory.'/*') as $path) { unlink($path); }
            if (is_file($directory.'/.lock')) { unlink($directory.'/.lock'); }rmdir($directory);
        }
    }

    /** A 256x256 palette PNG in the published key's colour order, blank (index 0) until $draw paints it. */
    private function indexPng(?callable $draw=null,?array $palette=null): string
    {
        $image=imagecreate(256,256);
        foreach ($palette ?? array_column(DetailedRelief::colorIndex(),'rgb') as [$r,$g,$b]) { imagecolorallocate($image,$r,$g,$b); }
        if ($draw) { $draw($image); }
        ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);return $bytes;
    }

    private function jpeg(): string
    {
        $image=imagecreatetruecolor(256,256);imagefill($image,0,0,imagecolorallocate($image,88,88,88));
        ob_start();imagejpeg($image);$bytes=ob_get_clean();imagedestroy($image);return $bytes;
    }
}
